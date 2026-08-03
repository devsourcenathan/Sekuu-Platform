<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Notify\Domain\Models\Notification;
use Modules\Notify\Domain\Models\NotificationDelivery;
use Modules\Notify\Domain\Models\NotificationEvent;

/**
 * Consultation de l'historique d'envoi.
 *
 * @see docs/03-services/notify/03-api.md
 */
final class NotificationController
{
    public function index(Request $request, AuthenticatedContext $context): JsonResponse
    {
        $organizationId = $context->token->organizationId
            ?? throw DomainException::forbidden(
                'ORGANIZATION_REQUIRED',
                __('Select an active organization before calling this endpoint.'),
            );

        $query = Notification::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyFilters($request, $query);

        $paginator = $query->cursorPaginate($this->perPage($request));

        return ApiResponse::success(
            $paginator->getCollection()->map($this->summarise(...))->all(),
            $this->paginationMeta($paginator),
        );
    }

    public function show(AuthenticatedContext $context, string $notificationId): JsonResponse
    {
        $notification = Notification::query()
            ->where('organization_id', $context->token->organizationId)
            ->whereKey($notificationId)
            ->with(['deliveries', 'events'])
            ->first();

        if ($notification === null) {
            throw DomainException::notFound(
                'NOTIFICATION_NOT_FOUND',
                __('This notification does not exist.'),
            );
        }

        return ApiResponse::success($this->summarise($notification) + [
            'deliveries' => $notification->deliveries->map(fn (NotificationDelivery $d) => [
                'provider' => $d->provider,
                'attempt' => $d->attempt,
                'status' => $d->status,
                'provider_message_id' => $d->provider_message_id,
                'error_code' => $d->error_code,
                'cost_amount' => $d->cost_amount,
                'cost_currency' => $d->cost_currency,
                'sent_at' => $d->sent_at?->toIso8601ZuluString(),
            ])->all(),
            'events' => $notification->events->map(fn (NotificationEvent $e) => [
                'type' => $e->type,
                'provider' => $e->provider,
                'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
            ])->all(),
        ]);
    }

    private function applyFilters(Request $request, $query): void
    {
        $filters = $request->query('filter', []);

        if (! is_array($filters)) {
            throw new DomainException('INVALID_FILTER', __('The filter parameter is malformed.'), 400);
        }

        $allowed = ['status', 'channel', 'category', 'template_key', 'user_id'];

        foreach ($filters as $field => $value) {
            if (! in_array($field, $allowed, true)) {
                throw new DomainException(
                    'INVALID_FILTER',
                    __('Unknown filter: :field', ['field' => (string) $field]),
                    400,
                );
            }

            $query->where($field, $value);
        }
    }

    /**
     * Le corps rendu n'est **jamais** exposé : il contient des liens à usage
     * unique dont la lecture équivaudrait à une prise de contrôle. Le
     * destinataire est masqué pour la même raison.
     *
     * @return array<string, mixed>
     */
    private function summarise(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'status' => $notification->status,
            'template_key' => $notification->template_key,
            'channel' => $notification->channel,
            'category' => $notification->category,
            'locale' => $notification->locale,
            'recipient' => $notification->maskedRecipient(),
            'payload' => $notification->payload ?? [],
            'failed_reason' => $notification->failed_reason,
            'request_id' => $notification->request_id,
            'created_at' => $notification->created_at?->toIso8601ZuluString(),
        ];
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', (string) config('sekuu.pagination.per_page'));

        return max(1, min($perPage, (int) config('sekuu.pagination.max_per_page')));
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationMeta(CursorPaginator $paginator): array
    {
        return [
            'per_page' => $paginator->perPage(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }
}
