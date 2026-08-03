<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Contracts;

use App\Platform\Contracts\BillingContact;
use App\Platform\Contracts\IdentityContract;
use Modules\Identity\Domain\Models\Membership;
use Modules\Identity\Domain\Models\Organization;

/**
 * Implémentation locale du contrat d'Identity, tant que la plateforme est un
 * monolithe modulaire.
 *
 * @see docs/01-overview/architecture.md
 */
final class IdentityGateway implements IdentityContract
{
    public function billingContact(string $organizationId): ?BillingContact
    {
        $organization = Organization::query()->find($organizationId);

        if ($organization === null) {
            return null;
        }

        // Le propriétaire le plus ancien. Une organisation en conserve toujours
        // au moins un — `LAST_OWNER_CANNOT_LEAVE` le garantit — ce qui rend ce
        // choix stable dans le temps, contrairement au dernier arrivé.
        //
        // Un champ dédié sur l'organisation serait meilleur : la personne qui
        // administre n'est pas toujours celle qui paie. En attendant, le
        // propriétaire est le seul destinataire dont l'existence est garantie.
        $membership = Membership::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('slug', 'owner'))
            ->with('user')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->first();

        $user = $membership?->user;

        if ($user === null) {
            return null;
        }

        return new BillingContact(
            userId: $user->id,
            organizationName: $organization->name,
            firstName: $user->first_name,
            email: $user->email,
            phone: $user->phone,
            // La langue de l'utilisateur prime sur celle de l'organisation :
            // c'est lui qui lit le message.
            locale: $user->language ?: $organization->locale,
        );
    }
}
