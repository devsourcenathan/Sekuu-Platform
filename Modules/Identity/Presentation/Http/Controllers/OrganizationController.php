<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Application\Organizations\CreateOrganization;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Presentation\Http\Requests\CreateOrganizationRequest;
use Modules\Identity\Presentation\Http\Responses\AuthPayload;

final class OrganizationController
{
    /**
     * Création d'une organisation. Le créateur en devient owner.
     */
    public function store(
        CreateOrganizationRequest $request,
        AuthenticatedContext $context,
        CreateOrganization $create,
        AuditLogger $audit,
    ): JsonResponse {
        $organization = $create->handle($context->user, $request->validated());

        $audit->record(
            AuditAction::ORGANIZATION_CREATED,
            user: $context->user,
            organizationId: $organization->id,
            target: $organization,
            payload: ['name' => $organization->name, 'slug' => $organization->slug],
        );

        return ApiResponse::created([
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'country' => $organization->country,
            'currency' => $organization->currency,
            'timezone' => $organization->timezone,
            'locale' => $organization->locale,
            'status' => $organization->status,
        ]);
    }

    /**
     * Organisations de l'utilisateur connecté.
     */
    public function index(AuthenticatedContext $context): JsonResponse
    {
        return ApiResponse::success(
            AuthPayload::organizations($context->user)
        );
    }
}
