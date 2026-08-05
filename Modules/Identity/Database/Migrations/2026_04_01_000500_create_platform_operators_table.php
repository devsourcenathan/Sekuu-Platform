<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui agit au nom de Sekuu.
 *
 * Jusqu'ici, personne : tous les rôles sont portés par une organisation, et un
 * utilisateur agit toujours au nom d'un client.
 *
 * Cette table n'est **jamais écrite par une route**. Elle se peuple par
 * `identity:operator` ou directement en base — c'est ce qui empêche un `owner`
 * de se promouvoir lui-même par `roles.assign`.
 *
 * @see docs/04-decisions/adr-0018-platform-operator.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_operators', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Un utilisateur supprimé cesse d'être opérateur, sans laisser de
            // ligne orpheline qui redeviendrait active si l'identifiant était
            // réattribué.
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();

            /*
             * Des permissions séparées, jamais un drapeau.
             *
             * Un booléen unique lierait le droit de corriger un quota à celui
             * de lire les factures de tous les clients — et ce lien ne se
             * défait plus une fois posé.
             */
            $table->jsonb('permissions')->default('[]');

            // Qui a octroyé, et quand. Un pouvoir sans provenance est un
            // pouvoir que personne n'assume.
            $table->uuid('granted_by')->nullable();
            $table->timestamp('granted_at');

            // Révocation par date plutôt que suppression : on garde la trace
            // qu'un accès a existé.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_operators');
    }
};
