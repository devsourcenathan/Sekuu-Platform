<?php

declare(strict_types=1);

namespace Modules\Identity\Presentation\Http\Controllers;

use App\Platform\Exceptions\DomainException;
use App\Platform\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Audit\AuditAction;
use Modules\Identity\Application\Audit\AuditLogger;
use Modules\Identity\Application\Events\IdentityEvents;
use Modules\Identity\Application\Invitations\AcceptInvitation;
use Modules\Identity\Application\Invitations\SendInvitation;
use Modules\Identity\Application\Products\OrganizationQuota;
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
        AuditLogger $audit,
        IdentityEvents $events,
        OrganizationQuota $quota,
        string $organizationId,
    ): JsonResponse {
        // Le siège est réservé dès l'invitation, pas à son acceptation :
        // constater le dépassement plus tard reviendrait à revenir sur une
        // promesse déjà faite à l'invité.
        $quota->assertCanAddMember($organizationId);

        $issued = $send->handle(
            organizationId: $this->assertOrganizationMatches($context, $organizationId),
            email: $request->string('email')->toString(),
            globalRoleId: $request->string('global_role_id')->toString(),
            inviter: $context->user,
        );

        $audit->record(
            AuditAction::INVITATION_SENT,
            user: $context->user,
            organizationId: $organizationId,
            target: $issued->invitation,
            payload: ['email' => $issued->invitation->email],
        );

        $invitation = $issued->invitation->load(['role', 'organization']);

        $events->invitationSent(
            organizationId: $organizationId,
            email: $invitation->email,
            organizationName: (string) $invitation->organization?->name,
            inviterName: $context->user->fullName(),
            role: (string) $invitation->role?->name,
            acceptUrl: rtrim((string) config('identity.frontend_url'), '/')
                .'/invitations/'.$issued->plainToken,
            expiresAt: $invitation->expires_at->toIso8601ZuluString(),
            locale: (string) ($invitation->organization?->locale ?? 'fr'),
        );

        $payload = $this->present($issued->invitation->load('role'));

        // Le jeton en clair n'est exposé qu'en développement : en production
        // il n'existe que dans le message envoyé par Notify.
        if (app()->environment('local', 'testing')) {
            $payload['token'] = $issued->plainToken;
        }

        return ApiResponse::created($payload);
    }

    public function destroy(
        AuthenticatedContext $context,
        AuditLogger $audit,
        string $invitationId,
    ): JsonResponse {
        $invitation = Invitation::query()
            ->where('organization_id', $this->organizationId($context))
            ->whereKey($invitationId)
            ->whereNull('accepted_at')
            ->first();

        if ($invitation === null) {
            throw DomainException::notFound(
                'INVITATION_NOT_FOUND',
                __('identity::messages.invitation_not_found'),
            );
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        $audit->record(
            AuditAction::INVITATION_REVOKED,
            user: $context->user,
            organizationId: $invitation->organization_id,
            target: $invitation,
            payload: ['email' => $invitation->email],
        );

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
                __('identity::messages.invitation_unusable'),
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
        AuditLogger $audit,
        string $token,
    ): JsonResponse {
        // La route est publique : un utilisateur déjà connecté est pris en
        // compte s'il présente un token, mais ce n'est pas exigé.
        $authenticated = $resolver->resolve($request)?->user;

        $result = $accept->handle($token, $authenticated, $request->validated());

        $audit->record(
            AuditAction::INVITATION_ACCEPTED,
            user: $result->user,
            organizationId: $result->membership->organization_id,
            target: $result->membership,
            payload: ['account_created' => $result->accountCreated],
        );

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
