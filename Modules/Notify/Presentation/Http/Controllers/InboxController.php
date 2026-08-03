<?php

declare(strict_types=1);

namespace Modules\Notify\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Notify\Domain\Channel;
use Modules\Notify\Domain\Models\Notification;

/**
 * Notifications internes de l'utilisateur.
 *
 * @see docs/03-services/notify/03-api.md
 */
final class InboxController
{
    public function index(Request $request, AuthenticatedContext $context): JsonResponse
    {
        $query = $this->scope($context)->orderByDesc('created_at')->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $paginator = $query->cursorPaginate($this->perPage($request));

        return ApiResponse::success(
            $paginator->getCollection()->map($this->present(...))->all(),
            [
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        );
    }

    public function unreadCount(AuthenticatedContext $context): JsonResponse
    {
        return ApiResponse::success([
            'unread' => $this->scope($context)->whereNull('read_at')->count(),
        ]);
    }

    public function markAsRead(AuthenticatedContext $context, string $notificationId): JsonResponse
    {
        $notification = $this->scope($context)->whereKey($notificationId)->first();

        if ($notification === null) {
            throw DomainException::notFound(
                'NOTIFICATION_NOT_FOUND',
                __('notify::messages.notification_not_found'),
            );
        }

        // Idempotent : relire une notification déjà lue ne déplace pas sa date.
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return ApiResponse::success($this->present($notification));
    }

    public function markAllAsRead(AuthenticatedContext $context): JsonResponse
    {
        $updated = $this->scope($context)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(['marked' => $updated]);
    }

    /**
     * Toute lecture est bornée à l'utilisateur du token : la boîte d'autrui
     * est indiscernable d'une boîte vide.
     */
    private function scope(AuthenticatedContext $context)
    {
        return Notification::query()
            ->where('channel', Channel::IN_APP)
            ->where('user_id', $context->user->id);
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', (string) config('sekuu.pagination.per_page'));

        return max(1, min($perPage, (int) config('sekuu.pagination.max_per_page')));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'template_key' => $notification->template_key,
            'category' => $notification->category,
            'organization_id' => $notification->organization_id,
            'subject' => $notification->rendered_subject,
            // Le corps est exposé ici, et seulement ici : une notification
            // interne n'a pas d'autre support que cette réponse.
            'body' => $notification->rendered_body,
            'read_at' => $notification->read_at?->toIso8601ZuluString(),
            'created_at' => $notification->created_at?->toIso8601ZuluString(),
        ];
    }
}
