<?php
declare(strict_types=1);

/**
 * Supported UI languages. Order is the order shown in the switcher.
 * 'en' is the source language and the fallback for missing keys.
 */
function supportedLocales(): array {
    return [
        'en' => 'English',
        'de' => 'Deutsch',
        'es' => 'Español',
        'fr' => 'Français',
        'hi' => 'हिन्दी',
        'zh' => '中文',
    ];
}

/**
 * Resolve the active locale, in priority order:
 *   1. ?lang=xx in the URL (also persisted to a cookie for the next request)
 *   2. lang cookie set by a previous switch
 *   3. Accept-Language header negotiation
 *   4. fallback: 'en'
 */
function getLocale(): string {
    $supported = array_keys(supportedLocales());
    if (isset($_GET['lang']) && is_string($_GET['lang'])
        && in_array($_GET['lang'], $supported, true)) {
        $lang = $_GET['lang'];
        if (!headers_sent()) {
            setcookie('lang', $lang, time() + 365 * 24 * 3600, '/');
        }
        $_COOKIE['lang'] = $lang;
        return $lang;
    }
    if (isset($_COOKIE['lang']) && is_string($_COOKIE['lang'])
        && in_array($_COOKIE['lang'], $supported, true)) {
        return $_COOKIE['lang'];
    }
    $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    return negotiateLocale(is_string($header) ? $header : '', $supported);
}

/**
 * Parse an RFC 7231 Accept-Language header and pick the supported locale with
 * the highest q. Matches by primary subtag only ("de-AT" → "de"). Unmatched
 * input returns 'en'. Exposed for tests.
 */
function negotiateLocale(string $header, array $supported): string {
    $best = 'en';
    $bestQ = -1.0;
    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $bits = explode(';', $part);
        $tag = strtolower(trim($bits[0]));
        if ($tag === '') continue;
        $q = 1.0;
        for ($i = 1, $n = count($bits); $i < $n; $i++) {
            if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $bits[$i], $m)) {
                $q = (float)$m[1];
            }
        }
        $primary = strtok($tag, '-');
        if (is_string($primary) && in_array($primary, $supported, true) && $q > $bestQ) {
            $best = $primary;
            $bestQ = $q;
        }
    }
    return $best;
}

/**
 * Load and cache translations for one locale. Missing file → empty array;
 * the t() helper then falls back to English and finally the key itself.
 */
function loadTranslations(string $locale): array {
    static $cache = [];
    if (isset($cache[$locale])) return $cache[$locale];
    $path = __DIR__ . '/i18n/' . $locale . '.json';
    if (!is_file($path)) {
        return $cache[$locale] = [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) return $cache[$locale] = [];
    $data = json_decode($raw, true);
    return $cache[$locale] = is_array($data) ? $data : [];
}

/**
 * Translate a key. Optional sprintf-style positional args are applied after
 * lookup so the placeholder strings stay translator-friendly ("Deleted set %s").
 * Missing key in the active locale falls back to English; missing in English
 * returns the key itself so the gap is visible in the UI.
 */
function t(string $key, $args = [], ?string $locale = null): string {
    $locale = $locale ?? getLocale();
    $data = loadTranslations($locale);
    $val = $data[$key] ?? null;
    if (!is_string($val)) {
        if ($locale !== 'en') {
            $en = loadTranslations('en');
            $val = $en[$key] ?? null;
        }
    }
    if (!is_string($val)) {
        $val = $key;
    }
    if (is_array($args) && !empty($args)) {
        return vsprintf($val, $args);
    }
    return $val;
}

/**
 * Strings exposed to client-side JS. Kept narrow on purpose — only the keys
 * used by the inline script in index.php, so we don't ship the whole catalog.
 */
function jsStrings(): array {
    $keys = [
        'rename.save', 'rename.cancel', 'rename.label', 'rename.failed', 'rename.success',
        'delete.confirm', 'delete.success', 'delete.failed',
        'status.downloading', 'status.done', 'status.download_failed', 'status.network_error',
        'count.set', 'count.sets',
    ];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = t($k);
    }
    return $out;
}
