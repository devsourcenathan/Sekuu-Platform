# Sekuu Notify — API

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Base URL :** `https://notify.sekuu.com/api/v1`
> **Dernière mise à jour :** Août 2026

Cette API respecte intégralement les [API Guidelines](../../02-standards/api-guidelines.md) : réponses uniformes, `snake_case`, UUID, dates ISO8601 UTC, `request_id`, codes d'erreur issus du [catalogue commun](../../02-standards/error-codes.md).

Le contrat faisant foi sera `Modules/Notify/openapi.yaml`, versionné avec le code — comme pour [Identity](../identity/03-api.md).

---

# 1. Conventions

* Toutes les routes sont préfixées par `/api/v1`.
* L'authentification est celle de la plateforme : access token émis par Identity, vérifié par signature — voir [security.md](../../02-standards/security.md).
* Les routes marquées `org` exigent un token portant une organisation active.
* Comme partout, une ressource hors périmètre renvoie `404`, jamais `403`.

## 1.1 Deux types d'appelants

| Appelant | Authentification | Ce qu'il peut faire |
| --- | --- | --- |
| **Utilisateur** | Access token | Consulter ses notifications et ses préférences |
| **Service** | API key d'organisation | Déclencher des envois, gérer templates et suppressions |

Déclencher un envoi n'est **jamais** une action d'utilisateur final : un utilisateur ne doit pas pouvoir faire partir un message au nom de la plateforme. Les routes d'envoi exigent une API key portant le scope `notifications.send`.

---

# 2. Envoi

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `POST` | `/notifications` | service | Déclencher un envoi |
| `POST` | `/notifications/bulk` | service | Déclencher jusqu'à 100 envois |
| `GET` | `/notifications` | org | Historique de l'organisation |
| `GET` | `/notifications/{id}` | org | Détail et livraisons |
| `POST` | `/notifications/{id}/cancel` | service | Annuler un envoi programmé |

## 2.1 Déclencher un envoi

```http
POST /api/v1/notifications
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

```json
{
  "template_key": "invitation.sent",
  "recipient": { "email": "john@gmail.com", "user_id": null },
  "locale": "fr",
  "organization_id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "variables": {
    "organization_name": "SOS Clinique",
    "inviter_name": "Nathan Tchinda",
    "accept_url": "https://app.sekuu.com/invitations/…"
  },
  "scheduled_for": null
}
```

L'appelant ne choisit **ni le canal, ni le fournisseur** : le canal appartient au template. C'est ce qui permet de basculer un message de l'email vers WhatsApp sans toucher au code appelant.

Il fournit en revanche **toutes les coordonnées dont il dispose**. Si la clé existe sur plusieurs canaux, le message part par chacun de ceux pour lesquels une coordonnée est connue.

Les numéros sont attendus au format **E.164** (`+237690000000`) : un numéro national est ambigu entre opérateurs, et les passerelles locales le refusent.

**Réponse `202 Accepted`** — l'envoi est accepté, pas effectué :

```json
{
  "success": true,
  "data": {
    "id": "…",
    "status": "queued",
    "template_key": "invitation.sent",
    "channel": "email",
    "category": "operational",
    "locale": "fr",
    "scheduled_for": null
  },
  "meta": { "request_id": "req_8b94d7d0" }
}
```

`202` et non `201` : la ressource créée est une *intention*, et rien ne garantit encore qu'un message partira — le destinataire peut être supprimé ou avoir refusé cette catégorie.

## 2.2 Idempotence

`Idempotency-Key` est **obligatoire** sur `/notifications` et `/notifications/bulk`.

Un envoi est un effet de bord non réversible : sans clé, un rejeu réseau produit un doublon. La même clé réutilisée renvoie la réponse d'origine ; réutilisée avec un corps différent, elle renvoie `409` / `IDEMPOTENCY_KEY_REUSED`.

Les consommateurs d'événements internes utilisent l'identifiant de l'événement comme clé — voir [04-events.md](04-events.md).

## 2.3 Erreurs d'envoi

| Code | HTTP | Cause |
| --- | --- | --- |
| `TEMPLATE_NOT_FOUND` | 404 | Clé inconnue, ou aucun contenu dans une langue utilisable |
| `TEMPLATE_VARIABLE_MISSING` | 422 | Variable obligatoire absente |
| `RECIPIENT_INVALID` | 422 | Adresse ou numéro syntaxiquement invalide |
| `RECIPIENT_OPTED_OUT` | 403 | Le destinataire a désactivé cette catégorie |
| `RECIPIENT_SUPPRESSED` | 403 | Destination sur liste de suppression |
| `CHANNEL_NOT_AVAILABLE` | 422 | Le destinataire n'a pas de coordonnée pour ce canal |
| `CHANNEL_NOT_CONFIGURED` | 503 | Aucun fournisseur actif pour ce canal |
| `QUOTA_EXCEEDED` | 429 | Quota d'envoi de l'organisation atteint |

`RECIPIENT_OPTED_OUT` et `RECIPIENT_SUPPRESSED` sont renvoyés **en synchrone**, avant la mise en file : l'appelant doit savoir que son message ne partira pas. La notification est tout de même enregistrée avec le statut `suppressed`, pour que le journal soit complet.

## 2.4 Envoi groupé

```json
{
  "template_key": "report.weekly",
  "locale": "fr",
  "messages": [
    { "recipient": { "user_id": "…" }, "variables": { "total": 42 } },
    { "recipient": { "user_id": "…" }, "variables": { "total": 17 } }
  ]
}
```

100 messages au maximum par appel. La réponse détaille le sort de chacun : un destinataire supprimé n'invalide pas les 99 autres.

---

# 3. Consultation

`GET /notifications` utilise la **pagination par curseur** — collection volumineuse et fortement écrite.

Filtres : `filter[status]`, `filter[channel]`, `filter[category]`, `filter[template_key]`, `filter[user_id]`, `filter[created_after]`.

Un filtre inconnu renvoie `400` / `INVALID_FILTER`.

`GET /notifications/{id}` renvoie la notification, ses livraisons et ses événements :

```json
{
  "success": true,
  "data": {
    "id": "…",
    "status": "delivered",
    "template_key": "invitation.sent",
    "channel": "email",
    "recipient": "j***@gmail.com",
    "payload": { "organization_name": "SOS Clinique" },
    "deliveries": [
      {
        "provider": "postmark",
        "attempt": 1,
        "status": "accepted",
        "provider_message_id": "…",
        "sent_at": "2026-08-03T13:42:51Z"
      }
    ],
    "events": [
      { "type": "delivered", "occurred_at": "2026-08-03T13:42:55Z" }
    ]
  }
}
```

**Le corps rendu n'est jamais exposé par l'API.** Il contient des liens à usage unique — réinitialisation, invitation — dont la lecture équivaudrait à une prise de contrôle. Le destinataire est masqué pour la même raison. La consultation sert au support et à la réconciliation, pas à relire les messages.

---

# 4. Templates

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/templates` | org | Templates visibles par l'organisation |
| `GET` | `/templates/{id}` | org | Détail et contenus traduits |
| `POST` | `/templates` | service | Créer un template d'organisation |
| `PATCH` | `/templates/{id}` | service | Modifier |
| `DELETE` | `/templates/{id}` | service | Archiver |
| `POST` | `/templates/{id}/preview` | service | Rendu à blanc, sans envoi |

Les templates de plateforme (`organization_id` nul) sont **en lecture seule** via l'API : ils sont versionnés avec le code, comme les migrations. Une organisation peut en créer une variante portant la même clé ; elle prend alors le pas.

`POST /templates/{id}/preview` accepte un jeu de variables et renvoie le rendu, sans rien envoyer ni enregistrer. C'est le seul moyen honnête de vérifier un template avant de l'exposer à de vrais destinataires.

Modifier un template **n'affecte aucun message déjà accepté** : le contenu est figé au moment de l'acceptation.

---

# 5. Préférences

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/preferences` | auth | Préférences de l'utilisateur connecté |
| `PATCH` | `/preferences` | auth | Modifier |
| `GET` | `/preferences/unsubscribe/{token}` | public | Contexte d'un lien de désabonnement |
| `POST` | `/preferences/unsubscribe/{token}` | public | Se désabonner |

```json
{
  "preferences": [
    { "category": "operational", "channel": "email", "enabled": true },
    { "category": "marketing", "channel": "email", "enabled": false }
  ]
}
```

Tenter de désactiver une catégorie `transactional` renvoie `422` / `TRANSACTIONAL_CANNOT_BE_DISABLED`. Accepter puis ne pas appliquer serait un mensonge à l'utilisateur.

Le désabonnement par lien est **public** : exiger une connexion pour se désabonner est une pratique hostile, et contraire aux obligations en la matière. Le jeton est opaque, à usage unique par action, et ne donne accès à rien d'autre.

---

# 6. Liste de suppression

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/suppressions` | service | Destinations supprimées |
| `POST` | `/suppressions` | service | Supprimer manuellement une destination |
| `DELETE` | `/suppressions/{id}` | service | Réhabiliter une destination |

`DELETE` est une action administrative sensible : elle exige le scope `notifications.manage`, et elle est journalisée. Réhabiliter une adresse qui rebondit durablement dégrade la réputation d'expédition de tout le domaine, pas seulement de l'organisation concernée.

---

# 7. Notifications internes

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/inbox` | auth | Notifications internes de l'utilisateur |
| `GET` | `/inbox/unread-count` | auth | Compteur |
| `POST` | `/inbox/{id}/read` | auth | Marquer comme lue |
| `POST` | `/inbox/read-all` | auth | Tout marquer comme lu |

Le canal `in_app` ne dépend d'aucun fournisseur externe : il est toujours disponible, et sert de repli lorsqu'aucun autre canal n'est configuré.

---

# 8. Webhooks entrants

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `POST` | `/webhooks/{provider}` | signature | Retours de livraison |

Route publique au sens réseau, mais **authentifiée par signature** : chaque fournisseur signe ses appels selon son propre schéma, vérifié sur le corps brut avant tout parsing.

Traitement :

1. Vérifier la signature. En cas d'échec : `401` / `WEBHOOK_SIGNATURE_INVALID`.
2. Dédupliquer sur `(provider, provider_event_id)` — les fournisseurs rejouent.
3. Rapprocher via `provider_message_id`.
4. Enregistrer l'événement, mettre à jour le statut.
5. Alimenter la liste de suppression sur `bounced` (dur) et `complained`.

La réponse est **toujours `200`**, y compris pour un événement inconnu ou déjà traité : répondre en erreur déclencherait des réessais inutiles chez le fournisseur.

---

# 9. Codes d'erreur

Les codes propres à Notify sont déclarés dans le [catalogue commun](../../02-standards/error-codes.md). Aucun code n'est inventé hors de ce catalogue.

---

# 10. Ce que l'API n'expose pas

* Le corps rendu des messages (jetons à usage unique).
* Les adresses complètes des destinataires dans l'historique (masquées).
* Les identifiants de fournisseurs.
* Les préférences d'un autre utilisateur.
