<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/02-standards/security.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // La table `sessions` de Laravel (driver de session web) n'a rien à voir
        // avec celle-ci : ici il s'agit des appareils connectés d'un utilisateur.
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_name', 200)->nullable();
            $table->string('platform', 50)->nullable();
            $table->string('browser', 50)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_activity');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index('expires_at');
        });

        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('user_sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // SHA-256 du jeton. La valeur en clair n'est jamais stockée.
            $table->char('token_hash', 64)->unique();

            // Chaînage pour la détection de rejeu.
            $table->foreignUuid('parent_id')->nullable()->constrained('refresh_tokens')->nullOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('user_sessions');
    }
};
