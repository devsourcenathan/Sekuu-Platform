<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/04-decisions/adr-0006-transactional-vs-marketing.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');

            // NULL = préférence globale de l'utilisateur, toutes organisations
            // confondues.
            $table->uuid('organization_id')->nullable();

            $table->string('category', 20);
            $table->string('channel', 20);
            $table->boolean('enabled');
            $table->timestamps();

            $table->index('user_id');
        });

        // L'unicité doit traiter NULL comme une valeur : deux index partiels,
        // car en SQL NULL n'est jamais égal à NULL.
        DB::statement(
            'CREATE UNIQUE INDEX notification_preferences_global_unique
             ON notification_preferences (user_id, category, channel)
             WHERE organization_id IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX notification_preferences_org_unique
             ON notification_preferences (user_id, organization_id, category, channel)
             WHERE organization_id IS NOT NULL'
        );

        Schema::create('suppressions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('channel', 20);
            $table->string('destination', 320);
            $table->string('reason', 30);
            $table->string('source', 50)->nullable();
            $table->foreignUuid('notification_id')->nullable()
                ->constrained('notifications')->nullOnDelete();

            // NULL = permanente.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'destination']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX suppressions_permanent_unique
             ON suppressions (channel, destination) WHERE expires_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('notification_preferences');
    }
};
