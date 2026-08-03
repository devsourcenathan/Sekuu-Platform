<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Invitations;

use App\Platform\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Application\Auth\RegisterUser;
use Modules\Identity\Domain\Models\Invitation;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\User;

final class AcceptInvitation
{
    public function __construct(private readonly RegisterUser $register) {}

    /**
     * @param  array{first_name?: string, last_name?: string, password?: string}  $registration
     */
    public function handle(
        string $plainToken,
        ?User $authenticatedUser = null,
        array $registration = [],
    ): AcceptedInvitation {
        $invitation = Invitation::query()
            ->where('token_hash', Invitation::hash($plainToken))
            ->first();

        if ($invitation === null) {
            throw DomainException::notFound(
                'INVITATION_NOT_FOUND',
                __('This invitation does not exist.'),
            );
        }

        $this->assertPending($invitation);

        // L'invitation est adressée à une adresse précise : elle ne peut pas
        // être détournée par un autre compte déjà connecté.
        if ($authenticatedUser !== null && ! $this->emailsMatch($authenticatedUser->email, $invitation->email)) {
            throw DomainException::forbidden(
                'INVITATION_EMAIL_MISMATCH',
                __('This invitation was issued for a different email address.'),
            );
        }

        return DB::transaction(function () use ($invitation, $authenticatedUser, $registration): AcceptedInvitation {
            $user = $authenticatedUser
                ?? User::query()->where('email', $invitation->email)->first();

            $accountCreated = false;

            if ($user === null) {
                $this->assertRegistrationIsComplete($registration);

                $user = $this->register->handle([
                    'first_name' => $registration['first_name'],
                    'last_name' => $registration['last_name'],
                    'email' => $invitation->email,
                    'password' => $registration['password'],
                ]);

                // L'adresse est prouvée par la réception du jeton d'invitation.
                $user->forceFill(['email_verified_at' => now()])->save();

                $accountCreated = true;
            }

            $membership = Membership::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $invitation->organization_id)
                ->first();

            if ($membership !== null) {
                throw DomainException::conflict(
                    'ALREADY_MEMBER',
                    __('You are already a member of this organization.'),
                );
            }

            $membership = Membership::create([
                'user_id' => $user->id,
                'organization_id' => $invitation->organization_id,
                'status' => 'active',
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
            ]);

            $membership->roles()->attach($invitation->global_role_id);

            $invitation->forceFill(['accepted_at' => now()])->save();

            return new AcceptedInvitation($user, $membership, $accountCreated);
        });
    }

    private function assertPending(Invitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw DomainException::conflict(
                'INVITATION_ALREADY_ACCEPTED',
                __('This invitation has already been accepted.'),
            );
        }

        if ($invitation->revoked_at !== null) {
            throw DomainException::notFound(
                'INVITATION_NOT_FOUND',
                __('This invitation does not exist.'),
            );
        }

        if ($invitation->expires_at->isPast()) {
            throw new DomainException(
                'INVITATION_EXPIRED',
                __('This invitation has expired.'),
                410,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $registration
     */
    private function assertRegistrationIsComplete(array $registration): void
    {
        $missing = array_diff(['first_name', 'last_name', 'password'], array_keys(array_filter($registration)));

        if ($missing !== []) {
            throw DomainException::unprocessable(
                'VALIDATION_ERROR',
                __('An account must be created to accept this invitation.'),
            );
        }
    }

    private function emailsMatch(string $a, string $b): bool
    {
        return mb_strtolower($a) === mb_strtolower($b);
    }
}
