<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Webhooks;

use Illuminate\Http\Request;

/**
 * Frontière avec les callbacks des agrégateurs.
 *
 * Contrairement à Notify, un callback n'est **jamais** la seule source
 * d'information : le sondage tourne en parallèle. Un callback perdu retarde une
 * confirmation, il ne la fait pas disparaître.
 */
interface PaymentWebhookHandler
{
    public function provider(): string;

    public function verify(Request $request): bool;

    /**
     * Identifiant stable de l'événement, pour la déduplication.
     *
     * Elle est une protection de **sécurité** autant que de propreté : chez un
     * agrégateur qui authentifie par secret partagé plutôt que par signature,
     * elle est ce qui empêche le rejeu.
     */
    public function eventId(Request $request): string;

    /**
     * Référence de la transaction chez l'agrégateur, pour retrouver la
     * tentative concernée.
     */
    public function providerRef(Request $request): ?string;
}
