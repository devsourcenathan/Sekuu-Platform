# Plan de migration — extraction de `Modules/Payments` hors de `Modules/Billing`

---

## 1. VERDICT

**L'extraction est raisonnable, mais elle n'est pas ce qu'on croit qu'elle est.**

Le travail utile n'est pas le déplacement de fichiers. Sur les ~73 fichiers concernés, une large majorité (toute l'infrastructure agrégateurs et webhooks, `Msisdn`, `AttemptStatus`, `PaymentAttempt`, `ProviderEvent`, `ProviderRegistry`, `WebhookRegistry`, les quatre adaptateurs, `ReconcilePayments`) n'a **aucune** dépendance vers `Invoice`, `Subscription` ou `Plan`. Ces fichiers se déplacent par `git mv`, c'est mécanique et sans risque.

Le vrai chantier tient en quatre points, tous vérifiés dans le code :

1. `InitiatePayment::handle()` prend un `Invoice` en premier paramètre (ligne 40) et en tire le montant (`$invoice->outstanding()`, ligne 63), le libellé envoyé au téléphone du client (`$invoice->number`, ligne 94), l'`organization_id`, l'`invoice_id` et la `currency` (lignes 140-143).
2. `SettlePayment` écrit en retour dans les tables de Billing : `markInvoicePaid()` (lignes 148-184) charge `Invoice::lockForUpdate()`, écrit `amount_paid`/`status`/`paid_at`, publie `billing.invoice.paid` et appelle `ActivateSubscription::fromInvoice()`.
3. La migration `2026_03_01_000300_create_payments_tables.php` porte **trois** vraies clés étrangères qui deviendront inter-modules : `payment_intents.invoice_id → invoices` (l. 20-21), `transactions.payment_intent_id → payment_intents` (l. 124-125), `transactions.payment_attempt_id → payment_attempts` (l. 126-127).
4. L'index partiel `payment_intents_one_alive_per_invoice` (l. 58-62) — le garde-fou anti-triple-clic — porte sur `invoice_id` **et exclut explicitement les intentions sans facture** (`AND invoice_id IS NOT NULL`). C'est-à-dire exactement le cas nominal de Sekuu Learn.

### Alternative moins coûteuse, à considérer sérieusement

**Option « découplage sans déménagement »** : faire les points 1 à 4 ci-dessus (généraliser le schéma, scinder `SettlePayment`, introduire le contrat, renommer la config) **sans créer `Modules/Payments`**. Le code reste dans `Modules/Billing`, mais Learn pourrait déjà l'appeler.

- Pour : 80 % de la valeur, aucun risque de namespace, aucun `openapi.yaml` à dupliquer (`tests/Feature/OpenApiContractTest.php` fait échouer la suite dès la création d'un dossier `Modules/*` sans contrat), aucune reconfiguration de webhook chez NotchPay/Tranzak, aucun renommage d'événement.
- Contre, décisif : rien n'empêche mécaniquement la re-fusion. Il n'existe aujourd'hui **aucun test d'architecture** dans `tests/`. Six mois plus tard, un `use Modules\Billing\Domain\Models\Invoice;` réapparaît dans le code de paiement et personne ne le voit. Et Learn devrait dépendre du module `Billing` pour encaisser, ce qui rend l'objectif inatteignable en pratique.

**Ma recommandation :** faire l'extraction complète, mais dans cet ordre — *découpler d'abord, déplacer ensuite*, en commits séparés, avec les tests d'architecture écrits **avant** (ils échouent, ce qui documente la dette). Le déplacement physique n'est pas le but, c'est le verrou qui empêche le retour en arrière.

**Une chose à ne pas faire :** ne pas déclencher ce chantier « parce que Learn arrive » sans avoir tranché la section 3. Le point 4 (l'index partiel) est le seul endroit où une approximation coûte de l'argent réel à un tiers, et il se répare mal après coup.

**Circonstance favorable :** le projet n'est déployé nulle part (aucune CI, aucun Dockerfile, aucun tag, `APP_ENV=local`, `docs/RECAP.md` §8.1 liste encore les bloquants de mise en production). On peut donc **réécrire les migrations en place** plutôt qu'empiler des migrations d'altération sur une table `append-only`. C'est un luxe qui disparaîtra au premier client réel.

---

## 2. FRONTIÈRE

### Partent dans `Modules/Payments` sans modification de fond

| Fichier | Note |
|---|---|
| `Domain/AttemptStatus.php` | `allowsFailover()`, `needsPolling()`, `isTerminal()` — zéro concept de facturation |
| `Domain/Msisdn.php` | normalisation E.164, déduction d'opérateur ; lit `config('billing.default_country')` → à rebrancher |
| `Domain/Models/PaymentAttempt.php` | y compris `allowsFailover()` (statut **et** `! customer_prompted`) |
| `Domain/Models/ProviderEvent.php` | corps brut des callbacks, pièce de preuve en cas de litige |
| `Infrastructure/Providers/PaymentProvider.php`, `ChargeRequest.php`, `ChargeOutcome.php`, `ProviderRegistry.php`, `NotchPayProvider.php`, `TranzakProvider.php` | aucun `use` vers Invoice/Subscription/Plan |
| `Infrastructure/Webhooks/` (4 fichiers) | idem |
| `Infrastructure/Console/ReconcilePaymentsCommand.php` | signature `billing:reconcile` → `payments:reconcile` |
| `Presentation/Http/Controllers/WebhookController.php` | ne connaît que `provider_events`, `PaymentAttempt` et le re-sondage |
| `Application/Payments/ReconcilePayments.php` | quasi pur : seuls `billing.payment.unresolved` et `invoice_id` dans la charge utile sont à généraliser |
| `Tests/Feature/{NotchPay,Tranzak}{Provider,Webhook}Test.php` | **à déplacer à l'octet près** : charges utiles capturées contre les bacs à sable, non reproductibles |
| `Tests/Support/{FakeProvider,FakeWebhookHandler,PrimaryProvider,SecondaryProvider}.php` | tout le double d'agrégateur |

### Restent dans `Modules/Billing` sans discussion

`Application/Invoicing/{InvoiceNumber,IssueInvoice}.php` · `Application/Notifications/AddressesTheOrganization.php` · `Application/Subscriptions/*` (5 fichiers) · `Domain/SubscriptionStatus.php` · `Domain/Models/{Invoice,InvoiceLine,Plan,PlanPrice,PlanProduct,Subscription}.php` · migrations `000100`, `000200`, `000400` · `Infrastructure/Console/AdvanceLifecycleCommand.php` · `Infrastructure/Contracts/BillingGateway.php` · `Presentation/Http/Controllers/{Invoice,Plan,Subscription}Controller.php` · `Requests/{ChangePlan,Subscribe}Request.php` · `Tests/Feature/{PlanQuota,SubscriptionLifecycle,BillingNotifications}Test.php`

### À scinder — c'est là que se concentre le travail

| Fichier | Part | Reste |
|---|---|---|
| `Application/Payments/SettlePayment.php` | `applyToAttempt()` (l. 37-56, dont le non-rétrogradage l. 44), la dérivation de statut d'intention (l. 64-82) | `recordSuccess()` (l. 107-141), `markInvoicePaid()` (l. 148-184), la branche `FAILED` avec `addressed(..., withPhone: true)` (l. 88-101) |
| `Application/Payments/InitiatePayment.php` | idempotence, rattrapage `23505` sur SAVEPOINT (l. 135-171), `merchant_reference` généré **avant** l'appel (l. 85), la boucle `ProviderRegistry` (l. 70-126), `PROVIDER_UNAVAILABLE` | signature `handle(Invoice …)`, gardes `INVOICE_VOIDED`/`INVOICE_ALREADY_PAID` (l. 45-51), `$invoice->outstanding()` (l. 63) |
| `Application/Ledger/CreditLedger.php` | `settle()` (l. 69-98) — seule méthode qui touche `PaymentAttempt` | `balance()`, `credit()`, `consume(Invoice)` |
| `Domain/Models/Transaction.php` | types `charge`, `fee`, `refund` | `credit`, `debit`, `adjustment` (déjà isolés par `creditTypes()`) |
| `Domain/Models/PaymentIntent.php` | le modèle entier | mais `invoice_id` + `belongsTo(Invoice)` disparaissent ; `Invoice::intents()` se supprime |
| `Database/Migrations/2026_03_01_000300_*.php` | `payment_intents`, `payment_attempts`, `provider_events` | `transactions` (partiellement — voir §3) |
| `Presentation/…/PaymentController.php` | `show()`, `index()`, l'en-tête `Retry-After` | `store()` (résolution d'organisation, `requireBillingRole`, chargement d'`Invoice`) |
| `Presentation/…/CreatePaymentRequest.php` | `msisdn`, `method` | `invoice_id` |
| `Presentation/…/HealthController.php` | `providers` + `can_collect` | route de santé Billing |
| `Presentation/Support/ResolvesOrganization.php` | — | **à promouvoir dans `app/Platform/`**, pas à dupliquer |
| `BillingServiceProvider.php` | singletons `ProviderRegistry` et `WebhookRegistry`, planification `everyFiveMinutes()` | binding `BillingContract → BillingGateway`, `AdvanceLifecycle` à 02h30 |
| `Routes/api_v1.php` | `billing/webhooks/{provider}`, les 3 routes `payments` | plans, subscription, invoices |
| `Resources/lang/{en,fr}/messages.php` | `payment_*`, `provider_*`, `webhook_*`, `invalid_msisdn`, `charge_description`, `payment_received`, `provider_fee`, `currency_*` | `plan_*`, `subscription_*`, `invoice_*`, `credit_applied_to_invoice`, `proration_credit`, `billing_role_required` |
| `config/billing.php` | `providers`, `webhooks`, `notchpay.*`, `tranzak.*`, `operators`, `payment.*`, `default_country` (volet opérateur) | `tax.CM`, `grace_days`, `expire_after_days`, `reminder_days`, `default_country` (volet TVA) |
| `Tests/Concerns/BillsAnOrganization.php` | `useFakeProviders()` → `Modules/Payments/Tests/Concerns/UsesFakeAggregators.php` | `subscribe()`, `expirePeriod()` ; **`signInAsOwner()` monte dans `tests/Concerns/SignsInAsOwner.php`** |
| `Tests/Feature/{PaymentFailover,RealFailover}Test.php` | les 10 cas | leur fixture, à reconstruire sur un motif neutre |
| `Tests/Feature/WebhookAndReconciliationTest.php` | 5 cas sur 7 | `test_polling_alone_settles_a_payment`, `test_the_callback_body_never_decides_the_outcome` |

**Attention particulière :** `signInAsOwner()` doit sortir de `BillsAnOrganization` avant tout déplacement de test. Sinon `Modules/Payments/Tests` importe `Modules\Billing\Tests\Concerns\BillsAnOrganization` et le module extrait dépend de Billing dès sa première ligne de test.

### Hors module

`app/Platform/Contracts/PaymentsContract.php` (nouveau) · `app/Platform/Support/Money.php` (promu) · `bootstrap/providers.php` (ajouter `PaymentsServiceProvider` **avant** `BillingServiceProvider`) · `config/sekuu.php` (`SEKUU_DOMAIN_PAYMENTS`) · `.env.example` · `phpunit.xml` · `tests/Feature/{TestEnvironmentIsolation,OpenApiContract,Localisation}Test.php` · `Modules/Notify/Application/Events/DomainEventSubscriber.php` (ligne 53) · `docs/01-overview/{architecture,vision}.md` · `docs/03-services/payments/` (nouveau)

---

## 3. DÉCISIONS À TRANCHER

### 3.1 Le registre `transactions`

**Trois options réelles.**

| | A — tout reste Billing | B — deux registres | C — tout part dans Payments |
|---|---|---|---|
| Payments écrit | rien | `charge`, `fee`, `refund` | tout |
| Billing écrit | tout | `credit`, `debit`, `adjustment` | rien (lit par contrat) |
| Relevé complet | 1 requête | 2 requêtes | 1 requête |
| FK inter-modules | **oui**, `transactions.payment_intent_id` (l. 124-127) | non | non |
| Learn utilisable | non (Billing stockerait des lignes qu'il ne facture pas) | oui | oui |
| Solde de crédit | naturel | naturel | Payments stockerait `credit`/`adjustment` sans savoir les expliquer |

**Recommandation : option B.** La couture existe déjà dans le code — `Transaction::creditTypes()` retourne exactement `[credit, debit, adjustment]` et exclut `charge` et `fee`. La table est déjà deux registres colocalisés ; la scission ne crée pas la frontière, elle la rend physique. Et `CreditLedger::settle()` (l. 69-98) est aujourd'hui une classe Billing qui lit `$attempt->intent`, un modèle qui va devenir Payments : cette méthode doit partir, sinon elle devient un appel inter-modules.

**Coût à assumer :**
- Le caractère `append-only` (`Transaction::booted()` qui interdit `updating`/`deleting`, absence d'`updated_at`) doit être **répliqué des deux côtés**. C'est une propriété du registre, pas du module.
- Le rapprochement bancaire d'une organisation lira deux tables. Le lien reste `payment_intent_id`, conservé en référence logique côté Billing.
- **Idempotence obligatoire côté Billing** : unicité sur `(invoice_id, payment_intent_id)` dans le registre de crédit. Sans elle, un événement rejoué crédite deux fois.
- Le `CHECK` `transactions_type_check` (l. 139-140) se scinde en deux contraintes réduites.

**À trancher au passage, pendant qu'on y est :** `refund` est déclaré dans le `CHECK` et dans le modèle, écrit **nulle part**, et absent de `creditTypes()` — donc un remboursement écrit aujourd'hui serait invisible du solde de crédit. Décision recommandée : un remboursement Mobile Money réel (l'argent quitte le compte marchand) est un fait de Payments ; un geste commercial sans mouvement de caisse est un `credit` Billing. Réserver la place maintenant coûte une ligne de documentation ; la découvrir après coup oblige à réinterpréter des données monétaires existantes.

### 3.2 Le rattachement d'un paiement à un objet métier

**Recommandation : couple `subject_type` / `subject_id`, tous deux NOT NULL, sans clé étrangère.**

```
subject_type varchar(40)  -- 'billing.invoice', 'learn.enrollment'
subject_id   uuid
```

`subject_type` suit la convention `{module}.{ressource}` déjà en vigueur pour les événements de domaine. Payments ne l'interprète jamais : il le porte, l'indexe, et le passe à un résolveur.

**Le point critique, et c'est le plus important de tout le document :** l'index partiel doit être recréé sur ce couple, **sans clause d'exclusion** :

```sql
CREATE UNIQUE INDEX payment_intents_one_alive_per_subject
  ON payment_intents (subject_type, subject_id)
  WHERE status IN ('pending','processing');
```

Rendre les deux colonnes NOT NULL n'est pas de la coquetterie : c'est ce qui supprime le `AND invoice_id IS NOT NULL` de la version actuelle (l. 61), et donc ce qui étend le garde-fou anti-triple-clic aux paiements sans facture. Aujourd'hui **un paiement sans facture n'a aucune protection d'unicité** — trou fonctionnel préexistant, sans conséquence tant qu'`InitiatePayment` exige une `Invoice`, et qui devient le cas nominal de Learn.

**Bon signe :** la régression, si elle se produit, sera bruyante. `Modules/Billing/Tests/Feature/PaymentFailoverTest.php::test_a_second_payment_on_the_same_invoice_is_refused` attend un 409 et `PAYMENT_ALREADY_PENDING` ; `InitiatePayment::createIntent()` traduit explicitement la violation `23505` en conflit métier (l. 153-171). Ce test doit être doublé d'une version « sans facture » dans Payments, sinon la protection Learn reste, elle, non couverte.

**Résolution `type → module` :** par table de configuration, pas par appel croisé.

```php
// config/payments.php
'payables' => [
    'billing.invoice'   => \Modules\Billing\Application\Payments\InvoicePayable::class,
    'learn.enrollment'  => \Modules\Learn\Application\Payments\EnrollmentPayable::class,
],
```

Résolu par le conteneur, dans l'esprit de `ProviderRegistry`. Aucun `use` de Payments vers Billing, aucun de Billing vers Payments. Un type absent échoue durement (`PAYABLE_TYPE_UNKNOWN`), jamais par repli silencieux.

**Question laissée ouverte volontairement — l'identité du payeur.** Trois formes se défendent :
1. `organization_id` devient nullable (les lectures HTTP scopées par organisation, `PaymentController` l. 33/59/89, doivent alors être repensées, et `DomainEvent::$organizationId` sera nul).
2. `organization_id` désigne **qui encaisse** (le vendeur), et un couple `payer_type`/`payer_id` séparé désigne **qui paie**. Tous les index existants survivent.
3. Learn crée une organisation implicite par apprenant.

**Recommandation : option 2.** C'est le seul découpage où « qui paie » et « qui encaisse » ne sont pas confondus — et c'est aussi le seul qui laisse une porte ouverte vers le reversement à un tiers (voir §6). Mais c'est une décision produit, pas technique : **elle doit être prise avant la première migration**, car c'est la seule erreur de ce chantier qui obligerait à remigrer des données monétaires.

Conséquence à assumer sur `AddressesTheOrganization` : le trait appelle `IdentityContract::billingContact($organizationId)` et **retourne silencieusement** si `$organizationId === null` (pas même le `Log::warning`). Un apprenant Learn ne recevrait donc aucun SMS d'échec de paiement, sans erreur. Il faut soit une méthode `contactFor(payerType, payerId)` sur `IdentityContract`, soit un contact porté par le résolveur au moment de l'initiation.

### 3.3 « Le montant ne vient jamais de l'appelant »

**C'est la règle la plus facile à perdre en traduction, et la tentation naturelle est le mauvais choix.**

Aujourd'hui la protection est **structurelle, pas conventionnelle** : `CreatePaymentRequest` n'a pas de champ `amount`, le contrôleur charge l'`Invoice` en base par id scopée à l'organisation (`PaymentController` l. 32-35), et `InitiatePayment` lit `$invoice->outstanding()` (l. 63). Aucun nombre ne traverse jamais HTTP. Le commentaire l. 61-62 dit l'enjeu : « l'accepter du corps permettrait de régler 49 663 XAF avec 100 XAF ».

**À rejeter — `collect(int $amount, ...)`.** Fait passer le contrôle d'un invariant structurel à une convention. Le premier contrôleur Learn écrira `$request->integer('amount')`. La faille exacte, déplacée d'une couche.

**À rejeter aussi — un DTO `PaymentSubject` portant `amount`.** Piège subtil : un objet de valeurs construit par l'appelant est **plus** falsifiable qu'un modèle Eloquent chargé côté serveur, pas moins. On croit avoir typé la sécurité, on a juste typé le trou.

**Recommandation — inverser la question. Payments ne reçoit pas le montant, il va le demander.**

```php
// app/Platform/Contracts/PayableSource.php
interface PayableSource
{
    /** Sans effet de bord, idempotente : appelée à chaque demande d'encaissement. */
    public function quote(PayableRef $ref, PayerContext $payer): PayableQuote;

    public function settled(PaymentSettlement $settlement): void;
    public function failed(PaymentSettlement $settlement): void;
}

// trois issues, dans l'esprit de PlanLimit déjà présent dans app/Platform/Contracts/
PayableQuote::due(int $amount, string $currency, string $description);
PayableQuote::nothingDue();
PayableQuote::refused(string $code, string $message);
```

Ce que cela préserve, point par point :

1. **Le montant est indicible.** Il n'existe dans aucune signature accessible à l'appelant. On ne *peut pas* passer 100 XAF : il n'y a pas de paramètre pour le faire.
2. **L'autorité vient de la propriété.** Billing répond pour `billing.invoice` avec `$invoice->outstanding()` — la ligne 63 actuelle, déplacée de deux fichiers.
3. **L'autorisation est au même endroit que le prix.** `quote()` reçoit le `PayerContext` : le propriétaire refuse un objet que ce payeur n'a pas le droit de régler. Sans cela, connaître un UUID de facture suffirait à déclencher une invite sur un téléphone. Payments ne peut pas trancher cette question — il ne sait rien des rôles. C'est ce qui remplace `requireBillingRole()` (dont le commentaire « engager une dépense n'est pas une action de membre ordinaire » est juste pour une organisation et absurde pour un apprenant qui achète sa propre formation).
4. **Le libellé suit le même chemin.** `description` sort de `PayableQuote::due()`. Billing envoie « Sekuu — facture F-2026-0142 », Learn « Sekuu Learn — Formation X ». Aujourd'hui `InitiatePayment` l. 94 code en dur `__('billing::messages.charge_description', ['number' => $invoice->number])`, chaîne qui part réellement chez NotchPay/Tranzak et atterrit sur le relevé du client. Un apprenant qui lit « facture » en payant une formation est un ticket de support garanti.
5. **Le montant est gelé à la création.** Le client a été invité à payer *ce* montant-là. `recordSuccess()` (l. 109-123) conserve sa logique inchangée : le montant rapporté par l'agrégateur est un constat, jamais une autorité.

**Course résiduelle assumée :** la cotation date de T0, le client valide à T0+3 min, l'objet a pu être soldé entre-temps. Couverte par l'index d'unicité de §3.2 et par un `settled()` idempotent. Billing absorbe le trop-perçu via `CreditLedger`. Learn, qui n'a pas de registre de crédit, doit **échouer bruyamment** plutôt qu'ignorer l'argent.

### 3.4 Annonce du résultat : callback synchrone **et** événement

Tout-événement serait un recul : `ReconcilePayments` nomme déjà la pire défaillance du module — le client a payé et n'a pas son accès. Confier `settled()` à une file crée une fenêtre où l'argent est encaissé et le service fermé, qu'un consommateur en échec définitif rend permanente.

Tout-callback serait un recul aussi : Notify n'a pas à être appelé synchronement dans la transaction d'encaissement.

| Destinataire | Mécanisme | Moment |
|---|---|---|
| Le propriétaire de l'objet (un seul) | `PayableSource::settled()` / `failed()` | synchrone, **dans** la transaction |
| Tous les autres | `DomainEvent` | après commit (`afterCommit`, ou un outbox) |

`InvoicePayable::settled()` reprend mot pour mot ce que fait `markInvoicePaid()` aujourd'hui : `CreditLedger`, `billing.invoice.paid`, `ActivateSubscription::fromInvoice()`. Le couplage n'est pas supprimé, il est **rapatrié chez celui à qui il appartient**.

**Piège à ne pas manquer :** `Modules/Notify/Application/Events/DomainEventSubscriber.php` ligne 53 mappe `'billing.payment.failed' => 'payment.failed'` par tableau littéral. Si Payments publie `payments.payment.failed`, la correspondance ne tombe plus — **aucune exception, aucun log**, le SMS d'échec disparaît en silence, au moment que `SettlePayment` identifie lui-même comme celui où le client est le plus susceptible de recommencer. C'est pour cela que `failed()` doit exister sur l'interface et pas seulement `settled()` : Billing republie `billing.payment.failed` enrichi du destinataire, et la carte de Notify reste correcte.

Les événements de Payments sont **nus** : `payment_id`, `subject_type`, `subject_id`, `amount`, `currency`, `provider`. Pas de `recipient`, pas de `phone`. Payments n'appelle jamais `IdentityContract`.

`payments.payment.unresolved` conserve `provider_refs` tel quel — seule piste de rapprochement manuel — et il faut lui donner un vrai destinataire : aujourd'hui `billing.payment.unresolved` n'a **aucun consommateur** dans le code, alors que l'ADR-0008 l'annonce comme mitigation.

### 3.5 `Money` et `Msisdn`

- **`Msisdn` part dans Payments.** C'est un objet Mobile Money. Sans discussion.
- **`Money` monte dans `app/Platform/`**, avec sa table d'exposants (`XAF` exponent 0 — le piège du franc CFA documenté dans la classe) déplacée dans une configuration partagée. Le dupliquer créerait deux définitions de l'exposant et un `assertSameCurrency` qui ne protège plus rien à la frontière ; le laisser dans Payments ferait dépendre Billing d'un objet interne d'un autre module. `Invoice::totalMoney()`/`outstanding()`, `PlanPrice`, `CreditLedger`, `IssueInvoice` d'un côté ; `PaymentIntent::money()`, `ChargeRequest`, `ChargeOutcome`, `PaymentAttempt::grossMoney()` de l'autre. Aucun arbitrage possible.

### 3.6 La route de webhook

**Recommandation : nouvelle route `payments/webhooks/{provider}`, ancienne route `billing/webhooks/{provider}` conservée en alias, déclarée dans Payments.**

- Le préfixe est obligatoire : `Modules/Notify/Routes/api_v1.php` ligne 33 déclare `webhooks/{provider}` **sans préfixe**, et `bootstrap/providers.php` enregistre Notify avant Billing. Un `webhooks/{provider}` nu côté Payments enverrait les callbacks d'agrégateurs au `WebhookController` de Notify. Silencieusement.
- L'alias doit vivre dans Payments (le module qui possède désormais le handler), pas dans Billing, sinon il réintroduit la lecture croisée. Et il doit figurer dans `Modules/Payments/openapi.yaml`, sans quoi `OpenApiContractTest::test_every_route_is_documented` échoue.
- `.env` contient `NOTCHPAY_CALLBACK_URL=…/api/v1/billing/webhooks/notchpay`, **figée dans les transactions NotchPay déjà initialisées**, et le tableau de bord NotchPay n'accepte qu'une seule URL pour tous les environnements. Sans alias, la panne est invisible : les paiements aboutissent quand même, constatés par `payments:reconcile` toutes les cinq minutes au lieu de quelques secondes. Personne ne le remarque avant qu'un client se plaigne.
- Critère de retrait de l'alias : **zéro appel observé sur l'ancienne route pendant N jours**, pas une date.

Ajouter aussi `'payments' => env('SEKUU_DOMAIN_PAYMENTS')` dans `config/sekuu.php`. `ModuleServiceProvider::domain()` renvoie `null` quand le sous-domaine est vide, et une route sans contrainte d'hôte répond sur **tous** les hôtes — y compris ceux des autres modules.

---

## 4. ÉTAPES

Chaque étape laisse la suite verte, sauf mention contraire. **Repère de non-régression :** relever le nombre de tests **et** le nombre d'assertions PHPUnit de la suite `Modules` avant l'étape 1, et vérifier que le total ne diminue jamais. Une fixture cassée qui rend un `intent` nul ferait passer des assertions sur du vide.

### Étape 0 — Sauvegarde et filet

- `pg_dump` des quatre tables `payment_intents`, `payment_attempts`, `transactions`, `provider_events` de la base `sekuu`, versionné hors du dépôt. Elles contiennent des paiements sandbox réels du 3 août 2026 : notamment **une ligne `fee −3 XAF` chez Tranzak**, seule trace existante que la séparation brut/net fonctionne de bout en bout (le sandbox NotchPay renvoie toujours `fees: []`), et des `provider_events` à clés de repli `unknown:<sha256>` qui documentent une correction. Un `migrate:fresh` les efface.
- Écrire les tests d'architecture **sur l'état actuel** : (a) aucun fichier de `Modules/Payments/**` ne contient `Modules\Billing\` ; (b) aucune contrainte `FOREIGN KEY` de `payment_intents`/`payment_attempts`/`transactions`/`provider_events` ne pointe vers `invoices` ou `subscriptions`, par interrogation d'`information_schema`. Ils échouent. C'est le but : ils chiffrent la dette avant qu'on la paye.

### Étape 1 — Extraire `signInAsOwner()`

`tests/Concerns/SignsInAsOwner.php`, namespace `Tests\Concerns`. Rien d'autre ne bouge. Suite verte.

### Étape 2 — Promouvoir `Money` et `ResolvesOrganization` dans `app/Platform/`

Déplacer `Money` avec sa configuration de devises. Signaler au passage que `ResolvesOrganization` importe `Modules\Identity\Infrastructure\Auth\JwtUserResolver` — l'infrastructure d'un autre module et non un contrat : entorse préexistante à corriger ici, sinon la duplication vers Payments la transformerait en deux entorses. Suite verte.

### Étape 3 — Généraliser le schéma

Réécrire `2026_03_01_000300` en place (aucun déploiement, donc aucune migration d'altération) :
- `invoice_id` → `subject_type` + `subject_id`, NOT NULL, **sans FK**
- index partiel recréé sur `(subject_type, subject_id)` **sans clause d'exclusion**
- suppression des FK `transactions.payment_intent_id` et `transactions.payment_attempt_id`
- ajout de `payer_type` / `payer_id` selon la décision §3.2

Billing renseigne `'billing.invoice'` + l'id de facture. Le comportement est identique. `test_a_second_payment_on_the_same_invoice_is_refused` doit rester vert — c'est le contrôle qui prouve que le garde-fou a survécu. Ajouter dans la foulée son jumeau : même test sur un `subject_type` fictif sans facture.

**Bases locales `sekuu` et `sekuu_testing` : `migrate:fresh` requis** (renommer un fichier de migration invalide sa ligne dans la table `migrations`). Sans risque, les plans venant de la migration de seed `000400`.

### Étape 4 — Introduire `PayableSource` et couper `SettlePayment` — ⚠️ **étape la plus exposée**

Créer `app/Platform/Contracts/{PayableSource,PayableRef,PayableQuote,PayerContext,PaymentSettlement}.php`. `InvoicePayable` implémente `quote()` (`$invoice->outstanding()`, gardes `INVOICE_VOIDED`/`INVOICE_ALREADY_PAID`) et `settled()`/`failed()` (le contenu actuel de `markInvoicePaid()` et de la branche `FAILED`). Le code reste physiquement dans `Modules/Billing`.

`InitiatePayment::handle()` ne prend plus `Invoice` mais `PayableRef` + `PayerContext`. `SettlePayment` se scinde en `SettleAttempt` (`applyToAttempt`) et `SettleIntent`.

**Pourquoi c'est ici que la règle de bascule est la plus exposée.** Elle n'est pas dans un fichier : elle est dans une redondance délibérée que ces réécritures vont abîmer sans faire tomber un test si les tests bougent au même moment.

1. `InitiatePayment` ligne 99 : `if (! $attempt->fresh()->allowsFailover())`. Le `fresh()` **a l'air** d'un aller-retour SQL inutile puisque `applyToAttempt()` retourne déjà la tentative mutée. Le remplacer par `$outcome->status->allowsFailover()` supprime le garde `customer_prompted` : le drapeau ne serait plus jamais consulté avant de basculer. C'est la simplification la plus tentante du fichier et la plus coûteuse.
2. `SettlePayment` ligne 44 : `'customer_prompted' => $attempt->customer_prompted || $outcome->customerPrompted`. La ligne de découpe de la classe passe littéralement à côté de ce `||`.
3. `ChargeOutcome::unknown()` renvoie `Processing` **avec** `customerPrompted: true`. Tout le « le défaut penche du bon côté » tient dans cette seule fabrique. Introduire un statut `Unknown` par souci de clarté oblige à réexaminer `allowsFailover()`, `isTerminal()` et surtout `needsPolling()`, qui ne renvoie `true` que pour `Prompted` et `Processing` — un nouveau statut sortirait silencieusement de la file de sondage.

**Discipline imposée sur cette étape :**
- **Interdiction absolue de toucher une ligne `assert*` dans le même commit qu'un déplacement.** Le diff doit être lisible ligne à ligne.
- Conserver les noms de méthodes de test à l'identique (`test_a_prompted_customer_stops_the_failover`, `test_an_unknown_outcome_never_falls_over`, `test_the_prompted_flag_is_never_downgraded`) : un cas supprimé se voit dans le diff, un cas renommé ne se voit pas.
- Conserver la forme `assertSame(['primary'], FakeProvider::$charged)` plutôt qu'un `assertCount` : c'est l'ordre **et** l'identité des agrégateurs sollicités qui constituent l'invariant, pas leur nombre.
- `test_the_prompted_flag_is_never_downgraded` appelle `applyToAttempt` directement : il reste tel quel, c'est le seul test qui protège la ligne 44.
- Ajouter une **table de vérité exhaustive** de `AttemptStatus::allowsFailover()` : itérer sur `AttemptStatus::cases()` et asserter le résultat attendu pour chacun. Aujourd'hui la règle n'est éprouvée que par les cas rencontrés ; un statut ajouté demain n'a rien qui l'oblige à échouer fermé.
- Ajouter le test d'idempotence de la couture : le même événement reçu deux fois ne règle pas la facture deux fois. Le rejeu est aujourd'hui bloqué au niveau du webhook (`UNIQUE (provider, provider_event_id)`) ; passer par un événement ajoute un point de rejeu que rien ne couvre.

**Verrouiller aussi les sept acquis des bacs à sable, documentés dans `docs/03-services/payments/05-providers.md`, chacun par un test nommé d'après le démenti.** Ils sont incarnés par du code qui, lu hors contexte, passe pour du bruit :
- `TranzakProvider::NEVER_PROMPTED = []` — constante vide, signalée par toute analyse statique comme condition toujours fausse ; la supprimer efface le point d'extension documenté.
- `TranzakProvider::refused()` teste `($body['success'] ?? null) === false` **indépendamment du code HTTP**. Factoriser un `AbstractHttpProvider` avec un `$response->failed()` commun restaure exactement le bug de la première version — et la bascule cesse simplement de se déclencher, sans erreur.
- `NotchPayProvider::reference()` tolère `transaction` en chaîne **ou** en objet. Typer `string $transaction` fait lire une réponse légitime comme une absence de référence → `rejected` → **basculable à tort**. C'est le chemin le plus court vers un double débit.
- L'asymétrie des deux `catch` de NotchPay : étape 1 → `rejected` (bascule autorisée), étape 2 → `unknown` (bascule interdite). Quinze lignes d'écart, sémantique opposée. Un `try` unique englobant les deux détruit l'acquis.
- `REFUSED_BEFORE_PROMPT = [400,401,403,404,409,422,429]` — liste fermée, `429` inclus délibérément.
- Montants lus dans `section($data,'merchant')`, jamais à la racine, jamais dans `payer.fee` (0 vs 3 sur le même paiement) ; `errorMessage` et non `statusMessage`.
- **Deux clés de déduplication différentes par agrégateur, et c'est un choix de sécurité** : `id` de livraison chez NotchPay (signature HMAC, rejeu modifié impossible), `eventType:resourceId` chez Tranzak (secret partagé, un `webhookId` peut être forgé). « Harmoniser » les deux handlers rouvre le rejeu.

### Étape 5 — Déplacer physiquement le code

`git mv` du bloc « part sans modification » + les deux moitiés Payments de `SettlePayment` et `InitiatePayment`. Ne modifier que les lignes de `namespace`, les `use`, les clés de config et les clés de traduction.

Renommer dans le même commit : `config/billing.{providers,webhooks,notchpay.*,tranzak.*,operators,payment.*}` → `config/payments.*` ; `billing::messages.*` → `payments::messages.*` ; `billing:reconcile` → `payments:reconcile` ; les routes ; `bootstrap/providers.php`.

**Deux clés mortes à ne pas transporter telles quelles :** `payment.poll_backoff_seconds` et `queue` / `BILLING_QUEUE` n'ont aucune occurrence dans `Modules/Billing`. Les câbler ou les supprimer — les recopier pérennise une config qui ment.

**Piège silencieux à corriger dans ce même commit :** `tests/Feature/TestEnvironmentIsolationTest.php` vérifie l'absence d'identifiants réels par `assertEmpty(config('billing.tranzak.app_id'))` et cinq clés analogues. Dès que ces clés deviennent `payments.*`, `config('billing.…')` renvoie `null`, `null` est vide, **les six cas passent au vert en ne vérifiant plus rien**. Le test qui existe parce que l'oubli s'est déjà produit — son docblock parle d'une centaine de vrais messages envoyés — cesse de protéger sans qu'aucune suite ne rougisse. Deux corrections : mettre à jour les six clés, et **ajouter un `Config::has($key)` avant chaque `assertEmpty`**, pour que toute disparition future de clé fasse échouer le test au lieu de le vider. Idéalement, une assertion structurelle : toute clé sous `payments.*` finissant par `_key`/`_token`/`_hash`/`app_id` est vide — ainsi l'ajout de Tara sera couvert sans éditer le data provider. Ajouter aussi `NOTCHPAY_CALLBACK_URL`, aujourd'hui neutralisée ni dans `phpunit.xml` ni dans le test.

Bonne nouvelle : les **variables d'environnement** portent des noms de vendeurs (`TRANZAK_*`, `NOTCHPAY_*`) et ne se renomment pas. On évite la fenêtre la plus dangereuse d'une extraction, celle où le code lit une variable que l'environnement ne fournit pas encore et conclut « aucun agrégateur configuré » sans erreur. Renommages réels, limités à trois : `BILLING_CURRENCY`, `BILLING_COUNTRY` (à dédoubler : TVA vs opérateur mobile), `BILLING_QUEUE`.

### Étape 6 — Contrat, documentation, périphérie

`Modules/Payments/openapi.yaml` — **obligatoire dans le commit qui crée le répertoire** : `OpenApiContractTest::test_every_module_ships_a_contract` compare `glob('Modules/*')` à `glob('Modules/*/openapi.yaml')` et fait échouer la suite entière sinon. Slug exactement `payments` (`spec()` fait `ucfirst($module)`). `Yaml::parseFile` ne suit aucun fichier externe : `SuccessEnvelope`, `ErrorEnvelope`, `PaymentIntent`, `IdempotencyKey`, `bearerAuth` et les responses `Unauthenticated`/`NotFound`/`InsufficientPermissions` doivent être **recopiés** dans le contrat Payments. Duplication structurelle, à accepter explicitement plutôt qu'à découvrir.

Retirer `/payments`, `/payments/{paymentId}`, `/billing/webhooks/{provider}` et les tags correspondants de `Modules/Billing/openapi.yaml` — sinon `test_the_contract_documents_no_phantom_route` échoue, ce qui est le comportement voulu.

Ajouter `payments` (et `billing`, qui y manque déjà) à `LocalisationTest::translationFiles()`. Vérifier que `PROVIDER_UNAVAILABLE`, `PAYMENT_ALREADY_PENDING`, `WEBHOOK_SIGNATURE_INVALID` figurent dans `docs/02-standards/error-codes.md`.

Mettre à jour `docs/01-overview/architecture.md` (schéma, arborescence de référence, table des bases, section contrats) et `vision.md`. Créer `docs/03-services/payments/` en miroir de `docs/03-services/billing/`. **Écrire un ADR-0009** : ADR-0007 et ADR-0008 raisonnent tous deux en supposant qu'un paiement règle une facture d'abonnement — « une seule intention vivante par facture » notamment. Les laisser tels quels rend la documentation faussement rassurante précisément là où une approximation coûte de l'argent réel à un tiers. L'ADR-0009 doit acter : le périmètre de chaque ADR après extraction, l'autorisation explicite du callback synchrone `settled()` au regard de la §11.1 de l'architecture (qui réserve l'appel synchrone à la lecture), et la promotion de `Money` dans le noyau partagé.

Trancher enfin qui possède les templates Notify `invoice.issued` / `invoice.paid` / `payment.failed`, seedés par `Modules/Notify/Database/Migrations/2026_03_01_000100_seed_billing_templates.php`, et scinder `test_every_billing_message_is_transactional` en conséquence.

### Étape 7 — Le test qui prouve l'objectif

Initier puis constater un paiement dont le motif est une inscription Learn (`subject_type` fictif + montant + devise), sans qu'aucune ligne n'existe dans `invoices` ni `subscriptions`. Terminer par `assertDatabaseCount('invoices', 0)`. C'est la démonstration littérale de ce pour quoi tout ce qui précède a été fait.

---

## 5. RISQUES RÉSIDUELS

**Ce qui restera non prouvé après l'extraction.**

1. **Le callback réel n'a jamais été reçu.** `docs/RECAP.md` §8.2 : les callbacks n'ont jamais été reçus pour de vrai, les comptes marchands NotchPay et Tranzak ne sont pas obtenus. Toute la couche webhook est éprouvée par des charges utiles capturées et rejouées, pas par du trafic entrant. L'extraction ne change rien à cela, dans un sens ni dans l'autre — mais elle ne doit pas donner l'illusion contraire.

2. **La séparation brut/net n'est prouvée qu'une fois, contre Tranzak.** La ligne `fee −3 XAF` de la base de développement est l'unique preuve enregistrée que le chemin complet fonctionne. Le sandbox NotchPay renvoie toujours `fees: []` : la branche `fee` de `NotchPayProvider` reste non éprouvée en conditions réelles, avant comme après.

3. **La règle de bascule n'est prouvée que sur les statuts rencontrés.** La table de vérité exhaustive proposée à l'étape 4 corrige cela pour l'avenir, mais la traduction `raw_status → AttemptStatus` de chaque adaptateur reste une hypothèse sur le vocabulaire des agrégateurs. Le sens exact de `CANCELLED` chez Tranzak reste non tranché — c'est, d'après `05-providers.md`, la seule vérification susceptible d'**élargir** la règle de bascule. **Recommandation : geler la règle telle quelle pendant l'extraction**, et poser la question à Tranzak séparément. Deux chantiers sur le même invariant en même temps, c'est un chantier de trop.

4. **Une tentative bloquée en `created` n'est ni sondée ni expirée — et l'extraction ne le répare pas.** `ReconcilePayments::handle()` et `expire()` ne sélectionnent que `[Prompted, Processing]`. Une tentative dont le processus meurt entre `PaymentAttempt::create()` (l. 81) et `applyToAttempt()` (l. 97) — worker tué, fatal PHP, timeout — n'est jamais reprise, occupe indéfiniment `payment_attempts_one_alive_per_intent`, et le client a peut-être été sollicité et débité. Le docblock de `PaymentProvider` pose pourtant comme troisième question obligatoire : « Comment retrouver une transaction à partir de notre `merchantReference` ? Sans cette capacité, un appel expiré reste à jamais irrésolu. » **Aucune méthode ne l'implémente.** `poll()` part de `$attempt->provider_ref`, `null` dans ce cas précis. L'extraction est le bon moment pour ajouter `findByMerchantReference()` (NotchPay : `trxref` ; Tranzak : `mchTransactionRef`) et élargir la requête à `created` — mais c'est un ajout, pas une conséquence de l'extraction. Si on ne le fait pas maintenant, le noter comme dette explicite.

5. **Le garde anti-double-encaissement du registre reste applicatif.** `applyToIntent()` protège par `if ($intent->status === SUCCEEDED) return $intent;` (l. 74-76), mais le `DB::transaction()` qui l'entoure ne pose **aucun `lockForUpdate()` sur l'intention** — contrairement à `markInvoicePaid()`, qui verrouille bien la facture. Deux exécutions concurrentes (le cron toutes les cinq minutes et un callback ; ou les trois callbacks NotchPay que `05-providers.md` §2.0.3 décrit comme arrivant dans un ordre variable) peuvent lire toutes deux `processing` et produire **deux lignes `charge`**. La facture reste juste, la comptabilité plateforme non. C'est une défaillance silencieuse. La protection devrait être une contrainte : index unique partiel sur `transactions (payment_intent_id) WHERE type = 'charge'`. Le volume de Learn rendra la fenêtre bien plus fréquente qu'aujourd'hui.

6. **Effet de bord voisin, sur le même chemin :** `applyToAttempt()` écrit `'settled_at' => $outcome->status->isTerminal() ? now() : null` (l. 52). Un callback tardif sur une tentative déjà `succeeded`, dont le `poll()` échoue en 5xx, produit un `ChargeOutcome::unknown()` → **la tentative redescend de `succeeded` à `processing`** et perd son `settled_at`. L'intention est protégée contre la rétrogradation, la tentative ne l'est pas. Elle retourne alors dans la file de sondage.

7. **La clé d'idempotence est unique globalement et lue sans filtre.** `payment_intents_idempotency_unique` porte sur la seule colonne `idempotency_key`, et `existingIntent()` (l. 174-181) est un `where('idempotency_key', …)->first()` **sans aucun scope d'organisation**, exécuté avant toute vérification (l. 53-57). Le scope organisation appliqué au chargement de l'`Invoice` dans le contrôleur est court-circuité. Aujourd'hui le risque est faible : un produit, un type d'appelant. Avec deux produits dont les clients dérivent naturellement leurs clés du métier (`invoice-123`, `order-1`), l'organisation B peut recevoir en réponse l'intention de l'organisation A — avec `amount`, `operator`, `invoice_id`, `failure_code` et la liste des tentatives — et voir son propre paiement silencieusement non lancé. L'index doit devenir `(scope, idempotency_key)` et `existingIntent()` doit être scopé. **À traiter dans l'étape 3**, pas après.

8. **La perte du `nullOnDelete()`.** Retirer la FK `payment_intents.invoice_id` retire aussi la remise à `null` automatique. Sans conséquence en pratique — une facture n'est jamais supprimée, elle est mise à `void` — mais à écrire noir sur blanc dans la migration, sinon quelqu'un ajoutera un jour un `delete` en croyant la base protégée.

9. **La fenêtre de bascule pendant la migration elle-même.** Les index partiels sont créés par `DB::statement` sans `IF NOT EXISTS`, et leurs noms sont **globaux au schéma PostgreSQL**, pas locaux au module. Le remède naïf — préfixer d'un `DROP INDEX IF EXISTS` — ouvre une fenêtre, même brève, pendant laquelle `payment_intents_one_alive_per_subject` et `payment_attempts_one_alive_per_intent` n'existent pas. Ce sont les deux garanties anti-double-invite. Migrer **caisse fermée**, ou par `CREATE UNIQUE INDEX CONCURRENTLY` sous un nouveau nom puis bascule. Aujourd'hui la question est théorique (rien n'est déployé) ; elle ne le sera plus.

10. **Le `CHECK` de statut fige l'enum en base.** `payment_attempts_status_check` énumère en dur les sept valeurs d'`AttemptStatus`. Ajouter un état côté PHP sans `ALTER` fait échouer l'écriture à l'exécution — et dans `attemptProviders()`, cette écriture se produit **après** que le débit a été envoyé à l'agrégateur. Le client serait sollicité et la tentative non enregistrée.

11. **`PaymentWebhookHandler` ne déclare pas `payload()`, mais `TranzakWebhookHandler` en définit une**, et `WebhookController` ne l'appelle jamais — il refait lui-même l'`array_diff_key`. Si quelqu'un « répare » le contrôleur, `NotchPayWebhookHandler` n'a pas la méthode : erreur fatale sur le chemin des callbacks de paiement. Soit la méthode entre dans l'interface, soit elle disparaît.

---

## 6. CE QUE ÇA NE RÉSOUT PAS

**L'extraction rend Payments réutilisable. Elle ne le rend pas multi-bénéficiaire.** Et c'est une distinction qui compte, parce que la question arrivera avec Learn, pas après.

Le modèle suppose que l'argent revient à Sekuu, à cinq endroits identifiables — et aucun n'est corrigé par ce plan :

1. **`ChargeRequest` n'a pas de bénéficiaire.** Ses quatre champs sont `money`, `msisdn`, `merchantReference`, `description`. Aucun compte de destination n'est transmis à l'agrégateur. Le sandbox Tranzak a pourtant confirmé « compte de collecte distinct du compte de reversement, `type` les distingue » — capacité constatée, jamais utilisée. Ajouter un bénéficiaire plus tard oblige à modifier l'interface que **tous** les adaptateurs implémentent.

2. **`transactions` n'a pas de colonne bénéficiaire**, et `transactions_type_check` fige la liste des types en dur : `('charge','fee','refund','credit','debit','adjustment')`. Il n'existe pas de type `payout`. Dans une place de marché, `charge` crédite la plateforme et un `payout` la débite vers le centre de formation ; ni la colonne ni le type n'existent. Les ajouter suppose un `ALTER` sur une table `append-only` verrouillée jusque dans `Transaction::booted()`.

3. **`CreditLedger::settle()` écrit contre `$intent->organization_id`, c'est-à-dire le payeur** (l. 74, 86). Un solde dû à un tiers exigerait une agrégation de sens opposé sur la même table, ce que le docblock (« le solde de crédit n'est jamais stocké ») n'anticipe pas.

4. **La commission est une charge de plateforme, par décision explicite.** `Transaction::FEE` : « charge de la plateforme, pas du client ». `05-providers.md` §3.4 : « Par défaut, la commission reste à la charge de la plateforme ». NotchPay renvoie `charge: "business"`. Ces trois affirmations sont cohérentes tant que le marchand **est** Sekuu. Dès que le marchand est un centre de formation, « qui porte les 3 % » redevient une question ouverte — et la réponse usuelle en place de marché est « déduits de la part du bénéficiaire », ce que le registre actuel ne sait pas représenter.

5. **Aucun état de reversement n'existe.** Pas de table `payouts`, pas de cycle de vie, pas d'unicité anti-double-reversement, pas de rapprochement entre ce qui a été encaissé et ce qui a été reversé.

**Ce que ce plan fait quand même pour ce futur-là :** l'option 2 de §3.2 — `organization_id` désigne **qui encaisse**, `payer_type`/`payer_id` désigne **qui paie** — est le seul découpage qui laisse la porte ouverte. Si le choix se porte sur `organization_id = payeur`, il faudra remigrer des données monétaires le jour où le reversement arrivera.

**Autres angles morts :**

- **Le remboursement.** `refund` est déclaré, documenté, écrit nulle part. Aucune table ne porte l'état d'un remboursement : ni statut, ni référence agrégateur, ni tentative. ADR-0007 l'exclut en espèces pour les abonnements (lent, coûteux, souvent manuel) et le règle par avoir. Learn rend le sujet immédiat : un apprenant qui annule une formation est un cas de support banal. **Le choix n'a pas besoin d'être implémenté maintenant, mais il doit être pris maintenant** — il décide si le registre Payments suffit (une ligne négative, sans état) ou s'il faut une table `payment_refunds` avec son propre cycle de vie. Ajouter cette table après coup est une migration ordinaire ; découvrir après coup qu'un remboursement avait besoin d'un statut alors qu'il a été écrit comme une ligne de registre oblige à réinterpréter des données monétaires existantes.

- **L'avoir côté Learn.** Si Learn a besoin d'un crédit (formation annulée, bon d'achat), alors le registre de crédit n'est pas un concept Billing mais un troisième concept — et le scinder en deux aujourd'hui, c'est le scinder au mauvais endroit. À vérifier avant l'étape 3.

- **La barème des agrégateurs et l'ordre de priorité vivent en configuration.** En cas de litige, « qui a décidé de tenter cet agrégateur en priorité 2 le 3 mars » n'a pas de réponse en base. Le passer en table est un choix à faire consciemment, pas par défaut.

- **`payments.payment.unresolved` n'aura toujours pas de destinataire** tant qu'on ne lui en donne pas un. C'est l'alerte annoncée par ADR-0008 comme mitigation du cas « le client a peut-être été débité et n'a pas son service ». Elle est publiée dans le vide.