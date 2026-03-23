<?php
if (!defined('MENU_LABUS_ROOT')) {
    http_response_code(404);
    exit;
}

require_once MENU_LABUS_ROOT . '/session_init.php';
require_once MENU_LABUS_ROOT . '/require_auth.php';


header('Content-Type: application/json');

// Validate CSRF on POST requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    
    if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
        echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
        exit;
    }
}

$allowed_folders = ['images', 'fonts', 'icons'];
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_fonts') {
    $fontsPath = MENU_LABUS_ROOT . '/fonts/';
    $fonts = [];
    
    if (is_dir($fontsPath)) {
        try {
            $iterator = new DirectoryIterator($fontsPath);
            
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot() || $fileinfo->isDir()) continue;
                
                $filename = $fileinfo->getFilename();
                $extension = strtolower($fileinfo->getExtension());
                
                if (in_array($extension, ['woff', 'woff2', 'ttf', 'otf'])) {
                    $fontName = pathinfo($filename, PATHINFO_FILENAME);
                    // Strip common weight/style suffixes from the font name.
                    $fontName = preg_replace('/[-_](Regular|Bold|Light|Medium|SemiBold|ExtraBold|Black|Thin|Italic)$/i', '', $fontName);
                    $fontName = ucfirst($fontName);
                    
                    $fonts[$fontName] = $filename;
                }
            }
            
            ksort($fonts);
            
        } catch (Exception $e) {
            error_log("Font directory iterator error: " . $e->getMessage());
        }
    }
    
    echo json_encode(['fonts' => $fonts]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $folder = $_GET['folder'] ?? '';
    $subfolder = $_GET['subfolder'] ?? '';
    
    if (!in_array($folder, $allowed_folders)) {
        echo json_encode(['items' => []]);
        exit;
    }
    
    $base_path = realpath(MENU_LABUS_ROOT . '/' . $folder);
    $path = $base_path;
    
    if ($subfolder) {
        $decoded_subfolder = urldecode($subfolder);
        
        // Prevent directory traversal.
        if (preg_match('/\.\./', $decoded_subfolder)) {
            echo json_encode(['items' => []]);
            exit;
        }
        
        $path = $base_path . '/' . $decoded_subfolder;
        
        // Ensure the resolved path still stays inside the allowed directory.
        if (strpos(realpath($path), $base_path) !== 0) {
            echo json_encode(['items' => []]);
            exit;
        }
    }
    
    $items = [];
    
    if (is_dir($path)) {
        try {
            $iterator = new DirectoryIterator($path);
            
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDot()) continue;
                
                $itemName = $fileinfo->getFilename();
                $relativePath = ($subfolder ? $subfolder . '/' : '') . $itemName;
                
                if ($fileinfo->isDir()) {
                    $items[] = [
                        'name' => $itemName,
                        'type' => 'folder',
                        'path' => $relativePath
                    ];
                } else {
                    $items[] = [
                        'name' => $itemName,
                        'type' => 'file',
                        'path' => $relativePath,
                        'size' => $fileinfo->getSize(),
                        'extension' => $fileinfo->getExtension()
                    ];
                }
            }
            
            // Sort folders before files, then sort by name.
            usort($items, function($a, $b) {
                if ($a['type'] === $b['type']) {
                    return strcmp($a['name'], $b['name']);
                }
                return $a['type'] === 'folder' ? -1 : 1;
            });
            
        } catch (Exception $e) {
            error_log("Directory iterator error: " . $e->getMessage());
        }
    }
    
    echo json_encode(['items' => $items]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'upload') {
        $folder = $_POST['folder'] ?? '';
        $subfolder = $_POST['subfolder'] ?? '';
        
        if (!in_array($folder, $allowed_folders)) {
            echo json_encode(['success' => false, 'error' => 'Invalid folder']);
            exit;
        }
        
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'No file uploaded']);
            exit;
        }
        
        $file = $_FILES['file'];
        $target_dir = MENU_LABUS_ROOT . '/' . $folder . '/';
        
        if ($subfolder) {
            $decoded_subfolder = urldecode($subfolder);
            
            // Prevent directory traversal.
            if (preg_match('/\.\./', $decoded_subfolder)) {
                echo json_encode(['success' => false, 'error' => 'Invalid subfolder path']);
                exit;
            }
            
            $target_dir .= $decoded_subfolder . '/';
            
            // Ensure the resolved path still stays inside the allowed directory.
            $base_path = realpath(MENU_LABUS_ROOT . '/' . $folder);
            $full_path = realpath($target_dir) ?: $target_dir;
            if (strpos($full_path, $base_path) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid subfolder path']);
                exit;
            }
            
            // Create the subfolder lazily when needed.
            if (!is_dir($target_dir)) {
                if (!mkdir($target_dir, 0755, true)) {
                    echo json_encode(['success' => false, 'error' => 'Failed to create directory']);
                    exit;
                }
            }
        }
        
        $target_file = $target_dir . basename($file['name']);
        
        // Validate the uploaded file type.
        $allowed_types = [
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
            'fonts' => ['woff', 'woff2', 'ttf', 'otf'],
            'icons' => ['svg', 'ico', 'png', 'jpg', 'jpeg']
        ];
        
        $extension = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_types[$folder])) {
            echo json_encode(['success' => false, 'error' => 'Invalid file type: ' . $extension]);
            exit;
        }
        
        // Enforce a 10 MB upload limit.
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
            exit;
        }
        
        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            // Rebuild auto-fonts.css after font uploads.
            if ($folder === 'fonts') {
                $fontsDir = MENU_LABUS_ROOT . '/fonts';
                $cssContent = '';
                
                if (is_dir($fontsDir)) {
                    $iterator = new DirectoryIterator($fontsDir);
                    
                    foreach ($iterator as $fileinfo) {
                        if ($fileinfo->isDot() || $fileinfo->isDir()) continue;
                        
                        $filename = $fileinfo->getFilename();
                        $extension = strtolower($fileinfo->getExtension());
                        
                        if (in_array($extension, ['woff', 'woff2', 'ttf', 'otf'])) {
                            $fontName = pathinfo($filename, PATHINFO_FILENAME);
                            $fontName = preg_replace('/[-_](Regular|Bold|Light|Medium|SemiBold|ExtraBold|Black|Thin|Italic)$/i', '', $fontName);
                            $fontName = ucfirst($fontName);
                            
                            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                            $format = $extension === 'ttf' ? 'truetype' : 
                                     ($extension === 'otf' ? 'opentype' : $extension);
                            
                            $cssContent .= "
@font-face {
    font-family: '{$fontName}';
    src: url('../fonts/{$filename}') format('{$format}');
    font-display: swap;
    font-weight: 400;
}
                            ";
                        }
                    }
                }
                
                file_put_contents(MENU_LABUS_ROOT . '/auto-fonts.css', $cssContent);
            }
            
            // Optimize uploaded images when possible.
            if ($folder === 'images') {
                require_once MENU_LABUS_ROOT . '/ImageOptimizer.php';
                try {
                    $optimizer = new ImageOptimizer();
                    $result = $optimizer->optimize($target_file);
                    if ($result) {
                        // Keep a lightweight success marker in the PHP log.
                        error_log("Image optimized: " . $target_file);
                    }
                } catch (Exception $e) {
                    error_log("Image optimization failed: " . $e->getMessage());
                }
            }
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Upload failed']);
        }
        exit;
    }
    
    if ($action === 'delete') {
        $data = json_decode(file_get_contents('php://input'), true);
        $folder = $data['folder'] ?? '';
        $path = $data['path'] ?? '';
        $type = $data['type'] ?? '';
        
        if (!in_array($folder, $allowed_folders)) {
            echo json_encode(['success' => false, 'error' => 'Invalid folder']);
            exit;
        }
        
        $decoded_path = urldecode($path);
        // Prevent directory traversal.
        if (preg_match('/\.\./', $decoded_path)) {
            echo json_encode(['success' => false, 'error' => 'Invalid path']);
            exit;
        }
        
        $file_path = MENU_LABUS_ROOT . '/' . $folder . '/' . $decoded_path;
        
        // Ensure the resolved path stays inside the allowed directory.
        $base_path = realpath(MENU_LABUS_ROOT . '/' . $folder);
        $full_path = realpath($file_path) ?: $file_path;
        if (strpos($full_path, $base_path) !== 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid path']);
            exit;
        }
        
        if ($type === 'folder') {
            // Delete folders recursively.
            if (is_dir($file_path)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($file_path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($files as $fileinfo) {
                    if ($fileinfo->isDir()) {
                        rmdir($fileinfo->getRealPath());
                    } else {
                        unlink($fileinfo->getRealPath());
                    }
                }
                rmdir($file_path);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Folder not found']);
            }
        } else {
            // Delete files directly.
            if (file_exists($file_path) && unlink($file_path)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Delete failed']);
            }
        }
        exit;
    }
    
    if ($action === 'create_folder') {
        $data = json_decode(file_get_contents('php://input'), true);
        $folder = $data['folder'] ?? '';
        $subfolder = $data['subfolder'] ?? '';
        $newFolderName = $data['name'] ?? '';
        
        if (!in_array($folder, $allowed_folders)) {
            echo json_encode(['success' => false, 'error' => 'Invalid folder']);
            exit;
        }
        
        if (empty($newFolderName)) {
            echo json_encode(['success' => false, 'error' => 'Folder name required']);
            exit;
        }
        
        // Reject unsafe folder names.
        if (preg_match('/[\/\\\\]/', $newFolderName)) {
            echo json_encode(['success' => false, 'error' => 'Invalid folder name']);
            exit;
        }
        
        $target_dir = MENU_LABUS_ROOT . '/' . $folder . '/';
        if ($subfolder) {
            $decoded_subfolder = urldecode($subfolder);
            
            // Prevent directory traversal.
            if (preg_match('/\.\./', $decoded_subfolder)) {
                echo json_encode(['success' => false, 'error' => 'Invalid subfolder path']);
                exit;
            }
            
            $target_dir .= $decoded_subfolder . '/';
            
            // Ensure the resolved path stays inside the allowed directory.
            $base_path = realpath(MENU_LABUS_ROOT . '/' . $folder);
            $full_path = realpath($target_dir) ?: $target_dir;
            if (strpos($full_path, $base_path) !== 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid subfolder path']);
                exit;
            }
        }
        
        $target_dir .= $newFolderName;
        
        if (!mkdir($target_dir, 0755, true)) {
            echo json_encode(['success' => false, 'error' => 'Failed to create folder']);
        } else {
            echo json_encode(['success' => true]);
        }
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
