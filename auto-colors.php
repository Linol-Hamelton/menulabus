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

echo ":root {\n";
echo "    /* Цвета */\n";

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
    echo "    --$key: $value;\n";
}

echo "}\n";
?>