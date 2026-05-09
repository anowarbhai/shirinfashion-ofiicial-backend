<?php

use App\Models\Product;
use App\Support\MediaUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'product_image')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->text('product_image')->nullable()->after('sku');
            });
        }

        Product::query()
            ->select(['id', 'gallery'])
            ->chunkById(100, function ($products): void {
                foreach ($products as $product) {
                    $image = $product->gallery[0] ?? null;

                    if (! $image) {
                        continue;
                    }

                    DB::table('order_items')
                        ->where('product_id', $product->id)
                        ->whereNull('product_image')
                        ->update(['product_image' => MediaUrl::normalizeStored($image)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'product_image')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('product_image');
            });
        }
    }
};
