# Sekuu Payments — Contrat d'événements

> **Version :** 1.0
> **Statut :** Spécification de référence
> **Dernière mise à jour :** Août 2026

Payments émet **deux** événements, et n'en consomme aucun.

C'est délibérément peu. L'essentiel de ce que ce module a à dire ne passe pas
par un événement mais par un **appel synchrone** au propriétaire de l'objet payé
— voir § 2.

Documents liés : [Vision](01-overview.md) · [Modèle de données](02-data-model.md) · [Intégrer un produit](06-integration.md) · [Contrat d'événements de Notify](../notify/04-events.md#11-format)

---

# 1. Ce qui ne doit jamais transiter

| Interdit dans `data` | Pourquoi |
| --- | --- |
| Numéro Mobile Money complet | Donnée personnelle, sans usage pour le consommateur |
| Nom de l'agrégateur | Détail d'exploitation ; l'exposer figerait l'ordre de priorité |
| Montant net et commission | Économie de la plateforme, pas information client |

Une exception assumée : `payments.payment.unresolved` **porte** les références
des agrégateurs. Il ne s'adresse pas à un produit mais à l'exploitation, et ces
références sont exactement ce dont un humain a besoin pour trancher.

---

# 2. Ce qui n'est pas un événement, et pourquoi

Le règlement d'un objet payé — « cette facture est réglée », « cette inscription
est ouverte » — **n'est pas publié**. Il est remis au propriétaire par
`PayableSource::settled()`, appelée **dans la transaction d'encaissement**.

C'est une exception assumée à
[l'architecture § 11.1](../../01-overview/architecture.md). Confier ce moment à
une file créerait une fenêtre où l'argent est encaissé et le service fermé,
qu'un consommateur en échec définitif rendrait **permanente**.

L'événement `payments.payment.succeeded` existe malgré tout, mais pour un autre
usage : il est **informatif**, destiné à l'analyse et à la supervision. Un
produit qui l'utiliserait pour ouvrir son service se donnerait précisément la
fenêtre que le contrat synchrone supprime.

---

# 3. Événements émis

| Événement | Quand | Destinataire |
| --- | --- | --- |
| `payments.payment.succeeded` | Encaissement constaté | Analytics, supervision |
| `payments.payment.unresolved` | Aucune issue tranchée à l'expiration | **Exploitation** |

```json
{
  "id": "evt_…",
  "type": "payments.payment.succeeded",
  "occurred_at": "2026-08-03T09:17:04Z",
  "request_id": "req_…",
  "organization_id": "…",
  "data": {
    "payment_intent_id": "…",
    "subject_type": "billing.invoice",
    "subject_id": "…",
    "amount": 49663,
    "currency": "XAF"
  }
}
```

`organization_id` est celui du **contexte** — le payeur s'il est une
organisation, le bénéficiaire sinon. Il peut être absent : un payeur qui n'est
pas une organisation Sekuu est un cas prévu.

## 3.1 `payments.payment.unresolved` n'a pas de destinataire produit

Il alerte l'équipe, et il est le seul cas de ce module qui exige une
intervention **humaine**.

Il signale une intention pour laquelle aucun agrégateur n'a jamais tranché : le
client a peut-être été débité sans que la plateforme le sache. Le taire serait
laisser un client payé sans service — la défaillance que tout ce module existe
pour empêcher.

Sa charge porte `provider_refs`, les références de chaque tentative, parce
qu'elles sont ce qu'un humain devra donner à l'agrégateur pour obtenir une
réponse.

## 3.2 Il n'existe pas d'événement de bascule

Passer de NotchPay à Tranzak est un détail d'exécution, pas un fait métier. Le
publier exposerait l'ordre de priorité à des consommateurs qui n'en ont aucun
usage.

Ces bascules se lisent dans `payment_attempts` et dans les journaux.

## 3.3 Il n'existe pas d'événement d'échec

C'est `PayableSource::failed()` qui prévient le propriétaire, et c'est **lui**
qui publie son propre événement — `billing.payment.failed` pour une facture.

La raison est concrète : Notify associe les événements aux templates par un
tableau littéral. Un `payments.payment.failed` générique ne tomberait dans
aucune correspondance, sans exception ni journal. Le message d'échec
disparaîtrait en silence, au moment précis où le client est le plus susceptible
de recommencer.

---

# 4. Événements consommés

**Aucun.**

Ce module ne réagit à rien. Il est déclenché par un appel — `InitiatePayment` —
et par les retours des agrégateurs : callbacks et réconciliation.

C'est cohérent avec sa vision : il ne sait pas ce qu'il encaisse, donc aucun
fait métier d'un autre module ne peut le concerner.

---

# 5. Correspondance avec les templates Notify

**Aucune.**

Payments ne prévient personne, et ne le peut pas : la résolution du contact
passe par `IdentityContract`, qui ne connaît que les utilisateurs Sekuu — pas un
apprenant Learn ni un client d'un produit tiers.

Prévenir le payeur est à la charge du propriétaire de l'objet, dans ses termes.
Pour Billing, la correspondance est
[ici](../billing/04-events.md#4-correspondance-avec-les-templates-notify).
