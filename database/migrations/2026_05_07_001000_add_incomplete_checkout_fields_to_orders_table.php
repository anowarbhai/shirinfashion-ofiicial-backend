<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'cart_session_id')) {
                $table->string('cart_session_id')->nullable()->index()->after('device_id');
            }

            if (! Schema::hasColumn('orders', 'cart_hash')) {
                $table->string('cart_hash', 64)->nullable()->index()->after('cart_session_id');
            }

            if (! Schema::hasColumn('orders', 'normalized_phone')) {
                $table->string('normalized_phone', 30)->nullable()->index()->after('phone');
            }

            if (! Schema::hasColumn('orders', 'normalized_address_hash')) {
                $table->string('normalized_address_hash', 64)->nullable()->index()->after('shipping_address');
            }

            if (! Schema::hasColumn('orders', 'last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable()->index()->after('placed_at');
            }

            if (! Schema::hasColumn('orders', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->index()->after('last_activity_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $columns = [
                'cart_session_id',
                'cart_hash',
                'normalized_phone',
                'normalized_address_hash',
                'last_activity_at',
                'completed_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
