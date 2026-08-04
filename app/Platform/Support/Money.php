<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Platform\Exceptions\DomainException;

/**
 * Montant entier dans l'unité la plus petite de sa devise.
 *
 * Jamais de flottant : sur un registre, les erreurs d'arrondi deviennent des
 * écarts irréconciliables.
 *
 * **Le franc CFA n'a pas de centime.** 1 000 XAF vaut `1000`, pas `100000`.
 * C'est le piège le plus coûteux de ce module, et il est invisible en
 * développement où les montants sont inventés : d'où l'exposant porté
 * explicitement par la devise, et aucune conversion implicite.
 *
 * Vit dans le noyau partagé, et non dans un module : Billing en a besoin pour
 * ses factures, la couche de paiement pour ses montants. Le dupliquer créerait
 * deux définitions de l'exposant, et un `assertSameCurrency` qui ne protégerait
 * plus rien à la frontière entre les deux.
 *
 * @see docs/03-services/billing/02-data-model.md
 */
final readonly class Money
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    public static function of(int $amount, ?string $currency = null): self
    {
        $currency = mb_strtoupper($currency ?? (string) config('sekuu.default_currency'));

        if (! is_array(config('sekuu.currencies.'.$currency))) {
            throw DomainException::unprocessable(
                'CURRENCY_NOT_SUPPORTED',
                __('payments::messages.currency_not_supported', ['currency' => $currency]),
            );
        }

        return new self($amount, $currency);
    }

    public static function zero(?string $currency = null): self
    {
        return self::of(0, $currency);
    }

    public function exponent(): int
    {
        return (int) config('sekuu.currencies.'.$this->currency.'.exponent', 2);
    }

    public function plus(self $other): self
    {
        return new self($this->amount + $this->assertSameCurrency($other), $this->currency);
    }

    public function minus(self $other): self
    {
        return new self($this->amount - $this->assertSameCurrency($other), $this->currency);
    }

    /**
     * Multiplication par un ratio, arrondie au demi supérieur.
     *
     * Utilisée pour la TVA et la proration. L'arrondi est explicite ici plutôt
     * que dispersé chez les appelants : deux règles d'arrondi différentes dans
     * un même module produisent des totaux qui ne se recomposent pas.
     */
    public function multipliedBy(float $ratio): self
    {
        return new self((int) round($this->amount * $ratio), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Borne un montant à zéro. Un crédit supérieur au dû ne produit pas une
     * facture négative : le reliquat reste au registre.
     */
    public function atLeastZero(): self
    {
        return $this->amount < 0 ? new self(0, $this->currency) : $this;
    }

    public function min(self $other): self
    {
        return $this->amount <= $this->assertSameCurrency($other) ? $this : $other;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /**
     * Représentation lisible : `45 000 XAF`, `10,00 EUR`.
     */
    public function format(): string
    {
        $exponent = $this->exponent();

        $value = $exponent === 0
            ? number_format($this->amount, 0, ',', ' ')
            : number_format($this->amount / (10 ** $exponent), $exponent, ',', ' ');

        return $value.' '.$this->currency;
    }

    /**
     * @return array{amount: int, currency: string, currency_exponent: int, formatted: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'currency_exponent' => $this->exponent(),
            'formatted' => $this->format(),
        ];
    }

    /**
     * Additionner des XAF et des EUR n'a aucun sens et doit échouer bruyamment,
     * pas produire un nombre.
     */
    private function assertSameCurrency(self $other): int
    {
        if ($other->currency !== $this->currency) {
            throw DomainException::unprocessable(
                'CURRENCY_MISMATCH',
                __('payments::messages.currency_mismatch'),
            );
        }

        return $other->amount;
    }
}
