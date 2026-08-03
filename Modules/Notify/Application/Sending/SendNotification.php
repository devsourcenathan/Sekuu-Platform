<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\RequestId;
use Illuminate\Database\QueryException;
use Modules\Notify\Application\Delivery\DeliverNotification;
use Modules\Notify\Application\Templates\TemplateRenderer;
use Modules\Notify\Application\Templates\TemplateResolver;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;

/**
 * Le pipeline d'envoi.
 *
 *   1. Déduplication      même clé d'idempotence déjà vue ? on s'arrête
 *   2. Résolution         template + langue + canal
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
    ) {}

    public function handle(SendRequest $request): Notification
    {
        // 1. Un rejeu ne doit jamais produire un second message.
        $existing = $this->findExisting($request);

        if ($existing !== null) {
            return $existing;
        }

        // 2.
        $template = $this->resolver->resolve($request->templateKey, $request->organizationId);

        $this->assertRecipientIsValid($template->channel, $request->recipient);

        // 3. Le rendu précède la mise en file : le contenu est figé à
        //    l'acceptation, pas à l'envoi.
        $rendered = $this->renderer->render(
            $template,
            $this->resolver->localeChain($request->locale),
            $request->variables,
        );

        // 4.
        $verdict = $this->filter->check(
            channel: $template->channel,
            category: $template->category,
            destination: $request->recipient,
            userId: $request->userId,
            organizationId: $request->organizationId,
        );

        $notification = $this->record($request, $template, $rendered, $verdict);

        // Le message filtré est enregistré puis signalé : l'appelant doit
        // savoir qu'il ne partira pas, et le journal doit rester complet.
        if (! $verdict->allowed) {
            throw DomainException::forbidden($verdict->errorCode, $verdict->reason);
        }

        // 5.
        DeliverNotification::dispatch($notification->id)
            ->onQueue('notifications')
            ->delay($notification->scheduled_for);

        return $notification;
    }

    private function findExisting(SendRequest $request): ?Notification
    {
        if ($request->idempotencyKey === null) {
            return null;
        }

        return Notification::query()
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();
    }

    private function record(
        SendRequest $request,
        $template,
        $rendered,
        FilterVerdict $verdict,
    ): Notification {
        $attributes = [
            'organization_id' => $request->organizationId,
            'user_id' => $request->userId,
            'template_id' => $template->id,
            'template_key' => $template->key,
            'channel' => $template->channel,
            'category' => $template->category,
            'locale' => $rendered->locale,
            'recipient' => $request->recipient,
            'rendered_subject' => $rendered->subject,
            'rendered_body' => $rendered->body,
            'payload' => self::scrub($request->variables),
            'status' => $verdict->allowed ? Notification::QUEUED : Notification::SUPPRESSED,
            'idempotency_key' => $request->idempotencyKey,
            'source_event_id' => $request->sourceEventId,
            'request_id' => RequestId::current(),
            'scheduled_for' => $request->scheduledFor,
            'failed_reason' => $verdict->allowed ? null : $verdict->errorCode,
        ];

        try {
            return Notification::create($attributes);
        } catch (QueryException $e) {
            // Deux consommateurs concurrents du même événement : l'index unique
            // tranche, et le perdant récupère la notification déjà créée.
            if ($request->idempotencyKey !== null && self::isUniqueViolation($e)) {
                return Notification::query()
                    ->where('idempotency_key', $request->idempotencyKey)
                    ->firstOrFail();
            }

            throw $e;
        }
    }

    private function assertRecipientIsValid(string $channel, string $recipient): void
    {
        $valid = match ($channel) {
            Channel::EMAIL => filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false,
            Channel::SMS, Channel::WHATSAPP => preg_match('/^\+[1-9]\d{7,14}$/', $recipient) === 1,
            default => $recipient !== '',
        };

        if (! $valid) {
            throw DomainException::unprocessable(
                'RECIPIENT_INVALID',
                __('The recipient is not valid for the :channel channel.', ['channel' => $channel]),
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
        $forbidden = ['token', 'password', 'secret', 'api_key', 'authorization', 'url'];
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
