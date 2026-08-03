<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @see docs/03-services/identity/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('slug', 100);
            $table->char('country', 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 10)->default('fr');
            $table->text('logo_url')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        DB::statement(
            'CREATE UNIQUE INDEX organizations_slug_unique ON organizations (slug) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
