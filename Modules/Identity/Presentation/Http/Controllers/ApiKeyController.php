<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\ApiKeys\IssueApiKey;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\ApiKey;
use Modules\Identity\Presentation\Http\Controllers\Concerns\ResolvesOrganizationContext;
use Modules\Identity\Presentation\Http\Requests\CreateApiKeyRequest;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class ApiKeyController
{
    use ResolvesOrganizationContext;

    public function index(AuthenticatedContext $context): JsonResponse
    {
        $keys = ApiKey::query()
            ->where('organization_id', $this->organizationId($context))
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get()
            ->map($this->present(...))
            ->all();

        return ApiResponse::success($keys);
    }

    public function store(
        CreateApiKeyRequest $request,
        AuthenticatedContext $context,
        IssueApiKey $issue,
        AuditLogger $audit,
    ): JsonResponse {
        $issued = $issue->handle(
            organizationId: $this->organizationId($context),
            name: $request->string('name')->toString(),
            scopes: $request->array('scopes'),
            creator: $context->user,
            expiresAt: $request->input('expires_at'),
        );

        $audit->record(
            AuditAction::API_KEY_CREATED,
            user: $context->user,
            organizationId: $issued->key->organization_id,
            target: $issued->key,
            payload: ['name' => $issued->key->name, 'scopes' => $issued->key->scopes],
        );

        // La valeur en clair n'est affichée qu'ici. Elle n'est jamais relisible :
        // seul son hachage est conservé.
        return ApiResponse::created($this->present($issued->key) + ['key' => $issued->plainKey]);
    }

    public function destroy(
        AuthenticatedContext $context,
        AuditLogger $audit,
        string $apiKeyId,
    ): JsonResponse {
        $key = ApiKey::query()
            ->where('organization_id', $this->organizationId($context))
            ->whereKey($apiKeyId)
            ->whereNull('revoked_at')
            ->first();

        if ($key === null) {
            throw DomainException::notFound(
                'RESOURCE_NOT_FOUND',
                __('identity::messages.api_key_not_found'),
            );
        }

        $key->forceFill(['revoked_at' => now()])->save();

        $audit->record(
            AuditAction::API_KEY_REVOKED,
            user: $context->user,
            organizationId: $key->organization_id,
            target: $key,
            payload: ['name' => $key->name],
        );

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            // Permet de repérer les clés dormantes, qu'il faudra révoquer.
            'last_used_at' => $key->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $key->expires_at?->toIso8601ZuluString(),
            'created_at' => $key->created_at?->toIso8601ZuluString(),
        ];
    }
}
