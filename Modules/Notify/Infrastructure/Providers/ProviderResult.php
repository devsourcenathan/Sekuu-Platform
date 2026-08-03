<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

final readonly class ProviderResult
{
    private function __construct(
        public bool $accepted,
        public ?string $messageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        /** Un échec infrastructurel autorise la bascule et le réessai ; un rejet métier, non. */
        public bool $retryable = false,
        public ?float $costAmount = null,
        public ?string $costCurrency = null,
        /** Le fournisseur sait que cette destination ne reçoit plus. */
        public bool $suppressesDestination = false,
    ) {}

    /**
     * Certains rejets valent constat de mort : le fournisseur a déjà enregistré
     * un rebond dur sur cette adresse. Attendre un webhook qui ne viendra pas
     * laisserait la liste de suppression incomplète.
     */
    public function suppressingDestination(): self
    {
        return new self(
            $this->accepted,
            $this->messageId,
            $this->errorCode,
            $this->errorMessage,
            $this->retryable,
            $this->costAmount,
            $this->costCurrency,
            suppressesDestination: true,
        );
    }

    public static function accepted(?string $messageId = null, ?float $cost = null, ?string $currency = null): self
    {
        return new self(true, messageId: $messageId, costAmount: $cost, costCurrency: $currency);
    }

    /**
     * Rejet métier : numéro invalide, contenu refusé. Réessayer ne changera
     * rien, et chaque tentative coûte.
     */
    public static function rejected(string $errorCode, string $message): self
    {
        return new self(false, errorCode: $errorCode, errorMessage: $message, retryable: false);
    }

    /**
     * Échec infrastructurel : timeout, 5xx. Réessayable, et éligible à la
     * bascule vers un autre fournisseur.
     */
    public static function failed(string $errorCode, string $message): self
    {
        return new self(false, errorCode: $errorCode, errorMessage: $message, retryable: true);
    }
}
