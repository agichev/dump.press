<?php
declare(strict_types=1);

function sendFcmPush($pdo, $userId, $fromUserId, $type, $postId, $postSlug): void {
    $saJson = $GLOBALS['FIREBASE_SERVICE_ACCOUNT'] ?? '';
    if (!$saJson) {
        error_log("FCM: FIREBASE_SERVICE_ACCOUNT is empty — check .env");
        return;
    }

    $sa = json_decode($saJson, true);
    if (!$sa || !isset($sa['project_id'], $sa['client_email'], $sa['private_key'])) {
        error_log("FCM: invalid service account JSON after base64_decode/json_decode");
        return;
    }

    $projectId = $sa['project_id'];
    $username = '';
    if ($fromUserId) {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$fromUserId]);
        $username = $stmt->fetchColumn() ?: '';
    }

    $body = match ($type) {
        'like'     => "$username поставил(а) лайк на ваш пост",
        'comment'  => "$username написал(а) комментарий к вашему посту",
        'follow'   => "$username подписался(-ась) на вас",
        'new_post' => "$username опубликовал(а) новый пост",
        'login'    => "Выполнен вход в ваш аккаунт",
        default    => 'Новое уведомление',
    };

    $stmt = $pdo->prepare("SELECT token FROM fcm_tokens WHERE user_id = ?");
    $stmt->execute([$userId]);
    $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($tokens)) {
        error_log("FCM: no tokens for user #$userId");
        return;
    }

    if (!function_exists('curl_init')) {
        error_log("FCM: curl is not installed");
        return;
    }
    if (!function_exists('openssl_sign')) {
        error_log("FCM: openssl is not installed");
        return;
    }

    $accessToken = getFcmAccessToken($sa);
    if (!$accessToken) {
        error_log("FCM: failed to obtain OAuth2 access token — check server logs above");
        return;
    }

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
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("FCM: push failed (HTTP $httpCode) for token " . substr($token, 0, 20) . "... response: " . substr($response, 0, 500));
        }
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
    $signOk = openssl_sign("$header.$assertion", $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
    if (!$signOk) {
        error_log("FCM: openssl_sign failed — check private_key format");
        return '';
    }
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
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("FCM: token endpoint returned HTTP $httpCode — $error — response: " . substr($response, 0, 500));
        return '';
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['access_token'])) {
        error_log("FCM: no access_token in token response — " . substr($response, 0, 500));
        return '';
    }

    $token = $data['access_token'];
    $expiresAt = $now + ($data['expires_in'] ?? 3600) - 300;

    @file_put_contents($cacheFile, json_encode(['token' => $token, 'expires_at' => $expiresAt]));

    return $token;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
