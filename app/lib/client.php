<?php
declare(strict_types=1);

function getClientIp(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', ''))));

    // Без доверенных прокси используем прямой адрес соединения.
    if (empty($trusted)) {
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    // Если REMOTE_ADDR не принадлежит доверенному прокси — игнорируем X-Forwarded-*.
    if (!in_array($remote, $trusted, true)) {
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    $ip = trim(explode(',', $cf)[0]);
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $ip;
    }

    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        // Первый публичный IP в цепочке — клиент.
        foreach ($parts as $part) {
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $part;
            }
        }
    }

    $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    $ip = trim($real);
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function getProxyUrl($url): string {
    if (!$url) return '';
    if (strpos($url, ',') !== false) {
        $urls = explode(',', $url);
        return getProxyUrl($urls[0]);
    }
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '\\/');
    return $base_path . '/index.php?api=proxy&url=' . base64_encode($url);
}
