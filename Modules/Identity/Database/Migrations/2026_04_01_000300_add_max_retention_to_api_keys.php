<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le plafond de rétention qu'une clé peut poser sur **nos** destinations.
 *
 * Zéro par défaut, et c'est tout l'intérêt : un produit externe ne peut rendre
 * aucun octet indestructible tant qu'on ne le lui a pas accordé. Sans ce
 * plafond, n'importe quel produit pourrait figer à trente ans ce qu'il dépose
 * chez nous, et nous laisser la facture d'un stockage que plus personne ne peut
 * effacer — y compris après son départ.
 *
 * Sur sa propre destination, aucune borne : il n'engage que sa facture cloud.
 *
 * @see docs/03-services/storage/07-external-api.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->integer('max_retention_days')->default(0)->after('subject_types');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('max_retention_days');
        });
    }
};
