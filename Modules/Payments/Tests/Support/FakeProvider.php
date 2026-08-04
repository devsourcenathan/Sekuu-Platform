<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Modules\Payments\Domain\Models\PaymentAttempt;
use Modules\Payments\Infrastructure\Providers\ChargeOutcome;
use Modules\Payments\Infrastructure\Providers\ChargeRequest;
use Modules\Payments\Infrastructure\Providers\PaymentProvider;

/**
 * Agrégateur factice, pour éprouver la règle de bascule sans réseau.
 *
 * Les tests de bascule n'ont pas besoin de Tranzak : ils ont besoin de deux
 * agrégateurs dont on contrôle exactement l'issue.
 */
abstract class FakeProvider implements PaymentProvider
{
    /** @var array<string, list<ChargeOutcome>> */
    public static array $queued = [];

    /** @var list<string> */
    public static array $charged = [];

    /** @var array<string, ChargeOutcome> */
    public static array $polls = [];

    public static function reset(): void
    {
        self::$queued = [];
        self::$charged = [];
        self::$polls = [];
    }

    public static function willReturn(string $provider, ChargeOutcome ...$outcomes): void
    {
        self::$queued[$provider] = array_merge(self::$queued[$provider] ?? [], $outcomes);
    }

    public static function willPoll(string $provider, ChargeOutcome $outcome): void
    {
        self::$polls[$provider] = $outcome;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supports(string $operator): bool
    {
        return true;
    }

    public function charge(ChargeRequest $request): ChargeOutcome
    {
        self::$charged[] = $this->name();

        $queued = self::$queued[$this->name()] ?? [];

        if ($queued === []) {
            return ChargeOutcome::prompted('ref-'.$this->name().'-'.count(self::$charged));
        }

        $outcome = array_shift($queued);
        self::$queued[$this->name()] = $queued;

        return $outcome;
    }

    public function poll(PaymentAttempt $attempt): ChargeOutcome
    {
        return self::$polls[$this->name()]
            ?? ChargeOutcome::processing($attempt->provider_ref);
    }

    public function findByMerchantReference(string $reference): ChargeOutcome
    {
        return self::$polls[$this->name()]
            ?? ChargeOutcome::unknown('Aucune transaction pour '.$reference);
    }
}
