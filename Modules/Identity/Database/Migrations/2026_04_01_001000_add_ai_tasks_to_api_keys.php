<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quelles tâches d'IA cette clé peut demander.
 *
 * ## La double borne, une troisième fois
 *
 * Le scope dit qu'une clé peut **exécuter des tâches** ; il ne dit pas
 * lesquelles. Sans cette colonne, une clé de Sekuu Learn habilitée à générer des
 * quiz pourrait extraire les champs d'un document, résumer un dossier médical,
 * ou lancer la tâche la plus chère du catalogue en boucle.
 *
 * C'est exactement le raisonnement de `subject_types` pour `payments.charge` :
 * le catalogue dit ce qui **existe**, la clé dit ce que **ce produit-là** peut
 * demander. Une tâche ajoutée n'habilite personne tant qu'aucune clé ne la
 * porte.
 *
 * `null` n'est pas « toutes les tâches » : `IssueApiKey` refuse d'émettre une
 * clé portant `ai.run` sans liste. La colonne est nullable pour les clés qui
 * n'ont rien à voir avec l'IA, pas pour ouvrir une porte.
 *
 * @see docs/03-services/ai/07-external-api.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->jsonb('ai_tasks')->nullable()->after('max_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('ai_tasks');
        });
    }
};
