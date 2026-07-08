<?php
declare(strict_types=1);

function sendFcmPush($pdo, $userId, $fromUserId, $type, $postId, $postSlug): void {
    $saJson = $GLOBALS['FIREBASE_SERVICE_ACCOUNT'] ?? '';
    if (!$saJson) return;

    $sa = json_decode($saJson, true);
    if (!$sa || !isset($sa['project_id'], $sa['client_email'], $sa['private_key'])) return;

    $projectId = $sa['project_id'];
    $username = '';
    if ($fromUserId) {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$fromUserId]);
        $username = $stmt->fetchColumn() ?: '';
    }

    $body = 'Новое уведомление';
    switch ($type) {
        case 'like':     $body = "$username поставил(а) лайк на ваш пост"; break;
        case 'comment':  $body = "$username написал(а) комментарий к вашему посту"; break;
        case 'follow':   $body = "$username подписался(-ась) на вас"; break;
        case 'new_post': $body = "$username опубликовал(а) новый пост"; break;
        case 'login':    $body = "Выполнен вход в ваш аккаунт"; break;
    }

    $stmt = $pdo->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
    $stmt->execute([$userId]);
    $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tokens)) return;

    if (!function_exists('curl_init') || !function_exists('openssl_sign')) return;

    $accessToken = getFcmAccessToken($sa);
    if (!$accessToken) return;

    $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";

    foreach ($tokens as $token) {
        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => 'Dump',
                    'body'  => $body,
                ],
                'data' => [
                    'type'          => $type,
                    'from_user_id'  => (string)$fromUserId,
                    'from_username' => $username,
                    'post_id'       => (string)$postId,
                    'post_slug'     => (string)$postSlug,
                ],
                'android' => ['priority' => 'HIGH'],
            ],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $accessToken",
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

function getFcmAccessToken(array $sa): string {
    $cacheKey = 'fcm_token_' . md5($sa['client_email']);
    $cacheDir = sys_get_temp_dir() . '/dump_fcm';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    $cacheFile = "$cacheDir/$cacheKey";

    $cached = @file_get_contents($cacheFile);
    if ($cached) {
        $data = json_decode($cached, true);
        if ($data && $data['expires_at'] > time() + 60) return $data['token'];
    }

    $now = time();
    $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $assertion = base64url_encode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));

    $signature = '';
    if (!openssl_sign("$header.$assertion", $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) return '';
    $jwt = "$header.$assertion." . base64url_encode($signature);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return '';

    $data = json_decode($response, true);
    if (!$data || empty($data['access_token'])) return '';

    $token = $data['access_token'];
    $expiresAt = $now + ($data['expires_in'] ?? 3600) - 300;

    @file_put_contents($cacheFile, json_encode(['token' => $token, 'expires_at' => $expiresAt]));

    return $token;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
