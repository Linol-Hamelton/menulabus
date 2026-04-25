<?php

/**
 * I18n — minimal translation helper for Phase 7.3.
 *
 * Lookup model:
 *   - Locales live as flat JSON in /locales/{ru,en,kk}.json.
 *   - `t('cart.title')` returns the value at the dotted key, falling back to
 *     the default locale (ru), then to the key string itself if nothing matches.
 *   - `t('cart.greeting', ['name' => 'Маша'])` interpolates named %{name}
 *     placeholders.
 *
 * Locale resolution order:
 *   1. Explicit ?lang=xx in the current request → also persisted to a cookie.
 *   2. Cookie `cleanmenu_lang`.
 *   3. Tenant setting `default_locale`.
 *   4. 'ru' (hard fallback — matches the historical hardcoded language).
 *
 * Designed to be no-op safe: if the locales file is missing, t() returns the
 * key, so a partial migration of templates doesn't break the page — strings
 * just stay in their original form.
 */
final class I18n
{
    private const SUPPORTED = ['ru', 'en', 'kk'];
    private const DEFAULT   = 'ru';
    private const COOKIE    = 'cleanmenu_lang';

    private static ?string $current = null;
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public static function locale(): string
    {
        if (self::$current !== null) return self::$current;

        $url = isset($_GET['lang']) ? strtolower((string)$_GET['lang']) : '';
        if ($url !== '' && in_array($url, self::SUPPORTED, true)) {
            self::$current = $url;
            self::persistCookie($url);
            return $url;
        }
        $cookie = isset($_COOKIE[self::COOKIE]) ? strtolower((string)$_COOKIE[self::COOKIE]) : '';
        if ($cookie !== '' && in_array($cookie, self::SUPPORTED, true)) {
            self::$current = $cookie;
            return $cookie;
        }
        // Tenant setting fallback. We can't depend on Database being loaded
        // (lib/I18n.php is included from session_init.php very early), so we
        // probe via $GLOBALS['defaultLocale'] which session_init can set.
        $tenantDefault = isset($GLOBALS['defaultLocale']) ? strtolower((string)$GLOBALS['defaultLocale']) : '';
        if ($tenantDefault !== '' && in_array($tenantDefault, self::SUPPORTED, true)) {
            self::$current = $tenantDefault;
            return $tenantDefault;
        }
        self::$current = self::DEFAULT;
        return self::DEFAULT;
    }

    public static function setLocale(string $locale): void
    {
        $locale = strtolower(trim($locale));
        if (!in_array($locale, self::SUPPORTED, true)) return;
        self::$current = $locale;
        self::persistCookie($locale);
    }

    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    public static function t(string $key, array $params = []): string
    {
        $locale = self::locale();
        $value = self::lookup($key, $locale);
        if ($value === null && $locale !== self::DEFAULT) {
            $value = self::lookup($key, self::DEFAULT);
        }
        if ($value === null) {
            $value = $key;
        }
        if (!empty($params)) {
            foreach ($params as $name => $val) {
                $value = str_replace('%{' . $name . '}', (string)$val, $value);
            }
        }
        return $value;
    }

    private static function lookup(string $key, string $locale): ?string
    {
        $bundle = self::loadBundle($locale);
        if (empty($bundle)) return null;
        // Dotted key traversal.
        $cursor = $bundle;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return is_string($cursor) ? $cursor : null;
    }

    private static function loadBundle(string $locale): array
    {
        if (isset(self::$cache[$locale])) return self::$cache[$locale];
        $path = dirname(__DIR__) . '/locales/' . $locale . '.json';
        if (!is_file($path)) {
            self::$cache[$locale] = [];
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            self::$cache[$locale] = [];
            return [];
        }
        $decoded = json_decode($raw, true);
        self::$cache[$locale] = is_array($decoded) ? $decoded : [];
        return self::$cache[$locale];
    }

    private static function persistCookie(string $locale): void
    {
        if (headers_sent()) return;
        $params = session_get_cookie_params();
        @setcookie(self::COOKIE, $locale, [
            'expires'  => time() + (60 * 60 * 24 * 365),
            'path'     => $params['path'] ?? '/',
            'secure'   => (bool)($params['secure'] ?? false),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('t')) {
    /**
     * Convenience wrapper so templates can read like:
     *   <?= t('cart.title') ?>
     * instead of `I18n::t(...)`. Plays nice with htmlspecialchars when needed.
     */
    function t(string $key, array $params = []): string
    {
        return I18n::t($key, $params);
    }
}
