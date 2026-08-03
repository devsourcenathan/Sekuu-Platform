<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jetons d'action à usage unique : réinitialisation de mot de passe et
 * vérification d'adresse email.
 *
 * @see docs/02-standards/security.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);

            // Comme les refresh tokens et les invitations : seul le hachage
            // est conservé. Un vol de base ne donne aucun jeton utilisable.
            $table->char('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        // Un seul jeton en attente par utilisateur et par type : demander un
        // nouveau lien doit invalider le précédent.
        DB::statement(
            'CREATE UNIQUE INDEX action_tokens_pending_unique
             ON action_tokens (user_id, type) WHERE consumed_at IS NULL'
        );

        // Historique des mots de passe, pour empêcher la réutilisation
        // immédiate. Seuls des hachages y sont stockés.
        Schema::create('password_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('password_hash', 255);
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('action_tokens');
    }
};
