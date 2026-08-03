<?php

declare(strict_types=1);

namespace Modules\Billing\Domain;

/**
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
enum SubscriptionStatus: string
{
    /**
     * Créé, en attente du premier paiement. Aucun accès.
     *
     * Distinct de `suspended`, qui implique un accès perdu : un abonnement qui
     * n'a jamais été payé n'a jamais rien eu à perdre. La nuance n'est pas
     * cosmétique — elle décide de ce qu'on écrit au client, et elle occupe
     * l'unique place d'une organisation, ce qui empêche d'en créer un second.
     */
    case Pending = 'pending';

    case Trialing = 'trialing';
    case Active = 'active';

    /** Période échue, accès **maintenu** le temps de payer. */
    case Grace = 'grace';

    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * L'accès au produit est-il ouvert ?
     *
     * `Grace` est ouvert : sans période de grâce, un oubli d'une journée
     * devient une interruption d'activité. Avec un paiement automatique par
     * carte, couper sec serait acceptable — l'échec y est rare et signale un
     * vrai problème. En Mobile Money, l'échéance tombe alors que le client n'a
     * rien d'automatique : il doit y penser, avoir du crédit, et être joignable.
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::Grace => true,
            default => false,
        };
    }

    /**
     * Un abonnement vivant occupe l'unique place d'une organisation.
     *
     * `Pending` en fait partie **sans donner d'accès** : sans cela, une
     * organisation qui souscrit puis ne paie pas pourrait souscrire à nouveau,
     * et se retrouverait avec deux abonnements et deux factures ouvertes.
     */
    public function isAlive(): bool
    {
        return match ($this) {
            self::Pending, self::Trialing, self::Active, self::Grace => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function aliveValues(): array
    {
        return [self::Pending->value, self::Trialing->value, self::Active->value, self::Grace->value];
    }
}
