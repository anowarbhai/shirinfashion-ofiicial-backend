<?php

namespace App\Services;

use App\Models\StorefrontMenu;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ThemeSettingsService
{
    public const CACHE_KEY = 'theme.settings.bundle';
    public const MENU_CACHE_KEY = 'theme.menus.bundle';

    public function getGroup(string $group): array
    {
        $stored = StorefrontSetting::query()
            ->where('key', $this->groupKey($group))
            ->value('value');

        return array_replace_recursive(
            $this->defaults()[$group] ?? [],
            is_array($stored) ? $stored : [],
        );
    }

    public function saveGroup(string $group, array $data, bool $isPublic = true): array
    {
        $merged = array_replace_recursive($this->defaults()[$group] ?? [], $data);

        StorefrontSetting::query()->updateOrCreate(
            ['key' => $this->groupKey($group)],
            [
                'group' => $group,
                'value' => $merged,
                'type' => 'json',
                'is_public' => $isPublic,
            ],
        );

        $this->flush();

        return $merged;
    }

    public function getSetting(string $path, mixed $default = null): mixed
    {
        [$group, $nested] = array_pad(explode('.', $path, 2), 2, null);

        if (!$group) {
            return $default;
        }

        $groupSettings = $this->getGroup($group);

        return $nested ? Arr::get($groupSettings, $nested, $default) : $groupSettings;
    }

    public function getPublicBundle(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            return [
                'appearance' => $this->getGroup('appearance'),
                'header' => $this->getGroup('header'),
                'footer' => $this->getGroup('footer'),
            ];
        });
    }

    public function getMenusBundle(): array
    {
        return Cache::remember(self::MENU_CACHE_KEY, now()->addHour(), function (): array {
            $menus = StorefrontMenu::query()
                ->with(['items.children'])
                ->orderBy('name')
                ->get();

            $byLocation = [];

            foreach ($menus as $menu) {
                if ($menu->location && $menu->is_active) {
                    $byLocation[$menu->location] = $this->serializeMenu($menu);
                }
            }

            return [
                'locations' => $this->menuLocations(),
                'assigned' => $byLocation,
            ];
        });
    }

    public function menuLocations(): array
    {
        return [
            ['id' => 'header_menu', 'label' => 'Header Menu'],
            ['id' => 'footer_menu_1', 'label' => 'Footer Menu 1'],
            ['id' => 'footer_menu_2', 'label' => 'Footer Menu 2'],
        ];
    }

    public function availableLinks(): array
    {
        return [
            'pages' => StorefrontPage::query()
                ->where('status', 'published')
                ->orderBy('title')
                ->get(['id', 'title', 'slug'])
                ->map(fn (StorefrontPage $page) => [
                    'id' => $page->id,
                    'label' => $page->title,
                    'slug' => $page->slug,
                    'url' => '/pages/'.$page->slug,
                ])
                ->values(),
            'categories' => \App\Models\Category::query()
                ->whereNull('parent_id')
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (\App\Models\Category $category) => [
                    'id' => $category->id,
                    'label' => $category->name,
                    'slug' => $category->slug,
                    'url' => '/shop?category='.$category->slug,
                ])
                ->values(),
            'products' => \App\Models\Product::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name', 'slug'])
                ->map(fn (\App\Models\Product $product) => [
                    'id' => $product->id,
                    'label' => $product->name,
                    'slug' => $product->slug,
                    'url' => '/products/'.$product->slug,
                ])
                ->values(),
        ];
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::MENU_CACHE_KEY);
    }

    public function defaults(): array
    {
        return [
            'appearance' => [
                'logo_url' => '',
                'favicon_url' => '',
                'company_name' => 'Shirin Fashion',
                'tagline' => 'Premium cosmetics and beauty products',
                'company_details' => 'Curated beauty products, dependable delivery, and a premium brand presence.',
                'contact' => [
                    'phone' => '+8801901856510',
                    'email' => 'hello@shirinfashionbd.com',
                    'address' => 'Shabnur Bari Road, Rashid, Doshid Bazar, Ashulia, Savar, Dhaka - 1341',
                    'hotline' => '+8801901856510',
                    'whatsapp' => '+8801901856510',
                ],
                'social_links' => [
                    'facebook' => '',
                    'instagram' => '',
                    'youtube' => '',
                    'linkedin' => '',
                    'tiktok' => '',
                    'twitter' => '',
                    'custom' => [],
                ],
                'colors' => [
                    'primary' => '#ff2b61',
                    'secondary' => '#d08969',
                    'accent' => '#1b2775',
                    'background' => '#fffaf7',
                    'text' => '#2f2523',
                ],
                'fonts' => [
                    'site_font_family' => 'Manrope',
                    'site_font_url' => '',
                    'default_font_size' => '16px',
                ],
                'headings' => [
                    'h1' => ['size' => '3rem', 'weight' => '600', 'color' => '#1f1816'],
                    'h2' => ['size' => '2.4rem', 'weight' => '600', 'color' => '#1f1816'],
                    'h3' => ['size' => '1.8rem', 'weight' => '600', 'color' => '#1f1816'],
                ],
                'body' => [
                    'font_family' => 'Manrope',
                    'font_size' => '16px',
                    'line_height' => '1.7',
                    'font_weight' => '400',
                    'color' => '#2f2523',
                ],
            ],
            'header' => [
                'active_style' => 'style-1',
                'sticky' => true,
                'show_top_bar' => false,
                'show_search' => true,
                'show_cart' => true,
                'show_account' => true,
                'show_wishlist' => true,
                'show_announcement_bar' => false,
                'announcement_text' => '',
                'background_color' => '#ffffff',
                'menu_alignment' => 'center',
                'logo_position' => 'left',
                'mobile_behavior' => 'drawer',
            ],
            'footer' => [
                'active_style' => 'style-1',
                'logo_url' => '',
                'about_text' => 'Welcome to Shirin Fashion, your trusted destination for premium beauty and self-care essentials.',
                'copyright_text' => '© 2026 Shirin Fashion. All rights reserved.',
                'newsletter_enabled' => true,
                'payment_icons_enabled' => false,
                'background_color' => '#2b211f',
                'text_color' => '#eadfd7',
                'columns' => 4,
                'show_social_links' => true,
            ],
        ];
    }

    private function groupKey(string $group): string
    {
        return 'theme.'.$group;
    }

    private function serializeMenu(StorefrontMenu $menu): array
    {
        $items = $menu->items
            ->whereNull('parent_id')
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($item) => $this->serializeMenuItem($item))
            ->values()
            ->all();

        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'location' => $menu->location,
            'is_active' => $menu->is_active,
            'items' => $items,
        ];
    }

    private function serializeMenuItem(\App\Models\StorefrontMenuItem $item): array
    {
        return [
            'id' => $item->id,
            'parent_id' => $item->parent_id,
            'title' => $item->title,
            'type' => $item->type,
            'reference_id' => $item->reference_id,
            'url' => $item->url,
            'target_blank' => $item->target_blank,
            'css_class' => $item->css_class,
            'icon' => $item->icon,
            'sort_order' => $item->sort_order,
            'is_active' => $item->is_active,
            'children' => $item->children
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($child) => $this->serializeMenuItem($child))
                ->all(),
        ];
    }
}
