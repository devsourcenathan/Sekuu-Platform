# Brancher un module sur Sekuu Storage

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Trois gestes : implémenter un contrat, ajouter une ligne de configuration,
appeler.

---

# 1. Le contrat d'un objet porteur de fichiers

Dans `app/Platform/Contracts/`, à côté de `PayableSource`. Quatre méthodes.

```php
interface FileOwner
{
    /**
     * Cet acteur peut-il attacher un fichier à cet objet, et sous quelles
     * bornes ? Sans effet de bord.
     */
    public function policy(FileRef $ref, RequestContext $actor): FilePolicy;

    /** Cet acteur peut-il lire ce fichier ? */
    public function mayRead(FileRef $ref, RequestContext $actor): bool;

    /** Les octets sont constatés. Appelée **dans la transaction**. */
    public function attached(AttachedFile $file): void;

    /** Le fichier est supprimé. */
    public function detached(AttachedFile $file): void;
}
```

`policy()` porte tout le poids, comme `quote()` chez Payments. Elle répond à
« qui, quoi, combien » en un seul aller :

```php
final class LessonFiles implements FileOwner
{
    public const TYPE = 'learn.lesson';

    public function policy(FileRef $ref, RequestContext $actor): FilePolicy
    {
        $lesson = Lesson::query()->find($ref->id);

        if ($lesson === null || ! $actor->canEdit($lesson->course)) {
            return FilePolicy::refuse();
        }

        return FilePolicy::allow(
            mimeTypes: ['video/mp4', 'application/pdf'],
            maxBytes: 512 * 1024 * 1024,
            destination: 'r2-videos',   // facultatif — voir §1.2
            fallback: 's3-archive',     // et le second choix, s'il y en a un
        );
    }
}
```

## 1.1 Pourquoi une politique plutôt qu'une configuration

On aurait pu lister les types autorisés dans `config/storage.php`, par
`owner_type`.

Mais les bornes dépendent souvent de l'objet, pas de son type : une leçon d'un
plan gratuit n'accepte pas la même taille qu'une leçon d'un plan payant, et un
brouillon accepte ce qu'un cours publié refuse. Une configuration statique
obligerait le propriétaire à revérifier après coup — c'est-à-dire une fois les
octets écrits.

## 1.2 Nommer une destination, et quand s'en abstenir

`destination` est le rang le plus fort de la résolution
([06-destinations.md](06-destinations.md) §4) : il l'emporte sur les règles de
placement de l'organisation et sur le défaut de la plateforme.

À n'utiliser que pour une raison **technique ou économique** propre au type de
fichier — « ce sont des vidéos, elles vont là où le trafic sortant est
gratuit ». Jamais pour un client particulier : cela appartient aux règles de
placement, qui se changent sans déploiement.

Un module qui nomme une destination accepte aussi son échec. Si elle est
`read_only` ou tombée, la déclaration échoue — **sauf** s'il a écrit un
`fallback`, auquel cas cette seconde destination est essayée, et elle seule.

Sans `fallback`, aucun repli. C'est délibéré : un repli deviné par la plateforme
enverrait des vidéos chez un fournisseur facturant le trafic sortant, et la
facture arriverait un mois plus tard sans que personne ait rien vu. Le seul à
pouvoir juger ce coût est celui qui a nommé la première destination.

Le repli est journalisé en `warning`, avec les deux slugs.

## 1.3 `refuse()` n'est pas une exception

Une politique de refus produit `FILE_ATTACH_FORBIDDEN` ou, en lecture,
`FILE_NOT_FOUND`. Le propriétaire n'a pas à choisir le code HTTP : il répond à
une question métier, Storage traduit.

C'est ce qui permet à la règle « ne jamais distinguer inexistant de pas-à-vous »
de tenir en un seul endroit, plutôt que dans chaque module.

---

# 2. La ligne de configuration

`config/storage.php`, racine de composition :

```php
'owners' => [
    InvoiceFiles::TYPE => InvoiceFiles::class,
    LessonFiles::TYPE => LessonFiles::class,
],
```

C'est **le seul endroit** où Storage apprend qu'une leçon existe. Aucun de ses
fichiers n'importe Learn ni Billing, et un test d'architecture le vérifie —
comme pour Payments.

---

# 3. Appeler depuis un module

Un module du monolithe n'a pas à passer par HTTP.

```php
$file = $storage->declare(
    owner: new FileRef(InvoiceFiles::TYPE, $invoice->id),
    organizationId: $invoice->organization_id,
    name: "facture-{$invoice->number}.pdf",
    contents: $pdfBytes,          // écrit directement, sans URL signée
    retainUntil: now()->addYears(10),
);
```

Quand c'est le serveur qui produit les octets, l'URL signée n'a pas d'objet : le
module écrit dans le magasin et confirme dans la foulée. Le chemin en deux
temps existe pour les octets qui viennent d'ailleurs.

---

# 4. Le cas qui a motivé le module : le PDF de facture

`GET /billing/invoices/{id}/pdf` rend `503` depuis l'origine. Storage seul ne le
résout pas — il faut aussi **produire** le PDF, ce qui appartient à Billing.

La chaîne :

1. à l'émission de la facture, Billing met en page le PDF et le confie à
   Storage, avec `retain_until` à dix ans ;
2. l'identifiant du fichier est posé sur la facture ;
3. `GET /billing/invoices/{id}/pdf` rend une redirection `302` vers une URL
   signée de courte durée.

## 4.1 Le PDF est produit une fois, jamais régénéré

C'est la décision de fond, et elle est prise dans
[ADR-0013](../../04-decisions/adr-0013-invoice-pdf-frozen.md).

Une facture émise est un document légal. Régénérée à chaque demande, elle suit
le code d'aujourd'hui : un taux de TVA modifié, une adresse d'entreprise
corrigée, un gabarit refait, et la facture de janvier téléchargée en décembre
n'est plus celle qui a été envoyée en janvier. Personne ne s'en apercevrait —
sauf un contrôleur fiscal comparant deux exemplaires du même document.

Le figer coûte quelques kilo-octets par facture. Le régénérer coûte la valeur
probante de l'ensemble des factures.

## 4.2 Pourquoi une redirection plutôt que le PDF

`302` vers une URL signée garde la règle du §3 de l'aperçu : les octets ne
traversent pas la plateforme. Le client suit la redirection sans le savoir, et
le navigateur télécharge depuis le magasin.

Un client d'API qui préfère l'URL brute a `GET /files/{id}/url`.

---

# 5. Le magasin d'objets

Storage n'écrit pas dans « un » magasin : il écrit dans une **destination**,
résolue à la déclaration puis figée sur la ligne du fichier.
[06-destinations.md](06-destinations.md) fait autorité ; deux points suffisent à
un module qui s'intègre.

**Le pilote `s3` couvre presque tout.** AWS, Cloudflare R2, Backblaze B2,
Scaleway, Wasabi, MinIO : même protocole, un point d'accès et une région de
différence. Ajouter l'un d'eux, ou un compte de plus, est une ligne en base.

**Recommandation pour ce qui est lu : Cloudflare R2.** Le trafic sortant y est
gratuit. Pour un produit qui servira des vidéos de formation depuis le Cameroun,
c'est le poste qui déciderait de la facture : chez AWS, dix mille lectures d'un
cours de 200 Mo coûtent davantage que leur stockage pendant un an. Pour ce qui
dort — PDF de facture, archives à dix ans — c'est le prix de l'octet stocké qui
compte, et S3 ou B2 reprennent l'avantage.

En développement et en test, le pilote `local` sert de destination. Les URL
signées y sont émises par Laravel lui-même, ce qui permet d'éprouver toute la
chaîne sans compte externe — déclarer, écrire, confirmer, lire.

## 5.1 Deux gardes plutôt qu'un

**L'environnement.** Une destination `test` est refusée en production, et
l'inverse. Sans échappatoire, comme `CredentialGuard` pour les identifiants
d'agrégateur. La faute qu'il empêche est irréversible : une recette pointée sur
le compartiment de production y écrirait sans une erreur, et le balayage des
orphelins y effacerait de vrais fichiers.

**L'épreuve.** Une destination n'est utilisable qu'après avoir écrit un objet
témoin, l'avoir relu et effacé. Elle est rejouée chaque jour : une clé révoquée
chez le fournisseur bascule la destination en `unverified` avant qu'un client ne
le découvre.

# 6. Le balayage

Une commande, `storage:sweep`, ordonnancée toutes les heures.

| Cible | Règle |
| --- | --- |
| Déclarations jamais confirmées | `pending` de plus de 24 h → objet effacé, ligne `deleted` |
| Fichiers supprimés | `deleted_at` de plus de 7 jours → octets effacés |
| Objets orphelins | Présents dans le magasin, absents de la base → signalés, **jamais effacés** |
| Destinations | Épreuve rejouée une fois par jour, indépendamment |

Le balayage parcourt **chaque destination séparément**. Une destination
injoignable n'interrompt pas les autres : elle est signalée, et son tour revient
au prochain passage.

Sans cette séparation, le compte d'un seul client en panne suspendrait le
nettoyage de toute la plateforme — un incident local devenu global.

La troisième ligne est la plus importante, et elle ne supprime rien
délibérément.

Un objet que la base ignore peut être un déchet — ou le signe que la base a
perdu une ligne. L'effacer automatiquement transformerait une incohérence en
perte de données définitive. La commande les compte et les journalise ; un
humain tranche, avec la même logique que le rapprochement manuel des paiements
non résolus.
