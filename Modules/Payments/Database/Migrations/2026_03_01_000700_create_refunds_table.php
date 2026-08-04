<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rendre l'argent.
 *
 * ## Une table, et non une ligne de registre négative
 *
 * C'était le point laissé ouvert par le modèle de données, à trancher avant que
 * des données monétaires n'existent. Il se tranche par la nature de
 * l'opération : un remboursement peut être **en attente**, échouer, être repris.
 *
 * Le registre de caisse, lui, ne porte que des **constats** : il est
 * append-only, sans colonne `updated_at`, et une ligne `pending` qu'on
 * corrigerait ensuite détruirait exactement la propriété qui le rend auditable.
 *
 * C'est la même séparation qu'entre `payment_intents` et `payment_transactions`,
 * appliquée dans l'autre sens. La ligne `refund` du registre n'est écrite qu'au
 * **décaissement constaté**, jamais à la décision.
 *
 * @see docs/03-services/payments/08-refunds.md
 * @see docs/04-decisions/adr-0011-refunds.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Référence logique, comme partout ailleurs dans ce module.
            $table->uuid('payment_intent_id');

            // Recopiés depuis l'intention : un remboursement doit rester
            // explicable même si l'objet payé a disparu du produit.
            $table->string('subject_type', 40);
            $table->uuid('subject_id');

            $table->bigInteger('amount');
            $table->char('currency', 3);

            /*
             * Toujours renseigné, et c'est délibéré.
             *
             * Un remboursement est un geste dont quelqu'un devra rendre compte.
             * Un motif facultatif serait vide dans neuf cas sur dix, et le
             * dixième est celui qu'on cherchera un an plus tard.
             */
            $table->string('reason', 255);

            $table->string('status', 20)->default('pending');

            /*
             * `null` = décaissement **manuel**, hors plateforme.
             *
             * C'est le cas nominal aujourd'hui : aucun adaptateur de
             * décaissement n'existe, et en écrire un sans bac à sable
             * reproduirait l'erreur du canal SMS — intégralement écrit, jamais
             * exécuté contre une vraie passerelle. Sur de l'argent qui **sort**,
             * la faute serait plus chère.
             */
            $table->string('provider', 30)->nullable();
            $table->string('provider_ref', 120)->nullable();

            $table->string('failure_code', 60)->nullable();
            $table->text('failure_reason')->nullable();

            // Qui a décidé. Un utilisateur, une clé d'API, ou `null` pour la
            // console — jamais anonyme sans raison.
            $table->uuid('requested_by')->nullable();
            $table->string('requested_via', 20)->default('api');

            $table->string('idempotency_key', 255)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['payment_intent_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check
                       CHECK (status IN ('pending','processing','succeeded','failed','cancelled'))");

        // Un remboursement de zéro n'est pas un remboursement, et un montant
        // négatif serait un encaissement déguisé.
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check
                       CHECK (amount > 0)');

        /*
         * Idempotence **scopée au paiement**.
         *
         * Rejouer une requête ne doit pas rendre l'argent deux fois. C'est le
         * miroir exact du double débit — sauf qu'ici l'erreur ne se voit pas sur
         * le relevé du client, qui n'a aucune raison de la signaler.
         *
         * Scopée au paiement plutôt que globale, pour la même raison que du côté
         * des intentions : deux produits dérivant leurs clés du métier
         * (`refund-1`) se renverraient sinon mutuellement leurs remboursements.
         */
        DB::statement(
            'CREATE UNIQUE INDEX refunds_idempotency_unique
             ON refunds (payment_intent_id, idempotency_key)
             WHERE idempotency_key IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
