<?php
/** Web app manifest: this is what makes the recipe screens installable as an app. */
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$name = kitchen_name() . ' recipes';
echo json_encode([
    'id' => './',
    'name' => $name,
    'short_name' => mb_strlen($name) > 12 ? 'Recipes' : $name,
    'description' => 'Recipes, ingredients and step-by-step guides for the kitchen.',
    'start_url' => './?source=app',
    'scope' => './',
    'display' => 'standalone',
    'display_override' => ['standalone', 'fullscreen', 'minimal-ui'],
    'orientation' => 'any',
    'background_color' => '#EEF1F6',
    'theme_color' => '#01216C',
    'categories' => ['food', 'business', 'productivity'],
    'icons' => [
        ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Recipes', 'url' => './recipes.php?source=shortcut', 'icons' => [['src' => 'assets/icons/sc-recipes.png', 'sizes' => '96x96']]],
        ['name' => 'Print a label', 'url' => './labels.php?source=shortcut', 'icons' => [['src' => 'assets/icons/sc-labels.png', 'sizes' => '96x96']]],
    ],
    'screenshots' => [
        ['src' => 'assets/icons/screen-wide.webp', 'sizes' => '1280x800', 'type' => 'image/webp', 'form_factor' => 'wide', 'label' => 'Step by step on a kitchen tablet'],
        ['src' => 'assets/icons/screen-narrow.webp', 'sizes' => '780x1688', 'type' => 'image/webp', 'form_factor' => 'narrow', 'label' => 'A recipe on a phone'],
    ],
    'launch_handler' => ['client_mode' => 'focus-existing'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
