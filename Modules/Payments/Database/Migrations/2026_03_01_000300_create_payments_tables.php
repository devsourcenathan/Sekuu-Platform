<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/04-decisions/adr-0008-payment-aggregators-failover.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('invoice_id')->nullable()
                ->constrained('invoices')->nullOnDelete();
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

            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_status_check
                       CHECK (status IN ('pending','processing','succeeded','failed','expired','cancelled'))");
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_amount_check
                       CHECK (amount > 0)');

        DB::statement(
            'CREATE UNIQUE INDEX payment_intents_idempotency_unique
             ON payment_intents (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );

        // Le garde-fou contre le client impatient : sans lui, trois clics
        // produisent trois invites, et trois débits possibles. La contrainte est
        // en base, pas dans le code — une vérification applicative perdrait la
        // course sous concurrence.
        DB::statement(
            "CREATE UNIQUE INDEX payment_intents_one_alive_per_invoice
             ON payment_intents (invoice_id)
             WHERE status IN ('pending','processing') AND invoice_id IS NOT NULL"
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

        // Registre append-only. Pas de `updated_at` : rien n'est modifié après
        // coup. Corriger une écriture en la réécrivant efface la trace de
        // l'erreur, et avec elle toute possibilité d'expliquer un solde.
        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('invoice_id')->nullable();
            $table->foreignUuid('payment_intent_id')->nullable()
                ->constrained('payment_intents')->nullOnDelete();
            $table->foreignUuid('payment_attempt_id')->nullable()
                ->constrained('payment_attempts')->nullOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->timestamp('occurred_at');
            $table->string('description', 255)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'occurred_at']);
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check
                       CHECK (type IN ('charge','fee','refund','credit','debit','adjustment'))");
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amount_check
                       CHECK (amount <> 0)');

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
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_intents');
    }
};
