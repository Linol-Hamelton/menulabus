<?php
/**
 * Asset Pipeline для Фазы 3 roadmap
 * Минификация и версионирование CSS/JS
 */

class AssetPipeline {
    private static $manifest = null;
    private static $manifestFile = __DIR__ . '/asset-manifest.json';
    
    public static function asset($path) {
        if (self::$manifest === null) {
            self::loadManifest();
        }
        
        if (isset(self::$manifest[$path])) {
            return self::$manifest[$path];
        }
        
        return $path;
    }
    
    private static function loadManifest() {
        if (file_exists(self::$manifestFile)) {
            self::$manifest = json_decode(
                file_get_contents(self::$manifestFile), 
                true
            ) ?? [];
        } else {
            self::$manifest = [];
        }
    }
    
    public static function build() {
        $manifest = [];
        
        // CSS
        $cssFiles = glob(__DIR__ . '/css/*.css');
        $cssContent = '';
        foreach ($cssFiles as $file) {
            $cssContent .= file_get_contents($file) . "\n";
        }
        
        // Минификация CSS
        $cssContent = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $cssContent);
        $cssContent = preg_replace('/\s+/', ' ', $cssContent);
        $cssContent = trim($cssContent);
        
        $cssHash = md5($cssContent);
        $cssFile = "css/app.{$cssHash}.min.css";
        file_put_contents(__DIR__ . '/' . $cssFile, $cssContent);
        
        $manifest['css/app.css'] = '/' . $cssFile;
        
        // JS
        $jsFiles = glob(__DIR__ . '/js/*.js');
        $jsContent = '';
        foreach ($jsFiles as $file) {
            $jsContent .= file_get_contents($file) . ";\n";
        }
        
        // Базовая минификация JS
        $jsContent = preg_replace('!/\*.*?\*/!s', '', $jsContent);
        $jsContent = preg_replace('/\s+/', ' ', $jsContent);
        $jsContent = trim($jsContent);
        
        $jsHash = md5($jsContent);
        $jsFile = "js/app.{$jsHash}.min.js";
        file_put_contents(__DIR__ . '/' . $jsFile, $jsContent);
        
        $manifest['js/app.js'] = '/' . $jsFile;
        
        file_put_contents(
            self::$manifestFile, 
            json_encode($manifest, JSON_PRETTY_PRINT)
        );
        
        echo "Assets built successfully. Manifest updated.\n";
    }
}

// Использование в header.php
// <link rel="stylesheet" href="<?= AssetPipeline::asset('css/app.css') ?>">
// <script src="<?= AssetPipeline::asset('js/app.js') ?>" defer></script>
?>
