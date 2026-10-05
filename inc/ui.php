<?php
/** Small inline SVG icon set (stroke icons, 24px grid). */
function icon(string $name): string
{
    $p = [
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'back' => '<path d="M15 18l-6-6 6-6"/>',
        'next' => '<path d="M9 18l6-6-6-6"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'play' => '<path d="M7 5v14l11-7z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 17-5-5-9 8"/>',
        'tip' => '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z"/>',
        'up' => '<path d="m6 15 6-6 6 6"/>',
        'down' => '<path d="m6 9 6 6 6-6"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'book' => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
        'cog' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'zoom' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M11 8v6M8 11h6"/>',
        'check' => '<path d="M5 12.5 10 17 19 7"/>',
        'restart' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'download' => '<path d="M12 4v12M7 11l5 5 5-5M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
        'copy' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'swap' => '<path d="M7 4 3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/>',
        'print' => '<path d="M6 9V3h12v6M6 18H4v-7h16v7h-2"/><rect x="6" y="14" width="12" height="7"/>',
    ][$name] ?? '';
    $fill = $name === 'play' ? 'currentColor' : 'none';
    return '<svg viewBox="0 0 24 24" fill="' . $fill . '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Photo, or a striped placeholder telling the team a photo is still to come. */
function pic(?string $file, string $alt, string $label = 'Photo to come'): string
{
    if ($file) return '<img src="' . e(img_url($file)) . '" alt="' . e($alt) . '" loading="lazy">';
    return '<div class="noimg"><div>' . icon('image') . '<span>' . e($label) . '</span></div></div>';
}

/** The MustrHQ mark, inline, so it takes the colour of the text around it. */
function mark_svg(): string
{
    static $svg = null;
    if ($svg === null) {
        $raw = (string)@file_get_contents(ROOT . '/assets/brand/logo-mark-white.svg');
        $svg = preg_replace(['/fill="#FFFFFF"/i', '/<svg /'], ['fill="currentColor"', '<svg aria-hidden="true" focusable="false" '], $raw) ?: '';
        $svg = preg_replace('/\s(role|aria-label)="[^"]*"/', '', $svg) ?? $svg;
        $svg = preg_replace('/\sid="[^"]*"/', '', $svg) ?? $svg;
    }
    return $svg;
}

function page_head(string $title, string $bodyClass = '', bool $app = true): void
{
    ?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#01216C">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title><?= e($title) ?> | <?= e(kitchen_name()) ?></title>
<link rel="icon" href="<?= e(base_url('assets/icons/favicon-32.png')) ?>" sizes="32x32">
<link rel="icon" href="<?= e(base_url('assets/icons/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(base_url('assets/icons/apple-180.png')) ?>">
<?php if ($app): ?><link rel="manifest" href="<?= e(base_url('manifest.php')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Recipes">
<?php endif; ?>
<link rel="preload" href="<?= e(base_url('assets/fonts/archivo-var.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="<?= e($bodyClass) ?>" data-base="<?= e(base_url()) ?>">
<a class="skip" href="#main">Skip to content</a>
<?php
}

function kitchen_top(): void
{
    ?>
<header class="top">
  <a class="mark" href="<?= e(base_url()) ?>">
    <span class="mark-box"><?= mark_svg() ?></span>
    <span><?= e(kitchen_name()) ?><small>Recipes</small></span>
  </a>
  <span class="spacer"></span>
  <?php if (is_admin()): ?>
    <a class="btn" href="<?= e(base_url('admin/')) ?>"><?= icon('cog') ?><span>Admin</span></a>
  <?php endif; ?>
</header>
<?php
}

/** A full page saying something was not found, with a way back. */
function not_found(string $title, string $text): never
{
    http_response_code(404);
    page_head($title);
    kitchen_top();
    echo '<main class="wrap" id="main"><div class="empty"><h2>' . e($title) . '</h2><p>' . e($text) . '</p><a class="btn btn-primary" href="' . e(base_url()) . '">' . icon('back') . 'All recipes</a></div></main>';
    page_foot();
    exit;
}

function lightbox_markup(): void
{ ?>
<div class="lb" id="lb" role="dialog" aria-modal="true" aria-label="Image preview" hidden>
  <div class="lb-top">
    <div class="cap" id="lbCap"></div>
    <div class="pos" id="lbPos"></div>
    <button class="ibtn" type="button" id="lbClose" aria-label="Close preview"><?= icon('close') ?></button>
  </div>
  <div class="lb-stage" id="lbStage">
    <img id="lbImg" alt="">
    <button class="ibtn lb-prev" type="button" id="lbPrev" aria-label="Previous image"><?= icon('back') ?></button>
    <button class="ibtn lb-next" type="button" id="lbNext" aria-label="Next image"><?= icon('next') ?></button>
  </div>
  <div class="lb-hint">Double-tap to zoom. Swipe or use the arrows to see the next picture.</div>
</div>
<?php }

function page_foot(): void
{
    echo "</body>\n</html>\n";
}
