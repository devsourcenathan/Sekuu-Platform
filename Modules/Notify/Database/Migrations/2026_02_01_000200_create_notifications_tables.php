<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/03-services/notify/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->uuid('user_id')->nullable();

            $table->foreignUuid('template_id')
                ->constrained('notification_templates')
                ->restrictOnDelete();

            // Copie de la clé : le journal reste lisible même si le template
            // est un jour supprimé.
            $table->string('template_key', 100);

            $table->string('channel', 20);
            $table->string('category', 20);
            $table->string('locale', 10);
            $table->string('recipient', 320);

            // Contenu figé à l'acceptation. Sans cela, un template corrigé
            // pendant l'attente en file changerait le message réellement
            // envoyé — voir ADR-0005.
            $table->text('rendered_subject')->nullable();
            $table->text('rendered_body');

            $table->json('payload')->default('{}');
            $table->string('status', 20)->default('queued');
            $table->string('idempotency_key', 255)->nullable();
            $table->string('source_event_id', 100)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->string('failed_reason', 100)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['user_id', 'created_at']);
            $table->index('template_key');
        });

        DB::statement(
            'CREATE UNIQUE INDEX notifications_idempotency_unique
             ON notifications (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );

        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->integer('attempt')->default(1);
            $table->string('status', 20);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();

            // Le coût unitaire du SMS le rend nécessaire dès le premier jour :
            // sans lui, aucun plafond ni refacturation par organisation.
            $table->decimal('cost_amount', 12, 4)->nullable();
            $table->char('cost_currency', 3)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'attempt']);
            $table->index('provider_message_id');
        });

        // Table append-only : ce que les fournisseurs rapportent après coup.
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignUuid('delivery_id')->nullable()
                ->constrained('notification_deliveries')->nullOnDelete();
            $table->string('type', 30);
            $table->string('provider', 50);
            $table->string('provider_event_id', 255)->nullable();
            $table->json('payload')->default('{}');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['notification_id', 'occurred_at']);
        });

        // Les fournisseurs rejouent leurs webhooks : la déduplication est
        // structurelle, pas applicative.
        DB::statement(
            'CREATE UNIQUE INDEX notification_events_provider_unique
             ON notification_events (provider, provider_event_id) WHERE provider_event_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
    }
};
