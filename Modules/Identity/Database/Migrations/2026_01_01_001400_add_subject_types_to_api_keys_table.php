<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le périmètre d'objets qu'une clé peut faire payer.
 *
 * Le scope `payments.charge` dit qu'une clé peut **déclarer un prix**. Il ne dit
 * pas sur quoi. Sans cette restriction, une clé fuitée permettrait de déclarer
 * 100 XAF sur une facture d'abonnement, ou de manipuler les objets d'un autre
 * produit.
 *
 * Encoder le type dans le scope aurait été l'autre voie : `IssueApiKey::SCOPES`
 * est une liste fermée, et y ajouter une entrée par produit la ferait croître
 * sans fin. Une colonne interrogeable dit la même chose sans figer le catalogue
 * dans du code.
 *
 * `null` — le cas de toutes les clés existantes — signifie **aucun type
 * autorisé**, et non « tous ». Un défaut ouvert transformerait la migration
 * elle-même en élargissement de droits.
 *
 * @see docs/04-decisions/adr-0010-external-payment-api.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->jsonb('subject_types')->nullable()->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('subject_types');
        });
    }
};
