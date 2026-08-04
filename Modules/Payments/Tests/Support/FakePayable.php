<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use App\Platform\Contracts\PayableQuote;
use App\Platform\Contracts\PayableRef;
use App\Platform\Contracts\PayableSource;
use App\Platform\Contracts\PayerContext;
use App\Platform\Contracts\PaymentSettlement;
use App\Platform\Support\Money;

/**
 * Objet payable factice, imitant un produit qui n'est pas Billing.
 *
 * Existe pour éprouver Payments **sans facture ni abonnement** : c'est la
 * démonstration littérale de ce pour quoi l'extraction a été faite.
 */
final class FakePayable implements PayableSource
{
    public const TYPE = 'learn.enrollment';

    /** Prix renvoyé par la cotation. */
    public static int $prix = 15000;

    /** Refus opposé à la cotation, le cas échéant. */
    public static ?array $refus = null;

    /** @var list<string> */
    public static array $regles = [];

    /** @var list<string> */
    public static array $echoues = [];

    public static function reset(): void
    {
        self::$prix = 15000;
        self::$refus = null;
        self::$regles = [];
        self::$echoues = [];
    }

    public function quote(PayableRef $ref, PayerContext $payer): PayableQuote
    {
        if (self::$refus !== null) {
            return PayableQuote::refused(self::$refus[0], self::$refus[1]);
        }

        return PayableQuote::due(
            Money::of(self::$prix, 'XAF'),
            'Sekuu Learn — formation',
        );
    }

    public function settled(PaymentSettlement $settlement): void
    {
        self::$regles[] = $settlement->subject->id;
    }

    public function failed(PaymentSettlement $settlement): void
    {
        self::$echoues[] = $settlement->subject->id;
    }
}
