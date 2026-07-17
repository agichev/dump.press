<?php
declare(strict_types=1);

function createSession($userId) {
    global $pdo;
    $token = bin2hex(random_bytes(64));
    $csrf = bin2hex(random_bytes(64));
    $wsToken = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 86400 * 30);
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250);
    // Реальный IP пользователя сразу при создании сессии (без задержки).
    $ip = substr(getClientIp(), 0, 45);

    $stmt = $pdo->prepare("INSERT INTO sessions (token, user_id, csrf_token, ws_token, expires_at, user_agent, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$token, $userId, $csrf, $wsToken, $expires, $ua, $ip]);

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    setcookie('vibe_session', $token, [
        'expires' => time() + 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    return $csrf;
}

function getActiveSession() {
    global $pdo;
    $token = $_COOKIE['vibe_session'] ?? '';
    if (!$token) return null;
    $stmt = $pdo->prepare("SELECT * FROM sessions WHERE token = ? AND expires_at > ?");
    $stmt->execute([$token, date('Y-m-d H:i:s')]);
    return $stmt->fetch() ?: null;
}

function destroySession() {
    global $pdo;
    $token = $_COOKIE['vibe_session'] ?? '';
    if ($token) {
        $pdo->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
        setcookie('vibe_session', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}
