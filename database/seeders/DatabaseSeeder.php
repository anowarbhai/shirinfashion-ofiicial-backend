<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\AdminPermission;
use App\Models\AdminRole;
use App\Models\MediaAsset;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Review;
use App\Models\Slider;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate([
            'email' => 'admin@shirinfashionbd.test',
        ], [
            'name' => 'Shirin Admin',
            'phone' => '+8801700000000',
            'role' => 'admin',
            'marketing_opt_in' => true,
            'password' => Hash::make('password'),
        ]);

        $superAdminRole = AdminRole::updateOrCreate(
            ['slug' => 'super-admin'],
            [
                'name' => 'Super Admin',
                'description' => 'Default system role with full access to every admin feature.',
                'is_system' => true,
                'is_active' => true,
            ],
        );

        $canDoEverythingPermission = AdminPermission::updateOrCreate(
            ['slug' => 'system.everything'],
            [
                'name' => 'Can do everything',
                'group' => 'system',
                'description' => 'Allows full access to all current and future admin capabilities.',
                'is_active' => true,
            ],
        );

        $superAdminRole->permissions()->syncWithoutDetaching([
            $canDoEverythingPermission->id,
        ]);

        $admin->update([
            'admin_role_id' => $superAdminRole->id,
            'status' => 'active',
        ]);

        $customer = User::updateOrCreate([
            'email' => 'customer@shirinfashionbd.test',
        ], [
            'name' => 'Maliha Sultana',
            'phone' => '+8801800000000',
            'address' => 'House 14, Road 7, Dhanmondi, Dhaka',
            'role' => 'customer',
            'status' => 'active',
            'marketing_opt_in' => true,
            'password' => Hash::make('password'),
        ]);

        $categories = collect([
            [
                'name' => 'Skincare',
                'slug' => 'skincare',
                'description' => 'Hydrating, barrier-supportive skincare essentials.',
                'seo_title' => 'Premium Skincare',
                'seo_description' => 'Shop luxury skincare for glow, hydration, and repair.',
                'is_featured' => true,
            ],
            [
                'name' => 'Complexion',
                'slug' => 'complexion',
                'description' => 'Studio-finish complexion products with skincare benefits.',
                'seo_title' => 'Complexion Makeup',
                'seo_description' => 'Buildable foundation and SPF complexion products.',
                'is_featured' => true,
            ],
            [
                'name' => 'Lips',
                'slug' => 'lips',
                'description' => 'Comfortable nude lip colors with premium textures.',
                'seo_title' => 'Luxury Lipstick',
                'seo_description' => 'Satin lipstick and lip essentials.',
                'is_featured' => false,
            ],
        ])->mapWithKeys(function (array $category) {
            $model = Category::updateOrCreate(
                ['slug' => $category['slug']],
                $category,
            );

            return [$category['slug'] => $model];
        });

        $products = [
            [
                'category' => 'skincare',
                'name' => 'Rose Quartz Serum',
                'slug' => 'rose-quartz-serum',
                'sku' => 'SBA-RQS-001',
                'brand' => 'Atelier Skin',
                'short_description' => 'Radiance serum for luminous, glassy skin.',
                'description' => 'A peptide-rich radiance serum that smooths texture, amplifies glow, and supports a plump glass-skin finish.',
                'price' => 58,
                'compare_price' => 68,
                'rating' => 4.9,
                'review_count' => 2,
                'inventory' => 58,
                'badge' => 'Best Seller',
                'skin_types' => ['Dry', 'Normal', 'Combination'],
                'gallery' => ['https://cdn.shirinfashionbd.com/products/rose-quartz-serum.svg'],
                'highlights' => ['Niacinamide', 'Peptide complex', 'Soft-focus finish'],
                'ingredients' => ['Niacinamide', 'Peptides', 'Rose water', 'Squalane'],
                'meta_title' => 'Rose Quartz Serum',
                'meta_description' => 'Premium hydrating face serum with peptides and niacinamide.',
                'is_active' => true,
                'is_featured' => true,
            ],
            [
                'category' => 'complexion',
                'name' => 'Velvet Veil Foundation',
                'slug' => 'velvet-veil-foundation',
                'sku' => 'SBA-VVF-002',
                'brand' => 'Studio Tint',
                'short_description' => 'Soft matte foundation with skincare actives.',
                'description' => 'Weightless medium-coverage foundation with skincare actives for a blurred, premium editorial finish.',
                'price' => 46,
                'compare_price' => 54,
                'rating' => 4.8,
                'review_count' => 1,
                'inventory' => 84,
                'badge' => 'Soft Matte',
                'skin_types' => ['Combination', 'Oily', 'Normal'],
                'gallery' => ['https://cdn.shirinfashionbd.com/products/velvet-veil-foundation.svg'],
                'highlights' => ['Medium coverage', 'Skin-flex finish', 'Humidity safe'],
                'ingredients' => ['Ceramides', 'Hyaluronic acid', 'Vitamin E'],
                'meta_title' => 'Velvet Veil Foundation',
                'meta_description' => 'Buildable premium foundation with a soft matte finish.',
                'is_active' => true,
                'is_featured' => true,
            ],
            [
                'category' => 'lips',
                'name' => 'Nude Satin Lipstick',
                'slug' => 'nude-satin-lipstick',
                'sku' => 'SBA-NSL-003',
                'brand' => 'Velour Edit',
                'short_description' => 'Creamy nude lipstick with satin payoff.',
                'description' => 'Creamy nude lipstick with satin payoff, nourishing oils, and a modern neutral tone.',
                'price' => 28,
                'compare_price' => 34,
                'rating' => 4.7,
                'review_count' => 1,
                'inventory' => 120,
                'badge' => 'New Shade',
                'skin_types' => ['All'],
                'gallery' => ['https://cdn.shirinfashionbd.com/products/nude-satin-lipstick.svg'],
                'highlights' => ['Nourishing oils', 'Satin shine', 'Comfort wear'],
                'ingredients' => ['Jojoba oil', 'Shea butter', 'Vitamin C'],
                'meta_title' => 'Nude Satin Lipstick',
                'meta_description' => 'Elegant satin nude lipstick for everyday premium makeup looks.',
                'is_active' => true,
                'is_featured' => false,
            ],
            [
                'category' => 'skincare',
                'name' => 'Moonflower Night Cream',
                'slug' => 'moonflower-night-cream',
                'sku' => 'SBA-MNC-004',
                'brand' => 'Lune Botanique',
                'short_description' => 'Restorative overnight barrier cream.',
                'description' => 'Rich overnight cream with botanical lipids and peptides for next-morning bounce and softness.',
                'price' => 64,
                'compare_price' => 74,
                'rating' => 4.9,
                'review_count' => 0,
                'inventory' => 42,
                'badge' => 'Night Repair',
                'skin_types' => ['Dry', 'Sensitive', 'Combination'],
                'gallery' => ['https://cdn.shirinfashionbd.com/products/moonflower-night-cream.svg'],
                'highlights' => ['Overnight repair', 'Barrier recovery', 'Velvet texture'],
                'ingredients' => ['Botanical lipids', 'Peptides', 'Bakuchiol'],
                'meta_title' => 'Moonflower Night Cream',
                'meta_description' => 'Luxury night cream for restorative hydration and smoother texture.',
                'is_active' => true,
                'is_featured' => true,
            ],
        ];

        $productModels = collect($products)->mapWithKeys(function (array $product) use ($categories) {
            $category = $product['category'];
            unset($product['category']);

            $model = Product::updateOrCreate(
                ['slug' => $product['slug']],
                [
                    ...$product,
                    'category_id' => $categories[$category]->id,
                ],
            );

            return [$product['slug'] => $model];
        });

        $productModels['rose-quartz-serum']->categories()->sync([
            $categories['skincare']->id,
        ]);
        $productModels['velvet-veil-foundation']->categories()->sync([
            $categories['complexion']->id,
        ]);
        $productModels['nude-satin-lipstick']->categories()->sync([
            $categories['lips']->id,
        ]);
        $productModels['moonflower-night-cream']->categories()->sync([
            $categories['skincare']->id,
        ]);

        $tags = collect([
            [
                'name' => 'Cream',
                'slug' => 'cream',
            ],
            [
                'name' => 'Glow',
                'slug' => 'glow',
            ],
        ])->mapWithKeys(function (array $tag) {
            $model = Tag::updateOrCreate(
                ['slug' => $tag['slug']],
                $tag,
            );

            return [$tag['slug'] => $model];
        });

        $productModels['moonflower-night-cream']->tags()->sync([
            $tags['cream']->id,
        ]);

        $productModels['rose-quartz-serum']->tags()->sync([
            $tags['glow']->id,
        ]);

        collect([
            [
                'name' => 'Size',
                'slug' => 'pa_size',
                'terms' => ['L', 'M', 'S', 'XL', 'XXL'],
            ],
        ])->each(function (array $attribute): void {
            $model = ProductAttribute::updateOrCreate(
                ['slug' => $attribute['slug']],
                [
                    'name' => $attribute['name'],
                    'slug' => $attribute['slug'],
                ],
            );

            $model->terms()->delete();
            $model->terms()->createMany(
                collect($attribute['terms'])->map(fn (string $term) => [
                    'name' => $term,
                    'slug' => \Illuminate\Support\Str::slug($term) ?: strtolower($term),
                ])->all(),
            );
        });

        collect([
            [
                'product' => 'rose-quartz-serum',
                'author_name' => 'Maliha S.',
                'rating' => 5,
                'title' => 'Instant glow',
                'body' => 'The serum layers beautifully and gives my skin a calm, hydrated finish.',
                'status' => 'approved',
                'is_featured' => true,
            ],
            [
                'product' => 'rose-quartz-serum',
                'author_name' => 'Afsana R.',
                'rating' => 5,
                'title' => 'Luxury feel',
                'body' => 'Looks and feels premium, and it plays well under makeup.',
                'status' => 'approved',
                'is_featured' => true,
            ],
            [
                'product' => 'velvet-veil-foundation',
                'author_name' => 'Noor T.',
                'rating' => 4,
                'title' => 'Soft matte done right',
                'body' => 'Good coverage without feeling heavy.',
                'status' => 'approved',
                'is_featured' => false,
            ],
        ])->each(function (array $review) use ($productModels, $customer): void {
            Review::updateOrCreate(
                [
                    'product_id' => $productModels[$review['product']]->id,
                    'author_name' => $review['author_name'],
                    'title' => $review['title'],
                ],
                [
                    'user_id' => $customer->id,
                    'rating' => $review['rating'],
                    'body' => $review['body'],
                    'status' => $review['status'],
                    'is_featured' => $review['is_featured'],
                ],
            );
        });

        collect([
            [
                'eyebrow' => 'Best price',
                'title' => 'The best look anytime, anywhere.',
                'subtitle' => '100% guaranty on quality',
                'image_url' => 'http://127.0.0.1:8000/storage/media/2026/04/dd4c43ab-f7e6-4bb1-89d9-eedd90769eb4-1759040957294_IMG-20250807-204210.webp',
                'floating_image_url' => 'http://127.0.0.1:8000/storage/media/2026/04/1aaa0c6c-2ce7-47e5-8c09-72937a2cfcf3-Zafran-Hair-Growth-Therapy-1.jpg-e1734779178155.webp',
                'badge_text' => 'BEST price',
                'primary_button_label' => 'Buy Now',
                'primary_button_url' => '/shop',
                'secondary_button_label' => 'Read More',
                'secondary_button_url' => '/#about',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'eyebrow' => 'Hair care',
                'title' => 'Beauty rituals built for everyday confidence.',
                'subtitle' => 'Curated formulas, elegant presentation, trusted delivery',
                'image_url' => 'http://127.0.0.1:8000/storage/media/2026/04/76b15376-066f-417b-8649-cd32c88f7c9b-haircare.jpg',
                'floating_image_url' => 'http://127.0.0.1:8000/storage/media/2026/04/ce903ff2-7f92-44e8-851a-62dda9731241-1760757453685_Vip-Tinh-Chat-Boc-Body-Cream.webp',
                'badge_text' => 'Hair care',
                'primary_button_label' => 'Shop Now',
                'primary_button_url' => '/shop',
                'secondary_button_label' => 'Explore More',
                'secondary_button_url' => '/#about',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'eyebrow' => 'Skincare glow',
                'title' => 'Discover fresh arrivals with soft-glow appeal.',
                'subtitle' => 'Fast checkout, premium care, and mobile-friendly browsing',
                'image_url' => 'http://127.0.0.1:8000/storage/media/2026/04/2cb49cfb-1ea4-43af-bd3e-eb96f139e568-1760702858745_Pomegranate-Gel-Peeling-Gel-Shirin-Fashion.webp',
                'floating_image_url' => 'http://127.0.0.1:8000/storage/media/2026/04/c46fb707-4e67-4ef6-bb82-721a721edc57-1759667703453_White-Aura-Miracle-Carrot-Soap-160gm.webp',
                'badge_text' => 'Skincare glow',
                'primary_button_label' => 'Buy Now',
                'primary_button_url' => '/shop',
                'secondary_button_label' => 'Read More',
                'secondary_button_url' => '/#about',
                'sort_order' => 3,
                'is_active' => true,
            ],
        ])->each(fn (array $slider) => Slider::updateOrCreate(
            ['title' => $slider['title']],
            $slider,
        ));

        collect([
            [
                'code' => 'GLOW10',
                'type' => 'percentage',
                'value' => 10,
                'minimum_order_amount' => 40,
                'usage_limit' => 100,
                'used_count' => 8,
                'starts_at' => Carbon::now()->subDays(7),
                'ends_at' => Carbon::now()->addDays(30),
                'is_active' => true,
            ],
            [
                'code' => 'NUDE15',
                'type' => 'percentage',
                'value' => 15,
                'minimum_order_amount' => 60,
                'usage_limit' => 50,
                'used_count' => 4,
                'starts_at' => Carbon::now()->subDays(2),
                'ends_at' => Carbon::now()->addDays(14),
                'is_active' => true,
            ],
        ])->each(fn (array $coupon) => Coupon::updateOrCreate(
            ['code' => $coupon['code']],
            $coupon,
        ));

        collect([
            [
                'file_name' => 'rose-quartz-serum.svg',
                'alt_text' => 'Rose Quartz Serum bottle',
                'url' => 'https://cdn.shirinfashionbd.com/products/rose-quartz-serum.svg',
                'disk' => 'cdn',
                'mime_type' => 'image/svg+xml',
                'size_bytes' => 18452,
                'width' => 640,
                'height' => 640,
                'metadata' => ['folder' => 'products'],
            ],
            [
                'file_name' => 'velvet-veil-foundation.svg',
                'alt_text' => 'Velvet Veil Foundation bottle',
                'url' => 'https://cdn.shirinfashionbd.com/products/velvet-veil-foundation.svg',
                'disk' => 'cdn',
                'mime_type' => 'image/svg+xml',
                'size_bytes' => 17820,
                'width' => 640,
                'height' => 640,
                'metadata' => ['folder' => 'products'],
            ],
        ])->each(fn (array $media) => MediaAsset::updateOrCreate(
            ['file_name' => $media['file_name']],
            $media,
        ));

        $order = Order::updateOrCreate([
            'order_number' => 'SBA-2048',
        ], [
            'user_id' => $customer->id,
            'customer_name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => 'shipped',
            'payment_method' => 'stripe',
            'payment_status' => 'paid',
            'subtotal' => 97,
            'discount_total' => 10,
            'shipping_total' => 8,
            'grand_total' => 95,
            'shipping_address' => [
                'address' => 'House 14, Road 7, Dhanmondi',
                'city' => 'Dhaka',
                'country' => 'Bangladesh',
            ],
            'tracking_number' => 'TRK-204820',
            'placed_at' => Carbon::now()->subDays(5),
        ]);

        $order->items()->delete();
        $order->items()->createMany([
            [
                'product_id' => $productModels['rose-quartz-serum']->id,
                'product_name' => 'Rose Quartz Serum',
                'sku' => 'SBA-RQS-001',
                'price' => 58,
                'quantity' => 1,
                'line_total' => 58,
            ],
            [
                'product_id' => $productModels['velvet-veil-foundation']->id,
                'product_name' => 'Velvet Veil Foundation',
                'sku' => 'SBA-VVF-002',
                'price' => 46,
                'quantity' => 1,
                'line_total' => 46,
            ],
        ]);
    }
}
