<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table append-only : ni UPDATE, ni DELETE, donc pas d'`updated_at`.
 *
 * @see docs/03-services/identity/02-data-model.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Les acteurs peuvent disparaître ; l'action, elle, est conservée.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->string('action', 100);
            $table->string('target_type', 100)->nullable();
            $table->uuid('target_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('payload')->default('{}');
            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
