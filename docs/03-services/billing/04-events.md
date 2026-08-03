# Sekuu Billing — Contrat d'événements

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Billing est le module le plus **émetteur** de la plateforme : c'est par ses événements que l'accès aux produits s'ouvre et se ferme.

Documents liés : [Vision](01-overview.md) · [Modèle de données](02-data-model.md) · [Architecture § 11](../../01-overview/architecture.md) · [Notify — événements](../notify/04-events.md)

---

# 1. Principe

```text
Billing  ──publie──►  billing.subscription.activated  ──►  Identity  ──►  organization_products
                                                      └──►  Notify    ──►  email de confirmation
```

Billing ne modifie **jamais** `organization_products` directement. Il publie un fait ; Identity applique.

Cette indirection paraît coûteuse tant que les deux modules partagent la même base. Elle est ce qui rend l'extraction possible : le jour où Billing devient un service distinct, seul le transport change.

Le format et les règles générales sont ceux du [contrat d'événements de Notify](../notify/04-events.md#11-format) — `{module}.{ressource}.{action}`, `request_id` propagé, livraison au moins une fois.

## 1.1 Ce qui ne doit jamais transiter

| Interdit dans `data` | Pourquoi |
| --- | --- |
| Numéro Mobile Money complet | Donnée personnelle, sans usage pour le consommateur |
| Référence de transaction chez l'agrégateur | Sert au rapprochement, pas à la diffusion |
| Nom de l'agrégateur | Détail d'exploitation ; l'exposer figerait l'ordre de priorité |
| Montant net et commission | Économie de la plateforme, pas information client |
| Coordonnées bancaires | Aucun usage légitime |

Un numéro de payeur peut apparaître **tronqué** (`+237 65 •• •• 00`) lorsqu'un message doit le rappeler au client. Jamais entier.

---

# 2. Événements émis

## 2.1 Abonnement

| Événement | Quand | Consommé par |
| --- | --- | --- |
| `billing.subscription.created` | Souscription enregistrée, avant paiement | Notify |
| `billing.subscription.activated` | Période payée, accès ouvert | **Identity**, Notify |
| `billing.subscription.renewed` | Nouvelle période payée | Identity, Notify |
| `billing.subscription.changed` | Plan modifié, effet immédiat ou différé | Identity, Notify |
| `billing.subscription.expiring` | J-7, J-3, J-1 avant le terme | Notify |
| `billing.subscription.grace_started` | Terme atteint sans paiement | Notify |
| `billing.subscription.suspended` | Grâce écoulée, accès fermé | **Identity**, Notify |
| `billing.subscription.cancelled` | Résiliation demandée | Notify |
| `billing.subscription.expired` | Suspendu depuis 90 jours | Identity |

Trois seulement modifient l'accès : `activated`, `changed` et `suspended` — plus `expired`, qui ne fait que confirmer une fermeture déjà en vigueur. Les autres sont informatifs.

```json
{
  "id": "evt_4c81ba90",
  "type": "billing.subscription.activated",
  "occurred_at": "2026-08-03T09:17:04Z",
  "request_id": "req_8b94d7d0",
  "organization_id": "…",
  "data": {
    "subscription_id": "…",
    "plan_key": "clinic-pro",
    "plan_name": "Clinic Pro",
    "products": ["clinicflow", "stock"],
    "limits": { "members": 25, "workspaces": 5, "storage_gb": 50, "sms_monthly": 500 },
    "current_period_start": "2026-08-03T00:00:00Z",
    "current_period_end": "2026-09-03T00:00:00Z"
  }
}
```

`products` et `limits` sont **portés par l'événement**, pas rechargés par le consommateur. Identity n'a ainsi jamais besoin de lire une table de Billing, et l'événement reste explicable des mois plus tard : il dit ce qui a été accordé, pas ce que le plan contient aujourd'hui.

C'est la même règle que dans Notify, où les événements transportent les données utiles plutôt que de simples identifiants à recharger.

## 2.2 Paiement et facturation

| Événement | Quand | Consommé par |
| --- | --- | --- |
| `billing.invoice.issued` | Facture émise | Notify |
| `billing.invoice.paid` | Facture réglée | Notify |
| `billing.invoice.overdue` | Échéance dépassée | Notify |
| `billing.payment.succeeded` | Paiement encaissé | Notify, Analytics |
| `billing.payment.failed` | Paiement refusé | Notify |
| `billing.payment.unresolved` | Issue inconnue après expiration | **Exploitation** |

`billing.payment.unresolved` n'a pas de destinataire produit : il alerte l'équipe. Il signale une intention pour laquelle l'agrégateur n'a jamais tranché — le client a peut-être été débité sans que la plateforme le sache. C'est le seul cas qui exige une intervention humaine, et le taire serait laisser un client payé sans service.

Il n'existe **pas** d'événement de bascule d'agrégateur. Passer de NotchPay à Tranzak est un détail d'exécution interne, pas un fait métier ; le publier exposerait l'ordre de priorité à des consommateurs qui n'en ont aucun usage. Ces bascules se lisent dans `payment_attempts` et dans les journaux.

---

# 3. Événements consommés

| Événement | Émis par | Effet dans Billing |
| --- | --- | --- |
| `identity.organization.created` | Identity | Crée un abonnement d'essai si un plan par défaut est configuré |
| `identity.organization.deleted` | Identity | Résilie l'abonnement au terme, conserve les factures |

## 3.1 Pourquoi les factures survivent à l'organisation

Une facture est un document légal, soumis à une obligation de conservation. Supprimer une organisation ne peut pas effacer ce qu'elle a payé.

`invoices.organization_id` est une référence **logique**, sans clé étrangère : la suppression en cascade est donc impossible par construction, et non seulement déconseillée.

## 3.2 Ce que Billing ne consomme pas

Aucun événement d'usage. Facturer à la consommation supposerait un flux fiable venant de chaque module — c'est un chantier qui vient après Analytics, et le modèle de données lui réserve la place sans l'ouvrir.

---

# 4. Correspondance avec les templates Notify

Ces templates **existent** dans Notify, traduits fr/en.

| Événement | Template | Canal | Catégorie |
| --- | --- | --- | --- |
| `billing.subscription.activated` | `subscription.activated` | email | transactional |
| `billing.subscription.expiring` | `subscription.expiring` | email + SMS | transactional |
| `billing.subscription.grace_started` | `subscription.grace` | email + SMS | transactional |
| `billing.subscription.suspended` | `subscription.suspended` | email | transactional |
| `billing.invoice.issued` | `invoice.issued` | email | transactional |
| `billing.invoice.paid` | `invoice.paid` | email | transactional |
| `billing.payment.failed` | `payment.failed` | email + SMS | transactional |

## 4.1 Pourquoi tout est transactionnel

Aucun de ces messages n'est désactivable, et ce n'est pas un abus de la catégorie.

Un client qui a coupé les notifications de facturation et découvre son accès fermé un lundi matin n'a pas exercé un choix : il a perdu l'information dont il avait besoin pour agir. La facture elle-même figure d'ailleurs explicitement parmi les exemples transactionnels de l'[ADR-0006](../../04-decisions/adr-0006-transactional-vs-marketing.md).

## 4.2 Pourquoi le SMS sur les échéances

C'est l'un des rares cas où le coût du SMS se justifie sans discussion.

L'échéance est le moment où le modèle prépayé exige une **action du client**. Un email non lu produit une suspension évitable ; sur le marché visé, le SMS est lu. C'est aussi le canal du téléphone qui recevra l'invite Mobile Money — le rappel arrive là où le paiement se fera.

Le SMS n'est pas envoyé aux trois rappels, seulement à J-1, au démarrage de la grâce, et sur un paiement échoué. Trois SMS par mois et par organisation coûteraient plus cher que le service qu'ils protègent.

La suspension, elle, n'a **pas** de SMS : l'accès est déjà fermé, et le SMS sert à faire agir avant, pas à constater après.

Techniquement, cette limitation ne duplique aucun template. Billing n'inclut le numéro dans l'événement que lorsque le SMS se justifie ; un canal sans coordonnée est simplement ignoré par Notify.

## 4.3 Comment le destinataire est résolu

Les événements de Billing ne portaient au départ aucune coordonnée : Billing ne connaît ni utilisateurs ni adresses.

Il les obtient d'Identity par son **contrat public** `IdentityContract::billingContact()` — jamais en lisant sa table. C'est le premier usage de la couche partagée décrite par [l'architecture § 11.1](../../01-overview/architecture.md), et le cas exact pour lequel elle était prévue : une lecture synchrone dont l'appelant a besoin immédiatement.

Le contact est ensuite **porté par l'événement**. Notify reste donc ignorant d'Identity, et l'événement dit à qui l'on a écrit — pas qui serait destinataire aujourd'hui.

Le contact est le **propriétaire le plus ancien** de l'organisation. Un champ dédié serait meilleur — la personne qui administre n'est pas toujours celle qui paie — mais le propriétaire est le seul destinataire dont l'existence est garantie, puisqu'une organisation en conserve toujours au moins un.

Une organisation sans contact joignable est **journalisée en avertissement**. Sur un modèle prépayé, un client qu'on ne peut pas prévenir est un client qu'on va perdre sans jamais savoir pourquoi.

---

# 5. Ordre et concurrence

Aucun ordre n'est garanti. `billing.payment.succeeded` peut être traité avant `billing.invoice.issued`.

Deux conséquences :

**Chaque événement est autonome.** `activated` porte les produits et les limites ; un consommateur n'a jamais à supposer qu'un autre événement est déjà arrivé.

**Les consommateurs sont idempotents.** Identity applique un état cible — « ces produits, actifs jusqu'à cette date » — et non un delta. Rejouer `activated` trois fois donne le même résultat qu'une fois.

Appliquer des deltas serait fragile pour une raison simple : le même événement peut être livré plusieurs fois, et un delta appliqué deux fois est un bug silencieux.

---

# 6. Réconciliation

En cas de désaccord entre `organization_products` et les abonnements, **Billing fait foi** — la règle est déjà posée par [le modèle de données d'Identity](../identity/02-data-model.md).

Une commande `billing:sync-access` reconstruit `organization_products` à partir des abonnements. Elle existe parce qu'un événement peut se perdre : queue vidée, incident, bug de consommateur. Sans elle, l'écart n'a aucun moyen d'être corrigé autrement qu'à la main.

Elle ne touche **jamais** les lignes dont `source = 'manual'`. Ce sont des activations commerciales accordées hors abonnement ; les révoquer au motif qu'aucun abonnement ne les justifie retirerait un accès délibérément offert par un humain.

---

# 7. Transport

Comme pour Notify, les événements passent par les queues Laravel (Redis) au démarrage.

Le contrat est indépendant du transport. Le jour où Billing est extrait, un bus de messages remplace la queue locale, et aucun émetteur ni consommateur n'est modifié.
