<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php port-admin-to-blade.php <legacy-page.php> <blade-output.blade.php> [--layout] [--partial]\n");
    exit(1);
}

$legacyPath = $argv[1];
$outputPath = $argv[2];
$isLayout = in_array('--layout', $argv, true);
$isPartial = in_array('--partial', $argv, true);

if (! is_file($legacyPath)) {
    fwrite(STDERR, "Legacy file not found: {$legacyPath}\n");
    exit(1);
}

$body = file_get_contents($legacyPath);

$body = preg_replace('/^<\?php\s*\/\*\*?[\s\S]*?\*\/\s*\?>\s*/m', '', $body) ?? $body;
$body = preg_replace('/^<\?php\s*\/\*[\s\S]*?\*\/\s*/m', '', $body) ?? $body;

// Blog partial include
$body = preg_replace(
    "/require __DIR__ \\. '\\/_form\\.php';/",
    "@include('admin.blog._form')",
    $body
) ?? $body;

// Protect inline script blocks from PHP conversion
$scriptPlaceholders = [];
$scriptIndex = 0;
$body = preg_replace_callback(
    '/<script(?:\s[^>]*)?>[\s\S]*?<\/script>/i',
    function (array $m) use (&$scriptPlaceholders, &$scriptIndex): string {
        $key = '___LFS_ADMIN_SCRIPT_'.$scriptIndex.'___';
        $scriptPlaceholders[$key] = $m[0];
        $scriptIndex++;

        return $key;
    },
    $body
) ?? $body;

// Strip page-level asset assignments (controller supplies extraStyles/extraScripts)
$body = preg_replace(
    '/<\?php\s*\$extraStyles\s*=\s*[^;]+;\s*\?>\s*/s',
    '',
    $body
) ?? $body;
$body = preg_replace(
    '/<\?php\s*\$extraScripts\s*=\s*[^;]+;\s*\?>\s*/s',
    '',
    $body
) ?? $body;
$body = preg_replace(
    '/<\?php\s*\$styles\s*=\s*[^;]+;\s*\?>\s*/s',
    '',
    $body
) ?? $body;
$body = preg_replace(
    '/<\?php\s*\$scripts\s*=\s*[^;]+;\s*\?>\s*/s',
    '',
    $body
) ?? $body;
$body = preg_replace(
    '/@php\s*\$extraStyles\s*=\s*[^;]+;\s*@endphp\s*/s',
    '',
    $body
) ?? $body;
$body = preg_replace(
    '/@php\s*\$extraScripts\s*=\s*[^;]+;\s*@endphp\s*/s',
    '',
    $body
) ?? $body;

// Admin URL paths
$adminPaths = [
    '/admin/dashboard', '/admin/messages', '/admin/members', '/admin/events',
    '/admin/gallery', '/admin/blog', '/admin/faqs', '/admin/products', '/admin/orders',
    '/admin/logout', '/admin/profile', '/admin/notifications', '/admin/activity',
];
foreach ($adminPaths as $path) {
    $body = str_replace('href="'.$path.'"', 'href="{{ url(\''.$path.'\') }}"', $body);
    $body = str_replace("href='".$path."'", "href=\"{{ url('".$path."') }}\"", $body);
    $body = str_replace('action="'.$path.'"', 'action="{{ url(\''.$path.'\') }}"', $body);
}

$body = preg_replace(
    '/action="\/admin\/<\?= htmlspecialchars\(\$loginSlug\)(?:\s*\?\>| @endphp)"/',
    'action="{{ url(\'/admin/\'.$loginSlug) }}"',
    $body
) ?? $body;

$body = str_replace('href="/"', 'href="{{ url(\'/\') }}"', $body);

// lfs_public_url to asset()
$body = preg_replace(
    "/htmlspecialchars\\(lfs_public_url\\('([^']+)'\\), ENT_QUOTES, 'UTF-8'\\)/",
    "asset('$1')",
    $body
) ?? $body;
$body = preg_replace(
    "/lfs_public_url\\('([^']+)'\\)/",
    "asset('$1')",
    $body
) ?? $body;

$replacements = [
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

$body = preg_replace('/@php if \\((.+?)\\): @endphp/s', '@if($1)', $body) ?? $body;
$body = preg_replace('/@php foreach \\((.+?)\\): @endphp/s', '@foreach($1)', $body) ?? $body;
$body = preg_replace('/@php else: @endphp/s', '@else', $body) ?? $body;
$body = preg_replace('/@php endif; @endphp/s', '@endif', $body) ?? $body;
$body = preg_replace('/@php endif @endphp/s', '@endif', $body) ?? $body;

// Fix hybrid leftovers
$body = preg_replace('/<\?= (.+?) @endphp/s', '{{ $1 }}', $body) ?? $body;

$body = str_replace('{{ {{', '{{', $body);
$body = str_replace('}} }}', '}}', $body);

// Restore protected scripts
foreach ($scriptPlaceholders as $key => $script) {
    $body = str_replace($key, $script, $body);
}

$body = trim($body);

if ($isLayout) {
    $body = str_replace('<?= $content ?>', "@yield('content')", $body);
    $body = str_replace('{{ $content }}', "@yield('content')", $body);
    $wrapped = $body;
} elseif ($isPartial) {
    $wrapped = $body;
} else {
    $wrapped = "@extends('layouts.admin')\n\n@section('content')\n".$body."\n\n@endsection\n";
}

$dir = dirname($outputPath);
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($outputPath, $wrapped);
echo "Wrote {$outputPath} (".strlen($wrapped)." bytes)\n";
