<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers\Concerns;

use App\Platform\Exceptions\DomainException;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\Workspace;

trait ResolvesOrganizationContext
{
    /**
     * Organisation active du token. Jamais lue depuis l'URL ou le corps de la
     * requête : c'est la garantie d'isolation entre tenants.
     */
    protected function organizationId(AuthenticatedContext $context): string
    {
        return $context->token->organizationId
            ?? throw DomainException::forbidden(
                'ORGANIZATION_REQUIRED',
                __('Select an active organization before calling this endpoint.'),
            );
    }

    /**
     * Certaines routes portent l'organisation dans leur chemin. Elle doit alors
     * correspondre à celle du token : l'URL ne peut jamais élargir la portée
     * d'un token, seulement la confirmer.
     */
    protected function assertOrganizationMatches(AuthenticatedContext $context, string $organizationId): string
    {
        if ($this->organizationId($context) !== $organizationId) {
            throw DomainException::notFound(
                'ORGANIZATION_NOT_FOUND',
                __('This organization does not exist.'),
            );
        }

        return $organizationId;
    }

    protected function currentMembership(AuthenticatedContext $context): Membership
    {
        return $context->user->activeMembershipIn($this->organizationId($context))
            ?? throw DomainException::notFound(
                'MEMBERSHIP_NOT_FOUND',
                __('You are not a member of this organization.'),
            );
    }

    /**
     * Un workspace d'une autre organisation est indiscernable d'un workspace
     * inexistant.
     */
    protected function findWorkspace(AuthenticatedContext $context, string $workspaceId): Workspace
    {
        return Workspace::query()
            ->ofOrganization($this->organizationId($context))
            ->whereKey($workspaceId)
            ->first()
            ?? throw DomainException::notFound(
                'WORKSPACE_NOT_FOUND',
                __('This workspace does not exist.'),
            );
    }

    /**
     * Accès à un workspace : en être membre, ou détenir workspace.manage.
     */
    protected function assertCanAccessWorkspace(
        AuthenticatedContext $context,
        Workspace $workspace,
        Membership $membership,
    ): void {
        if ($context->token->hasScope('workspace.manage')) {
            return;
        }

        if (! $workspace->hasMember($membership->id)) {
            throw DomainException::forbidden(
                'WORKSPACE_ACCESS_DENIED',
                __('You are not a member of this workspace.'),
            );
        }
    }
}
