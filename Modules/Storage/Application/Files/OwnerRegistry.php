<?php

declare(strict_types=1);

namespace Modules\Storage\Application\Files;

use App\Platform\Contracts\FileOwner;
use App\Platform\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;

/**
 * Résolution `owner_type` → propriétaire de l'objet.
 *
 * Par configuration, jamais par appel croisé : la couche de stockage n'importe
 * aucune implémentation, et un produit s'enregistre sans qu'elle change.
 *
 * Un type inconnu échoue **durement**. Un repli silencieux ferait aboutir un
 * téléversement que personne ne saurait rattacher — des octets facturés sans
 * objet auquel les rendre.
 */
final class OwnerRegistry
{
    /** @var array<string, class-string<FileOwner>> */
    private array $owners = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<FileOwner>  $owner
     */
    public function register(string $ownerType, string $owner): void
    {
        $this->owners[$ownerType] = $owner;
    }

    public function for(string $ownerType): FileOwner
    {
        $owner = $this->owners[$ownerType] ?? null;

        if ($owner === null) {
            throw DomainException::unprocessable(
                'FILE_OWNER_TYPE_UNKNOWN',
                __('storage::messages.owner_type_unknown', ['type' => $ownerType]),
            );
        }

        return $this->container->make($owner);
    }

    public function knows(string $ownerType): bool
    {
        return isset($this->owners[$ownerType]);
    }
}
