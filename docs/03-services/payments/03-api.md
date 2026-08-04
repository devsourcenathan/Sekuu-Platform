# Sekuu Payments — API

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Base URL :** `https://payments.sekuu.com/api/v1`
> **Dernière mise à jour :** Août 2026

Cette API respecte intégralement les [API Guidelines](../../02-standards/api-guidelines.md) : réponses uniformes, `snake_case`, UUID, dates ISO8601 UTC, `request_id`, codes d'erreur issus du [catalogue commun](../../02-standards/error-codes.md).

Le contrat qui fait foi est `Modules/Payments/openapi.yaml`, versionné avec le code. Un test vérifie que toute route déclarée y figure, et qu'aucune route documentée n'a disparu du code.

Documents liés : [Vision](01-overview.md) · [Modèle de données](02-data-model.md) · [Agrégateurs](05-providers.md) · [Intégrer un produit](06-integration.md) · [Service externe](07-external-api.md)

---

# 1. Ce que cette API ne contient pas

**`POST /payments` n'existe pas ici, et n'existera pas.**

Déclencher le paiement d'un objet du monolithe suppose de savoir ce qu'on paie,
combien cela vaut, et qui a le droit de le régler — trois choses que ce module
ignore délibérément. Une route de ce genre offrirait un moyen de faire **sonner
le téléphone de quelqu'un** sans motif vérifiable.

C'est le module propriétaire de l'objet payé qui expose la sienne :

| Ce qu'on règle | Route de déclenchement | Module |
| --- | --- | --- |
| Une facture d'abonnement | `POST /api/v1/payments` | [Billing](../billing/03-api.md#5-déclencher-un-paiement) |
| Une inscription à une formation | `POST /api/v1/payments/charges` | § 6, API externe |

La règle n'a jamais été « aucune création ici ». Elle est **seul le propriétaire
de l'objet nomme son prix** — prouvé par une interface PHP pour un module du
monolithe, par une clé d'API scopée pour un service externe.

---

# 2. Conventions

* Toutes les routes sont préfixées par `/api/v1`.
* Les routes marquées `org` exigent un access token portant une organisation active.
* Une ressource hors périmètre renvoie `404`, jamais `403`.

| Appelant | Authentification | Ce qu'il peut faire |
| --- | --- | --- |
| **Public** | Aucune | Consulter l'état du service |
| **Membre** | Access token + organisation | Consulter ses paiements |
| **Owner / Admin** | Access token + rôle | Voir le détail des tentatives |
| **Agrégateur** | Signature ou secret partagé | Notifier un résultat |

---

# 3. Consulter

| Méthode | Route | Accès | Description |
| --- | --- | --- | --- |
| `GET` | `/payments` | org | Paiements dont l'organisation est **payeuse** |
| `GET` | `/payments/{id}` | org | Statut d'une intention |

La liste répond aux paiements dont l'organisation courante est le **payeur**. Le
jour où un tiers encaisse via la plateforme, il consultera les siens par
bénéficiaire : deux questions distinctes, deux requêtes distinctes.

## 3.1 Sonder

`GET /payments/{id}` est fait pour être **sondé** par le client, toutes les 3 à
5 secondes. La réponse porte `Retry-After` tant que le statut n'est pas
définitif.

```json
{
  "id": "…",
  "status": "processing",
  "operator": "mtn",
  "subject_type": "billing.invoice",
  "subject_id": "…",
  "amount": 49663,
  "currency": "XAF",
  "currency_exponent": 0,
  "formatted": "49 663 XAF",
  "expires_at": "2026-08-03T09:24:00Z",
  "instructions": "Validez la demande sur votre téléphone, ou composez *126#.",
  "attempts": [
    { "provider": "notchpay", "status": "rejected",   "customer_prompted": false, "started_at": "…" },
    { "provider": "tranzak",  "status": "processing", "customer_prompted": true,  "started_at": "…" }
  ]
}
```

`currency_exponent` est renvoyé pour qu'aucun client n'ait à deviner que le XAF
n'a pas de centime. `49663` se lit 49 663 XAF, pas 496,63.

Les tentatives sont exposées pour une raison précise : quand un support client
demande « ce paiement est-il passé ? », la réponse doit être lisible sans
requête SQL. Elles ne le sont **pas** aux membres ordinaires, seulement aux
`Owner` et `Admin` — l'ordre de priorité des agrégateurs est une information
d'exploitation.

`customer_prompted` explique pourquoi la bascule s'est arrêtée là : une fois
l'invite partie, on n'essaie plus ailleurs.

## 3.2 Statuts

| Statut | Signification |
| --- | --- |
| `pending` | Demande envoyée, le client n'a pas encore répondu à l'invite |
| `processing` | L'agrégateur traite |
| `succeeded` | Encaissé, l'objet payé est réglé |
| `failed` | Refusé — solde insuffisant, code erroné, annulation |
| `expired` | **On ne sait pas.** Aucune issue tranchée à temps |
| `cancelled` | Abandonné avant l'invite |

`expired` n'est pas `failed`, et la distinction est importante : un paiement
dont on ignore l'issue **peut avoir été encaissé**. Le traiter comme un échec
risquerait de facturer deux fois. Ces intentions sont réinterrogées par la
réconciliation, et signalées pour rapprochement manuel si l'agrégateur reste
muet.

---

# 4. État du service

| Méthode | Route | Accès |
| --- | --- | --- |
| `GET` | `/payments/health` | public |

```json
{ "status": "degraded", "providers": ["tranzak"], "can_collect": true, "currency": "XAF" }
```

Un agrégateur sans identifiants n'est **jamais essayé**. Le dire franchement
évite de croire qu'un paiement pourra partir alors que rien n'est branché —
`can_collect: false` signifie qu'aucun paiement ne peut aboutir.

C'est ce qui permet de développer sans compte marchand, sans que le module
échoue à l'exécution en le laissant croire.

---

# 5. Callbacks

| Méthode | Route | Accès |
| --- | --- | --- |
| `POST` | `/payments/webhooks/{provider}` | signature |
| `POST` | `/billing/webhooks/{provider}` | signature — **ancienne adresse** |

`provider ∈ { notchpay, tranzak }`. Publique au sens réseau, authentifiée selon
le schéma propre à chaque agrégateur :

* **Notch Pay** — HMAC-SHA256 sur le corps brut, en-tête `x-notch-signature`.
  Une modification en transit est détectable.
* **Tranzak** — `authKey` transporté **dans le corps**. Cela prouve que
  l'émetteur connaît le secret, jamais que le corps est intact.

## 5.1 Un callback n'est jamais cru sur parole

Il déclenche une **relecture** du statut chez l'agrégateur ; il ne le dicte pas.

Un paiement produit plusieurs livraisons, dans un ordre variable — constaté en
conditions réelles chez les deux agrégateurs. Croire le statut annoncé ferait
régresser un encaissement déjà constaté.

## 5.2 Toujours `200`, sauf signature invalide

Y compris pour un doublon ou une référence inconnue. Répondre en erreur
déclencherait des réessais inutiles, et finirait par faire désactiver le
endpoint côté agrégateur — donc par laisser des clients débités sans service.

```json
{ "success": true, "data": { "processed": false, "reason": "duplicate" } }
```

Une signature invalide renvoie `401` / `WEBHOOK_SIGNATURE_INVALID`, et le
callback est tout de même **enregistré** avec `signature_valid = false`. Le
jeter en silence priverait de toute trace en cas de tentative de fraude.

La déduplication s'appuie sur l'unicité en base de `(provider,
provider_event_id)`, pas sur une vérification applicative qui perdrait la course
sous concurrence. La clé n'est pas construite de la même façon chez les deux —
voir [02-data-model.md § 6.2](02-data-model.md#62-la-clé-de-déduplication-diffère-par-agrégateur).

## 5.3 Pourquoi l'ancienne adresse subsiste

`POST /billing/webhooks/{provider}` est strictement équivalente.

Des transactions déjà initialisées portent cette URL **figée dans leur
payload** : la retirer trop tôt ferait échouer leurs callbacks. À supprimer une
fois qu'aucune transaction en cours ne la référence.

## 5.4 Le callback n'est jamais la seule source

`payments:reconcile` interroge les agrégateurs pour toute tentative non
terminale. Un callback perdu **retarde** une confirmation ; il ne la fait pas
disparaître.

Le planificateur n'est pas encore enregistré — voir
[02-data-model.md § 8](02-data-model.md#8-tâches-planifiées).

---

# 6. API externe

| Méthode | Route | Accès |
| --- | --- | --- |
| `POST` | `/payments/charges` | clé d'API — `payments.charge` |
| `GET` | `/payments/charges/{id}` | clé d'API — `payments.read` |
| `GET` | `/payments/charges` | clé d'API — `payments.read` |
| `POST` | `/payments/charges/{id}/refunds` | clé d'API — `payments.refund` |
| `GET` | `/payments/charges/{id}/refunds` | clé d'API — `payments.read` |
| `GET` | `/payments/charges/{id}/refunds/{refund}` | clé d'API — `payments.read` |

Réservée aux produits qui **ne partagent pas cette base de code**. Le prix y est
**déclaré** plutôt que demandé, borné par deux choses : la clé porte la liste des
`subject_type` qu'elle peut faire payer, et le type doit être servi par l'API
externe côté plateforme.

**`billing.invoice` ne peut être porté par aucune clé.**

Ces routes ne s'authentifient jamais par un access token : une clé agit au nom
d'un **produit**, pas d'une personne, et il n'existe aucun utilisateur Sekuu
derrière un apprenant Learn.

`payments.refund` est **distinct** de `payments.charge` : faire entrer de
l'argent et en faire sortir sont deux dangers opposés, et un seul droit pour les
deux serait le plus large des deux.

Un service externe n'obtient **pas** la garantie que « encaissé » et « service
ouvert » soient atomiques : il ne participe pas à la transaction. Le détail
complet, avec ce que le produit doit impérativement mettre en place, est dans
[07-external-api.md](07-external-api.md), et le remboursement dans
[08-refunds.md](08-refunds.md).

---

# 7. Codes d'erreur

Catalogués au [§ 4.5 du catalogue commun](../../02-standards/error-codes.md#45-payments).

| Code | HTTP | Description |
| --- | --- | --- |
| `PAYMENT_ALREADY_PENDING` | 409 | Une intention est déjà vivante sur cet objet |
| `INVALID_MSISDN` | 422 | Numéro invalide ou opérateur non reconnu |
| `PROVIDER_UNAVAILABLE` | 503 | Aucun agrégateur ne couvre cet opérateur, ou tous ont refusé |
| `PAYABLE_TYPE_UNKNOWN` | 500 | `subject_type` absent de `config/payments.php` |
| `WEBHOOK_SIGNATURE_INVALID` | 401 | Signature du callback invalide |

Ces codes sont produits à l'**initiation**, donc renvoyés par la route du module
propriétaire — pas par cette API, qui n'en expose aucune.

`PAYMENT_ALREADY_PENDING` est le garde-fou anti-triple-clic, porté par un index
partiel en base et non par une vérification applicative qui perdrait la course.
Un appelant doit le traiter comme une **information** — « un paiement est déjà
en cours » — et non comme une erreur à réessayer.

`PAYABLE_TYPE_UNKNOWN` échoue durement, délibérément : un repli silencieux ferait
aboutir un paiement que personne ne saurait rattacher, c'est-à-dire de l'argent
encaissé sans service rendu.

`PAYMENT_METHOD_REQUIRED`, présent au catalogue commun, ne sera **pas** utilisé :
il suppose un moyen de paiement enregistré, ce que le Mobile Money ne permet pas.
