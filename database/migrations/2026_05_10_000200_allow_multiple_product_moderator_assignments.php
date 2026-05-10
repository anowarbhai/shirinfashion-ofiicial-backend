<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_moderator_assignments', function (Blueprint $table): void {
            try {
                $table->dropUnique(['product_id']);
            } catch (\Throwable) {
                // Older installs may already have the multi-moderator index.
            }
        });

        Schema::table('product_moderator_assignments', function (Blueprint $table): void {
            try {
                $table->unique(['product_id', 'moderator_id'], 'product_moderator_assignments_product_moderator_unique');
            } catch (\Throwable) {
                // Keep migration idempotent across partially updated servers.
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_moderator_assignments', function (Blueprint $table): void {
            try {
                $table->dropUnique('product_moderator_assignments_product_moderator_unique');
            } catch (\Throwable) {
                //
            }

            try {
                $table->unique('product_id');
            } catch (\Throwable) {
                //
            }
        });
    }
};
