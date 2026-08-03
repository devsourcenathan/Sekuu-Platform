# Sekuu Platform API Guidelines

> **Version :** 1.0
> **Statut :** Draft
> **Dernière mise à jour :** Août 2026

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

Les clés JSON utilisent le format **snake_case**.

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

Toutes les API doivent supporter le header :

```http
Accept-Language
```

Exemple :

```http
Accept-Language: fr

Accept-Language: en

Accept-Language: es
```

Les messages peuvent être traduits.

Les codes d'erreur restent identiques.

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

Toutes les listes sont paginées.

Paramètres :

```http
?page=1

&per_page=20
```

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

Toutes les ressources doivent supporter :

```http
?search=nathan
```

Le comportement exact dépend du domaine métier.

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

---

# 26. Dépréciation

Lorsqu'une version devient obsolète :

* elle reste disponible pendant une période définie ;
* elle est documentée comme dépréciée ;
* une date de fin de support est communiquée.

Aucune rupture de compatibilité ne doit être introduite dans une version majeure existante.

---

# 27. Documentation

Chaque API doit disposer d'un contrat OpenAPI (`openapi.yaml`) versionné avec le code.

Ce contrat est la source de vérité.

La documentation interactive (Swagger ou Redoc) est générée automatiquement à partir de ce contrat.

---

# 28. Évolution

Les API doivent être conçues pour évoluer.

Les ajouts de nouveaux champs sont autorisés tant qu'ils ne cassent pas les intégrations existantes.

Les suppressions ou modifications incompatibles nécessitent une nouvelle version majeure.

---

# 29. Résumé

Toutes les API Sekuu respectent les règles suivantes :

* Versionnées (`/api/v1`)
* REST
* HTTPS uniquement
* JSON UTF-8
* UUID comme identifiants
* Dates ISO8601 en UTC
* `snake_case` pour les propriétés JSON
* Format de réponse uniforme
* Pagination, tri et filtres standardisés
* OpenAPI comme contrat officiel
* Messages internationalisables
* `request_id` présent dans chaque réponse
* Compatibilité ascendante garantie au sein d'une même version majeure
