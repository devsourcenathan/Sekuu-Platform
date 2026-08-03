<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Invitations;

use App\Platform\Exceptions\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Identity\Domain\Models\GlobalRole;
use Modules\Identity\Domain\Models\Invitation;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\User;

final class SendInvitation
{
    public function handle(
        string $organizationId,
        string $email,
        string $globalRoleId,
        User $inviter,
    ): IssuedInvitation {
        $role = GlobalRole::query()->find($globalRoleId);

        if ($role === null) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('The requested role does not exist.'),
            );
        }

        $this->assertNotAlreadyMember($organizationId, $email);

        $plainToken = Str::random(64);

        try {
            $invitation = Invitation::create([
                'organization_id' => $organizationId,
                'global_role_id' => $role->id,
                'email' => $email,
                'token_hash' => Invitation::hash($plainToken),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(7),
            ]);
        } catch (QueryException $e) {
            // L'index partiel garantit une seule invitation en attente par
            // adresse et par organisation.
            if ($e->getCode() === '23505' || str_contains(strtolower($e->getMessage()), 'unique')) {
                throw DomainException::conflict(
                    'DUPLICATE_RESOURCE',
                    __('An invitation is already pending for this email address.'),
                );
            }

            throw $e;
        }

        // C'est Notify qui enverra le message : Identity se contente de
        // publier l'événement dès que le module existera.

        return new IssuedInvitation($invitation, $plainToken);
    }

    private function assertNotAlreadyMember(string $organizationId, string $email): void
    {
        $alreadyMember = Membership::query()
            ->where('organization_id', $organizationId)
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw DomainException::conflict(
                'ALREADY_MEMBER',
                __('This user is already a member of the organization.'),
            );
        }
    }
}
