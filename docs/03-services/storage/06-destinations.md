# Sekuu Storage — Destinations et pilotes

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Une **destination** est un magasin où des octets peuvent être posés : un
compartiment S3, un compartiment R2, un dossier Google Drive, le disque local
des tests.

La décision de les tenir en base plutôt qu'en configuration est prise dans
[ADR-0014](../../04-decisions/adr-0014-storage-destinations.md).

---

# 1. Pilote, préréglage, destination

Trois niveaux, et les confondre est la première source de malentendu.

| | Ce que c'est | Où ça vit |
| --- | --- | --- |
| **Pilote** | Un protocole — savoir signer une URL, écrire, lire, effacer | Une classe PHP |
| **Préréglage** | Les particularités d'un service parlant ce protocole | `config/storage.php` |
| **Destination** | Un compte, un compartiment, des identifiants | Une ligne en base |

Un pilote sert plusieurs services ; un service sert plusieurs comptes.

```
s3 (pilote)
├── aws        (préréglage)  → 4 destinations : archives, factures, recette, client-X
├── r2         (préréglage)  → 3 destinations : vidéos-cm, vidéos-eu, client-Y
├── b2         (préréglage)  → 1 destination
└── minio      (préréglage)  → 1 destination (développement)

gdrive (pilote)
└── —                        → 1 destination par compte raccordé

local (pilote)
└── —                        → 1 destination : les tests
```

## 1.1 Les préréglages

Un préréglage n'est **que de la donnée** : point d'accès, région, style de
chemin, particularités de signature.

```php
'presets' => [
    'aws' => [
        'driver' => 's3',
        'region' => null,               // exigée à la destination
    ],
    'r2' => [
        'driver' => 's3',
        'endpoint' => 'https://{account_id}.r2.cloudflarestorage.com',
        'region' => 'auto',
        'path_style' => false,
        'requires' => ['account_id'],
    ],
    'b2' => [
        'driver' => 's3',
        'endpoint' => 'https://s3.{region}.backblazeb2.com',
        'path_style' => true,
    ],
    'scaleway' => [
        'driver' => 's3',
        'endpoint' => 'https://s3.{region}.scw.cloud',
    ],
    'minio' => [
        'driver' => 's3',
        'path_style' => true,
        'requires' => ['endpoint'],
    ],
],
```

Ajouter Wasabi, Vultr, OVH ou Cellar demande une entrée de ce tableau — et rien
d'autre. Une destination peut même s'en passer complètement, en fournissant son
point d'accès à la main : le préréglage est un confort, pas un passage obligé.

## 1.2 Ajouter une famille demande une classe

C'est la moitié qui ne peut pas être une donnée, et
[ADR-0014](../../04-decisions/adr-0014-storage-destinations.md) explique
pourquoi : un pilote doit savoir **fabriquer une autorisation d'écriture** chez
son fournisseur, ce qui est un protocole d'authentification, pas un paramètre.

Le contrat à implémenter tient en cinq méthodes.

```php
interface StorageDriver
{
    /** Ce que ce pilote sait faire — voir §2. */
    public function capabilities(): DriverCapabilities;

    /** Une autorisation d'écriture, bornée dans le temps et à un objet. */
    public function uploadTicket(Destination $to, string $path, UploadIntent $intent): UploadTicket;

    /** Ce que le magasin dit réellement de l'objet : taille, type, empreinte. */
    public function inspect(Destination $at, string $path): ?ObjectFacts;

    /** Une autorisation de lecture, bornée dans le temps. */
    public function readUrl(Destination $at, string $path, int $seconds): string;

    public function delete(Destination $at, string $path): void;
}
```

`uploadTicket()` rend la méthode HTTP, l'URL et les en-têtes exigés — c'est ce
qui permet à Google Drive, qui ouvre une session reprenable, et à S3, qui signe
un `PUT`, de tenir dans la même API sans que le client ait à les distinguer.

Une classe, une ligne dans `drivers`, et rien d'autre dans la plateforme ne
change.

---

# 2. Les pilotes ne savent pas tous la même chose

```php
final class DriverCapabilities
{
    public bool $directUpload;      // le client écrit sans passer par nous
    public bool $temporaryUrls;     // lecture par URL signée
    public bool $checksums;         // le magasin rend une empreinte fiable
    public int  $maxObjectBytes;    // borne du fournisseur
}
```

## 2.1 Quand le téléversement direct est impossible

[ADR-0012](../../04-decisions/adr-0012-direct-upload.md) pose que les octets ne
traversent pas la plateforme. C'est la règle, et un pilote incapable de la tenir
ne l'annule pas : il **restreint** ce qu'il accepte.

Une destination sans téléversement direct rend `upload_method: "proxy"` et une
taille maximale de 25 Mo, appliquée par le pilote — pas par une consigne. Une
facture ou une pièce jointe passent ; une vidéo est refusée avec
`FILE_TOO_LARGE`, et le message dit que la destination en est la cause.

L'exception est étroite, déclarée par le pilote, et bornée là où elle ne dépend
plus de la bonne volonté de l'appelant.

## 2.2 Quand le magasin ne rend pas d'empreinte fiable

L'ETag de S3 n'est pas un MD5 quand l'objet a été écrit en plusieurs parties.
D'autres fournisseurs ne rendent rien du tout.

`checksums: false` signifie que `files.checksum` reste nul, et **c'est tout** :
l'empreinte sert à vérifier une intégrité, jamais à décider d'une issue. Aucune
règle de la plateforme n'en dépend, ce qui est délibéré — voir
[02-data-model.md](02-data-model.md) §1.2.

---

# 3. La destination

| Champ | Rôle |
| --- | --- |
| `slug` | Nom court, stable, utilisé par les politiques et les règles |
| `driver` / `preset` | Le protocole, et le service |
| `config` | Point d'accès, région, compartiment, préfixe |
| `credentials` | **Chiffrées**, jamais rendues |
| `owner` | La plateforme, ou une organisation, ou un produit externe |
| `environment` | `production` ou `test` |
| `status` | `unverified`, `active`, `read_only`, `disabled` |

## 3.1 Les états, et ce qu'ils permettent

| État | Écriture | Lecture | Choisi par la résolution |
| --- | --- | --- | --- |
| `unverified` | non | non | **non** |
| `active` | oui | oui | oui |
| `read_only` | non | oui | non |
| `disabled` | non | non | non |

`read_only` est l'état qui compte. On retire une destination du service en
cessant d'y écrire, jamais en coupant la lecture : les fichiers déjà posés y
sont, et le resteront. Une destination qu'on « désactive » sans y penser rendrait
illisibles des années de documents.

`disabled` existe pour le cas où le compte n'est plus le nôtre — un client
parti, un compartiment supprimé. Les lignes de `files` demeurent, et disent
franchement pourquoi elles ne sont plus servables.

## 3.2 L'épreuve

À l'enregistrement, et sur demande : écrire un objet témoin sous
`{prefix}/.sekuu-probe`, le relire, comparer, l'effacer.

Réussite → `active`. Échec → la destination reste `unverified`, avec la raison
exacte : identifiants refusés, compartiment inexistant, droit d'écriture absent,
point d'accès injoignable.

L'épreuve est rejouée par l'ordonnanceur une fois par jour. Une clé révoquée
chez le fournisseur bascule ainsi la destination en `unverified` avant qu'un
client ne le découvre — et non l'inverse.

## 3.3 Le garde-fou d'environnement

Une destination `test` est refusée quand `APP_ENV=production`, et une
destination `production` est refusée ailleurs. Sans échappatoire, exactement
comme `CredentialGuard` pour les identifiants d'agrégateur.

La faute qu'il empêche est irréversible : un environnement de recette pointé sur
le compartiment de production y écrirait sans une erreur, et le balayage des
orphelins y effacerait de vrais fichiers.

## 3.4 Les identifiants

Chiffrés au repos par la couche de chiffrement de Laravel. **Jamais rendus par
l'API**, y compris à celui qui les a déposés : la lecture rend une empreinte —
`AKIA…7X2Q`, quatre caractères et un condensat — assez pour reconnaître une clé,
pas pour s'en servir.

Un identifiant déposé chez nous doit être **restreint au seul compartiment
concerné**. C'est exigé dans la documentation d'intégration et vérifiable dans
les faits : l'épreuve du §3.2 échouera à écrire hors du préfixe déclaré. Une clé
racine confiée à un tiers est une faute du déposant, mais nous n'avons aucune
raison de la rendre commode.

---

# 4. La résolution

Du plus précis au plus général, premier trouvé :

| Rang | Source | Cas d'usage |
| --- | --- | --- |
| 1 | `FilePolicy::allow(destination: 'r2-videos')` | Le module sait que ce sont des vidéos |
| 2 | Règle de placement `(organisation, owner_type)` | Ce client veut ses cours chez lui |
| 3 | Règle de placement `(organisation, *)` | Ce client veut tout chez lui |
| 4 | Destination par défaut de la plateforme | Le cas courant |

Une destination nommée mais `unverified`, `read_only` ou hors environnement
**échoue** — la résolution ne redescend pas d'un rang toute seule.

Un module qui exige R2 pour ses vidéos a une raison, presque toujours
économique ; un repli silencieux vers AWS produirait une facture de trafic
sortant que personne ne verrait venir, un mois plus tard.

## 4.1 Le repli est déclaré, jamais deviné

Un module qui accepte un second choix l'écrit :

```php
FilePolicy::allow(destination: 'r2-videos', fallback: 's3-archive');
```

Sans `fallback`, l'échec est dur. Avec, la seconde destination est essayée — et
elle seule : le repli n'a qu'un rang, il ne parcourt pas la liste.

C'est la règle de bascule des agrégateurs de paiement, transposée. Elle y est
délibérément étroite parce qu'un repli commode finit par produire une
conséquence que personne n'a choisie. Ici la conséquence est une facture, et le
seul à pouvoir la juger est celui qui a nommé la première destination.

Le repli est **journalisé au niveau `warning`** et porte les deux slugs. Un
repli silencieux serait un repli qu'on découvre en cherchant pourquoi les
octets ne sont pas là où on les croyait.

## 4.3 Ce qui est écrit sur le fichier

`files.destination_id`, à la déclaration, définitivement.

Un fichier vit où ses octets ont été posés. Changer une règle de placement
n'affecte que les fichiers à venir — sans quoi rebrancher une organisation
rendrait illisibles tous ses fichiers antérieurs, d'un coup, sans erreur.

---

# 5. Les règles de placement

`storage_placements` : `(organization_id, owner_type|null, destination_id)`.

```
organisation 019fd0…  ·  learn.lesson  →  r2-client-acme
organisation 019fd0…  ·  *             →  s3-client-acme
```

Elles se gèrent par l'API (§ [03-api.md](03-api.md)) ou par
`storage:placement`. Une règle vers une destination qu'on n'a pas le droit
d'utiliser est refusée à l'écriture, pas à l'usage : découvrir une erreur de
configuration au premier fichier d'un client est trop tard.

---

# 6. Choisir une destination par défaut

**Cloudflare R2** pour ce qui est lu — vidéos, images, pièces jointes
consultées. Le trafic sortant y est gratuit ; c'est le poste qui déciderait de
la facture d'un produit de formation servi depuis le Cameroun.

**S3 ou B2** pour ce qui dort — PDF de facture, archives à dix ans. Ce qui
compte alors est le prix de l'octet stocké, et l'existence de classes
d'archivage.

**Le disque local** en développement et en test. Les URL temporaires y sont
émises par Laravel : toute la chaîne — déclarer, écrire, confirmer, lire —
s'éprouve sans compte externe et sans réseau.

Ce dernier point n'est pas un détail de confort. C'est ce qui manquait au canal
SMS de Notify : intégralement écrit, jamais exécuté contre une vraie passerelle,
et faux sur trois points le jour du premier envoi.
