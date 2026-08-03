<?php

declare(strict_types=1);

namespace App\Platform\Events;

/**
 * À utiliser par les modules émetteurs.
 *
 * Publier reste une opération sans effet observable pour l'appelant : si aucun
 * consommateur n'écoute, rien ne se passe et rien n'échoue.
 */
trait PublishesDomainEvents
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function publish(string $type, array $data = [], ?string $organizationId = null): DomainEvent
    {
        $event = new DomainEvent($type, $data, $organizationId);

        event($event);

        return $event;
    }
}
