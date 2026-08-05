<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La photo de profil, désormais un fichier de la plateforme.
 *
 * `avatar_url` demeure et n'est pas migrée : elle porte les photos venues d'un
 * fournisseur OAuth — une URL chez Google, que nous n'hébergeons pas et n'avons
 * aucune raison de recopier. Les deux colonnes répondent à deux questions
 * différentes, et `avatar_file_id` l'emporte quand elle est renseignée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Référence logique vers `files`, sans clé étrangère : une
            // contrainte inter-modules imposerait un ordre de migration que
            // rien ne documente.
            $table->uuid('avatar_file_id')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_file_id');
        });
    }
};
