# Sekuu Billing — Modèle de données

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Ce document fait autorité sur les tables du module Billing.

Documents liés : [Vision](01-overview.md) · [API](03-api.md) · [Événements](04-events.md) · [ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md)

> **Les tables de paiement ne sont plus ici.** `payment_intents`,
> `payment_attempts`, `payment_transactions` et `provider_events` appartiennent
> au module Payments et font autorité dans
> [son modèle de données](../payments/02-data-model.md). Billing ne conserve que
> son registre de **crédit commercial** (§ 6).

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
        organizations ────────┴── subscriptions ──── invoices ──┬── invoice_lines
        (Identity)                                              │
                                                                └── credit_entries
                                                                    (registre de crédit)

        payment_intents        →  module Payments, référence logique
```

**Aucune table de Billing ne porte d'argent encaissé.** L'encaissement est
constaté dans `payment_transactions`, côté
[Payments](../payments/02-data-model.md#5-registre-de-caisse). Ce qui vit ici,
ce sont des documents (`invoices`), du catalogue (`plans`), un droit d'accès
daté (`subscriptions`) et un solde commercial (`credit_entries`).

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

### Les limites se changent sans déployer

`limits` est une colonne `jsonb` : la donnée est déjà une donnée. Ce qui
manquait était le moyen de l'écrire — l'API ne rendait les plans qu'en lecture,
et les valeurs venaient d'une migration.

Elles se modifient désormais par `PATCH /platform/plans/{key}`, réservé à un
**opérateur de plateforme** ([ADR-0018](../../04-decisions/adr-0018-platform-operator.md)).
Aucun rôle d'organisation n'y donne accès : une route protégée par
`subscription.manage` laisserait un client s'accorder mille sièges.

La frontière posée par cette décision vaut d'être retenue : **un nombre passe
par l'API, un secret n'y passe jamais.** Les identifiants d'agrégateurs et les
magasins restent en ligne de commande.

Clés en usage : `members`, `workspaces`, `storage_gb`, `sms_monthly`,
`ai_credits_monthly`.

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
status               varchar(20)  DEFAULT 'pending'
current_period_start timestamptz  NOT NULL
current_period_end   timestamptz  NOT NULL
trial_ends_at        timestamptz  NULL
grace_ends_at        timestamptz  NULL
cancelled_at         timestamptz  NULL
cancel_at_period_end boolean      DEFAULT false
pending_plan_id      uuid         NULL   -- descente en gamme différée
pending_plan_price_id uuid        NULL
suspended_at         timestamptz  NULL
created_by           uuid         NULL   -- référence logique vers users
```

**Contraintes**

* `UNIQUE (organization_id) WHERE status IN ('pending','trialing','active','grace')` — index partiel. **Une organisation n'a qu'un abonnement vivant à la fois.**
* `status ∈ { pending, trialing, active, grace, suspended, cancelled, expired }`.
* `current_period_end > current_period_start`.

**Index** : `(status, current_period_end)` — c'est la requête de la tâche planifiée quotidienne.

## 4.0 `granted_limits` — ce qui a été promis

Colonne `jsonb`, **non nullable**, écrite à l'ouverture de chaque période.
`BillingContract::limit()` la lit, et ne lit plus jamais le plan
([ADR-0019](../../04-decisions/adr-0019-granted-limits.md)).

Sans elle, baisser une limite du catalogue rétrograderait tous les abonnés le
soir même — y compris ceux qui ont payé une année d'avance la semaine
précédente. Sur un modèle prépayé, ce n'est pas un réglage, c'est une rupture de
contrat.

L'asymétrie est la décision : **une hausse est reportée immédiatement sur tous
les abonnements actifs, une baisse attend le renouvellement.** La plateforme
peut être plus généreuse que promis, jamais moins.

Non nullable, et avec un objet vide par défaut, pour une raison précise : lire
`null` sur une colonne vide et en conclure « illimité » serait le défaut le plus
coûteux possible. Un abonnement sans copie est **non couvert**, et
`billing:regrant` remplit ce qui manque.

## 4.1 `pending` fait partie des états vivants, et c'est un correctif

Un abonnement souscrit sans essai attend son premier paiement. Il était marqué
`suspended`, ce qui le plaçait **hors** de l'index d'unicité : une organisation
pouvait alors créer un second abonnement, puis un troisième, chacun avec sa
facture.

`pending` existe pour cela, et il est inclus dans les états vivants. Le nettoyage
des souscriptions jamais payées est une opération explicite, pas un effet de bord
d'un statut mal choisi.

## 4.2 Pourquoi l'unicité est un index partiel

Une organisation ne peut avoir qu'un seul abonnement **en cours**, mais elle en a nécessairement plusieurs dans son historique : celui de l'an dernier, celui qu'elle a résilié, celui qu'elle a repris. Une contrainte d'unicité simple sur `organization_id` rendrait le second impossible.

C'est le même mécanisme que l'unicité des suppressions permanentes dans Notify.

## 4.3 `cancel_at_period_end` plutôt qu'une annulation immédiate

Résilier ne coupe pas l'accès : la période est payée. Le drapeau marque l'intention, et la tâche quotidienne applique la suspension au terme.

Couper immédiatement obligerait à rembourser — impossible à faire proprement en Mobile Money.

## 4.4 `pending_plan_id`

Une descente en gamme est enregistrée ici et appliquée au renouvellement. La montée en gamme, elle, est immédiate et ne passe pas par ce champ : elle est payée sur le champ.

## 4.5 Ce que la table ne contient pas

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

# 6. Registre de crédit

C'est la seule table de Billing qui porte des montants **constatés**, et elle ne
porte pas d'argent encaissé : elle porte un **crédit commercial**.

```text
credit_entries

id                  uuid         PK
organization_id     uuid         NOT NULL   -- référence logique
invoice_id          uuid         NULL       -- référence logique
payment_intent_id   uuid         NULL       -- référence logique, vers Payments
type                varchar(20)  NOT NULL
amount              bigint       NOT NULL   -- signé
currency            char(3)      NOT NULL
occurred_at         timestamptz  NOT NULL
description         varchar(255) NULL
metadata            jsonb        DEFAULT '{}'
created_at          timestamptz  NULL
```

**Contraintes**

* `type ∈ { credit, debit, adjustment }`.
* `amount <> 0`.
* `UNIQUE (invoice_id, payment_intent_id, type) WHERE invoice_id IS NOT NULL AND payment_intent_id IS NOT NULL`.

**Index** : `(organization_id, occurred_at)`.

| Type | Signe | Origine |
| --- | --- | --- |
| `credit` | positif | Proration d'une montée en gamme, avoir |
| `debit` | négatif | Crédit consommé sur une facture |
| `adjustment` | l'un ou l'autre | Correction manuelle, toujours motivée |

Le solde de crédit d'une organisation est la **somme** de ses lignes. Il n'est
pas stocké : un solde stocké et un registre finissent par diverger, et c'est
alors le registre qui a raison.

## 6.1 Pourquoi il est séparé du registre de caisse

Les deux étaient colocalisés dans une table `transactions` unique, mais la
frontière existait déjà dans le code : `Transaction::creditTypes()` excluait
exactement `charge` et `fee`.

La scission ne crée pas la frontière, elle la rend **physique** — et supprime au
passage deux clés étrangères qui allaient devenir inter-modules.

Elle sépare aussi deux natures distinctes. Une commission d'agrégateur est une
charge de la plateforme ; elle n'a jamais eu à entrer dans le solde d'un client.

## 6.2 Append-only, des deux côtés

Aucun `UPDATE`, aucun `DELETE` — pas de colonne `updated_at`, et le modèle lève
sur les deux opérations.

C'est une propriété du **registre**, pas du module : elle devait donc être
répliquée dans `payment_transactions`, et elle l'est.

## 6.3 L'unicité protège d'un rejeu

Le règlement d'une facture passe désormais par un événement, et un événement
peut être livré plusieurs fois.

Sans cette contrainte, un rejeu créditerait deux fois — et le solde n'étant pas
stocké mais calculé, l'erreur serait invisible jusqu'à la facture suivante.

## 6.4 Ce que Billing ne porte plus

| Table | Où elle vit | Ce qu'elle porte |
| --- | --- | --- |
| `payment_intents` | Payments | Ce qu'un payeur veut régler |
| `payment_attempts` | Payments | Ce qu'on a tenté, et chez quel agrégateur |
| `payment_transactions` | Payments | L'argent réellement encaissé, et la commission |
| `provider_events` | Payments | Les callbacks bruts, dédupliqués |

`invoices` n'a **aucune** clé étrangère vers `payment_intents`, et
réciproquement. Le lien se fait par `(subject_type, subject_id)` —
`('billing.invoice', <id>)` — que Payments transporte sans jamais l'interpréter.

C'est ce qui permet à une inscription à une formation d'emprunter exactement le
même chemin qu'une facture d'abonnement. Voir
[ADR-0009](../../04-decisions/adr-0009-payments-module-extraction.md).

---

# 7. Ce que le modèle réserve sans implémenter

| Besoin futur | Place déjà prévue |
| --- | --- |
| Facturation à l'usage | `invoice_lines.quantity` et `metadata` |
| Multi-devise | `currency` sur toutes les tables porteuses de montant |
| Codes promotionnels | Ligne de facture négative, sans table dédiée |

Aucun de ces ajouts ne demande de migration destructrice.

Les réserves qui touchent au paiement — remboursement, encaissement pour un
tiers, carte bancaire — appartiennent au
[modèle de Payments](../payments/02-data-model.md#7-ce-que-le-modèle-réserve-sans-implémenter).

---

# 8. Tâches planifiées

| Commande | Fréquence | Rôle |
| --- | --- | --- |
| `billing:advance` | Quotidienne, 02:30 | Grâce, suspension, expiration **et** rappels d'échéance à J-7, J-3, J-1 |

Une seule commande, et non quatre : `billing:remind` et `billing:expire` n'ont
jamais existé séparément. Les découper reviendrait à faire trois passes sur la
même table pour lire les mêmes dates.

Tôt le matin, délibérément : une suspension doit être constatée avant que
l'organisation n'ouvre ses portes, pas au milieu de sa journée.

Elle doit être **idempotente** : la relancer deux fois le même jour ne doit pas
raccourcir une grâce de deux jours. C'est la raison d'être de `grace_ends_at`,
une date absolue plutôt qu'un compteur décrémenté.

La réconciliation des paiements a quitté ce module :
[`payments:reconcile`](../payments/02-data-model.md#8-tâches-planifiées).
