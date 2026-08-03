<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clés d'API pour les intégrations serveur à serveur.
 *
 * Déclencher un envoi n'est jamais une action d'utilisateur final : un
 * utilisateur ne doit pas pouvoir faire partir un message au nom de la
 * plateforme.
 *
 * @see docs/02-standards/security.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 200);

            // Préfixe conservé en clair : il permet d'identifier une clé dans
            // une interface sans jamais exposer sa valeur.
            $table->string('prefix', 20);

            // SHA-256, comme les refresh tokens. La valeur n'est affichée
            // qu'une seule fois, à la création.
            $table->char('key_hash', 64)->unique();

            $table->json('scopes')->default('[]');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
