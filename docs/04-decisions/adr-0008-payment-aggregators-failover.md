# ADR-0008 — Agrégateurs de paiement et règle de bascule

> **Statut :** Acceptée
> **Date :** Août 2026

---

## Contexte

[ADR-0007](adr-0007-mobile-money-prepaid-subscriptions.md) pose que les abonnements sont prépayés, parce que le Mobile Money exige une action du client. Restait à décider **par qui** l'argent transite.

Deux chemins existaient :

* **Intégration directe** par opérateur — un compte marchand MTN, un compte marchand Orange, deux intégrations distinctes, deux jeux de bizarreries à absorber.
* **Agrégateurs** — un intermédiaire qui expose MTN et Orange derrière une seule API, contre une commission.

Le choix est fait : **NotchPay, Tranzak et Tara**, dans cet ordre de priorité, avec bascule.

Trois agrégateurs et non un seul, parce qu'un agrégateur est un point de défaillance unique placé exactement sur le chemin de l'argent. S'il tombe, la plateforme n'encaisse plus — et sur un modèle prépayé, ne plus encaisser signifie que les accès se ferment les uns après les autres.

Mais la bascule sur un paiement n'est pas la bascule sur un email, et c'est tout l'objet de cette décision.

## Le problème que cette ADR tranche

Notify possède déjà une politique de bascule : si Resend échoue, on essaie Postmark. Elle est sans danger, parce que le pire résultat est un destinataire qui reçoit deux fois le même message.

Transposer ce réflexe au paiement produirait **deux débits sur le compte du client pour une seule facture**.

Le danger est d'autant plus réel que le cas se présente de la façon la plus banale qui soit : l'appel à l'agrégateur expire au bout de trente secondes. Le code ne sait pas si la demande a été reçue. Le réflexe — réessayer ailleurs — est ici précisément la mauvaise réponse.

## Décision

### Une intention, plusieurs tentatives

Le modèle sépare `payment_intents` (ce que le client veut payer) de `payment_attempts` (ce qu'on a tenté, et chez qui).

Une facture n'est payée qu'une fois, même si trois agrégateurs ont été sollicités. Un index partiel garantit **une seule tentative vivante par intention**, et **une seule intention vivante par facture**.

### La règle de bascule

> **On ne bascule que si l'invite n'est jamais partie sur le téléphone du client.**

C'est ce que porte la colonne `payment_attempts.customer_prompted`.

| Situation | Statut | Bascule |
| --- | --- | --- |
| Agrégateur injoignable, en panne, authentification refusée | `rejected` | **Oui** |
| Agrégateur ne couvrant pas cet opérateur | `rejected` | **Oui** |
| Invite partie, client silencieux | `prompted` | Non |
| Client a refusé, solde insuffisant | `failed` | Non |
| Aucune réponse dans le délai | `expired` | Non |

### L'incertitude compte comme « invite partie »

Tout statut qu'un adaptateur ne sait pas traduire est traité comme `prompted`. Le défaut penche du côté qui ne débite pas deux fois.

Ce défaut n'est pas une précaution théorique. La documentation publique de Notch Pay et de Tranzak a été vérifiée : **aucun des deux n'expose de champ indiquant que l'invite est partie** ([05-providers.md](../03-services/payments/05-providers.md)). L'information doit être déduite de l'issue de l'appel de débit, et seules les erreurs d'authentification ou de validation la prouvent négative. Une temporisation ne prouve rien.

La règle de bascule est donc encore plus étroite que prévu à sa rédaction : en pratique, elle ne couvre que l'agrégateur qui refuse explicitement la demande.

### Un rejet métier ne bascule jamais

Un solde insuffisant chez MTN le reste quel que soit l'agrégateur qui pose la question.

C'est la règle déjà posée pour Notify — un rejet métier ne réussira pas davantage ailleurs, et chaque tentative coûte — appliquée ici avec un enjeu supérieur.

### La référence marchande est écrite avant l'appel

`merchant_reference` est persistée **avant** que l'agrégateur soit contacté. Sans elle, un appel qui expire laisse une tentative dont on ne peut rien savoir. Avec elle, on peut interroger l'agrégateur et trancher.

### Le sondage reste obligatoire

Inchangé par rapport à l'ADR-0007, et renforcé : avec trois agrégateurs, il y a trois schémas de callback pouvant se perdre. Toute tentative non terminale est réinterrogée jusqu'à expiration.

### Le brut et le net sont deux faits distincts

La facture est réglée sur le montant **brut** payé par le client. La commission est une ligne de registre séparée (`type = 'fee'`), à la charge de la plateforme.

### Le client ne choisit pas l'agrégateur

Il n'apparaît ni dans la requête, ni dans le catalogue. L'exposer figerait l'ordre de priorité dans les interfaces clientes et rendrait tout changement d'ordre incompatible.

## Conséquences

**Positives**

* Une seule intégration Mobile Money couvre MTN et Orange, au lieu de deux comptes marchands et deux API.
* La panne d'un agrégateur ne ferme pas la caisse.
* Le double débit est empêché par construction, pas par vigilance.
* La séparation intention / tentative rend un incident de paiement lisible sans requête SQL — ce qui compte pour le support, qui sera confronté à « j'ai payé et je n'ai pas accès ».
* Trois agrégateurs, c'est aussi trois barèmes : un levier de négociation commerciale.

**Négatives**

* **Une commission sur chaque encaissement**, permanente. C'est le prix explicite de ce choix.
* **Trois intégrations à écrire et à maintenir**, chacune avec son schéma de signature et son vocabulaire de statuts. L'économie par rapport au direct est plus faible qu'il n'y paraît — elle porte sur les démarches marchandes et la couverture opérateur, pas sur le volume de code.
* Trois comptes marchands à obtenir, avec trois parcours administratifs.
* Un intermédiaire de plus dans le chemin de l'argent, donc un délai de reversement et un risque de contrepartie supplémentaires.
* **Le point de fragilité se déplace vers la traduction des statuts.** C'est le seul endroit du module où une approximation coûte de l'argent réel à un tiers.
* La bascule est étroite par conception : beaucoup d'échecs ne basculeront pas, et c'est voulu. Elle protège contre l'indisponibilité d'un agrégateur, pas contre un paiement qui échoue.

**Mitigations**

* Chaque adaptateur doit **énumérer explicitement** les statuts signifiant « invite jamais partie ». Tout le reste est `prompted` par défaut.
* Cette énumération est le premier sujet de tests de chaque adaptateur, avant même le chemin nominal.
* `billing.payment.unresolved` alerte l'exploitation sur toute issue inconnue.
* `provider_events` conserve le corps brut de chaque callback : en cas de litige, c'est la seule pièce qui dit ce que l'agrégateur a réellement envoyé, et non ce que le code en a compris.
* Les comptes marchands doivent être demandés **en parallèle**. Un seul obtenu, la bascule n'existe que sur le papier.
* **Deux agrégateurs suffisent** à supprimer le point de défaillance unique. Tara n'ayant pas de documentation technique publique, son adaptateur est repoussé sans que cela bloque le module.
* L'ordre de développement n'est pas l'ordre de priorité : Tranzak d'abord, parce qu'il est le seul à documenter un bac à sable. Écrire un adaptateur de paiement sans environnement de test reviendrait à reproduire le canal SMS de Notify — écrit intégralement, jamais exécuté contre une vraie passerelle.

## Ce qui a tranché

L'asymétrie des dégâts, comme pour [ADR-0006](adr-0006-transactional-vs-marketing.md).

Ne pas encaisser est un incident : le client réessaie, ou le support intervient. Encaisser deux fois est une faute que le client découvre sur son relevé, que le remboursement Mobile Money rend pénible à corriger, et qui détruit la confiance sur laquelle repose un service de paiement.

Une politique de bascule agressive optimise le taux de succès et paie ce gain en double débits. Sur un marché où le paiement mobile est le seul moyen de payer, ce n'est pas une monnaie d'échange acceptable.

## Alternatives écartées

**Intégration directe par opérateur** — évite la commission et l'intermédiaire, mais exige deux comptes marchands, deux intégrations, et laisse chaque opérateur sans repli. La commission achète surtout de la couverture et de la disponibilité.

**Un agrégateur unique** — moins de code, mais un point de défaillance unique sur le chemin de l'argent. Sur un modèle prépayé, l'indisponibilité de la caisse ferme progressivement les accès.

**Bascule systématique sur tout échec** — le réflexe hérité de Notify. Produit des doubles débits, et l'erreur n'est visible que sur le relevé du client.

**Laisser le client choisir son agrégateur** — reporte le problème sur lui, expose un détail d'exploitation, et fige l'ordre de priorité dans toutes les interfaces clientes.

**Bascule automatique après expiration** — tentant, puisque l'issue est inconnue. C'est exactement le cas où le client peut avoir été débité. Une intention `expired` part au rapprochement manuel, jamais à une nouvelle tentative automatique.
