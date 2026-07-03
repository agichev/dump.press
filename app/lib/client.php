<?php
declare(strict_types=1);

function getClientIp(): string {
    $candidates = [];

    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '') $candidates[] = trim(explode(',', $cf)[0]);

    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        foreach (explode(',', $xff) as $part) {
            $candidates[] = trim($part);
        }
    }

    $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if ($real !== '') $candidates[] = trim($real);

    $candidates[] = $_SERVER['REMOTE_ADDR'] ?? '';

    $ipv4 = '';
    $ipv6 = '';
    foreach ($candidates as $ip) {
        $ip = trim($ip);
        if ($ip === '') continue;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipv4 = $ip;
            break;
        }
        if ($ipv6 === '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipv6 = $ip;
        }
    }
    return $ipv4 ?: ($ipv6 ?: '0.0.0.0');
}

/**
 * Внутренний прокси для безопасного отображения внешних изображений.
 */
function getProxyUrl($url): string {
    if (!$url) return '';
    if (strpos($url, ',') !== false) {
        $urls = explode(',', $url);
        return getProxyUrl($urls[0]);
    }
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '\\/');
    return $base_path . '/index.php?api=proxy&url=' . base64_encode($url);
}
