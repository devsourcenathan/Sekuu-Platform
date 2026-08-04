<?php

declare(strict_types=1);

namespace App\Platform\Contracts;

/**
 * Ce que le propriétaire d'un objet répond quand on lui demande s'il rend
 * l'argent.
 *
 * Symétrique de [PayableQuote], et pour la même raison : la couche de paiement
 * ne peut pas trancher une question métier — la formation a-t-elle été suivie,
 * le délai de rétractation est-il écoulé ?
 *
 * Deux réponses seulement. Il n'existe pas de « oui, mais moins » : un
 * propriétaire qui veut rembourser partiellement demande le montant qu'il veut
 * rembourser. Laisser la cotation corriger le montant à la baisse rendrait
 * illisible ce qui a été décidé, et par qui.
 */
final readonly class RefundDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $refusalCode,
        public ?string $refusalMessage,
    ) {}

    public static function allowed(): self
    {
        return new self(true, null, null);
    }

    /**
     * Le code remonte tel quel à l'appelant : c'est le propriétaire qui refuse,
     * et lui seul sait le dire dans ses termes.
     */
    public static function refused(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
