<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registre de crédit commercial d'une organisation.
 *
 * Séparé du registre de caisse (`payment_transactions`) : les deux étaient
 * colocalisés dans une seule table `transactions`, mais la frontière existait
 * déjà dans le code — `Transaction::creditTypes()` excluait exactement `charge`
 * et `fee`. La scission ne crée pas la frontière, elle la rend physique, et
 * supprime au passage deux clés étrangères qui allaient devenir inter-modules.
 *
 * Append-only, comme son jumeau : c'est une propriété du registre, pas du
 * module. Elle doit donc être répliquée des deux côtés.
 *
 * @see docs/05-analyses/extraction-payments.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Références logiques : la facture appartient à Billing, l'intention
            // de paiement non.
            $table->uuid('invoice_id')->nullable();
            $table->uuid('payment_intent_id')->nullable();

            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->timestamp('occurred_at');
            $table->string('description', 255)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'occurred_at']);
        });

        DB::statement("ALTER TABLE credit_entries ADD CONSTRAINT credit_entries_type_check
                       CHECK (type IN ('credit','debit','adjustment'))");
        DB::statement('ALTER TABLE credit_entries ADD CONSTRAINT credit_entries_amount_check
                       CHECK (amount <> 0)');

        /*
         * Idempotence du crédit.
         *
         * Le règlement d'une facture passera désormais par un événement, et un
         * événement peut être livré plusieurs fois. Sans cette contrainte, un
         * rejeu créditerait deux fois — et le solde de crédit n'étant pas
         * stocké mais calculé, l'erreur serait invisible jusqu'à la facture
         * suivante.
         */
        DB::statement(
            'CREATE UNIQUE INDEX credit_entries_once_per_invoice_and_intent
             ON credit_entries (invoice_id, payment_intent_id, type)
             WHERE invoice_id IS NOT NULL AND payment_intent_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_entries');
    }
};
