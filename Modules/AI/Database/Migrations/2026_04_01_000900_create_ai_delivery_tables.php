<?php

declare(strict_types=1);

use App\Platform\Support\SignedWebhook;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Où livrer l'issue d'une génération, et ce qui a été livré.
 *
 * ## Deux tables de plus, et pas celles de Payments
 *
 * La forme est la même, le contenu non : une livraison de paiement porte un
 * `payment_intent_id`, celle-ci une `generation_id`. Les faire converger
 * donnerait une table qui ne décrit bien ni l'une ni l'autre, et une colonne
 * nulle dans la moitié des lignes.
 *
 * Ce qui **n'est pas** dupliqué est la signature et le garde-fou de test —
 * voir {@see SignedWebhook}. C'est là que deux
 * implémentations divergentes coûteraient vraiment quelque chose.
 *
 * @see docs/03-services/ai/07-external-api.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Une destination par organisation. Plusieurs demanderaient de
            // décider laquelle reçoit quoi, et personne n'a ce besoin.
            $table->uuid('organization_id')->unique();

            $table->string('url', 500);
            $table->string('secret', 120);

            /*
             * Rotation sans coupure : pendant la fenêtre, chaque livraison porte
             * les deux signatures. Le produit change son secret quand il veut,
             * sans qu'aucun message ne soit rejeté entre-temps.
             */
            $table->string('previous_secret', 120)->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();

            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('ai_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_endpoint_id')->constrained('ai_endpoints')->cascadeOnDelete();

            // L'identifiant que le produit doit dédupliquer. **Stable d'un
            // réessai à l'autre** : c'est tout son intérêt.
            $table->string('event_id', 60)->unique();

            $table->string('event_type', 60);

            /*
             * Nullable, et c'est ce qui distingue cette table de celle de
             * Payments : deux des quatre événements ne parlent pas d'une
             * génération. `ai.account.unverified` parle d'un compte,
             * `ai.spend.threshold_reached` d'un mois.
             */
            $table->uuid('generation_id')->nullable();

            $table->jsonb('payload');

            $table->string('status', 20)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->smallInteger('last_status_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('generation_id');
        });

        DB::statement("ALTER TABLE ai_endpoints ADD CONSTRAINT ai_endpoints_status_check
            CHECK (status IN ('active', 'paused'))");

        DB::statement("ALTER TABLE ai_deliveries ADD CONSTRAINT ai_deliveries_status_check
            CHECK (status IN ('pending', 'delivered', 'exhausted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_deliveries');
        Schema::dropIfExists('ai_endpoints');
    }
};
