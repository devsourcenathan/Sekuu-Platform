<?php

declare(strict_types=1);

namespace Modules\Storage\Infrastructure\Drivers;

use DateTimeImmutable;

/**
 * Une autorisation d'écriture, bornée dans le temps et à un objet.
 *
 * La méthode, l'URL et les en-têtes viennent du pilote : S3 signe un `PUT`,
 * Google Drive ouvre une session reprenable par un `POST`. Un client qui suit
 * ces trois champs sans les interpréter fonctionne avec les deux.
 *
 * Les en-têtes ne sont **pas une suggestion** : la signature couvre le
 * `Content-Type`, et un client qui écrit d'autres octets sous un autre type
 * verra son écriture refusée par le magasin — avant même la confirmation. Une
 * borne de plus, posée là où elle ne dépend plus de notre code.
 */
final readonly class UploadTicket
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public DateTimeImmutable $expiresAt,
    ) {}

    /**
     * Le mode dégradé : les octets traversent la plateforme.
     *
     * Réservé aux pilotes incapables de téléversement direct, et borné par
     * {@see DriverCapabilities::PROXY_MAX_BYTES}.
     */
    public static function proxy(string $url, DateTimeImmutable $expiresAt): self
    {
        return new self('proxy', $url, [], $expiresAt);
    }

    public function isProxy(): bool
    {
        return $this->method === 'proxy';
    }
}
