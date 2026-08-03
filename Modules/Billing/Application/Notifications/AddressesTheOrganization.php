<?php

declare(strict_types=1);

namespace Modules\Billing\Application\Notifications;

use App\Platform\Contracts\IdentityContract;
use Illuminate\Support\Facades\Log;

/**
 * Ajoute le destinataire aux événements qui doivent produire un message.
 *
 * Billing ne connaît ni utilisateurs ni adresses : il demande le contact à
 * Identity par son **contrat public**, jamais en lisant sa table.
 *
 * Le contact est ensuite **porté par l'événement**, ce qui permet à Notify de
 * rester ignorant d'Identity — et rend l'événement explicable des mois plus
 * tard : il dit à qui l'on a écrit, pas qui serait destinataire aujourd'hui.
 *
 * @see docs/03-services/billing/04-events.md
 */
trait AddressesTheOrganization
{
    /**
     * @param  array<string, mixed>  $variables  variables du template
     * @param  bool  $withPhone  n'inclure le numéro que lorsque le SMS se justifie
     * @return array<string, mixed>
     */
    protected function addressed(
        ?string $organizationId,
        array $variables = [],
        bool $withPhone = false,
    ): array {
        if ($organizationId === null) {
            return ['variables' => $variables];
        }

        $contact = app(IdentityContract::class)->billingContact($organizationId);

        if ($contact === null) {
            // Une organisation sans contact ne recevra rien. Cela doit se voir :
            // sur un modèle prépayé, un client qu'on ne peut pas prévenir est un
            // client qu'on va perdre sans jamais savoir pourquoi.
            Log::warning('Organisation sans contact de facturation joignable.', [
                'organization_id' => $organizationId,
            ]);

            return ['variables' => $variables];
        }

        return [
            'recipient' => $contact->email,
            // Un canal sans coordonnée est simplement ignoré par Notify : c'est
            // ainsi qu'on limite le SMS aux échéances où il se justifie, sans
            // dupliquer les templates.
            'phone' => $withPhone ? $contact->phone : null,
            'user_id' => $contact->userId,
            'locale' => $contact->locale,
            'variables' => $variables + [
                'first_name' => $contact->firstName,
                'organization_name' => $contact->organizationName,
            ],
        ];
    }
}
