<?php

declare(strict_types=1);

/**
 * Legacy view helpers — ported from lfs-website-php src/utility/helpers.php
 */

if (! function_exists('lfs_public_url')) {
    function lfs_public_url(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return asset(ltrim($path, '/'));
    }
}

if (! function_exists('lfs_formatPrice')) {
    function lfs_formatPrice(int|float|null $amount, bool $showCents = false): string
    {
        if ($amount === null || ! is_numeric($amount)) {
            return 'K —';
        }

        $num = (float) $amount;
        $decimals = $showCents ? 2 : 0;

        return 'K '.number_format($num, $decimals);
    }
}

if (! function_exists('lfs_formatPriceRange')) {
    function lfs_formatPriceRange(int|float $min, int|float $max): string
    {
        if ($min === $max) {
            return lfs_formatPrice($min);
        }

        return lfs_formatPrice($min).' – '.lfs_formatPrice($max);
    }
}

if (! function_exists('lfs_timeAgo')) {
    function lfs_timeAgo(string $datetime): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }

        $diff = time() - $ts;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60).' min ago';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600).' hours ago';
        }
        if ($diff < 604800) {
            return (int) floor($diff / 86400).' days ago';
        }
        if ($diff < 2592000) {
            return (int) floor($diff / 604800).' weeks ago';
        }
        if ($diff < 31536000) {
            return (int) floor($diff / 2592000).' months ago';
        }

        return (int) floor($diff / 31536000).' years ago';
    }
}

if (! function_exists('blogFormatDate')) {
    function blogFormatDate(?string $d): string
    {
        if (! $d) {
            return '—';
        }
        $ts = strtotime($d);

        return $ts === false ? '—' : date('j M Y', $ts);
    }
}

if (! function_exists('lfs_slugify')) {
    function lfs_slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^\w\s\-]/u', '', $text);
        $text = preg_replace('/[\s_]+/', '-', $text);
        $text = preg_replace('/\-{2,}/', '-', $text);

        return trim($text, '-');
    }
}

if (! function_exists('lfs_truncate')) {
    function lfs_truncate(string $str, int $maxLen = 160): string
    {
        if (mb_strlen($str) <= $maxLen) {
            return $str;
        }
        $cut = mb_substr($str, 0, $maxLen);
        $cut = preg_replace('/\s+\S*$/u', '', $cut);

        return $cut.'…';
    }
}

if (! function_exists('lfs_toTitleCase')) {
    function lfs_toTitleCase(string $str): string
    {
        return mb_convert_case($str, MB_CASE_TITLE, 'UTF-8');
    }
}

if (! function_exists('lfs_categoryLabel')) {
    function lfs_categoryLabel(string $slug): string
    {
        $labels = [
            'running-kits' => 'Running Kits',
            't-shirts' => 'T-Shirts',
            'caps' => 'Caps',
            'shorts' => 'Shorts',
            'accessories' => 'Accessories',
            'other' => 'Other',
        ];

        return $labels[$slug] ?? lfs_toTitleCase(str_replace('-', ' ', $slug));
    }
}

if (! function_exists('lfs_checkStockAvailability')) {
    function lfs_checkStockAvailability(?array $product, ?string $size = null): array
    {
        $empty = [
            'inStock' => false,
            'totalStock' => 0,
            'sizeStock' => null,
            'status' => 'out-of-stock',
            'statusLabel' => 'Out of Stock',
        ];

        if ($product === null) {
            return $empty;
        }

        $totalStock = (int) ($product['totalStock'] ?? 0);
        $sizes = is_array($product['sizes'] ?? null) ? $product['sizes'] : [];
        if ($sizes !== []) {
            $totalStock = (int) array_sum(array_column($sizes, 'stock'));
        }

        $sizeStock = null;
        if ($size !== null && $sizes !== []) {
            foreach ($sizes as $s) {
                if (($s['size'] ?? '') === $size) {
                    $sizeStock = (int) ($s['stock'] ?? 0);
                    break;
                }
            }
            if ($sizeStock === null) {
                $sizeStock = 0;
            }
        }

        $effective = $sizeStock !== null ? $sizeStock : $totalStock;
        $inStock = $effective > 0;

        if (! $inStock) {
            return array_merge($empty, ['totalStock' => $totalStock, 'sizeStock' => $sizeStock]);
        }
        if ($effective <= 5) {
            return [
                'inStock' => true,
                'totalStock' => $totalStock,
                'sizeStock' => $sizeStock,
                'status' => 'low-stock',
                'statusLabel' => "Only {$effective} left",
            ];
        }

        return [
            'inStock' => true,
            'totalStock' => $totalStock,
            'sizeStock' => $sizeStock,
            'status' => 'in-stock',
            'statusLabel' => 'In Stock',
        ];
    }
}

if (! function_exists('lfs_getMaxQty')) {
    function lfs_getMaxQty(array $product, string $size): int
    {
        $result = lfs_checkStockAvailability($product, $size);

        return max(0, $result['sizeStock'] !== null ? $result['sizeStock'] : $result['totalStock']);
    }
}

if (! function_exists('lfs_buildProductJsonLd')) {
    function lfs_buildProductJsonLd(array $product, string $siteUrl = 'https://www.lfszambia.run'): array
    {
        $sizes = is_array($product['sizes'] ?? null) ? $product['sizes'] : [];
        $inStock = $sizes !== []
            ? (bool) array_filter($sizes, fn ($s) => (int) ($s['stock'] ?? 0) > 0)
            : ((int) ($product['totalStock'] ?? 0) > 0);

        $images = array_map(
            fn ($img): string => str_starts_with((string) $img, 'http') ? (string) $img : $siteUrl.$img,
            is_array($product['images'] ?? null) ? $product['images'] : []
        );

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'],
            'description' => $product['description'] ?? $product['shortDescription'] ?? '',
            'url' => $siteUrl.'/shop/product/'.$product['slug'],
            'image' => $images,
            'sku' => (string) ($product['id'] ?? $product['slug']),
            'brand' => ['@type' => 'Brand', 'name' => 'LFS — Lusaka Fitness Squad'],
            'offers' => [
                '@type' => 'Offer',
                'url' => $siteUrl.'/shop/product/'.$product['slug'],
                'price' => (float) ($product['price'] ?? 0),
                'priceCurrency' => 'ZMW',
                'priceValidUntil' => date('Y-m-d', strtotime('+30 days')),
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'seller' => ['@type' => 'Organization', 'name' => 'LFS — Lusaka Fitness Squad'],
            ],
        ];
    }
}
