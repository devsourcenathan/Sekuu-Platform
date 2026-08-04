# Sekuu Payments — Encaisser depuis un service externe

> **Version :** 1.0
> **Statut :** Spécification — **non implémentée**
> **Dernière mise à jour :** Août 2026

Ce document spécifie comment un produit qui **ne partage pas la base de code** de Sekuu Platform — Sekuu Learn, notamment — encaisse via Payments.

Rien de ce qui suit n'existe encore. Les décisions sont prises dans [ADR-0010](../../04-decisions/adr-0010-external-payment-api.md).

L'intégration d'un module interne, elle, est décrite dans [06-integration.md](06-integration.md).

---

# 1. La différence qu'il faut comprendre avant tout le reste

Un module interne implémente `PayableSource`. Payments lui **demande** le prix
au moment du paiement, et lui **remet** l'issue dans la transaction
d'encaissement.

Un service externe ne peut faire ni l'un ni l'autre. Ces deux échanges deviennent :

| | Module interne | Service externe |
| --- | --- | --- |
| Le prix | **demandé** au moment du paiement | **déclaré** à la création |
| L'autorisation | vérifiée par `quote()`, au moment du paiement | vérifiée par le produit, **avant** l'appel |
| L'issue | `settled()`, **dans** la transaction | webhook sortant, **après** commit |

La troisième ligne est la seule perte réelle, et elle est irréductible.

## 1.1 Ce qu'un service externe ne peut pas obtenir

En interne, « l'argent est encaissé » et « le service est ouvert » sont **atomiques** : `settled()` est appelée dans la même transaction que l'écriture du registre. Il n'existe aucun instant où l'un est vrai sans l'autre.

Un service externe ne peut pas participer à cette transaction. Entre l'encaissement et la prise en compte par le produit, il existe une fenêtre — brève, mais réelle — pendant laquelle **un client a payé et n'a pas son service**.

C'est exactement la défaillance que ce module existe pour empêcher. On ne peut pas la supprimer pour un service externe ; on peut seulement la rendre **courte et rattrapable** :

* un webhook sortant, réessayé ;
* un endpoint de consultation, pour que le produit sonde ce qu'il n'a pas reçu ;
* un rapport de réconciliation, pour que ce qui a échappé aux deux se voie.

Un produit externe qui ne met en place que le webhook aura, tôt ou tard, un client payé sans service et aucun moyen de s'en apercevoir.

---

# 2. Authentification

## 2.1 L'invariant à préserver

La règle n'est pas « le montant ne vient jamais d'HTTP ». Elle est :

> **Seul le propriétaire de l'objet nomme son prix.**

En interne, l'interface le garantit. En externe, c'est le **scope de la clé d'API** qui le garantit :

```text
scope           payments.charge
subject_types   ["learn.enrollment", "learn.subscription"]
```

Sekuu Learn peut déclarer le prix de ses inscriptions, et **rien d'autre** — ni une facture Billing, ni la commande d'un autre produit. La propriété survit ; le mécanisme change.

## 2.2 La condition qui fait tout tenir

**Cette clé est strictement serveur à serveur.**

Si elle atterrit dans un navigateur, une application mobile ou un dépôt public, n'importe qui déclare n'importe quel montant sur n'importe lequel des objets du produit. Toute la protection tombe d'un coup.

Conséquences opérationnelles, non négociables :

* jamais dans un client, jamais dans un dépôt, jamais dans un journal ;
* une clé par environnement, révocable indépendamment ;
* le préfixe visible (`sk_live_` / `sk_test_`) permet de détecter une fuite, pas de l'empêcher.

## 2.3 Ce que la clé ne dit pas

Elle authentifie le **produit**, pas l'utilisateur final. Payments ne saura jamais si l'apprenant qui paie avait le droit de s'inscrire.

C'est au produit de le vérifier **avant** d'appeler. En interne, `quote()` recevait le payeur et pouvait refuser ; ici, cette barrière n'existe plus côté plateforme.

---

# 3. Créer un paiement

```http
POST /api/v1/payments/charges
Authorization: sk_live_…
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
```

```json
{
  "subject_type": "learn.enrollment",
  "subject_id": "9f1c2b7a-…",
  "payer_type": "learn.learner",
  "payer_id": "4d81a398-…",
  "amount": 15000,
  "currency": "XAF",
  "description": "Sekuu Learn — Comptabilité pour PME",
  "msisdn": "+237650000000"
}
```

`202 Accepted`, jamais `201` : ce qui est créé est une **intention**.

## 3.1 Le payeur n'a pas à exister dans Identity

`payer_type` suit la même convention que `subject_type` — `{module}.{ressource}`.
Un apprenant Learn n'est pas forcément un utilisateur Sekuu.

Conséquence à connaître : **Payments ne pourra prévenir personne.** La résolution du contact passe par Identity, qui ne connaît pas ce payeur. Les messages d'échec, de reçu et de relance sont à la charge du produit.

## 3.2 Le montant est figé à la création

C'est la contrepartie du prix déclaré plutôt que demandé.

Le client valide l'invite deux ou trois minutes plus tard. Si le prix a changé entre-temps, ou si l'objet a été réglé autrement, **le paiement aboutira quand même au montant déclaré**.

Le produit doit donc traiter l'issue comme faisant autorité, et **échouer bruyamment** si le montant reçu ne correspond plus à ce qu'il attendait — plutôt que d'ignorer l'écart. Une organisation qui a un registre de crédit peut absorber le trop-perçu ; un produit qui n'en a pas doit le signaler.

## 3.3 Erreurs propres à ce chemin

| Code | HTTP | Cause |
| --- | --- | --- |
| `SUBJECT_TYPE_NOT_ALLOWED` | 403 | La clé ne porte pas ce `subject_type` |
| `PAYMENT_ALREADY_PENDING` | 409 | Une intention vit déjà sur ce sujet |
| `INVALID_MSISDN` | 422 | Numéro invalide ou opérateur non reconnu |
| `PROVIDER_UNAVAILABLE` | 503 | Aucun agrégateur ne couvre cet opérateur, ou tous ont refusé |

`PAYMENT_ALREADY_PENDING` est le garde-fou anti-triple-clic, appliqué par un index unique en base. Le produit doit le traiter comme une **information** — « un paiement est déjà en cours » — et non comme une erreur à réessayer.

---

# 4. Apprendre l'issue

**Les deux mécanismes sont obligatoires.** C'est la même raison qui fait que Payments ne croit pas les callbacks des agrégateurs : un webhook se perd.

## 4.1 Webhook sortant

```http
POST https://learn.sekuu.com/webhooks/payments
X-Sekuu-Signature: <hmac-sha256 du corps brut>
```

```json
{
  "id": "evt_…",
  "type": "payment.succeeded",
  "occurred_at": "2026-08-04T09:14:00Z",
  "data": {
    "payment_id": "…",
    "subject_type": "learn.enrollment",
    "subject_id": "…",
    "amount": 15000,
    "currency": "XAF",
    "status": "succeeded"
  }
}
```

Trois obligations pour le produit :

**Vérifier la signature** sur le corps **brut**, en comparaison à temps constant. Une signature calculée sur le corps désérialisé ne vérifie pas ce qui a transité.

**Dédupliquer sur `id`.** La livraison est « au moins une fois ». Un même événement peut arriver deux fois, et deux événements distincts peuvent arriver dans le désordre — c'est ce qu'ont fait les agrégateurs en conditions réelles.

**Répondre `2xx` rapidement.** Le traitement long se fait après. Une réponse en erreur déclenche des réessais, et un endpoint durablement en échec finit désactivé.

## 4.2 Sondage

`GET /api/v1/payments/{id}`, authentifié par la même clé, scopé aux `subject_types` qu'elle porte.

À utiliser dans deux cas : pendant que le client attend, et pour rattraper ce que le webhook n'a pas livré.

`expired` signifie **on ne sait pas**, et non « cela a échoué ». Un paiement dont l'issue est inconnue peut avoir été encaissé : le traiter comme un échec risquerait de facturer deux fois.

## 4.3 Réconciliation

Un endpoint listant les paiements réglés sur une période, pour que le produit compare avec ce qu'il a enregistré.

C'est le seul filet contre le cas où le webhook et le sondage ont tous deux échoué. Sans lui, un client payé sans service reste invisible jusqu'à sa réclamation.

---

# 5. Ce que le produit ne doit jamais faire

**Ne pas relancer un paiement échoué automatiquement.** C'est contourner la règle de bascule, qui existe pour ne pas débiter deux fois. Un nouveau paiement est une **action du client**, jamais un réessai du code.

**Ne pas croire le corps d'un webhook sur parole pour un montant.** Il est signé, donc authentique — mais en cas d'écart avec ce qui était attendu, c'est un signal d'incident, pas une valeur à enregistrer.

**Ne pas exposer la clé d'API au client final.** Voir § 2.2 : c'est la seule erreur qui casse tout le modèle d'un coup.

**Ne pas traiter `expired` comme un échec.** Voir § 4.2.

---

# 6. Ce qui reste à trancher

Points ouverts, à décider avant l'implémentation :

* **Le rythme et la durée des réessais** du webhook sortant, et ce qu'on fait d'un endpoint durablement injoignable.
* **La rotation du secret de signature** : deux secrets valides pendant une fenêtre, ou coupure nette.
* **Le remboursement**, qui n'existe toujours pas — et qu'un produit vendant des formations rencontrera avant Billing.
* **Le reversement à un tiers**, si un centre de formation encaisse via la plateforme. `payee_organization_id` existe, rien derrière n'est construit.
