<?php
if (!defined('MENU_LABUS_ROOT')) {
    http_response_code(404);
    exit;
}

require_once MENU_LABUS_ROOT . '/session_init.php';
require_once MENU_LABUS_ROOT . '/require_auth.php';

header('Content-Type: application/json');

function cleanmenu_file_manager_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function cleanmenu_file_manager_error(string $message, int $status = 400): void
{
    cleanmenu_file_manager_respond([
        'success' => false,
        'error' => $message,
    ], $status);
}

function cleanmenu_file_manager_path_within_base(string $path, string $basePath): bool
{
    $normalizedBase = rtrim(str_replace('\\', '/', $basePath), '/');
    $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');

    return $normalizedPath === $normalizedBase || strpos($normalizedPath, $normalizedBase . '/') === 0;
}

function cleanmenu_file_manager_normalize_relative_path(string $value): ?string
{
    $value = trim(str_replace('\\', '/', urldecode($value)), '/');
    if ($value === '') {
        return '';
    }

    if (preg_match('/(?:^|\/)\.\.(?:\/|$)|[\x00-\x1F\x7F]/u', $value)) {
        return null;
    }

    $segments = explode('/', $value);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return null;
        }

        if (!preg_match('/^[^\/\x00-\x1F\x7F]{1,100}$/u', $segment)) {
            return null;
        }
    }

    return implode('/', $segments);
}

function cleanmenu_file_manager_safe_slug(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? '';
    if (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    if ($value === '') {
        return 'upload';
    }

    return substr($value, 0, 80);
}

function cleanmenu_file_manager_generated_filename(string $originalName, string $extension): string
{
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $safeBaseName = cleanmenu_file_manager_safe_slug($baseName);

    return sprintf('%s-%s.%s', $safeBaseName, bin2hex(random_bytes(6)), $extension);
}

function cleanmenu_file_manager_escape_css_string(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    return addcslashes($value, "\\'");
}

function cleanmenu_file_manager_public_asset_url(string $folder, string $filename): string
{
    $segments = explode('/', ltrim(str_replace('\\', '/', $folder . '/' . $filename), '/'));
    $segments = array_map(static fn(string $segment): string => rawurlencode($segment), $segments);

    return '/' . implode('/', $segments);
}

function cleanmenu_file_manager_is_valid_image_upload(string $tmpPath, string $extension): bool
{
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        return false;
    }

    $allowedTypesByExtension = [
        'jpg' => [IMAGETYPE_JPEG],
        'jpeg' => [IMAGETYPE_JPEG],
        'png' => [IMAGETYPE_PNG],
        'gif' => [IMAGETYPE_GIF],
        'webp' => [IMAGETYPE_WEBP],
        'ico' => defined('IMAGETYPE_ICO') ? [IMAGETYPE_ICO] : [],
    ];

    return in_array((int)$imageInfo[2], $allowedTypesByExtension[$extension] ?? [], true);
}

function cleanmenu_file_manager_is_valid_font_upload(string $tmpPath, string $extension): bool
{
    $handle = @fopen($tmpPath, 'rb');
    if ($handle === false) {
        return false;
    }

    $signature = (string)fread($handle, 4);
    fclose($handle);

    return match ($extension) {
        'woff' => $signature === 'wOFF',
        'woff2' => $signature === 'wOF2',
        'ttf' => $signature === "\x00\x01\x00\x00" || $signature === 'true' || $signature === 'ttcf',
        'otf' => $signature === 'OTTO',
        default => false,
    };
}

function cleanmenu_file_manager_rebuild_fonts_css(): void
{
    $fontsDir = MENU_LABUS_ROOT . '/fonts';
    $cssContent = '';

    if (!is_dir($fontsDir)) {
        @file_put_contents(MENU_LABUS_ROOT . '/auto-fonts.css', $cssContent, LOCK_EX);
        return;
    }

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

        $fontName = pathinfo($filename, PATHINFO_FILENAME);
        $fontName = preg_replace('/[-_](Regular|Bold|Light|Medium|SemiBold|ExtraBold|Black|Thin|Italic)$/i', '', $fontName);
        $fontName = ucfirst((string)$fontName);
        $format = match ($extension) {
            'ttf' => 'truetype',
            'otf' => 'opentype',
            default => $extension,
        };

        $cssContent .= "\n@font-face {\n";
        $cssContent .= "    font-family: '" . cleanmenu_file_manager_escape_css_string($fontName) . "';\n";
        $cssContent .= "    src: url('" . cleanmenu_file_manager_public_asset_url('fonts', $filename) . "') format('{$format}');\n";
        $cssContent .= "    font-display: swap;\n";
        $cssContent .= "    font-weight: 400;\n";
        $cssContent .= "}\n";
    }

    @file_put_contents(MENU_LABUS_ROOT . '/auto-fonts.css', $cssContent, LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $submittedToken = (string)($input['csrf_token'] ?? '');

    if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        cleanmenu_file_manager_error('CSRF token mismatch', 403);
    }
}

$allowedFolders = ['images', 'fonts', 'icons'];
$allowedTypes = [
    'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'fonts' => ['woff', 'woff2', 'ttf', 'otf'],
    'icons' => ['ico', 'png', 'jpg', 'jpeg', 'webp'],
];
$action = (string)($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_fonts') {
    $fontsPath = MENU_LABUS_ROOT . '/fonts/';
    $fonts = [];

    if (is_dir($fontsPath)) {
        try {
            $iterator = new DirectoryIterator($fontsPath);

            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot() || $fileinfo->isDir()) {
                    continue;
                }

                $filename = $fileinfo->getFilename();
                $extension = strtolower($fileinfo->getExtension());
                if (!in_array($extension, ['woff', 'woff2', 'ttf', 'otf'], true)) {
                    continue;
                }

                $fontName = pathinfo($filename, PATHINFO_FILENAME);
                $fontName = preg_replace('/[-_](Regular|Bold|Light|Medium|SemiBold|ExtraBold|Black|Thin|Italic)$/i', '', $fontName);
                $fontName = ucfirst((string)$fontName);
                $fonts[$fontName] = $filename;
            }

            ksort($fonts);
        } catch (Throwable $e) {
            error_log('Font directory iterator error: ' . $e->getMessage());
        }
    }

    cleanmenu_file_manager_respond(['fonts' => $fonts]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $folder = (string)($_GET['folder'] ?? '');
    $subfolder = cleanmenu_file_manager_normalize_relative_path((string)($_GET['subfolder'] ?? ''));

    if (!in_array($folder, $allowedFolders, true)) {
        cleanmenu_file_manager_respond(['items' => []]);
    }

    if ($subfolder === null) {
        cleanmenu_file_manager_respond(['items' => []]);
    }

    $basePath = realpath(MENU_LABUS_ROOT . '/' . $folder);
    if ($basePath === false) {
        cleanmenu_file_manager_respond(['items' => []]);
    }

    $path = $basePath;
    if ($subfolder !== '') {
        $requestedPath = $basePath . '/' . $subfolder;
        $resolvedPath = realpath($requestedPath);
        if ($resolvedPath === false || !cleanmenu_file_manager_path_within_base($resolvedPath, $basePath)) {
            cleanmenu_file_manager_respond(['items' => []]);
        }
        $path = $resolvedPath;
    }

    $items = [];
    if (is_dir($path)) {
        try {
            $iterator = new DirectoryIterator($path);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) {
                    continue;
                }

                $itemName = $fileinfo->getFilename();
                $relativePath = $subfolder !== '' ? $subfolder . '/' . $itemName : $itemName;
                $safeName = htmlspecialchars($itemName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safePath = htmlspecialchars($relativePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if ($fileinfo->isDir()) {
                    $items[] = [
                        'name' => $safeName,
                        'type' => 'folder',
                        'path' => $safePath,
                    ];
                    continue;
                }

                $items[] = [
                    'name' => $safeName,
                    'type' => 'file',
                    'path' => $safePath,
                    'size' => $fileinfo->getSize(),
                    'extension' => $fileinfo->getExtension(),
                ];
            }

            usort($items, static function (array $left, array $right): int {
                if ($left['type'] === $right['type']) {
                    return strcmp($left['name'], $right['name']);
                }

                return $left['type'] === 'folder' ? -1 : 1;
            });
        } catch (Throwable $e) {
            error_log('Directory iterator error: ' . $e->getMessage());
        }
    }

    cleanmenu_file_manager_respond(['items' => $items]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    $folder = (string)($_POST['folder'] ?? '');
    $subfolder = cleanmenu_file_manager_normalize_relative_path((string)($_POST['subfolder'] ?? ''));

    if (!in_array($folder, $allowedFolders, true)) {
        cleanmenu_file_manager_error('Invalid folder');
    }

    if ($subfolder === null) {
        cleanmenu_file_manager_error('Invalid subfolder path');
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        cleanmenu_file_manager_error('No file uploaded');
    }

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        cleanmenu_file_manager_error('Upload failed');
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        cleanmenu_file_manager_error('File too large (max 10MB)');
    }

    $originalName = (string)($file['name'] ?? '');
    if ($originalName === '' || preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $originalName)) {
        cleanmenu_file_manager_error('Invalid file name');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedTypes[$folder] ?? [], true)) {
        cleanmenu_file_manager_error('Invalid file type: ' . $extension);
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        cleanmenu_file_manager_error('Invalid upload source');
    }

    if ($folder === 'fonts') {
        if (!cleanmenu_file_manager_is_valid_font_upload($tmpPath, $extension)) {
            cleanmenu_file_manager_error('Uploaded file does not match the declared font type');
        }
    } elseif (!cleanmenu_file_manager_is_valid_image_upload($tmpPath, $extension)) {
        cleanmenu_file_manager_error('Uploaded file is not a valid image');
    }

    $basePath = realpath(MENU_LABUS_ROOT . '/' . $folder);
    if ($basePath === false) {
        cleanmenu_file_manager_error('Upload directory not found', 500);
    }

    $targetDir = $basePath;
    if ($subfolder !== '') {
        $targetDir .= '/' . $subfolder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            cleanmenu_file_manager_error('Failed to create directory', 500);
        }

        $resolvedTargetDir = realpath($targetDir);
        if ($resolvedTargetDir === false || !cleanmenu_file_manager_path_within_base($resolvedTargetDir, $basePath)) {
            cleanmenu_file_manager_error('Invalid subfolder path');
        }
        $targetDir = $resolvedTargetDir;
    }

    $storedFilename = cleanmenu_file_manager_generated_filename($originalName, $extension);
    $targetFile = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $storedFilename;

    if (!move_uploaded_file($tmpPath, $targetFile)) {
        cleanmenu_file_manager_error('Upload failed', 500);
    }

    @chmod($targetFile, 0644);

    if ($folder === 'fonts') {
        cleanmenu_file_manager_rebuild_fonts_css();
    }

    if ($folder === 'images') {
        require_once MENU_LABUS_ROOT . '/ImageOptimizer.php';
        try {
            $optimizer = new ImageOptimizer();
            $result = $optimizer->optimize($targetFile);
            if ($result) {
                error_log('Image optimized: ' . $targetFile);
            }
        } catch (Throwable $e) {
            error_log('Image optimization failed: ' . $e->getMessage());
        }
    }

    cleanmenu_file_manager_respond([
        'success' => true,
        'filename' => $storedFilename,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $folder = (string)($data['folder'] ?? '');
    $path = cleanmenu_file_manager_normalize_relative_path((string)($data['path'] ?? ''));
    $type = (string)($data['type'] ?? '');

    if (!in_array($folder, $allowedFolders, true)) {
        cleanmenu_file_manager_error('Invalid folder');
    }

    if ($path === null || $path === '') {
        cleanmenu_file_manager_error('Invalid path');
    }

    $basePath = realpath(MENU_LABUS_ROOT . '/' . $folder);
    if ($basePath === false) {
        cleanmenu_file_manager_error('Invalid folder');
    }

    $filePath = $basePath . '/' . $path;
    $resolvedPath = realpath($filePath);
    if ($resolvedPath === false || !cleanmenu_file_manager_path_within_base($resolvedPath, $basePath)) {
        cleanmenu_file_manager_error('Invalid path');
    }

    if ($type === 'folder') {
        if (!is_dir($resolvedPath)) {
            cleanmenu_file_manager_error('Folder not found', 404);
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            if ($fileinfo->isDir()) {
                rmdir($fileinfo->getRealPath());
            } else {
                unlink($fileinfo->getRealPath());
            }
        }
        rmdir($resolvedPath);

        cleanmenu_file_manager_respond(['success' => true]);
    }

    if (is_file($resolvedPath) && unlink($resolvedPath)) {
        cleanmenu_file_manager_respond(['success' => true]);
    }

    cleanmenu_file_manager_error('Delete failed', 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_folder') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $folder = (string)($data['folder'] ?? '');
    $subfolder = cleanmenu_file_manager_normalize_relative_path((string)($data['subfolder'] ?? ''));
    $newFolderName = trim((string)($data['name'] ?? ''));

    if (!in_array($folder, $allowedFolders, true)) {
        cleanmenu_file_manager_error('Invalid folder');
    }

    if ($subfolder === null) {
        cleanmenu_file_manager_error('Invalid subfolder path');
    }

    if ($newFolderName === '' || preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $newFolderName) || str_contains($newFolderName, '..')) {
        cleanmenu_file_manager_error('Invalid folder name');
    }

    $basePath = realpath(MENU_LABUS_ROOT . '/' . $folder);
    if ($basePath === false) {
        cleanmenu_file_manager_error('Invalid folder');
    }

    $targetDir = $basePath;
    if ($subfolder !== '') {
        $resolvedTargetDir = realpath($basePath . '/' . $subfolder);
        if ($resolvedTargetDir === false || !cleanmenu_file_manager_path_within_base($resolvedTargetDir, $basePath)) {
            cleanmenu_file_manager_error('Invalid subfolder path');
        }
        $targetDir = $resolvedTargetDir;
    }

    $newFolderPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $newFolderName;
    if (is_dir($newFolderPath)) {
        cleanmenu_file_manager_error('Folder already exists');
    }

    if (!mkdir($newFolderPath, 0755, true)) {
        cleanmenu_file_manager_error('Failed to create folder', 500);
    }

    $resolvedNewFolderPath = realpath($newFolderPath);
    if ($resolvedNewFolderPath === false || !cleanmenu_file_manager_path_within_base($resolvedNewFolderPath, $basePath)) {
        @rmdir($newFolderPath);
        cleanmenu_file_manager_error('Invalid folder path');
    }

    cleanmenu_file_manager_respond(['success' => true]);
}

cleanmenu_file_manager_error('Invalid action', 404);
