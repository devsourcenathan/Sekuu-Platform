<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Workspaces\ManageWorkspaceMembers;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\WorkspaceMember;
use Modules\Identity\Presentation\Http\Controllers\Concerns\ResolvesOrganizationContext;
use Modules\Identity\Presentation\Http\Requests\AddWorkspaceMemberRequest;

final class WorkspaceMemberController
{
    use ResolvesOrganizationContext;

    public function index(AuthenticatedContext $context, string $workspaceId): JsonResponse
    {
        $workspace = $this->findWorkspace($context, $workspaceId);

        $this->assertCanAccessWorkspace($context, $workspace, $this->currentMembership($context));

        $members = $workspace->members()
            ->with('membership.user')
            ->get()
            ->map(fn (WorkspaceMember $member) => [
                'membership_id' => $member->membership_id,
                'is_default' => $member->is_default,
                'user' => [
                    'id' => $member->membership?->user?->id,
                    'first_name' => $member->membership?->user?->first_name,
                    'last_name' => $member->membership?->user?->last_name,
                    'email' => $member->membership?->user?->email,
                ],
            ])
            ->all();

        return ApiResponse::success($members);
    }

    public function store(
        AddWorkspaceMemberRequest $request,
        AuthenticatedContext $context,
        ManageWorkspaceMembers $members,
        string $workspaceId,
    ): JsonResponse {
        $workspace = $this->findWorkspace($context, $workspaceId);

        $member = $members->add($workspace, $request->string('membership_id')->toString());

        return ApiResponse::created([
            'membership_id' => $member->membership_id,
            'workspace_id' => $member->workspace_id,
        ]);
    }

    public function destroy(
        AuthenticatedContext $context,
        ManageWorkspaceMembers $members,
        string $workspaceId,
        string $membershipId,
    ): JsonResponse {
        $members->remove($this->findWorkspace($context, $workspaceId), $membershipId);

        return ApiResponse::noContent();
    }
}
