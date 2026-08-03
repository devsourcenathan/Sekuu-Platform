<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Workspaces\CreateWorkspace;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\Workspace;
use Modules\Identity\Presentation\Http\Controllers\Concerns\ResolvesOrganizationContext;
use Modules\Identity\Presentation\Http\Requests\CreateWorkspaceRequest;
use Modules\Identity\Presentation\Http\Requests\UpdateWorkspaceRequest;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class WorkspaceController
{
    use ResolvesOrganizationContext;

    public function index(AuthenticatedContext $context): JsonResponse
    {
        $membership = $this->currentMembership($context);

        $query = Workspace::query()->ofOrganization($this->organizationId($context));

        // Sans workspace.manage, on ne voit que les workspaces dont on est
        // effectivement membre.
        if (! $context->token->hasScope('workspace.manage')) {
            $query->visibleTo($membership->id);
        }

        return ApiResponse::success(
            $query->orderBy('name')->get()->map($this->present(...))->all()
        );
    }

    public function store(
        CreateWorkspaceRequest $request,
        AuthenticatedContext $context,
        CreateWorkspace $create,
    ): JsonResponse {
        $workspace = $create->handle($this->currentMembership($context), $request->validated());

        return ApiResponse::created($this->present($workspace));
    }

    public function show(AuthenticatedContext $context, string $workspaceId): JsonResponse
    {
        $workspace = $this->findWorkspace($context, $workspaceId);

        $this->assertCanAccessWorkspace($context, $workspace, $this->currentMembership($context));

        return ApiResponse::success($this->present($workspace));
    }

    public function update(
        UpdateWorkspaceRequest $request,
        AuthenticatedContext $context,
        string $workspaceId,
    ): JsonResponse {
        $workspace = $this->findWorkspace($context, $workspaceId);

        $workspace->fill($request->validated())->save();

        return ApiResponse::success($this->present($workspace));
    }

    public function destroy(AuthenticatedContext $context, string $workspaceId): JsonResponse
    {
        $this->findWorkspace($context, $workspaceId)->delete();

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'organization_id' => $workspace->organization_id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'settings' => $workspace->settings ?? [],
            'status' => $workspace->status,
            'created_at' => $workspace->created_at?->toIso8601ZuluString(),
        ];
    }
}
