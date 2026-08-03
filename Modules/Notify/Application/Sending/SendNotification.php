<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\RequestId;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Notify\Application\Delivery\DeliverNotification;
use Modules\Notify\Application\Templates\RenderedMessage;
use Modules\Notify\Application\Templates\TemplateRenderer;
use Modules\Notify\Application\Templates\TemplateResolver;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationTemplate;

/**
 * Le pipeline d'envoi.
 *
 *   1. Résolution         un template par canal, pour la clé demandée
 *   2. Déduplication      même clé d'idempotence déjà vue ? on s'arrête
 *   3. Rendu              variables appliquées, contenu figé
 *   4. Filtrage           préférences, liste de suppression
 *   5. Mise en file       le message devient une tâche
 *
 * @see docs/03-services/notify/01-overview.md
 * @see docs/04-decisions/adr-0005-notify-asynchronous-delivery.md
 */
final class SendNotification
{
    public function __construct(
        private readonly TemplateResolver $resolver,
        private readonly TemplateRenderer $renderer,
        private readonly RecipientFilter $filter,
        private readonly SpendGuard $budget,
        private readonly ChannelQuota $quota,
    ) {}

    public function handle(SendRequest $request): SendOutcome
    {
        $templates = $this->resolver->resolveAll($request->templateKey, $request->organizationId);

        $queued = new Collection;
        $blocked = new Collection;
        $skipped = [];

        foreach ($templates as $channel => $template) {
            $destination = $request->destinationFor($channel);

            // Aucune coordonnée pour ce canal : ce n'est pas une erreur, c'est
            // un canal simplement inapplicable à ce destinataire.
            if ($destination === null) {
                $skipped[$channel] = 'CHANNEL_NOT_AVAILABLE';

                continue;
            }

            $notification = $this->sendOne($request, $template, $channel, $destination);

            $notification->status === Notification::SUPPRESSED
                ? $blocked->push($notification)
                : $queued->push($notification);
        }

        $outcome = new SendOutcome($queued, $blocked, $skipped);

        // Rien n'a pu partir : l'appelant doit le savoir. Si au moins un canal
        // a fonctionné, le message est passé — le détail reste dans le résultat.
        if (! $outcome->sentAnything()) {
            throw $this->failureFor($outcome);
        }

        return $outcome;
    }

    private function sendOne(
        SendRequest $request,
        NotificationTemplate $template,
        string $channel,
        string $destination,
    ): Notification {
        $idempotencyKey = $request->idempotencyKeyFor($channel);

        $existing = $idempotencyKey === null
            ? null
            : Notification::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->assertDestinationIsValid($channel, $destination);

        // Vérifiés avant le rendu : inutile de préparer un message qu'on ne
        // s'autorise pas à envoyer.
        //
        // Deux bornes distinctes, et non redondantes : le quota du plan est une
        // limite **commerciale**, le plafond de dépense un garde-fou contre
        // l'emballement. Un plan illimité n'échappe donc pas au second.
        $this->quota->assertWithinQuota($channel, $request->organizationId);
        $this->budget->assertWithinBudget($channel, $request->organizationId);

        // Le rendu précède la mise en file : le contenu est figé à
        // l'acceptation, pas à l'envoi.
        $rendered = $this->renderer->render(
            $template,
            $this->resolver->localeChain($request->locale),
            $request->variables,
        );

        $verdict = $this->filter->check(
            channel: $channel,
            category: $template->category,
            destination: $destination,
            userId: $request->userId,
            organizationId: $request->organizationId,
        );

        $notification = $this->record($request, $template, $rendered, $verdict, $destination, $idempotencyKey);

        if ($verdict->allowed && $notification->status === Notification::QUEUED) {
            DeliverNotification::dispatch($notification->id)
                ->onQueue(config('notify.queue'))
                ->delay($notification->scheduled_for);
        }

        return $notification;
    }

    private function record(
        SendRequest $request,
        NotificationTemplate $template,
        RenderedMessage $rendered,
        FilterVerdict $verdict,
        string $destination,
        ?string $idempotencyKey,
    ): Notification {
        $attributes = [
            'organization_id' => $request->organizationId,
            'user_id' => $request->userId,
            'template_id' => $template->id,
            'template_key' => $template->key,
            'channel' => $template->channel,
            'category' => $template->category,
            'locale' => $rendered->locale,
            'recipient' => $destination,
            'rendered_subject' => $rendered->subject,
            'rendered_body' => $rendered->body,
            'payload' => self::scrub($request->variables),
            'status' => $verdict->allowed ? Notification::QUEUED : Notification::SUPPRESSED,
            'idempotency_key' => $idempotencyKey,
            'source_event_id' => $request->sourceEventId,
            'request_id' => RequestId::current(),
            'scheduled_for' => $request->scheduledFor,
            'failed_reason' => $verdict->allowed ? null : $verdict->errorCode,
        ];

        try {
            // SAVEPOINT : sur PostgreSQL, une violation d'unicité annule la
            // transaction courante. Sans lui, le rattrapage ci-dessous
            // laisserait la transaction inutilisable.
            return DB::transaction(fn () => Notification::create($attributes));
        } catch (QueryException $e) {
            // Deux consommateurs concurrents du même événement : l'index unique
            // tranche, et le perdant récupère la notification déjà créée.
            if ($idempotencyKey !== null && self::isUniqueViolation($e)) {
                return Notification::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            throw $e;
        }
    }

    private function failureFor(SendOutcome $outcome): DomainException
    {
        $code = $outcome->blockingReason() ?? 'CHANNEL_NOT_AVAILABLE';

        return match ($code) {
            'RECIPIENT_SUPPRESSED' => DomainException::forbidden(
                $code,
                __('notify::messages.recipient_suppressed'),
            ),
            'RECIPIENT_OPTED_OUT' => DomainException::forbidden(
                $code,
                __('notify::messages.recipient_opted_out'),
            ),
            default => DomainException::unprocessable(
                'CHANNEL_NOT_AVAILABLE',
                __('notify::messages.channel_not_available'),
            ),
        };
    }

    private function assertDestinationIsValid(string $channel, string $destination): void
    {
        $valid = match ($channel) {
            Channel::EMAIL => filter_var($destination, FILTER_VALIDATE_EMAIL) !== false,
            // Format E.164 : les passerelles locales le refusent autrement, et
            // un numéro national serait ambigu entre opérateurs.
            Channel::SMS, Channel::WHATSAPP => preg_match('/^\+[1-9]\d{7,14}$/', $destination) === 1,
            default => $destination !== '',
        };

        if (! $valid) {
            throw DomainException::unprocessable(
                'RECIPIENT_INVALID',
                __('notify::messages.recipient_invalid', ['channel' => $channel]),
            );
        }
    }

    /**
     * `payload` est exposé par l'API de consultation : les jetons n'y ont pas
     * leur place. Ils restent dans le corps rendu, qui n'est jamais sérialisé.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function scrub(array $variables): array
    {
        $forbidden = ['token', 'password', 'secret', 'api_key', 'authorization', 'url', 'code'];
        $clean = [];

        foreach ($variables as $key => $value) {
            $normalised = mb_strtolower((string) $key);

            foreach ($forbidden as $needle) {
                if (str_contains($normalised, $needle)) {
                    continue 2;
                }
            }

            $clean[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $clean;
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
