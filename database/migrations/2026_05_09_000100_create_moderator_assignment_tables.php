<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('digital_marketer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('assignment_order')->default(1)->index();
            $table->timestamps();
        });

        Schema::create('product_moderator_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('moderator_id')->constrained('moderators')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('assignment_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('order_status_type', 30)->index();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('last_moderator_id')->nullable()->constrained('moderators')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_status_type', 'scope_type', 'scope_id'], 'assignment_counter_unique');
        });

        Schema::create('order_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('moderator_id')->nullable()->constrained('moderators')->nullOnDelete();
            $table->string('order_status_type', 30)->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_type', 40)->default('auto_round_robin')->index();
            $table->string('status', 40)->default('assigned')->index();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'order_item_id'], 'order_assignment_unique');
            $table->index(['moderator_id', 'order_status_type', 'status'], 'order_assignment_filter_index');
        });

        Schema::create('order_assignment_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('previous_moderator_id')->nullable()->constrained('moderators')->nullOnDelete();
            $table->foreignId('new_moderator_id')->nullable()->constrained('moderators')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_status_type', 30)->index();
            $table->string('change_type', 40)->index();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'assigned_moderator_id')) {
                $table->foreignId('assigned_moderator_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'assignment_status')) {
                $table->string('assignment_status', 40)->nullable()->after('status')->index();
            }

            if (! Schema::hasColumn('orders', 'assignment_type')) {
                $table->string('assignment_type', 40)->nullable()->after('assignment_status')->index();
            }

            if (! Schema::hasColumn('orders', 'assignment_status_type')) {
                $table->string('assignment_status_type', 30)->nullable()->after('assignment_type')->index();
            }
        });

        $now = now();
        foreach (['processing', 'incomplete'] as $statusType) {
            DB::table('assignment_counters')->updateOrInsert(
                ['order_status_type' => $statusType, 'scope_type' => null, 'scope_id' => null],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            foreach (['assigned_moderator_id'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['assignment_status', 'assignment_type', 'assignment_status_type'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('order_assignment_histories');
        Schema::dropIfExists('order_assignments');
        Schema::dropIfExists('assignment_counters');
        Schema::dropIfExists('product_moderator_assignments');
        Schema::dropIfExists('moderators');
    }
};
