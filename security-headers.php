<?php
/**
 * Security Headers для проекта menu.labus.pro
 * Этот файл подключается через auto_prepend_file в PHP-FPM
 * и добавляет недостающие заголовки, которые могут быть потеряны
 * из-за особенностей конфигурации Nginx.
 */

// Отправляем заголовки только если они ещё не отправлены
if (!headers_sent()) {
    // Strict-Transport-Security (HSTS)
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

    // X-Frame-Options
    header('X-Frame-Options: DENY');

    // X-Content-Type-Options
    header('X-Content-Type-Options: nosniff');

    // X-XSS-Protection
    header('X-XSS-Protection: 1; mode=block');

    // Referrer-Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Cross-Origin-Resource-Policy
    header('Cross-Origin-Resource-Policy: same-origin');

    // Cross-Origin-Embedder-Policy
    header('Cross-Origin-Embedder-Policy: require-corp');

    // Cross-Origin-Opener-Policy
    header('Cross-Origin-Opener-Policy: same-origin');

    // Content-Security-Policy
    // Базовая политика, можно расширить под конкретные нужды
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;");

    // Дополнительный заголовок для отключения MIME-типа
    header('X-Content-Type-Options: nosniff');
}
?>