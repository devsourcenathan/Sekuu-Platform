<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qui a été promis, figé sur l'abonnement.
 *
 * Sans cette copie, baisser une limite du catalogue rétrograderait tous les
 * abonnés le soir même — y compris ceux qui ont payé une année d'avance la
 * semaine précédente. Sur un modèle prépayé, ce n'est pas un réglage.
 *
 * @see docs/04-decisions/adr-0019-granted-limits.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            /*
             * **Non nullable**, avec un objet vide par défaut.
             *
             * Lire `null` sur une colonne vide et en conclure « illimité »
             * serait le défaut le plus coûteux possible : il ouvrirait toutes
             * les ressources à tous les abonnements non encore copiés. Un
             * abonnement sans copie doit être **non couvert**.
             */
            $table->jsonb('granted_limits')->default('{}');

            // Quand la copie a été faite. Un opérateur qui cherche pourquoi un
            // client est bloqué a besoin de savoir de quelle période elle date.
            $table->timestamp('limits_granted_at')->nullable();
        });

        /*
         * Rattrapage des abonnements existants.
         *
         * Ils n'ont pas de copie, et la règle du non-couvert les bloquerait
         * tous instantanément — une migration ne doit pas fermer un service en
         * cours d'exécution. On copie donc l'état actuel du catalogue, qui est
         * exactement ce dont ils bénéficient aujourd'hui.
         */
        DB::statement('
            UPDATE subscriptions
            SET granted_limits = plans.limits,
                limits_granted_at = NOW()
            FROM plans
            WHERE plans.id = subscriptions.plan_id
        ');
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['granted_limits', 'limits_granted_at']);
        });
    }
};
