# Sekuu Storage — API

> **Version :** 1.0
> **Statut :** Spécification de référence — fait autorité sur les routes
> **Dernière mise à jour :** Août 2026

Préfixe `/api/v1`. Enveloppe, pagination et erreurs suivent
[api-guidelines.md](../../02-standards/api-guidelines.md). Le contrat OpenAPI
(`Modules/Storage/openapi.yaml`) fait foi sur les schémas, et un test vérifie
que routes et contrat ne divergent pas.

---

# 1. Les routes

| Méthode | Route | Rôle |
| --- | --- | --- |
| `POST` | `/files` | Déclarer un fichier, obtenir une URL de téléversement |
| `POST` | `/files/{id}/confirm` | Constater les octets, rendre le fichier servable |
| `GET` | `/files/{id}` | Les métadonnées d'un fichier |
| `GET` | `/files/{id}/url` | Une URL de lecture signée, de courte durée |
| `GET` | `/files` | Les fichiers d'un objet — `owner_type` et `owner_id` **requis** |
| `DELETE` | `/files/{id}` | Supprimer, si la rétention le permet |

Aucune ne rend d'octet : la plateforme ne sert jamais un fichier elle-même.

Six autres administrent les magasins — leur raison d'être est dans
[06-destinations.md](06-destinations.md).

| Méthode | Route | Rôle |
| --- | --- | --- |
| `GET` | `/storage/destinations` | Les destinations utilisables par l'appelant |
| `POST` | `/storage/destinations` | Enregistrer un magasin, et l'éprouver |
| `POST` | `/storage/destinations/{id}/verify` | Rejouer l'épreuve |
| `PUT` | `/storage/destinations/{id}/credentials` | Rotation des identifiants |
| `PATCH` | `/storage/destinations/{id}` | Changer l'état — `active`, `read_only`, `disabled` |
| `PUT` | `/storage/placements` | Où poser les fichiers d'une organisation |

Il n'y a **pas** de `DELETE /storage/destinations/{id}` tant qu'elle porte des
fichiers : `STORAGE_DESTINATION_IN_USE`, `409`. Même logique que la rétention —
supprimer la ligne rendrait illisibles des octets bien présents, et la base
serait la seule à savoir où ils étaient.

Une destination qu'on veut retirer du service passe `read_only` : on cesse d'y
écrire, on continue d'y lire.

## 1.1 Pourquoi le listing exige un propriétaire

`GET /files` sans filtre rendrait « tous les fichiers de mon organisation » — et
il faudrait alors définir ce que voit un membre simple d'une organisation qui en
compte trois cents. Storage ne peut pas répondre : il ignore les rôles.

Avec `owner_type` et `owner_id` obligatoires, la question redevient « les
fichiers de cette facture », et le propriétaire sait y répondre.

---

# 2. Déclarer

```http
POST /api/v1/files
Authorization: Bearer <token>

{
  "owner_type": "learn.lesson",
  "owner_id": "019fd1...",
  "name": "cours-01.mp4",
  "mime_type": "video/mp4",
  "size": 184320000,
  "destination": "r2-videos"
}
```

`destination` est facultatif, et rarement utile : la résolution
([06-destinations.md](06-destinations.md) §4) répond dans la quasi-totalité des
cas. Nommer une destination qu'on n'a pas le droit d'utiliser rend
`STORAGE_DESTINATION_FORBIDDEN`.

```json
{
  "success": true,
  "data": {
    "id": "019fd2a1-...",
    "status": "pending",
    "upload_url": "https://<magasin>/...&X-Amz-Expires=900&X-Amz-Signature=...",
    "upload_method": "PUT",
    "upload_headers": { "Content-Type": "video/mp4" },
    "expires_at": "2026-08-05T10:15:00Z"
  }
}
```

Avant de rendre cette réponse, Storage a posé quatre questions :

1. **au propriétaire** — cet acteur peut-il attacher un fichier à cet objet, et
   quelles bornes s'appliquent (types, taille maximale, rétention) ;
2. **à la résolution** — dans quelle destination ces octets vont-ils ;
3. **à Billing**, si la destination est la nôtre — reste-t-il du quota ;
4. **à lui-même** — le type et la taille annoncés tiennent-ils dans ces bornes,
   et dans celles du pilote.

## 2.0 `upload_method` n'est pas toujours `PUT`

Il vaut ce que le pilote de la destination répond. S3 signe un `PUT` ; Google
Drive ouvre une session reprenable ; une destination sans téléversement direct
rend `proxy`, et alors les octets transitent — bornés à 25 Mo par le pilote
lui-même.

Un client qui suit `upload_method`, `upload_url` et `upload_headers` sans les
interpréter fonctionne partout. Un client qui suppose `PUT` casse le jour où le
magasin change, sans que rien ne l'ait prévenu.

Un `owner_type` absent du registre échoue durement, `FILE_OWNER_TYPE_UNKNOWN`.
Le repli silencieux est la faute que Payments a déjà refusée : il ferait aboutir
un téléversement que personne ne saurait rattacher.

## 2.1 Les en-têtes rendus sont contraignants

`upload_headers` n'est pas une suggestion. La signature couvre le
`Content-Type` : un client qui écrit d'autres octets sous un autre type verra
son `PUT` refusé par le magasin, avant même la confirmation.

C'est une borne de plus, posée là où elle ne dépend plus de notre code.

## 2.2 Erreurs

| Code | HTTP | Quand |
| --- | --- | --- |
| `FILE_TOO_LARGE` | 422 | Taille annoncée au-delà de la borne du propriétaire |
| `MIME_TYPE_NOT_ALLOWED` | 422 | Type hors de la liste du propriétaire |
| `STORAGE_QUOTA_EXCEEDED` | 429 | Plus de quota pour l'organisation |
| `FILE_OWNER_TYPE_UNKNOWN` | 422 | `owner_type` absent du registre |
| `FILE_ATTACH_FORBIDDEN` | 403 | Le propriétaire refuse le rattachement |
| `STORAGE_DESTINATION_FORBIDDEN` | 403 | Destination nommée, mais pas à cet appelant |
| `STORAGE_DESTINATION_UNVERIFIED` | 409 | Destination jamais éprouvée, ou retombée en échec |
| `STORAGE_DESTINATION_UNAVAILABLE` | 503 | Le magasin refuse ou ne répond pas |

Une destination nommée mais inéligible **échoue** ; la résolution ne redescend
pas d'un rang. Un module qui exige R2 pour ses vidéos a une raison presque
toujours économique, et un repli silencieux vers AWS produirait une facture de
trafic sortant que personne ne verrait venir.

---

# 3. Confirmer

```http
POST /api/v1/files/019fd2a1-.../confirm
```

Storage interroge le magasin. Trois issues :

**L'objet est là, conforme.** Le fichier passe `ready`, le compteur d'usage est
ajusté, le propriétaire est prévenu par `attached()` **dans la transaction**, et
`storage.file.attached` est publié.

**L'objet est absent.** `UPLOAD_INCOMPLETE`, 422. Le fichier reste `pending` :
le client peut réessayer avec la même URL tant qu'elle vit.

**L'objet est là, non conforme.** Type ou taille hors des bornes du
propriétaire : l'objet est effacé, le fichier passe `deleted`, et la réponse
porte la vraie raison — `MIME_TYPE_NOT_ALLOWED` ou `FILE_TOO_LARGE`.

Le fichier n'est pas laissé `pending` dans ce dernier cas, et c'est délibéré :
il ne s'agit pas d'une incertitude mais d'un refus constaté. Le garder en
attente inviterait à réessayer une opération qui ne peut pas aboutir.

## 3.1 Idempotence

Confirmer un fichier déjà `ready` rend `200` et le même corps. Le compteur
d'usage n'est pas ajusté deux fois — c'est le passage d'état qui le déclenche,
pas l'appel.

Un client qui perd la réponse réessaie ; la règle est celle du reste de la
plateforme.

## 3.2 Pourquoi une confirmation explicite

On pourrait s'en passer : le magasin sait notifier une écriture.

Mais cette notification arrive par un canal que nous n'avons pas éprouvé,
qu'aucune infrastructure gratuite ne garantit, et dont Payments a appris ce
qu'il en coûte de dépendre — trois livraisons dans un ordre variable, un corps
qui ment sur le statut. Une confirmation demandée par le client est synchrone,
attribuable, et testable sans agrégateur.

Le balayage rattrape les clients qui ne confirment pas ; il ne rattraperait pas
une plateforme qui n'a pas de moment défini pour constater.

---

# 4. Lire

```http
GET /api/v1/files/019fd2a1-.../url
```

```json
{
  "success": true,
  "data": {
    "url": "https://<magasin>/...&X-Amz-Expires=600&X-Amz-Signature=...",
    "expires_at": "2026-08-05T10:10:00Z",
    "name": "cours-01.mp4",
    "mime_type": "video/mp4",
    "size": 184320000
  }
}
```

Storage demande d'abord au propriétaire si cet acteur peut lire. Un refus, comme
un fichier inexistant, rend `FILE_NOT_FOUND` — jamais `403`.

C'est la règle déjà posée pour les factures et les charges : distinguer
« inexistant » de « pas à vous » transformerait l'endpoint en oracle. Un client
pourrait énumérer les identifiants et apprendre ce qui existe chez les autres.

Chaque délivrance écrit une ligne dans `file_downloads`.

Un fichier `pending` n'a pas d'URL de lecture : `FILE_NOT_READY`, 409. Les
octets ne sont pas garantis présents, et une URL signée vers un objet absent
rendrait une erreur du magasin, illisible et hors de notre catalogue.

---

# 5. Supprimer

```http
DELETE /api/v1/files/019fd2a1-...
```

Le propriétaire est consulté. Puis la rétention :

```json
{
  "success": false,
  "error": {
    "code": "FILE_RETAINED",
    "message": "Ce fichier doit être conservé jusqu'au 2036-08-05.",
    "details": { "retain_until": "2036-08-05T00:00:00Z" }
  }
}
```

`409`. Aucun paramètre ne passe outre, aucune permission ne l'emporte. Une
obligation légale qu'un rôle suffit à contourner n'est pas une obligation.

La suppression est **logique** : `deleted_at` est posé, le quota est rendu, la
ligne demeure. Les octets partent au balayage suivant.

Deux raisons de ne pas effacer tout de suite. Un `DELETE` accidentel reste
réparable pendant quelques jours. Et un effacement dans le magasin qui échoue
au milieu d'une transaction de base de données laisserait les deux en
désaccord — la base dit présent, le magasin dit absent, et rien ne le
signalerait.

---

# 6. Les routes qui n'existent pas

**`GET /files/{id}/download`.** Servir les octets rendrait la plateforme proxy
de son propre magasin : mémoire, temps de requête, et un fichier client servi
depuis notre domaine. Tout le §4 de l'aperçu tomberait.

**`PUT /files/{id}`.** Remplacer les octets d'un fichier sous le même
identifiant rendrait fausse toute référence déjà conservée ailleurs — une
facture téléchargée hier ne serait plus celle d'aujourd'hui. Une nouvelle
version est un nouveau fichier.

**`POST /files/{id}/share`.** Voir [02-data-model.md](02-data-model.md) §4.
