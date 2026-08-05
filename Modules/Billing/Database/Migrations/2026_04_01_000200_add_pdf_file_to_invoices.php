<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le PDF d'une facture, produit une fois et figé.
 *
 * @see docs/04-decisions/adr-0013-invoice-pdf-frozen.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            /*
             * Référence **logique** vers `files`, sans clé étrangère.
             *
             * Une contrainte inter-modules imposerait un ordre de migration que
             * rien ne documente, et empêcherait de déployer les deux
             * séparément. C'est la règle déjà appliquée entre Billing et
             * Payments, et un test d'architecture la vérifie.
             */
            $table->uuid('pdf_file_id')->nullable()->after('billing_details');

            // Distinct de la présence du fichier : une mise en page qui a
            // échoué doit se voir, et se réessayer, sans qu'on relise le
            // module de stockage pour le savoir.
            $table->timestamp('pdf_rendered_at')->nullable()->after('pdf_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['pdf_file_id', 'pdf_rendered_at']);
        });
    }
};
