# ADR-0007 — Abonnements prépayés plutôt que reconduction automatique

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

Le modèle d'abonnement que tout le monde a en tête est celui de Stripe : une carte est enregistrée, elle est débitée à chaque échéance, et le renouvellement est un effet de bord invisible du temps qui passe. L'échec de paiement y est une **exception**, traitée par une séquence de relances qui consiste à re-débiter la carte.

Ce modèle repose sur une hypothèse : la plateforme peut prélever de l'argent sans que le client fasse quoi que ce soit.

Sur le marché visé — le Cameroun, puis l'Afrique centrale — cette hypothèse est fausse.

Le paiement passe par Mobile Money (MTN MoMo, Orange Money). Le mécanisme est le *request-to-pay* : la plateforme demande un paiement, l'opérateur envoie une invite sur le téléphone du client, et le client **saisit son code secret**. Il n'y a pas d'instrument de paiement conservé côté marchand qu'on puisse débiter en silence.

La carte bancaire existe, mais elle est marginale sur ce marché, et l'exiger reviendrait à exclure la majorité des clients visés.

## Décision

**Un abonnement Sekuu est un droit d'accès prépayé et daté, pas un contrat à reconduction tacite.**

### Le renouvellement est un acte volontaire

À l'approche du terme, la plateforme **prévient** — J-7, J-3, J-1, par email et par SMS. Le client renouvelle en déclenchant lui-même un paiement (`POST /subscription/renew`).

Aucun prélèvement n'est tenté sans lui. La plateforme n'en a d'ailleurs pas le moyen technique.

### La fin de période n'est pas un incident

C'est un état prévu du cycle de vie, avec une transition douce :

```text
active  ──►  grace (7 jours, accès maintenu)  ──►  suspended (accès fermé)  ──►  expired
```

La grâce coûte sept jours de service non payé. Sans elle, un oubli d'une journée devient une interruption d'activité : une clinique qui ne peut pas ouvrir son agenda un lundi matin ne reste pas cliente.

### La suspension ne détruit rien

Un accès fermé n'est pas une donnée supprimée. La rétention relève de chaque produit et d'une décision explicite, jamais d'un défaut de paiement.

### Aucun remboursement en espèces

Tout trop-perçu — proration de montée en gamme, avoir commercial — devient un **crédit** imputé au prochain paiement. Un remboursement Mobile Money est lent, coûteux, et souvent manuel.

Le remboursement réel existe, mais comme geste décidé par un humain, pas comme mécanique du module.

### Une descente en gamme est différée, jamais immédiate

La période en cours est payée. L'écourter obligerait à rembourser.

### Le paiement transite par des agrégateurs

NotchPay, Tranzak et Tara, avec bascule — décision et règles dans [ADR-0008](adr-0008-payment-aggregators-failover.md).

### Le sondage est obligatoire, pas le callback

Un callback Mobile Money peut se perdre, arriver deux fois, ou arriver dans le désordre.

Toute intention de paiement en attente est donc **réinterrogée** chez l'opérateur jusqu'à ce qu'il tranche. Le callback accélère la confirmation ; il n'en est jamais la seule source.

Corollaire : une intention dont l'opérateur n'a jamais tranché est `expired`, et **non** `failed`. `expired` signifie *on ne sait pas*, ce qui n'est pas *cela a échoué*.

## Conséquences

**Positives**

* Le modèle correspond au moyen de paiement réel du marché, au lieu de le contraindre.
* Aucune promesse technique intenable : la plateforme ne prétend jamais pouvoir débiter.
* Pas de prélèvement surprise, donc pas de litige sur un débit non attendu — le grief le plus fréquent contre les abonnements à reconduction tacite.
* La séquence de relance est un problème de **communication**, que Notify sait déjà traiter, et non un problème de réessai de paiement.
* Le crédit plutôt que le remboursement évite d'exposer le module à l'opération la plus fragile du Mobile Money.

**Négatives**

* **Le taux de rétention sera structurellement inférieur** à celui d'un modèle à reconduction. Une part des clients ne renouvellera pas par simple inertie, alors qu'un débit automatique les aurait conservés. C'est le coût principal de cette décision, et il est réel.
* Les revenus sont moins prévisibles : ils dépendent d'une action client, pas d'un calendrier.
* Une charge de rappels récurrente, avec un coût SMS à surveiller.
* Un cas d'exploitation permanent : les paiements sans issue connue, qui exigent un rapprochement manuel.
* Le renouvellement anticipé et la grâce compliquent le calcul de période — deux sources de bugs subtils sur les dates.

**Mitigations**

* Le tarif annuel réduit mécaniquement le nombre d'échéances, donc le nombre d'occasions d'abandonner. C'est le principal levier de rétention disponible dans ce modèle, et il justifie de le proposer dès le départ.
* `grace_ends_at` est une **date absolue**, jamais un compteur décrémenté : la tâche quotidienne reste idempotente si elle tourne deux fois.
* `payments.payment.unresolved` alerte l'exploitation plutôt que de laisser un client payé sans service. *(Publié sous le préfixe `billing.` jusqu'à l'[ADR-0009](adr-0009-payments-module-extraction.md).)*
* La commande `billing:sync-access` reconstruit les droits en cas d'événement perdu.

## Ce qui a tranché

Un modèle de facturation qui suppose un moyen de paiement que les clients n'ont pas ne dégrade pas l'expérience : il **empêche d'encaisser**.

L'alternative aurait été d'exiger la carte bancaire pour bénéficier du renouvellement automatique. Cela revenait à réserver le produit à la minorité qui en possède une — un arbitrage de rétention payé par la taille du marché adressable.

## Alternatives écartées

**Carte bancaire obligatoire** — exclut la majorité des clients visés. La rétention supérieure porterait sur une base beaucoup plus petite.

**Mandat de prélèvement Mobile Money** — les opérateurs et certains agrégateurs proposent des mécanismes de pré-approbation, mais leur disponibilité, leurs plafonds et leurs conditions varient selon l'opérateur et le statut du marchand. Construire le modèle de facturation sur une capacité non confirmée, c'est risquer de tout réécrire à l'intégration. Le jour où un mandat est confirmé, il s'ajoute comme un `payment_intents.method` supplémentaire — le modèle prépayé reste valide, et rien de ce qui est décrit ici n'est perdu.

**Coupure sèche à l'échéance, sans grâce** — cohérent avec le prépaiement, mais transforme un oubli en interruption d'activité. Le coût de sept jours offerts est très inférieur au coût d'un client perdu.

**Remboursement automatique du reliquat** — expose le module à l'opération la plus fragile et la plus coûteuse du Mobile Money, pour un cas marginal.

**Facturation postpayée** — revient à accorder un crédit à des organisations dont la solvabilité n'est pas vérifiée, sans moyen de recouvrement.
