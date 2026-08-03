# Sekuu Billing — Modèle de données

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Ce document fait autorité sur les tables du module Billing.

Documents liés : [Vision](01-overview.md) · [API](03-api.md) · [Événements](04-events.md) · [ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md)

---

# 1. Conventions

Identiques à celles d'Identity et de Notify.

* Clés primaires `uuid`.
* `created_at` / `updated_at` sur toutes les tables, `timestamptz`.
* Suppression logique (`deleted_at`) uniquement là où elle est indiquée.
* Aucune clé étrangère vers un autre module : `organization_id`, `user_id` et `product_id` sont des **références logiques**, pour que Billing reste extractible.

## 1.1 Montants

Tout montant est un `bigint`, exprimé dans l'unité la plus petite de sa devise, et **toujours accompagné de sa devise**.

```text
1 000 XAF   →  amount = 1000,   currency = 'XAF'   (exposant 0)
10,00 EUR   →  amount = 1000,   currency = 'EUR'   (exposant 2)
```

Le XAF n'a pas de centime. Stocker `100000` pour 1 000 XAF est l'erreur la plus coûteuse que ce module puisse contenir, et elle est invisible tant que les montants sont inventés.

`bigint` et non `integer` : 2,1 milliards de XAF, c'est environ 3,2 M€. Un plafond atteignable sur un cumul annuel.

---

# 2. Vue d'ensemble

```text
plans ──┬── plan_prices ──────┐
        │                     │
        └── plan_products     │
                              │
        organizations ────────┴── subscriptions ──┬── invoices ── invoice_lines
        (Identity)                                │        │
                                                  │        └── payment_intents
                                                  │                  │
                                                  └────────── transactions
                                                                     
        provider_events        (déduplication des callbacks)
```

Une seule table porte de l'argent constaté : `transactions`. Les autres portent des intentions, des documents ou du catalogue.

---

# 3. Catalogue

## 3.1 Plans

```text
plans

id              uuid         PK
key             varchar(60)  NOT NULL   -- ex. clinic-pro
name            varchar(120) NOT NULL
description     text         NULL
status          varchar(20)  DEFAULT 'active'
is_public       boolean      DEFAULT true
trial_days      smallint     DEFAULT 0
sort_order      smallint     DEFAULT 0
limits          jsonb        DEFAULT '{}'
```

**Contraintes**

* `UNIQUE (key)`.
* `status ∈ { active, archived }`.

**Règles**

Un plan **n'est jamais supprimé**, seulement archivé : des abonnements et des factures y renvoient.

`is_public = false` couvre les plans négociés au cas par cas, invisibles du catalogue mais assignables.

`limits` porte les quotas que les autres modules feront respecter :

```json
{
  "members": 25,
  "workspaces": 5,
  "storage_gb": 50,
  "sms_monthly": 500,
  "ai_credits_monthly": 10000
}
```

`null` dans `limits` signifie *sans limite*. L'absence d'une clé signifie *non couvert par ce plan*. La distinction compte : elle sépare « illimité » de « pas d'accès ».

**Les plans sont versionnés avec le code**, comme les templates de plateforme de Notify. Ils sont créés par migration ou seeder, pas par une API publique. Un tarif ne se change pas depuis un formulaire.

## 3.2 Tarifs

Un plan a plusieurs tarifs : mensuel, annuel, et à terme d'autres devises.

```text
plan_prices

id              uuid         PK
plan_id         uuid         FK → plans(id) ON DELETE RESTRICT
currency        char(3)      NOT NULL
amount          bigint       NOT NULL   -- hors taxes
interval        varchar(10)  NOT NULL   -- month | year
interval_count  smallint     DEFAULT 1
status          varchar(20)  DEFAULT 'active'
```

**Contraintes**

* `UNIQUE (plan_id, currency, interval, interval_count) WHERE status = 'active'` — index partiel.
* `interval ∈ { month, year }`.
* `amount >= 0`.

**Règles**

Changer un prix, c'est **archiver l'ancien tarif et en créer un nouveau**. Jamais un `UPDATE`.

Un abonnement référence le `plan_price_id` avec lequel il a été souscrit : une hausse de tarif ne s'applique donc qu'au renouvellement suivant, et le client conserve son prix jusqu'au terme de sa période payée. C'est aussi ce qui rend une facture passée explicable — le tarif qu'elle applique existe encore, archivé.

`amount = 0` est légitime : plan gratuit.

## 3.3 Produits ouverts par un plan

```text
plan_products

id           uuid  PK
plan_id      uuid  FK → plans(id) ON DELETE CASCADE
product_id   uuid  -- référence logique vers Identity, sans FK
```

**Contraintes** : `UNIQUE (plan_id, product_id)`.

C'est la table qui alimente `organization_products` d'Identity au moment de l'activation. Elle traduit « le plan Clinic Pro » en « ClinicFlow + Stock ».

---

# 4. Abonnements

```text
subscriptions

id                   uuid         PK
organization_id      uuid         NOT NULL   -- référence logique
plan_id              uuid         FK → plans(id)        ON DELETE RESTRICT
plan_price_id        uuid         FK → plan_prices(id)  ON DELETE RESTRICT
status               varchar(20)  DEFAULT 'trialing'
current_period_start timestamptz  NOT NULL
current_period_end   timestamptz  NOT NULL
trial_ends_at        timestamptz  NULL
grace_ends_at        timestamptz  NULL
cancelled_at         timestamptz  NULL
cancel_at_period_end boolean      DEFAULT false
pending_plan_id      uuid         NULL   -- descente en gamme différée
suspended_at         timestamptz  NULL
created_by           uuid         NULL   -- référence logique vers users
```

**Contraintes**

* `UNIQUE (organization_id) WHERE status IN ('trialing','active','grace')` — index partiel. **Une organisation n'a qu'un abonnement vivant à la fois.**
* `status ∈ { trialing, active, grace, suspended, cancelled, expired }`.
* `current_period_end > current_period_start`.

**Index** : `(status, current_period_end)` — c'est la requête de la tâche planifiée quotidienne.

## 4.1 Pourquoi l'unicité est un index partiel

Une organisation ne peut avoir qu'un seul abonnement **en cours**, mais elle en a nécessairement plusieurs dans son historique : celui de l'an dernier, celui qu'elle a résilié, celui qu'elle a repris. Une contrainte d'unicité simple sur `organization_id` rendrait le second impossible.

C'est le même mécanisme que l'unicité des suppressions permanentes dans Notify.

## 4.2 `cancel_at_period_end` plutôt qu'une annulation immédiate

Résilier ne coupe pas l'accès : la période est payée. Le drapeau marque l'intention, et la tâche quotidienne applique la suspension au terme.

Couper immédiatement obligerait à rembourser — impossible à faire proprement en Mobile Money.

## 4.3 `pending_plan_id`

Une descente en gamme est enregistrée ici et appliquée au renouvellement. La montée en gamme, elle, est immédiate et ne passe pas par ce champ : elle est payée sur le champ.

## 4.4 Ce que la table ne contient pas

Aucun champ `is_active` ni `has_access`. L'accès se déduit du statut et des dates, et se lit dans `organization_products` côté Identity. Deux champs qui disent la même chose finissent toujours par se contredire.

---

# 5. Factures

## 5.1 Définition

```text
invoices

id               uuid         PK
organization_id  uuid         NOT NULL
subscription_id  uuid         NULL   FK → subscriptions(id) ON DELETE SET NULL
number           varchar(30)  NOT NULL   -- ex. SKU-2026-000241
status           varchar(20)  DEFAULT 'open'
currency         char(3)      NOT NULL
subtotal         bigint       NOT NULL   -- hors taxes
tax_rate         numeric(6,4) NOT NULL   -- ex. 0.1925, figé
tax_amount       bigint       NOT NULL
total            bigint       NOT NULL
amount_paid      bigint       DEFAULT 0
credit_applied   bigint       DEFAULT 0
period_start     timestamptz  NULL
period_end       timestamptz  NULL
issued_at        timestamptz  NOT NULL
due_at           timestamptz  NULL
paid_at          timestamptz  NULL
voided_at        timestamptz  NULL
billing_details  jsonb        DEFAULT '{}'
```

**Contraintes**

* `UNIQUE (number)`.
* `status ∈ { open, paid, void, uncollectible }`.
* `total = subtotal + tax_amount - credit_applied`.

**Index** : `(organization_id, issued_at DESC)`, `(status, due_at)`.

## 5.2 La numérotation est séquentielle et sans trou

Une facture est un document légal. Sa numérotation doit être continue : un trou est une question à laquelle il faudra répondre lors d'un contrôle.

Un UUID ne convient donc pas comme numéro, et une numérotation par `MAX(number) + 1` produit des doublons sous concurrence. Le numéro provient d'une **séquence PostgreSQL** dédiée par année.

Corollaire : une facture émise ne se supprime pas. On l'**annule** (`void`), et le numéro reste consommé.

## 5.3 Le taux de TVA est copié, pas référencé

`tax_rate` est figé sur la facture. Si le taux passe de 19,25 % à 20 %, les factures passées continuent d'afficher ce qui a été réellement facturé.

C'est la même logique que le contenu rendu figé des notifications, et que `template_key` copié à côté de `template_id` : un document émis ne doit dépendre d'aucune donnée susceptible de changer.

## 5.4 `billing_details` est une copie

Raison sociale, numéro de contribuable, adresse, au moment de l'émission. Les recharger depuis Identity ferait changer une facture passée quand l'organisation change de nom.

## 5.5 Lignes

```text
invoice_lines

id            uuid         PK
invoice_id    uuid         FK → invoices(id) ON DELETE CASCADE
description   varchar(255) NOT NULL
quantity      integer      DEFAULT 1
unit_amount   bigint       NOT NULL   -- hors taxes, peut être négatif
amount        bigint       NOT NULL   -- quantity × unit_amount
product_id    uuid         NULL
metadata      jsonb        DEFAULT '{}'
```

`unit_amount` peut être négatif : c'est ainsi qu'apparaît le crédit de proration lors d'une montée en gamme, lisible sur le document plutôt que soustrait en silence.

---

# 6. Paiements

Le paiement passe par des **agrégateurs** — NotchPay, Tranzak, Tara — et non par des comptes marchands opérateur en direct. Deux tables sont donc nécessaires, et les confondre est la principale erreur de conception possible ici.

```text
payment_intents      ce que le client veut payer          (1)
       │
       └── payment_attempts   ce qu'on a tenté, et chez qui   (N)
```

Une intention, plusieurs tentatives. Un paiement de 49 663 XAF reste **un** paiement même si NotchPay est indisponible et qu'on bascule sur Tranzak.

## 6.1 Intentions de paiement

```text
payment_intents

id               uuid         PK
organization_id  uuid         NOT NULL
invoice_id       uuid         NULL   FK → invoices(id) ON DELETE SET NULL
amount           bigint       NOT NULL
currency         char(3)      NOT NULL
method           varchar(20)  NOT NULL   -- mobile_money | bank_transfer
operator         varchar(20)  NULL       -- mtn | orange, déduit du préfixe
msisdn           varchar(20)  NULL       -- numéro débité, normalisé E.164
status           varchar(20)  DEFAULT 'pending'
failure_code     varchar(60)  NULL
failure_reason   text         NULL
idempotency_key  varchar(255) NULL
expires_at       timestamptz  NOT NULL
initiated_by     uuid         NULL
request_id       varchar(64)  NULL
```

**Contraintes**

* `UNIQUE (idempotency_key) WHERE idempotency_key IS NOT NULL` — index partiel.
* `UNIQUE (invoice_id) WHERE status IN ('pending','processing')` — index partiel. **Une seule intention vivante par facture.**
* `status ∈ { pending, processing, succeeded, failed, expired, cancelled }`.
* `amount > 0`.

`operator` et non `provider` : le réseau du payeur est un fait, l'agrégateur est un choix. Les mélanger dans une colonne rendrait la bascule illisible.

L'unicité partielle sur `invoice_id` est le garde-fou contre le client impatient : sans elle, trois clics produisent trois invites, et trois débits possibles.

## 6.2 Tentatives

```text
payment_attempts

id                  uuid         PK
payment_intent_id   uuid         FK → payment_intents(id) ON DELETE CASCADE
provider            varchar(30)  NOT NULL   -- notchpay | tranzak | tara
priority            smallint     NOT NULL   -- rang au moment de la tentative
merchant_reference  varchar(64)  NOT NULL   -- notre référence, envoyée à l'agrégateur
provider_ref        varchar(120) NULL       -- référence de l'agrégateur
status              varchar(20)  DEFAULT 'created'
customer_prompted   boolean      DEFAULT false
gross_amount        bigint       NULL       -- payé par le client
fee_amount          bigint       NULL       -- commission de l'agrégateur
net_amount          bigint       NULL       -- reversé au marchand
failure_code        varchar(60)  NULL
failure_reason      text         NULL
raw_status          varchar(60)  NULL       -- statut brut de l'agrégateur
last_polled_at      timestamptz  NULL
poll_count          smallint     DEFAULT 0
started_at          timestamptz  NOT NULL
settled_at          timestamptz  NULL
```

**Contraintes**

* `UNIQUE (merchant_reference)` — **notre** clé de corrélation, générée avant l'appel.
* `UNIQUE (provider, provider_ref) WHERE provider_ref IS NOT NULL` — la garantie anti-double-encaissement.
* `UNIQUE (payment_intent_id) WHERE status NOT IN ('rejected','failed','expired','succeeded')` — index partiel. **Une seule tentative vivante par intention.**
* `status ∈ { created, rejected, prompted, processing, succeeded, failed, expired }`.

**Index** : `(status, last_polled_at)` — la requête de la tâche de réconciliation.

## 6.3 `customer_prompted` est la colonne qui empêche le double débit

C'est la donnée la plus importante de la table.

Elle répond à une seule question : **l'invite est-elle partie sur le téléphone du client ?**

| Réponse | Bascule vers un autre agrégateur |
| --- | --- |
| Non — l'agrégateur a refusé la demande | Autorisée |
| Oui | **Interdite** |
| On ne sait pas | **Interdite** |

**Aucun agrégateur n'expose cette information.** Vérification faite dans la documentation de Notch Pay et de Tranzak, aucun champ ni statut ne dit « l'invite est partie » — voir [05-providers.md](05-providers.md).

La colonne est donc renseignée par **déduction**, à partir de la seule chose observable : l'appel de débit a-t-il été accepté ? Une erreur d'authentification ou de validation prouve qu'il ne l'a pas été ; une temporisation ne prouve rien, et vaut donc `true`.

Une fois l'invite envoyée, le client peut la valider avec dix minutes de retard. Rejouer entre-temps chez un autre agrégateur produirait deux invites et deux débits possibles pour une seule facture.

L'incertitude est traitée comme un « oui ». Ne pas encaisser est un incident ; encaisser deux fois est une faute que le client découvre sur son relevé.

## 6.4 `merchant_reference` est généré avant l'appel

Il est écrit en base **avant** que l'agrégateur soit contacté.

Sans cela, un appel qui expire côté réseau laisse une tentative dont on ignore si elle a atteint l'agrégateur — et la réponse à cette question est précisément ce qui autorise ou interdit une bascule. Avec une référence à nous, on peut interroger l'agrégateur pour trancher.

`provider_ref` est unique par agrégateur : la même référence peut exister chez deux d'entre eux sans collision, ce qu'une unicité globale interdirait à tort.

## 6.5 Les trois montants

Un agrégateur prélève une commission. Ce que le client paie n'est pas ce que la plateforme reçoit.

```text
gross_amount   49 663 XAF   débité au client
fee_amount        993 XAF   commission de l'agrégateur
net_amount     48 670 XAF   reversé sur le compte marchand
```

La facture est réglée sur `gross_amount` : le client a payé son dû. La commission est une charge de la plateforme, enregistrée séparément au registre.

Traiter le net comme le montant payé laisserait la facture éternellement impayée à hauteur de la commission — et un abonnement jamais activé.

## 6.6 Pourquoi `poll_count` et `last_polled_at`

Un callback se perd. S'il est la seule source d'information, un client peut avoir été débité sans que la plateforme le sache — il a payé et n'a pas son accès, la pire défaillance possible pour ce module.

Une tâche planifiée reprend donc toute tentative non terminale et interroge l'agrégateur, avec un intervalle croissant, jusqu'à `expires_at` de l'intention.

Une tentative dépassée sans réponse ne devient pas `failed` mais `expired` : *on ne sait pas* n'est pas *cela a échoué*. La différence commande le rapprochement manuel — et interdit toute bascule.

## 6.7 Statuts d'une tentative

| Statut | Sens | Bascule |
| --- | --- | --- |
| `created` | Enregistrée, agrégateur pas encore appelé | — |
| `rejected` | L'agrégateur a refusé la **demande** | **Oui** |
| `prompted` | L'invite est partie sur le téléphone | Non |
| `processing` | L'agrégateur traite | Non |
| `succeeded` | Encaissé | Non |
| `failed` | Le client a refusé, ou solde insuffisant | Non |
| `expired` | Aucune réponse dans le délai | Non |

`rejected` est le seul statut qui autorise une bascule, et il ne concerne que les échecs **avant** que le client ne soit sollicité : agrégateur injoignable, authentification refusée, service en panne, opérateur non couvert.

`failed` n'en autorise aucune : un solde insuffisant chez MTN le reste quel que soit l'agrégateur qui pose la question. C'est la même règle que dans Notify — un rejet métier ne bascule pas, il ne réussira pas davantage ailleurs — avec ici un enjeu supérieur.

## 6.4 Registre des mouvements

```text
transactions

id                  uuid         PK
organization_id     uuid         NOT NULL
invoice_id          uuid         NULL
payment_intent_id   uuid         NULL   FK → payment_intents(id)  ON DELETE SET NULL
payment_attempt_id  uuid         NULL   FK → payment_attempts(id) ON DELETE SET NULL
type                varchar(20)  NOT NULL
amount              bigint       NOT NULL   -- signé
currency            char(3)      NOT NULL
occurred_at         timestamptz  NOT NULL
description         varchar(255) NULL
metadata            jsonb        DEFAULT '{}'
```

**Contraintes**

* `type ∈ { charge, fee, refund, credit, debit, adjustment }`.
* `amount <> 0`.

**Index** : `(organization_id, occurred_at DESC)`.

**Règles**

Table **append-only**. Aucun `UPDATE`, aucun `DELETE` — la migration ne crée d'ailleurs pas de colonne `updated_at`, et un déclencheur PostgreSQL peut interdire les deux.

Le solde de crédit d'une organisation est la **somme** de ses lignes `credit` et `debit`. Il n'est pas stocké : un solde stocké et un registre finissent par diverger, et c'est alors le registre qui a raison.

| Type | Signe | Origine |
| --- | --- | --- |
| `charge` | positif | Paiement encaissé, montant **brut** |
| `fee` | négatif | Commission de l'agrégateur |
| `refund` | négatif | Remboursement effectif, geste commercial |
| `credit` | positif | Proration, avoir |
| `debit` | négatif | Crédit consommé sur une facture |
| `adjustment` | l'un ou l'autre | Correction manuelle, toujours motivée |

Un paiement encaissé produit **deux** lignes : le `charge` brut et le `fee`. Le client a payé 49 663 XAF, la plateforme en a reçu 48 670 — les deux faits sont vrais, et les confondre rendrait le rapprochement bancaire impossible.

`fee` n'entre pas dans le solde de crédit de l'organisation : c'est une charge de la plateforme, pas une somme due au client.

## 6.5 Callbacks entrants

```text
provider_events

id                  uuid         PK
provider            varchar(30)  NOT NULL   -- notchpay | tranzak | tara
provider_event_id   varchar(120) NOT NULL
payment_attempt_id  uuid         NULL   FK → payment_attempts(id) ON DELETE SET NULL
payload             jsonb        NOT NULL
signature_valid     boolean      NOT NULL
received_at         timestamptz  NOT NULL
processed_at        timestamptz  NULL
error               text         NULL
```

**Contraintes** : `UNIQUE (provider, provider_event_id)`.

`payment_attempt_id` est renseigné après rapprochement. Il reste `null` quand le callback ne correspond à aucune tentative connue — cas qui doit être **visible**, car il signale soit une erreur de configuration entre environnements, soit un callback qui ne nous était pas destiné.

**Le montant d'un callback n'est jamais cru.** Il est comparé à l'intention enregistrée, ou relu chez l'agrégateur.

Ce n'est pas de la prudence excessive : Tranzak authentifie ses callbacks par un `authKey` transporté **dans le corps**, ce qui prouve que l'émetteur connaît un secret mais ne dit rien de l'intégrité du corps. Un callback intercepté peut être rejoué modifié. Notch Pay signe en HMAC-SHA256, ce qui interdit la modification — mais pas le rejeu à l'identique, que l'unicité `(provider, provider_event_id)` neutralise.

Le corps brut est conservé. Quand un paiement est contesté, c'est la seule pièce qui dit ce que l'opérateur a réellement envoyé — pas ce que le code en a compris.

Les callbacks à signature invalide sont **enregistrés** avec `signature_valid = false` et non traités. Les jeter en silence priverait de toute trace en cas de tentative de fraude.

---

# 7. Ce que le modèle réserve sans implémenter

| Besoin futur | Place déjà prévue |
| --- | --- |
| Facturation à l'usage | `invoice_lines.quantity` et `metadata` |
| Multi-devise | `currency` sur toutes les tables porteuses de montant |
| Codes promotionnels | Ligne de facture négative, sans table dédiée |
| Cartes bancaires | `payment_intents.method` et `provider` |

Aucun de ces ajouts ne demande de migration destructrice.

---

# 8. Tâches planifiées

| Tâche | Fréquence | Rôle |
| --- | --- | --- |
| `billing:advance` | Quotidienne | Fait passer les abonnements échus en grâce, puis en suspension ; applique les descentes de gamme différées |
| `billing:remind` | Quotidienne | Publie les événements d'échéance à J-7, J-3 et J-1 |
| `billing:reconcile` | Toutes les 5 min | Interroge l'opérateur pour toute intention en attente |
| `billing:expire` | Quotidienne | Passe en `expired` les intentions dépassées et les abonnements suspendus depuis 90 jours |

`billing:advance` doit être **idempotente** : la relancer deux fois le même jour ne doit pas raccourcir une grâce de deux jours. C'est la raison d'être de `grace_ends_at`, une date absolue plutôt qu'un compteur décrémenté.
