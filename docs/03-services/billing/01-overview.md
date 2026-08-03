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
* Les agrégateurs de paiement sont détaillés dans [05-providers.md](05-providers.md).
* Le choix du modèle d'abonnement est motivé dans [ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md).

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
* Des paiements Mobile Money, plus le virement bancaire pour les gros comptes.
* Des factures numérotées, avec TVA figée à l'émission.
* Un registre de mouvements **append-only** : rien de ce qui touche à l'argent n'est modifié après coup.
* Des quotas par plan, consommables par les autres modules.

## 4.2 Objectifs techniques

* **Idempotent** : un callback rejoué ne crédite jamais deux fois.
* **Réconcilié par sondage, pas par confiance** : les callbacks Mobile Money se perdent. Le statut réel se lit chez l'opérateur.
* **Neutre vis-à-vis des fournisseurs** : ajouter un agrégateur ne modifie aucun produit.
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
                    │  Billing │
                    └────┬─────┘
                         │
        ┌────────────────┼────────────┬───────────┐
        │                │            │           │
    NotchPay   ──►   Tranzak   ──►   Tara      Virement
        │                │            │       (rapprochement
        └────────────────┴────────────┘          manuel)
                         │
                MTN MoMo · Orange Money
```

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

# 7. Paiement

## 7.1 Le flux Mobile Money

```text
 1. Intention      l'organisation demande à payer une facture
 2. Demande        Billing appelle l'opérateur (request-to-pay)
 3. Invite         le client reçoit une invite sur son téléphone
 4. Saisie         le client saisit son code secret
 5. Callback       l'opérateur notifie Billing              ← peut se perdre
 6. Sondage        Billing interroge l'opérateur            ← filet de sécurité
 7. Constat        succès ou échec, écrit une seule fois
```

Les étapes 5 et 6 sont **redondantes à dessein**. Un callback Mobile Money peut se perdre, arriver deux fois, ou arriver dans le désordre. S'appuyer sur lui seul produit des paiements encaissés par l'opérateur mais jamais constatés par la plateforme — le client a payé, et n'a pas son accès.

Le sondage est donc obligatoire, pas optionnel : toute intention de paiement laissée en attente est réinterrogée jusqu'à ce que l'opérateur tranche, ou jusqu'à expiration.

## 7.2 Le client ne peut pas payer un montant arbitraire

Le montant vient toujours de la facture, jamais du corps de la requête. Accepter un montant fourni par l'appelant permettrait de régler une facture de 50 000 XAF avec 100 XAF.

## 7.3 Agrégateurs

Les paiements passent par des **agrégateurs**, pas par des comptes marchands opérateur en direct : **NotchPay**, **Tranzak**, **Tara**, dans cet ordre de priorité.

Chacun couvre MTN MoMo et Orange Money derrière une seule intégration. Le choix de l'agrégateur est une décision de la plateforme ; le réseau du payeur, lui, est un fait déduit du préfixe du numéro.

```text
   msisdn  ──►  opérateur (fait)  ──►  agrégateurs qui le couvrent (choix)
+237 65…         MTN                    NotchPay  ─►  Tranzak  ─►  Tara
+237 69…         Orange                 NotchPay  ─►  Tranzak  ─►  Tara
```

Comme dans Notify, l'ordre vaut priorité, et **un agrégateur non configuré n'est jamais essayé**. C'est ce qui permet de développer sans compte marchand : aucun paiement ne part, et le module le dit franchement plutôt que d'échouer à l'exécution.

## 7.4 La bascule n'obéit pas aux mêmes règles que dans Notify

C'est le point le plus important du module, et l'intuition venue de Notify y est dangereuse.

Dans Notify, rejouer un email chez un autre fournisseur ne coûte rien de grave : au pire le destinataire reçoit deux fois le même message. Ici, rejouer une demande de paiement peut produire **deux débits sur le compte du client**.

La règle est donc unique et étroite :

> **On ne bascule que si l'invite n'est jamais partie sur le téléphone du client.**

| Situation | Bascule |
| --- | --- |
| L'agrégateur est injoignable, en panne, ou refuse la demande | **Oui** |
| L'agrégateur ne couvre pas cet opérateur | **Oui** |
| L'invite est partie, le client n'a pas encore répondu | Non |
| Le client a refusé, ou son solde est insuffisant | Non |
| **On ignore si l'invite est partie** | **Non** |

Les deux dernières lignes méritent d'être lues ensemble.

Un solde insuffisant chez MTN le reste quel que soit l'agrégateur qui pose la question — c'est un rejet **métier**, et la règle de Notify s'applique à l'identique : il ne réussira pas davantage ailleurs, et chaque tentative coûte.

L'incertitude, elle, est traitée comme un « oui, l'invite est partie ». Ne pas encaisser est un incident réparable ; encaisser deux fois est une faute que le client découvre sur son relevé, et qu'un remboursement Mobile Money rend pénible à corriger.

C'est pourquoi le modèle sépare une **intention** de paiement de ses **tentatives** : une facture n'est payée qu'une fois, même si trois agrégateurs ont été sollicités.

Le raisonnement complet est dans [ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md).

## 7.5 La commission n'est pas invisible

Un agrégateur prélève sa part. Le client paie 49 663 XAF, la plateforme en reçoit 48 670.

La facture est réglée sur le montant **brut** — le client a payé son dû. La commission est enregistrée séparément au registre, comme une charge de la plateforme.

Confondre les deux laisserait la facture éternellement impayée à hauteur de la commission, et l'abonnement jamais activé.

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

`transactions` est **append-only**. Un remboursement est une nouvelle ligne de signe opposé, pas une modification de la ligne d'origine. Un avoir est une nouvelle ligne.

Corriger une écriture en la réécrivant efface la trace de l'erreur — et avec elle, toute possibilité d'expliquer un solde.

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

C'est ce qui remplace le plafond de dépense global aujourd'hui codé dans Notify, noté comme dette dans le [RECAP](../../RECAP.md).

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

* **Un compte marchand chez Tranzak et Notch Pay au minimum.** L'obtention est administrative et **longue** ; les deux doivent être engagées en parallèle, sinon la bascule n'existe que sur le papier. Deux agrégateurs suffisent à supprimer le point de défaillance unique — le troisième améliore, il ne conditionne pas.
* **La documentation technique de Tara**, à demander directement : elle n'est pas publique. Son adaptateur ne peut pas être spécifié en son absence.
* Un environnement bac à sable, sans quoi aucun test n'est réel. Seul Tranzak en documente un aujourd'hui.
* Un compte bancaire pour le reversement et le rapprochement des virements.

Ce que la documentation publique confirme, et ce qui reste à vérifier agrégateur par agrégateur, est consigné dans [05-providers.md](05-providers.md).

Un point mérite d'être connu avant de commencer : **aucun agrégateur n'expose de champ disant « le client a reçu l'invite »**. C'est pourtant la donnée dont dépend la règle de bascule. Elle doit être déduite de l'issue de l'appel de débit, et c'est l'endroit du module où une approximation coûte de l'argent réel à un tiers.

Tant que rien de tout cela n'existe, Billing peut être développé et testé de bout en bout avec un agrégateur factice — mais **aucun paiement n'aura été prouvé**. C'est exactement la situation du canal SMS de Notify, dont la passerelle n'a jamais été configurée.
