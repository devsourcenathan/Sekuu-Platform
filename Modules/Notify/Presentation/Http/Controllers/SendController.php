<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Infrastructure\Auth\ApiKeyResolver;
use Modules\Notify\Application\Sending\SendNotification;
use Modules\Notify\Application\Sending\SendOutcome;
use Modules\Notify\Application\Sending\SendRequest;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Presentation\Http\Requests\SendBulkRequest;
use Modules\Notify\Presentation\Http\Requests\SendNotificationRequest;

/**
 * Déclenchement d'un envoi par un service.
 *
 * Jamais une action d'utilisateur final : une clé d'API portant le scope
 * `notifications.send` est exigée, sinon n'importe quel utilisateur connecté
 * pourrait faire partir un message au nom de la plateforme.
 *
 * @see docs/03-services/notify/03-api.md
 */
final class SendController
{
    /** Au-delà, la requête devient un traitement par lot, pas un appel d'API. */
    private const BULK_LIMIT = 100;

    public function __construct(private readonly ApiKeyResolver $keys) {}

    public function store(
        SendNotificationRequest $request,
        SendNotification $send,
    ): JsonResponse {
        $organizationId = $this->organizationFor($request);

        $outcome = $send->handle($this->toSendRequest($request, $request->validated(), $organizationId));

        // 202, pas 201 : la ressource créée est une **intention**. Rien ne
        // garantit encore qu'un message partira.
        return ApiResponse::success($this->present($outcome), status: 202);
    }

    public function bulk(SendBulkRequest $request, SendNotification $send): JsonResponse
    {
        $organizationId = $this->organizationFor($request);
        $messages = $request->array('messages');

        if (count($messages) > self::BULK_LIMIT) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('notify::messages.bulk_limit_exceeded', ['limit' => self::BULK_LIMIT]),
            );
        }

        $results = [];

        foreach ($messages as $index => $message) {
            $payload = [
                'template_key' => $request->string('template_key')->toString(),
                'locale' => $request->input('locale'),
                'recipient' => $message['recipient'],
                'variables' => $message['variables'] ?? [],
            ];

            // Un destinataire supprimé n'invalide pas les 99 autres : chaque
            // message est rapporté indépendamment.
            try {
                $outcome = $send->handle(
                    $this->toSendRequest($request, $payload, $organizationId, (string) $index)
                );

                $results[] = ['index' => $index, 'accepted' => true] + $this->present($outcome);
            } catch (DomainException $e) {
                $results[] = [
                    'index' => $index,
                    'accepted' => false,
                    'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
                ];
            }
        }

        return ApiResponse::success(['results' => $results], status: 202);
    }

    public function cancel(Request $request, string $notificationId): JsonResponse
    {
        $context = $this->keys->require($request, 'notifications.send');

        $notification = Notification::query()
            ->where('organization_id', $context->organizationId())
            ->whereKey($notificationId)
            ->first();

        if ($notification === null) {
            throw DomainException::notFound(
                'NOTIFICATION_NOT_FOUND',
                __('notify::messages.notification_not_found'),
            );
        }

        // Un message déjà remis au fournisseur ne se rattrape pas : mieux vaut
        // le dire que laisser croire à une annulation.
        if (! $notification->isCancellable()) {
            throw DomainException::conflict(
                'NOTIFICATION_NOT_CANCELLABLE',
                __('notify::messages.notification_not_cancellable'),
            );
        }

        $notification->forceFill(['status' => Notification::CANCELLED])->save();

        return ApiResponse::success(['id' => $notification->id, 'status' => $notification->status]);
    }

    /**
     * L'organisation vient de la clé d'API, jamais du corps de la requête :
     * une clé ne peut pas envoyer au nom d'une autre organisation.
     */
    private function organizationFor(Request $request): string
    {
        return $this->keys->require($request, 'notifications.send')->organizationId();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toSendRequest(
        Request $request,
        array $payload,
        string $organizationId,
        ?string $suffix = null,
    ): SendRequest {
        $recipient = (array) ($payload['recipient'] ?? []);

        $recipients = array_filter([
            Channel::EMAIL => $recipient['email'] ?? null,
            Channel::SMS => $recipient['phone'] ?? null,
            Channel::IN_APP => $recipient['user_id'] ?? null,
        ]);

        if ($recipients === []) {
            throw DomainException::unprocessable(
                'CHANNEL_NOT_AVAILABLE',
                __('notify::messages.channel_not_available'),
            );
        }

        // L'idempotence est obligatoire sur un effet de bord non réversible :
        // sans clé, un rejeu réseau produirait un doublon.
        $idempotencyKey = (string) $request->header('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('notify::messages.idempotency_key_required'),
            );
        }

        return new SendRequest(
            templateKey: (string) $payload['template_key'],
            recipients: $recipients,
            variables: (array) ($payload['variables'] ?? []),
            userId: $recipient['user_id'] ?? null,
            organizationId: $organizationId,
            locale: $payload['locale'] ?? null,
            idempotencyKey: $suffix === null ? $idempotencyKey : $idempotencyKey.':'.$suffix,
            scheduledFor: $payload['scheduled_for'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SendOutcome $outcome): array
    {
        return [
            'queued' => $outcome->queued->map(fn (Notification $n) => [
                'id' => $n->id,
                'channel' => $n->channel,
                'status' => $n->status,
                'scheduled_for' => $n->scheduled_for?->toIso8601ZuluString(),
            ])->values()->all(),
            'blocked' => $outcome->blocked->map(fn (Notification $n) => [
                'id' => $n->id,
                'channel' => $n->channel,
                'reason' => $n->failed_reason,
            ])->values()->all(),
            'skipped' => $outcome->skipped,
        ];
    }
}
