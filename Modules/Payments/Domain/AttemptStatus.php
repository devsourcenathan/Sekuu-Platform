<?php

declare(strict_types=1);

namespace Modules\Billing\Domain;

/**
 * État d'une tentative de paiement chez un agrégateur.
 *
 * C'est ici que vit la règle la plus importante du module : **quel état
 * autorise à réessayer chez un autre agrégateur**.
 *
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
enum AttemptStatus: string
{
    /** Enregistrée, agrégateur pas encore appelé. */
    case Created = 'created';

    /** L'agrégateur a refusé la **demande** — le client n'a jamais rien reçu. */
    case Rejected = 'rejected';

    /** L'invite est partie sur le téléphone. */
    case Prompted = 'prompted';

    case Processing = 'processing';

    case Succeeded = 'succeeded';

    /** Le client a refusé, ou son solde est insuffisant. */
    case Failed = 'failed';

    /** Aucune réponse dans le délai : **on ne sait pas**, ce qui n'est pas « échoué ». */
    case Expired = 'expired';

    /**
     * Le seul état qui autorise à essayer l'agrégateur suivant.
     *
     * `Failed` n'en fait pas partie : un solde insuffisant chez MTN le reste
     * quel que soit l'agrégateur qui pose la question. C'est la règle déjà
     * posée pour Notify — un rejet métier ne réussira pas davantage ailleurs —
     * avec ici un enjeu supérieur.
     *
     * `Expired` non plus : le client a peut-être été débité.
     */
    public function allowsFailover(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * Une invite partie interdit toute nouvelle tentative, ailleurs comme
     * ici : le client peut la valider avec dix minutes de retard.
     */
    public function customerWasPrompted(): bool
    {
        return match ($this) {
            self::Created, self::Rejected => false,
            default => true,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Expired, self::Rejected => true,
            default => false,
        };
    }

    /** Doit-on continuer à interroger l'agrégateur ? */
    public function needsPolling(): bool
    {
        return match ($this) {
            self::Prompted, self::Processing => true,
            default => false,
        };
    }
}
