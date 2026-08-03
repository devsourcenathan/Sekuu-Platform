# Sekuu Platform API Guidelines

> **Version :** 1.1
> **Statut :** Standard applicable
> **Dernière mise à jour :** Août 2026

Documents liés : [Sécurité & Tokens](security.md) · [Catalogue des codes d'erreur](error-codes.md) · [ADR-0002 — Versionnement](../04-decisions/adr-0002-api-versioning.md)

---

# 1. Introduction

## 1.1 Objectif

Ce document définit les standards de conception des API de **Sekuu Platform**.

Il s'applique à tous les services de la plateforme :

* Identity
* Verify
* Notify
* Billing
* Storage
* AI
* Search
* Analytics

Ainsi qu'à tous les futurs services.

Aucune API ne peut être développée sans respecter ces conventions.

---

# 2. Philosophie

Les API Sekuu doivent être :

* Simples
* Prévisibles
* Cohérentes
* Versionnées
* Sécurisées
* Documentées
* Internationalisées
* Faciles à maintenir

Le développeur ne doit jamais avoir à deviner le fonctionnement d'une API.

---

# 3. Principes

Toutes les API suivent les principes REST.

Les ressources représentent des objets métier.

Exemple :

```text
/users
/organizations
/workspaces
/verifications
/notifications
```

Les verbes HTTP décrivent l'action.

---

# 4. Versionnement

Toutes les routes publiques sont obligatoirement versionnées.

Format :

```text
/api/v1
```

Exemple :

```http
GET /api/v1/users

POST /api/v1/auth/login

GET /api/v1/organizations

POST /api/v1/verifications
```

Une API ne doit jamais être exposée sans version.

---

# 5. Structure des URLs

Les URLs utilisent exclusivement des noms.

Jamais de verbes.

✔ Correct

```text
/users

/organizations

/products
```

❌ Incorrect

```text
/getUsers

/createOrganization

/deleteProduct
```

Les verbes HTTP suffisent.

---

# 6. Sous-ressources

Les relations utilisent des sous-ressources.

Exemple :

```http
GET /organizations/{organization_id}/members

GET /organizations/{organization_id}/workspaces

GET /organizations/{organization_id}/subscriptions
```

---

# 7. Identifiants

Tous les identifiants sont des UUID.

Exemple :

```text
550e8400-e29b-41d4-a716-446655440000
```

Les identifiants auto-incrémentés ne doivent jamais être exposés.

---

# 8. Format des données

Les clés JSON utilisent le format **snake_case**, sans exception.

Cette règle s'applique également aux colonnes de base de données : `first_name`, jamais `firstname`.

Exemple :

```json
{
  "first_name": "Nathan",
  "last_name": "Tchinda",
  "email_verified_at": null
}
```

---

# 9. Dates

Toutes les dates sont exprimées :

* en UTC ;
* au format ISO8601.

Exemple :

```json
{
  "created_at": "2026-08-03T13:42:51Z"
}
```

Le frontend est responsable de l'affichage dans le fuseau horaire de l'utilisateur.

---

# 10. Langue

Toutes les API supportent `Accept-Language`.

```http
Accept-Language: fr
Accept-Language: fr-CA
Accept-Language: de;q=1.0, fr;q=0.8
```

## 10.1 Langues supportées

`en` (défaut) et `fr`. La liste fait foi dans `config/sekuu.php`.

Une langue non supportée n'est jamais servie partiellement : la réponse repart dans la langue par défaut. Exposer une clé de traduction brute serait pire que répondre en anglais.

La région est ignorée : `fr-CA` est traité comme `fr`. Les qualités (`q=`) sont respectées, par ordre décroissant.

## 10.2 Ordre de résolution

| Priorité | Source |
| --- | --- |
| 1 | La **préférence enregistrée** de l'utilisateur (`users.language`, porté par le claim `lang`) |
| 2 | L'en-tête `Accept-Language` |
| 3 | La langue par défaut de la plateforme |

Le profil prime sur l'en-tête, et c'est délibéré : un navigateur envoie sa propre langue sans que l'utilisateur l'ait demandé, alors que le choix enregistré dans le profil est explicite. Un utilisateur ayant choisi le français ne doit pas recevoir de l'anglais parce qu'il consulte depuis un poste configuré autrement.

Sur les routes non authentifiées — connexion, mot de passe oublié — seul l'en-tête s'applique, faute de profil connu.

## 10.3 Réponse

Toute réponse déclare la langue utilisée :

```http
Content-Language: fr
Vary: Accept-Language
```

`Vary` est indispensable : sans lui, un cache intermédiaire servirait la version française à un client anglophone.

## 10.4 Ce qui n'est jamais traduit

Les codes d'erreur, les slugs, les clés de template et les valeurs d'énumération sont des **références stables**. Ils ne changent ni avec la langue, ni avec la région.

C'est la contrepartie de la traduction des messages : le client s'appuie sur `error.code`, jamais sur `error.message`.

## 10.5 Implémentation

Les messages sont des **clés**, jamais des phrases : `__('identity::messages.credentials_invalid')`, et non `__('The credentials are incorrect.')`. Passer la phrase anglaise comme clé fonctionne par accident tant que rien ne la traduit, puis échoue silencieusement le jour où l'on ajoute une langue.

| Portée | Emplacement | Préfixe |
| --- | --- | --- |
| Plateforme | `lang/{locale}/platform.php` | `platform.` |
| Module | `Modules/{Module}/Resources/lang/{locale}/messages.php` | `{module}::messages.` |

Trois tests verrouillent l'ensemble : le code d'erreur ne varie pas avec la langue, toute clé absente d'une langue fait échouer la suite, et un `__()` recevant une phrase est signalé.

---

# 11. Content-Type

Toutes les API utilisent :

```http
Content-Type: application/json
```

Les uploads utilisent :

```http
multipart/form-data
```

---

# 12. Encodage

Toutes les API utilisent :

```text
UTF-8
```

---

# 13. HTTPS

Toutes les communications utilisent HTTPS.

HTTP est interdit.

---

# 14. Authentification

Les API utilisent des Bearer Tokens.

Exemple :

```http
Authorization: Bearer eyJhbGciOi...
```

Le format des tokens, leurs claims, leur durée de vie et leur révocation sont définis dans [security.md](security.md).

---

# 15. API Version

Le numéro de version ne doit jamais être envoyé dans les headers.

Il fait partie de l'URL.

Exemple :

```text
/api/v1/users
```

---

# 16. Réponses

Toutes les réponses suivent le même format.

Succès :

```json
{
  "success": true,
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000"
  },
  "meta": {
    "request_id": "req_8b94d7d0"
  }
}
```

---

Erreur :

```json
{
  "success": false,
  "error": {
    "code": "USER_NOT_FOUND",
    "message": "The requested user does not exist."
  },
  "meta": {
    "request_id": "req_8b94d7d0"
  }
}
```

---

# 17. request_id

Chaque requête possède un identifiant unique.

Il est présent :

* dans les logs ;
* dans les réponses ;
* dans les traces distribuées.

Cela facilite le support et le débogage.

---

# 18. Codes HTTP

Les API utilisent uniquement les codes standards.

Succès :

| Code | Utilisation                     |
| ---- | ------------------------------- |
| 200  | Lecture ou mise à jour réussie  |
| 201  | Ressource créée                 |
| 202  | Traitement accepté (asynchrone) |
| 204  | Suppression sans contenu        |

Client :

| Code | Utilisation           |
| ---- | --------------------- |
| 400  | Requête invalide      |
| 401  | Non authentifié       |
| 403  | Accès refusé          |
| 404  | Ressource introuvable |
| 409  | Conflit               |
| 422  | Erreur de validation  |
| 429  | Limite dépassée       |

Serveur :

| Code | Utilisation          |
| ---- | -------------------- |
| 500  | Erreur interne       |
| 503  | Service indisponible |

---

# 19. Pagination

Toutes les listes sont paginées. Aucune API ne renvoie une collection complète.

## 19.1 Pagination par page (défaut)

```http
?page=1

&per_page=20
```

`per_page` vaut 20 par défaut et ne peut pas dépasser **100**.

Réponse :

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 250,
    "last_page": 13,
    "request_id": "req_8b94d7d0"
  }
}
```

## 19.2 Pagination par curseur

Les collections volumineuses ou fortement écrites (`audit_logs`, `notification_logs`, événements, exports) utilisent un curseur.

La pagination par offset y devient coûteuse et produit des doublons ou des oublis lorsque des lignes sont insérées pendant le parcours.

```http
?cursor=eyJpZCI6IjU1MGU4...&per_page=50
```

Réponse :

```json
{
  "success": true,
  "data": [],
  "meta": {
    "per_page": 50,
    "next_cursor": "eyJpZCI6IjU1MGU4...",
    "has_more": true,
    "request_id": "req_8b94d7d0"
  }
}
```

Le curseur est opaque. Les consommateurs ne doivent jamais le décoder ni le construire.

---

# 20. Tri

Toutes les ressources supportent :

```http
?sort=name

?sort=-created_at
```

Le préfixe "-" indique un ordre décroissant.

---

# 21. Recherche

Les ressources pour lesquelles une recherche textuelle a du sens exposent :

```http
?search=nathan
```

Ce paramètre n'est pas obligatoire sur toutes les ressources. Lorsqu'il existe, la documentation OpenAPI précise les champs couverts.

Une ressource qui ne le supporte pas doit répondre `400` avec le code `UNSUPPORTED_PARAMETER` plutôt que d'ignorer silencieusement le paramètre.

---

# 22. Filtres

Les filtres utilisent une syntaxe uniforme.

```http
?filter[status]=active

?filter[country]=CM

?filter[type]=teacher
```

---

# 23. Sélection de champs

Pour réduire la taille des réponses :

```http
?fields=id,name,email
```

---

# 24. Inclusion des relations

Les relations peuvent être chargées explicitement.

```http
?include=organization

?include=members

?include=subscription
```

Contraintes obligatoires :

* chaque endpoint déclare une **liste blanche** des relations incluables ; une relation inconnue renvoie `400` / `UNSUPPORTED_PARAMETER` ;
* profondeur maximale : **2 niveaux** (`organization.owner` est accepté, `organization.owner.memberships` ne l'est pas) ;
* maximum **3** relations par requête ;
* le chargement doit être *eager* — aucune relation incluse ne doit produire de requête par ligne (N+1).

---

# 25. Format des erreurs

Toutes les erreurs suivent la même structure.

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed.",
    "details": {
      "email": [
        "The email field is required."
      ]
    }
  },
  "meta": {
    "request_id": "req_8b94d7d0"
  }
}
```

Les consommateurs de l'API doivent utiliser le champ `code` comme référence stable et ne jamais baser leur logique sur le texte du message, qui peut être traduit selon la langue demandée.

La liste des codes autorisés est centralisée dans [error-codes.md](error-codes.md). Aucun module ne peut inventer un code hors de ce catalogue.

---

# 26. Limitation de débit (rate limiting)

Toutes les API publiques sont limitées en débit.

## 26.1 Portée

La limite s'applique par **organisation** pour les tokens utilisateur, et par **clé d'API** pour les intégrations serveur. Elle n'est jamais appliquée par adresse IP seule, sauf sur les routes non authentifiées.

## 26.2 Quotas par défaut

| Catégorie de route | Limite |
| --- | --- |
| Lecture (`GET`) | 1 000 req / min |
| Écriture (`POST`, `PATCH`, `DELETE`) | 300 req / min |
| Authentification (`/auth/login`, `/auth/forgot-password`) | 10 req / min et par adresse IP |
| Endpoints coûteux (AI, OCR, exports) | 60 req / min |

Un plan Billing peut relever ces quotas. Les valeurs effectives sont exposées dans les headers.

## 26.3 Headers

Toute réponse inclut :

```http
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 987
X-RateLimit-Reset: 1754225400
```

Au dépassement, l'API répond `429` avec le code `RATE_LIMIT_EXCEEDED` et le header :

```http
Retry-After: 42
```

---

# 27. Idempotence

Toute requête `POST` qui crée une ressource ou déclenche un effet de bord non réversible (paiement, envoi de message, activation de produit) doit accepter :

```http
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

Règles :

* la clé est générée par le client, unique par opération métier ;
* elle est conservée **24 heures** ;
* une seconde requête avec la même clé et le même corps renvoie la **réponse d'origine**, sans réexécuter l'opération ;
* une même clé réutilisée avec un corps différent renvoie `409` / `IDEMPOTENCY_KEY_REUSED`.

Les consommateurs d'événements internes doivent appliquer la même garantie : un événement peut être livré plusieurs fois.

---

# 28. Webhooks

Les services qui notifient l'extérieur (Verify, Billing, Notify) émettent des webhooks.

## 28.1 Format

```json
{
  "id": "evt_9f1c2b7a",
  "type": "verification.completed",
  "created_at": "2026-08-03T13:42:51Z",
  "data": {}
}
```

Le champ `type` suit la convention `ressource.action`.

## 28.2 Signature

Chaque livraison est signée avec le secret du endpoint :

```http
Sekuu-Signature: t=1754225400,v1=5257a869e7ecebeda32affa62cdca3fa...
```

La signature est un HMAC-SHA256 de `{timestamp}.{corps brut}`.

Le consommateur doit :

* recalculer la signature sur le corps **brut**, avant tout parsing JSON ;
* comparer en temps constant ;
* rejeter tout événement dont l'horodatage dépasse **5 minutes** (protection contre le rejeu).

## 28.3 Livraison

* Une réponse `2xx` vaut acquittement. Tout autre code déclenche un réessai.
* Réessais avec backoff exponentiel : 1 min, 5 min, 30 min, 2 h, 6 h, 24 h.
* Après 6 échecs, le endpoint est désactivé et une notification est envoyée à l'organisation.
* La livraison est **au moins une fois** : le consommateur doit dédupliquer sur `id`.

---

# 29. Dépréciation

Lorsqu'une version devient obsolète :

* elle reste disponible pendant une période définie ;
* elle est documentée comme dépréciée ;
* une date de fin de support est communiquée.

Aucune rupture de compatibilité ne doit être introduite dans une version majeure existante.

---

# 30. Documentation

Chaque API doit disposer d'un contrat OpenAPI (`openapi.yaml`) versionné avec le code.

Ce contrat est la source de vérité.

La documentation interactive (Swagger ou Redoc) est générée automatiquement à partir de ce contrat.

---

# 31. Évolution

Les API doivent être conçues pour évoluer.

Les ajouts de nouveaux champs sont autorisés tant qu'ils ne cassent pas les intégrations existantes.

Les suppressions ou modifications incompatibles nécessitent une nouvelle version majeure.

---

# 32. Résumé

Toutes les API Sekuu respectent les règles suivantes :

* Versionnées (`/api/v1`)
* REST
* HTTPS uniquement
* JSON UTF-8
* UUID comme identifiants
* Dates ISO8601 en UTC
* `snake_case` pour les propriétés JSON
* Format de réponse uniforme
* Pagination (page ou curseur), tri et filtres standardisés
* OpenAPI comme contrat officiel
* Codes d'erreur issus du catalogue commun
* Messages internationalisables
* `request_id` présent dans chaque réponse
* Rate limiting exposé dans les headers
* `Idempotency-Key` sur les écritures non réversibles
* Webhooks signés et rejouables
* Compatibilité ascendante garantie au sein d'une même version majeure
