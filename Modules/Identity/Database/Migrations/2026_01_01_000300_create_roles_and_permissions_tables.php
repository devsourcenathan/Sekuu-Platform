<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles et permissions **globaux** uniquement. Les permissions métier
 * appartiennent à chaque produit et ne sont jamais stockées ici.
 *
 * @see docs/04-decisions/adr-0003-two-level-roles.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 50);
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('global_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 100)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignUuid('global_role_id')
                ->constrained('global_roles')
                ->cascadeOnDelete();

            $table->foreignUuid('global_permission_id')
                ->constrained('global_permissions')
                ->cascadeOnDelete();

            $table->primary(['global_role_id', 'global_permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('global_permissions');
        Schema::dropIfExists('global_roles');
    }
};
