<?php
/**
 * manifest.php — dynamic PWA manifest.
 *
 * Replaces the static manifest.json/manifest.webmanifest.
 * Reads app_name, app_description, and brand colors from DB so the
 * restaurant can fully white-label their PWA without touching files.
 *
 * NOTE: Standard PWA manifest does NOT resolve CSS variables, so we
 * read the actual hex values stored in settings and output them directly.
 */

define('LABUS_CTX', 'web');
require_once __DIR__ . '/db.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$db = Database::getInstance();

// Brand (settings.value is JSON-encoded — must json_decode before use)
$appName  = json_decode($db->getSetting('app_name'),        true) ?? 'labus';
$appDesc  = json_decode($db->getSetting('app_description'), true) ?? 'Цифровое меню ресторана';

// Colors (stored as JSON-encoded hex strings)
$primaryColor   = json_decode($db->getSetting('color_primary-color')   ?? '"#cd1719"', true) ?? '#cd1719';
$secondaryColor = json_decode($db->getSetting('color_secondary-color') ?? '"#121212"', true) ?? '#121212';

$manifest = [
    'name'        => $appName,
    'short_name'  => mb_substr($appName, 0, 12),
    'description' => $appDesc,
    'start_url'   => '/?source=pwa',
    'display'     => 'standalone',
    'background_color' => $secondaryColor,
    'theme_color'      => $primaryColor,
    'orientation'      => 'portrait-primary',
    'categories'       => ['food', 'restaurant'],
    'lang'             => 'ru-RU',
    'scope'            => '/',
    'prefer_related_applications' => false,
    'icons' => [
        ['src' => '/icons/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => '/icons/icon-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => '/icons/icon-384x384.png', 'sizes' => '384x384', 'type' => 'image/png'],
        ['src' => '/icons/icon-256x256.png', 'sizes' => '256x256', 'type' => 'image/png'],
        ['src' => '/icons/icon-128x128.png', 'sizes' => '128x128', 'type' => 'image/png'],
    ],
    'screenshots' => [
        ['src' => '/images/screenshot1.webp', 'sizes' => '1080x1920', 'type' => 'image/webp', 'form_factor' => 'narrow'],
        ['src' => '/images/screenshot2.webp', 'sizes' => '1920x1080', 'type' => 'image/webp', 'form_factor' => 'wide'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
