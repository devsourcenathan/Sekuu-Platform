<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/03-services/billing/02-data-model.md
 * @see docs/04-decisions/adr-0007-mobile-money-prepaid-subscriptions.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignUuid('plan_price_id')->constrained('plan_prices')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->timestamp('trial_ends_at')->nullable();

            // Date absolue et non compteur décrémenté : la tâche quotidienne
            // reste idempotente si elle tourne deux fois.
            $table->timestamp('grace_ends_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->string('cancellation_reason', 255)->nullable();

            // Descente en gamme différée : la période en cours est payée,
            // l'écourter obligerait à rembourser.
            $table->uuid('pending_plan_id')->nullable();
            $table->uuid('pending_plan_price_id')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
            $table->index('organization_id');
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check
                       CHECK (status IN ('pending','trialing','active','grace','suspended','cancelled','expired'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_check
                       CHECK (current_period_end > current_period_start)');

        // Une organisation n'a qu'un abonnement **vivant**, mais en a
        // nécessairement plusieurs dans son historique. D'où l'index partiel
        // plutôt qu'une contrainte d'unicité simple.
        //
        // `pending` en fait partie : un abonnement souscrit mais jamais payé
        // occupe la place. Sans lui, une organisation qui ne paie pas pourrait
        // souscrire à nouveau et accumuler des factures ouvertes.
        DB::statement(
            "CREATE UNIQUE INDEX subscriptions_one_alive_per_organization
             ON subscriptions (organization_id)
             WHERE status IN ('pending','trialing','active','grace')"
        );

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('subscription_id')->nullable()
                ->constrained('subscriptions')->nullOnDelete();

            // Séquentiel et sans trou : un trou est une question à laquelle il
            // faudra répondre lors d'un contrôle.
            $table->string('number', 30)->unique();

            $table->string('status', 20)->default('open');
            $table->char('currency', 3);
            $table->bigInteger('subtotal');

            // Figé à l'émission. Si le taux change, les factures passées
            // continuent d'afficher ce qui a été réellement facturé.
            $table->decimal('tax_rate', 6, 4);

            $table->bigInteger('tax_amount');
            $table->bigInteger('credit_applied')->default(0);
            $table->bigInteger('total');
            $table->bigInteger('amount_paid')->default(0);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();

            // Copie : les recharger depuis Identity ferait changer une facture
            // passée quand l'organisation change de nom.
            $table->jsonb('billing_details')->default('{}');
            $table->timestamps();

            $table->index(['organization_id', 'issued_at']);
            $table->index(['status', 'due_at']);
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
                       CHECK (status IN ('open','paid','void','uncollectible'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_check
                       CHECK (total = subtotal + tax_amount - credit_applied)');

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description', 255);
            $table->integer('quantity')->default(1);

            // Peut être négatif : c'est ainsi qu'apparaît le crédit de
            // proration, lisible sur le document plutôt que soustrait en
            // silence.
            $table->bigInteger('unit_amount');

            $table->bigInteger('amount');
            $table->uuid('product_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
    }
};
