# Sekuu Storage — Modèle de données

> **Version :** 1.0
> **Statut :** Spécification de référence — fait autorité sur les tables
> **Dernière mise à jour :** Août 2026

Cinq tables. Trois pour les fichiers, deux pour les magasins où ils vivent.

Le périmètre est étroit, et le modèle doit le rester : chaque colonne ajoutée
ici est une chose que Storage prétendrait savoir d'un fichier qu'il ne comprend
pas.

---

# 1. `files`

Le fichier, de sa déclaration à sa suppression.

| Colonne | Type | Rôle |
| --- | --- | --- |
| `id` | `uuid` | UUIDv7, identifiant public |
| `organization_id` | `uuid` | Le porteur du quota et de la facture |
| `owner_type` | `varchar(64)` | `billing.invoice`, `learn.lesson` — jamais interprété |
| `owner_id` | `varchar(64)` | Identifiant chez le propriétaire |
| `destination_id` | `uuid` | Le magasin où l'objet réside — **résolu une fois, jamais recalculé** |
| `path` | `varchar(512)` | Clé de l'objet — **jamais exposée au client** |
| `name` | `varchar(255)` | Nom d'origine, tel que le client l'a donné |
| `mime_type` | `varchar(128)` | **Constaté** à la confirmation, jamais déclaré |
| `size` | `bigint` | Octets, **constatés** à la confirmation |
| `checksum` | `varchar(64)` | Empreinte rendue par le magasin (ETag ou SHA-256) |
| `status` | `varchar(16)` | `pending`, `ready`, `deleted` |
| `visibility` | `varchar(16)` | `private` — la colonne existe, une seule valeur est acceptée |
| `retain_until` | `timestamptz` nullable | Avant cette date, la suppression est refusée |
| `uploaded_by` | `uuid` nullable | L'utilisateur, quand il y en a un |
| `confirmed_at` | `timestamptz` nullable | Le moment où les octets ont été constatés |
| `deleted_at` | `timestamptz` nullable | Suppression logique — les octets partent plus tard |
| `metadata` | `jsonb` | Ce que le propriétaire veut retrouver sans nous demander |
| `created_at` / `updated_at` | `timestamptz` | |

## 1.1 Pourquoi `path` n'est jamais rendu

La clé porte l'identifiant d'organisation. La rendre, c'est publier la structure
du compartiment et inviter à deviner celle du voisin. Un client manipule un `id`
et reçoit des URL signées ; il n'a jamais besoin de savoir où l'octet est posé.

La forme de la clé :

```
{organization_id}/{yyyy}/{mm}/{uuidv7}.{extension}
```

Le préfixe d'organisation permet un audit, un export ou une purge par
organisation directement sur le magasin, sans base de données. Le mois évite un
préfixe à un million d'objets — S3 partitionne par préfixe, et un préfixe unique
finit par brider les écritures.

L'extension est **dérivée du type constaté**, pas du nom donné. Un
`facture.pdf.exe` ne devient pas un `.exe`.

## 1.2 Pourquoi pas de déduplication par empreinte

Deux clients qui téléversent les mêmes octets obtiennent deux objets.

Partager l'objet supposerait un compteur de références — donc une suppression
qui ne supprime pas, et un bogue de comptage qui efface le fichier d'autrui. Pis :
téléverser un fichier et constater qu'il existe déjà révèle qu'un autre client le
détient. Sur des documents d'identité ou des contrats, c'est une fuite.

L'empreinte est conservée pour vérifier une intégrité, jamais pour économiser un
octet.

## 1.3 `status` — trois états, et ce qu'ils signifient

| État | Signification |
| --- | --- |
| `pending` | Déclaré, URL délivrée. Les octets ne sont **pas** garantis présents. |
| `ready` | Les octets sont constatés. Seul état servable. |
| `deleted` | La ligne survit, les octets sont partis ou vont partir. |

`pending` ne signifie pas « en cours de téléversement » — Storage n'en sait
rien : le client écrit dans le magasin sans nous. Il signifie **on ne sait
pas**, exactement comme une intention de paiement expirée. C'est ce qui rend le
balayage nécessaire plutôt qu'optionnel.

## 1.4 Index

| Index | Colonnes | Motif |
| --- | --- | --- |
| Unicité | `destination_id, path` | Une clé, un fichier. Filet contre une collision d'identifiant. |
| Recherche | `owner_type, owner_id` | La question la plus posée : les fichiers de cet objet. |
| Quota | `organization_id, status` | Somme des tailles d'une organisation. |
| Destination | `destination_id, status` (partiel, `status <> 'deleted'`) | Une destination porte-t-elle encore des fichiers ? |
| Balayage | `status, created_at` (partiel, `status = 'pending'`) | Les orphelins, sans parcourir la table. |
| Purge | `deleted_at` (partiel, non nul) | Les octets à effacer réellement. |

Les deux index partiels suivent l'usage déjà fait dans Payments : un balayage
fréquent sur une petite fraction des lignes n'a pas à porter la table entière.

---

# 2. `file_downloads`

Qui a demandé à lire quoi, et quand.

| Colonne | Type | Rôle |
| --- | --- | --- |
| `id` | `uuid` | |
| `file_id` | `uuid` | |
| `actor_type` | `varchar(16)` | `user`, `api_key`, `system` |
| `actor_id` | `varchar(64)` nullable | |
| `ip` | `inet` nullable | |
| `expires_at` | `timestamptz` | Fin de validité de l'URL délivrée |
| `created_at` | `timestamptz` | |

**Append-only**, scellé au niveau du modèle comme les registres de Payments :
`updating` et `deleting` lèvent.

## 2.1 Ce que cette table peut et ne peut pas dire

Elle enregistre **la délivrance d'une URL**, pas un téléchargement. Le client
récupère les octets auprès du magasin, sans nous : nous ne voyons jamais l'accès
lui-même.

C'est écrit ici parce que la nuance décide d'un litige. « Ce document a été
consulté le 3 août » est faux ; « une autorisation de lecture a été délivrée à
cet utilisateur le 3 août, valable dix minutes » est vrai, et suffit à un audit.

Les journaux d'accès du magasin donnent l'autre moitié, si un jour on la veut.

---

# 3. `storage_usage`

Les octets consommés par organisation.

| Colonne | Type | Rôle |
| --- | --- | --- |
| `organization_id` | `uuid` | |
| `destination_id` | `uuid` | |
| `bytes_used` | `bigint` | Somme des fichiers `ready` |
| `file_count` | `integer` | |
| `updated_at` | `timestamptz` | |

Clé primaire composée : `(organization_id, destination_id)`.

Le compteur est ventilé par destination parce que **le quota ne porte pas sur
tout**. Les octets posés sur une destination appartenant au client ou à un
produit externe sont comptés — donc rapportables — mais jamais opposables : il
paie sa propre facture cloud. Seule la somme sur les destinations de la
plateforme est comparée à la limite du plan.

Un unique compteur par organisation aurait mélangé les deux, et refusé un
téléversement que rien ne nous coûte.

## 3.1 Pourquoi une table plutôt qu'un `SUM()`

Le quota est vérifié à chaque déclaration. Un `SUM(size)` sur les fichiers d'une
organisation reste rapide à dix mille lignes et cesse de l'être à dix millions —
et il le cesse d'abord pour le plus gros client, celui qui paie le plus.

Le compteur est ajusté dans la même transaction que le passage à `ready` et que
la suppression. Une commande de recalcul le rebâtit depuis `files`, qui reste la
vérité ; le compteur n'est qu'une lecture rapide, et il doit pouvoir être jeté.

---

# 4. `storage_destinations`

Un magasin où des octets peuvent être posés. Le raisonnement est dans
[ADR-0014](../../04-decisions/adr-0014-storage-destinations.md), l'usage dans
[06-destinations.md](06-destinations.md).

| Colonne | Type | Rôle |
| --- | --- | --- |
| `id` | `uuid` | |
| `slug` | `varchar(64)` | Nom stable, cité par les politiques et les règles |
| `driver` | `varchar(32)` | `s3`, `gdrive`, `local` — le protocole |
| `preset` | `varchar(32)` nullable | `aws`, `r2`, `b2`, `scaleway`, `minio` |
| `config` | `jsonb` | Point d'accès, région, compartiment, préfixe |
| `credentials` | `text` | **Chiffré**, jamais rendu |
| `owner_organization_id` | `uuid` nullable | Nul = destination de la plateforme |
| `owner_api_key_id` | `uuid` nullable | Destination d'un produit externe |
| `environment` | `varchar(16)` | `production` ou `test` |
| `status` | `varchar(16)` | `unverified`, `active`, `read_only`, `disabled` |
| `is_default` | `boolean` | Une seule à `true` par environnement |
| `verified_at` | `timestamptz` nullable | Dernière épreuve réussie |
| `verification_error` | `text` nullable | Raison exacte du dernier échec |
| `created_at` / `updated_at` | `timestamptz` | |

## 4.1 Pourquoi `credentials` est une colonne et non trois

Les identifiants ne se ressemblent pas d'un pilote à l'autre : une clé et un
secret pour S3, un jeton de rafraîchissement OAuth pour Google Drive, rien du
tout pour le disque local.

Un unique blob chiffré évite d'ajouter une colonne à chaque famille — et
surtout, il évite qu'une colonne pensée pour un pilote se retrouve à porter
autre chose chez un autre. Le pilote sait lire ce qu'il a écrit ; personne
d'autre n'a à le comprendre.

## 4.2 Deux colonnes de propriété plutôt qu'une

`owner_organization_id` et `owner_api_key_id` répondent à deux questions
distinctes : *à quel client appartient ce compte* et *quel produit externe l'a
enregistré*. Les deux nulles désignent la plateforme.

Les fondre dans un couple polymorphe aurait économisé une colonne et perdu les
clés étrangères — donc la garantie qu'une destination ne survit pas à
l'organisation qui la possède.

## 4.3 Index

| Index | Colonnes | Motif |
| --- | --- | --- |
| Unicité | `slug` | Le nom est cité en clair par les politiques. |
| Unicité partielle | `environment` où `is_default` | Une seule destination par défaut, garantie par la base. |
| Résolution | `status, environment` | Les candidates éligibles. |

L'unicité partielle est la plus utile des trois : deux destinations par défaut
donneraient un choix dépendant de l'ordre de lecture, et donc des fichiers
répartis au hasard entre deux comptes. Le genre de défaut qui ne se voit qu'en
cherchant un fichier là où il n'est pas.

---

# 5. `storage_placements`

Où poser les octets d'une organisation.

| Colonne | Type | Rôle |
| --- | --- | --- |
| `id` | `uuid` | |
| `organization_id` | `uuid` | |
| `owner_type` | `varchar(64)` nullable | Nul = tous les types |
| `destination_id` | `uuid` | |
| `created_at` / `updated_at` | `timestamptz` | |

Unicité sur `(organization_id, owner_type)`, `owner_type` nul compris — un index
partiel s'en charge, PostgreSQL ne considérant pas deux `NULL` comme égaux.

Sans cette précaution, une organisation pourrait porter deux règles « tous
types » contradictoires, et la résolution dépendrait de l'ordre de lecture.

## 5.1 Ce que ces règles ne font pas

Elles ne déplacent rien. Une règle ajoutée ou modifiée ne vaut que pour les
fichiers **à venir** : ceux qui existent portent déjà leur destination, écrite
sur leur ligne.

C'est écrit ici parce que l'intuition dit le contraire. « Je change la
destination de ce client » ressemble à un déménagement ; ce n'en est pas un, et
croire l'inverse ferait chercher des fichiers là où ils ne sont pas.

---

# 6. Ce que le modèle ne porte pas

**Aucune table de partage.** Un lien de partage public est une décision de
produit — qui, jusqu'à quand, avec ou sans mot de passe — et elle appartient au
module propriétaire. Storage délivre des URL courtes ; un partage durable se
construit par-dessus, avec ses propres règles.

**Aucune table de version.** Une nouvelle version est un nouveau fichier
rattaché au même objet. Le propriétaire décide lequel est courant ; lui seul
sait si « la pièce jointe précédente » a un sens dans son domaine.

**Aucune table de dossier.** L'arborescence est celle du propriétaire, pas la
nôtre. Storage ne connaît qu'un rattachement.

**Aucune table de migration entre destinations.** Copier, vérifier, repointer,
effacer, avec reprise sur panne à chaque étape : c'est un projet, pas une
colonne. L'absence est un choix, tranché dans
[ADR-0014](../../04-decisions/adr-0014-storage-destinations.md).
