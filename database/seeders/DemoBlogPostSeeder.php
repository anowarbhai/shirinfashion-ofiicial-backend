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

        BlogPost::create([
            'title' => 'Top 5 Women\'s Fashion Trends for 2026: Elevate Your Style with Shirin Fashion BD',
            'slug' => 'top-5-womens-fashion-trends-2026',
            'category_id' => $catFashion->id,
            'author_name' => 'DigiTrix Labs',
            'status' => 'published',
            'is_featured' => true,
            'views_count' => 142,
            'featured_image' => 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?q=80&w=1200',
            'excerpt' => 'Discover the latest Bangladeshi women\'s fashion trends for 2026. From handcrafted zardosi embroidery to vibrant pastel palettes, here is your ultimate guide to looking effortlessly elegant.',
            'content' => '
<h2>Redefining Grace &amp; Modern Fashion in 2026</h2>
<p>Fashion is constantly evolving, but true elegance remains timeless. As we enter 2026, women’s fashion in Bangladesh is witnessing a breathtaking blend of traditional heritage craftsmanship and modern minimal aesthetics. Whether you are preparing for a festive family gathering, a wedding event, or seeking chic everyday attire, Shirin Fashion BD brings you the top fashion trends shaping this season.</p>

<img src="https://images.unsplash.com/photo-1490481651871-ab68de25d43d?q=80&w=1000" alt="Fashion Trends 2026" />

<h2>1. Pastel Palettes &amp; Soft Earthy Tones</h2>
<p>Soft pastel shades such as powder blue, blush pink, mint green, and warm beige are taking center stage this year. These colors not only offer a fresh, soothing visual appeal but also allow intricate embroidery details to stand out naturally.</p>

<h2>2. Handcrafted Zardosi &amp; Thread Embroidery</h2>
<p>Delicate embroidery never goes out of style. In 2026, heavy printed designs are taking a backseat to detailed hand embroidery around necklines, cuffs, and dupattas. Fine zardosi work and thread embroidery add a subtle touch of luxury to traditional outfits.</p>

<blockquote>
<p>"True style is about expressing your unique personality with confidence and grace. Our 2026 collection celebrates every woman\'s authentic beauty."</p>
</blockquote>

<h2>3. Versatile Fusion Wear &amp; Flowy Silhouettes</h2>
<p>Modern fashion lovers appreciate comfort just as much as style. Flowy kaftans, asymmetrical kurtis, and layered three-piece sets are becoming wardrobe essentials for young professionals and fashion-forward women.</p>

<h2>4. Statement Dupattas &amp; Dupatta Draping Techniques</h2>
<p>A regal organza or silk dupatta can instantly transform a simple outfit into an ethnic masterpiece. Adding a heavily embroidered dupatta over a minimal solid salwar kameez is one of the easiest ways to make an effortless style statement.</p>

<h2>5. Sustainable &amp; Breathable Fabrics</h2>
<p>With a growing focus on comfort and quality, premium breathable fabrics like pure georgette, organza, lawn cotton, and tissue silk are preferred choices for long event days.</p>

<h3>Summary Checklist for Your 2026 Wardrobe:</h3>
<ul>
  <li>Invest in versatile neutral &amp; pastel shades.</li>
  <li>Pair minimal dresses with statement dupattas.</li>
  <li>Choose lightweight, breathable fabrics for all-day comfort.</li>
  <li>Accessorize with minimal gold or pearl jewelry for a polished look.</li>
</ul>

<p>Explore the full 2026 collection on <strong>Shirin Fashion BD</strong> today and elevate your wardrobe with high-quality, authentic fashion crafted just for you!</p>
',
            'meta_title' => 'Top 5 Women\'s Fashion Trends for 2026 | Shirin Fashion BD',
            'meta_description' => 'Explore 2026\'s top women fashion trends in Bangladesh. Premium salwar kameez, sarees, and modern fusion wear curated by Shirin Fashion BD.',
            'meta_keywords' => 'fashion trends 2026, ladies dresses, salwar kameez bangladesh, shirin fashion bd, digitrix labs',
            'published_at' => now(),
        ]);
    }
}
