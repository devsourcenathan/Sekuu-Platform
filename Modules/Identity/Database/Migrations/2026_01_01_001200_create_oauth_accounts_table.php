<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/03-services/identity/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('provider_id', 255);
            $table->string('email', 255)->nullable();
            $table->timestamps();

            // Un compte externe ne peut être rattaché qu'à un seul utilisateur.
            $table->unique(['provider', 'provider_id']);

            // Un utilisateur ne lie qu'un compte par fournisseur.
            $table->unique(['user_id', 'provider']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE oauth_accounts ALTER COLUMN email TYPE citext');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_accounts');
    }
};
