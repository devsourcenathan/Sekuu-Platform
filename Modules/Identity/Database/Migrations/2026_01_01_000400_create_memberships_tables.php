<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/03-services/identity/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            // Un utilisateur ne peut pas être membre deux fois de la même organisation.
            $table->unique(['user_id', 'organization_id']);
            $table->index(['organization_id', 'status']);
        });

        // Un membre peut cumuler plusieurs rôles globaux : admin + billing_manager.
        Schema::create('membership_roles', function (Blueprint $table): void {
            $table->foreignUuid('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignUuid('global_role_id')->constrained('global_roles')->restrictOnDelete();

            $table->primary(['membership_id', 'global_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('memberships');
    }
};
