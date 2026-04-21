<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_id')->constrained('storefront_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('storefront_menu_items')->nullOnDelete();
            $table->string('title');
            $table->string('type')->default('custom_url');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('url')->nullable();
            $table->boolean('target_blank')->default(false);
            $table->string('css_class')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_menu_items');
    }
};
