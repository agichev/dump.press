<?php
declare(strict_types=1);

/**
 * Безопасность: проверка Cloudflare Turnstile.
 *
 * Капча показывается в модалке при входе/регистрации. Токен проверяется
 * серверной стороной через siteverify. Если секрет не сконфигурирован
 * (например локальная разработка) — проверка пропускается.
 */
function verifyTurnstile(?string $token, string $remoteIp): bool {
    $secret = $GLOBALS['TURNSTILE_SECRET_KEY'] ?? '';
    $site   = $GLOBALS['TURNSTILE_SITE_KEY'] ?? '';
    // Капча отключена — не проверяем
    if ($secret === '' || $site === '') return true;
    if (!$token) return false;

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $remoteIp,
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) return false;
    $data = json_decode($res, true);
    return !empty($data['success']);
}
