<?php
// Fix double-encoded UTF-8 in db.php
$file = __DIR__ . '/../db.php';
$content = file_get_contents($file);

$replacements = [
    'завершён' => 'завершён',
    'Приём' => 'Приём',
    'готовим' => 'готовим',
    'доставляем' => 'доставляем',
    'отказ' => 'отказ',
    'Неделя' => 'Неделя',
    'Время' => 'Время',
];

$total = 0;
foreach ($replacements as $garbled => $correct) {
    // Encode the correct Russian string through double-UTF8 to find the garbled version
    $doubleEncoded = mb_convert_encoding($correct, 'UTF-8', 'UTF-8');

    // Try direct replacement of what we know is garbled
    $count = 0;
    $content = str_replace($garbled, $correct, $content, $count);
    if ($count > 0) {
        echo "Replaced '$correct' (variant 1): $count times\n";
        $total += $count;
    }
}

// Also try a brute-force approach: find all single-quoted strings that contain
// sequences like "Р" followed by other Cyrillic-looking chars (double-encoded pattern)
// Pattern: bytes D0xx D0xx... where the decoded chars look like Р + something
$pattern = "/('(?:[\\xD0\\xD1][\\x80-\\xBF]){4,}')/";
preg_match_all($pattern, $content, $matches);
echo "\nRemaining potentially garbled strings:\n";
foreach ($matches[0] as $m) {
    // Try to detect if it's double-encoded
    $inner = substr($m, 1, -1); // strip quotes
    $decoded = @mb_detect_encoding($inner, 'UTF-8', true) ? $inner : '(not UTF-8)';
    echo "  Found: $m\n";
}

echo "\nTotal replacements: $total\n";
file_put_contents($file, $content);
echo "File saved.\n";
