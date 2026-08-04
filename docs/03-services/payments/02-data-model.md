# Sekuu Payments — Modèle de données

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Ce document fait autorité sur les tables du module Payments.

Documents liés : [Vision](01-overview.md) · [API](03-api.md) · [Agrégateurs](05-providers.md) · [Intégrer un produit](06-integration.md) · [ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md) · [ADR-0009](../../04-decisions/adr-0009-payments-module-extraction.md)

Source de vérité : `Modules/Payments/Database/Migrations/2026_03_01_000300_create_payments_tables.php`.

---

# 1. Conventions

Identiques à celles des autres modules.

* Clés primaires `uuid`.
* `timestamptz` partout.
* **Aucune clé étrangère sortant du module.** `subject_id`, `payer_id`,
  `payee_organization_id` et `initiated_by` sont des références **logiques**.

Cette dernière règle n'est pas de la coquetterie ici : c'est ce qui permet à
`learn.enrollment` d'être payé sans qu'aucune table de Learn n'existe dans cette
base. Une clé étrangère vers `invoices` interdirait tout paiement qui n'est pas
une facture.

## 1.1 Montants

Tout montant est un `bigint`, dans l'unité la plus petite de sa devise, et
**toujours accompagné de sa devise**.

```text
1 000 XAF   →  amount = 1000,   currency = 'XAF'   (exposant 0)
10,00 EUR   →  amount = 1000,   currency = 'EUR'   (exposant 2)
```

Le XAF n'a pas de centime. Stocker `100000` pour 1 000 XAF est l'erreur la plus
coûteuse que ce module puisse contenir, et elle est invisible tant que les
montants sont inventés.

`bigint` et non `integer` : 2,1 milliards de XAF, c'est environ 3,2 M€ — un
plafond atteignable sur un cumul.

---

# 2. Vue d'ensemble

```text
payment_intents ──┬── payment_attempts ──── provider_events
   (ce que le     │      (ce qu'on a           (callbacks bruts,
    client veut   │       tenté, et chez        dédupliqués)
    payer)        │       qui)
                  │
                  └── payment_transactions
                         (registre de caisse, append-only)
```

Quatre tables, et **aucune** ne connaît la facture, l'inscription ou la commande
qu'elle règle. Elles portent un couple `(subject_type, subject_id)` qu'elles
transportent sans jamais l'interpréter.

Le crédit commercial d'une organisation n'est pas ici : il vit dans
`credit_entries`, côté [Billing](../billing/02-data-model.md#6-registre-de-crédit).

---

# 3. Intentions

Une intention, plusieurs tentatives. Un paiement de 49 663 XAF reste **un**
paiement même si NotchPay est indisponible et qu'on bascule sur Tranzak.

```text
payment_intents

id                     uuid         PK
subject_type           varchar(40)  NOT NULL   -- billing.invoice, learn.enrollment
subject_id             uuid         NOT NULL
payer_type             varchar(40)  NOT NULL   -- identity.organization, identity.user
payer_id               uuid         NOT NULL
payee_organization_id  uuid         NULL       -- null = la plateforme encaisse
amount                 bigint       NOT NULL
currency               char(3)      NOT NULL
method                 varchar(20)  NOT NULL   -- mobile_money | bank_transfer
operator               varchar(20)  NULL       -- mtn | orange, déduit du préfixe
msisdn                 varchar(20)  NULL       -- numéro débité, normalisé E.164
status                 varchar(20)  DEFAULT 'pending'
failure_code           varchar(60)  NULL
failure_reason         text         NULL
idempotency_key        varchar(255) NULL
expires_at             timestamptz  NOT NULL
initiated_by           uuid         NULL
request_id             varchar(64)  NULL
```

**Contraintes**

* `status ∈ { pending, processing, succeeded, failed, expired, cancelled }`.
* `amount > 0`.
* `UNIQUE (payer_type, payer_id, idempotency_key) WHERE idempotency_key IS NOT NULL`.
* `UNIQUE (subject_type, subject_id) WHERE status IN ('pending','processing')`.

**Index** : `(payer_type, payer_id)`, `(subject_type, subject_id)`, `payee_organization_id`.

## 3.1 `subject_type` / `subject_id` sont NOT NULL, et c'est le point

C'est ce qui permet à l'index d'unicité de se passer d'une clause d'exclusion.

La version précédente portait `invoice_id NULL` et excluait explicitement les
intentions sans facture. Autrement dit, un paiement qui n'était pas une facture
n'avait **aucune** protection anti-double-invite : trois clics, trois invites,
trois débits possibles. Le garde-fou existait pour Billing et pour lui seul.

## 3.2 Payeur et bénéficiaire sont deux colonnes

Les confondre marche tant qu'il n'y a qu'un vendeur.

Sekuu facturant ses organisations clientes, un unique `organization_id`
désignait le **payeur**. Le jour où un centre de formation encaisse via la
plateforme, le même champ désignerait le **bénéficiaire**. Un seul champ ne peut
pas dire les deux, et s'en apercevoir plus tard obligerait à remigrer des
données monétaires.

`payee_organization_id = null` signifie que la plateforme encaisse pour
elle-même. C'est le cas de toute facture Billing.

**Rien n'est construit derrière le bénéficiaire** — voir [01-overview.md § 7](01-overview.md#7-ce-que-ce-module-ne-sait-pas-encore-faire).

## 3.3 L'idempotence est scopée au payeur

L'index portait auparavant sur la seule colonne `idempotency_key`.

Avec deux produits dont les clients dérivent naturellement leurs clés du métier
— `invoice-123`, `order-1` — un payeur pouvait recevoir en réponse l'intention
d'un **autre** payeur, montant et tentatives compris, et voir son propre
paiement silencieusement non lancé.

## 3.4 `operator` et non `provider`

Le réseau du payeur est un **fait**, déduit du préfixe du numéro. L'agrégateur
est un **choix**. Les mélanger dans une colonne rendrait la bascule illisible.

---

# 4. Tentatives

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

* `status ∈ { created, rejected, prompted, processing, succeeded, failed, expired }`.
* `UNIQUE (merchant_reference)`.
* `UNIQUE (provider, provider_ref) WHERE provider_ref IS NOT NULL`.
* `UNIQUE (payment_intent_id) WHERE status IN ('created','prompted','processing')`.

**Index** : `(status, last_polled_at)` — la requête de la réconciliation.

## 4.1 `customer_prompted` est la colonne qui empêche le double débit

C'est la donnée la plus importante du module.

Elle répond à une seule question : **l'invite est-elle partie sur le téléphone
du client ?**

| Réponse | Bascule vers un autre agrégateur |
| --- | --- |
| Non — l'agrégateur a refusé la demande | Autorisée |
| Oui | **Interdite** |
| On ne sait pas | **Interdite** |

**Aucun agrégateur n'expose cette information.** Vérification faite dans la
documentation de Notch Pay et de Tranzak, puis contre leurs bacs à sable :
aucun champ ni statut ne dit « l'invite est partie » — voir
[05-providers.md](05-providers.md).

La colonne est donc renseignée par **déduction**, à partir de la seule chose
observable : l'appel de débit a-t-il été accepté ? Une erreur d'authentification
ou de validation prouve qu'il ne l'a pas été ; une temporisation ne prouve rien,
et vaut donc `true`.

Une fois l'invite envoyée, le client peut la valider avec dix minutes de retard.
Rejouer entre-temps chez un autre agrégateur produirait deux invites et deux
débits possibles pour un seul objet.

La colonne ne **redescend jamais** à `false`. Une livraison tardive annonçant un
état antérieur rouvrirait la bascule sur un client déjà sollicité.

## 4.2 `merchant_reference` est généré avant l'appel

Il est écrit en base **avant** que l'agrégateur soit contacté.

Sans cela, un appel qui expire côté réseau laisse une tentative dont on ignore
si elle a atteint l'agrégateur — et la réponse à cette question est précisément
ce qui autorise ou interdit une bascule. Avec une référence à nous, on peut
interroger l'agrégateur pour trancher.

`provider_ref` est unique **par agrégateur** : la même référence peut exister
chez deux d'entre eux sans collision, ce qu'une unicité globale interdirait à
tort.

## 4.3 Les trois montants

Un agrégateur prélève une commission. Ce que le client paie n'est pas ce que la
plateforme reçoit.

```text
gross_amount   49 663 XAF   débité au client
fee_amount        993 XAF   commission de l'agrégateur
net_amount     48 670 XAF   reversé sur le compte marchand
```

L'objet est réglé sur `gross_amount` : le client a payé son dû. La commission
est une charge de la plateforme, enregistrée séparément au registre.

Traiter le net comme le montant payé laisserait la facture éternellement
impayée à hauteur de la commission — et un abonnement jamais activé.

**Ces trois champs se lisent là où l'agrégateur les met, et pas ailleurs.**
Tranzak les niche sous `merchant` ; les lire à la racine renvoyait `null` sans
erreur, et la ligne `fee` n'était jamais écrite. Notch Pay renvoie `fees` sous
forme de **tableau**, sans champ net : la somme et la soustraction sont faites
par l'adaptateur.

## 4.4 `poll_count` et `last_polled_at`

Un callback se perd. S'il est la seule source d'information, un client peut
avoir été débité sans que la plateforme le sache — il a payé et n'a pas son
service, la pire défaillance possible pour ce module.

La réconciliation reprend donc toute tentative non terminale et interroge
l'agrégateur, avec un intervalle croissant, jusqu'à `expires_at` de l'intention.

Une tentative dépassée sans réponse ne devient pas `failed` mais `expired` :
*on ne sait pas* n'est pas *cela a échoué*. La différence commande le
rapprochement manuel — et interdit toute bascule.

## 4.5 Statuts

| Statut | Sens | Bascule |
| --- | --- | --- |
| `created` | Enregistrée, agrégateur pas encore appelé | — |
| `rejected` | L'agrégateur a refusé la **demande** | **Oui** |
| `prompted` | L'invite est partie sur le téléphone | Non |
| `processing` | L'agrégateur traite | Non |
| `succeeded` | Encaissé | Non |
| `failed` | Le client a refusé, ou solde insuffisant | Non |
| `expired` | Aucune réponse dans le délai | Non |

`rejected` est le seul statut qui autorise une bascule, et il ne concerne que
les échecs **avant** que le client ne soit sollicité : agrégateur injoignable,
authentification refusée, service en panne, opérateur non couvert.

`failed` n'en autorise aucune : un solde insuffisant chez MTN le reste quel que
soit l'agrégateur qui pose la question.

La règle est encodée **trois fois** — `AttemptStatus::allowsFailover()`,
`PaymentAttempt::allowsFailover()` qui y ajoute `&& ! customer_prompted`, et la
boucle de `InitiatePayment`. La redondance est délibérée : c'est l'endroit du
module où une régression coûte de l'argent réel à un tiers, et une table de
vérité exhaustive la verrouille dans
[`FailoverInvariantTest`](../../../Modules/Billing/Tests/Feature/FailoverInvariantTest.php).

---

# 5. Registre de caisse

```text
payment_transactions

id                     uuid         PK
payment_intent_id      uuid         NULL   -- référence logique
payment_attempt_id     uuid         NULL   -- référence logique
subject_type           varchar(40)  NULL
subject_id             uuid         NULL
payee_organization_id  uuid         NULL
type                   varchar(20)  NOT NULL
amount                 bigint       NOT NULL   -- signé
currency               char(3)      NOT NULL
occurred_at            timestamptz  NOT NULL
description            varchar(255) NULL
metadata               jsonb        DEFAULT '{}'
created_at             timestamptz  NULL
```

**Contraintes**

* `type ∈ { charge, fee, refund }`.
* `amount <> 0`.
* `UNIQUE (payment_intent_id) WHERE type = 'charge' AND payment_intent_id IS NOT NULL`.

**Index** : `(subject_type, subject_id)`, `payment_intent_id`.

| Type | Signe | Origine |
| --- | --- | --- |
| `charge` | positif | Paiement encaissé, montant **brut** |
| `fee` | négatif | Commission de l'agrégateur |
| `refund` | négatif | Remboursement effectif — **déclaré, jamais écrit** |

Un paiement encaissé produit **deux** lignes : le `charge` brut et le `fee`. Le
client a payé 49 663 XAF, la plateforme en a reçu 48 670 — les deux faits sont
vrais, et les confondre rendrait le rapprochement bancaire impossible.

## 5.1 Pas de colonne `updated_at`

Table **append-only**, scellée au niveau du modèle : `booted()` lève sur
`updating` et `deleting`.

Corriger une écriture en la réécrivant efface la trace de l'erreur, et avec elle
toute possibilité d'expliquer un solde. Un remboursement est une nouvelle ligne
de signe opposé.

## 5.2 L'unicité du `charge` est en base, pas dans le code

La protection était applicative : le règlement court-circuite si l'intention est
déjà `succeeded`.

Deux exécutions concurrentes — le sondage et un callback, ou deux des trois
livraisons qu'un agrégateur envoie pour un seul paiement — peuvent lire toutes
deux `processing` et écrire deux lignes `charge`. La facture resterait juste, la
comptabilité non.

## 5.3 Ce que ce registre ne porte pas

Le crédit commercial d'une organisation — proration, avoir, imputation — vit
dans `credit_entries`, côté Billing.

La frontière existait déjà dans le code : `Transaction::creditTypes()` excluait
exactement `charge` et `fee`. La scission ne l'a pas créée, elle l'a rendue
physique, et a supprimé au passage deux clés étrangères qui allaient devenir
inter-modules.

---

# 6. Callbacks entrants

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

`payment_attempt_id` est renseigné après rapprochement. Il reste `null` quand le
callback ne correspond à aucune tentative connue — cas qui doit être
**visible**, car il signale soit une erreur de configuration entre
environnements, soit un callback qui ne nous était pas destiné.

## 6.1 Le corps n'est jamais cru

Le statut est **relu chez l'agrégateur**. Le callback ne fait que déclencher
cette relecture.

Ce n'est pas de la prudence excessive : Tranzak authentifie ses callbacks par un
`authKey` transporté **dans le corps**, ce qui prouve que l'émetteur connaît un
secret mais ne dit rien de l'intégrité du corps. Notch Pay signe en HMAC-SHA256,
ce qui interdit la modification — mais pas le rejeu à l'identique, que l'unicité
`(provider, provider_event_id)` neutralise.

Le corps brut est conservé. Quand un paiement est contesté, c'est la seule pièce
qui dit ce que l'agrégateur a réellement envoyé — pas ce que le code en a
compris.

## 6.2 La clé de déduplication diffère par agrégateur

Pour Tranzak, elle est construite à partir de `eventType` et de l'identifiant de
la **ressource**, délibérément pas de l'identifiant de livraison : Tranzak ne
signant pas, un identifiant de livraison forgé contournerait la déduplication.

Pour Notch Pay, la signature rend l'identifiant de livraison digne de confiance.

## 6.3 Les signatures invalides sont enregistrées

Avec `signature_valid = false`, et non traitées. Les jeter en silence priverait
de toute trace en cas de tentative de fraude.

---

# 7. Ce que le modèle réserve sans implémenter

| Besoin futur | Place déjà prévue |
| --- | --- |
| Rembourser | `type = 'refund'` déclaré au registre |
| Encaisser pour un tiers | `payee_organization_id` sur l'intention et le registre |
| Virement bancaire | `method` |
| Cartes bancaires | `method`, `operator` |

Aucun de ces ajouts ne demande de migration destructrice. **Aucun n'est
construit** : le déclarer n'est pas l'implémenter, et le § 7 de
[01-overview.md](01-overview.md#7-ce-que-ce-module-ne-sait-pas-encore-faire) dit
précisément ce qui manque derrière.

---

# 8. Tâches planifiées

| Commande | Fréquence visée | Rôle |
| --- | --- | --- |
| `payments:reconcile` | Toutes les 5 min | Interroge les agrégateurs pour toute tentative non terminale, et expire les intentions dépassées |

**Aucun planificateur n'est encore enregistré.** La commande existe et
s'exécute à la main ; sa mise au calendrier fait partie de la mise en service,
au même titre que les comptes marchands.

C'est un manque à connaître : sans elle, un callback perdu n'est jamais rattrapé
par personne.
