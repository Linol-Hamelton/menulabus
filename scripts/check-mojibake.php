#!/usr/bin/env php
<?php
declare(strict_types=1);

$repoRoot = realpath(__DIR__ . '/..') ?: getcwd();
if ($repoRoot === false) {
    fwrite(STDERR, "[mojibake] Unable to resolve repository root.\n");
    exit(2);
}

chdir($repoRoot);

/**
 * @return list<string>
 */
function defaultFileList(): array
{
    $output = [];
    $exitCode = 0;
    exec('git ls-files', $output, $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "[mojibake] Failed to collect tracked files with git ls-files.\n");
        exit(2);
    }

    return array_values(array_filter(array_map('trim', $output), static fn(string $file): bool => $file !== ''));
}

function normalizePath(string $path): string
{
    return str_replace('\\', '/', trim($path));
}

function isTargetFile(string $path): bool
{
    $path = normalizePath($path);
    if ($path === '') {
        return false;
    }

    foreach (['vendor/', 'node_modules/', '.git/', 'data/cache/', '.playwright-cli/', 'docs/archive/'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return false;
        }
    }

    if ($path === 'scripts/check-mojibake.php') {
        return false;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($extension, [
        'php',
        'js',
        'css',
        'md',
        'html',
        'htm',
        'json',
        'yml',
        'yaml',
        'txt',
        'svg',
    ], true);
}

function shortenLine(string $line, int $maxLength = 180): string
{
    $line = preg_replace('/\s+/u', ' ', trim($line)) ?? trim($line);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($line, 'UTF-8') <= $maxLength) {
            return $line;
        }

        return mb_substr($line, 0, max(0, $maxLength - 3), 'UTF-8') . '...';
    }

    if (strlen($line) <= $maxLength) {
        return $line;
    }

    return substr($line, 0, max(0, $maxLength - 3)) . '...';
}

$files = array_slice($argv, 1);
if ($files === []) {
    $files = defaultFileList();
}

$patterns = [
    ['label' => 'UTF-8 replacement character', 'regex' => '/\x{FFFD}/u'],
    ['label' => 'double-decoded replacement marker', 'regex' => '/\x{043F}\x{0457}\x{0405}/u'],
    ['label' => 'CP1251/UTF-8 mojibake chain', 'regex' => '/(?:\x{0420}.|\x{0421}.){3,}/u'],
    ['label' => 'Latin-1/UTF-8 mojibake chain', 'regex' => '/(?:\x{00D0}.|\x{00D1}.|\x{00E2}.){3,}/u'],
    ['label' => 'broken ruble sign', 'regex' => '/\x{0432}\x{201A}\x{0405}/u'],
];

$findings = [];
$scannedFiles = 0;

foreach ($files as $file) {
    $normalized = normalizePath((string) $file);
    if (!isTargetFile($normalized) || !is_file($normalized)) {
        continue;
    }

    $contents = @file_get_contents($normalized);
    if ($contents === false) {
        $findings[] = [
            'file' => $normalized,
            'line' => 0,
            'reason' => 'unable to read file',
            'snippet' => '',
        ];
        continue;
    }

    $scannedFiles++;

    if (!preg_match('//u', $contents)) {
        $findings[] = [
            'file' => $normalized,
            'line' => 0,
            'reason' => 'invalid UTF-8 stream',
            'snippet' => '',
        ];
        continue;
    }

    $lines = preg_split('/\R/u', $contents) ?: [];
    foreach ($lines as $index => $line) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern['regex'], $line) !== 1) {
                continue;
            }

            $findings[] = [
                'file' => $normalized,
                'line' => $index + 1,
                'reason' => $pattern['label'],
                'snippet' => shortenLine($line),
            ];
            break;
        }
    }
}

if ($findings !== []) {
    fwrite(STDERR, "[mojibake] Found suspicious text patterns:\n");
    foreach ($findings as $finding) {
        $location = $finding['line'] > 0
            ? $finding['file'] . ':' . $finding['line']
            : $finding['file'];
        fwrite(STDERR, sprintf("- %s [%s]\n", $location, $finding['reason']));
        if ($finding['snippet'] !== '') {
            fwrite(STDERR, "  " . $finding['snippet'] . "\n");
        }
    }
    exit(1);
}

fwrite(STDOUT, sprintf("[mojibake] OK (%d files scanned)\n", $scannedFiles));
exit(0);
