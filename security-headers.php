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

    // Content-Security-Policy — strict fallback baseline.
    // session_init.php overrides this with a nonce-aware policy on pages it loads.
    // Pages that bypass session_init (JSON webhooks, CLI helpers) inherit this safe default.
    header("Content-Security-Policy: " . implode('; ', [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        "img-src 'self' data: blob:",
        "font-src 'self'",
        "connect-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        "manifest-src 'self'",
    ]));
}
?>