<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Webhooks;

use Illuminate\Http\Request;

/**
 * Callbacks Tranzak.
 *
 * **`authKey` est un secret partagé transporté dans le corps, pas une
 * signature.** Il prouve que l'émetteur connaît le secret ; il ne dit rien de
 * l'intégrité du corps. Un callback intercepté peut donc être rejoué modifié.
 *
 * Deux conséquences, appliquées sans exception :
 *
 *  1. Le montant d'un callback n'est jamais cru — il est relu chez Tranzak par
 *     sondage. Ce handler ne renvoie donc **aucun** montant ni statut, seulement
 *     de quoi identifier la tentative à réinterroger.
 *  2. La déduplication `(provider, provider_event_id)` est une protection de
 *     sécurité, pas de propreté.
 *
 * @see docs/03-services/billing/05-providers.md
 */
class TranzakWebhookHandler implements PaymentWebhookHandler
{
    public function provider(): string
    {
        return 'tranzak';
    }

    public function verify(Request $request): bool
    {
        $expected = config('payments.tranzak.auth_key');

        // Sans secret configuré, aucun callback n'est accepté. Accepter par
        // défaut ferait d'une variable d'environnement oubliée une porte
        // ouverte sur les paiements.
        if (! is_string($expected) || $expected === '') {
            return false;
        }

        $provided = $request->input('authKey');

        return is_string($provided) && hash_equals($expected, $provided);
    }

    /**
     * Clé de déduplication : `eventType` + ressource, **délibérément pas**
     * l'identifiant de livraison.
     *
     * Le corps réel porte pourtant un `webhookId` par livraison, qui serait le
     * choix naturel — c'est celui retenu pour Notch Pay. Mais Notch Pay **signe**
     * ses callbacks : un rejeu modifié y est impossible.
     *
     * Tranzak n'authentifie que par un secret partagé dans le corps. Un
     * callback capté peut donc être rejoué avec un `webhookId` différent, et
     * serait traité une seconde fois. `eventType` + ressource résiste à cela :
     * le même fait produit la même clé, quel que soit l'habillage.
     *
     * Deux agrégateurs, deux clés — parce qu'ils n'offrent pas les mêmes
     * garanties.
     */
    public function eventId(Request $request): string
    {
        $resource = $this->providerRef($request);
        $type = (string) $request->input('eventType', 'UNKNOWN');

        if ($resource !== null) {
            return $type.':'.$resource;
        }

        // Faute de ressource identifiable, on retombe sur une empreinte du
        // corps. Deux callbacks strictement identiques restent dédupliqués.
        return $type.':'.hash('sha256', $request->getContent());
    }

    public function providerRef(Request $request): ?string
    {
        foreach (['resourceId', 'requestId', 'transactionId'] as $key) {
            $value = $request->input($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $resource = $request->input('resource');

        if (is_array($resource)) {
            foreach (['requestId', 'transactionId', 'id'] as $key) {
                if (is_string($resource[$key] ?? null) && $resource[$key] !== '') {
                    return $resource[$key];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Request $request): array
    {
        // Le secret ne doit pas être conservé en base avec le corps brut : il
        // servirait à forger un callback valide à quiconque lirait la table.
        return array_diff_key($request->all(), ['authKey' => null]);
    }
}
