<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Drivers;

use App\Platform\Exceptions\DomainException;
use Illuminate\Http\Client\ConnectionException;

/**
 * Comment se lit une panne de fournisseur.
 *
 * Deux questions, et une seule compte pour décider de la suite : la requête
 * a-t-elle atteint le modèle ?
 *
 * ## Pourquoi cette classe existe
 *
 * Laravel lève la même `ConnectionException` pour deux faits opposés :
 *
 *  - **le serveur n'a jamais répondu** — DNS, connexion refusée. Rien n'a été
 *    généré, rien n'est facturé, on peut aller ailleurs ;
 *  - **le délai a expiré en attendant la réponse** — la requête est arrivée, le
 *    modèle produit peut-être encore. Les jetons sont facturés qu'on lise la
 *    réponse ou non.
 *
 * Les confondre coûte de l'argent dans un sens et de la fiabilité dans l'autre.
 *
 * ## Le doute penche vers « c'est arrivé »
 *
 * Quand le message ne permet pas de trancher, on suppose que la requête est
 * partie et on **n'essaie pas ailleurs**. C'est l'ADR-0008 mot pour mot :
 * *l'incertitude compte comme un appel abouti.*
 *
 * Se tromper dans ce sens coûte une génération perdue. Se tromper dans l'autre
 * paie deux fois, et rend une réponse différente de celle qui arrivait
 * peut-être.
 *
 * @see docs/04-decisions/adr-0016-ai-spend-and-privacy.md
 */
final class ProviderFailure
{
    /**
     * Les signatures qui prouvent que **rien n'a été atteint**.
     *
     * La liste est délibérément courte : elle n'accueille que ce dont on est
     * certain. Tout le reste tombe dans le cas prudent.
     */
    private const NEVER_ARRIVED = [
        'could not resolve host',
        'couldn\'t resolve host',
        'connection refused',
        'failed to connect',
        'no route to host',
        'network is unreachable',
        'ssl connect error',
        'certificate',
    ];

    public static function from(ConnectionException $e): DomainException
    {
        $message = mb_strtolower($e->getMessage());

        foreach (self::NEVER_ARRIVED as $signature) {
            if (str_contains($message, $signature)) {
                return new DomainException('AI_PROVIDER_UNREACHABLE', mb_substr($e->getMessage(), 0, 500), 503);
            }
        }

        return new DomainException('AI_PROVIDER_TIMEOUT', mb_substr($e->getMessage(), 0, 500), 504);
    }

    /**
     * Le crédit du compte est épuisé chez le fournisseur.
     *
     * ## Pourquoi ce n'est pas un `429` comme les autres
     *
     * La plupart des fournisseurs rendent le même statut pour « trop d'appels
     * par minute » et pour « votre compte n'a plus d'argent ». Les confondre
     * fait réessayer indéfiniment chez un compte à sec, et envoie régénérer une
     * clé qui n'a rien de cassé.
     *
     * L'un se résout tout seul en quelques secondes ; l'autre demande une carte
     * bancaire.
     */
    public static function looksLikeExhaustedCredit(string $body): bool
    {
        $texte = mb_strtolower($body);

        foreach (['insufficient_quota', 'insufficient quota', 'credit balance', 'insufficient_credit',
            'exceeded your current quota', 'billing', 'payment required'] as $signature) {
            if (str_contains($texte, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un refus de modération, reconnu au corps de la réponse.
     *
     * Les fournisseurs ne s'accordent ni sur le statut ni sur le nom du champ,
     * mais tous nomment la chose. La reconnaissance est ici, partagée : deux
     * listes divergentes finiraient par traiter le même refus différemment
     * selon le fournisseur, ce qui est exactement ce qu'on veut éviter.
     */
    public static function looksLikeModeration(string $body): bool
    {
        $texte = mb_strtolower($body);

        foreach (['content_policy', 'content_filter', 'moderation', 'safety', 'blocked', 'refusal'] as $signature) {
            if (str_contains($texte, $signature)) {
                return true;
            }
        }

        return false;
    }
}
