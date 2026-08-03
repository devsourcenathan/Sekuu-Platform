<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/03-services/notify/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            // Le canal interne n'a pas de fournisseur : la notification **est**
            // l'entrée de boîte de réception. Son état de lecture vit donc ici.
            $table->timestamp('read_at')->nullable()->after('failed_reason');
        });

        DB::statement(
            'CREATE INDEX notifications_inbox_index
             ON notifications (user_id, read_at, created_at DESC)
             WHERE channel = \'in_app\''
        );

        // La purge supprime les notifications, pas l'histoire : sans cet
        // agrégat, douze mois de statistiques disparaîtraient avec elles.
        Schema::create('notification_statistics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('day');
            $table->uuid('organization_id')->nullable();
            $table->string('channel', 20);
            $table->string('category', 20);
            $table->string('status', 20);
            $table->unsignedInteger('total')->default(0);
            $table->timestamps();

            $table->index(['day', 'channel']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX notification_statistics_unique
             ON notification_statistics (day, channel, category, status, COALESCE(organization_id, \'00000000-0000-0000-0000-000000000000\'))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_statistics');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('read_at');
        });
    }
};
