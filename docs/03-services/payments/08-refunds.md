# Sekuu Payments — Rendre l'argent

> **Version :** 1.0
> **Statut :** Implémenté — **sans décaissement automatique**
> **Dernière mise à jour :** Août 2026

Documents liés : [Vision](01-overview.md) · [Modèle de données](02-data-model.md) · [API](03-api.md) · [Service externe](07-external-api.md) · [ADR-0011](../../04-decisions/adr-0011-refunds.md)

---

# 1. Ce qu'un remboursement est réellement

**Un décaissement, pas l'annulation d'un débit.**

C'est la première chose à comprendre, et elle commande tout le reste. En Mobile
Money, on ne « rétracte » pas un paiement : on envoie de l'argent dans l'autre
sens. Cela suppose un solde disponible sur le compte marchand, une API de
transfert distincte de celle d'encaissement, et cela échoue pour des raisons qui
n'ont rien à voir avec le paiement d'origine.

Trois conséquences directes :

* **Le décaissement est aujourd'hui manuel.** Un opérateur vire, puis vient le
  constater. Voir § 5.
* **La commission de l'agrégateur n'est pas rendue.** Sur un remboursement
  intégral, elle reste à la charge de la plateforme. C'est une perte sèche,
  proportionnelle au taux d'annulation.
* **Rien n'est instantané.** Un produit qui ferme l'accès dès la demande fermera
  un accès que rien n'a encore remboursé.

---

# 2. Décider n'est pas décaisser

Deux étapes, deux tables, et la distinction est le cœur du modèle.

```text
RequestRefund   →  refunds (pending)          l'obligation
                        │
                        │  un opérateur vire
                        ▼
SettleRefund    →  refunds (succeeded)        le constat
                   payment_transactions (refund, négatif)
```

**La ligne de registre n'est écrite qu'au décaissement constaté.** Le registre de
caisse ne porte que des faits : il est append-only, sans `updated_at`, scellé au
niveau du modèle. Y écrire à la décision lui ferait dire qu'un argent est sorti
alors qu'il est encore sur le compte marchand — et un registre append-only ne se
corrige pas.

C'est la même séparation qu'entre `payment_intents` et `payment_transactions`,
appliquée dans l'autre sens.

## 2.1 Les états

| Statut | Sens | La somme est-elle immobilisée ? |
| --- | --- | --- |
| `pending` | Décidé, l'argent n'est pas sorti | **Oui** |
| `processing` | Décaissement en cours | **Oui** |
| `succeeded` | L'argent est sorti, le registre le dit | **Oui** |
| `failed` | Le transfert a échoué, rien n'est sorti | Non |
| `cancelled` | Abandonné avant tout décaissement | Non |

`failed` et `cancelled` **libèrent** la somme : elle redevient remboursable.

Reprendre un remboursement échoué demande une **nouvelle décision**, jamais un
réessai automatique. C'est la même règle qu'à l'encaissement : on ignore parfois
si le transfert est parti, et l'incertitude ne doit pas produire un second.

---

# 3. Les deux invariants

## 3.1 On ne rend jamais plus qu'on n'a encaissé

Gardé par la couche de paiement. **Aucun produit n'a à en décider, et aucun ne
peut s'en affranchir.**

Le plafond est le montant de la ligne `charge` du registre — le constat — moins
ce qui est déjà engagé. Il porte sur le **cumul** : trois remboursements
partiels ne peuvent pas dépasser ensemble ce qu'un paiement a rapporté.

Un remboursement `pending` compte dans l'engagé. Ne compter que les
décaissements constatés laisserait décider deux fois le même remboursement avant
que le premier ne soit versé — et les deux partiraient.

La vérification se fait **sous verrou** sur l'intention. Sans lui, deux demandes
concurrentes de 15 000 sur un paiement de 20 000 passeraient toutes deux, et
30 000 sortiraient.

## 3.2 On ne rend pas deux fois

C'est le miroir du double débit, avec une différence qui change tout : **le
client n'a aucune raison de signaler l'erreur.**

Un double débit se découvre sur un relevé et produit une réclamation. Un double
remboursement est un cadeau que personne ne dénonce, et qui ne se voit qu'au
rapprochement bancaire — des semaines plus tard.

D'où deux protections :

* `Idempotency-Key`, portée par un index unique en base, **scopée au paiement** ;
* `SettleRefund::succeeded()` idempotente : constater deux fois n'écrit qu'une
  ligne de registre, et conserve la **première** référence de transfert.

---

# 4. Qui décide

Le propriétaire de l'objet payé, par `RefundableSource` — la même inversion que
pour le prix. La couche de paiement ne peut pas savoir si un remboursement est
justifié : la formation a-t-elle été suivie, le délai de rétractation est-il
écoulé ?

```php
interface RefundableSource extends PayableSource
{
    public function refundable(PayableRef $ref, Money $requested): RefundDecision;
    public function refunded(RefundSettlement $settlement): void;
}
```

## 4.1 Ne pas porter cette interface est une réponse

Et c'est celle de **Billing**.

Un trop-perçu y devient un **crédit** imputé au prochain paiement
([ADR-0007](../../04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md)) :
un client d'abonnement repassera à la caisse le mois suivant, et lui virer de
l'argent serait lent, coûteux et inutile.

Demander le remboursement d'une facture échoue donc en `REFUND_NOT_SUPPORTED`.
Ce n'est pas une lacune, c'est la décision.

L'ajouter à `PayableSource` aurait forcé chaque produit à écrire une méthode
pour dire non, et la première implémentation copiée-collée aurait dit oui par
distraction. Ici le défaut est le refus.

## 4.2 `refunded()` est appelée dans la transaction

Comme `settled()`, et pour la même raison : confier ce moment à une file créerait
une fenêtre où l'argent est rendu et l'accès toujours ouvert, qu'un consommateur
en échec définitif rendrait permanente.

Elle doit donc être **brève** et **idempotente**.

---

# 5. Décaisser

```bash
php artisan payments:refund
```

Sans argument, la commande liste ce qui est en attente — elle répond à la
question qu'un opérateur se pose réellement : *qu'est-ce que je dois virer
aujourd'hui ?*

```bash
php artisan payments:refund <refund-id> --reference=TRF-20260804-001
```

**La référence du transfert est obligatoire.** C'est la seule pièce qui relie une
ligne de registre à un mouvement réel sur le compte marchand. Sans elle, un
rapprochement bancaire ne peut pas conclure, et un remboursement contesté devient
indéfendable.

```bash
php artisan payments:refund <refund-id> --fail="Solde marchand insuffisant"
php artisan payments:refund <refund-id> --cancel="Le client a renonce"
```

`--fail` et `--cancel` libèrent tous deux la somme, et se distinguent
délibérément : un échec technique et un renoncement ne s'expliquent pas de la
même façon à qui relira le registre.

## 5.1 Pourquoi aucun adaptateur d'agrégateur

Aucun compte marchand de production n'existe, et aucun agrégateur ne documente un
bac à sable de **transfert**.

Écrire l'adaptateur maintenant reproduirait exactement l'erreur du canal SMS de
Notify — intégralement écrit, jamais exécuté contre une vraie passerelle. Sur de
l'argent qui **sort**, la facture serait plus salée.

La couture est prête : `SettleRefund` est le point d'entrée unique par lequel
passeront le décaissement manuel et, le jour venu, celui d'un agrégateur.

---

# 6. Pour un produit externe

```http
POST /api/v1/payments/charges/{charge_id}/refunds
Authorization: Bearer sk_live_…
Idempotency-Key: 550e8400-…
```

```json
{ "amount": 4000, "reason": "Formation annulee par le centre" }
```

`202`, jamais `201` : ce qui est créé est une **obligation**.

**`amount` est facultatif** — absent, c'est tout ce qui reste remboursable.
Obliger le produit à calculer le reliquat lui ferait tenir une seconde
comptabilité, qui finirait par diverger de celle de la plateforme.

**`reason` est obligatoire.**

## 6.1 Le scope est distinct

`payments.refund` ne s'obtient pas avec `payments.charge`. Faire entrer de
l'argent et en faire sortir sont deux dangers opposés ; un seul droit pour les
deux serait le plus large des deux, et une clé destinée à vendre pourrait vider
le compte marchand.

## 6.2 Apprendre le décaissement

Webhook `refund.succeeded` / `refund.failed`, ou sondage sur
`GET /payments/charges/{id}/refunds/{refund_id}`.

La charge utile porte le montant **rendu**, et `charge_amount` à côté :

```json
{
  "type": "refund.succeeded",
  "data": {
    "refund_id": "…",
    "charge_id": "…",
    "amount": 4000,
    "charge_amount": 15000,
    "status": "succeeded"
  }
}
```

Sur un remboursement partiel, réutiliser la structure d'un encaissement ferait
croire au produit qu'il a tout rendu. Les deux montants côte à côte lui évitent
de tenir ses propres comptes.

**La charge reste `paid`.** Elle dit ce qui a été encaissé, pas ce qui reste dû.
La somme des deux est la seule vérité comptable, et `GET /payments/charges/{id}/refunds`
la donne.

---

# 7. Ce qui n'existe pas

**Le décaissement automatique.** Voir § 5.1.

**L'expiration d'une obligation.** Un remboursement décidé et jamais constaté
immobilise indéfiniment une part du brut. Rien ne le périme aujourd'hui — c'est
le manque le plus proche sur ce chemin.

**Le remboursement de la commission.** Elle reste à la charge de la plateforme, y
compris sur un remboursement intégral.

**Le remboursement d'une facture Billing.** Par décision, pas par omission —
voir § 4.1.
