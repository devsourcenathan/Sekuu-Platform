<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\AuditLog;
use Modules\Identity\Presentation\Http\Controllers\Concerns\ResolvesOrganizationContext;

/**
 * Journal d'audit de l'organisation active.
 *
 * Collection volumineuse et fortement écrite : elle est paginée par curseur,
 * conformément aux guidelines.
 *
 * @see docs/02-standards/api-guidelines.md
 */
final class AuditLogController
{
    use ResolvesOrganizationContext;

    public function index(Request $request, AuthenticatedContext $context): JsonResponse
    {
        $query = AuditLog::query()
            ->where('organization_id', $this->organizationId($context))
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyFilters($request, $query);

        $paginator = $query->cursorPaginate($this->perPage($request));

        return ApiResponse::success(
            $paginator->getCollection()->map($this->present(...))->all(),
            $this->paginationMeta($paginator),
        );
    }

    private function applyFilters(Request $request, $query): void
    {
        $filters = $request->query('filter', []);

        if (! is_array($filters)) {
            throw new DomainException('INVALID_FILTER', __('platform.filter_malformed'), 400);
        }

        $allowed = ['action', 'user_id', 'target_type'];

        foreach ($filters as $field => $value) {
            if (! in_array($field, $allowed, true)) {
                throw new DomainException(
                    'INVALID_FILTER',
                    __('platform.filter_unknown', ['field' => (string) $field]),
                    400,
                );
            }

            $query->where($field, $value);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function present(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'ip_address' => $log->ip_address,
            'request_id' => $log->request_id,
            'payload' => $log->payload ?? [],
            'created_at' => $log->created_at?->toIso8601ZuluString(),
            'user' => $log->user === null ? null : [
                'id' => $log->user->id,
                'first_name' => $log->user->first_name,
                'last_name' => $log->user->last_name,
                'email' => $log->user->email,
            ],
        ];
    }
}
