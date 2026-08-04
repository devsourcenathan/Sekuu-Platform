<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Providers;

use Modules\Payments\Domain\AttemptStatus;

/**
 * Ce qu'un agrégateur a répondu, traduit dans le vocabulaire du module.
 *
 * `customerPrompted` est la donnée décisive, et **aucun agrégateur ne
 * l'expose** : elle est déduite de l'issue de l'appel. Les fabriques nommées
 * ci-dessous existent pour que cette déduction soit faite à un seul endroit
 * par adaptateur, et relisible.
 *
 * @see docs/03-services/payments/05-providers.md
 */
final readonly class ChargeOutcome
{
    private function __construct(
        public AttemptStatus $status,
        public bool $customerPrompted,
        public ?string $providerRef = null,
        public ?string $rawStatus = null,
        public ?string $failureCode = null,
        public ?string $failureReason = null,
        public ?int $grossAmount = null,
        public ?int $feeAmount = null,
        public ?int $netAmount = null,
    ) {}

    /**
     * L'agrégateur a refusé la **demande** : authentification, validation,
     * opérateur non couvert. Le client n'a jamais rien reçu.
     *
     * **Le seul cas qui autorise à essayer l'agrégateur suivant.**
     */
    public static function rejected(
        string $code,
        string $reason,
        ?string $raw = null,
        ?string $providerRef = null,
    ): self {
        return new self(
            status: AttemptStatus::Rejected,
            // Une référence peut exister sans qu'aucune invite ne soit partie :
            // chez un agrégateur en deux étapes, la transaction est créée avant
            // d'être présentée au client.
            customerPrompted: false,
            providerRef: $providerRef,
            rawStatus: $raw,
            failureCode: $code,
            failureReason: $reason,
        );
    }

    /** L'invite est partie sur le téléphone. Plus aucune bascule. */
    public static function prompted(string $providerRef, ?string $raw = null): self
    {
        return new self(
            status: AttemptStatus::Prompted,
            customerPrompted: true,
            providerRef: $providerRef,
            rawStatus: $raw,
        );
    }

    public static function processing(?string $providerRef = null, ?string $raw = null): self
    {
        return new self(
            status: AttemptStatus::Processing,
            customerPrompted: true,
            providerRef: $providerRef,
            rawStatus: $raw,
        );
    }

    public static function succeeded(
        string $providerRef,
        ?int $gross = null,
        ?int $fee = null,
        ?int $net = null,
        ?string $raw = null,
    ): self {
        return new self(
            status: AttemptStatus::Succeeded,
            customerPrompted: true,
            providerRef: $providerRef,
            rawStatus: $raw,
            grossAmount: $gross,
            feeAmount: $fee,
            netAmount: $net,
        );
    }

    /**
     * Rejet **métier** : solde insuffisant, code erroné, annulation du client.
     *
     * Ne bascule pas : il ne réussira pas davantage chez un autre agrégateur,
     * et chaque tentative coûte. C'est la règle déjà posée pour Notify.
     */
    public static function failed(string $code, string $reason, ?string $providerRef = null, ?string $raw = null): self
    {
        return new self(
            status: AttemptStatus::Failed,
            customerPrompted: true,
            providerRef: $providerRef,
            rawStatus: $raw,
            failureCode: $code,
            failureReason: $reason,
        );
    }

    /**
     * On ne sait pas — temporisation, panne réseau, statut inconnu.
     *
     * Traité comme « invite partie » : le défaut penche du côté qui ne débite
     * pas deux fois. Ne pas encaisser est un incident réparable ; encaisser
     * deux fois est une faute que le client découvre sur son relevé.
     */
    public static function unknown(string $reason, ?string $providerRef = null, ?string $raw = null): self
    {
        return new self(
            status: AttemptStatus::Processing,
            customerPrompted: true,
            providerRef: $providerRef,
            rawStatus: $raw,
            failureCode: 'PROVIDER_UNRESPONSIVE',
            failureReason: $reason,
        );
    }
}
