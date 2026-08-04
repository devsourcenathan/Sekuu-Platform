# Sekuu Billing — Vision & Périmètre

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Projet :** Sekuu Ecosystem
> **Composant :** Sekuu Billing Service
> **Dernière mise à jour :** Août 2026

Ce document décrit **le rôle et les frontières** de Sekuu Billing.

* Le modèle de données fait autorité dans [02-data-model.md](02-data-model.md).
* L'API fait autorité dans [03-api.md](03-api.md).
* Les événements font autorité dans [04-events.md](04-events.md).
* Le choix du modèle d'abonnement est motivé dans [ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md).

> **L'encaissement n'est plus dans ce module.** Agrégateurs, intentions,
> tentatives, callbacks et registre de caisse appartiennent à
> [Payments](../payments/01-overview.md). Billing décide **combien** et
> **pourquoi** ; Payments encaisse, sans savoir ce qu'il encaisse.

---

# 1. Contexte

Identity porte déjà la table `organization_products`, qui répond à une seule question :

> Cette organisation peut-elle utiliser ce produit, aujourd'hui ?

Cette table existe, elle est lue à chaque requête, et **rien ne l'alimente**. Les droits d'accès d'une organisation se modifient aujourd'hui à la main, en base. Son champ `subscription_id` pointe vers un module qui n'existe pas.

[La documentation d'Identity](../identity/02-data-model.md) est déjà explicite sur le partage des rôles : `organization_products` est un *cache de droits dérivé*, jamais une source de vérité financière, et **en cas de désaccord, Billing fait foi**. Le contrat est écrit ; il manque l'émetteur.

Par ailleurs, Notify porte une rustine qui appartient ici : son plafond de dépense est global, identique pour toutes les organisations. Un plafond par plan est une décision de facturation, pas de notification.

---

# 2. Vision

Billing est la **seule source de vérité** sur ce qu'une organisation a payé, et jusqu'à quand.

Aucun autre module ne décide de l'accès à un produit. Billing constate un paiement, en déduit une période de droits, et publie l'événement correspondant. Identity applique.

```text
Billing constate  ──►  événement  ──►  Identity applique  ──►  le produit s'ouvre
```

Un produit — ClinicFlow, DealerOS — ne connaît ni plan, ni facture, ni fournisseur de paiement. Il demande à Identity si l'accès est ouvert, et rien de plus.

---

# 3. Le fait qui structure tout le module

**Au Cameroun, on paie par Mobile Money, et un paiement Mobile Money ne peut pas être déclenché sans l'utilisateur.**

MTN MoMo et Orange Money fonctionnent en *request-to-pay* : la plateforme demande un paiement, l'opérateur envoie une invite sur le téléphone du client, le client saisit son code, et un callback confirme. Le client est **présent, et actif**.

Il n'y a pas de carte enregistrée qu'on débite en silence à l'échéance.

Cela invalide le modèle d'abonnement que tout le monde a en tête — celui de Stripe, où le renouvellement est un effet de bord invisible du temps qui passe. Ici :

| Modèle « carte » | Réalité Mobile Money |
| --- | --- |
| Le renouvellement est automatique | Le renouvellement est un **acte volontaire** |
| L'échec de paiement est une exception | L'absence de paiement est le cas **normal** à l'échéance |
| On relance en débitant à nouveau | On relance en **prévenant**, et on attend |
| Le remboursement est trivial | Le remboursement est lent, coûteux, parfois manuel |

Billing est donc conçu autour d'un **droit d'accès prépayé et daté**, pas d'un contrat à reconduction tacite. Un abonnement n'est pas une promesse de payer : c'est une fenêtre déjà payée.

Conséquence directe sur la conception : la fin d'une période n'est **pas** un incident. C'est un événement attendu, annoncé à l'avance, suivi d'une période de grâce, puis d'une suspension — jamais d'une suppression.

Le détail du raisonnement et les alternatives écartées sont dans [ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md).

---

# 4. Objectifs

## 4.1 Objectifs fonctionnels

* Un catalogue de plans, versionnés, avec plusieurs tarifs par plan (mensuel, annuel).
* Des abonnements d'organisation, avec période d'essai, grâce et suspension.
* Des factures numérotées, avec TVA figée à l'émission.
* Un registre de mouvements **append-only** : rien de ce qui touche à l'argent n'est modifié après coup.
* Des quotas par plan, consommables par les autres modules.

## 4.2 Objectifs techniques

* **Idempotent** : un règlement rejoué ne crédite jamais deux fois.
* **Ignorant du moyen de paiement** : ajouter un agrégateur ne modifie aucune ligne de Billing.
* **Auditable** : de tout solde, on doit pouvoir remonter à la ligne de registre qui l'a produit.

## 4.3 Ce que Billing ne fait pas

| Hors périmètre | Responsable |
| --- | --- |
| Autoriser une requête au moment où elle arrive | **Identity** (`organization_products`) |
| Connaître les utilisateurs et les organisations | **Identity** |
| Envoyer les rappels d'échéance et les reçus | **Notify** |
| Stocker les PDF de facture | **Storage** |
| Comptabilité, liasse fiscale, déclaration de TVA | Hors plateforme — Billing fournit l'export |
| Vérifier l'identité du payeur | **Verify** |
| Encaisser, choisir un agrégateur, recevoir les callbacks | **Payments** |

Billing ne bloque jamais une requête produit. Il publie un fait ; le blocage est appliqué par Identity, en un seul endroit, sur une table déjà lue à chaque appel. Dupliquer ce contrôle créerait deux vérités.

---

# 5. Architecture

```text
   ClinicFlow   DealerOS   Tontines        (les produits)
        │           │          │
        └───────────┴────┬─────┘
                         │  « ai-je accès ? »
                    ┌────▼─────┐
                    │ Identity │  organization_products
                    └────▲─────┘
                         │  événements d'abonnement
                    ┌────┴─────┐
                    │  Billing │   plans, abonnements, factures
                    └────┬─────┘
                         │  « ce que vaut cette facture »
                    ┌────▼─────┐
                    │ Payments │   agrégateurs, invites, encaissement
                    └────┬─────┘
                         │
                MTN MoMo · Orange Money
```

Billing ne parle à **aucun** agrégateur. Il implémente une interface —
`PayableSource` — par laquelle Payments lui demande un prix, puis lui remet
l'issue. Une facture d'abonnement et une inscription à une formation empruntent
ainsi exactement le même chemin.

Le sens des flèches est le point important : Billing **pousse** vers Identity. Identity n'interroge jamais Billing pour autoriser une requête — ce serait un appel synchrone sur le chemin critique de chaque appel API, vers un module qui peut être indisponible.

---

# 6. Cycle de vie d'un abonnement

```text
                  souscription
                       │
              ┌────────▼────────┐
     essai ──►│     active      │◄──── renouvellement payé
              └────────┬────────┘
                       │ fin de période sans paiement
              ┌────────▼────────┐
              │      grace      │  accès maintenu, 7 jours
              └────────┬────────┘
                       │ grâce écoulée
              ┌────────▼────────┐
              │    suspended    │  accès fermé, données conservées
              └────────┬────────┘
                       │ 90 jours
                    expired        (les données relèvent de la rétention produit)
```

| État | Accès au produit | Ce qu'il signifie |
| --- | --- | --- |
| `trialing` | Ouvert | Essai en cours, aucun paiement encore |
| `active` | Ouvert | Période payée en cours |
| `grace` | **Ouvert** | Période échue, on laisse le temps de payer |
| `suspended` | Fermé | Non payé après la grâce, ou annulé |
| `cancelled` | Ouvert jusqu'au terme | Résiliation demandée, période en cours honorée |
| `expired` | Fermé | Suspendu depuis longtemps |

## 6.1 Pourquoi une période de grâce

Sans elle, un abonnement expire à minuit, et l'organisation découvre le lendemain matin qu'elle ne peut plus ouvrir son logiciel de gestion de clinique.

Avec un paiement automatique par carte, c'est acceptable : l'échec est rare et signale un vrai problème. Avec Mobile Money, l'échéance tombe alors que le client n'a **rien** à faire d'automatique — il doit y penser, avoir du crédit sur son compte, et être joignable. Couper sèchement transformerait un oubli d'une journée en interruption d'activité.

Sept jours de grâce coûtent sept jours de service non payé. Une clinique qui ne peut pas ouvrir son agenda un lundi matin coûte le client.

## 6.2 Suspension et non suppression

Un abonnement suspendu ferme l'accès ; il ne détruit rien. Les données appartiennent au client, pas au contrat. Leur suppression relève de la politique de rétention de chaque produit, et d'une décision explicite — jamais d'un défaut de paiement.

---

# 7. Ce que Billing attend du paiement

Le mécanisme d'encaissement — agrégateurs, invites, bascule, commission — est
décrit dans [Payments](../payments/01-overview.md) et décidé dans
[ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md).

Trois choses seulement en découlent ici, et elles structurent tout le module.

## 7.1 Le client ne peut pas payer un montant arbitraire

Le montant vient toujours de la facture, jamais du corps de la requête. Accepter
un montant fourni par l'appelant permettrait de régler une facture de 50 000 XAF
avec 100 XAF.

Ce n'est pas une règle de bonne conduite : `InitiatePayment` n'a **aucun
paramètre** pour passer un montant. Billing le produit en réponse à une question
que Payments lui pose — `InvoicePayable::quote()` — et cette méthode porte aussi
l'autorisation : ce payeur a-t-il le droit de régler cette facture ?

## 7.2 Le règlement est synchrone, pas événementiel

Payments appelle `InvoicePayable::settled()` **dans la transaction
d'encaissement**. Il n'existe aucun instant où l'argent est encaissé et la
facture impayée.

Confier ce moment à une file créerait une fenêtre où le client a payé et n'a pas
son accès, qu'un consommateur en échec définitif rendrait permanente. C'est une
exception assumée à
[l'architecture § 11.1](../../01-overview/architecture.md), et la seule du
module.

## 7.3 La commission n'est pas invisible

Un agrégateur prélève sa part. Le client paie 49 663 XAF, la plateforme en
reçoit 48 670.

**La facture est réglée sur le montant brut** — le client a payé son dû. La
commission est enregistrée séparément, au registre de caisse de Payments, comme
une charge de la plateforme.

Confondre les deux laisserait la facture éternellement impayée à hauteur de la
commission, et l'abonnement jamais activé.

## 7.4 Un paiement échoué ne se relance pas tout seul

La règle de bascule entre agrégateurs est **volontairement étroite** : on
n'essaie ailleurs que si l'invite n'est jamais partie sur le téléphone du
client, et l'incertitude compte comme « partie ».

Billing ne doit donc jamais réessayer un paiement de sa propre initiative. Un
nouveau paiement est une **action du client** — ce qui est cohérent avec le
modèle prépayé du § 3 : à l'échéance, on relance en prévenant, pas en débitant.

---

# 8. Argent

## 8.1 Entiers, jamais de flottants

Tous les montants sont des **entiers**, dans l'unité la plus petite de la devise. Un flottant introduit des erreurs d'arrondi qui, sur un registre, deviennent des écarts irréconciliables.

## 8.2 Le franc CFA n'a pas de centime

C'est un piège classique : les bibliothèques de paiement supposent presque toutes deux décimales, et stockent « 1000 » pour 10,00 €.

Le XAF est une devise **à zéro décimale**. 1 000 XAF se stocke `1000`, pas `100000`. Appliquer le réflexe « ×100 » multiplierait tous les montants par cent — une erreur qui ne se voit pas en développement, où les montants sont inventés.

La devise porte donc son exposant explicitement, et aucune conversion n'est implicite.

## 8.3 TVA

Les prix sont stockés **hors taxes**. Le taux est appliqué à l'émission de la facture et **figé** sur celle-ci.

Une facture est un document légal : elle doit rester lisible telle qu'émise, même si le taux change ensuite. Recalculer une facture passée à partir du taux courant produirait un document qui ne correspond plus à ce qui a été payé.

Au Cameroun, le taux applicable est de 19,25 % (TVA 18 % + centimes additionnels communaux). Il est configurable par pays : la plateforme vise d'autres marchés.

## 8.4 Le registre ne se modifie pas

`credit_entries` est **append-only**, et scellé au niveau du modèle. Un avoir est
une nouvelle ligne ; une imputation est une nouvelle ligne de signe opposé.

Corriger une écriture en la réécrivant efface la trace de l'erreur — et avec
elle, toute possibilité d'expliquer un solde.

C'est une propriété du **registre**, pas de ce module : elle vaut à l'identique
pour le registre de caisse de Payments, et y est répliquée.

---

# 9. Changement de plan

## 9.1 Montée en gamme

Le crédit restant sur la période en cours est **imputé** sur le nouveau prix ; le client paie la différence.

```text
Plan actuel   10 000 XAF/mois, 12 jours restants   →  crédit  4 000 XAF
Nouveau plan  25 000 XAF/mois                      →  dû     21 000 XAF
```

## 9.2 Descente en gamme

Elle prend effet **à la fin de la période en cours**, jamais immédiatement. La période est déjà payée ; l'écourter reviendrait à devoir rembourser.

Elle est refusée (`DOWNGRADE_NOT_ALLOWED`) si l'usage courant dépasse les limites du plan visé — trois workspaces actifs vers un plan qui en autorise un seul. Accepter puis appliquer silencieusement détruirait des données du client.

## 9.3 Jamais de remboursement en espèces

Un remboursement Mobile Money est lent, coûteux, et souvent manuel. Tout trop-perçu devient un **crédit** sur le compte de l'organisation, imputé au prochain paiement.

Le remboursement réel existe, mais comme geste commercial explicite, décidé par un humain — pas comme mécanique automatique du module.

---

# 10. Quotas

Un plan ouvre des produits, et fixe des limites : nombre de membres, de workspaces, volume de SMS, crédits IA, stockage.

Billing **publie** ces limites ; il ne les fait pas respecter. Chaque module contrôle son propre quota, parce que lui seul sait le compter. Notify sait combien de SMS il a envoyés ; Billing ne le saura jamais mieux que lui.

La lecture passe par le contrat `BillingContract::limit()`, symétrique de celui d'Identity. Le comptage et le refus appartiennent à l'appelant.

## 10.1 Une limite a trois états, pas deux

| État | Sens |
| --- | --- |
| Plafonnée | une valeur |
| **Illimitée** | le plan couvre la ressource sans borne |
| **Non couverte** | le plan n'ouvre pas cette ressource |

Un simple `?int` confondrait les deux derniers, et « illimité » se lirait comme « interdit » — ou l'inverse, ce qui serait pire : un client qui a payé pour ne pas être borné se retrouverait bloqué.

## 10.2 Un quota n'est pas un contrôle d'accès

Une organisation **sans abonnement** n'est pas bloquée par les quotas.

Le faire dupliquerait le rôle d'`organization_products` côté Identity — et surtout, cela fermerait toute organisation créée avant qu'un abonnement n'existe, y compris pendant l'inscription.

Un quota borne un usage **autorisé**. Il ne décide pas de l'autorisation.

## 10.3 Ce qui est appliqué aujourd'hui

| Ressource | Module | Comptage |
| --- | --- | --- |
| `members` | Identity | Membres actifs **+ invitations en attente** |
| `workspaces` | Identity | Workspaces non supprimés |
| `sms_monthly` | Notify | Messages **acceptés** dans le mois |

Les invitations en attente consomment un siège : ne compter que les membres laisserait envoyer cent invitations sur un plan de trois, et le dépassement ne serait constaté qu'à l'acceptation — une fois la promesse faite à l'invité.

Notify compte les messages acceptés et non les livraisons : compter les secondes laisserait un envoi groupé franchir le quota avant qu'aucun message n'ait abouti.

## 10.4 Le plafond de dépense n'est pas remplacé

Le plafond global de Notify était un **substitut** aux quotas par plan, faute de Billing. Maintenant qu'ils existent, il redevient ce qu'il aurait dû être d'emblée : un garde-fou absolu contre une boucle ou une clé d'API fuitée, indépendant du plan.

| | Quota de plan | Plafond de dépense |
| --- | --- | --- |
| Mesure | un **volume** | une **dépense** |
| Source | le plan de l'organisation | la configuration de la plateforme |
| Rôle | limite **commerciale** | garde-fou contre l'emballement |

Les deux coexistent. Supprimer le second laisserait une organisation au plan illimité sans aucune borne — et c'est précisément celle qui peut coûter le plus cher.

---

# 11. Ce qui reste hors de la version 1

| Écarté | Pourquoi |
| --- | --- |
| Facturation à l'usage (*metered*) | Suppose un flux de consommation fiable venant de chaque module ; à faire après Analytics |
| Multi-devise réelle | Le marché visé est XAF ; le modèle porte la devise, mais un seul taux de change ne s'invente pas |
| Cartes bancaires | Marginales sur le marché visé, et coûteuses à intégrer correctement (3-D Secure, litiges) |
| Codes promotionnels | Utile commercialement, sans effet structurant — s'ajoute sans rien casser |
| Paiement fractionné | Complexifie le rapprochement pour un besoin non exprimé |

Le modèle de données réserve la place des trois premiers sans les implémenter.

---

# 12. Prérequis de mise en service

Ce qui relève de l'encaissement — comptes marchands, bacs à sable, documentation
de Tara — est listé dans [Payments](../payments/05-providers.md). Aucun de ces
prérequis n'est satisfait aujourd'hui en production.

Propre à Billing :

* **Un ordonnanceur qui exécute réellement `schedule:run`.** La tâche
  `billing:advance` est déclarée et visible dans `schedule:list` ; encore
  faut-il qu'une crontab appelle Laravel toutes les minutes en production. Sur
  un modèle prépayé, sans cela, aucun rappel d'échéance ne part et aucun
  abonnement échu ne passe en grâce.
* **Storage**, pour les PDF de facture. En son absence,
  `GET /invoices/{id}/download` renvoie `503` — franchement, plutôt qu'un PDF
  généré à la volée dont personne ne garantit qu'il sera identique demain.
* **Un compte bancaire** pour le reversement et le rapprochement des virements.
* **Le taux de TVA validé** par un comptable camerounais. 19,25 % est appliqué
  et figé sur chaque facture émise ; se tromper obligerait à annuler et rééditer
  des documents légaux.
