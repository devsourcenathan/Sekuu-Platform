<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

use Illuminate\Support\Collection;
use Modules\Notify\Domain\Models\Notification;

/**
 * Résultat d'une intention d'envoi, canal par canal.
 *
 * Un message peut partir par email et être bloqué en SMS : un résultat unique
 * ne saurait pas le dire.
 */
final readonly class SendOutcome
{
    /**
     * @param  Collection<int, Notification>  $queued
     * @param  Collection<int, Notification>  $blocked
     * @param  array<string, string>  $skipped  canal => raison
     */
    public function __construct(
        public Collection $queued,
        public Collection $blocked,
        public array $skipped = [],
    ) {}

    public function sentAnything(): bool
    {
        return $this->queued->isNotEmpty();
    }

    public function first(): ?Notification
    {
        return $this->queued->first() ?? $this->blocked->first();
    }

    public function forChannel(string $channel): ?Notification
    {
        return $this->queued->firstWhere('channel', $channel)
            ?? $this->blocked->firstWhere('channel', $channel);
    }

    /**
     * Code d'erreur à remonter lorsque rien n'a pu partir.
     */
    public function blockingReason(): ?string
    {
        return $this->blocked->first()?->failed_reason
            ?? (reset($this->skipped) ?: null);
    }
}
