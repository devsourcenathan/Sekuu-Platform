<?php

declare(strict_types=1);

namespace Modules\Notify\Application\Sending;

use Modules\Notify\Domain\Channel;

/**
 * Intention d'envoi.
 *
 * L'appelant fournit les coordonnées dont il dispose, **pas** le canal : c'est
 * le template qui décide par où le message part. Fournir une adresse et un
 * numéro n'impose donc rien ; cela rend simplement les deux canaux possibles.
 */
final readonly class SendRequest
{
    /**
     * @param  array<string, string>  $recipients  canal => destination
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public string $templateKey,
        public array $recipients,
        public array $variables = [],
        public ?string $userId = null,
        public ?string $organizationId = null,
        public ?string $locale = null,
        public ?string $idempotencyKey = null,
        public ?string $sourceEventId = null,
        public ?string $scheduledFor = null,
    ) {}

    /**
     * Raccourci pour les messages qui n'ont qu'une adresse.
     *
     * @param  array<string, mixed>  $variables
     */
    public static function toEmail(
        string $templateKey,
        string $email,
        array $variables = [],
        ?string $userId = null,
        ?string $organizationId = null,
        ?string $locale = null,
        ?string $idempotencyKey = null,
        ?string $sourceEventId = null,
    ): self {
        return new self(
            templateKey: $templateKey,
            recipients: [Channel::EMAIL => $email],
            variables: $variables,
            userId: $userId,
            organizationId: $organizationId,
            locale: $locale,
            idempotencyKey: $idempotencyKey,
            sourceEventId: $sourceEventId,
        );
    }

    public function destinationFor(string $channel): ?string
    {
        $destination = $this->recipients[$channel] ?? null;

        return $destination === '' ? null : $destination;
    }

    /**
     * La clé d'idempotence est déclinée par canal : un même événement produit
     * un email **et** un SMS, qui sont deux messages distincts.
     */
    public function idempotencyKeyFor(string $channel): ?string
    {
        return $this->idempotencyKey === null ? null : $this->idempotencyKey.':'.$channel;
    }
}
