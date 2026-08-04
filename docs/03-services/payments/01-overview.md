# Sekuu Payments — Vision & Périmètre

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Composant :** Sekuu Payments Service
> **Dernière mise à jour :** Août 2026

Ce document décrit **le rôle et les frontières** de Sekuu Payments.

* **Pour brancher un module du monolithe : [06-integration.md](06-integration.md).**
* **Pour brancher un service externe : [07-external-api.md](07-external-api.md)** — spécifié, non implémenté.
* Les tables font autorité dans [02-data-model.md](02-data-model.md).
* L'API fait autorité dans [03-api.md](03-api.md), et le contrat OpenAPI par-dessus.
* Les événements font autorité dans [04-events.md](04-events.md).
* Les agrégateurs et ce qu'ils ont démenti font autorité dans [05-providers.md](05-providers.md).
* Le remboursement fait autorité dans [08-refunds.md](08-refunds.md).
* La règle de bascule est décidée dans [ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md).
* L'extraction elle-même est décidée dans [ADR-0009](../../04-decisions/adr-0009-payments-module-extraction.md).

---

# 1. Vision

Payments encaisse de l'argent par Mobile Money. **Rien d'autre.**

Il ne sait pas ce qu'il encaisse. Une intention de paiement porte un
`subject_type` et un `subject_id` — `billing.invoice`, `learn.enrollment` —
qu'il transporte, indexe, et remet à un résolveur sans jamais les interpréter.

C'est ce qui permet à une facture d'abonnement et à une inscription à une
formation d'emprunter exactement le même chemin, sans que l'un sache que l'autre
existe.

---

# 2. Ce que Payments ne fait pas

| Hors périmètre | Responsable |
| --- | --- |
| Décider **combien** encaisser | Le module propriétaire de l'objet payé |
| Décider **qui a le droit** de payer | Idem |
| Émettre une facture, appliquer une TVA | **Billing** |
| Gérer un abonnement, une période, une grâce | **Billing** |
| Connaître les utilisateurs et les organisations | **Identity** |
| Prévenir le client | **Notify**, via le propriétaire de l'objet |

## 2.1 Aucune route de création

C'est la conséquence la plus visible, et elle est délibérée.

`POST /payments` n'existe pas ici. Déclencher un paiement suppose de savoir ce
qu'on paie, combien cela vaut et qui a le droit de le régler — trois choses que
ce module ignore. Une route de création exposée ici offrirait un moyen de faire
sonner le téléphone de quelqu'un sans motif vérifiable.

Chaque module propriétaire expose la sienne : `POST /payments` côté Billing
règle une facture.

---

# 3. Le montant est indicible

`InitiatePayment::handle()` ne prend aucun montant en paramètre.

```php
$payments->handle(
    subject: new PayableRef('billing.invoice', $invoiceId),
    payer: PayerContext::organization($organizationId, $userId),
    rawMsisdn: '+237650000000',
);
```

On ne *peut pas* demander à régler 49 663 XAF avec 100 XAF : il n'y a pas de
paramètre pour le faire. Le montant est produit par `PayableSource::quote()`,
c'est-à-dire par le propriétaire de l'objet, qui seul sait ce qu'il vaut.

`quote()` reçoit le payeur, et refuse un objet que ce payeur n'a pas le droit de
régler. Payments ne peut pas trancher cette question — il ne sait rien des rôles.

---

# 4. Le contrat d'un objet payable

Trois méthodes, implémentées par le module qui possède l'objet.

| Méthode | Rôle |
| --- | --- |
| `quote(ref, payer)` | Combien, avec quel libellé, et ce payeur y a-t-il droit ? Sans effet de bord. |
| `settled(settlement)` | Le paiement a abouti. Appelée **dans la transaction**. |
| `failed(settlement)` | Le paiement a échoué définitivement. |

`settled()` est synchrone et non événementielle. C'est une exception assumée à
[l'architecture § 11.1](../../01-overview/architecture.md) : confier ce moment à
une file créerait une fenêtre où l'argent est encaissé et le service fermé,
qu'un consommateur en échec définitif rendrait permanente.

La résolution `subject_type` → propriétaire passe par `config/payments.php`.
C'est **le seul endroit** où ce module apprend que Billing existe — aucun de ses
fichiers ne l'importe, et un test d'architecture le vérifie.

---

# 5. Les invariants qui coûtent de l'argent

Trois règles dont la violation débite un client à tort.

**On ne bascule que si l'invite n'est jamais partie.** Détaillé dans
l'[ADR-0008](../../04-decisions/adr-0008-payment-aggregators-failover.md). En
pratique, seul un agrégateur qui refuse explicitement la demande autorise à
essayer le suivant : une temporisation ne prouve rien et vaut « invite partie ».

**Une seule intention vivante par objet payable.** Index unique partiel, sans
clause d'exclusion — donc valable pour un objet quelconque, pas seulement pour
une facture. C'est le garde-fou contre le client qui clique trois fois.

**Le corps d'un callback ne décide jamais de l'issue.** Le statut est relu chez
l'agrégateur. Un paiement produit plusieurs livraisons, dans un ordre variable :
croire le statut annoncé ferait régresser un encaissement constaté.

---

# 6. Le sondage n'est pas optionnel

`payments:reconcile` interroge les agrégateurs toutes les cinq minutes.

Un callback se perd. S'il est la seule source d'information, un client peut avoir
été débité sans que la plateforme le sache — il a payé et n'a pas son service.
C'est la pire défaillance possible pour ce module, et le sondage existe pour la
rendre impossible.

Une intention dépassée devient `expired`, ce qui signifie **on ne sait pas** —
et non « cela a échoué ». Elle part au rapprochement manuel, jamais à une
nouvelle tentative automatique.

---

# 7. Ce que ce module ne sait pas encore faire

**Encaisser pour le compte d'un tiers.** `payee_organization_id` existe et laisse
la porte ouverte, mais rien derrière n'est construit : `ChargeRequest` ne porte
aucun compte de destination, il n'existe pas de type `payout` au registre, ni
d'état de reversement. La commission est traitée comme une charge de la
plateforme, ce qui n'est vrai que tant que le marchand est Sekuu.

**Décaisser automatiquement.** Le remboursement existe et fonctionne
([08-refunds.md](08-refunds.md)), mais le transfert lui-même est exécuté **à la
main** par un opérateur : aucun agrégateur ne documente un bac à sable de
décaissement, et écrire l'adaptateur sans pouvoir l'éprouver reproduirait
l'erreur du canal SMS de Notify — sur de l'argent qui sort.
