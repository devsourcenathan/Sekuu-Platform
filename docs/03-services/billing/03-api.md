# Sekuu Billing — API

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Base URL :** `https://billing.sekuu.com/api/v1`
> **Dernière mise à jour :** Août 2026

Cette API respecte intégralement les [API Guidelines](../../02-standards/api-guidelines.md) : réponses uniformes, `snake_case`, UUID, dates ISO8601 UTC, `request_id`, codes d'erreur issus du [catalogue commun](../../02-standards/error-codes.md).

Le contrat faisant foi sera `Modules/Billing/openapi.yaml`, versionné avec le code — comme pour [Identity](../identity/03-api.md) et [Notify](../notify/03-api.md).

---

# 1. Conventions

* Toutes les routes sont préfixées par `/api/v1`.
* Les routes marquées `org` exigent un access token portant une organisation active.
* Les routes marquées `owner` exigent en plus le rôle `Owner` ou `Admin` de l'organisation.
* Une ressource hors périmètre renvoie `404`, jamais `403`.

## 1.1 Qui peut faire quoi

| Appelant | Authentification | Ce qu'il peut faire |
| --- | --- | --- |
| **Public** | Aucune | Consulter le catalogue de plans |
| **Membre** | Access token | Consulter l'abonnement et les factures |
| **Owner / Admin** | Access token + rôle | Souscrire, changer de plan, payer, résilier |
| **Opérateur** | Signature du callback | Notifier un résultat de paiement |

**Engager une dépense n'est pas une action de membre ordinaire.** Souscrire, monter en gamme ou déclencher un paiement exige `Owner` ou `Admin` : ce sont des actes qui engagent l'organisation financièrement.

À l'inverse, **consulter** l'abonnement est ouvert à tout membre. Un utilisateur doit pouvoir comprendre pourquoi une fonctionnalité lui est refusée sans avoir à demander à son patron.

## 1.2 Ce que cette API ne fait pas

Elle ne répond **jamais** à la question « ai-je accès à ce produit ? ». Cette question se pose à Identity, sur `organization_products`. Y répondre ici créerait une seconde vérité, et placerait Billing sur le chemin critique de chaque requête produit.

---

# 2. Catalogue

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/plans` | public | Plans publics, avec leurs tarifs |
| `GET` | `/plans/{key}` | public | Détail d'un plan |

Le catalogue est **public**, sans authentification : une page de tarifs doit être lisible avant d'avoir un compte.

Les plans non publics (`is_public = false`) n'apparaissent jamais ici, y compris pour l'organisation qui en bénéficie — leur existence est une information commerciale.

```json
{
  "success": true,
  "data": [
    {
      "key": "clinic-pro",
      "name": "Clinic Pro",
      "description": "Pour les cabinets de 5 à 25 praticiens",
      "trial_days": 14,
      "products": ["clinicflow", "stock"],
      "limits": {
        "members": 25,
        "workspaces": 5,
        "storage_gb": 50,
        "sms_monthly": 500
      },
      "prices": [
        { "id": "…", "currency": "XAF", "amount": 45000, "interval": "month" },
        { "id": "…", "currency": "XAF", "amount": 450000, "interval": "year" }
      ]
    }
  ]
}
```

Les montants sont **hors taxes**, en unité la plus petite de la devise. Le XAF n'ayant pas de centime, `45000` se lit 45 000 XAF. Une réponse porte donc aussi l'exposant de la devise, pour qu'aucun client n'ait à le deviner :

```json
"currency_exponent": 0
```

Il n'y a **pas** de `POST /plans`. Les plans sont versionnés avec le code, comme les templates de plateforme de Notify. Un tarif ne se modifie pas depuis un formulaire, et changer un prix consiste à archiver l'ancien tarif — une opération de migration, pas d'API.

---

# 3. Abonnement

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/subscription` | org | Abonnement courant de l'organisation |
| `POST` | `/subscription` | owner | Souscrire |
| `POST` | `/subscription/change` | owner | Changer de plan |
| `POST` | `/subscription/renew` | owner | Renouveler la période |
| `POST` | `/subscription/cancel` | owner | Résilier au terme |
| `POST` | `/subscription/resume` | owner | Annuler une résiliation |
| `GET` | `/subscription/history` | org | Abonnements passés |

Le singulier est délibéré : une organisation n'a **qu'un** abonnement vivant. `/subscriptions/{id}` n'existe pas, parce qu'il n'y a rien à choisir.

## 3.1 Consulter

```json
{
  "success": true,
  "data": {
    "id": "…",
    "status": "grace",
    "plan": { "key": "clinic-pro", "name": "Clinic Pro" },
    "price": { "currency": "XAF", "amount": 45000, "interval": "month" },
    "current_period_start": "2026-07-03T00:00:00Z",
    "current_period_end": "2026-08-03T00:00:00Z",
    "grace_ends_at": "2026-08-10T00:00:00Z",
    "cancel_at_period_end": false,
    "pending_plan": null,
    "access_open": true,
    "credit_balance": 4000
  }
}
```

`access_open` est un **confort d'affichage**, pas une autorisation. Un client qui s'en sert pour ouvrir ou fermer une fonctionnalité fait le mauvais choix : la source de vérité est Identity.

`credit_balance` est la somme du registre, jamais une colonne stockée.

## 3.2 Souscrire

```http
POST /api/v1/subscription
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

```json
{
  "plan_key": "clinic-pro",
  "price_id": "…"
}
```

Réponse `201`. L'abonnement est créé en `trialing` si le plan offre un essai, sinon une facture est émise et l'abonnement reste inactif jusqu'au paiement.

C'est un point de conception important : **souscrire ne donne pas l'accès**. En l'absence d'essai, la souscription produit une facture ; c'est le paiement qui ouvre le produit. Ouvrir d'abord et facturer ensuite reviendrait à accorder un crédit à un inconnu.

| Erreur | Code | Cause |
| --- | --- | --- |
| `409` | `SUBSCRIPTION_ALREADY_ACTIVE` | Un abonnement vivant existe déjà — utiliser `/change` |
| `404` | `PLAN_NOT_FOUND` | Plan inconnu ou archivé |
| `422` | `CURRENCY_NOT_SUPPORTED` | Aucun tarif dans la devise demandée |

## 3.3 Changer de plan

```json
{ "plan_key": "clinic-enterprise", "price_id": "…" }
```

Le comportement dépend du sens, et la réponse le dit explicitement plutôt que de laisser deviner :

```json
{
  "success": true,
  "data": {
    "direction": "upgrade",
    "effective": "immediate",
    "credit_applied": 4000,
    "invoice": { "id": "…", "total": 21000, "status": "open" }
  }
}
```

**Montée en gamme** — immédiate. Le reliquat de la période payée est imputé en crédit sur une facture de différence. L'accès au nouveau plan s'ouvre au paiement de cette facture.

**Descente en gamme** — différée au terme (`effective: "period_end"`). La période en cours est payée ; l'écourter obligerait à rembourser, ce que le Mobile Money rend lent et coûteux. Le plan visé est enregistré dans `pending_plan_id` et appliqué au renouvellement.

Une descente est refusée si l'usage courant dépasse les limites du plan visé :

```json
{
  "success": false,
  "error": {
    "code": "DOWNGRADE_NOT_ALLOWED",
    "message": "L'usage actuel dépasse les limites du plan visé.",
    "details": [
      { "limit": "workspaces", "current": 3, "allowed": 1 },
      { "limit": "members", "current": 12, "allowed": 5 }
    ]
  }
}
```

Refuser plutôt qu'accepter puis appliquer : appliquer signifierait supprimer deux workspaces du client. Aucun formulaire de facturation ne devrait pouvoir détruire des données.

## 3.4 Renouveler

```http
POST /api/v1/subscription/renew
```

Émet la facture de la période suivante et renvoie de quoi la payer. C'est **l'acte volontaire** qui remplace le renouvellement automatique — voir [ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md).

Appelable pendant la période en cours (renouvellement anticipé, la période s'ajoute) comme pendant la grâce.

## 3.5 Résilier

```json
{ "reason": "trop cher" }
```

Positionne `cancel_at_period_end`. L'accès est maintenu jusqu'au terme — il est payé. `POST /subscription/resume` annule cette intention tant que le terme n'est pas atteint.

Le motif est facultatif et **libre**. Une liste fermée produit des statistiques flatteuses et sans valeur.

---

# 4. Factures

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/invoices` | org | Factures de l'organisation |
| `GET` | `/invoices/{id}` | org | Détail et lignes |
| `GET` | `/invoices/{id}/download` | org | PDF |

Pas de `POST` : une facture n'est jamais créée à la main. Elle est la conséquence d'une souscription, d'un changement de plan ou d'un renouvellement.

Pas de `DELETE` non plus : une facture émise s'annule (`void`), elle ne se supprime pas. La numérotation doit rester sans trou.

Filtres : `filter[status]`, `filter[period]`. Tri par `issued_at` décroissant par défaut.

```json
{
  "id": "…",
  "number": "SKU-2026-000241",
  "status": "open",
  "currency": "XAF",
  "currency_exponent": 0,
  "subtotal": 45000,
  "tax_rate": 0.1925,
  "tax_amount": 8663,
  "credit_applied": 4000,
  "total": 49663,
  "amount_paid": 0,
  "issued_at": "2026-08-03T09:14:00Z",
  "due_at": "2026-08-10T00:00:00Z",
  "lines": [
    { "description": "Clinic Pro — août 2026", "quantity": 1, "unit_amount": 45000, "amount": 45000 },
    { "description": "Crédit période précédente", "quantity": 1, "unit_amount": -4000, "amount": -4000 }
  ]
}
```

Le crédit apparaît comme **une ligne**, pas comme une soustraction silencieuse. Un client doit pouvoir vérifier son total à la main.

`GET /invoices/{id}/download` renverra `302` vers une URL signée de Storage. Tant que Storage n'existe pas, la route renvoie `503` / `SERVICE_UNAVAILABLE` — franchement, plutôt qu'un PDF généré à la volée dont personne ne garantit qu'il sera identique demain.

---

# 5. Paiement

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `POST` | `/payments` | owner | Initier un paiement |
| `GET` | `/payments/{id}` | org | Statut d'une intention |
| `GET` | `/payments` | org | Historique |
| `POST` | `/webhooks/{provider}` | signature | Callback opérateur |

## 5.1 Initier

```http
POST /api/v1/payments
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

```json
{
  "invoice_id": "…",
  "method": "mobile_money",
  "msisdn": "+237650000000"
}
```

**Le montant n'est pas dans le corps.** Il vient de la facture. Accepter un montant fourni par l'appelant permettrait de régler 49 663 XAF avec 100 XAF — c'est une faille, pas une commodité.

**L'agrégateur non plus n'est pas dans le corps.** NotchPay, Tranzak et Tara sont un détail d'exploitation ; les exposer au choix du client figerait l'ordre de priorité dans son interface, et empêcherait toute bascule.

L'opérateur, lui, est déduit du préfixe du numéro. C'est un fait, pas un choix.

Réponse `202` — le paiement n'est pas fait, il est **demandé** :

```json
{
  "success": true,
  "data": {
    "id": "…",
    "status": "pending",
    "operator": "mtn",
    "expires_at": "2026-08-03T09:24:00Z",
    "instructions": "Validez la demande sur votre téléphone, ou composez *126#."
  }
}
```

`202` et non `201`, pour la même raison qu'un envoi Notify : ce qui est créé est une **intention**. Le client doit ensuite interroger `GET /payments/{id}`.

| Erreur | Code | Cause |
| --- | --- | --- |
| `422` | `INVALID_MSISDN` | Numéro invalide, ou opérateur non reconnu |
| `409` | `INVOICE_ALREADY_PAID` | Facture déjà réglée |
| `409` | `PAYMENT_ALREADY_PENDING` | Une intention est déjà en cours sur cette facture |
| `503` | `PROVIDER_UNAVAILABLE` | Aucun agrégateur configuré ne couvre cet opérateur |

`PAYMENT_ALREADY_PENDING` est un garde-fou concret : sans lui, un client impatient qui clique trois fois reçoit trois invites, et peut payer trois fois. La contrainte est portée par un index partiel en base, pas par une vérification applicative qui perdrait la course.

`PROVIDER_UNAVAILABLE` couvre aussi le cas où les trois agrégateurs ont été essayés sans qu'aucun n'accepte la demande. L'intention reste consultable, avec le détail de chaque tentative.

## 5.2 Suivre

`GET /payments/{id}` est fait pour être **sondé** par le client, toutes les 3 à 5 secondes. La réponse porte `Retry-After` tant que le statut n'est pas définitif.

```json
{
  "id": "…",
  "status": "processing",
  "operator": "mtn",
  "polled_at": "2026-08-03T09:16:31Z",
  "attempts": [
    { "provider": "notchpay", "status": "rejected", "customer_prompted": false, "started_at": "…" },
    { "provider": "tranzak",  "status": "processing", "customer_prompted": true,  "started_at": "…" }
  ]
}
```

Les tentatives sont exposées pour une raison précise : quand un support client demande « ce paiement est-il passé ? », la réponse doit être lisible sans requête SQL. Elles ne le sont **pas** aux membres ordinaires, seulement aux `Owner` et `Admin` — l'ordre de priorité des agrégateurs est une information d'exploitation.

`customer_prompted` explique pourquoi la bascule s'est arrêtée là : une fois l'invite partie, on n'essaie plus ailleurs.

| Statut | Signification |
| --- | --- |
| `pending` | Demande envoyée, le client n'a pas encore répondu à l'invite |
| `processing` | L'opérateur traite |
| `succeeded` | Encaissé, facture réglée, accès ouvert |
| `failed` | Refusé — solde insuffisant, code erroné, annulation |
| `expired` | **On ne sait pas.** L'opérateur n'a pas tranché à temps |
| `cancelled` | Abandonné avant l'invite |

`expired` n'est pas `failed`, et la distinction est importante : un paiement dont on ignore l'issue peut avoir été encaissé. Le traiter comme un échec risquerait de facturer deux fois. Ces intentions sont réinterrogées par la tâche de réconciliation, et signalées pour rapprochement manuel si l'opérateur reste muet.

## 5.3 Callbacks

`POST /webhooks/{provider}` où `provider ∈ { notchpay, tranzak, tara }` — publique au sens réseau, authentifiée par **signature vérifiée sur le corps brut**, comme les webhooks de Notify.

Chaque agrégateur signe selon son propre schéma. Un adaptateur par agrégateur traduit son vocabulaire vers celui du module, et cette traduction est la partie la plus sensible du code : **confondre un rejet avant invite avec un échec après invite autorise une bascule qui double-débite le client.** Chaque adaptateur doit donc énumérer explicitement les statuts qu'il considère comme « invite jamais partie », et traiter tout statut inconnu comme « invite partie ».


Une signature invalide renvoie `401` / `WEBHOOK_SIGNATURE_INVALID`, et le callback est tout de même **enregistré** avec `signature_valid = false`. Le jeter en silence priverait de toute trace en cas de tentative de fraude.

Un callback déjà vu renvoie `200` sans rien refaire — la déduplication s'appuie sur l'unicité en base de `(provider, provider_event_id)`, pas sur une vérification applicative qui perdrait la course sous concurrence.

**Un callback n'est jamais la seule source d'information.** La tâche `billing:reconcile` interroge l'opérateur toutes les cinq minutes pour toute intention en attente. Un callback perdu retarde une confirmation ; il ne la fait pas disparaître.

---

# 6. Crédit

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/credit` | org | Solde et mouvements |

```json
{
  "balance": 4000,
  "currency": "XAF",
  "entries": [
    { "type": "credit", "amount": 4000, "description": "Proration Clinic Pro", "occurred_at": "…" }
  ]
}
```

Lecture seule. Le crédit ne s'achète pas et ne se retire pas : il naît d'une proration ou d'un avoir, et s'impute sur une facture.

---

# 7. Idempotence

L'en-tête `Idempotency-Key` est **obligatoire** sur `POST /subscription`, `/subscription/change`, `/subscription/renew` et `/payments`.

Ce sont des effets de bord non réversibles : un rejeu réseau produirait un second paiement ou un second abonnement. Même règle que sur l'envoi d'une notification, avec un enjeu supérieur — ici, le doublon coûte de l'argent au client.

Une clé réutilisée avec un corps différent renvoie `409` / `IDEMPOTENCY_KEY_REUSED`.

---

# 8. Codes d'erreur

Au [catalogue commun](../../02-standards/error-codes.md#44-billing) s'ajoutent :

| Code | HTTP | Description |
| --- | --- | --- |
| `PAYMENT_ALREADY_PENDING` | 409 | Une intention est déjà en cours sur cette facture |
| `PAYMENT_PENDING` | 202 | Paiement en cours, issue inconnue |
| `INVALID_MSISDN` | 422 | Numéro invalide ou opérateur non reconnu |
| `PROVIDER_UNAVAILABLE` | 503 | Aucun fournisseur configuré pour cet opérateur |
| `INVOICE_NOT_FOUND` | 404 | Facture inexistante |
| `INVOICE_ALREADY_PAID` | 409 | Facture déjà réglée |
| `INVOICE_VOIDED` | 409 | Facture annulée, non payable |
| `PLAN_ARCHIVED` | 409 | Plan retiré du catalogue |
| `WEBHOOK_SIGNATURE_INVALID` | 401 | Signature du callback invalide |

`PAYMENT_METHOD_REQUIRED`, présent au catalogue commun, ne sera **pas** utilisé : il suppose un moyen de paiement enregistré, ce que le Mobile Money ne permet pas.
