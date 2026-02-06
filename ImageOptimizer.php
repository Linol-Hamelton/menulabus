<?php
/**
 * Image Optimizer для Фазы 3 roadmap
 * WebP конвертация и оптимизация изображений
 */

class ImageOptimizer {
    private $quality = 85;
    private $webpQuality = 80;
    
    public function optimize($imagePath) {
        if (!file_exists($imagePath)) {
            return false;
        }
        
        $info = getimagesize($imagePath);
        if (!$info) {
            return false;
        }
        
        $type = $info[2];
        
        $image = match($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($imagePath),
            IMAGETYPE_PNG => imagecreatefrompng($imagePath),
            IMAGETYPE_GIF => imagecreatefromgif($imagePath),
            default => throw new Exception('Unsupported image type')
        };
        
        if (!$image) {
            return false;
        }
        
        // Создаем WebP версию
        $webpPath = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $imagePath);
        if (imagewebp($image, $webpPath, $this->webpQuality)) {
            // OK
        }
        
        // Оптимизируем оригинал
        match($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $imagePath, $this->quality),
            IMAGETYPE_PNG => $this->optimizePng($image, $imagePath),
            IMAGETYPE_GIF => imagegif($image, $imagePath),
            default => null
        };
        
        imagedestroy($image);
        
        return [
            'original' => $imagePath,
            'webp' => $webpPath
        ];
    }
    
    private function optimizePng($image, $path) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image, $path, 6); // Компрессия 6 - баланс размера/качества
    }
}

// Пример использования в file-manager.php
// $optimizer = new ImageOptimizer();
// $result = $optimizer->optimize($uploadedPath);
// if ($result) {
//     echo "Optimized: " . $result['webp'];
// }
?>
