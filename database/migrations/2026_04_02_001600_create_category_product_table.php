<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['category_id', 'product_id']);
        });

        $now = now();
        $pairs = DB::table('products')
            ->select(['id as product_id', 'category_id'])
            ->whereNotNull('category_id')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id,
                'product_id' => $row->product_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($pairs !== []) {
            DB::table('category_product')->insert($pairs);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
