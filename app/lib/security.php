<?php
declare(strict_types=1);

function verifyRecaptcha(?string $token): bool {
    $secret = $GLOBALS['RECAPTCHA_V3_SECRET_KEY'] ?? '';
    $site   = $GLOBALS['RECAPTCHA_V3_SITE_KEY'] ?? '';
    // Без явного DEV_MODE пустые ключи не являются поводом отключать защиту.
    if ($secret === '' || $site === '') {
        return !empty($GLOBALS['DEV_MODE']);
    }
    if (!$token) return false;

    $url = $GLOBALS['RECAPTCHA_VERIFY_URL'] ?? 'https://www.google.com/recaptcha/api/siteverify';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret'   => $secret,
        'response' => $token,
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) return false;
    $data = json_decode((string)$res, true);
    // reCAPTCHA v3 возвращает score от 0.0 до 1.0. Считаем валидным, если score >= 0.5.
    return !empty($data['success']) && ($data['score'] ?? 0) >= 0.5;
}

function verifyTurnstile(string $token, string $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify'): bool {
    $secret = $GLOBALS['TURNSTILE_SECRET_KEY'] ?? '';
    if ($secret === '') return false;
    if (!$token) return false;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret'   => $secret,
        'response' => $token,
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) return false;
    $data = json_decode((string)$res, true);
    return !empty($data['success']);
}
