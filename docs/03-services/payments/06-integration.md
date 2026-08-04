# Sekuu Payments — Intégrer un produit

> **Version :** 1.0
> **Statut :** Guide d'intégration
> **Dernière mise à jour :** Août 2026

Ce document répond à une seule question : **que doit faire un produit pour encaisser via Payments ?**

Quatre choses. Trois sont mécaniques ; la deuxième contient tout le sujet.

> ### Ce guide suppose un produit **dans le monolithe**
>
> Il décrit l'implémentation d'une interface PHP et un enregistrement dans
> `config/payments.php` — donc un module de `Modules/`, comme Billing.
>
> **Un service externe, qui ne consomme que l'API HTTP, ne peut rien faire de
> tout cela.** Son intégration passe par [07-external-api.md](07-external-api.md) :
> il déclare son prix par une clé scopée au lieu de l'exposer par une interface,
> et reçoit l'issue par webhook au lieu de la recevoir dans la transaction.
>
> Les deux chemins n'offrent donc pas les mêmes garanties : **un module interne
> obtient l'atomicité, un service externe non.**

---

# 1. Décrire ce que vous vendez

Un type d'objet payable, au format `{module}.{ressource}` — la même convention que les événements de domaine.

```text
learn.enrollment      une inscription à une formation
learn.subscription    un abonnement à un parcours
stock.order           une commande
```

Payments ne l'interprète **jamais**. Il le porte, l'indexe, et le remet à votre résolveur.

Le couple `(subject_type, subject_id)` doit désigner **exactement une chose payable**. C'est ce qui garantit le garde-fou anti-triple-clic : un index unique partiel interdit deux paiements vivants sur le même sujet. Si deux inscriptions distinctes partagent le même `subject_id`, ce garde-fou tombe.

---

# 2. Implémenter `PayableSource`

C'est l'essentiel du travail, et les trois méthodes n'ont pas le même poids.

```php
namespace Modules\Learn\Application\Payments;

use App\Platform\Contracts\{PayableQuote, PayableRef, PayableSource, PayerContext, PaymentSettlement};
use App\Platform\Support\Money;

final class EnrollmentPayable implements PayableSource
{
    public const TYPE = 'learn.enrollment';

    public function quote(PayableRef $ref, PayerContext $payer): PayableQuote
    {
        $inscription = Enrollment::query()->find($ref->id);

        if ($inscription === null) {
            return PayableQuote::refused('ENROLLMENT_NOT_FOUND', __('learn::messages.enrollment_not_found'));
        }

        // Ce payeur a-t-il le droit de régler cet objet ?
        if ($payer->id !== $inscription->learner_id) {
            return PayableQuote::refused('ENROLLMENT_NOT_FOUND', __('learn::messages.enrollment_not_found'));
        }

        if ($inscription->isPaid()) {
            return PayableQuote::nothingDue();
        }

        return PayableQuote::due(
            Money::of($inscription->price, 'XAF'),
            'Sekuu Learn — '.$inscription->course->title,
            payeeOrganizationId: null,
        );
    }

    public function settled(PaymentSettlement $settlement): void
    {
        $inscription = Enrollment::query()->lockForUpdate()->find($settlement->subject->id);

        if ($inscription === null || $inscription->isPaid()) {
            return;   // idempotente : ce règlement peut arriver deux fois
        }

        $inscription->markPaid($settlement->amount);
    }

    public function failed(PaymentSettlement $settlement): void
    {
        // Prévenez votre client dans vos propres termes.
    }
}
```

## 2.1 `quote()` — pourquoi c'est vous qui donnez le prix

**Payments ne reçoit jamais de montant.** `InitiatePayment::handle()` n'a aucun paramètre pour en passer un.

Ce n'est pas une élégance : c'est ce qui empêche de régler une facture de 49 663 XAF avec 100 XAF. Une méthode `encaisser(int $montant)` déplacerait la protection d'un invariant vers une convention, et le premier appelant écrirait `$request->integer('amount')`.

Corollaire pour vous : **ne lisez jamais le montant depuis la requête HTTP.** Chargez votre objet et lisez son prix. Si vous prenez un montant de l'extérieur pour le renvoyer dans `due()`, vous rouvrez exactement le trou que ce contrat ferme.

**`quote()` porte aussi l'autorisation.** Payments ne peut pas trancher qui a le droit de payer quoi — il ne sait rien de vos rôles. Sans votre contrôle, connaître un identifiant suffirait à déclencher une invite sur le téléphone de quelqu'un d'autre.

Renvoyez le **même refus** pour « inexistant » et « pas à vous ». Deux messages différents transforment l'endpoint en oracle : on énumère les identifiants valides.

`quote()` doit être **sans effet de bord et idempotente** : elle est appelée à chaque demande d'encaissement, y compris sur un objet déjà réglé.

## 2.2 `settled()` — appelée dans la transaction

Pas par un événement, et c'est délibéré : confier ce moment à une file créerait une fenêtre où l'argent est encaissé et le service fermé, qu'un consommateur en échec définitif rendrait permanente.

Trois conséquences pour votre implémentation :

**Elle doit être idempotente.** Un callback puis un sondage peuvent régler le même paiement deux fois. Verrouillez votre objet, et sortez sans rien faire s'il est déjà payé.

**Elle doit être brève.** Vous êtes dans la transaction d'encaissement. N'y envoyez pas d'email, n'y appelez pas d'API tierce — publiez un événement, et laissez Notify travailler après.

**Le montant est celui de l'intention**, pas celui rapporté par l'agrégateur. Ce dernier est un constat, jamais une autorité : chez un agrégateur qui authentifie ses callbacks par un secret partagé plutôt que par une signature, croire le montant reçu serait une faille.

## 2.3 `failed()` — pourquoi elle existe

Pour que vous préveniez votre client dans vos termes, et publiiez **vos** événements.

Notify associe les événements aux templates par un tableau littéral. Un événement générique publié par Payments ne tomberait dans aucune correspondance — sans exception ni journal. Le message d'échec disparaîtrait en silence, au moment précis où le client est le plus susceptible de recommencer.

---

# 3. S'enregistrer

Une ligne dans `config/payments.php` :

```php
'payables' => [
    InvoicePayable::TYPE => InvoicePayable::class,
    EnrollmentPayable::TYPE => EnrollmentPayable::class,
],
```

C'est **le seul endroit** où Payments apprend que votre module existe. Aucun de ses fichiers ne vous importe, et un test d'architecture le vérifie.

Un type absent échoue durement (`PAYABLE_TYPE_UNKNOWN`). Un repli silencieux ferait aboutir un paiement que personne ne saurait rattacher : de l'argent encaissé sans service rendu.

---

# 4. Exposer votre route de paiement

Payments n'en expose aucune, et n'en exposera pas : déclencher un paiement suppose de savoir ce qu'on paie, combien cela vaut et qui peut le régler.

```php
public function __invoke(PayEnrollmentRequest $request, InitiatePayment $payments): JsonResponse
{
    $intent = $payments->handle(
        subject: new PayableRef(EnrollmentPayable::TYPE, $request->string('enrollment_id')->toString()),
        payer: PayerContext::user($this->userId()),
        rawMsisdn: $request->string('msisdn')->toString(),
        idempotencyKey: $request->header('Idempotency-Key'),
    );

    return ApiResponse::success([...], status: 202);
}
```

**`202` et non `201`** : ce qui est créé est une *intention*. Le client sonde ensuite `GET /api/v1/payments/{id}`, qui appartient à Payments et fonctionne pour tous les produits.

## 4.1 Choisir le payeur

| | Quand |
| --- | --- |
| `PayerContext::user($userId)` | Une personne achète pour son propre compte |
| `PayerContext::organization($orgId, $userId)` | Une organisation paie ; `$userId` est celui qui a cliqué |

L'idempotence est **scopée au payeur**. Deux produits peuvent utiliser la clé `order-1` sans se voler mutuellement leur intention — mais à l'intérieur d'un même payeur, la clé doit être unique.

## 4.2 Si un tiers encaisse

`PayableQuote::due(..., payeeOrganizationId: $centreDeFormation->id)`.

**Sachez que rien n'est construit derrière.** Le bénéficiaire est enregistré, mais aucun compte de destination n'est transmis à l'agrégateur, il n'existe pas de type `payout` au registre, ni d'état de reversement. Le reversement reste à faire à la main, et la commission est comptée comme une charge de la plateforme.

---

# 5. Ce que vous n'avez pas à faire

Payments s'en charge, et vous ne devez pas le refaire.

| | |
| --- | --- |
| Choisir l'agrégateur | Déduit du numéro et de l'ordre de priorité |
| Réessayer après un échec | **Surtout pas** — la règle de bascule est délibérément étroite, la contourner double-débite |
| Sonder l'agrégateur | `payments:reconcile` toutes les 5 minutes |
| Recevoir les callbacks | Un endpoint unique, par agrégateur |
| Empêcher le double paiement | Index unique en base, sur `(subject_type, subject_id)` |
| Enregistrer l'encaissement | Registre de caisse, avec le brut **et** la commission |

**Un avertissement qui vaut d'être lu deux fois.** Si un paiement échoue et que vous relancez `handle()` automatiquement, vous contournez la règle de bascule — celle qui existe précisément pour ne pas débiter un client deux fois. Un nouveau paiement doit être une **action du client**, jamais un réessai du code.

---

# 6. Vérifier votre intégration

Le module fournit de quoi tester sans agrégateur ni réseau : `Modules\Payments\Tests\Support\FakeProvider` et ses deux implémentations, dont on contrôle exactement l'issue.

[`PaymentWithoutBillingTest`](../../../Modules/Payments/Tests/Feature/PaymentWithoutBillingTest.php) est un modèle directement copiable : il éprouve le parcours complet sur un `learn.enrollment` fictif, sans qu'aucune ligne n'existe dans `invoices`.

Quatre choses à couvrir de votre côté :

1. `quote()` refuse un objet qui n'appartient pas au payeur — avec le **même** message que « inexistant ».
2. `quote()` renvoie `nothingDue()` sur un objet déjà réglé, et non un montant nul.
3. `settled()` appelée deux fois ne règle l'objet qu'une fois.
4. Le montant provient de votre objet, et non de la requête. Un test qui envoie un `amount` dans le corps et vérifie qu'il est ignoré vaut mieux qu'une relecture.
