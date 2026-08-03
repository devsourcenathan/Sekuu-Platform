<?php

declare(strict_types=1);

namespace Modules\Notify\Infrastructure\Webhooks;

use Illuminate\Http\Request;

/**
 * Traduit les retours d'un fournisseur en événements normalisés.
 *
 * Chaque fournisseur a son propre schéma de signature et son propre
 * vocabulaire ; le domaine n'en connaît aucun.
 */
interface WebhookHandler
{
    public function provider(): string;

    /**
     * La vérification porte sur le **corps brut**, avant tout parsing : un
     * corps re-sérialisé ne produit pas la même signature.
     */
    public function verify(Request $request): bool;

    /**
     * @return list<NormalisedDeliveryEvent>
     */
    public function parse(Request $request): array;
}
