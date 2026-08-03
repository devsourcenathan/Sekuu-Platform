<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `organization_products` est un cache de droits d'accès dérivé, alimenté par
 * les événements de Billing. Ce n'est jamais une source de vérité financière.
 *
 * @see docs/03-services/identity/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->text('icon_url')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->string('source', 20)->default('subscription');
            $table->timestamp('activated_at');
            $table->timestamp('expires_at')->nullable();

            // Référence logique vers Billing : pas de clé étrangère, afin que
            // le module reste extractible sans contrainte inter-schémas.
            $table->uuid('subscription_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'product_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_products');
        Schema::dropIfExists('products');
    }
};
