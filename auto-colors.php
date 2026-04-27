<?php
require_once __DIR__ . '/session_init.php';

header('Content-Type: text/css');
header("Cache-Control: no-cache, must-revalidate");

$db = Database::getInstance();

// Дефолтные значения
$defaultColors = [
    'primary-color' => '#cd1719',
    'secondary-color' => '#121212',
    'primary-dark' => '#000000',
    'accent-color' => '#db3a34',
    'text-color' => '#ffffff',
    'acception' => '#2c83c2',
    'light-text' => '#555555',
    'bg-light' => '#f9f9f9',
    'white' => '#ffffff',
    'agree' => '#4CAF50',
    'procces' => '#ff9321',
    'brown' => '#712121'
];

/**
 * Convert #RRGGBB / #RGB / rgb(R,G,B) into "R, G, B" suitable for
 * `rgba(var(--primary-rgb), 0.06)` — lets stylesheets recolor
 * translucent washes alongside the brand hex update.
 */
function cm_hex_to_rgb_triplet(string $hex): string {
    $hex = trim($hex);
    if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $hex, $m)) {
        return $m[1] . ', ' . $m[2] . ', ' . $m[3];
    }
    if (str_starts_with($hex, '#')) $hex = substr($hex, 1);
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '0, 0, 0';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r, $g, $b";
}

echo ":root {\n";
echo "    /* Цвета */\n";

$resolved = [];
foreach ($defaultColors as $key => $default) {
    $saved = $db->getSetting("color_$key");
    $value = $saved ?: $default;
    // Если сохранено как JSON, декодируем
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if ($decoded !== null) {
            $value = $decoded;
        }
    }
    $resolved[$key] = (string)$value;
    echo "    --$key: $value;\n";
}

// Phase 10.9: also expose `R, G, B` triplets so stylesheets can do
// `rgba(var(--primary-rgb), 0.06)` for translucent washes that recolor
// alongside the brand hex update — avoids hardcoded `rgba(205,23,25,.06)`
// literals scattered across order-track.css, employee-triage.css, etc.
echo "    /* RGB triplets (Phase 10.9) */\n";
foreach ($resolved as $key => $hex) {
    echo "    --{$key}-rgb: " . cm_hex_to_rgb_triplet($hex) . ";\n";
}

echo "}\n";
?>