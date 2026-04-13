<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_term_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_term_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'attribute_term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_term_product');
    }
};
