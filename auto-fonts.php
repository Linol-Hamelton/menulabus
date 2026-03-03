<?php
require_once __DIR__ . '/session_init.php';

header('Content-Type: text/css');
header("Cache-Control: no-cache, must-revalidate");

$fontsDir = __DIR__ . '/fonts';
$fontVariants = [];

if (is_dir($fontsDir)) {
    $iterator = new DirectoryIterator($fontsDir);
    
    foreach ($iterator as $fileinfo) {
        if ($fileinfo->isDot() || $fileinfo->isDir()) continue;
        
        $filename = $fileinfo->getFilename();
        $extension = strtolower($fileinfo->getExtension());
        
        if (in_array($extension, ['woff', 'woff2', 'ttf', 'otf'])) {
            $baseName = pathinfo($filename, PATHINFO_FILENAME);
            $fontName = preg_replace('/[-_](Regular|Bold|Light|Medium|SemiBold|ExtraBold|Black|Thin|Italic)$/i', '', $baseName);
            $fontName = ucfirst($fontName);
            
            // Определяем вес шрифта
            $weight = 400; // по умолчанию
            if (preg_match('/Bold|Black|ExtraBold/i', $baseName)) $weight = 700;
            elseif (preg_match('/Light|Thin/i', $baseName)) $weight = 300;
            elseif (preg_match('/Medium/i', $baseName)) $weight = 500;
            elseif (preg_match('/SemiBold/i', $baseName)) $weight = 600;
            
            $fontVariants[$fontName][] = [
                'file' => $filename,
                'weight' => $weight,
                'style' => preg_match('/Italic/i', $baseName) ? 'italic' : 'normal'
            ];
        }
    }
}

// Генерируем @font-face для всех вариантов
foreach ($fontVariants as $fontName => $variants) {
    foreach ($variants as $variant) {
        $extension = strtolower(pathinfo($variant['file'], PATHINFO_EXTENSION));
        $format = $extension === 'ttf' ? 'truetype' :
                 ($extension === 'otf' ? 'opentype' : $extension);

        echo "
@font-face {
    font-family: '{$fontName}';
    src: url('../fonts/{$variant['file']}') format('{$format}');
    font-display: swap;
    font-weight: {$variant['weight']};
    font-style: {$variant['style']};
}
        ";
    }
}

// CSS-переменные для шрифтов из базы данных
$db = Database::getInstance();

$defaultFonts = [
    'logo'    => "'Magistral', serif",
    'text'    => "'proxima-nova', sans-serif",
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