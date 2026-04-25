<?php
require_once __DIR__ . '/session_init.php';

header('Content-Type: text/css');
header('Cache-Control: no-cache, must-revalidate');

function cleanmenu_auto_fonts_escape_css_string(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return addcslashes($value, "\\'");
}

function cleanmenu_auto_fonts_public_url(string $folder, string $filename): string
{
    $segments = explode('/', ltrim(str_replace('\\', '/', $folder . '/' . $filename), '/'));
    $segments = array_map(static fn(string $segment): string => rawurlencode($segment), $segments);

    return '/' . implode('/', $segments);
}

$fontsDir = __DIR__ . '/fonts';
$fontVariants = [];

if (is_dir($fontsDir)) {
    $iterator = new DirectoryIterator($fontsDir);

    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDot() || $fileinfo->isDir()) {
            continue;
        }

        $filename = $fileinfo->getFilename();
        $extension = strtolower($fileinfo->getExtension());

        if (!in_array($extension, ['woff', 'woff2', 'ttf', 'otf'], true)) {
            continue;
        }

        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $fontName = preg_replace('/[-_](Regular|Bold|Light|Medium|SemiBold|ExtraBold|Black|Thin|Italic)$/i', '', $baseName);
        $fontName = ucfirst((string)$fontName);

        $weight = 400;
        if (preg_match('/Bold|Black|ExtraBold/i', $baseName)) {
            $weight = 700;
        } elseif (preg_match('/Light|Thin/i', $baseName)) {
            $weight = 300;
        } elseif (preg_match('/Medium/i', $baseName)) {
            $weight = 500;
        } elseif (preg_match('/SemiBold/i', $baseName)) {
            $weight = 600;
        }

        $fontVariants[$fontName][] = [
            'file' => $filename,
            'weight' => $weight,
            'style' => preg_match('/Italic/i', $baseName) ? 'italic' : 'normal',
        ];
    }
}

foreach ($fontVariants as $fontName => $variants) {
    foreach ($variants as $variant) {
        $extension = strtolower(pathinfo($variant['file'], PATHINFO_EXTENSION));
        $format = match ($extension) {
            'ttf' => 'truetype',
            'otf' => 'opentype',
            default => $extension,
        };

        echo "\n@font-face {\n";
        echo "    font-family: '" . cleanmenu_auto_fonts_escape_css_string($fontName) . "';\n";
        echo "    src: url('" . cleanmenu_auto_fonts_public_url('fonts', $variant['file']) . "') format('{$format}');\n";
        echo "    font-display: swap;\n";
        echo "    font-weight: {$variant['weight']};\n";
        echo "    font-style: {$variant['style']};\n";
        echo "}\n";
    }
}

$db = Database::getInstance();
$defaultFonts = [
    'logo' => "'Magistral', serif",
    'text' => "'proxima-nova', sans-serif",
    'heading' => "'Inter', sans-serif",
];

echo ":root {\n";
foreach ($defaultFonts as $key => $default) {
    $saved = $db->getSetting("font_$key");
    if ($saved) {
        $decoded = json_decode($saved, true);
        $value = ($decoded !== null) ? $decoded : $default;
    } else {
        $value = $default;
    }

    echo "    --font-$key: $value;\n";
}
echo "}\n";
?>
