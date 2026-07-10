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

    $publicV4 = '';
    $publicV6 = '';
    $anyV4 = '';
    $anyV6 = '';
    foreach ($candidates as $ip) {
        $ip = trim($ip);
        if ($ip === '') continue;

        $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

        if ($isV4 && $anyV4 === '') $anyV4 = $ip;
        if ($isV6 && $anyV6 === '') $anyV6 = $ip;

        $isPublic = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$isPublic) continue;

        if ($isV4) { $publicV4 = $ip; break; }
        if ($isV6 && $publicV6 === '') $publicV6 = $ip;
    }

    return $publicV4 ?: ($publicV6 ?: ($anyV4 ?: ($anyV6 ?: '0.0.0.0')));
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
