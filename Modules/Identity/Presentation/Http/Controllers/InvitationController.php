<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Invitations\AcceptInvitation;
use Modules\Identity\Application\Invitations\SendInvitation;
use Modules\Identity\Domain\AuthenticatedContext;
use Modules\Identity\Domain\Models\Invitation;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Auth\JwtUserResolver;
use Modules\Identity\Presentation\Http\Controllers\Concerns\ResolvesOrganizationContext;
use Modules\Identity\Presentation\Http\Requests\AcceptInvitationRequest;
use Modules\Identity\Presentation\Http\Requests\SendInvitationRequest;

/**
 * @see docs/03-services/identity/03-api.md
 */
final class InvitationController
{
    use ResolvesOrganizationContext;

    /**
     * Invitations en attente de l'organisation active.
     */
    public function index(AuthenticatedContext $context, string $organizationId): JsonResponse
    {
        $invitations = Invitation::query()
            ->where('organization_id', $this->assertOrganizationMatches($context, $organizationId))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->with('role')
            ->orderByDesc('created_at')
            ->get()
            ->map($this->present(...))
            ->all();

        return ApiResponse::success($invitations);
    }

    public function store(
        SendInvitationRequest $request,
        AuthenticatedContext $context,
        SendInvitation $send,
        string $organizationId,
    ): JsonResponse {
        $issued = $send->handle(
            organizationId: $this->assertOrganizationMatches($context, $organizationId),
            email: $request->string('email')->toString(),
            globalRoleId: $request->string('global_role_id')->toString(),
            inviter: $context->user,
        );

        $payload = $this->present($issued->invitation->load('role'));

        // Le jeton en clair n'est exposé qu'en développement : en production
        // il n'existe que dans le message envoyé par Notify.
        if (app()->environment('local', 'testing')) {
            $payload['token'] = $issued->plainToken;
        }

        return ApiResponse::created($payload);
    }

    public function destroy(AuthenticatedContext $context, string $invitationId): JsonResponse
    {
        $invitation = Invitation::query()
            ->where('organization_id', $this->organizationId($context))
            ->whereKey($invitationId)
            ->whereNull('accepted_at')
            ->first();

        if ($invitation === null) {
            throw DomainException::notFound(
                'INVITATION_NOT_FOUND',
                __('This invitation does not exist.'),
            );
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        return ApiResponse::noContent();
    }

    /**
     * Consultation publique, avant acceptation : le jeton fait office de
     * preuve. Seules les informations nécessaires à l'affichage sont exposées.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = Invitation::query()
            ->where('token_hash', Invitation::hash($token))
            ->with(['organization', 'role'])
            ->first();

        if ($invitation === null || ! $invitation->isPending()) {
            throw DomainException::notFound(
                'INVITATION_NOT_FOUND',
                __('This invitation does not exist or is no longer valid.'),
            );
        }

        return ApiResponse::success([
            'email' => $invitation->email,
            'organization' => [
                'name' => $invitation->organization?->name,
                'slug' => $invitation->organization?->slug,
            ],
            'role' => $invitation->role?->slug,
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            'requires_account' => ! $this->accountExistsFor($invitation->email),
        ]);
    }

    public function accept(
        AcceptInvitationRequest $request,
        AcceptInvitation $accept,
        JwtUserResolver $resolver,
        string $token,
    ): JsonResponse {
        // La route est publique : un utilisateur déjà connecté est pris en
        // compte s'il présente un token, mais ce n'est pas exigé.
        $authenticated = $resolver->resolve($request)?->user;

        $result = $accept->handle($token, $authenticated, $request->validated());

        return ApiResponse::success([
            'organization_id' => $result->membership->organization_id,
            'membership_id' => $result->membership->id,
            'account_created' => $result->accountCreated,
            'user' => [
                'id' => $result->user->id,
                'email' => $result->user->email,
            ],
        ]);
    }

    private function accountExistsFor(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role?->slug,
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            'created_at' => $invitation->created_at?->toIso8601ZuluString(),
        ];
    }
}
