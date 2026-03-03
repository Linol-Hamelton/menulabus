<?php
header('Content-Type: text/css');
header('Cache-Control: public, max-age=31536000');

$fontName = $_GET['font'] ?? '';
$fontFile = $_GET['file'] ?? '';

// Валидация: только безопасные символы в имени шрифта
if (!preg_match('/^[a-zA-Z0-9 _\-]{1,100}$/', $fontName)) {
    http_response_code(400);
    echo '/* Invalid font name */';
    exit;
}

// Валидация: только имя файла без пути, допустимые расширения
$fontFile = basename($fontFile);
$extension = strtolower(pathinfo($fontFile, PATHINFO_EXTENSION));
if (!in_array($extension, ['woff', 'woff2', 'ttf', 'otf'])) {
    http_response_code(400);
    echo '/* Invalid font file */';
    exit;
}

// Проверяем что файл реально существует в папке fonts
$fontPath = __DIR__ . '/fonts/' . $fontFile;
if (!file_exists($fontPath)) {
    http_response_code(404);
    echo '/* Font file not found */';
    exit;
}

$format = match($extension) {
    'ttf'  => 'truetype',
    'otf'  => 'opentype',
    default => $extension,
};

echo "@font-face {
    font-family: '$fontName';
    src: url('/fonts/$fontFile') format('$format');
    font-display: swap;
}
";
?>
