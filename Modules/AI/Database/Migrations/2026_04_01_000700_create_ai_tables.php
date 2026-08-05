<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cinq tables : trois pour les générations, deux pour les comptes qui les
 * exécutent.
 *
 * Les **tâches** n'en font pas partie : une tâche déclare un modèle, une
 * température et un format de sortie — un comportement facturé, donc du code.
 *
 * @see docs/03-services/ai/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('driver', 32);
            $table->string('preset', 32)->nullable();
            $table->jsonb('config')->default('{}');

            // Un blob chiffré : une clé chez Anthropic, un jeton OAuth chez
            // Google, rien du tout en local. Le pilote sait lire ce qu'il a
            // écrit ; personne d'autre n'a à le comprendre.
            $table->text('credentials')->nullable();

            // Modèles servis. Nul = ce que le pilote sait faire.
            $table->jsonb('models')->nullable();

            // Les deux nuls = la plateforme.
            $table->uuid('owner_organization_id')->nullable();
            $table->uuid('owner_api_key_id')->nullable();

            $table->string('environment', 16);
            $table->string('status', 16)->default('unverified');

            // Plafond propre au compte. Sur celui d'un client, c'est sa seule
            // borne — notre plafond absolu protège notre argent, pas le sien.
            $table->bigInteger('spend_cap_micros')->nullable();

            /*
             * Un **ordre**, pas un drapeau « par défaut ».
             *
             * Plusieurs comptes de la plateforme peuvent servir le même modèle,
             * et l'un prend la suite de l'autre sur un `429`. Deux comptes « par
             * défaut » auraient donné un choix dépendant de l'ordre de lecture.
             */
            $table->smallInteger('priority')->default(100);

            $table->timestamp('verified_at')->nullable();
            $table->string('verification_reason', 32)->nullable();
            $table->text('verification_error')->nullable();
            $table->timestamps();

            $table->index(['environment', 'status', 'priority']);
            $table->index('owner_organization_id');
        });

        Schema::create('ai_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->string('task', 64);
            $table->string('status', 16)->default('queued');

            // Résolu une fois, jamais recalculé : une génération dit où elle
            // est partie, et qui l'a payée.
            $table->uuid('account_id')->nullable();

            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();

            /*
             * L'empreinte, jamais l'entrée.
             *
             * Un registre de prompts concentrerait en clair ce que tous les
             * produits ont de plus sensible, et grossirait sans limite.
             */
            $table->char('input_hash', 64);

            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();

            // En millionièmes d'unité : un appel coûte des fractions de franc,
            // et le franc CFA n'a pas de subdivision. `null` = prix inconnu,
            // distinct de zéro.
            $table->bigInteger('cost_micros')->nullable();

            // Vrai sur le compte d'un tiers : notre calcul suit les prix
            // publics, son tarif négocié donne autre chose.
            $table->boolean('cost_estimated')->default(false);

            $table->integer('latency_ms')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->string('failure_code', 48)->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('requested_by', 64)->nullable();
            $table->string('requested_via', 16)->default('system');
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('ai_accounts');

            $table->index(['organization_id', 'created_at']);
            $table->index(['task', 'created_at']);
            $table->index(['account_id', 'created_at']);
        });

        /*
         * L'idempotence est **cloisonnée par organisation** : deux produits
         * dérivant leurs clés de leur métier pourraient sinon se renvoyer
         * mutuellement leurs générations.
         */
        DB::statement(
            'CREATE UNIQUE INDEX ai_generations_idempotency
             ON ai_generations (organization_id, idempotency_key)
             WHERE idempotency_key IS NOT NULL'
        );

        DB::statement(
            "CREATE INDEX ai_generations_pending
             ON ai_generations (created_at) WHERE status IN ('queued', 'running')"
        );

        Schema::create('ai_contents', function (Blueprint $table): void {
            $table->uuid('generation_id')->primary();
            $table->text('input');
            $table->text('output')->nullable();
            $table->timestamp('expires_at');

            $table->foreign('generation_id')->references('id')->on('ai_generations')->cascadeOnDelete();
            $table->index('expires_at');
        });

        Schema::create('ai_spend', function (Blueprint $table): void {
            $table->uuid('organization_id');
            $table->char('period', 7);

            // Deux colonnes, **jamais une somme**. Le quota ne porte que sur nos
            // comptes ; ce qu'un client dépense sur sa clé, il le paie à son
            // fournisseur. Et les deux nombres n'ont même pas la même
            // exactitude.
            $table->bigInteger('cost_micros')->default(0);
            $table->bigInteger('cost_micros_byo')->default(0);

            $table->integer('generations')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->primary(['organization_id', 'period']);
        });

        Schema::create('ai_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('task', 64)->nullable();
            $table->uuid('account_id');
            $table->timestamps();

            $table->foreign('account_id')->references('id')->on('ai_accounts')->cascadeOnDelete();
        });

        // PostgreSQL ne considère pas deux `NULL` comme égaux : sans ces deux
        // index, une organisation porterait deux règles attrape-tout
        // contradictoires, et la résolution dépendrait de l'ordre de lecture.
        DB::statement(
            'CREATE UNIQUE INDEX ai_placements_typed
             ON ai_placements (organization_id, task) WHERE task IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX ai_placements_catch_all
             ON ai_placements (organization_id) WHERE task IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_placements');
        Schema::dropIfExists('ai_spend');
        Schema::dropIfExists('ai_contents');
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('ai_accounts');
    }
};
