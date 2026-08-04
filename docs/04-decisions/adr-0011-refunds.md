# ADR-0011 — Rendre l'argent

> **Statut :** Acceptée — implémentée, **sans décaissement automatique**
> **Date :** Août 2026

---

## Contexte

`refund` était déclaré au registre de caisse depuis l'origine et écrit nulle
part. Le modèle de données le signalait comme un choix à faire **avant que des
données monétaires n'existent** : ligne négative au registre, ou table avec son
propre cycle de vie.

L'[ADR-0007](adr-0007-mobile-money-prepaid-subscriptions.md) avait déjà tranché
pour Billing : pas de remboursement en espèces, un trop-perçu devient un
**crédit** imputé au prochain paiement. C'était suffisant tant que la plateforme
ne vendait que des abonnements — un client d'abonnement repasse à la caisse le
mois suivant.

L'[ADR-0010](adr-0010-external-payment-api.md) a ouvert l'encaissement à des
produits externes. Sekuu Learn vend des formations à des apprenants qui n'ont ni
compte Sekuu, ni organisation, ni registre de crédit. Un apprenant qui annule ne
peut pas être « crédité » : il n'y a rien à créditer.

## Le problème que cette ADR tranche

Trois questions, dont deux n'avaient pas de réponse évidente.

1. Où vit un remboursement — au registre, ou dans sa propre table ?
2. Qui décide qu'un remboursement est justifié ?
3. Qui fait sortir l'argent ?

## Décision

### Une table, pas une ligne de registre

Un remboursement peut être **en attente**, échouer, être repris. Le registre de
caisse ne porte que des **constats** : append-only, sans `updated_at`, scellé au
niveau du modèle. Une ligne `pending` qu'on corrigerait ensuite détruirait
exactement la propriété qui le rend auditable.

C'est la même séparation qu'entre `payment_intents` et `payment_transactions`,
appliquée dans l'autre sens. La ligne `refund` du registre — négative — n'est
écrite **qu'au décaissement constaté**.

### Le propriétaire décide, par une interface qu'il peut ne pas porter

Même inversion que pour le prix : la couche de paiement ne peut pas savoir si un
remboursement est justifié — la formation a-t-elle été suivie, le délai de
rétractation est-il écoulé ?

Mais `RefundableSource` est une interface **distincte** de `PayableSource`, et
c'est le point : **ne pas la porter est une réponse**, et la bonne pour Billing.

L'ajouter à `PayableSource` aurait forcé chaque produit à écrire une méthode
pour dire non, et la première implémentation copiée-collée aurait dit oui par
distraction. Ici le défaut est le refus, et il échoue durement
(`REFUND_NOT_SUPPORTED`).

### Le plafond appartient à la plateforme, pas au produit

**On ne rend jamais plus que ce qui a été réellement encaissé.** Aucun produit
n'a à en décider, et aucun ne doit pouvoir s'en affranchir.

Le plafond est le montant de la ligne `charge` du registre — le constat — et non
celui de l'intention. Les deux devraient coïncider ; s'ils divergent, c'est le
registre qui dit ce qui est entré en caisse.

Un remboursement `pending` **immobilise** déjà la somme. Ne compter que les
décaissements constatés laisserait décider deux fois le même remboursement avant
que le premier ne soit versé, et les deux partiraient.

### Aucun décaissement automatique — et c'est délibéré

Un remboursement Mobile Money est un **décaissement**, pas l'annulation d'un
débit. Il exige un solde disponible sur le compte marchand, une API de transfert
distincte de celle d'encaissement, et il échoue pour des raisons qui n'ont rien
à voir avec le paiement d'origine.

Aucun compte marchand de production n'existe, et aucun agrégateur ne documente
un bac à sable de transfert. Écrire l'adaptateur maintenant reproduirait
exactement l'erreur du canal SMS de Notify — intégralement écrit, jamais exécuté
contre une vraie passerelle. Sur de l'argent qui **sort**, la facture serait plus
salée.

Le module enregistre donc l'obligation ; un opérateur vire, puis vient la
constater avec la référence du transfert (`payments:refund`). C'est ce que
l'ADR-0007 décrivait déjà : « un geste décidé par un humain, pas une mécanique
du module ».

La couture est prête : `SettleRefund` est le point d'entrée unique, et un
adaptateur n'aura qu'à l'appeler.

### Un scope distinct de l'encaissement

`payments.refund` ne s'obtient pas avec `payments.charge`.

Faire entrer de l'argent et en faire sortir sont deux dangers opposés. Un seul
droit pour les deux serait le plus large des deux, et une clé destinée à vendre
pourrait vider le compte marchand.

### Un motif obligatoire

Un remboursement est un geste dont quelqu'un devra rendre compte. Un motif
facultatif serait vide neuf fois sur dix, et la dixième est celle qu'on
cherchera un an plus tard.

## Conséquences

**Positives**

* Un produit externe peut rendre l'argent, ce qui était le manque le plus proche
  après l'ADR-0010.
* Le registre reste un registre : aucune écriture n'y est faite avant que
  l'argent n'ait bougé.
* Le refus de Billing devient explicite et vérifiable, au lieu d'être une
  absence de code.
* Un remboursement partiel est possible, et le cumul est borné.

**Négatives**

* **Le décaissement reste manuel.** Un opérateur doit virer, puis constater. Cela
  ne passe pas à l'échelle, et c'est un délai supplémentaire pour un client qui
  attend son argent.
* Une obligation enregistrée et jamais constatée immobilise indéfiniment une
  part du brut. Rien ne l'expire aujourd'hui.
* Deux vérités à recomposer pour connaître le net d'une charge : la charge dit ce
  qui a été encaissé, `refunds` ce qui a été rendu.
* La commission de l'agrégateur n'est **pas** rendue au client, et elle reste à
  la charge de la plateforme sur un remboursement intégral. C'est une perte
  sèche, proportionnelle au taux d'annulation.

**Mitigations**

* `payments:refund` sans argument liste ce qui est en attente : la commande
  répond à la question qu'un opérateur se pose réellement.
* La référence du transfert est **obligatoire** pour constater : sans elle, un
  rapprochement bancaire ne peut pas conclure.
* Un décaissement échoué **libère** la somme, et une nouvelle décision est
  nécessaire pour réessayer — jamais un réessai automatique.

## Ce qui a tranché

L'asymétrie des dégâts, comme pour l'[ADR-0008](adr-0008-payment-aggregators-failover.md).

Ne pas rembourser est un litige : le client réclame, le support intervient, et la
plateforme le sait. Rembourser deux fois est une perte que **personne ne
signale** — le client n'a aucune raison de le faire.

C'est le miroir exact du double débit, avec une visibilité inversée. D'où
l'idempotence en base plutôt qu'applicative, le verrou sur l'intention, et la
règle qu'un remboursement en attente immobilise déjà la somme.

## Alternatives écartées

**Une ligne négative au registre, sans table** — plus simple, et incompatible
avec un registre append-only : il faudrait corriger la ligne quand le
décaissement échoue.

**Ajouter `refundable()` à `PayableSource`** — forcerait chaque produit à écrire
une méthode pour dire non. Le défaut serait alors l'acceptation, par
copier-coller.

**Écrire un adaptateur de décaissement maintenant** — sans compte marchand ni bac
à sable, c'est du code jamais exécuté contre une vraie passerelle, sur le chemin
de l'argent qui sort.

**Rembourser automatiquement en cas d'échec de service** — expose la plateforme à
l'opération la plus fragile du Mobile Money, sans qu'aucun humain ne valide le
montant.

**Rendre aussi la commission** — la plateforme la paierait deux fois. Le choix
est de l'assumer comme une charge, et de la rendre visible au registre plutôt que
de la répercuter au client.
