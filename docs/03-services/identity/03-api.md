# Sekuu Identity — API

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Base URL :** `https://identity.sekuu.com/api/v1`
> **Dernière mise à jour :** Août 2026

Cette API respecte intégralement les [API Guidelines](../../02-standards/api-guidelines.md) : réponses uniformes, `snake_case`, UUID, dates ISO8601 UTC, pagination, `request_id`, codes d'erreur issus du [catalogue commun](../../02-standards/error-codes.md).

Le contrat faisant foi est `openapi.yaml`, versionné avec le code. Ce document en est la vue lisible.

---

# 1. Conventions

* Toutes les routes sont préfixées par `/api/v1`. Aucune route n'est exposée sans version.
* Sauf mention `public`, toutes les routes exigent `Authorization: Bearer <access_token>`.
* Les routes marquées `org` exigent un token portant un claim `org` (organisation active).
* Les écritures non réversibles acceptent `Idempotency-Key`.

---

# 2. Authentification

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `POST` | `/auth/register` | public | Création d'un compte |
| `POST` | `/auth/login` | public | Connexion — renvoie un access token et pose le refresh token |
| `POST` | `/auth/refresh` | public | Rotation du refresh token, nouvel access token |
| `POST` | `/auth/logout` | auth | Révoque la session courante |
| `POST` | `/auth/logout-all` | auth | Révoque toutes les sessions de l'utilisateur |
| `GET` | `/auth/me` | auth | Profil, organisations, rôles et produits accessibles |
| `POST` | `/auth/switch-organization` | auth | Change l'organisation active, renvoie un nouveau token |
| `POST` | `/auth/forgot-password` | public | Déclenche l'envoi d'un lien de réinitialisation |
| `POST` | `/auth/reset-password` | public | Applique le nouveau mot de passe |
| `POST` | `/auth/verify-email` | public | Valide une adresse email |
| `POST` | `/auth/resend-verification` | auth | Renvoie le lien de vérification |

L'ancienne route `/identity/me` n'existe pas. La route correcte est `GET /api/v1/auth/me`.

`/auth/forgot-password` renvoie toujours `202`, que l'adresse existe ou non.

## 2.1 Réponse de connexion

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOi...",
    "token_type": "Bearer",
    "expires_in": 900,
    "user": {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "first_name": "Nathan",
      "last_name": "Tchinda",
      "email": "nathan@sekuu.com",
      "language": "fr"
    },
    "organizations": [
      {
        "id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
        "name": "SOS Clinique",
        "slug": "sos-clinique",
        "roles": ["owner"]
      }
    ]
  },
  "meta": {
    "request_id": "req_8b94d7d0"
  }
}
```

Le refresh token n'apparaît pas dans le corps sur les clients web : il est posé en cookie `HttpOnly`.

---

# 3. Utilisateurs

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/users` | org | Membres de l'organisation active |
| `GET` | `/users/{id}` | org | Détail d'un membre |
| `PATCH` | `/users/{id}` | auth | Mise à jour du profil (soi-même, ou `admin`) |
| `DELETE` | `/users/{id}` | auth | Suppression de compte (différée de 30 jours) |
| `POST` | `/users/{id}/change-password` | auth | Changement de mot de passe |

`GET /users` est **toujours** filtré sur l'organisation du token. Il n'existe aucune route listant tous les utilisateurs de la plateforme.

---

# 4. Organisations

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/organizations` | auth | Organisations de l'utilisateur connecté |
| `POST` | `/organizations` | auth | Création — le créateur devient `owner` |
| `GET` | `/organizations/{id}` | org | Détail |
| `PATCH` | `/organizations/{id}` | org | Mise à jour — `organization.manage` |
| `DELETE` | `/organizations/{id}` | org | Suppression — `organization.delete` |
| `GET` | `/organizations/{id}/members` | org | Membres et rôles |
| `PATCH` | `/organizations/{id}/members/{user_id}` | org | Modification des rôles — `roles.assign` |
| `DELETE` | `/organizations/{id}/members/{user_id}` | org | Retrait d'un membre — `users.remove` |

Retirer le dernier `owner` renvoie `409` / `LAST_OWNER_CANNOT_LEAVE`.

---

# 5. Workspaces

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/workspaces` | org | Workspaces accessibles à l'utilisateur |
| `POST` | `/workspaces` | org | Création — `workspace.create` |
| `GET` | `/workspaces/{id}` | org | Détail |
| `PATCH` | `/workspaces/{id}` | org | Mise à jour — `workspace.manage` |
| `DELETE` | `/workspaces/{id}` | org | Suppression — `workspace.manage` |
| `GET` | `/workspaces/{id}/members` | org | Membres du workspace |
| `POST` | `/workspaces/{id}/members` | org | Ajout d'un membre — `workspace.manage` |
| `DELETE` | `/workspaces/{id}/members/{membership_id}` | org | Retrait d'un membre |

`GET /workspaces` ne renvoie que les workspaces dont l'utilisateur est membre, sauf s'il détient `workspace.manage`.

Le créateur d'un workspace en devient automatiquement membre — sans quoi il ne verrait pas ce qu'il vient de créer.

Distinction importante sur les refus :

| Situation | Réponse |
| --- | --- |
| Workspace d'une **autre organisation** | `404` / `WORKSPACE_NOT_FOUND` — son existence n'est pas révélée |
| Workspace de **son organisation**, dont on n'est pas membre | `403` / `WORKSPACE_ACCESS_DENIED` |

Toutes ces routes exigent un token portant une organisation active ; à défaut, `403` / `ORGANIZATION_REQUIRED`.

---

# 6. Invitations

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/organizations/{id}/invitations` | org | Invitations en attente |
| `POST` | `/organizations/{id}/invitations` | org | Inviter par email — `users.invite` |
| `DELETE` | `/invitations/{id}` | org | Révoquer une invitation |
| `GET` | `/invitations/{token}` | public | Détail d'une invitation (avant acceptation) |
| `POST` | `/invitations/{token}/accept` | public | Accepter — crée le compte si nécessaire |

Le corps de création référence un rôle existant :

```json
{
  "email": "john@gmail.com",
  "global_role_id": "7c9e6679-7425-40de-944b-e07fc1f90ae7"
}
```

## 6.1 Acceptation

`GET /invitations/{token}` et `POST /invitations/{token}/accept` sont **publics** : le jeton fait office de preuve, puisqu'il n'a été transmis qu'à l'adresse invitée.

* Si aucun compte n'existe pour l'adresse, `accept` exige `first_name`, `last_name` et `password`, et crée le compte. L'adresse est alors considérée comme vérifiée : sa maîtrise est prouvée par la réception du jeton.
* Si un compte existe, ces champs sont inutiles.
* Si l'appelant présente un access token, son adresse doit correspondre à celle de l'invitation — sinon `403` / `INVITATION_EMAIL_MISMATCH`. Une invitation ne peut pas être détournée par un autre compte connecté.

Le jeton en clair n'est renvoyé par `POST /organizations/{id}/invitations` qu'en environnement local ou de test. En production, il n'existe que dans le message envoyé par Notify.

## 6.2 Réponse de la consultation publique

```json
{
  "success": true,
  "data": {
    "email": "john@gmail.com",
    "organization": { "name": "SOS Clinique", "slug": "sos-clinique" },
    "role": "member",
    "expires_at": "2026-08-10T13:42:51Z",
    "requires_account": true
  },
  "meta": { "request_id": "req_8b94d7d0" }
}
```

Ni l'identifiant de l'invitation, ni celui de l'organisation, ni l'inviteur ne sont exposés à un visiteur non authentifié.

---

# 7. Produits et droits d'accès

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/products` | auth | Catalogue des produits de la plateforme |
| `GET` | `/organization-products` | org | Produits accessibles à l'organisation active |
| `POST` | `/organization-products` | org | Activation manuelle — réservé à l'administration Sekuu |
| `DELETE` | `/organization-products/{id}` | org | Désactivation manuelle |

Ces routes exposent des **droits d'accès**, pas des abonnements.

En usage normal, l'activation résulte d'un événement Billing ; l'activation manuelle est un outil d'exception.

> **Il n'existe ni `/subscriptions`, ni `/plans` sur `identity.sekuu.com`.** Ces ressources appartiennent à `billing.sekuu.com` — voir [02-data-model.md](02-data-model.md).

---

# 8. Rôles et permissions

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/global-roles` | auth | Rôles globaux disponibles |
| `GET` | `/global-permissions` | auth | Permissions globales et leur description |

Ces ressources sont en lecture seule : les rôles système ne sont pas modifiables via l'API.

---

# 9. Sessions

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/sessions` | auth | Appareils connectés |
| `DELETE` | `/sessions/{id}` | auth | Déconnexion d'un appareil |

---

# 10. Comptes externes

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/oauth/{provider}/redirect` | public | Démarre le flux OAuth |
| `GET` | `/oauth/{provider}/callback` | public | Retour du fournisseur |
| `GET` | `/oauth/accounts` | auth | Comptes externes liés |
| `DELETE` | `/oauth/accounts/{id}` | auth | Délier un compte |

Délier le dernier moyen de connexion d'un compte sans mot de passe renvoie `409` / `RESOURCE_CONFLICT`.

---

# 11. Journal d'audit

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/audit-logs` | org | Journal de l'organisation — `audit.read` |

Cette collection utilise la **pagination par curseur** (`?cursor=…`), conformément aux guidelines : elle est volumineuse et fortement écrite, deux conditions où l'offset produit doublons et oublis.

Filtres acceptés : `filter[action]`, `filter[user_id]`, `filter[target_type]`. Tout autre filtre renvoie `400` / `INVALID_FILTER` plutôt que d'être ignoré silencieusement.

```json
{
  "success": true,
  "data": [
    {
      "id": "…",
      "action": "workspace.created",
      "target_type": "Workspace",
      "target_id": "…",
      "ip_address": "127.0.0.1",
      "request_id": "req_8b94d7d0",
      "payload": { "name": "Douala", "slug": "douala" },
      "created_at": "2026-08-03T13:42:51Z",
      "user": { "id": "…", "first_name": "Nathan", "last_name": "Tchinda", "email": "nathan@sekuu.com" }
    }
  ],
  "meta": {
    "per_page": 20,
    "next_cursor": "eyJpZCI6…",
    "has_more": true,
    "request_id": "req_8b94d7d0"
  }
}
```

Le `request_id` de chaque entrée est celui de la requête qui a provoqué l'action : c'est ce qui relie une ligne de journal, une réponse d'API et une trace applicative.

---

# 12. Routes de service

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/.well-known/jwks.json` | public | Clés publiques de signature des JWT |
| `GET` | `/auth/revocations?since=` | service | Tokens et sessions révoqués depuis un horodatage |
| `GET` | `/health` | public | État du service |

`/.well-known/jwks.json` et `/health` sont servis à la racine du domaine, hors préfixe `/api/v1`.

---

# 13. Erreurs

Toutes les erreurs suivent le format standard et n'utilisent que des codes du [catalogue](../../02-standards/error-codes.md).

```json
{
  "success": false,
  "error": {
    "code": "LAST_OWNER_CANNOT_LEAVE",
    "message": "Une organisation doit conserver au moins un propriétaire."
  },
  "meta": {
    "request_id": "req_8b94d7d0"
  }
}
```

Rappel : la logique cliente s'appuie sur `code`, jamais sur `message`, qui est traduit selon `Accept-Language`.
