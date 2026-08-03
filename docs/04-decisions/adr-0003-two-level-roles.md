# ADR-0003 — Rôles à deux niveaux : plateforme et métier

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Un même utilisateur intervient dans plusieurs produits avec des responsabilités différentes :

```text
Nathan  →  Sekuu Identity  →  owner
Nathan  →  ClinicFlow      →  médecin
Nathan  →  DealerOS        →  administrateur
Nathan  →  Sekuu Learn     →  formateur
```

Deux options s'opposaient : centraliser toutes les permissions dans Identity, ou les laisser à chaque produit.

Centraliser signifierait qu'ajouter une permission métier dans ClinicFlow impose une migration et un déploiement d'Identity.

## Décision

Les rôles sont séparés en **deux niveaux étanches**.

**Niveau 1 — rôles plateforme**, gérés par Identity :

```text
owner, admin, billing_manager, member
```

Ils gouvernent la gestion de l'organisation, des membres, des workspaces, de l'abonnement et de l'accès aux produits. Ils sont identiques pour tous les produits et portés par le claim `roles` du JWT.

**Niveau 2 — rôles métier**, gérés par chaque produit :

```text
ClinicFlow  →  doctor, nurse, receptionist
DealerOS    →  sales_agent, dealer_manager, warehouse_manager
Stock       →  stock_manager, inventory_agent
```

Ils gouvernent les actions métier. Identity **ne les connaît pas** et ne les stocke pas.

Frontière : Identity répond à *« qui est-ce, dans quelle organisation, et a-t-il accès à ce produit ? »*. Le produit répond à *« que peut-il faire ici ? »*.

## Conséquences

**Positives**

* Un nouveau produit peut être lancé sans aucune modification d'Identity.
* Un produit fait évoluer son modèle de permissions à son propre rythme.
* Le JWT reste petit : il ne transporte que les scopes globaux.
* Un produit peut être vendu ou opéré séparément.

**Négatives**

* Il n'existe pas de vue unique « toutes les permissions de Nathan » — il faut interroger chaque produit.
* Chaque produit doit implémenter son propre système de rôles ; le SDK doit donc en fournir une base commune pour éviter huit implémentations divergentes.
* Le rattachement d'un rôle métier à un membership doit être maintenu de part et d'autre lorsqu'un membre est retiré de l'organisation.

**Mitigation.** Identity publie `MembershipRemoved` ; chaque produit est tenu de révoquer les rôles métier associés à sa réception.

## Alternatives écartées

* **Toutes les permissions dans Identity** — couplage fort, Identity devient un goulot d'étranglement pour toute évolution produit.
* **Tous les rôles dans les produits** — chaque produit devrait reconstruire la gestion des organisations et des membres.
* **Permissions métier dans le JWT** — token volumineux, et toute modification de droits exigerait une réémission immédiate.
