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
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('slug', 100);
            $table->json('settings')->default('{}');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement(
            'CREATE UNIQUE INDEX workspaces_org_slug_unique
             ON workspaces (organization_id, slug) WHERE deleted_at IS NULL'
        );

        // L'appartenance à un workspace est explicite : être membre de
        // l'organisation ne donne pas accès à tous ses workspaces.
        Schema::create('workspace_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // La référence pointe vers le membership, et non vers l'utilisateur :
            // il devient structurellement impossible de rattacher au workspace
            // d'une organisation quelqu'un qui n'en est pas membre.
            $table->foreignUuid('membership_id')->constrained('memberships')->cascadeOnDelete();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'membership_id']);
            $table->index('membership_id');
        });

        // Au plus un workspace par défaut par membership.
        DB::statement(
            'CREATE UNIQUE INDEX workspace_members_single_default
             ON workspace_members (membership_id) WHERE is_default'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
