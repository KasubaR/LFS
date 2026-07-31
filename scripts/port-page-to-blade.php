<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php port-page-to-blade.php <legacy-page.php> <blade-output.blade.php>\n");
    exit(1);
}

$legacyPath = $argv[1];
$outputPath = $argv[2];
$body = file_get_contents($legacyPath);

$body = preg_replace('/^<\?php \/\*.*?\*\/ \?>\s*/s', '', $body) ?? $body;

$body = str_replace(
    "<?php\n\$sectionClass = 'bg-lfs-off-white';\nrequire __DIR__ . '/../partials/satellites.php';\n?>",
    "@include('partials.satellites', ['sectionClass' => 'bg-lfs-off-white'])",
    $body
);

$body = str_replace(
    "<?php\n\$galleryPreview = \$galleryPreview ?? [];\n\$sectionId      = 'photos';\nrequire __DIR__ . '/../partials/home-gallery.php';\n?>",
    "@include('partials.home-gallery', ['galleryPreview' => \$galleryPreview, 'sectionId' => 'photos'])",
    $body
);

$body = str_replace(
    "<?php\n\$sectionId = 'shop';\n\$bg        = 'var(--off-white)';\nrequire __DIR__ . '/../partials/shop-preview.php';\n?>",
    "@include('partials.shop-preview', ['sectionId' => 'shop', 'bg' => 'var(--off-white)'])",
    $body
);

$body = str_replace(
    "<?php require __DIR__ . '/../partials/shop-preview.php'; ?>",
    "@include('partials.shop-preview')",
    $body
);

// Protect inline hero slider script from PHP tag conversion (home.php)
$heroScriptPlaceholder = '___LFS_HERO_SLIDER_SCRIPT___';
if (preg_match('/<script>\s*\(function \(\) \{[\s\S]*?\}\)\(\);\s*<\/script>/', $body, $heroScriptMatch)) {
    $body = str_replace($heroScriptMatch[0], $heroScriptPlaceholder, $body);
}

// Events listing detail URL (inside foreach @php blocks)
$body = str_replace(
    "\$detailUrl = !empty(\$ev['slug'])\n                ? '/events/' . htmlspecialchars(\$ev['slug'])\n                : htmlspecialchars(\$ev['link'] ?? '/contact');",
    "\$detailUrl = !empty(\$ev['slug'])\n                ? url('/events/'.\$ev['slug'])\n                : url(\$ev['link'] ?? '/contact');",
    $body
);

// Events ternary short-echo
$body = preg_replace(
    '/<\?= count\(\$allEvents\) \?\> event<\?= count\(\$allEvents\) !== 1 \\? \'s\' : \'\' \?\>/',
    '{{ count($allEvents) }} event{{ count($allEvents) !== 1 ? \'s\' : \'\' }}',
    $body
) ?? $body;

$body = preg_replace(
    '/<\?= \$i >= 6 \\? \'hidden\' : \'\' \?\>/',
    '{{ $i >= 6 ? \'hidden\' : \'\' }}',
    $body
) ?? $body;

// Gallery album href (listing page)
$body = str_replace(
    "\$href          = \$hasExternal ? \$album['externalUrl'] : ('/gallery/' . \$album['_id']);",
    "\$href          = \$hasExternal ? \$album['externalUrl'] : url('/gallery/'.\$album['_id']);",
    $body
);

// Gallery item count ternary
$body = preg_replace(
    '/<\?= \$itemCount \?\> item<\?= \$itemCount === 1 \\? \'\' : \'s\' \?\>/',
    '{{ $itemCount }} item{{ $itemCount === 1 ? \'\' : \'s\' }}',
    $body
) ?? $body;

// Contact form group error classes
$body = preg_replace(
    '/class="form-group<\?= isset\(\$errors\[\'([^\']+)\'\]\) \\? \' form-group--error\' : \'\' \?\>"/',
    'class="form-group{{ isset($errors[\'$1\']) ? \' form-group--error\' : \'\' }}"',
    $body
) ?? $body;

// Contact satellite option selected
$body = preg_replace(
    '/<\?= \$oldSatellite === \$val \\? \'selected\' : \'\' \?\>/',
    '{{ $oldSatellite === $val ? \'selected\' : \'\' }}',
    $body
) ?? $body;

// FAQ answer with line breaks (trusted after escape)
$body = preg_replace(
    '/<\?= nl2br\(htmlspecialchars\(\$faq\[\'answer\'\], ENT_QUOTES, \'UTF-8\'\)\) \?\>/',
    '{!! nl2br(e($faq[\'answer\'])) !!}',
    $body
) ?? $body;

// Gallery album aspect ratio — Laravel public_path instead of PUBLIC_ROOT
$body = str_replace(
    "if (\$ratioSource && str_starts_with(\$ratioSource, '/') && defined('PUBLIC_ROOT')) {\n              \$fsPath = rtrim(PUBLIC_ROOT, '/') . \$ratioSource;",
    "if (\$ratioSource && str_starts_with(\$ratioSource, '/')) {\n              \$fsPath = public_path(ltrim(\$ratioSource, '/'));",
    $body
);

// Gallery header banner (gallery.php)
$body = preg_replace(
    '/<\?php \$hasBanner = !empty\(\$galleryBanner \?\? null\); \?\>\s*<header class="gallery-header<\?= \$hasBanner \\? \' gallery-header--has-banner\' : \'\' \?\>"\s*<\?= \$hasBanner \\? \'style="background-image:url\\(\\\'\' \. htmlspecialchars\(\$galleryBanner, ENT_QUOTES, \'UTF-8\'\) \. \'\\\'\\)\'"\' : \'\' \?\>/s',
    "@php \$hasBanner = !empty(\$galleryBanner ?? null); @endphp\n<header class=\"gallery-header{{ \$hasBanner ? ' gallery-header--has-banner' : '' }}\"\n  @if(\$hasBanner) style=\"background-image:url('{{ e(\$galleryBanner) }}')\" @endif",
    $body
) ?? $body;

// Blog pagination block (news.php)
$paginationBlock = <<<'PHP'
    <?php if ($totalPages > 1): ?>
      <?php
        $baseUrl         = (defined('BASE_PATH') ? BASE_PATH : '') . '/news?';
        $qParams         = [];
        if ($activeCategory) $qParams[] = 'category=' . urlencode($activeCategory);
        if ($searchQuery)    $qParams[] = 'q=' . urlencode($searchQuery);
        $qParams[]       = 'page=';
        $baseUrl        .= implode('&', $qParams);
        $paginationLabel = 'Blog pages';
        require __DIR__ . '/../partials/pagination.php';
      ?>
    <?php endif; ?>
PHP;

$paginationBlade = <<<'BLADE'
    @if($totalPages > 1)
      @php
        $baseUrl = url('/news').'?';
        $qParams = [];
        if ($activeCategory) $qParams[] = 'category='.urlencode($activeCategory);
        if ($searchQuery) $qParams[] = 'q='.urlencode($searchQuery);
        $qParams[] = 'page=';
        $baseUrl .= implode('&', $qParams);
      @endphp
      @include('partials.pagination', [
        'currentPage' => $currentPage,
        'totalPages' => $totalPages,
        'baseUrl' => $baseUrl,
        'paginationLabel' => 'Blog pages',
      ])
    @endif
BLADE;

$body = str_replace($paginationBlock, $paginationBlade, $body);

// Trusted HTML (news-post) — before generic echo conversion
$body = str_replace(
    "<?= \$post['content'] ?? '<p>Content coming soon.</p>' ?>",
    "{!! \$post['content'] ?? '<p>Content coming soon.</p>' !!}",
    $body
);

// postUrl helpers inside @php blocks (after @php conversion, applied via pre-replace on source)
$body = str_replace(
    "return defined('BASE_PATH') ? BASE_PATH . '/news/' . \$slug : '/news/' . \$slug;",
    "return url('/news/'.\$slug);",
    $body
);
$body = str_replace(
    "return (defined('BASE_PATH') ? BASE_PATH : '') . '/news/' . \$slug;",
    "return url('/news/'.\$slug);",
    $body
);

$shareUrlLegacy = "\$shareUrl   = urlencode('https://www.lfszambia.run' . (defined('BASE_PATH') ? BASE_PATH : '') . '/news/' . (\$post['slug'] ?? ''));";
$shareUrlBlade = "\$shareUrl   = urlencode(rtrim(config('app.url', 'https://www.lfszambia.run'), '/').'/news/'.(\$post['slug'] ?? ''));";
$body = str_replace($shareUrlLegacy, $shareUrlBlade, $body);

// Category href in @php blocks (news listing)
$body = str_replace(
    "\$href   = (defined('BASE_PATH') ? BASE_PATH : '') . '/news?category=' . \$slug . (\$searchQuery ? '&q=' . urlencode(\$searchQuery) : '');",
    "\$href   = url('/news').'?category='.\$slug.(\$searchQuery ? '&q='.urlencode(\$searchQuery) : '');",
    $body
);

// Multi-tag lines (filter strip "All Posts" link)
$body = preg_replace(
    '/href="<\?= safeStr\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\/news<\?= \$searchQuery \\? \'\\?q=\' \\. urlencode\\(\$searchQuery\\) : \'\' \?\>"/',
    'href="{{ url(\'/news\') }}{{ $searchQuery ? \'?q=\'.urlencode($searchQuery) : \'\' }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/class="blog-filter-chip<\?= !\$activeCategory \\? \' blog-filter-chip--active\' : \'\' \?\>"/',
    'class="blog-filter-chip{{ !$activeCategory ? \' blog-filter-chip--active\' : \'\' }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/class="blog-filter-chip <\?= catClass\\(\$cat\\) \?\><\?= \$active \\? \' blog-filter-chip--active\' : \'\' \?\>"/',
    'class="blog-filter-chip {{ catClass($cat) }}{{ $active ? \' blog-filter-chip--active\' : \'\' }}"',
    $body
) ?? $body;

// Ternary short-echo (must run before generic <?= conversion)
$body = preg_replace(
    '/<\?= \$searchQuery\s*\?\s*\'No results for "\' \. safeStr\(\$searchQuery\) \. \'". Try a different search\.\'\s*:\s*\'Check back soon — stories from the squad are coming.\' \?\>/s',
    '{{ $searchQuery ? \'No results for "\'.safeStr($searchQuery).\'. Try a different search.\' : \'Check back soon — stories from the squad are coming.\' }}',
    $body
) ?? $body;

$body = preg_replace(
    '/<\?= \$total \?\> post<\?= \$total !== 1 \\? \'s\' : \'\' \?\>/',
    '{{ $total }} post{{ $total !== 1 ? \'s\' : \'\' }}',
    $body
) ?? $body;

$body = preg_replace(
    '/<\?= \$activeCategory \\? \' in <strong>\' \. safeStr\(\$activeCategory\) \. \'<\/strong>\' : \'\' \?\>/',
    '{!! $activeCategory ? \' in <strong>\'.e($activeCategory).\'</strong>\' : \'\' !!}',
    $body
) ?? $body;

$body = preg_replace(
    '/<\?= \$searchQuery \\? \' for "<strong>\' \. safeStr\(\$searchQuery\) \. \'<\/strong>"\' : \'\' \?\>/',
    '{!! $searchQuery ? \' for "<strong>\'.e($searchQuery).\'</strong>"\' : \'\' !!}',
    $body
) ?? $body;

// Inline class ternaries in attributes
$body = preg_replace(
    '/class="blog-badge <\?= catClass\(([^)]+)\) \?\>"><\?= safeStr\(([^)]+)\) \?\><\/span>/',
    'class="blog-badge {{ catClass($1) }}">{{ safeStr($2) }}</span>',
    $body
) ?? $body;

$body = preg_replace(
    '/class="blog-badge <\?= catClass\(([^)]+)\) \?\>">\s*<\?= safeStr\(([^)]+)\) \?\>/',
    'class="blog-badge {{ catClass($1) }}">{{ safeStr($2) }}',
    $body
) ?? $body;

$body = preg_replace(
    '/class="blog-badge <\?= catClass2\(([^)]+)\) \?\>"><\?= safeStr2\(([^)]+)\) \?\><\/span>/',
    'class="blog-badge {{ catClass2($1) }}">{{ safeStr2($2) }}</span>',
    $body
) ?? $body;

$body = preg_replace(
    '/class="sidebar-cat <\?= \$active \\? \'sidebar-cat--active\' : \'\' \?\>"/',
    'class="sidebar-cat{{ $active ? \' sidebar-cat--active\' : \'\' }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/blog-badge--<\?= \$catColors\[\$cat\] \?\? \'green\' \?\>"/',
    'blog-badge--{{ $catColors[$cat] ?? \'green\' }}">',
    $body
) ?? $body;

// Home page patterns (before generic <?= conversion)
$body = str_replace(
    "<?php \$newsBase = (defined('BASE_PATH') ? BASE_PATH : '') . '/news'; ?>",
    "@php \$newsBase = url('/news'); @endphp",
    $body
);

$body = preg_replace(
    '/class="events-list <\?= empty\(\$events\) \\? \'events-list--empty\' : \'\' \?\>"/',
    'class="events-list{{ empty($events) ? \' events-list--empty\' : \'\' }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/class="home-hero__slide<\?= \$_i === 0 \\? \' home-hero__slide--active\' : \'\' \?\>"/',
    'class="home-hero__slide{{ $_i === 0 ? \' home-hero__slide--active\' : \'\' }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/loading="<\?= \$_i === 0 \\? \'eager\' : \'lazy\' \?\>"/',
    'loading="{{ $_i === 0 ? \'eager\' : \'lazy\' }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/class="hero-slider-dot<\?= \$_i === 0 \\? \' hero-slider-dot--active\' : \'\' \?\>"/',
    'class="hero-slider-dot{{ $_i === 0 ? \' hero-slider-dot--active\' : \'\' }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/class="home-hero__featured home-hero__featured-panel<\?= \$_active \?\>"/',
    'class="home-hero__featured home-hero__featured-panel{{ $_active }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/class="badge <\?= \$tagClass \?\>"/',
    'class="badge {{ $tagClass }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/data-reveal-delay="<\?= \$delay \?\>"/',
    'data-reveal-delay="{{ $delay }}"',
    $body
) ?? $body;

$body = preg_replace(
    '/data-reveal-delay="<\?= \$idx \+ 1 \?\>"/',
    'data-reveal-delay="{{ $idx + 1 }}"',
    $body
) ?? $body;

// Shop pages — productCard partial include
$body = str_replace(
    "<?php require __DIR__ . '/../partials/productCard.php'; ?>",
    "@include('partials.productCard', ['product' => \$product])",
    $body
);

// Cart item count ternary
$body = preg_replace(
    '/item<\?= \$itemCount !== 1 \\? \'s\' : \'\' \?\>/',
    'item{{ $itemCount !== 1 ? \'s\' : \'\' }}',
    $body
) ?? $body;

// Shop filter selected attributes
$body = preg_replace(
    '/<\?= \(\$filters\[\'sort\'\] \?\? \'\'\) === \'([^\']+)\' \\? \'selected\' : \'\' \?\>/',
    '{{ ($filters[\'sort\'] ?? \'\') === \'$1\' ? \'selected\' : \'\' }}',
    $body
) ?? $body;

$body = preg_replace(
    '/<\?= \(\$filters\[\'category\'\] \?\? \'\'\) === \$cat \\? \'checked\' : \'\' \?\>/',
    '{{ ($filters[\'category\'] ?? \'\') === $cat ? \'checked\' : \'\' }}',
    $body
) ?? $body;

$body = preg_replace(
    '/<\?= \(\$filters\[\'gender\'\] \?\? \'\'\) === \$g \\? \'checked\' : \'\' \?\>/',
    '{{ ($filters[\'gender\'] ?? \'\') === $g ? \'checked\' : \'\' }}',
    $body
) ?? $body;

$body = preg_replace(
    '/class="shop-pagination__page <\?= \$p === \$currentPage \\? \'is-active\' : \'\' \?\>"/',
    'class="shop-pagination__page{{ $p === $currentPage ? \' is-active\' : \'\' }}"',
    $body
) ?? $body;

// formatPrice short-echo (before generic <?= conversion)
$body = preg_replace(
    '/<\?= \$formatPrice\(([^)]+)\) \?\>/',
    '{{ $formatPrice($1) }}',
    $body
) ?? $body;

// Strip page-local asset assignments (controller supplies extraStyles/extraScripts)
$assetStripPatterns = [
    "/@php\n\\/\\/ Inject page-level stylesheet into the layout\n\\\$styles = '<link rel=\"stylesheet\" href=\"' \\. htmlspecialchars\\(asset\\('\\/css\\/shop\\.css'\\), ENT_QUOTES, 'UTF-8'\\) \\. '\">';@endphp\n\n" => '',
    "@php\n\$styles = '<link rel=\"stylesheet\" href=\"' . htmlspecialchars(asset('/css/shop.css'), ENT_QUOTES, 'UTF-8') . '\">';\n@endphp\n\n" => '',
    "@php \$extraStyles = '<link rel=\"stylesheet\" href=\"' . htmlspecialchars(asset('/css/cart.css'), ENT_QUOTES, 'UTF-8') . '\">'; @endphp\n\n" => '',
];
foreach ($assetStripPatterns as $pattern => $replacement) {
    $body = str_replace($pattern, $replacement, $body);
}

// Strip legacy $styles/$scripts blocks (pre-@php conversion on raw PHP)
$body = preg_replace(
    '/^\\$styles = \'<link rel="stylesheet" href="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/css\\/shop\\.css\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'">\';\\s*/m',
    '',
    $body
) ?? $body;

$body = preg_replace(
    '/^\\$styles = \'<link rel="stylesheet" href="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/css\\/shop\\.css\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'"><link rel="stylesheet" href="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/css\\/productDetails\\.css\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'">\';\\s*/m',
    '',
    $body
) ?? $body;

$body = preg_replace(
    '/^\\$styles  = \'<link rel="stylesheet" href="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/css\\/checkout\\.css\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'">\';\\s*\\$scripts = \'<script src="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/js\\/checkout\\.js\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'"><\\/script>\';\\s*/m',
    '',
    $body
) ?? $body;

$body = preg_replace(
    '/^\\$styles  = \'<link rel="stylesheet" href="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/css\\/checkout\\.css\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'">\';\\s*/m',
    '',
    $body
) ?? $body;

$body = preg_replace(
    '/^<\\?php \\$extraStyles = \'<link rel="stylesheet" href="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/css\\/cart\\.css\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'">\'; \\?>\\s*/m',
    '',
    $body
) ?? $body;

$body = preg_replace(
    '/<\\?php \\$scripts = \'<script src="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/js\\/shop\\.js\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'"><\\/script>\'; \\?>\\s*/m',
    '',
    $body
) ?? $body;

$body = preg_replace(
    '/<\\?php \\$scripts = \'<script src="\' \\. htmlspecialchars\\(lfs_public_url\\(\'\\/js\\/productDetails\\.js\'\\), ENT_QUOTES, \'UTF-8\'\\) \\. \'"><\\/script>\'; \\?>\\s*/m',
    '',
    $body
) ?? $body;

$replacements = [
    "/htmlspecialchars\\(lfs_public_url\\('([^']+)'\\), ENT_QUOTES, 'UTF-8'\\)/" => "{{ asset('$1') }}",
    '/<\?= safeStr2\\(postUrl2\\((.+?)\\)\\) \?\>/s' => '{{ postUrl2($1) }}',
    '/<\?= safeStr\\(postUrl\\((.+?)\\)\\) \?\>/s' => '{{ postUrl($1) }}',
    '/<\?= safeStr2\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\//' => "{{ url('/') }}",
    '/<\?= safeStr\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\//' => "{{ url('/') }}",
    '/<\?= safeStr2\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>/' => "{{ url('') }}",
    '/<\?= safeStr\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>/' => "{{ url('') }}",
    '/action="<\?= safeStr\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\/news"/' => 'action="{{ url(\'/news\') }}"',
    '/href="<\?= safeStr\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\/news"/' => 'href="{{ url(\'/news\') }}"',
    '/href="<\?= safeStr2\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\/news"/' => 'href="{{ url(\'/news\') }}"',
    '/href="<\?= safeStr2\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\/about"/' => 'href="{{ url(\'/about\') }}"',
    '/href="<\?= safeStr2\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\/news\\?category=<\?= urlencode\\(\$post\\[\'category\'\\]\\) \?\>"/' => 'href="{{ url(\'/news\') }}?category={{ urlencode($post[\'category\']) }}"',
    '/href="<\?= safeStr2\\(defined\\(\'BASE_PATH\'\\) \\? BASE_PATH : \'\'\\) \?\>\/news\\?category=<\?= urlencode\\(\$cat\\) \?\>"/' => 'href="{{ url(\'/news\') }}?category={{ urlencode($cat) }}"',
    '/<\?= htmlspecialchars\\((.+?)\\) \?\>/s' => '{{ $1 }}',
    '/<\?php foreach \\((.+?)\\): \?\>/s' => '@foreach($1)',
    '/<\?php endforeach; \?\>/' => '@endforeach',
    '/<\?php endforeach \?\>/' => '@endforeach',
    '/<\?php else: \?\>/' => '@else',
    '/<\?php else \?\>/' => '@else',
    '/<\?php elseif \\((.+?)\\): \?\>/s' => '@elseif($1)',
    '/<\?php if \\((.+?)\\): \?\>/s' => '@if($1)',
    '/<\?php endif; \?\>/' => '@endif',
    '/<\?php endif \?\>/' => '@endif',
    '/<\?= \\$([a-zA-Z_][\\w\\[\\\'\\"]*) \?\>/' => '{{ $$1 }}',
    '/<\?= (.+?) \?\>/s' => '{{ $1 }}',
];

foreach ($replacements as $pattern => $replacement) {
    $body = preg_replace($pattern, $replacement, $body) ?? $body;
}

$body = preg_replace('/<\?php/', '@php', $body) ?? $body;
$body = preg_replace('/\?\>/', '@endphp', $body) ?? $body;
$body = str_replace('@endphp@endphp', '@endphp', $body);

// Leftover control structures from partial <?= conversion
$body = preg_replace('/@php if \\((.+?)\\): @endphp/s', '@if($1)', $body) ?? $body;
$body = preg_replace('/@php foreach \\((.+?)\\): @endphp/s', '@foreach($1)', $body) ?? $body;

// Fix url('') patterns to proper routes
$body = preg_replace('/\{\{ url\(\'\'\) \}\}\//', "{{ url('/') }}/", $body) ?? $body;
$body = str_replace("{{ url('') }}/news", "{{ url('/news') }}", $body);
$body = str_replace("{{ url('') }}/about", "{{ url('/about') }}", $body);
$body = str_replace('href="/"', 'href="{{ url(\'/\') }}"', $body);
$body = str_replace('href="/contact"', 'href="{{ url(\'/contact\') }}"', $body);
$body = str_replace('href="/about"', 'href="{{ url(\'/about\') }}"', $body);
$body = str_replace('href="/gallery"', 'href="{{ url(\'/gallery\') }}"', $body);
$body = str_replace('href="/events"', 'href="{{ url(\'/events\') }}"', $body);
$body = str_replace('href="/shop"', 'href="{{ url(\'/shop\') }}"', $body);
$body = str_replace('href="/shop/cart"', 'href="{{ url(\'/shop/cart\') }}"', $body);
$body = str_replace('action="/shop"', 'action="{{ url(\'/shop\') }}"', $body);
$body = str_replace('href="/gallery"', 'href="{{ url(\'/gallery\') }}"', $body);
$body = str_replace("{{ url('/') }}events", "{{ url('/events') }}", $body);
$body = str_replace("{{ url('/') }}gallery", "{{ url('/gallery') }}", $body);
$body = str_replace("{{ url('/') }}contact", "{{ url('/contact') }}", $body);
$body = str_replace("{{ url('/') }}gallery", "{{ url('/gallery') }}", $body);
$body = str_replace('{{ {{', '{{', $body);
$body = str_replace('}} }}', '}}', $body);

// Restore protected hero slider script
if (isset($heroScriptMatch[0])) {
    $body = str_replace($heroScriptPlaceholder, $heroScriptMatch[0], $body);
}

$wrapped = "@extends('layouts.app')\n\n@section('content')\n".trim($body)."\n\n@endsection\n";

$dir = dirname($outputPath);
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($outputPath, $wrapped);
echo "Wrote {$outputPath} (".strlen($wrapped)." bytes)\n";
