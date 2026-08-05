<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cinq tables : trois pour les fichiers, deux pour les magasins où ils vivent.
 *
 * @see docs/03-services/storage/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('driver', 32);
            $table->string('preset', 32)->nullable();
            $table->jsonb('config');

            /*
             * Un unique blob chiffré plutôt qu'une colonne par champ : les
             * identifiants ne se ressemblent pas d'un pilote à l'autre — clé et
             * secret pour S3, jeton OAuth pour Drive, rien pour le disque
             * local. Le pilote sait lire ce qu'il a écrit ; personne d'autre
             * n'a à le comprendre.
             */
            $table->text('credentials')->nullable();

            // Deux colonnes de propriété plutôt qu'un couple polymorphe : les
            // clés étrangères sont ce qui garantit qu'une destination ne
            // survit pas à l'organisation qui la possède.
            $table->uuid('owner_organization_id')->nullable();
            $table->uuid('owner_api_key_id')->nullable();

            $table->string('environment', 16);
            $table->string('status', 16)->default('unverified');
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_reason', 32)->nullable();
            $table->text('verification_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'environment']);
            $table->index('owner_organization_id');
        });

        /*
         * Une seule destination par défaut et par environnement, garantie par
         * la base.
         *
         * Deux défauts donneraient un choix dépendant de l'ordre de lecture, et
         * donc des fichiers répartis au hasard entre deux comptes — le genre de
         * défaut qui ne se voit qu'en cherchant un fichier là où il n'est pas.
         */
        DB::statement(
            'CREATE UNIQUE INDEX storage_destinations_one_default_per_environment
             ON storage_destinations (environment) WHERE is_default'
        );

        Schema::create('files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();

            // Jamais interprétés par ce module : portés, indexés, remis à un
            // résolveur.
            $table->string('owner_type', 64);
            $table->string('owner_id', 64);

            // Résolue une fois à la déclaration, jamais recalculée : un fichier
            // vit là où ses octets ont été posés.
            $table->uuid('destination_id');

            $table->string('path', 512);
            $table->string('name', 255);

            // Constatés à la confirmation, jamais déclarés. La colonne est
            // nullable parce qu'avant la confirmation, on ne sait pas.
            $table->string('mime_type', 128)->nullable();
            $table->bigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();

            $table->string('status', 16)->default('pending');
            $table->string('visibility', 16)->default('private');
            $table->timestamp('retain_until')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            /*
             * Le moment où les octets ont réellement quitté le magasin,
             * distinct de la suppression logique.
             *
             * Une colonne plutôt qu'un chemin vidé : `path` porte une
             * contrainte d'unicité avec la destination, et deux fichiers purgés
             * partageraient alors la même valeur vide. C'est aussi ce qui rend
             * la purge idempotente sans avoir à relire le magasin.
             */
            $table->timestamp('purged_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('destination_id')->references('id')->on('storage_destinations');

            $table->unique(['destination_id', 'path']);
            $table->index(['owner_type', 'owner_id']);
            $table->index(['organization_id', 'status']);
        });

        // Index partiels : un balayage fréquent sur une petite fraction des
        // lignes n'a pas à porter la table entière.
        DB::statement("CREATE INDEX files_pending_sweep ON files (created_at) WHERE status = 'pending'");
        DB::statement('CREATE INDEX files_purge ON files (deleted_at) WHERE deleted_at IS NOT NULL AND purged_at IS NULL');
        DB::statement("CREATE INDEX files_live_per_destination ON files (destination_id) WHERE status <> 'deleted'");

        Schema::create('file_downloads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('file_id');
            $table->string('actor_type', 16);
            $table->string('actor_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
            $table->index(['file_id', 'created_at']);
        });

        Schema::create('storage_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('owner_type', 64)->nullable();
            $table->uuid('destination_id');
            $table->timestamps();

            $table->foreign('destination_id')->references('id')->on('storage_destinations')->cascadeOnDelete();
        });

        /*
         * Unicité y compris quand `owner_type` est nul.
         *
         * PostgreSQL ne considère pas deux `NULL` comme égaux : sans ces deux
         * index, une organisation pourrait porter deux règles « tous types »
         * contradictoires, et la résolution dépendrait de l'ordre de lecture.
         */
        DB::statement(
            'CREATE UNIQUE INDEX storage_placements_typed
             ON storage_placements (organization_id, owner_type) WHERE owner_type IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX storage_placements_catch_all
             ON storage_placements (organization_id) WHERE owner_type IS NULL'
        );

        Schema::create('storage_usage', function (Blueprint $table): void {
            $table->uuid('organization_id');
            $table->uuid('destination_id');
            $table->bigInteger('bytes_used')->default(0);
            $table->integer('file_count')->default(0);
            $table->timestamp('updated_at')->nullable();

            // Ventilé par destination : le quota ne porte que sur nos comptes.
            // Un compteur unique aurait mélangé nos octets et ceux d'un client
            // qui paie sa propre facture cloud, et refusé un téléversement que
            // rien ne nous coûte.
            $table->primary(['organization_id', 'destination_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_usage');
        Schema::dropIfExists('storage_placements');
        Schema::dropIfExists('file_downloads');
        Schema::dropIfExists('files');
        Schema::dropIfExists('storage_destinations');
    }
};
