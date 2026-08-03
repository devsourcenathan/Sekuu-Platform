<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Support;

use Illuminate\Http\Request;
use Modules\Billing\Infrastructure\Webhooks\TranzakWebhookHandler;

/**
 * Reprend intégralement la vérification et l'extraction de Tranzak — c'est
 * précisément ce qu'on veut éprouver — sous un nom d'agrégateur factice, pour
 * que le registre de fournisseurs des tests puisse le sonder.
 */
final class FakeWebhookHandler extends TranzakWebhookHandler
{
    public function provider(): string
    {
        return 'primary';
    }

    public function eventId(Request $request): string
    {
        return parent::eventId($request);
    }
}
