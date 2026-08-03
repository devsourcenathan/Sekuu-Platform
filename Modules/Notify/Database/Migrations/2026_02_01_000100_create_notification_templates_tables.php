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
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 100);
            $table->string('channel', 20);
            $table->string('category', 20);

            // Référence logique vers Identity, sans clé étrangère : Notify ne
            // connaît pas les organisations, il en porte l'identifiant.
            // NULL = template de plateforme.
            $table->uuid('organization_id')->nullable();

            $table->string('provider_ref', 255)->nullable();
            $table->json('variables')->default('[]');
            $table->string('status', 20)->default('active');
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->index(['key', 'channel']);
            $table->index('organization_id');
        });

        // Un template par clé, canal et organisation. L'index partiel distingue
        // le template de plateforme (organization_id NULL) des variantes.
        DB::statement(
            'CREATE UNIQUE INDEX notification_templates_platform_unique
             ON notification_templates (key, channel) WHERE organization_id IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX notification_templates_org_unique
             ON notification_templates (key, channel, organization_id) WHERE organization_id IS NOT NULL'
        );

        Schema::create('notification_template_contents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')
                ->constrained('notification_templates')
                ->cascadeOnDelete();
            $table->string('locale', 10);
            $table->text('subject')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->unique(['template_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_template_contents');
        Schema::dropIfExists('notification_templates');
    }
};
