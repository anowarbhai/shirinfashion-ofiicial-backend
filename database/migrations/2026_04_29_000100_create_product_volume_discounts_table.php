<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_volume_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('free_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('flat_price', 12, 2);
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_volume_discounts');
    }
};
