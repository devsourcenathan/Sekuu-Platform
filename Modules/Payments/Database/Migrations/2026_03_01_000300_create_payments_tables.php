<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 * @see docs/05-analyses/extraction-payments.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * Ce que ce paiement règle, sans que la couche de paiement sache ce
             * que c'est. `subject_type` suit `{module}.{ressource}` —
             * `billing.invoice`, `learn.enrollment` — et n'est jamais
             * interprété ici : il est porté, indexé, et remis à un résolveur.
             *
             * NOT NULL tous les deux, et c'est le point : c'est ce qui permet à
             * l'index d'unicité ci-dessous de se passer d'une clause
             * d'exclusion. La version précédente portait `invoice_id NULL` et
             * excluait explicitement les intentions sans facture — autrement
             * dit, un paiement sans facture n'avait **aucune** protection
             * anti-double-invite.
             */
            $table->string('subject_type', 40);
            $table->uuid('subject_id');

            /*
             * Qui paie et qui encaisse, séparément.
             *
             * Les confondre marche tant qu'il n'y a qu'un vendeur. Sekuu
             * facturant ses organisations clientes, `organization_id` désignait
             * jusqu'ici le **payeur** ; le jour où un centre de formation
             * encaisse via la plateforme, il désigne le **bénéficiaire**. Un
             * seul champ ne peut pas dire les deux, et s'en apercevoir plus
             * tard obligerait à remigrer des données monétaires.
             */
            $table->string('payer_type', 40);
            $table->uuid('payer_id');

            // `null` = la plateforme encaisse pour elle-même. Référence
            // logique : aucune contrainte vers un autre module.
            $table->uuid('payee_organization_id')->nullable();

            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('method', 20);

            // Le réseau du payeur est un **fait** déduit du numéro ; l'agrégateur
            // est un choix. Les mélanger dans une colonne rendrait la bascule
            // illisible.
            $table->string('operator', 20)->nullable();

            $table->string('msisdn', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('failure_code', 60)->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamp('expires_at');
            $table->uuid('initiated_by')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamps();

            $table->index(['payer_type', 'payer_id']);
            $table->index('payee_organization_id');
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_status_check
                       CHECK (status IN ('pending','processing','succeeded','failed','expired','cancelled'))");
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_amount_check
                       CHECK (amount > 0)');

        /*
         * Idempotence **scopée au payeur**.
         *
         * L'index portait auparavant sur la seule colonne `idempotency_key`, et
         * la lecture correspondante ne filtrait sur rien. Avec deux produits
         * dont les clients dérivent naturellement leurs clés du métier —
         * `invoice-123`, `order-1` — un payeur pouvait recevoir en réponse
         * l'intention d'un autre, montant et tentatives compris, et voir son
         * propre paiement silencieusement non lancé.
         */
        DB::statement(
            'CREATE UNIQUE INDEX payment_intents_idempotency_unique
             ON payment_intents (payer_type, payer_id, idempotency_key)
             WHERE idempotency_key IS NOT NULL'
        );

        /*
         * Le garde-fou contre le client impatient : trois clics ne produisent
         * pas trois invites. **Sans clause d'exclusion**, donc valable pour tout
         * objet payable, pas seulement pour une facture.
         */
        DB::statement(
            "CREATE UNIQUE INDEX payment_intents_one_alive_per_subject
             ON payment_intents (subject_type, subject_id)
             WHERE status IN ('pending','processing')"
        );

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_intent_id')
                ->constrained('payment_intents')->cascadeOnDelete();
            $table->string('provider', 30);
            $table->smallInteger('priority');

            // Écrite **avant** l'appel à l'agrégateur : sans elle, un appel qui
            // expire laisse une tentative dont on ignore si elle a abouti — et
            // c'est précisément la question dont dépend la bascule.
            $table->string('merchant_reference', 64)->unique();

            $table->string('provider_ref', 120)->nullable();
            $table->string('status', 20)->default('created');

            // La colonne la plus importante de la table : l'invite est-elle
            // partie sur le téléphone du client ? Aucun agrégateur ne l'expose,
            // elle est déduite de l'issue de l'appel de débit.
            $table->boolean('customer_prompted')->default(false);

            $table->bigInteger('gross_amount')->nullable();
            $table->bigInteger('fee_amount')->nullable();
            $table->bigInteger('net_amount')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('raw_status', 60)->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->smallInteger('poll_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_polled_at']);
        });

        DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status_check
                       CHECK (status IN ('created','rejected','prompted','processing','succeeded','failed','expired'))");

        // La garantie anti-double-encaissement. Unique **par agrégateur** : la
        // même référence peut exister chez deux d'entre eux sans collision.
        DB::statement(
            'CREATE UNIQUE INDEX payment_attempts_provider_ref_unique
             ON payment_attempts (provider, provider_ref) WHERE provider_ref IS NOT NULL'
        );

        // Une seule tentative vivante par intention : sans quoi deux invites
        // pourraient coexister pour une même facture.
        DB::statement(
            "CREATE UNIQUE INDEX payment_attempts_one_alive_per_intent
             ON payment_attempts (payment_intent_id)
             WHERE status IN ('created','prompted','processing')"
        );

        /*
         * Registre des mouvements de caisse, append-only.
         *
         * Ne porte que ce qui touche à l'argent réellement encaissé : `charge`,
         * `fee`, `refund`. Le crédit commercial d'une organisation vit dans
         * `credit_entries`, côté Billing — la frontière existait déjà dans le
         * code (`creditTypes()` excluait exactement `charge` et `fee`), elle
         * devient physique.
         *
         * Pas de colonne `updated_at` : rien n'est modifié après coup. Corriger
         * une écriture en la réécrivant efface la trace de l'erreur, et avec
         * elle toute possibilité d'expliquer un solde.
         */
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Références **logiques** : ces tables changeront de module.
            $table->uuid('payment_intent_id')->nullable();
            $table->uuid('payment_attempt_id')->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->uuid('payee_organization_id')->nullable();

            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->timestamp('occurred_at');
            $table->string('description', 255)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('payment_intent_id');
        });

        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_type_check
                       CHECK (type IN ('charge','fee','refund'))");
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_amount_check
                       CHECK (amount <> 0)');

        /*
         * Anti-double-encaissement, en base cette fois.
         *
         * La protection était applicative : `applyToIntent()` court-circuite si
         * l'intention est déjà `succeeded`, mais sans verrou. Deux exécutions
         * concurrentes — le sondage toutes les cinq minutes et un callback, ou
         * deux des trois callbacks qu'un agrégateur envoie pour un seul
         * paiement — peuvent lire toutes deux `processing` et écrire deux
         * lignes `charge`. La facture reste juste, la comptabilité non.
         */
        DB::statement(
            "CREATE UNIQUE INDEX payment_transactions_one_charge_per_intent
             ON payment_transactions (payment_intent_id)
             WHERE type = 'charge' AND payment_intent_id IS NOT NULL"
        );

        Schema::create('provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 30);
            $table->string('provider_event_id', 120);
            $table->foreignUuid('payment_attempt_id')->nullable()
                ->constrained('payment_attempts')->nullOnDelete();

            // Le corps brut est conservé : quand un paiement est contesté,
            // c'est la seule pièce qui dit ce que l'agrégateur a réellement
            // envoyé, et non ce que le code en a compris.
            $table->jsonb('payload');

            $table->boolean('signature_valid');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_intents');
    }
};
