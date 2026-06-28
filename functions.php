<?php
function createSession($userId) {
    global $pdo;
    $token = bin2hex(random_bytes(64)); 
    $csrf = bin2hex(random_bytes(64));
    $expires = date('Y-m-d H:i:s', time() + 86400 * 30);
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250);
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? 'Unknown', 0, 45);
    
    $stmt = $pdo->prepare("INSERT INTO sessions (token, user_id, csrf_token, expires_at, user_agent, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$token, $userId, $csrf, $expires, $ua, $ip]);
    
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    
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

function getProxyUrl($url) {
    if (!$url) return '';
    if (strpos($url, ',') !== false) {
        $urls = explode(',', $url);
        return getProxyUrl($urls[0]);
    }
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '\\/');
    return $base_path . '/index.php?api=proxy&url=' . base64_encode($url);
}

function sendResendEmail($to, $subject, $code) {
    $apiKey = $_ENV['RESEND_API_KEY'] ?? getenv('RESEND_API_KEY');
    if (!$apiKey) return false;
    
    $html = '
    <div style="font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; background: #000; color: #fff; padding: 40px 20px; text-align: center; border-radius: 12px; max-width: 500px; margin: 0 auto;">
        <h1 style="margin-bottom: 10px; font-size: 32px; letter-spacing: -1px; font-weight: 800;">Dump</h1>
        <p style="color: #808080; font-size: 16px; margin-top: 0;">Код подтверждения для двухфакторной аутентификации.</p>
        <div style="margin: 35px auto; background: #111; padding: 20px 30px; border-radius: 12px; font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #fff; width: fit-content; border: 1px solid #333;">' . $code . '</div>
        <p style="color: #808080; font-size: 14px; margin-top: 30px;">Если это были не вы, проигнорируйте это письмо.</p>
    </div>';

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'from' => 'Dump Security <noreply@dump.press>',
        'to' => [$to],
        'subject' => $subject,
        'html' => $html
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function generateBase32Secret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) $secret .= $chars[random_int(0, 31)];
    return $secret;
}

function base32_decode_tfa($b32) {
    $b32 = strtoupper($b32);
    $map = [
        'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,
        'I'=>8,'J'=>9,'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,
        'Q'=>16,'R'=>17,'S'=>18,'T'=>19,'U'=>20,'V'=>21,'W'=>22,'X'=>23,
        'Y'=>24,'Z'=>25,'2'=>26,'3'=>27,'4'=>28,'5'=>29,'6'=>30,'7'=>31
    ];
    $bin = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        if (isset($map[$b32[$i]])) $bin .= str_pad(decbin($map[$b32[$i]]), 5, '0', STR_PAD_LEFT);
    }
    $res = '';
    foreach (str_split($bin, 8) as $chunk) {
        if (strlen($chunk) == 8) $res .= chr(bindec($chunk));
    }
    return $res;
}

function verifyTOTP($secret, $code) {
    if (strlen($code) !== 6) return false;
    $decoded = base32_decode_tfa($secret);
    $timeSlot = floor(time() / 30);
    
    for ($i = -1; $i <= 1; $i++) {
        $ts = pack('N*', 0) . pack('N*', $timeSlot + $i);
        $hash = hash_hmac('sha1', $ts, $decoded, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $calc = (
            ((ord($hash[$offset+0]) & 0x7F) << 24) |
            ((ord($hash[$offset+1]) & 0xFF) << 16) |
            ((ord($hash[$offset+2]) & 0xFF) << 8) |
            (ord($hash[$offset+3]) & 0xFF)
        ) % 1000000;
        
        if (str_pad($calc, 6, '0', STR_PAD_LEFT) === $code) return true;
    }
    return false;
}
