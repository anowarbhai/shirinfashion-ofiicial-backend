<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BlogCategory;
use App\Models\BlogPost;

class DemoBlogPostSeeder extends Seeder
{
    public function run(): void
    {
        $catFashion = BlogCategory::firstOrCreate(
            ['slug' => 'fashion-trends'],
            ['name' => 'Fashion & Trends', 'description' => 'Discover the latest clothing & style trends.', 'is_active' => true]
        );

        BlogCategory::firstOrCreate(
            ['slug' => 'styling-tips'],
            ['name' => 'Styling Tips', 'description' => 'Expert tips on how to style your wardrobe.', 'is_active' => true]
        );

        BlogCategory::firstOrCreate(
            ['slug' => 'festive-collection'],
            ['name' => 'Festive Collection', 'description' => 'Special outfits for weddings, Eid, and festivals.', 'is_active' => true]
        );

        // Men's fashion trend post
        BlogPost::updateOrCreate(
            ['slug' => 'top-5-mens-fashion-trends-for-2026-elevate-your-style-with-bd-caliph'],
            [
                'title' => 'Top 5 Men\'s Fashion Trends for 2026: Elevate Your Style with BD Caliph',
                'category_id' => $catFashion->id,
                'author_name' => 'DigiTrix Labs',
                'status' => 'published',
                'is_featured' => true,
                'views_count' => 285,
                'featured_image' => '/images/bd_caliph_blog_cover.jpg',
                'excerpt' => 'Discover the latest Bangladeshi men\'s fashion trends for 2026 with BD Caliph. From tailored panjabis to modern casual shirts, elevate your everyday look effortlessly.',
                'content' => '
<h2>Redefining Men\'s Style &amp; Elegance in 2026 with BD Caliph</h2>
<p>Modern men’s fashion in Bangladesh is undergoing an unprecedented transformation. Today’s man demands clothes that blend crisp tailored elegance with relaxed daily comfort. Whether you are dressing for a corporate meeting, a weekend hanging out, or a festive celebration, <strong>BD Caliph</strong> brings you the top 5 men’s fashion trends dominating 2026.</p>

<img src="https://images.unsplash.com/photo-1617137984095-74e4e5e3613f?q=80&w=1000" alt="BD Caliph Men Fashion Trends 2026" />

<h2>1. Minimalist Tailored Panjabis with Metallic Accents</h2>
<p>Traditional panjabis are receiving a contemporary upgrade. Clean cuts, breathable cotton-viscose fabrics, subtle collar embroidery, and sleek metallic buttons define 2026 festive Panjabi trends.</p>

<h2>2. Cuban Collar &amp; Textured Linen Shirts</h2>
<p>For casual &amp; smart-casual outings, Cuban collar shirts in textured linen and soft pastel shades provide an effortlessly stylish, breathable look perfect for South Asian weather.</p>

<blockquote>
<p>"Style is not just what you wear, but how you feel in it. Quality fabrics and immaculate fitting define the BD Caliph gentleman."</p>
</blockquote>

<h2>3. Tapered Chinos &amp; Tailored Trousers</h2>
<p>Overly tight denim is out. In 2026, relaxed-tapered chinos in classic navy, olive, beige, and charcoal black are essential staples that pair seamlessly with both t-shirts and formal shirts.</p>

<h2>4. Layered Overshirts &amp; Lightweight Jackets</h2>
<p>Layering adds instant depth to any outfit. Wearing an unbuttoned utility overshirt or lightweight linen jacket over a clean solid crewneck tee elevates your look in seconds.</p>

<h2>5. Premium Accessories &amp; Minimal Footwear</h2>
<p>Complete your ensemble with handcrafted leather slide sandals or clean minimalist sneakers, paired with a classic watch.</p>

<p>Explore the full 2026 Men’s Collection on <strong>BD Caliph</strong> today!</p>
',
                'meta_title' => 'Top 5 Men\'s Fashion Trends for 2026 | BD Caliph',
                'meta_description' => 'Explore 2026\'s top men fashion trends in Bangladesh. Premium panjabis, shirts, and trousers curated by BD Caliph.',
                'meta_keywords' => 'mens fashion 2026, panjabi bangladesh, BD Caliph, digitrix labs',
                'published_at' => now(),
            ]
        );

        // Women's fashion trend post
        BlogPost::updateOrCreate(
            ['slug' => 'top-5-womens-fashion-trends-2026'],
            [
                'title' => 'Top 5 Women\'s Fashion Trends for 2026: Elevate Your Style with BD Caliph',
                'category_id' => $catFashion->id,
                'author_name' => 'DigiTrix Labs',
                'status' => 'published',
                'is_featured' => false,
                'views_count' => 142,
                'featured_image' => 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?q=80&w=1200',
                'excerpt' => 'Discover the latest Bangladeshi women\'s fashion trends for 2026 with BD Caliph. From handcrafted zardosi embroidery to vibrant pastel palettes, here is your ultimate guide to looking effortlessly elegant.',
                'content' => '
<h2>Redefining Grace &amp; Modern Fashion in 2026 with BD Caliph</h2>
<p>Fashion is constantly evolving, but true elegance remains timeless. As we enter 2026, women’s fashion in Bangladesh is witnessing a breathtaking blend of traditional heritage craftsmanship and modern minimal aesthetics. Whether you are preparing for a festive family gathering, a wedding event, or seeking chic everyday attire, <strong>BD Caliph</strong> brings you the top fashion trends shaping this season.</p>

<img src="https://images.unsplash.com/photo-1490481651871-ab68de25d43d?q=80&w=1000" alt="BD Caliph Fashion Trends 2026" />

<h2>1. Pastel Palettes &amp; Soft Earthy Tones</h2>
<p>Soft pastel shades such as powder blue, blush pink, mint green, and warm beige are taking center stage at BD Caliph this year.</p>

<h2>2. Handcrafted Zardosi &amp; Thread Embroidery</h2>
<p>Delicate embroidery never goes out of style. Fine zardosi work and thread embroidery add a subtle touch of luxury to BD Caliph traditional outfits.</p>

<p>Explore the full 2026 collection on <strong>BD Caliph</strong> today!</p>
',
                'meta_title' => 'Top 5 Women\'s Fashion Trends for 2026 | BD Caliph',
                'meta_description' => 'Explore 2026\'s top women fashion trends in Bangladesh. Premium salwar kameez, sarees, and modern fusion wear curated by BD Caliph.',
                'meta_keywords' => 'fashion trends 2026, ladies dresses, salwar kameez bangladesh, BD Caliph, digitrix labs',
                'published_at' => now(),
            ]
        );
    }
}
