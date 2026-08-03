<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Events;

use App\Platform\Events\PublishesDomainEvents;

/**
 * Publication des faits d'Identity.
 *
 * Identity ne connaît ni template, ni canal, ni fournisseur : il déclare un
 * fait. C'est Notify qui décide de ce qui part — et ajouter un message ne
 * modifie donc jamais ce fichier.
 *
 * @see docs/03-services/notify/04-events.md
 */
final class IdentityEvents
{
    use PublishesDomainEvents;

    public function userRegistered(string $userId, string $email, string $firstName, string $locale, string $verificationUrl): void
    {
        $this->publish('identity.user.registered', [
            'recipient' => $email,
            'user_id' => $userId,
            'locale' => $locale,
            'variables' => [
                'first_name' => $firstName,
                'verification_url' => $verificationUrl,
            ],
        ]);
    }

    public function emailVerificationRequested(string $userId, string $email, string $firstName, string $locale, string $verificationUrl, int $expiresInHours): void
    {
        $this->publish('identity.email.verification_requested', [
            'recipient' => $email,
            'user_id' => $userId,
            'locale' => $locale,
            'variables' => [
                'first_name' => $firstName,
                'verification_url' => $verificationUrl,
                'expires_in_hours' => $expiresInHours,
            ],
        ]);
    }

    public function passwordResetRequested(string $userId, string $email, string $firstName, string $locale, string $resetUrl, int $expiresInHours): void
    {
        $this->publish('identity.password.reset_requested', [
            'recipient' => $email,
            'user_id' => $userId,
            'locale' => $locale,
            'variables' => [
                'first_name' => $firstName,
                'reset_url' => $resetUrl,
                'expires_in_hours' => $expiresInHours,
            ],
        ]);
    }

    public function passwordChanged(string $userId, string $email, string $firstName, string $locale, ?string $ipAddress = null): void
    {
        $this->publish('identity.password.changed', [
            'recipient' => $email,
            'user_id' => $userId,
            'locale' => $locale,
            'variables' => [
                'first_name' => $firstName,
                'changed_at' => now()->toIso8601ZuluString(),
                'ip_address' => $ipAddress,
            ],
        ]);
    }

    public function invitationSent(string $organizationId, string $email, string $organizationName, string $inviterName, string $role, string $acceptUrl, string $expiresAt, string $locale): void
    {
        $this->publish('identity.invitation.sent', [
            'recipient' => $email,
            'locale' => $locale,
            'variables' => [
                'organization_name' => $organizationName,
                'inviter_name' => $inviterName,
                'role' => $role,
                'accept_url' => $acceptUrl,
                'expires_at' => $expiresAt,
            ],
        ], $organizationId);
    }

    public function organizationCreated(string $organizationId, string $userId, string $email, string $firstName, string $organizationName, string $locale): void
    {
        $this->publish('identity.organization.created', [
            'recipient' => $email,
            'user_id' => $userId,
            'locale' => $locale,
            'variables' => [
                'first_name' => $firstName,
                'organization_name' => $organizationName,
            ],
        ], $organizationId);
    }
}
