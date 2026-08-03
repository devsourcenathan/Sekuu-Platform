<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Personne à qui écrire au sujet de l'argent d'une organisation.
 *
 * Volontairement pauvre : le strict nécessaire pour rendre un message. Un objet
 * plus riche ferait fuiter le modèle d'Identity dans les modules appelants, et
 * rendrait le contrat coûteux à honorer le jour où Identity devient un service
 * distinct.
 *
 * @see docs/01-overview/architecture.md
 */
final readonly class BillingContact
{
    public function __construct(
        public string $userId,
        public string $organizationName,
        public string $firstName,
        public string $email,
        public ?string $phone,
        public string $locale,
    ) {}
}
