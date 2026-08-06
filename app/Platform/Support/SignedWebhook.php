<?php

declare(strict_types=1);

namespace App\Platform\Support;

use LogicException;

/**
 * Ce qu'une livraison sortante a d'identique d'un module à l'autre.
 *
 * ## Pourquoi extraire ces deux fonctions et pas le reste
 *
 * Les tables diffèrent — une livraison de paiement porte un `payment_intent_id`,
 * une livraison d'IA une `generation_id` — et les faire converger de force
 * donnerait une table qui ne décrit bien ni l'une ni l'autre.
 *
 * Mais **la signature et le garde-fou de test n'ont aucune raison de différer**.
 * Deux implémentations du même HMAC finiraient par diverger sur un détail —
 * l'ordre des secrets, le séparateur, l'encodage — et un intégrateur qui a écrit
 * un vérificateur pour Payments verrait celui d'AI le rejeter sans comprendre.
 *
 * @see docs/03-services/payments/07-external-api.md
 */
final class SignedWebhook
{
    /**
     * Signature HMAC-SHA256 sur le corps **brut**.
     *
     * ## Le corps est signé tel qu'il part
     *
     * Signer une représentation puis en envoyer une autre — un tableau
     * réordonné, un espace de plus après une virgule — produit une signature que
     * le produit ne peut pas reproduire. L'appelant sérialise **une fois**, et
     * passe cette chaîne ici puis dans le corps de la requête.
     *
     * ## Deux signatures pendant une rotation
     *
     * La nouvelle d'abord, l'ancienne ensuite. Le produit accepte celle qu'il
     * connaît et change de secret quand il veut : aucun message n'est rejeté
     * entre-temps.
     *
     * Une coupure nette aurait été plus simple à écrire, et aurait fait échouer
     * toutes les livraisons d'un produit qui déploie une heure plus tard.
     *
     * Le format `v1=…,v1=…` est repris de ce que font les plateformes de
     * paiement, précisément parce qu'un intégrateur l'aura déjà vu ailleurs.
     *
     * @param  list<string>  $secrets  Le courant d'abord
     */
    public static function signature(array $secrets, string $body): string
    {
        return implode(',', array_map(
            static fn (string $secret): string => 'v1='.hash_hmac('sha256', $body, $secret),
            $secrets,
        ));
    }

    /**
     * Aucune livraison réelle depuis la suite de tests.
     *
     * ## Pourquoi cette garde est structurelle et non disciplinaire
     *
     * Les identifiants des fournisseurs sont neutralisés dans `phpunit.xml`.
     * Ici, la destination vient de la base, écrite par le test lui-même : un
     * `Http::fake()` oublié suffit donc à faire sortir une requête vers un hôte
     * réel, et l'histoire de ce dépôt dit que cela finit par arriver.
     *
     * Seuls les domaines réservés aux tests passent.
     */
    public static function assertTestSafeHost(string $url): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        $reserved = $host === 'localhost'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.example')
            || str_ends_with($host, '.invalid')
            || str_ends_with($host, '.localhost');

        if (! $reserved) {
            throw new LogicException(
                "Livraison vers un hôte réel depuis les tests : {$host}. "
                .'Utilisez un domaine réservé (.test, .example) ou Http::fake().'
            );
        }
    }
}
