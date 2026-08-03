<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Workspaces;

use App\Platform\Exceptions\DomainException;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\Workspace;
use Modules\Identity\Domain\Models\WorkspaceMember;

final class ManageWorkspaceMembers
{
    public function add(Workspace $workspace, string $membershipId): WorkspaceMember
    {
        $membership = Membership::query()
            ->where('id', $membershipId)
            // Le membership doit appartenir à la même organisation que le
            // workspace : c'est la frontière de tenant.
            ->where('organization_id', $workspace->organization_id)
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            throw DomainException::notFound(
                'MEMBERSHIP_NOT_FOUND',
                __('This user is not a member of the organization.'),
            );
        }

        if ($workspace->hasMember($membership->id)) {
            throw DomainException::conflict(
                'DUPLICATE_RESOURCE',
                __('This member already belongs to the workspace.'),
            );
        }

        return WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'membership_id' => $membership->id,
        ]);
    }

    public function remove(Workspace $workspace, string $membershipId): void
    {
        $member = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('membership_id', $membershipId)
            ->first();

        if ($member === null) {
            throw DomainException::notFound(
                'MEMBERSHIP_NOT_FOUND',
                __('This member does not belong to the workspace.'),
            );
        }

        $member->delete();
    }
}
