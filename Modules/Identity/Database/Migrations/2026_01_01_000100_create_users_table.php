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
        // citext rend la comparaison des emails insensible à la casse côté
        // moteur. L'extension n'existe que sur PostgreSQL, la cible de
        // production ; ailleurs (tests) on retombe sur varchar.
        if ($this->isPostgres()) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);

            $this->isPostgres()
                ? $table->addColumn('citext', 'email')
                : $table->string('email', 255);

            $table->string('phone', 32)->nullable();
            $table->string('password_hash', 255)->nullable();
            $table->text('avatar_url')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('language', 10)->default('fr');
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('last_login_at');
        });

        // Unicité de l'email restreinte aux comptes vivants : un compte
        // supprimé ne doit pas bloquer une réinscription. Postgres et SQLite
        // supportent tous deux les index uniques partiels.
        DB::statement(
            'CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL'
        );

        // SQLite n'accepte pas l'ajout d'une contrainte après création de la
        // table ; la contrainte n'existe donc que sur la cible de production.
        if ($this->isPostgres()) {
            DB::statement(
                "ALTER TABLE users ADD CONSTRAINT users_status_check
                 CHECK (status IN ('active', 'pending', 'suspended', 'deleted'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
};
