<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

/**
 * Intention d'envoi.
 *
 * L'appelant ne choisit **ni le canal, ni le fournisseur** : le canal appartient
 * au template. C'est ce qui permet de basculer un message de l'email vers
 * WhatsApp sans toucher au code appelant.
 */
final readonly class SendRequest
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public string $templateKey,
        public string $recipient,
        public array $variables = [],
        public ?string $userId = null,
        public ?string $organizationId = null,
        public ?string $locale = null,
        public ?string $idempotencyKey = null,
        public ?string $sourceEventId = null,
        public ?string $scheduledFor = null,
    ) {}
}
