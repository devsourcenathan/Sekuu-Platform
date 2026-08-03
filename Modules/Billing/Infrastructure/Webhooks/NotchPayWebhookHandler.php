<?php

declare(strict_types=1);

namespace Modules\Billing\Infrastructure\Webhooks;

use Illuminate\Http\Request;

/**
 * Callbacks Notch Pay.
 *
 * Contrairement à Tranzak, la vérification est une **vraie signature** :
 * HMAC-SHA256 sur le corps brut, dans l'en-tête `x-notch-signature`. Un
 * callback modifié en transit est donc détectable, ce qu'un secret partagé dans
 * le corps ne permet pas.
 *
 * Le rejeu à l'identique, lui, reste possible : c'est la contrainte d'unicité
 * `(provider, provider_event_id)` qui le neutralise.
 *
 * Cela ne change rien à la règle : **le corps ne décide jamais de l'issue**. Le
 * statut est relu chez Notch Pay. Une signature valide prouve l'origine, pas que
 * le paiement a réussi.
 *
 * @see docs/03-services/billing/05-providers.md
 */
class NotchPayWebhookHandler implements PaymentWebhookHandler
{
    public function provider(): string
    {
        return 'notchpay';
    }

    public function verify(Request $request): bool
    {
        $secret = config('billing.notchpay.webhook_hash');

        // Sans secret configuré, aucun callback n'est accepté. Accepter par
        // défaut ferait d'une variable d'environnement oubliée une porte
        // ouverte sur les paiements.
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $provided = $request->header('x-notch-signature');

        if (! is_string($provided) || $provided === '') {
            return false;
        }

        // Sur le corps **brut** : une signature calculée sur le tableau
        // désérialisé ne vérifierait pas ce qui a réellement transité.
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        // Comparaison en temps constant : un `===` fuit la position du premier
        // octet divergent, et rend la signature devinable par mesure.
        return hash_equals($expected, $provided);
    }

    public function eventId(Request $request): string
    {
        $type = (string) $request->input('type', 'unknown');
        $id = $request->input('data.id');

        if (is_string($id) && $id !== '') {
            return $type.':'.$id;
        }

        return $type.':'.hash('sha256', $request->getContent());
    }

    public function providerRef(Request $request): ?string
    {
        foreach (['data.reference', 'data.id', 'reference'] as $key) {
            $value = $request->input($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
