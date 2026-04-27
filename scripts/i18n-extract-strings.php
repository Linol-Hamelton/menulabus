<?php
/**
 * i18n-extract-strings.php — surface untranslated UI strings (Phase 7.3, 2026-04-28).
 *
 * Walks PHP and JS files under the project root looking for hardcoded
 * Cyrillic text in user-visible positions:
 *   - PHP echo / <?= /  print() of literal strings
 *   - PHP htmlspecialchars(literal)
 *   - JS .textContent = "..." / alert("...") / .innerHTML = "..." (literal)
 *   - HTML title / placeholder / alt / aria-label attributes
 *
 * The scanner is intentionally conservative — it flags candidates rather
 * than auto-rewriting. Output is a list of (file:line) → text occurrences,
 * grouped by file, with a frequency tally to help prioritise extraction.
 *
 * Usage:
 *   php scripts/i18n-extract-strings.php
 *   php scripts/i18n-extract-strings.php --root=. --min-len=4
 *   php scripts/i18n-extract-strings.php --json --out=/tmp/i18n-todo.json
 *
 * Skipped paths: vendor/, node_modules/, tests/visual/__snapshots__/,
 * data/, *.min.js (minified noise), CSS files (no user text), images.
 *
 * False-positive minimisers:
 *   - require >= --min-len chars of contiguous Cyrillic
 *   - skip lines whose surrounding context references t( or htmlspecialchars(t(
 *   - skip strings inside SQL queries (INSERT/SELECT/UPDATE/DELETE)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['root::', 'min-len::', 'json', 'out::', 'help']);
if (array_key_exists('help', $opts)) {
    fwrite(STDOUT, "Usage: php scripts/i18n-extract-strings.php [--root=PATH] [--min-len=N] [--json] [--out=FILE]\n");
    exit(0);
}

$root   = realpath($opts['root'] ?? __DIR__ . '/..');
$minLen = max(2, min(40, (int)($opts['min-len'] ?? 4)));
$wantJson = array_key_exists('json', $opts);
$outPath = $opts['out'] ?? null;

if (!$root || !is_dir($root)) {
    fwrite(STDERR, "Bad root: {$root}\n");
    exit(1);
}

$skipPatterns = [
    '#/vendor/#', '#/node_modules/#', '#/tests/visual/__snapshots__/#',
    '#/data/#', '#\.min\.js$#', '#\.css$#', '#\.png$#', '#\.jpe?g$#',
    '#\.webp$#', '#\.svg$#', '#\.ico$#', '#\.woff2?$#', '#\.ttf$#',
    '#\.lock$#', '#/\.git/#', '#/\.playwright(-cli)?/#', '#/\.playwright-mcp/#',
    '#/audit-screens/#', '#/scripts/i18n-extract-strings\.php$#',
    '#/locales/#',
];

$cyrillic = '/[А-ЯЁа-яёa-zA-Z][А-ЯЁа-яё][А-ЯЁа-яё ,!.\-?:;()«»\d]{' . ($minLen - 1) . ',}/u';

$findings = []; // file => array of [line, text]
$tally = [];   // text => count

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    $path = str_replace('\\', '/', $f->getPathname());
    if (!$f->isFile()) continue;
    foreach ($skipPatterns as $p) {
        if (preg_match($p, $path)) continue 2;
    }
    $ext = strtolower($f->getExtension());
    if (!in_array($ext, ['php', 'js', 'html', 'phtml'], true)) continue;

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!$lines) continue;

    foreach ($lines as $i => $line) {
        // Skip if this line uses t() near the cyrillic — heuristic: same line
        if (preg_match('/\bt\s*\(/', $line)) continue;
        // Skip SQL noise
        if (preg_match('/\b(INSERT|UPDATE|DELETE|SELECT|CREATE|ALTER)\b/i', $line)) continue;
        if (preg_match_all($cyrillic, $line, $matches)) {
            foreach ($matches[0] as $m) {
                $m = trim($m);
                if (mb_strlen($m, 'UTF-8') < $minLen) continue;
                $findings[$path][] = ['line' => $i + 1, 'text' => $m];
                $tally[$m] = ($tally[$m] ?? 0) + 1;
            }
        }
    }
}

arsort($tally);

if ($wantJson) {
    $payload = ['scanned_root' => $root, 'min_len' => $minLen, 'tally' => $tally, 'findings' => $findings];
    $out = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($outPath) { file_put_contents($outPath, $out); fwrite(STDERR, "[i18n-extract] wrote {$outPath}\n"); }
    else { fwrite(STDOUT, $out . "\n"); }
    exit(0);
}

$totalFindings = 0;
foreach ($findings as $arr) { $totalFindings += count($arr); }
$totalUnique = count($tally);

fwrite(STDOUT, "[i18n-extract] root={$root}\n");
fwrite(STDOUT, "[i18n-extract] total findings={$totalFindings} unique={$totalUnique}\n\n");

fwrite(STDOUT, "TOP 30 MOST-REPEATED CANDIDATES (highest first):\n");
$top = array_slice($tally, 0, 30, true);
foreach ($top as $text => $n) {
    fwrite(STDOUT, sprintf("  %4d × %s\n", $n, $text));
}

fwrite(STDOUT, "\nFILES WITH MOST FINDINGS (top 20):\n");
$byFile = [];
foreach ($findings as $file => $arr) { $byFile[$file] = count($arr); }
arsort($byFile);
$top = array_slice($byFile, 0, 20, true);
foreach ($top as $file => $n) {
    fwrite(STDOUT, sprintf("  %4d  %s\n", $n, str_replace($root . '/', '', $file)));
}

fwrite(STDOUT, "\nRun with --json --out=tmp/i18n-todo.json for the full report.\n");
