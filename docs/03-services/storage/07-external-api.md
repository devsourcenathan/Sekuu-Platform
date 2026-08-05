# Sekuu Storage — Stocker depuis un service externe

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Un service hors du monolithe — Sekuu Learn, un produit tiers — dépose et sert
des fichiers par cette API, **avec nos comptes de stockage ou avec les siens**.

Le mécanisme est celui de
[Payments — API externe](../payments/07-external-api.md) : clé d'API scopée,
liste blanche de types, webhook sortant signé. Ce document ne redit que ce qui
diffère.

---

# 1. La différence qu'il faut comprendre avant tout le reste

Côté Payments, l'invariant est *seul le propriétaire de l'objet nomme son prix*,
et un service externe déclare son prix parce qu'il **est** le propriétaire.

Ici l'invariant est *seul le propriétaire de l'objet dit qui peut le lire*. Un
service externe est donc, de la même façon, celui qui répond — et Storage ne
peut pas vérifier sa réponse.

## 1.1 Ce que cela oblige à admettre

Un produit externe autorise ses propres lectures. Storage ne connaît ni ses
utilisateurs, ni ses rôles, ni ce qu'est un « élève inscrit ».

Deux bornes tiennent l'ensemble, et il faut les deux :

* **la clé d'API est scopée** — elle porte la liste des `owner_type` qu'elle
  peut manipuler, et rien d'autre. Une clé de Learn ne touche pas un
  `billing.invoice` ;
* **les fichiers d'une clé sont cloisonnés** — un `file_id` d'un produit est
  invisible pour un autre, y compris en devinant l'identifiant.

Une clé mal émise ne suffit donc pas à lire les fichiers d'autrui, et une ligne
ajoutée au registre des types n'habilite personne tant qu'aucune clé ne la
porte. C'est la même double borne que côté paiement, et pour la même raison.

## 1.2 Ce qu'un service externe ne peut pas obtenir

| Refusé | Pourquoi |
| --- | --- |
| Le chemin de l'objet | Publierait la structure du compartiment |
| Les identifiants d'une destination, même la sienne | Voir [06-destinations.md](06-destinations.md) §3.4 |
| Une URL de lecture durable | Un droit d'accès qu'on ne peut plus retirer |
| Les fichiers d'un `owner_type` hors de sa clé | La première borne du §1.1 |
| Effacer un fichier sous rétention | Aucun appelant ne le peut |

---

# 2. Deux façons de stocker

C'est la décision que le produit prend en s'intégrant, et elle a des
conséquences très différentes.

| | **Nos comptes** | **Les siens** |
| --- | --- | --- |
| Où vivent les octets | Destination par défaut de la plateforme | Destination enregistrée par le produit |
| Qui paie le cloud | Sekuu | Le produit |
| Quota | Compté et opposable | **Aucun** — voir §2.2 |
| Juridiction des données | La nôtre | La sienne |
| En cas de rupture | Nous gardons les octets | Il les a déjà |
| Identifiants détenus par nous | — | **Oui**, chiffrés |

## 2.1 Enregistrer sa propre destination

```http
POST /api/v1/storage/destinations
Authorization: Bearer <clé d'API>

{
  "slug": "acme-videos",
  "preset": "r2",
  "config": {
    "account_id": "…",
    "bucket": "acme-sekuu-videos",
    "prefix": "prod/"
  },
  "credentials": {
    "key": "…",
    "secret": "…"
  },
  "environment": "production"
}
```

La réponse ne rend jamais les identifiants — une empreinte, et l'état.

L'épreuve est immédiate : écriture d'un objet témoin, relecture, effacement. Un
échec rend `STORAGE_DESTINATION_UNVERIFIED` avec la raison exacte, et la
destination n'est utilisable par personne tant qu'elle n'a pas réussi.

Découvrir des identifiants faux à l'enregistrement coûte deux minutes ; les
découvrir au premier téléversement d'un client coûte un incident.

## 2.2 Sur sa destination, aucun quota

Le produit paie sa facture cloud ; lui opposer notre quota n'aurait pas de sens.

Storage enregistre ses fichiers, sait les compter, et les rapporte —
`GET /storage/usage` répond, destination par destination. Mais il ne refuse
rien : les seules bornes sont celles de son fournisseur, et elles remontent
telles quelles en `STORAGE_DESTINATION_UNAVAILABLE`, `503`.

## 2.3 Les identifiants qu'un tiers nous confie

Ils doivent être **restreints au seul compartiment déclaré**, et au préfixe s'il
y en a un. C'est la responsabilité du déposant, mais l'épreuve du §2.1 le vérifie
en pratique : elle échoue si l'écriture est possible ailleurs qu'où elle doit
l'être.

Rotation par `PUT /storage/destinations/{id}/credentials`, qui rejoue l'épreuve
avant de remplacer. Les anciens identifiants ne sont abandonnés qu'après succès
— une rotation ratée ne doit pas couper le service.

---

# 3. Déposer un fichier

```http
POST /api/v1/files
Authorization: Bearer <clé d'API>

{
  "owner_type": "learn.lesson",
  "owner_id": "cours-42",
  "name": "seance-01.mp4",
  "mime_type": "video/mp4",
  "size": 184320000,
  "destination": "acme-videos",
  "retain_until": null
}
```

Le champ `destination` est facultatif ; absent, la résolution s'applique
([06-destinations.md](06-destinations.md) §4). Nommer une destination qui n'est
pas la sienne rend `STORAGE_DESTINATION_FORBIDDEN`, `403`.

La suite est identique au chemin interne : `PUT` vers l'URL rendue, puis
`POST /files/{id}/confirm`. La déclaration ne fait jamais foi ; Storage
interroge le magasin.

## 3.1 `retain_until` est plafonné

Un produit externe peut poser une rétention, jusqu'à une borne fixée par la clé.

Sans plafond, un produit pourrait rendre indestructible tout ce qu'il dépose sur
**nos** comptes — et nous laisser la facture d'un stockage que plus personne ne
peut effacer. La rétention est une obligation, pas un droit de tirage.

Sur sa propre destination, la borne n'existe pas : il ne s'engage que lui-même.

---

# 4. Apprendre ce qui arrive à ses fichiers

Webhook sortant signé, réessais 1 min / 5 min / 30 min / 2 h / 6 h, rotation de
secret sans coupure — le mécanisme est **exactement** celui de Payments, et il
est décrit là-bas.

Trois événements sont livrés :

| Événement | Quand |
| --- | --- |
| `storage.file.attached` | Les octets sont constatés |
| `storage.file.deleted` | Un fichier a été supprimé |
| `storage.destination.unverified` | Une destination du produit a cessé de répondre |

Le troisième n'a pas d'équivalent côté paiement, et c'est le plus utile. Une clé
révoquée chez le fournisseur ou un compartiment supprimé bascule la destination
en `unverified` à l'épreuve quotidienne. Le produit l'apprend le jour même,
plutôt qu'au prochain téléversement d'un de ses clients.

## 4.1 Le webhook n'est jamais la garantie

Même règle que Payments, et pour la même raison : un produit qui ne met en place
que lui aura tôt ou tard un fichier prêt et un objet qui l'ignore. La
confirmation est synchrone et rend déjà tout ce qu'il faut savoir ; le webhook
n'est qu'un accélérateur pour ce qui arrive **après** — une suppression, une
destination tombée.

---

# 5. Ce que le produit ne doit jamais faire

**Conserver une URL de lecture.** Elle expire, et sa durée n'est pas un contrat.
Demander une URL au moment de servir, jamais avant.

**Mettre une URL signée dans un courriel.** Elle sera morte à l'ouverture, ou
pire, vivante et transférable. Un lien vers son propre service, qui redemande
une URL au moment du clic, est la seule forme correcte.

**Déduire un droit d'accès de la possession d'un `file_id`.** L'identifiant
n'autorise rien ; c'est `mayRead` qui tranche, chez le produit.

**Déposer des identifiants cloud non restreints.** Voir §2.3.

---

# 6. Ce qui n'existe pas

**La migration d'une destination à une autre.** Un produit qui passe de nos
comptes aux siens garde ses anciens fichiers là où ils sont. Copier, vérifier,
repointer, effacer — avec reprise sur panne à chaque étape — est un projet, et
[ADR-0014](../../04-decisions/adr-0014-storage-destinations.md) l'écrit pour que
l'absence soit un choix connu.

**L'export en masse.** Un produit qui part récupère ses fichiers par l'API, un
par un. S'il est sur sa propre destination, il les a déjà : c'est l'argument le
plus fort en faveur de ce mode.

**Le partage public.** Storage ne délivre que des URL courtes. Un lien durable
est une décision de produit — qui, jusqu'à quand, avec ou sans mot de passe — et
elle se construit par-dessus, chez celui qui connaît ses utilisateurs.
