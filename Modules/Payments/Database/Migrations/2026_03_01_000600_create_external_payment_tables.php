<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De quoi encaisser pour un produit qui ne partage pas cette base de code.
 *
 * @see docs/03-services/payments/07-external-api.md
 * @see docs/04-decisions/adr-0010-external-payment-api.md
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Le prix, déclaré par le produit et **écrit avant tout paiement**.
         *
         * C'est l'analogue de `invoices` pour un produit externe : l'objet dont
         * le propriétaire nomme le prix. Sans lui, le montant devrait traverser
         * `InitiatePayment` en paramètre — c'est-à-dire exactement la signature
         * que `PayableQuote` existe pour ne jamais offrir.
         *
         * Le montant reste donc **lu en base** par `quote()`, comme pour une
         * facture. Ce qui change n'est pas le mécanisme, c'est qui a rempli la
         * ligne : un produit authentifié par une clé scopée, au lieu de Billing.
         */
        Schema::create('external_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Le produit propriétaire, via l'organisation qui porte la clé.
            // Référence logique : cette table ne doit pas empêcher de supprimer
            // une organisation, et une charge payée survit à son émetteur.
            $table->uuid('organization_id');

            // Quelle clé a déclaré ce prix. Sans cette trace, une clé fuitée
            // puis révoquée ne laisserait aucun moyen de savoir ce qu'elle a
            // facturé.
            $table->uuid('api_key_id')->nullable();

            $table->string('subject_type', 40);
            $table->uuid('subject_id');

            // L'apprenant Learn n'est pas un utilisateur Sekuu. `payer_type` ne
            // peut d'ailleurs jamais valoir `identity.*` par cette voie : un
            // produit externe n'a pas à se prétendre un compte de la plateforme.
            $table->string('payer_type', 40);
            $table->uuid('payer_id');

            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('description', 255);

            $table->string('status', 20)->default('pending');
            $table->uuid('payment_intent_id')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE external_charges ADD CONSTRAINT external_charges_status_check
                       CHECK (status IN ('pending','paid','failed','expired'))");
        DB::statement('ALTER TABLE external_charges ADD CONSTRAINT external_charges_amount_check
                       CHECK (amount > 0)');

        /*
         * Une seule charge en attente par objet, en miroir de l'unicité des
         * intentions.
         *
         * Sans elle, `quote()` aurait à choisir entre deux prix déclarés pour le
         * même objet — et choisirait forcément mal une fois sur deux.
         */
        DB::statement(
            "CREATE UNIQUE INDEX external_charges_one_pending_per_subject
             ON external_charges (subject_type, subject_id)
             WHERE status = 'pending'"
        );

        /*
         * Où livrer l'issue, et avec quel secret la signer.
         *
         * Un endpoint par organisation. Il n'est pas créé par l'API : déclarer
         * une destination sortante est une configuration permanente, du même
         * ordre qu'une règle de redirection de courrier. Elle passe par la
         * commande `payments:endpoint`, donc par un opérateur de la plateforme.
         */
        Schema::create('payment_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->string('url', 500);

            $table->string('secret', 120);

            /*
             * Rotation sans coupure.
             *
             * Pendant la fenêtre, chaque livraison porte **deux** signatures :
             * l'ancienne et la nouvelle. Le produit met à jour son secret quand
             * il veut, sans qu'aucun message ne soit rejeté entre-temps.
             *
             * Une coupure nette aurait été plus simple à écrire et aurait fait
             * échouer toutes les livraisons d'un produit qui déploie une heure
             * plus tard — c'est-à-dire des clients payés sans service.
             */
            $table->string('previous_secret', 120)->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();

            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE payment_endpoints ADD CONSTRAINT payment_endpoints_status_check
                       CHECK (status IN ('active','paused'))");

        /*
         * Les livraisons sortantes, une ligne par événement et par endpoint.
         *
         * Persistées avant tout appel : une livraison qui n'aboutit jamais doit
         * rester **visible**, pas disparaître avec la tâche qui la portait.
         */
        Schema::create('payment_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_endpoint_id')
                ->constrained('payment_endpoints')->cascadeOnDelete();

            // L'identifiant que le produit doit dédupliquer. Stable d'un
            // réessai à l'autre : c'est tout son intérêt.
            $table->string('event_id', 60)->unique();

            $table->string('event_type', 60);
            $table->uuid('payment_intent_id')->nullable();
            $table->jsonb('payload');

            $table->string('status', 20)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('last_status_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('payment_intent_id');
        });

        DB::statement("ALTER TABLE payment_deliveries ADD CONSTRAINT payment_deliveries_status_check
                       CHECK (status IN ('pending','delivered','exhausted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_deliveries');
        Schema::dropIfExists('payment_endpoints');
        Schema::dropIfExists('external_charges');
    }
};
