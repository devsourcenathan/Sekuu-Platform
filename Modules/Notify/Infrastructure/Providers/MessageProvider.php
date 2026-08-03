<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Providers;

use Modules\Notify\Domain\Models\Notification;

/**
 * Frontière avec les fournisseurs d'envoi.
 *
 * L'interface existe pour que le domaine ne connaisse aucun fournisseur, et
 * pour que les tests n'aient jamais besoin du réseau.
 */
interface MessageProvider
{
    public function name(): string;

    public function channel(): string;

    /**
     * Un fournisseur sans identifiants n'est pas essayé du tout.
     *
     * Le tenter produirait une tentative vouée à l'échec dans le journal de
     * livraison, et masquerait les vraies pannes derrière du bruit.
     */
    public function isConfigured(): bool;

    public function send(Notification $notification): ProviderResult;
}
