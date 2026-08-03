<?php

declare(strict_types=1);

namespace App\Platform\Events;

use App\Platform\Http\RequestId;
use Illuminate\Support\Str;

/**
 * Événement de domaine publié par un module.
 *
 * Il est volontairement **générique** : le type est une chaîne, pas une classe.
 * Un module consommateur n'a donc aucune dépendance de compilation vers le
 * module émetteur — c'est ce qui permettra d'extraire l'un ou l'autre sans
 * modifier le second.
 *
 * @see docs/01-overview/architecture.md
 * @see docs/03-services/notify/04-events.md
 */
final class DomainEvent
{
    public readonly string $eventId;

    public readonly string $occurredAt;

    public readonly string $requestId;

    /**
     * @param  string  $type  Format `{module}.{ressource}.{action}`
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
        public readonly ?string $organizationId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? 'evt_'.Str::lower(Str::random(16));
        $this->occurredAt = now()->toIso8601ZuluString();

        // Propagé jusqu'au consommateur : une notification reste rattachable à
        // la requête HTTP qui l'a déclenchée, à travers deux modules.
        $this->requestId = RequestId::current();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->eventId,
            'type' => $this->type,
            'occurred_at' => $this->occurredAt,
            'request_id' => $this->requestId,
            'organization_id' => $this->organizationId,
            'data' => $this->data,
        ];
    }
}
