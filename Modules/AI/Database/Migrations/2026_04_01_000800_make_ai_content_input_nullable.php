<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La sortie se conserve brièvement ; l'entrée, non.
 *
 * ## Pourquoi les deux ne vont pas ensemble
 *
 * La table portait `input` et `output` comme un couple, en supposant qu'on
 * garde les deux ou aucun. C'est faux, et le contrat d'API le dit : la sortie
 * doit survivre à l'appel — sans quoi un sondage `GET /ai/tasks/{id}` n'aurait
 * rien à lire, et une clé d'idempotence rejouée ne rendrait que des métriques.
 *
 * L'entrée, elle, n'a aucune raison de survivre. Un registre de prompts
 * concentrerait en clair ce que tous les produits ont de plus sensible, et il
 * n'est constitué que si la tâche déclare une rétention.
 *
 * D'où cette colonne nullable : `null` dit « on ne l'a pas gardée », ce qu'une
 * chaîne vide ne dirait pas — elle se lirait comme une entrée vide.
 *
 * @see docs/04-decisions/adr-0016-ai-spend-and-privacy.md
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_contents ALTER COLUMN input DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE ai_contents SET input = '' WHERE input IS NULL");
        DB::statement('ALTER TABLE ai_contents ALTER COLUMN input SET NOT NULL');
    }
};
