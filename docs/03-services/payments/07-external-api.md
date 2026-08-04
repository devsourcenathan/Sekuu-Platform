# Sekuu Payments — Encaisser depuis un service externe

> **Version :** 1.1
> **Statut :** **Implémenté**
> **Dernière mise à jour :** Août 2026

Ce document décrit comment un produit qui **ne partage pas la base de code** de
Sekuu Platform — Sekuu Learn, notamment — encaisse via Payments.

Les décisions sont prises dans [ADR-0010](../../04-decisions/adr-0010-external-payment-api.md).
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

En interne, « l'argent est encaissé » et « le service est ouvert » sont
**atomiques** : `settled()` est appelée dans la même transaction que l'écriture
du registre. Il n'existe aucun instant où l'un est vrai sans l'autre.

Un service externe ne peut pas participer à cette transaction. Entre
l'encaissement et la prise en compte par le produit, il existe une fenêtre —
brève, mais réelle — pendant laquelle **un client a payé et n'a pas son
service**.

C'est exactement la défaillance que ce module existe pour empêcher. On ne peut
pas la supprimer pour un service externe ; on peut seulement la rendre **courte
et rattrapable** :

* un webhook sortant, réessayé ;
* un endpoint de consultation, pour sonder ce qu'on n'a pas reçu ;
* un endpoint de réconciliation, pour que ce qui a échappé aux deux se voie.

Un produit externe qui ne met en place que le webhook aura, tôt ou tard, un
client payé sans service et aucun moyen de s'en apercevoir.

## 1.2 Le prix est toujours relu en base

Le montant traverse HTTP **une fois**, à la déclaration. Il est alors écrit dans
`external_charges` — l'analogue d'une facture pour un produit externe.

Au moment de payer, `quote()` le **relit en base**, exactement comme Billing
relit sa facture. `InitiatePayment` n'a toujours aucun paramètre de montant.

Ce n'est pas un détour décoratif : sans lui, il aurait fallu une signature
acceptant un montant, et le premier appelant y aurait écrit
`$request->integer('amount')`. La faille exacte, une couche plus bas.

---

# 2. Authentification

## 2.1 L'invariant à préserver

La règle n'est pas « le montant ne vient jamais d'HTTP ». Elle est :

> **Seul le propriétaire de l'objet nomme son prix.**

En interne, l'interface le garantit. En externe, **deux bornes indépendantes**
le garantissent, et il faut les deux :

```text
la clé d'API       scope         payments.charge, payments.read
                   subject_types ["learn.enrollment"]

la plateforme      config/payments.php
                   'learn.enrollment' => ExternalPayable::class
```

Une clé mal émise ne suffit donc pas à faire payer un objet dont le prix vit
dans le monolithe, et une ligne de configuration n'habilite personne tant
qu'aucune clé ne la porte.

**`billing.invoice` ne peut être porté par aucune clé.** La garde est dans
`IssueApiKey` : l'émission échoue, quel que soit l'appelant.

Un scope de paiement **exige** un périmètre. Une clé portant `payments.charge`
sans `subject_types` est refusée à l'émission — elle autoriserait à déclarer un
prix sans dire sur quoi.

## 2.2 La condition qui fait tout tenir

**Cette clé est strictement serveur à serveur.**

Si elle atterrit dans un navigateur, une application mobile ou un dépôt public,
n'importe qui déclare n'importe quel montant sur n'importe lequel des objets du
produit. Toute la protection tombe d'un coup.

Conséquences opérationnelles, non négociables :

* jamais dans un client, jamais dans un dépôt, jamais dans un journal ;
* une clé par environnement, révocable indépendamment ;
* le préfixe visible (`sk_live_` / `sk_test_`) permet de détecter une fuite, pas
  de l'empêcher.

## 2.3 Ce que la clé ne dit pas

Elle authentifie le **produit**, pas l'utilisateur final. Payments ne saura
jamais si l'apprenant qui paie avait le droit de s'inscrire.

C'est au produit de le vérifier **avant** d'appeler. En interne, `quote()`
recevait le payeur et pouvait refuser ; ici, cette barrière n'existe plus côté
plateforme.

---

# 3. Créer un paiement

```http
POST /api/v1/payments/charges
Authorization: Bearer sk_live_…
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

```json
{
  "success": true,
  "data": {
    "charge_id": "…",
    "payment_id": "…",
    "status": "pending",
    "operator": "mtn",
    "amount": 15000,
    "currency": "XAF",
    "currency_exponent": 0,
    "formatted": "15 000 XAF",
    "expires_at": "2026-08-04T09:24:00Z",
    "instructions": "Validez la demande sur votre téléphone…"
  }
}
```

`currency_exponent` vaut **0** en XAF : `15000` se lit 15 000 francs, pas
150,00.

## 3.1 Le payeur n'a pas à exister dans Identity

`payer_type` suit la même convention que `subject_type` — `{module}.{ressource}`.
Un apprenant Learn n'est pas un utilisateur Sekuu.

**`identity.*` est refusé** (`PAYER_TYPE_NOT_ALLOWED`). Un produit externe ne
peut pas se réclamer d'un compte de la plateforme : le paiement apparaîtrait
dans les paiements d'une organisation qu'il n'authentifie pas.

Conséquence à connaître : **Payments ne pourra prévenir personne.** La
résolution du contact passe par Identity, qui ne connaît pas ce payeur. Les
messages d'échec, de reçu et de relance sont à la charge du produit.

## 3.2 Le montant est figé à la déclaration

C'est la contrepartie du prix déclaré plutôt que demandé.

Le client valide l'invite deux ou trois minutes plus tard. Si le prix a changé
entre-temps, ou si l'objet a été réglé autrement, **le paiement aboutira quand
même au montant déclaré**.

Le produit doit donc traiter l'issue comme faisant autorité, et **échouer
bruyamment** si le montant reçu ne correspond plus à ce qu'il attendait — plutôt
que d'ignorer l'écart.

## 3.3 Erreurs propres à ce chemin

| Code | HTTP | Cause |
| --- | --- | --- |
| `SUBJECT_TYPE_NOT_ALLOWED` | 403 | La clé ne porte pas ce `subject_type`, ou le type n'est pas servi par l'API externe |
| `PAYER_TYPE_NOT_ALLOWED` | 422 | `payer_type` désigne un compte de la plateforme |
| `PAYMENT_ALREADY_PENDING` | 409 | Une charge ou une intention vit déjà sur ce sujet |
| `INVALID_MSISDN` | 422 | Numéro invalide ou opérateur non reconnu |
| `PROVIDER_UNAVAILABLE` | 503 | Aucun agrégateur ne couvre cet opérateur, ou tous ont refusé |

`SUBJECT_TYPE_NOT_ALLOWED` couvre deux causes sous un seul code, délibérément :
deux réponses distinctes permettraient d'énumérer les types servis par la
plateforme.

`PAYMENT_ALREADY_PENDING` est le garde-fou anti-triple-clic, appliqué par un
index unique en base. Le produit doit le traiter comme une **information** — « un
paiement est déjà en cours » — et non comme une erreur à réessayer.

Sur `PROVIDER_UNAVAILABLE`, la charge déclarée est **close**, pas laissée en
attente : elle bloquerait sinon toute nouvelle tentative sur cet objet. Aucun
webhook n'est émis — le refus est déjà dans la réponse, et le livrer une seconde
fois ferait traiter deux fois le même échec.

---

# 4. Apprendre l'issue

**Les trois mécanismes sont obligatoires.** C'est la même raison qui fait que
Payments ne croit pas les callbacks des agrégateurs : un webhook se perd.

## 4.1 Webhook sortant

L'URL et le secret sont déclarés par un opérateur de la plateforme, avec
`payments:endpoint` — voir § 5. Ils ne passent pas par l'API : une clé fuitée ne
doit pas suffire à détourner l'issue de tous les paiements d'un produit.

```http
POST https://learn.sekuu.com/webhooks/payments
X-Sekuu-Signature: v1=<hmac-sha256 du corps brut>
X-Sekuu-Event-Id: evt_01j…
X-Sekuu-Delivery-Attempt: 1
```

```json
{
  "id": "evt_01j…",
  "type": "payment.succeeded",
  "occurred_at": "2026-08-04T09:14:00Z",
  "request_id": "req_…",
  "data": {
    "charge_id": "…",
    "payment_id": "…",
    "subject_type": "learn.enrollment",
    "subject_id": "…",
    "payer_type": "learn.learner",
    "payer_id": "…",
    "amount": 15000,
    "currency": "XAF",
    "currency_exponent": 0,
    "formatted": "15 000 XAF",
    "status": "paid"
  }
}
```

Trois obligations pour le produit :

**Vérifier la signature** sur le corps **brut**, en comparaison à temps
constant. Une signature calculée sur le corps désérialisé ne vérifie pas ce qui
a transité.

**Dédupliquer sur `id`.** La livraison est « au moins une fois ». Un même
événement peut arriver deux fois, et deux événements distincts peuvent arriver
dans le désordre — c'est ce qu'ont fait les agrégateurs en conditions réelles.

**Répondre `2xx` rapidement.** Le traitement long se fait après.

### Réessais

1 min, 5 min, 30 min, 2 h, 6 h — six tentatives au total, la même cadence que
Notify. Une seule cadence pour toute la plateforme : deux barèmes différents
pour le même problème n'apprendraient rien de plus à personne.

**Un endpoint durablement injoignable n'est pas désactivé.** La livraison passe
en `exhausted` et un journal d'erreur est émis, mais l'endpoint reste actif :
le désactiver transformerait une panne de quelques heures en silence permanent,
qu'il faudrait qu'un humain remarque pour le rouvrir. C'est la réconciliation
qui rattrape.

## 4.2 Sondage

```http
GET /api/v1/payments/charges/{charge_id}
Authorization: Bearer sk_live_…
```

Scope `payments.read`. `Retry-After` accompagne la réponse tant que l'issue
n'est pas tranchée.

À utiliser dans deux cas : pendant que le client attend, et pour rattraper ce
que le webhook n'a pas livré.

`expired` signifie **on ne sait pas**, et non « cela a échoué ». Un paiement
dont l'issue est inconnue peut avoir été encaissé : le traiter comme un échec
risquerait de facturer deux fois.

## 4.3 Réconciliation

```http
GET /api/v1/payments/charges?since=2026-08-01T00:00:00Z&status=paid
```

Les 200 charges les plus récentes de ce produit, pour comparer avec ce qu'il a
enregistré.

C'est le seul filet quand le webhook et le sondage ont tous deux échoué. À
passer **périodiquement**, pas seulement en cas d'incident : son intérêt est de
révéler ce dont on ignore l'existence.

---

# 5. Déclarer l'endpoint, et faire tourner son secret

```bash
php artisan payments:endpoint <organization-id> --url=https://learn.sekuu.com/webhooks/payments
```

Le secret n'est affiché **qu'une fois**, à la création. Il n'est jamais
relisible par l'API.

## 5.1 Rotation sans coupure

```bash
php artisan payments:endpoint <organization-id> --rotate --window=48
```

Pendant la fenêtre, chaque livraison porte **deux** signatures, séparées par une
virgule :

```text
X-Sekuu-Signature: v1=<nouveau>,v1=<ancien>
```

Le produit accepte celle qu'il reconnaît, et change son secret quand il veut.
Aucun message n'est rejeté entre-temps, et la plateforme n'a pas à savoir quand
le déploiement a eu lieu.

Une coupure nette aurait été plus simple à écrire, et aurait fait échouer toutes
les livraisons d'un produit qui déploie une heure plus tard — c'est-à-dire des
clients payés sans service.

Passé le délai, l'ancien secret cesse de signer de lui-même.

## 5.2 Suspendre

```bash
php artisan payments:endpoint <organization-id> --pause
```

Les livraisons **s'accumulent**, elles ne se perdent pas. Utile pendant une
migration côté produit.

---

# 6. Ce que le produit ne doit jamais faire

**Ne pas relancer un paiement échoué automatiquement.** C'est contourner la
règle de bascule, qui existe pour ne pas débiter deux fois. Un nouveau paiement
est une **action du client**, jamais un réessai du code.

**Ne pas croire le corps d'un webhook sur parole pour un montant.** Il est
signé, donc authentique — mais en cas d'écart avec ce qui était attendu, c'est
un signal d'incident, pas une valeur à enregistrer.

**Ne pas exposer la clé d'API au client final.** Voir § 2.2 : c'est la seule
erreur qui casse tout le modèle d'un coup.

**Ne pas traiter `expired` comme un échec.** Voir § 4.2.

**Ne pas se contenter du webhook.** Voir § 1.1.

---

# 7. Brancher un produit

Trois actes, tous côté plateforme :

1. Une ligne dans `config/payments.php` :
   `'learn.enrollment' => ExternalPayable::class`.
2. Une clé d'API portant `payments.charge`, `payments.read` et la liste des
   `subject_types`.
3. Un endpoint déclaré par `payments:endpoint`.

Rien de tout cela ne s'obtient par l'API. C'est délibéré : chacun de ces trois
actes élargit ce qu'un produit peut faire encaisser.

---

# 8. Ce qui n'existe toujours pas

**Le remboursement.** `refund` est déclaré au registre de caisse et écrit nulle
part. Un produit vendant des formations le rencontrera avant Billing — c'est la
prochaine lacune à combler.

**Le reversement à un tiers.** `payee_organization_id` existe sur l'intention,
mais rien derrière n'est construit : pas de compte de destination transmis à
l'agrégateur, pas de type `payout`, pas d'état de reversement. Un produit
externe encaisse donc **pour le compte de la plateforme**, et le reversement se
fait hors système.
