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
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();

            // Le rôle est une clé étrangère, pas une chaîne libre : une
            // invitation ne peut pas accorder un rôle inexistant.
            $table->foreignUuid('global_role_id')->constrained('global_roles')->restrictOnDelete();

            $table->string('email', 255);

            // Jeton stocké haché, comme les refresh tokens.
            $table->char('token_hash', 64)->unique();

            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'accepted_at']);
        });

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE invitations ALTER COLUMN email TYPE citext');
        }

        // Une seule invitation en attente par adresse et par organisation.
        DB::statement(
            'CREATE UNIQUE INDEX invitations_pending_unique
             ON invitations (organization_id, email)
             WHERE accepted_at IS NULL AND revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
