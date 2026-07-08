<?php
declare(strict_types=1);

if (!headers_sent()) {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; font-src 'self' data: https:; img-src 'self' data: blob: https: http:; connect-src 'self' https: wss:; frame-src 'self' https://www.google.com https://recaptcha.google.com; frame-ancestors 'none'; form-action 'self';");
}

if (!function_exists('load_env')) {
function load_env(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Убираем обрамляющие кавычки.
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        if (!array_key_exists($name, $_ENV)) $_ENV[$name] = $value;
        if (getenv($name) === false) putenv("$name=$value");
    }
}
}

load_env(__DIR__ . '/../.env');
if (is_file(__DIR__ . '/../.env.local')) load_env(__DIR__ . '/../.env.local');

if (!function_exists('env')) {
function env(string $key, string $default = ''): string {
    $v = $_ENV[$key] ?? (getenv($key) ?: '');
    return $v !== '' ? $v : $default;
}
}

if (!function_exists('env_bool')) {
function env_bool(string $key, bool $default = false): bool {
    $v = strtolower(env($key, ''));
    if ($v === '') return $default;
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}
}

/* ---------------------------------------------------------------------
 |  База данных
 | -------------------------------------------------------------------- */
$GLOBALS['db_host']     = env('DB_HOST', 'localhost');
$GLOBALS['db_port']     = env('DB_PORT', '3306');
$GLOBALS['db_name']     = env('DB_NAME', 'dump_db');
$GLOBALS['db_user']     = env('DB_USERNAME', env('DB_USER', 'root'));
$GLOBALS['db_password'] = env('DB_PASSWORD', '');

/* ---------------------------------------------------------------------
 |  Интеграции (заполняются на проде через .env)
 | -------------------------------------------------------------------- */
    // Google reCAPTCHA v3 (капча на входе/регистрации).
$GLOBALS['RECAPTCHA_V3_SITE_KEY']   = env('RECAPTCHA_V3_SITE_KEY', '');
$GLOBALS['RECAPTCHA_V3_SECRET_KEY'] = env('RECAPTCHA_V3_SECRET_KEY', '');

// Хостинг изображений imgbb.
$GLOBALS['IMGBB_API_KEY'] = env('IMGBB_API_KEY', '');

// Транзакционная почта Resend (коды 2FA).
$GLOBALS['RESEND_API_KEY'] = env('RESEND_API_KEY', '');

// Публичный базовый URL сервиса (для sitemap/SEO/ссылок).
$GLOBALS['APP_URL'] = rtrim(env('APP_URL', ''), '/');

// Firebase Cloud Messaging v1 — service account JSON (base64).
$GLOBALS['FIREBASE_SERVICE_ACCOUNT'] = base64_decode(env('FIREBASE_SERVICE_ACCOUNT', ''), true) ?: '';

// Контактная почта поддержки.
$GLOBALS['SUPPORT_EMAIL'] = env('SUPPORT_EMAIL', 'help@dump.press');
