<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Webhooks;

use Illuminate\Support\Carbon;

/**
 * Retour de livraison, exprimé dans le vocabulaire de la plateforme.
 */
final readonly class NormalisedDeliveryEvent
{
    /**
     * @param  string  $type  delivered | bounced | complained | rejected | opened | clicked | unsubscribed
     * @param  bool  $permanentFailure  Vrai pour un rebond dur : la destination doit être supprimée.
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public ?string $providerMessageId,
        public ?string $providerEventId = null,
        public ?string $destination = null,
        public bool $permanentFailure = false,
        public ?Carbon $occurredAt = null,
        public array $payload = [],
    ) {}
}
