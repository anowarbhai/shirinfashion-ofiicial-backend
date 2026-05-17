<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_cart_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 128)->nullable()->index();
            $table->string('cart_hash', 64)->index();
            $table->json('items');
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamp('last_reminded_at')->nullable()->index();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'synced_at']);
            $table->index(['device_id', 'synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_cart_snapshots');
    }
};
