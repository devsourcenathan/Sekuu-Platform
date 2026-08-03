<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/03-services/billing/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_public')->default(true);
            $table->smallInteger('trial_days')->default(0);
            $table->smallInteger('sort_order')->default(0);

            // `null` dans une limite = illimité. L'absence de la clé = non
            // couvert par ce plan. La distinction sépare « illimité » de
            // « pas d'accès ».
            $table->jsonb('limits')->default('{}');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_status_check
                       CHECK (status IN ('active','archived'))");

        Schema::create('plan_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->char('currency', 3);

            // Hors taxes, en plus petite unité de la devise. Le XAF n'a pas de
            // centime : 45 000 XAF se stocke 45000.
            $table->bigInteger('amount');

            $table->string('interval', 10);
            $table->smallInteger('interval_count')->default(1);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE plan_prices ADD CONSTRAINT plan_prices_interval_check
                       CHECK (interval IN ('month','year'))");
        DB::statement('ALTER TABLE plan_prices ADD CONSTRAINT plan_prices_amount_check
                       CHECK (amount >= 0)');

        // Changer un prix consiste à archiver l'ancien tarif et à en créer un
        // nouveau, jamais à faire un UPDATE : un abonnement référence le tarif
        // avec lequel il a été souscrit, et une facture passée doit rester
        // explicable.
        DB::statement(
            'CREATE UNIQUE INDEX plan_prices_active_unique
             ON plan_prices (plan_id, currency, interval, interval_count)
             WHERE status = \'active\''
        );

        Schema::create('plan_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();

            // Référence logique vers Identity, sans clé étrangère : Billing
            // doit rester extractible.
            $table->uuid('product_id');
            $table->timestamps();

            $table->unique(['plan_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_products');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
