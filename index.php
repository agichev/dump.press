<?php
// ==========================================
// 1. НАСТРОЙКИ БАЗЫ ДАННЫХ И КОНФИГУРАЦИЯ (.env)
// ==========================================
// БЕЗОПАСНОСТЬ: Добавляем базовые HTTP заголовки защиты
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
// Расширяем CSP
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; font-src 'self' data: https:; img-src 'self' data: blob: https: http:; connect-src 'self' https: wss:; frame-ancestors 'none'; form-action 'self';");

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}

$db_host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$db_port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
$db_name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'dump_db';
$db_user = $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?? $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$db_password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;charset=utf8mb4", $db_user, $db_password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true, 
    ]);
    
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    // Основные таблицы
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            username VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            avatar_url VARCHAR(500) DEFAULT '',
            bio TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token VARCHAR(128) PRIMARY KEY,
            user_id INT NOT NULL,
            csrf_token VARCHAR(128) NOT NULL,
            expires_at DATETIME NOT NULL,
            user_agent VARCHAR(255) DEFAULT '',
            ip_address VARCHAR(45) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            slug VARCHAR(64) UNIQUE,
            content TEXT NOT NULL,
            image_url TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            post_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_like (user_id, post_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            post_id INT NOT NULL,
            content TEXT NOT NULL,
            image_url VARCHAR(500) DEFAULT '',
            parent_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS follows (
            follower_id INT NOT NULL,
            following_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (follower_id, following_id),
            FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS views (
            user_id INT NOT NULL,
            post_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, post_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS bookmarks (
            user_id INT NOT NULL,
            post_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, post_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS comment_likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            comment_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_clike (user_id, comment_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
        );
    ");

    // Индексы
    try { $pdo->exec("CREATE UNIQUE INDEX idx_posts_slug ON posts(slug)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE FULLTEXT INDEX idx_fulltext_content ON posts(content)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_posts_user_time ON posts(user_id, created_at)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_likes_post ON likes(post_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_comments_post ON comments(post_id)"); } catch (PDOException $e) {}

    // Обновления для 2FA
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_enabled TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_method VARCHAR(20) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_secret VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS temp_auth (
            token VARCHAR(128) PRIMARY KEY,
            user_id INT NOT NULL,
            code VARCHAR(10) DEFAULT '',
            type VARCHAR(20) DEFAULT '',
            expires_at DATETIME NOT NULL
        );
    ");

    $pdo->exec("UPDATE posts SET slug = SUBSTRING(MD5(RAND()), 1, 10) WHERE slug IS NULL OR slug = ''");

} catch (PDOException $e) {
    die("Критическая ошибка БД: Сервис временно недоступен."); 
}

// ==========================================
// 2. СИСТЕМА СЕССИЙ И CSRF
// ==========================================
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

// ==========================================
// 2.5 ФУНКЦИИ 2FA (Resend + TOTP)
// ==========================================
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
    
    // Проверка текущего и соседних интервалов времени (+- 30 сек)
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


// ==========================================
// 3. БЭКЕНД API
// ==========================================
if (isset($_GET['api'])) {
    $action = $_GET['api'];
    
    if ($action === 'proxy') {
        $url = base64_decode($_GET['url'] ?? '');
        $parsed = parse_url($url);
        $is_allowed_host = false;
        if (isset($parsed['host'])) {
            $allowed_domains = ['ibb.co', 'i.ibb.co', 'imgbb.com', 'i.imgbb.com', 'ui-avatars.com'];
            if (in_array(strtolower($parsed['host']), $allowed_domains, true)) {
                $is_allowed_host = true;
            }
        }
        if ($is_allowed_host && filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)) {
            $context = stream_context_create(['http' => [
                'method' => 'GET', 'header' => "User-Agent: Dump/6.6\r\n", 'timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true
            ]]);
            $image_data = @file_get_contents($url, false, $context);
            if ($image_data !== false) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($image_data);
                if (strpos($mime, 'image/') === 0) {
                    header("Content-Type: $mime");
                    header("Cache-Control: public, max-age=31536000");
                    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:;"); 
                    header("X-Content-Type-Options: nosniff");
                    echo $image_data;
                    exit;
                }
            }
        }
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#1c1c1c"/></svg>';
        exit;
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    
    $current_session = getActiveSession();
    
    function requireAuth() {
        global $current_session;
        if (!$current_session) {
            echo json_encode(['success' => false, 'error' => 'Не авторизован']);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['login', 'register', 'tfa_verify_login'])) {
        $client_csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (!$current_session || !hash_equals($current_session['csrf_token'], $client_csrf)) {
            echo json_encode(['success' => false, 'error' => 'Ошибка безопасности (CSRF). Пожалуйста, обновите страницу.']);
            exit;
        }
    }

    try {
        switch ($action) {
            case 'register':
                $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
                $password = $_POST['password'] ?? '';
                if (!$email || strlen($password) < 6) throw new Exception('Укажите корректный email и пароль (минимум 6 символов).');
                
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) throw new Exception('Пользователь с таким email уже существует.');

                $username = 'dump_' . bin2hex(random_bytes(4));
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("INSERT INTO users (email, username, password_hash) VALUES (?, ?, ?)");
                $stmt->execute([$email, $username, $hash]);
                createSession($pdo->lastInsertId());
                echo json_encode(['success' => true]);
                break;

            case 'login':
                $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
                $password = $_POST['password'] ?? '';
                $stmt = $pdo->prepare("SELECT id, email, password_hash, tfa_enabled, tfa_method FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                $dummy_hash = '$2y$10$abcdefghijklmnopqrstuv'; 
                $is_valid = false;
                
                if ($user) {
                    $is_valid = password_verify($password, $user['password_hash']);
                } else {
                    password_verify($password, $dummy_hash); 
                }
                
                if ($is_valid) {
                    // Проверка на включенный 2FA
                    if ($user['tfa_enabled']) {
                        $tempToken = bin2hex(random_bytes(32));
                        $code = '';
                        
                        if ($user['tfa_method'] === 'email') {
                            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                            sendResendEmail($user['email'], "Код входа: $code", $code);
                        }
                        
                        $stmt = $pdo->prepare("INSERT INTO temp_auth (token, user_id, code, type, expires_at) VALUES (?, ?, ?, 'login', DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
                        $stmt->execute([$tempToken, $user['id'], $code]);
                        
                        echo json_encode([
                            'success' => true, 
                            'require_2fa' => true, 
                            'method' => $user['tfa_method'], 
                            'temp_token' => $tempToken
                        ]);
                        exit;
                    }

                    destroySession();
                    createSession($user['id']);
                    echo json_encode(['success' => true, 'require_2fa' => false]);
                } else {
                    usleep(random_int(300000, 500000));
                    throw new Exception('Неверный email или пароль.');
                }
                break;

            case 'tfa_verify_login':
                $token = $_POST['temp_token'] ?? '';
                $code = trim($_POST['code'] ?? '');
                if (!$token || !$code) throw new Exception('Некорректные данные');

                $stmt = $pdo->prepare("SELECT ta.user_id, ta.code as temp_code, u.tfa_method, u.tfa_secret FROM temp_auth ta JOIN users u ON ta.user_id = u.id WHERE ta.token = ? AND ta.type = 'login' AND ta.expires_at > NOW()");
                $stmt->execute([$token]);
                $authData = $stmt->fetch();

                if (!$authData) throw new Exception('Сессия входа устарела, попробуйте снова.');

                $isValid = false;
                if ($authData['tfa_method'] === 'email') {
                    if ($authData['temp_code'] === $code) $isValid = true;
                } else if ($authData['tfa_method'] === 'app') {
                    if (verifyTOTP($authData['tfa_secret'], $code)) $isValid = true;
                }

                if ($isValid) {
                    $pdo->prepare("DELETE FROM temp_auth WHERE token = ?")->execute([$token]);
                    destroySession();
                    createSession($authData['user_id']);
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception('Неверный код.');
                }
                break;

            case 'logout':
                destroySession();
                echo json_encode(['success' => true]);
                break;

            case 'me':
                if ($current_session) {
                    $stmt = $pdo->prepare("SELECT id, username, email, avatar_url, bio, created_at, tfa_enabled FROM users WHERE id = ?");
                    $stmt->execute([$current_session['user_id']]);
                    $user = $stmt->fetch();
                    $user['username'] = htmlspecialchars($user['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $user['bio'] = htmlspecialchars($user['bio'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $user['avatar_url'] = htmlspecialchars($user['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    echo json_encode(['user' => $user, 'csrf' => $current_session['csrf_token']]);
                } else {
                    echo json_encode(['user' => null]);
                }
                break;

            // --- 2FA НАСТРОЙКИ (SETUP) ---
            case 'tfa_settings':
                requireAuth();
                $stmt = $pdo->prepare("SELECT tfa_enabled, tfa_method FROM users WHERE id = ?");
                $stmt->execute([$current_session['user_id']]);
                echo json_encode(['success' => true, 'data' => $stmt->fetch()]);
                break;

            case 'tfa_setup_start':
                requireAuth();
                $method = $_POST['method'] ?? 'email';
                if (!in_array($method, ['email', 'app'])) throw new Exception('Неверный метод');

                $tempToken = bin2hex(random_bytes(32));
                
                if ($method === 'app') {
                    $secret = generateBase32Secret();
                    $stmt = $pdo->prepare("INSERT INTO temp_auth (token, user_id, code, type, expires_at) VALUES (?, ?, ?, 'setup_app', DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
                    $stmt->execute([$tempToken, $current_session['user_id'], $secret]);
                    
                    $stmtUser = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                    $stmtUser->execute([$current_session['user_id']]);
                    $email = $stmtUser->fetchColumn();
                    
                    $qrName = urlencode("Dump:$email");
                    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=otpauth://totp/$qrName?secret=$secret&issuer=Dump";
                    
                    echo json_encode(['success' => true, 'temp_token' => $tempToken, 'secret' => $secret, 'qr_url' => $qrUrl]);
                } else {
                    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("INSERT INTO temp_auth (token, user_id, code, type, expires_at) VALUES (?, ?, ?, 'setup_email', DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
                    $stmt->execute([$tempToken, $current_session['user_id'], $code]);
                    
                    $stmtUser = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                    $stmtUser->execute([$current_session['user_id']]);
                    sendResendEmail($stmtUser->fetchColumn(), "Настройка 2FA: $code", $code);
                    
                    echo json_encode(['success' => true, 'temp_token' => $tempToken, 'message' => 'Код отправлен на email']);
                }
                break;

            case 'tfa_setup_verify':
                requireAuth();
                $token = $_POST['temp_token'] ?? '';
                $code = trim($_POST['code'] ?? '');
                
                $stmt = $pdo->prepare("SELECT * FROM temp_auth WHERE token = ? AND user_id = ? AND expires_at > NOW()");
                $stmt->execute([$token, $current_session['user_id']]);
                $authData = $stmt->fetch();

                if (!$authData) throw new Exception('Сессия истекла');

                $isValid = false;
                $finalSecret = '';
                $method = '';

                if ($authData['type'] === 'setup_app') {
                    if (verifyTOTP($authData['code'], $code)) {
                        $isValid = true;
                        $finalSecret = $authData['code'];
                        $method = 'app';
                    }
                } else if ($authData['type'] === 'setup_email') {
                    if ($authData['code'] === $code) {
                        $isValid = true;
                        $method = 'email';
                    }
                }

                if ($isValid) {
                    $pdo->prepare("UPDATE users SET tfa_enabled = 1, tfa_method = ?, tfa_secret = ? WHERE id = ?")
                        ->execute([$method, $finalSecret, $current_session['user_id']]);
                    $pdo->prepare("DELETE FROM temp_auth WHERE token = ?")->execute([$token]);
                    echo json_encode(['success' => true]);
                } else {
                    throw new Exception('Неверный код');
                }
                break;

            case 'tfa_disable':
                requireAuth();
                $pdo->prepare("UPDATE users SET tfa_enabled = 0, tfa_method = '', tfa_secret = '' WHERE id = ?")
                    ->execute([$current_session['user_id']]);
                echo json_encode(['success' => true]);
                break;


            // ... (ВЕСЬ ОСТАЛЬНОЙ API КОД БЕЗ ИЗМЕНЕНИЙ) ...
            case 'search':
                $q = trim($_GET['q'] ?? '');
                if (!$q) { echo json_encode(['users' => [], 'posts' => []]); break; }
                
                $stmt_posts = $pdo->prepare("
                    SELECT p.id, p.slug, p.content, p.image_url, u.username, u.avatar_url 
                    FROM posts p JOIN users u ON p.user_id = u.id 
                    WHERE p.content LIKE ? ORDER BY p.created_at DESC LIMIT 15
                ");
                $stmt_posts->execute(["%$q%"]);
                $posts = $stmt_posts->fetchAll();
                
                $stmt_users = $pdo->prepare("SELECT id, username, avatar_url FROM users WHERE username LIKE ? LIMIT 10");
                $stmt_users->execute(["%$q%"]);
                $users = $stmt_users->fetchAll();
                
                foreach($posts as &$post) {
                    $post['content'] = htmlspecialchars($post['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $post['username'] = htmlspecialchars($post['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $post['avatar_url'] = htmlspecialchars($post['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $post['image_url'] = htmlspecialchars($post['image_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                foreach($users as &$u) {
                    $u['username'] = htmlspecialchars($u['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $u['avatar_url'] = htmlspecialchars($u['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                
                echo json_encode(['users' => $users, 'posts' => $posts]);
                break;

            case 'user_profile':
                $user_id = (int)($_GET['id'] ?? 0);
                $current_user_id = $current_session ? (int)$current_session['user_id'] : 0;
                
                $stmt = $pdo->prepare("
                    SELECT id, username, avatar_url, bio, created_at,
                        (SELECT COUNT(*) FROM posts WHERE user_id = u.id) as posts_count,
                        (SELECT COUNT(*) FROM follows WHERE following_id = u.id) as followers_count,
                        (SELECT COUNT(*) FROM follows WHERE follower_id = u.id) as following_count,
                        (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) as is_followed
                    FROM users u WHERE id = ?
                ");
                $stmt->execute([$current_user_id, $user_id]);
                $profile = $stmt->fetch();
                if (!$profile) throw new Exception('Пользователь не найден');

                $profile['username'] = htmlspecialchars($profile['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $profile['bio'] = htmlspecialchars((string)($profile['bio'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $profile['avatar_url'] = htmlspecialchars($profile['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $stmt_posts = $pdo->prepare("
                    SELECT p.*, u.username, u.avatar_url,
                        (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as likes_count,
                        (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as is_liked,
                        (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id AND user_id = ?) as is_bookmarked,
                        (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count
                    FROM posts p JOIN users u ON p.user_id = u.id
                    WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT 50
                ");
                $stmt_posts->execute([$current_user_id, $current_user_id, $user_id]);
                $posts = $stmt_posts->fetchAll();
                
                $bookmarks = [];
                if ($current_user_id === $user_id) {
                    $stmt_bookmarks = $pdo->prepare("
                        SELECT p.*, u.username, u.avatar_url,
                            (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as likes_count,
                            (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as is_liked,
                            1 as is_bookmarked,
                            (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count
                        FROM posts p 
                        JOIN bookmarks b ON p.id = b.post_id 
                        JOIN users u ON p.user_id = u.id
                        WHERE b.user_id = ? ORDER BY b.created_at DESC LIMIT 50
                    ");
                    $stmt_bookmarks->execute([$current_user_id, $user_id]);
                    $bookmarks = $stmt_bookmarks->fetchAll();
                }

                foreach($posts as &$post) {
                    $post['content'] = htmlspecialchars($post['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $post['username'] = htmlspecialchars($post['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $post['avatar_url'] = htmlspecialchars($post['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $post['image_url'] = htmlspecialchars($post['image_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                foreach($bookmarks as &$bmark) {
                    $bmark['content'] = htmlspecialchars($bmark['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $bmark['username'] = htmlspecialchars($bmark['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $bmark['avatar_url'] = htmlspecialchars($bmark['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $bmark['image_url'] = htmlspecialchars($bmark['image_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                echo json_encode(['profile' => $profile, 'posts' => $posts, 'bookmarks' => $bookmarks]);
                break;

            case 'toggle_follow':
                requireAuth();
                $following_id = (int)($_POST['id'] ?? 0);
                $follower_id = (int)$current_session['user_id'];
                if ($follower_id === $following_id) throw new Exception('Нельзя подписаться на себя');
                
                $stmt = $pdo->prepare("SELECT * FROM follows WHERE follower_id = ? AND following_id = ?");
                $stmt->execute([$follower_id, $following_id]);
                if ($stmt->fetch()) {
                    $pdo->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?")->execute([$follower_id, $following_id]);
                    echo json_encode(['followed' => false]);
                } else {
                    $pdo->prepare("INSERT INTO follows (follower_id, following_id) VALUES (?, ?)")->execute([$follower_id, $following_id]);
                    echo json_encode(['followed' => true]);
                }
                break;

            case 'mark_seen':
                requireAuth();
                $post_id = (int)($_POST['post_id'] ?? 0);
                if ($post_id > 0) {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO views (user_id, post_id) VALUES (?, ?)");
                    $stmt->execute([$current_session['user_id'], $post_id]);
                }
                echo json_encode(['success' => true]);
                break;

            case 'posts':
                $type = $_GET['type'] ?? 'all'; 
                $limit = 35; 
                $user_id = $current_session ? (int)$current_session['user_id'] : 0;
                $requested_slug = $_GET['slug'] ?? '';
                
                $posts = [];
                $exclude_ids = [];

                if ($requested_slug) {
                    $stmt = $pdo->prepare("
                        SELECT p.*, u.username, u.avatar_url, 
                               (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as likes_count,
                               (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as is_liked,
                               (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id AND user_id = ?) as is_bookmarked,
                               (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count
                        FROM posts p JOIN users u ON p.user_id = u.id 
                        WHERE p.slug = ?
                    ");
                    $stmt->execute([$user_id, $user_id, $requested_slug]);
                    $single = $stmt->fetch();
                    if ($single) {
                        $posts[] = $single;
                        $exclude_ids[] = (int)$single['id'];
                    }
                }

                $join_cond = "";
                $where_cond = "WHERE 1=1 ";
                $select_cols = "p.*, u.username, u.avatar_url, 
                               (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as likes_count,
                               (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as is_liked,
                               (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id AND user_id = ?) as is_bookmarked,
                               (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count";
                $order_clause = "ORDER BY p.created_at DESC";
                $params = [$user_id, $user_id];

                if (!empty($exclude_ids)) {
                    $safe_ids = array_map('intval', $exclude_ids); 
                    $where_cond .= " AND p.id NOT IN (" . implode(',', $safe_ids) . ") ";
                }

                if ($type === 'all' && $user_id) {
                    $join_cond = "LEFT JOIN views v ON p.id = v.post_id AND v.user_id = " . (int)$user_id . " ";
                    $where_cond .= " AND v.post_id IS NULL AND p.user_id != " . (int)$user_id . " ";

                    $stmt_likes = $pdo->prepare("
                        (SELECT p.content FROM likes l JOIN posts p ON l.post_id = p.id WHERE l.user_id = ? AND p.content != '' ORDER BY l.created_at DESC LIMIT 15)
                        UNION
                        (SELECT p.content FROM comments c JOIN posts p ON c.post_id = p.id WHERE c.user_id = ? AND p.content != '' ORDER BY c.created_at DESC LIMIT 10)
                    ");
                    $stmt_likes->execute([$user_id, $user_id]);
                    $likedTexts = $stmt_likes->fetchAll(PDO::FETCH_COLUMN);
                    
                    $relevance_sql = " (
                        ((SELECT COUNT(*) FROM likes WHERE post_id = p.id) * 0.15) + 
                        ((SELECT COUNT(*) FROM comments WHERE post_id = p.id) * 0.25) +
                        IF(p.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR), 3.5, 0) +
                        (RAND() * 2.0)
                    ) ";

                    if (!empty($likedTexts)) {
                        $allText = implode(' ', $likedTexts);
                        
                        preg_match_all('/#[a-zA-Zа-яА-ЯёЁ0-9_]+/u', mb_strtolower($allText, 'UTF-8'), $hash_matches);
                        if (!empty($hash_matches[0])) {
                            $top_tags = array_slice(array_unique($hash_matches[0]), 0, 7);
                            foreach ($top_tags as $tag) {
                                $tag_esc = $pdo->quote('%' . $tag . '%');
                                $relevance_sql .= " + IF(p.content LIKE $tag_esc, 5.0, 0) ";
                            }
                        }

                        preg_match_all('/[a-zA-Zа-яА-ЯёЁ]{4,}/u', mb_strtolower($allText, 'UTF-8'), $matches);
                        if (!empty($matches[0])) {
                            $words = array_slice(array_unique($matches[0]), 0, 15);
                            $searchQuery = implode(' ', $words);
                            if ($searchQuery) {
                                $relevance_sql .= " + MATCH(p.content) AGAINST(? IN NATURAL LANGUAGE MODE) * 2.0 ";
                                $params[] = $searchQuery;
                            }
                        }
                    }

                    $select_cols .= ", ($relevance_sql) as relevance_score";
                    $order_clause = "ORDER BY relevance_score DESC, p.created_at DESC";

                } elseif ($type === 'following' && $user_id) {
                    $join_cond = "JOIN follows f ON p.user_id = f.following_id AND f.follower_id = " . (int)$user_id;
                }

                $sql = "SELECT $select_cols FROM posts p JOIN users u ON p.user_id = u.id $join_cond $where_cond $order_clause LIMIT $limit";
                $stmt = $pdo->prepare($sql);
                
                try {
                    $stmt->execute($params);
                    $feed_posts = $stmt->fetchAll();
                    $posts = array_merge($posts, $feed_posts);
                } catch (PDOException $e) {
                    if (strpos($sql, 'MATCH') !== false) {
                        $sql_fallback = "SELECT p.*, u.username, u.avatar_url, 
                                       (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as likes_count,
                                       (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as is_liked,
                                       (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id AND user_id = ?) as is_bookmarked,
                                       (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count
                                       FROM posts p JOIN users u ON p.user_id = u.id $join_cond $where_cond ORDER BY p.created_at DESC LIMIT $limit";
                        $stmt = $pdo->prepare($sql_fallback);
                        $stmt->execute([$user_id, $user_id]);
                        $feed_posts = $stmt->fetchAll();
                        $posts = array_merge($posts, $feed_posts);
                    } else {
                        throw $e;
                    }
                }
                
                foreach($posts as &$post) {
                    $post['content'] = htmlspecialchars($post['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $post['username'] = htmlspecialchars($post['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $post['avatar_url'] = htmlspecialchars($post['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (!empty($post['image_url'])) {
                        $imgArr = explode(',', $post['image_url']);
                        $post['image_url'] = htmlspecialchars(implode(',', array_unique(array_filter($imgArr))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                echo json_encode(['posts' => $posts]);
                break;

            case 'upload_image':
                requireAuth();
                if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('Ошибка загрузки файла.');
                }
                $file = $_FILES['image'];
                if ($file['size'] > 5 * 1024 * 1024) throw new Exception('Файл слишком большой (макс 5 МБ).');
                
                $mime = mime_content_type($file['tmp_name']);
                if (strpos($mime, 'image/') !== 0) throw new Exception('Недопустимый формат файла.');

                $apiKey = $_ENV['IMGBB_API_KEY'] ?? getenv('IMGBB_API_KEY') ?: '5a676b7e05fcbc4f253e20eeb725c488';
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://api.imgbb.com/1/upload?key=" . urlencode($apiKey));
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $cFile = new CURLFile($file['tmp_name'], $mime, $file['name']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['image' => $cFile]);
                $response = curl_exec($ch);
                curl_close($ch);

                $data = json_decode($response, true);
                if (isset($data['success']) && $data['success']) {
                    echo json_encode(['success' => true, 'url' => $data['data']['url']]);
                } else {
                    throw new Exception('Ошибка хостинга изображений.');
                }
                break;

            case 'create_post':
                requireAuth();
                $content = trim($_POST['content'] ?? '');
                
                $raw_image_urls = explode(',', trim($_POST['image_url'] ?? ''));
                $valid_urls = [];
                foreach ($raw_image_urls as $url) {
                    $url = trim($url);
                    if ($url && filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)) {
                        $valid_urls[] = $url;
                    }
                }
                $image_url = implode(',', array_unique($valid_urls));
                
                if (empty($content) && empty($image_url)) throw new Exception('Пост пуст');
                
                $slug = bin2hex(random_bytes(5)); 
                
                $stmt = $pdo->prepare("INSERT INTO posts (user_id, content, image_url, slug) VALUES (?, ?, ?, ?)");
                $stmt->execute([$current_session['user_id'], $content, $image_url, $slug]);
                echo json_encode(['success' => true, 'slug' => $slug]);
                break;

            case 'delete_post':
                requireAuth();
                $post_id = (int)($_POST['post_id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?");
                $stmt->execute([$post_id, $current_session['user_id']]);
                echo json_encode(['success' => true]);
                break;

            case 'toggle_like':
                requireAuth();
                $post_id = (int)($_POST['post_id'] ?? 0);
                $user_id = (int)$current_session['user_id'];
                $stmt = $pdo->prepare("SELECT id FROM likes WHERE user_id = ? AND post_id = ?");
                $stmt->execute([$user_id, $post_id]);
                if ($stmt->fetch()) {
                    $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?")->execute([$user_id, $post_id]);
                    echo json_encode(['liked' => false]);
                } else {
                    $pdo->prepare("INSERT INTO likes (user_id, post_id) VALUES (?, ?)")->execute([$user_id, $post_id]);
                    echo json_encode(['liked' => true]);
                }
                break;

            case 'toggle_bookmark':
                requireAuth();
                $post_id = (int)($_POST['post_id'] ?? 0);
                $user_id = (int)$current_session['user_id'];
                $stmt = $pdo->prepare("SELECT * FROM bookmarks WHERE user_id = ? AND post_id = ?");
                $stmt->execute([$user_id, $post_id]);
                if ($stmt->fetch()) {
                    $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ? AND post_id = ?")->execute([$user_id, $post_id]);
                    echo json_encode(['bookmarked' => false]);
                } else {
                    $pdo->prepare("INSERT INTO bookmarks (user_id, post_id) VALUES (?, ?)")->execute([$user_id, $post_id]);
                    echo json_encode(['bookmarked' => true]);
                }
                break;

            case 'comments':
                $post_id = (int)($_GET['post_id'] ?? 0);
                $user_id = $current_session ? (int)$current_session['user_id'] : 0;
                $stmt = $pdo->prepare("
                    SELECT c.*, u.username, u.avatar_url,
                        (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id) as likes_count,
                        (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.id AND user_id = ?) as is_liked
                    FROM comments c JOIN users u ON c.user_id = u.id 
                    WHERE c.post_id = ? ORDER BY c.created_at ASC LIMIT 150
                ");
                $stmt->execute([$user_id, $post_id]);
                $comments = $stmt->fetchAll();
                
                foreach($comments as &$comment) {
                    $comment['content'] = htmlspecialchars($comment['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $comment['username'] = htmlspecialchars($comment['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $comment['avatar_url'] = htmlspecialchars($comment['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $comment['image_url'] = htmlspecialchars($comment['image_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                echo json_encode(['comments' => $comments]);
                break;

            case 'add_comment':
                requireAuth();
                $post_id = (int)($_POST['post_id'] ?? 0);
                $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
                $content = trim($_POST['content'] ?? '');
                
                $image_url = trim($_POST['image_url'] ?? '');
                if ($image_url && (!filter_var($image_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $image_url))) { $image_url = ''; }

                if ($parent_id) {
                    $stmt = $pdo->prepare("SELECT id FROM comments WHERE id = ? AND post_id = ?");
                    $stmt->execute([$parent_id, $post_id]);
                    if (!$stmt->fetch()) {
                        $parent_id = null;
                    }
                }

                if (empty($content) && empty($image_url)) throw new Exception('Пустой комментарий');
                
                $stmt = $pdo->prepare("INSERT INTO comments (user_id, post_id, content, image_url, parent_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$current_session['user_id'], $post_id, $content, $image_url, $parent_id]);
                echo json_encode(['success' => true]);
                break;
                
            case 'delete_comment':
                requireAuth();
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("
                    DELETE FROM comments 
                    WHERE id = ? AND (
                        user_id = ? OR 
                        post_id IN (SELECT id FROM posts WHERE user_id = ?)
                    )
                ");
                $stmt->execute([$id, $current_session['user_id'], $current_session['user_id']]);
                echo json_encode(['success' => true]);
                break;

            case 'toggle_comment_like':
                requireAuth();
                $comment_id = (int)($_POST['comment_id'] ?? 0);
                $user_id = (int)$current_session['user_id'];
                $stmt = $pdo->prepare("SELECT id FROM comment_likes WHERE user_id = ? AND comment_id = ?");
                $stmt->execute([$user_id, $comment_id]);
                if ($stmt->fetch()) {
                    $pdo->prepare("DELETE FROM comment_likes WHERE user_id = ? AND comment_id = ?")->execute([$user_id, $comment_id]);
                    echo json_encode(['liked' => false]);
                } else {
                    $pdo->prepare("INSERT INTO comment_likes (user_id, comment_id) VALUES (?, ?)")->execute([$user_id, $comment_id]);
                    echo json_encode(['liked' => true]);
                }
                break;

            case 'update_profile':
                requireAuth();
                $bio = trim($_POST['bio'] ?? '');
                $avatar_url = trim($_POST['avatar_url'] ?? '');
                if ($avatar_url && (!filter_var($avatar_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $avatar_url))) { $avatar_url = ''; }

                if ($avatar_url) {
                    $stmt = $pdo->prepare("UPDATE users SET bio = ?, avatar_url = ? WHERE id = ?");
                    $stmt->execute([$bio, $avatar_url, $current_session['user_id']]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET bio = ? WHERE id = ?");
                    $stmt->execute([$bio, $current_session['user_id']]);
                }
                echo json_encode(['success' => true]);
                break;

            case 'update_account':
                requireAuth();
                $username = trim($_POST['username'] ?? '');
                $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

                if (!$username || !$email) throw new Exception('Имя пользователя и Email обязательны');
                
                $stmt = $pdo->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?");
                $stmt->execute([$email, $username, $current_session['user_id']]);
                if ($stmt->fetch()) throw new Exception('Email или Имя пользователя уже заняты');

                $stmt = $pdo->prepare("UPDATE users SET email = ?, username = ? WHERE id = ?");
                $stmt->execute([$email, $username, $current_session['user_id']]);
                echo json_encode(['success' => true]);
                break;

            case 'change_password':
                requireAuth();
                $curr_pass = $_POST['current_password'] ?? '';
                $new_pass = $_POST['new_password'] ?? '';
                $confirm_pass = $_POST['confirm_password'] ?? '';

                if (strlen($new_pass) < 6) throw new Exception('Новый пароль должен содержать минимум 6 символов');
                if ($new_pass !== $confirm_pass) throw new Exception('Пароли не совпадают');

                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$current_session['user_id']]);
                $user = $stmt->fetch();
                if (!password_verify($curr_pass, $user['password_hash'])) {
                    throw new Exception('Неверный текущий пароль');
                }

                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $current_session['user_id']]);
                echo json_encode(['success' => true]);
                break;

            case 'get_sessions':
                requireAuth();
                $stmt = $pdo->prepare("SELECT SHA2(token, 256) as id, ip_address, user_agent, created_at, (token = ?) as is_current FROM sessions WHERE user_id = ? ORDER BY created_at DESC");
                $stmt->execute([$current_session['token'], $current_session['user_id']]);
                $sessions = $stmt->fetchAll();
                
                foreach($sessions as &$s) {
                    $s['ip_address'] = htmlspecialchars($s['ip_address'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $s['user_agent'] = htmlspecialchars($s['user_agent'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                echo json_encode(['sessions' => $sessions]);
                break;

            case 'revoke_session':
                requireAuth();
                $sid = $_POST['id'] ?? '';
                $stmt = $pdo->prepare("DELETE FROM sessions WHERE user_id = ? AND SHA2(token, 256) = ? AND token != ?");
                $stmt->execute([$current_session['user_id'], $sid, $current_session['token']]);
                echo json_encode(['success' => true]);
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'API route not found']);
        }
    } catch (PDOException $e) {
        error_log("DB_ERROR: " . $e->getMessage()); 
        echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка базы данных']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// 4. SEO ОПТИМИЗАЦИЯ
// ==========================================
$req_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '\\/');
if ($base_path && strpos($req_path, $base_path) === 0) {
    $req_path = substr($req_path, strlen($base_path));
}
$req_path = trim($req_path, '/');
$path_parts = explode('/', $req_path);

$random_titles = ["Dump", "Настоящий Dump"];
$seo_title = $random_titles[array_rand($random_titles)];
$seo_desc = "Dump — это место, где ты можешь делиться фотографиями, мыслями и находить крутой контент от других людей.";
$seo_image = "https://ui-avatars.com/api/?name=D&background=000&color=fff&size=512";

try {
    if (isset($path_parts[0]) && $path_parts[0] === 'post' && !empty($path_parts[1])) {
        $slug = $path_parts[1];
        $stmt = $pdo->prepare("SELECT p.content, p.image_url, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.slug = ?");
        $stmt->execute([$slug]);
        if ($post = $stmt->fetch()) {
            $seo_title = "Публикация от @" . $post['username'] . " | Dump";
            $text_clean = trim(preg_replace('/\s+/', ' ', strip_tags($post['content'])));
            if ($text_clean) {
                $seo_desc = mb_substr($text_clean, 0, 150) . (mb_strlen($text_clean) > 150 ? '...' : '');
            }
            if (!empty($post['image_url'])) {
                $images = explode(',', $post['image_url']);
                $seo_image = trim($images[0]);
            }
        }
    } elseif (isset($path_parts[0]) && $path_parts[0] === 'profile' && !empty($path_parts[1])) {
        $uid = (int)$path_parts[1];
        $stmt = $pdo->prepare("SELECT username, bio, avatar_url FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        if ($user = $stmt->fetch()) {
            $seo_title = "@" . $user['username'] . " | Профиль Dump";
            $bio_clean = trim(preg_replace('/\s+/', ' ', strip_tags((string)$user['bio'])));
            $seo_desc = $bio_clean ? (mb_substr($bio_clean, 0, 150) . '...') : "Смотрите публикации пользователя @" . $user['username'] . " на Dump.";
            if (!empty($user['avatar_url'])) {
                $seo_image = trim($user['avatar_url']);
            }
        }
    }
} catch (Exception $e) {}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($seo_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>
    
    <meta name="description" content="<?= htmlspecialchars($seo_desc, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($seo_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seo_desc, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($seo_image, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='50' cy='50' r='50' fill='%23000000'/><text x='50' y='55' dominant-baseline='middle' text-anchor='middle' font-size='76' font-family='-apple-system, BlinkMacSystemFont, sans-serif' font-weight='800' fill='%23ffffff'>D</text></svg>">

    <link rel="stylesheet" type="text/css" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/fill/style.css">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    
    <style>
        :root {
            --bg: #000000;
            --surface: #0a0a0a;
            --surface-elevated: #111111;
            --surface-hover: #1a1a1a;
            --surface-active: #222222;
            --text-main: #ffffff;
            --text-muted: #808080;
            --accent: #ffffff;
            --accent-bg: #000000;
            --error: #ff2a5f;
            --warning: #f5a623;
            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 24px;
            --font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            --transition: 0.35s cubic-bezier(0.25, 1, 0.5, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg); color: var(--text-main); font-family: var(--font-family); -webkit-font-smoothing: antialiased; overflow: hidden; width: 100vw; height: 100vh; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: #333333; border-radius: 6px; }
        ::-webkit-scrollbar-thumb:hover { background: #555555; }

        a, button { cursor: pointer; border: none; background: none; font-family: inherit; transition: var(--transition); }
        input, textarea { font-family: inherit; transition: var(--transition); }
        
        .hidden { display: none !important; }
        .text-center { text-align: center; }
        .text-muted { color: var(--text-muted); }
        .w-full { width: 100%; }
        .h-full { height: 100%; }
        .flex { display: flex; }
        .flex-col { flex-direction: column; }
        .items-center { align-items: center; }
        .justify-center { justify-content: center; }
        .justify-between { justify-content: space-between; }
        .font-bold { font-weight: 700; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-4 { margin-bottom: 1rem; }
        .mb-6 { margin-bottom: 1.5rem; }
        .mb-8 { margin-bottom: 2rem; }
        .mt-2 { margin-top: 0.5rem; }
        .mt-4 { margin-top: 1rem; }
        .gap-2 { gap: 0.5rem; }
        .gap-3 { gap: 0.75rem; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slide-up-fade { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes heart-pop { 
            0% { transform: translate(-50%, -50%) scale(0); opacity: 0; } 
            25% { transform: translate(-50%, -50%) scale(1.3); opacity: 1; } 
            75% { transform: translate(-50%, -50%) scale(1); opacity: 1; } 
            100% { transform: translate(-50%, -50%) scale(1.5); opacity: 0; } 
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        @keyframes slide-down-fade { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        .smooth-fade-in { animation: fadeIn 0.4s cubic-bezier(0.25, 1, 0.5, 1) forwards; }
        .spin { animation: spin 1s linear infinite; }

        .dump-logo {
            font-family: var(--font-family);
            font-weight: 800;
            color: var(--text-main);
            display: inline-block;
            transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            user-select: none;
            letter-spacing: -1px;
        }
        .dump-logo:active { transform: scale(0.9); }

        .view-section {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; visibility: hidden; pointer-events: none;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            background-color: var(--bg); z-index: 1;
        }
        .view-section.active { opacity: 1; visibility: visible; pointer-events: auto; z-index: 5; }

        .input-group { position: relative; margin-bottom: 1.25rem; }
        .vc-input {
            width: 100%; background-color: var(--surface-elevated);
            border: 1px solid transparent; color: var(--text-main);
            padding: 24px 16px 8px 16px; border-radius: var(--radius-md);
            outline: none; font-size: 1rem;
        }
        .vc-input:focus { background-color: var(--surface-hover); }
        .vc-label {
            position: absolute; left: 16px; top: 16px;
            color: var(--text-muted); font-size: 1rem;
            pointer-events: none; transition: var(--transition);
        }
        .vc-input:focus ~ .vc-label, .vc-input:not(:placeholder-shown) ~ .vc-label {
            top: 6px; font-size: 0.75rem; font-weight: 500;
        }

        .vc-btn {
            background-color: var(--accent); color: var(--accent-bg);
            font-weight: 600; padding: 16px 24px; border-radius: var(--radius-md);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1rem; width: 100%; gap: 8px; cursor: pointer; border: 1px solid transparent;
        }
        .vc-btn:active { transform: scale(0.98); }
        .vc-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .vc-btn-outline { background-color: transparent; color: var(--text-main); border: 1px solid rgba(255,255,255,0.15); border-radius: var(--radius-md); padding: 16px 24px; font-weight: 600; text-align: center; cursor: pointer;}
        .vc-btn-outline:hover { background-color: var(--surface-elevated); border-color: transparent; }
        .vc-btn-text { color: var(--text-muted); font-size: 0.9rem; margin-top: 1rem; }
        .vc-btn-text:hover { color: var(--text-main); }

        .nav-bar {
            position: fixed; top: 0; left: 0; width: 100%; padding: 1.25rem 1.5rem;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 50; background: linear-gradient(to bottom, rgba(0,0,0,0.8) 0%, transparent 100%);
            pointer-events: none; opacity: 0; visibility: hidden; transition: opacity 0.3s ease;
        }
        .nav-bar.visible { opacity: 1; visibility: visible; pointer-events: auto; }
        .nav-brand { font-size: 2.2rem; cursor: pointer; }
        
        .nav-tabs { position: relative; display: flex; background: var(--surface-elevated); border-radius: 99px; padding: 4px; position: absolute; left: 50%; transform: translateX(-50%); box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
        .tab-indicator { position: absolute; top: 4px; bottom: 4px; left: 4px; background: var(--surface-active); border-radius: 99px; transition: transform 0.3s cubic-bezier(0.25, 1, 0.5, 1), width 0.3s cubic-bezier(0.25, 1, 0.5, 1); z-index: 0; pointer-events: none; }
        .nav-tab { padding: 6px 20px; border-radius: 99px; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); transition: color 0.2s ease; position: relative; z-index: 1; }
        .nav-tab.active { color: var(--text-main); }

        .icon-btn {
            width: 44px; height: 44px; border-radius: 50%; background-color: var(--surface-elevated);
            color: var(--text-main); display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; box-shadow: 0 4px 15px rgba(0,0,0,0.5);
        }
        .icon-btn:active { transform: scale(0.92); }

        .feed-container { position: relative; overflow: hidden; width: 100vw; height: 100vh; touch-action: pan-y; }
        .feed-wrapper { display: flex; flex-direction: row; height: 100%; width: max-content; transition: transform 0.45s cubic-bezier(0.25, 1, 0.5, 1); will-change: transform; transform: translateZ(0); }
        .post-card { flex: 0 0 100vw; width: 100vw; height: 100vh; display: flex; justify-content: center; align-items: center; position: relative; }
        
        .post-wrapper { width: 100%; max-width: 450px; height: 82vh; max-height: 850px; position: relative; background-color: var(--surface); border-radius: var(--radius-lg); overflow: hidden; display: flex; align-items: center; justify-content: center; flex-direction: column; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; transform: translateZ(0); }
        
        .post-overlay-bottom { position: absolute; bottom: 0; left: 0; width: 100%; padding: 4rem 1.5rem 1.5rem; background: linear-gradient(to top, rgba(0,0,0,0.95) 0%, rgba(0,0,0,0.6) 60%, transparent 100%); color: white; font-size: 1.05rem; line-height: 1.4; pointer-events: none; word-break: break-word; z-index: 20; }
        .post-overlay-bottom a, .post-overlay-bottom .hashtag { pointer-events: auto; } 
        
        .scrollable-overlay { max-height: 45vh; overflow-y: auto; pointer-events: auto; overscroll-behavior: contain; }
        .post-text-content { pointer-events: auto; }
        
        .hashtag { color: #ffffff; font-weight: 700; cursor: pointer; transition: 0.2s; position: relative; z-index: 50; }
        .hashtag:hover { color: #e0e0e0; text-shadow: 0 0 10px rgba(255, 255, 255, 0.5); }

        .image-slider { display: flex; width: 100%; height: 100%; position: relative; z-index: 10; transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1); pointer-events: none; transform: translateZ(0); }
        .slider-img { flex: 0 0 100%; width: 100%; height: 100%; object-fit: contain; pointer-events: none; }
        .slider-dots { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 30; pointer-events: none; }
        
        .slider-dot { width: 8px; height: 8px; border-radius: 4px; background: rgba(255,255,255,0.3); transition: width 0.3s, background-color 0.3s; position: relative; overflow: hidden; }
        .slider-dot.active { width: 32px; background: rgba(255,255,255,0.2); }
        .slider-dot.active::after {
            content: '';
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 0%;
            background: #ffffff;
            animation: dot-progress 2s linear forwards;
        }
        .slider-dot.active.paused::after {
            animation-play-state: paused !important;
        }
        @keyframes dot-progress {
            0% { width: 0%; }
            100% { width: 100%; }
        }

        .post-author { position: absolute; top: 1.5rem; left: 1.5rem; display: flex; align-items: center; gap: 0.5rem; background: rgba(0,0,0,0.65); padding: 4px 12px 4px 4px; border-radius: 99px; cursor: pointer; z-index: 30; max-width: 60%; }
        .post-author img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
        .author-name { font-size: 0.85rem; font-weight: 600; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .post-time { font-size: 0.75rem; color: rgba(255,255,255,0.6); margin-left: 2px; white-space: nowrap; }

        .post-actions { position: absolute; right: 1.5rem; bottom: 2rem; display: flex; flex-direction: column; gap: 1.2rem; z-index: 30; }
        .action-btn { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; color: white; cursor: pointer; text-shadow: 0 2px 4px rgba(0,0,0,0.5); }
        .action-btn .icon-bg { background: rgba(0,0,0,0.65); padding: 12px; border-radius: 50%; font-size: 1.6rem; transition: var(--transition); }
        .action-btn:active .icon-bg { transform: scale(0.9); }
        .action-btn span { font-size: 0.85rem; font-weight: 700; }

        .double-tap-heart { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0); color: var(--error); font-size: 6rem; filter: drop-shadow(0 0 30px rgba(255,42,95,0.6)); z-index: 25; pointer-events: none; }
        .double-tap-heart.animating { animation: heart-pop 0.9s cubic-bezier(0.175, 0.885, 0.32, 1.275); }

        .floating-comment { position: absolute; bottom: -50px; display: flex; align-items: center; gap: 10px; background: rgba(20, 20, 20, 0.95); padding: 6px 14px 6px 6px; border-radius: 99px; font-size: 0.9rem; pointer-events: none; z-index: 25; animation: floatUp 4.5s linear forwards; max-width: 80%; }
        .floating-comment img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; }
        .fc-text { display: flex; flex-direction: column; justify-content: center; line-height: 1.2; overflow: hidden; }
        .fc-name { font-weight: 700; color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fc-msg { color: white; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        @keyframes floatUp { 0% { transform: translateY(0); opacity: 0; } 10% { opacity: 1; } 90% { opacity: 1; } 100% { transform: translateY(-300px); opacity: 0; } }

        .profile-container { padding: 6rem 1rem 2rem; max-width: 600px; margin: 0 auto; left: 0; right: 0; height: 100vh; overflow-y: auto; }
        .profile-header { display: flex; align-items: center; gap: 2rem; margin-bottom: 1.5rem; }
        .profile-avatar { width: 90px; height: 90px; border-radius: 50%; object-fit: cover; background: var(--surface-elevated); }
        .profile-stats { display: flex; gap: 2rem; margin-top: 1.2rem; }
        .stat-item { display: flex; flex-direction: column; align-items: center; }
        .stat-val { font-size: 1.4rem; font-weight: 800; }
        .stat-lbl { font-size: 0.75rem; color: var(--text-muted); font-weight: 500; }
        
        .profile-tabs-wrapper { padding: 0 0.5rem; margin-bottom: 1.5rem; margin-top: 1.5rem; }
        .profile-tabs { position: relative; display: flex; background: var(--surface-elevated); border-radius: 14px; padding: 4px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.2); }
        .profile-tab-indicator { position: absolute; top: 4px; bottom: 4px; left: 4px; width: calc(50% - 4px); background: var(--surface-active); border-radius: 10px; transition: transform 0.3s cubic-bezier(0.25, 1, 0.5, 1); z-index: 0; }
        .profile-tab { flex: 1; padding: 12px 0; font-size: 0.95rem; font-weight: 600; color: var(--text-muted); position: relative; z-index: 1; display: flex; justify-content: center; align-items: center; gap: 6px; transition: color 0.3s; }
        .profile-tab.active { color: var(--text-main); }

        .profile-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2px; }
        .grid-item { aspect-ratio: 1/1; background-color: var(--surface-elevated); overflow: hidden; display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; }
        .grid-item img { width: 100%; height: 100%; object-fit: cover; opacity: 0; transition: opacity 0.3s; }
        .grid-item img.loaded { opacity: 1; }
        .grid-item .text-preview { font-size: 0.75rem; padding: 0.5rem; text-align: center; color: var(--text-muted); word-break: break-word; line-height: 1.3; overflow-y: hidden; }
        .multi-img-icon { position: absolute; top: 6px; right: 6px; color: white; background: rgba(0,0,0,0.5); border-radius: 4px; padding: 2px; font-size: 0.8rem; z-index: 10;}

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.85); display: flex; align-items: center; justify-content: center; z-index: 100; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal-overlay.open { opacity: 1; visibility: visible; }
        .modal-bottom { align-items: flex-end; }
        
        .modal-content { background: var(--bg); width: 100%; max-width: 450px; border-radius: var(--radius-lg); padding: 1.5rem; position: relative; transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.25, 1, 0.5, 1); }
        .modal-overlay.open .modal-content { transform: scale(1); }
        
        .modal-bottom .modal-content { background: var(--surface); border-radius: 24px 24px 0 0; height: 80vh; max-height: 800px; display: flex; flex-direction: column; padding: 0; transform: translateY(100%); max-width: 600px; }
        .modal-overlay.open.modal-bottom .modal-content { transform: translateY(0); }

        .settings-tabs { display: flex; border-bottom: 1px solid var(--surface-hover); margin-bottom: 1.5rem; overflow-x: auto; scrollbar-width: none; }
        .settings-tabs::-webkit-scrollbar { display: none; }
        .settings-tab { padding: 12px 20px; font-weight: 600; color: var(--text-muted); position: relative; white-space: nowrap; }
        .settings-tab.active { color: var(--text-main); }
        .settings-tab.active::after { content: ''; position: absolute; bottom: -1px; left: 0; width: 100%; height: 2px; background: var(--accent); border-radius: 2px; }

        .session-item { background: var(--surface-elevated); padding: 12px 16px; border-radius: var(--radius-md); display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; }
        
        .comments-list { flex: 1; overflow-y: auto; padding: 1.5rem; display: flex; flex-direction: column; gap: 1.2rem; }
        .comment-item { display: flex; gap: 0.8rem; animation: slide-up-fade 0.3s ease forwards; position: relative; }
        .comment-item.is-reply { margin-left: 2.5rem; position: relative; }
        .comment-item.is-reply::before { content:''; position:absolute; left:-20px; top:0; width:2px; height:100%; background:var(--surface-hover); border-radius:2px;}
        .comment-item > img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; cursor: pointer; flex-shrink: 0; align-self: flex-start; margin-top: 2px; }
        .comment-content { flex: 1; background: var(--surface-elevated); padding: 12px 16px; border-radius: 4px 16px 16px 16px; display: flex; flex-direction: column; }
        .comment-header { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.4rem; }
        .comment-author { font-weight: 700; font-size: 0.9rem; cursor: pointer; }
        .comment-time { font-size: 0.7rem; color: var(--text-muted); margin-left: 4px; }
        .comment-text { font-size: 0.95rem; line-height: 1.4; color: var(--text-main); word-break: break-word; }
        .comment-delete { color: var(--text-muted); cursor: pointer; transition: color 0.2s; font-size: 1.1rem; }
        .comment-delete:hover { color: var(--error); }
        
        .comment-actions { display: flex; align-items: center; gap: 1.2rem; margin-top: 0.8rem; }
        .c-action-btn { 
            display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; 
            font-weight: 600; color: var(--text-muted); cursor: pointer; 
            transition: var(--transition); padding: 4px 10px; 
            border-radius: 99px; margin-left: -8px; background: transparent; 
        }
        .c-action-btn:hover { background: var(--surface-hover); color: var(--text-main); }
        .c-action-btn:active { transform: scale(0.95); }
        .c-action-btn i { font-size: 1.1rem; }
        
        .comment-input-area { padding: 1rem; border-top: 1px solid var(--surface-hover); display: flex; align-items: stretch; background: var(--surface); flex-direction: column; }
        .comment-input-wrapper { display: flex; gap: 0.5rem; align-items: flex-end; width: 100%; }
        .comment-input-wrapper textarea { flex: 1; background: var(--surface-elevated); border: none; color: white; outline: none; font-size: 0.95rem; padding: 12px 16px; border-radius: 20px; resize: none; max-height: 120px; min-height: 44px; overflow-y: hidden; }
        
        #replyingToIndicator { animation: slide-down-fade 0.2s ease forwards; }

        #toastContainer { position: fixed; top: 1.5rem; left: 50%; transform: translateX(-50%); z-index: 99999; display: flex; flex-direction: column; gap: 0.5rem; pointer-events: none; align-items: center; }
        .toast { background: var(--text-main); color: var(--bg); padding: 12px 24px; border-radius: 99px; font-weight: 600; font-size: 0.9rem; animation: toast-in 0.3s cubic-bezier(0.25, 1, 0.5, 1) forwards; box-shadow: 0 4px 20px rgba(0,0,0,0.4); text-align: center; max-width: 90vw; }
        .toast.fade-out { animation: toast-out 0.3s forwards; }
        @keyframes toast-in { from { opacity: 0; transform: translateY(-20px) scale(0.9); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes toast-out { to { opacity: 0; transform: translateY(-10px) scale(0.9); } }

        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; height: 100%; color: var(--text-muted); text-align: center; padding: 2rem; flex-grow: 1; }
        .empty-state i { font-size: 3.5rem; margin-bottom: 1rem; opacity: 0.4; }

        .auth-container { display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .auth-card { width: 100%; max-width: 380px; margin: auto; }
        
        .search-result-item { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: var(--radius-md); transition: var(--transition); cursor: pointer; }
        .search-result-item:hover { background: var(--surface-elevated); }
        .search-result-img { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
        .search-result-post { border-radius: 8px; width: 44px; height: 44px; }
        .search-section-title { font-size: 0.85rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin: 1rem 0 0.5rem 0.5rem; letter-spacing: 0.5px; }

        .loader-screen { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; flex: 1; min-height: 200px; }
        
        .big-preview { position: relative; width: 100%; height: 350px; border-radius: var(--radius-md); overflow: hidden; margin-bottom: 1rem; background: var(--surface-elevated); }
        .big-preview img { width: 100%; height: 100%; object-fit: cover; }
        .big-preview .overlay-btn { position: absolute; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s; cursor: pointer; }
        .big-preview:hover .overlay-btn { opacity: 1; }
        .big-preview .remove-btn { position: absolute; top: 12px; right: 12px; background: rgba(255,42,95,0.8); color: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; z-index: 10; cursor: pointer; font-size: 1.2rem; transition: var(--transition); }
        .big-preview .remove-btn:hover { background: rgba(255,42,95,1); transform: scale(1.1); }
        
        .preview-grid { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 8px; scrollbar-width: thin; margin-bottom: 1rem; }
        .preview-grid::-webkit-scrollbar { height: 4px; }
        .preview-item { position: relative; width: 80px; height: 80px; flex-shrink: 0; border-radius: 8px; overflow: hidden; }
        .preview-item img { width: 100%; height: 100%; object-fit: cover; }
        .preview-item .remove-btn { position: absolute; top: 4px; right: 4px; background: rgba(0,0,0,0.7); color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; cursor: pointer; }
        .add-more-grid-item { border: 2px dashed var(--surface-hover); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--text-muted); cursor: pointer; border-radius: 8px; flex-shrink: 0; width: 80px; height: 80px; transition: var(--transition); }
        .add-more-grid-item:hover { background: var(--surface-hover); color: white; border-color: transparent; }

        /* TOGGLE SWITCH CSS */
        .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--surface-hover); transition: .4s; border-radius: 24px; }
        .toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: var(--text-muted); transition: .4s cubic-bezier(0.25, 1, 0.5, 1); border-radius: 50%; }
        .toggle-switch input:checked + .toggle-slider { background-color: var(--text-main); }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); background-color: var(--bg); }
        .toggle-switch input:disabled + .toggle-slider { opacity: 0.5; cursor: not-allowed; }

        /* RADIO BUTTONS CSS */
        .radio-group { display: flex; gap: 1rem; margin-bottom: 1rem; }
        .radio-label { flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px; background: var(--surface-elevated); border: 2px solid transparent; border-radius: var(--radius-md); cursor: pointer; font-weight: 600; transition: var(--transition); }
        .radio-label input { display: none; }
        .radio-label input:checked + span { color: var(--accent); }
        .radio-label:has(input:checked) { border-color: var(--accent); background: rgba(255,255,255,0.05); }

    </style>
</head>
<body>
    <script>
        const BASE_PATH = '<?php echo rtrim(dirname($_SERVER["SCRIPT_NAME"]), "\\/"); ?>';
        const apiCall = (action) => BASE_PATH + '/index.php?api=' + action;
        const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB
        
        const getProxyUrl = (url) => {
            if (!url) return '';
            if (url.includes('/index.php?api=proxy')) return url;
            try { return BASE_PATH + '/index.php?api=proxy&url=' + btoa(url); } 
            catch(e) { return url; }
        };

        function fireConfetti() {
            const colors = ['#ff2a5f', '#f5a623', '#ffffff', '#60a5fa', '#34d399'];
            const canvas = document.createElement('canvas');
            canvas.style.position = 'fixed';
            canvas.style.top = '0'; canvas.style.left = '0';
            canvas.style.width = '100vw'; canvas.style.height = '100vh';
            canvas.style.pointerEvents = 'none'; canvas.style.zIndex = '999999';
            document.body.appendChild(canvas);
            const ctx = canvas.getContext('2d');
            let w = window.innerWidth, h = window.innerHeight;
            canvas.width = w; canvas.height = h;
            const pieces = [];
            for(let i=0; i<80; i++) {
                pieces.push({
                    x: w / 2, y: h / 2,
                    vx: (Math.random() - 0.5) * (w / 25), vy: (Math.random() - 1) * (h / 35) - 5,
                    size: Math.random() * 10 + 6, color: colors[Math.floor(Math.random() * colors.length)],
                    rot: Math.random() * 360, rotSpeed: (Math.random() - 0.5) * 10
                });
            }
            function animate() {
                ctx.clearRect(0, 0, w, h);
                let active = false;
                pieces.forEach(p => {
                    p.x += p.vx; p.y += p.vy; p.vy += 0.4; p.rot += p.rotSpeed;
                    if(p.y < h + 50) active = true;
                    ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot * Math.PI / 180);
                    ctx.fillStyle = p.color; ctx.fillRect(-p.size/2, -p.size/2, p.size, p.size); ctx.restore();
                });
                if(active) requestAnimationFrame(animate); else canvas.remove();
            }
            animate();
        }
    </script>

    <div id="toastContainer"></div>

    <div id="mainNav" class="nav-bar">
        <div class="flex items-center gap-2">
            <button id="navBackBtn" class="icon-btn hidden" onclick="window.history.back()"><i class="ph ph-arrow-left"></i></button>
            <h1 class="nav-brand dump-logo" id="navLogo" onclick="goHome()">Dump</h1>
        </div>
        
        <div class="nav-tabs hidden" id="feedTabs">
            <div id="tabIndicator" class="tab-indicator"></div>
            <button onclick="setFeedType('all')" id="tab-all" class="nav-tab active">Глобально</button>
            <button onclick="setFeedType('following')" id="tab-following" class="nav-tab">Подписки</button>
        </div>
        <div class="flex gap-2">
            <button onclick="openSearch()" class="icon-btn"><i class="ph ph-magnifying-glass"></i></button>
            <button onclick="navigate('/create')" id="navCreateBtn" class="icon-btn"><i class="ph ph-plus"></i></button>
            <button id="navUserBtn" class="icon-btn"><i class="ph ph-user"></i></button>
        </div>
    </div>

    <div id="loginView" class="view-section auth-container flex items-center justify-center">
        <div class="auth-card">
            <div class="text-center mb-8">
                <h2 class="dump-logo" style="font-size: 4.2rem;">Dump</h2>
                <p class="text-muted mt-2">Войдите в свой аккаунт</p>
            </div>
            <form onsubmit="handleAuth(event, 'login')" novalidate>
                <div class="input-group">
                    <input type="email" name="email" id="loginEmail" class="vc-input" placeholder=" " required autocomplete="username">
                    <label for="loginEmail" class="vc-label">Email</label>
                </div>
                <div class="input-group">
                    <input type="password" name="password" id="loginPassword" class="vc-input" placeholder=" " required autocomplete="current-password">
                    <label for="loginPassword" class="vc-label">Пароль</label>
                </div>
                <button type="submit" class="vc-btn mb-4">Войти</button>
                <div class="text-center">
                    <button type="button" class="vc-btn-text" onclick="navigate('/register')">У меня еще нет аккаунта</button>
                </div>
            </form>
        </div>
    </div>

    <div id="registerView" class="view-section auth-container flex items-center justify-center">
        <div class="auth-card">
            <div class="text-center mb-8">
                <h2 class="dump-logo" style="font-size: 4.2rem;">Dump</h2>
                <p class="text-muted mt-2">Присоединяйтесь к Dump</p>
            </div>
            <form onsubmit="handleAuth(event, 'register')" novalidate>
                <div class="input-group">
                    <input type="email" name="email" id="regEmail" class="vc-input" placeholder=" " required autocomplete="username">
                    <label for="regEmail" class="vc-label">Ваш Email</label>
                </div>
                <div class="input-group">
                    <input type="password" name="password" id="regPassword" class="vc-input" placeholder=" " required minlength="6" autocomplete="new-password">
                    <label for="regPassword" class="vc-label">Пароль</label>
                </div>
                <button type="submit" class="vc-btn mb-4">Создать аккаунт</button>
                <div class="text-center">
                    <button type="button" class="vc-btn-text" onclick="navigate('/login')">Я уже зарегистрирован</button>
                </div>
            </form>
        </div>
    </div>

    <div id="feedView" class="view-section feed-container"></div>
    <div id="profileView" class="view-section profile-container"></div>

    <div id="postOptionsModal" class="modal-overlay modal-bottom" onclick="closeModalOnOutsideClick(event, 'postOptionsModal')">
        <div class="modal-content" style="padding-bottom: 2rem; max-height: auto; height: auto;">
            <div class="flex justify-center mb-6"><div style="width:40px; height:5px; background:var(--surface-hover); border-radius:4px;"></div></div>
            <div class="flex flex-col gap-3">
                <button class="vc-btn-outline flex items-center justify-start gap-3" style="border:none; background:var(--surface-elevated); padding: 18px 24px;" id="poBookmarkBtn" onclick="doBookmarkFromOptions()">
                    <i class="ph ph-bookmark" style="font-size:1.5rem;"></i> <span id="poBookmarkText" style="font-size:1.05rem;">Сохранить</span>
                </button>
                <button class="vc-btn-outline flex items-center justify-start gap-3" style="border:none; background:var(--surface-elevated); padding: 18px 24px;" onclick="doShareFromOptions()">
                    <i class="ph ph-share-network" style="font-size:1.5rem;"></i> <span style="font-size:1.05rem;">Поделиться</span>
                </button>
                <button id="poDeleteBtn" class="vc-btn-outline flex items-center justify-start gap-3 hidden" style="border:none; background:rgba(255,42,95,0.1); color:var(--error); padding: 18px 24px; margin-top: 10px;" onclick="doDeleteFromOptions()">
                    <i class="ph ph-trash" style="font-size:1.5rem;"></i> <span style="font-size:1.05rem;">Удалить пост</span>
                </button>
            </div>
        </div>
    </div>

    <div id="searchModal" class="modal-overlay modal-bottom" onclick="closeModalOnOutsideClick(event, 'searchModal')">
        <div class="modal-content" style="max-height: 85vh;">
            <div class="flex justify-between items-center" style="padding:1.25rem 1.5rem; border-bottom:1px solid var(--surface-hover);">
                <h3 class="font-bold" style="font-size:1.1rem;">Поиск</h3>
                <button type="button" onclick="closeModal('searchModal')" style="color:var(--text-muted);"><i class="ph ph-caret-down" style="font-size:1.4rem;"></i></button>
            </div>
            <div class="p-4 border-b border-surface-hover">
                <div class="input-group mb-0">
                    <input type="text" id="searchInput" class="vc-input" placeholder=" " oninput="debounceSearch()">
                    <label for="searchInput" class="vc-label">Найти посты или людей...</label>
                </div>
            </div>
            <div id="searchResults" class="overflow-y-auto" style="flex:1; padding: 0.5rem 1rem 1.5rem;">
                <div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Что будем искать?</p></div>
            </div>
        </div>
    </div>

    <div id="settingsModal" class="modal-overlay" onclick="closeModalOnOutsideClick(event, 'settingsModal')">
        <div class="modal-content" style="max-width: 500px; max-height: 90vh; overflow-y: auto; display: flex; flex-direction: column;">
            <div class="flex justify-between items-center mb-4">
                <h2 class="font-bold" style="font-size:1.5rem;">Настройки</h2>
                <button type="button" onclick="closeModal('settingsModal')" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.4rem;"></i></button>
            </div>
            
            <div class="settings-tabs">
                <button class="settings-tab active" id="tabBtnProfile" onclick="switchSettingsTab('profile')">Профиль</button>
                <button class="settings-tab" id="tabBtnAccount" onclick="switchSettingsTab('account')">Аккаунт</button>
                <button class="settings-tab" id="tabBtnSessions" onclick="switchSettingsTab('sessions')">Сессии</button>
            </div>

            <div id="paneProfile" class="block smooth-fade-in">
                <form onsubmit="saveProfile(event)" novalidate>
                    <div class="flex justify-center mb-6">
                        <div style="position:relative; width:100px; height:100px; cursor:pointer;" onclick="document.getElementById('settingsAvatarFile').click()">
                            <img id="settingsAvatarPreview" src="" class="w-full h-full rounded-full object-cover" style="background:var(--surface-elevated);">
                            <div class="absolute inset-0 flex items-center justify-center rounded-full" style="background:rgba(0,0,0,0.5); top:0; left:0; width:100%; height:100%; position:absolute;"><i class="ph ph-camera text-white" style="font-size:1.5rem;"></i></div>
                        </div>
                        <input type="file" id="settingsAvatarFile" class="hidden" accept="image/png, image/jpeg, image/webp" onchange="initCrop(event)">
                    </div>
                    <div class="input-group">
                        <textarea id="settingsBio" class="vc-input" placeholder=" " style="height: 100px; resize:none; padding-top:24px;"></textarea>
                        <label class="vc-label">О себе</label>
                    </div>
                    <button type="submit" class="vc-btn mb-4">Обновить профиль</button>
                </form>
            </div>

            <div id="paneAccount" class="hidden smooth-fade-in">
                <form onsubmit="saveAccount(event)" novalidate>
                    <div class="input-group">
                        <input type="text" id="accUsername" class="vc-input" placeholder=" " required data-name="Имя пользователя">
                        <label class="vc-label">Имя пользователя</label>
                    </div>
                    <div class="input-group">
                        <input type="email" id="accEmail" class="vc-input" placeholder=" " required data-name="Email">
                        <label class="vc-label">Email</label>
                    </div>
                    
                    <div style="border-top: 1px solid var(--surface-hover); border-bottom: 1px solid var(--surface-hover); margin: 1rem 0 1.5rem; padding: 1rem 0; display:flex; flex-direction:column; gap:0.5rem;">
                        <button type="button" class="vc-btn-outline w-full flex justify-between items-center" style="border: none; padding: 0.5rem; font-weight: 500;" onclick="openPasswordModal()">
                            <span class="flex items-center gap-2"><i class="ph ph-lock-key" style="font-size:1.2rem;"></i> Изменить пароль</span> 
                            <i class="ph ph-caret-right text-muted"></i>
                        </button>
                        <button type="button" class="vc-btn-outline w-full flex justify-between items-center" style="border: none; padding: 0.5rem; font-weight: 500;" onclick="openTfaSettingsModal()">
                            <span class="flex items-center gap-2"><i class="ph ph-shield-check" style="font-size:1.2rem;"></i> Настройка 2FA</span> 
                            <div class="flex items-center gap-2">
                                <span id="tfaStatusBadge" style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; background: var(--surface-hover); color: var(--text-muted);">Выкл</span>
                                <i class="ph ph-caret-right text-muted"></i>
                            </div>
                        </button>
                    </div>

                    <button type="submit" class="vc-btn mb-2">Сохранить изменения</button>
                </form>
            </div>

            <div id="paneSessions" class="hidden smooth-fade-in">
                <div id="sessionsList" class="flex flex-col mb-4" style="min-height: 150px; position: relative;">
                    <div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>
                </div>
                <button type="button" onclick="showConfirm('Выход', 'Точно выйти из текущего аккаунта?', logout)" class="vc-btn vc-btn-outline w-full" style="color:var(--error); border-color:rgba(255,42,95,0.3);">Выйти из текущего аккаунта</button>
            </div>
        </div>
    </div>

    <div id="passwordModal" class="modal-overlay" style="z-index: 110;" onclick="closeModalOnOutsideClick(event, 'passwordModal')">
        <div class="modal-content" style="max-width: 400px;">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold" style="font-size:1.3rem;">Смена пароля</h3>
                <button type="button" onclick="closeModal('passwordModal')" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.4rem;"></i></button>
            </div>
            <form onsubmit="changePassword(event)" novalidate>
                <div class="input-group mb-4">
                    <input type="password" id="chPassCurrent" class="vc-input" placeholder=" " required data-name="Текущий пароль">
                    <label class="vc-label">Текущий пароль</label>
                </div>
                <div class="input-group mb-4">
                    <input type="password" id="chPassNew" class="vc-input" placeholder=" " required minlength="6" data-name="Новый пароль">
                    <label class="vc-label">Новый пароль</label>
                </div>
                <div class="input-group mb-6">
                    <input type="password" id="chPassConfirm" class="vc-input" placeholder=" " required minlength="6" data-name="Повторите пароль">
                    <label class="vc-label">Повторите новый пароль</label>
                </div>
                <button type="submit" class="vc-btn">Сохранить пароль</button>
            </form>
        </div>
    </div>

    <!-- 2FA НАСТРОЙКИ (SETUP) -->
    <div id="tfaSettingsModal" class="modal-overlay" style="z-index: 110;" onclick="closeModalOnOutsideClick(event, 'tfaSettingsModal')">
        <div class="modal-content" style="max-width: 450px;">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold flex items-center gap-2" style="font-size:1.3rem;"><i class="ph-fill ph-shield-check"></i> Двухфакторная аутентификация</h3>
                <button type="button" onclick="closeModal('tfaSettingsModal')" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.4rem;"></i></button>
            </div>
            
            <div class="flex justify-between items-center p-4 mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                <div>
                    <div class="font-bold mb-1">Использовать 2FA</div>
                    <div class="text-xs text-muted">Дополнительная защита аккаунта</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="tfaToggle" onchange="handleTfaToggleChange()">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div id="tfaSetupContainer" class="hidden smooth-fade-in">
                <div class="radio-group">
                    <label class="radio-label">
                        <input type="radio" name="tfaMethod" value="email" checked onchange="changeTfaMethodPreview()">
                        <span>Email код</span>
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="tfaMethod" value="app" onchange="changeTfaMethodPreview()">
                        <span>Приложение</span>
                    </label>
                </div>
                
                <div id="tfaPreviewEmail" class="text-center p-4 mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                    <i class="ph ph-envelope-simple text-muted mb-2" style="font-size:2.5rem;"></i>
                    <p class="text-sm text-muted">Мы будем отправлять 6-значный код на ваш email при каждом входе в аккаунт.</p>
                </div>

                <div id="tfaPreviewApp" class="hidden text-center p-4 mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                    <i class="ph ph-device-mobile text-muted mb-2" style="font-size:2.5rem;"></i>
                    <p class="text-sm text-muted">Используйте Google Authenticator или подобное приложение для генерации кодов без интернета.</p>
                </div>

                <button id="tfaStartSetupBtn" class="vc-btn" onclick="startTfaSetup()">Продолжить</button>
            </div>
            
            <div id="tfaVerifyContainer" class="hidden smooth-fade-in">
                <div id="tfaAppQrContainer" class="hidden flex flex-col items-center mb-4 p-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                    <p class="text-sm text-center mb-3">Отсканируйте этот QR-код в приложении аутентификаторе:</p>
                    <div class="bg-white p-2 rounded-lg mb-3 inline-block">
                        <img id="tfaQrImage" src="" style="width: 150px; height: 150px; display: block;">
                    </div>
                    <div class="text-xs text-muted">Или введите ключ вручную:</div>
                    <div id="tfaSecretKey" class="font-bold mt-1 tracking-widest text-accent" style="user-select: all;"></div>
                </div>

                <div id="tfaEmailSentMessage" class="hidden text-center p-4 mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                    <i class="ph-fill ph-envelope-open text-accent mb-2" style="font-size:2.5rem;"></i>
                    <p class="text-sm">Мы отправили письмо с кодом подтверждения на ваш Email. Пожалуйста, проверьте почту.</p>
                </div>

                <div class="input-group mb-4">
                    <input type="text" id="tfaSetupCode" class="vc-input text-center font-bold tracking-widest" placeholder=" " required maxlength="6" style="font-size: 1.5rem; letter-spacing: 10px;">
                    <label class="vc-label" style="text-align: center; width: 100%; left: 0;">Введите 6-значный код</label>
                </div>

                <button id="tfaConfirmSetupBtn" class="vc-btn" onclick="confirmTfaSetup()">Подтвердить и Включить</button>
            </div>
        </div>
    </div>

    <!-- 2FA ЛОГИН (ВХОД) -->
    <div id="tfaLoginModal" class="modal-overlay" style="z-index: 200; background: var(--bg);" onclick="closeModalOnOutsideClick(event, 'tfaLoginModal')">
        <div class="modal-content" style="max-width: 400px; text-align: center; background: transparent; padding: 2rem;">
            <i id="tfaLoginIcon" class="ph ph-shield-check mb-4" style="font-size: 4rem; color: var(--accent);"></i>
            <h2 class="font-bold mb-2" style="font-size: 1.5rem;">Подтверждение входа</h2>
            <p id="tfaLoginDesc" class="text-muted mb-6 text-sm">Введите код из приложения.</p>

            <form onsubmit="verifyTfaLogin(event)" novalidate>
                <div class="input-group mb-6">
                    <input type="text" id="tfaLoginCode" class="vc-input text-center font-bold" placeholder=" " required maxlength="6" style="font-size: 1.5rem; letter-spacing: 10px; background: var(--surface-elevated);">
                    <label class="vc-label" style="text-align: center; width: 100%; left: 0;">6-значный код</label>
                </div>
                <button type="submit" id="tfaLoginBtn" class="vc-btn w-full">Войти</button>
                <button type="button" class="vc-btn-text w-full mt-4" onclick="cancelTfaLogin()">Отмена</button>
            </form>
        </div>
    </div>

    <!-- ОСТАЛЬНЫЕ МОДАЛКИ (Без изменений) -->
    <div id="cropModal" class="modal-overlay" style="z-index: 200;">
        <div class="modal-content">
            <h3 class="font-bold mb-4" style="font-size:1.2rem;">Обрезать аватар</h3>
            <div style="width: 100%; height: 300px; background: #000; margin-bottom: 1rem;">
                <img id="cropImage" src="">
            </div>
            <div class="flex gap-2">
                <button class="vc-btn-outline flex-1" onclick="cancelCrop()">Отмена</button>
                <button class="vc-btn flex-1" id="cropBtn" onclick="doCrop()">Применить</button>
            </div>
        </div>
    </div>

    <div id="confirmModal" class="modal-overlay" style="z-index: 9999;">
        <div class="modal-content text-center smooth-fade-in" style="max-width: 320px; padding: 2rem 1.5rem;">
            <div style="margin-bottom: 1rem; color: var(--error);"><i class="ph ph-warning-circle" style="font-size: 3rem;"></i></div>
            <h3 class="font-bold mb-2" id="confirmTitle" style="font-size: 1.2rem;">Подтверждение</h3>
            <p class="text-muted mb-6" id="confirmText" style="line-height: 1.4;">Вы уверены?</p>
            <div class="flex gap-3">
                <button class="vc-btn-outline flex-1" style="padding: 12px; border-color: rgba(255,255,255,0.1);" onclick="closeModal('confirmModal')">Отмена</button>
                <button class="vc-btn flex-1" id="confirmActionBtn" style="padding: 12px; background: var(--error); color: white;">Да</button>
            </div>
        </div>
    </div>

    <div id="textWarningModal" class="modal-overlay" style="z-index: 9999;">
        <div class="modal-content text-center smooth-fade-in" style="max-width: 340px; padding: 2rem 1.5rem;">
            <div style="margin-bottom: 1rem; color: var(--warning);"><i class="ph ph-info" style="font-size: 3rem;"></i></div>
            <h3 class="font-bold mb-2" style="font-size: 1.2rem;">Пост без текста?</h3>
            <p class="text-muted mb-6" style="line-height: 1.4; font-size: 0.95rem;">Публикации с описанием чаще рекомендуются другим пользователям на основе алгоритмов. Уверены, что хотите оставить пост пустым?</p>
            <div class="flex gap-3 flex-col">
                <button class="vc-btn" style="padding: 12px;" onclick="closeModal('textWarningModal'); document.getElementById('postContent').focus();">Добавить текст</button>
                <button class="vc-btn-outline w-full" style="padding: 12px; border: none; color: var(--text-muted);" onclick="forceCreatePost()">Всё равно опубликовать</button>
            </div>
        </div>
    </div>

    <div id="createView" class="modal-overlay" style="background:var(--bg);" onclick="closeModalOnOutsideClick(event, 'createView', false, true)">
        <div class="modal-content" style="background:transparent; max-width: 500px; padding: 1rem;">
            <div class="flex justify-between items-center mb-8">
                <h2 class="font-bold" style="font-size:1.6rem;">Новый пост</h2>
                <button type="button" onclick="closeCreatePost()" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.6rem;"></i></button>
            </div>
            
            <form id="createPostForm" onsubmit="handleCreatePostSubmit(event)" novalidate>
                <input id="postImageUpload" type="file" multiple class="hidden" accept="image/png, image/jpeg, image/gif, image/webp" onchange="handleMultiplePostImages(event)" />
                
                <div id="multiImagePreviewContainer" class="hidden"></div>
                
                <div id="uploadZone" class="mb-4">
                    <label id="uploadLabel" class="flex flex-col items-center justify-center" style="height:80px; background:var(--surface-elevated); border-radius:var(--radius-md); cursor:pointer; transition:var(--transition);"
                           ondragover="event.preventDefault(); this.style.backgroundColor='var(--surface-active)';"
                           ondragleave="event.preventDefault(); this.style.backgroundColor='var(--surface-elevated)';"
                           ondrop="event.preventDefault(); this.style.backgroundColor='var(--surface-elevated)'; handleDropPhotos(event);"
                           onclick="document.getElementById('postImageUpload').click()">
                        <div class="flex items-center gap-2 text-muted">
                            <i class="ph ph-image" style="font-size:1.5rem;"></i>
                            <span style="font-size:0.95rem; font-weight:500;">Прикрепить фото</span>
                        </div>
                    </label>
                </div>
                
                <div class="input-group">
                    <textarea id="postContent" class="vc-input auto-resize" placeholder=" " style="min-height: 140px; resize:none; padding-top:24px; font-size:1.1rem;" oninput="resizeTextarea(this)"></textarea>
                    <label class="vc-label">Напишите что-нибудь</label>
                </div>
                <button type="submit" id="submitPostBtn" class="vc-btn">Опубликовать</button>
            </form>
        </div>
    </div>

    <div id="commentsModal" class="modal-overlay modal-bottom" onclick="closeModalOnOutsideClick(event, 'commentsModal')">
        <div class="modal-content">
            <div class="flex justify-between items-center" style="padding:1.25rem 1.5rem; border-bottom:1px solid var(--surface-hover);">
                <h3 class="font-bold" style="font-size:1.1rem;">Комментарии</h3>
                <button type="button" onclick="closeModal('commentsModal')" style="color:var(--text-muted);"><i class="ph ph-caret-down" style="font-size:1.4rem;"></i></button>
            </div>
            
            <div id="commentsList" class="comments-list smooth-fade-in"></div>
            
            <form onsubmit="sendComment(event)" class="comment-input-area" novalidate>
                <div id="replyingToIndicator" class="hidden" style="width: 100%; padding: 10px 16px; margin-bottom: 12px; background-color: var(--surface-elevated); border-radius: 12px; font-size: 0.85rem; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--surface-hover);">
                    <div class="flex items-center gap-2">
                        <i class="ph ph-arrow-u-down-left" style="font-size: 1.1rem;"></i> 
                        <span>В ответ <b id="replyingToName" style="color: white; margin-left: 2px;"></b></span>
                    </div>
                    <button type="button" onclick="cancelReply()" style="color: var(--text-muted); font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: color 0.2s;" onmouseover="this.style.color='white'" onmouseout="this.style.color='var(--text-muted)'">
                        <i class="ph ph-x"></i>
                    </button>
                </div>
                
                <div id="commentImagePreviewContainer" class="hidden w-full mb-3 relative">
                    <div style="position: relative; display: inline-block;">
                        <img id="commentImagePreview" src="" style="max-height: 140px; border-radius: 12px; object-fit: contain; background: black;">
                        <button type="button" onclick="clearCommentImage()" style="position: absolute; top: -8px; right: -8px; background: var(--surface-elevated); color: white; border-radius: 50%; padding: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.5); border: 1px solid var(--surface-hover);"><i class="ph ph-x"></i></button>
                    </div>
                </div>
                
                <div class="comment-input-wrapper">
                    <button type="button" onclick="document.getElementById('commentImageUpload').click()" style="width: 44px; height: 44px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--text-muted); transition: 0.2s;"><i class="ph ph-plus-circle"></i></button>
                    <input type="file" id="commentImageUpload" class="hidden" accept="image/png, image/jpeg, image/gif, image/webp" onchange="handleCommentImage(event)">
                    
                    <textarea id="commentInput" placeholder="Добавить комментарий..." autocomplete="off" rows="1" oninput="resizeTextarea(this)"></textarea>
                    
                    <button type="submit" id="sendCommentBtn" style="width: 44px; height: 44px; border-radius: 50%; background: var(--accent); color: var(--accent-bg); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; padding-left: 4px;"><i class="ph-fill ph-paper-plane-right"></i></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentUser = null;
        let csrfToken = '';
        let currentOpenPostId = null;
        let postCommentsCache = {};
        let floatIntervals = {};
        let activeFeedType = 'all';
        let isProcessing = false;
        let pendingPostImageFiles = [];
        let localBookmarksState = {};
        let replyingToCommentId = null;

        let currentFeedIndex = 0;
        let isScrollingFeed = false;
        let touchStartX = 0;
        let currentX = 0;
        let isDragging = false;
        let wheelTimeout;

        // Переменные для 2FA логина
        let tfaLoginTempToken = '';
        let tfaLoginMethod = '';
        
        // Переменные для 2FA настройки
        let tfaSetupTempToken = '';

        const showToast = (msg) => {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast'; toast.textContent = msg;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => { if(toast.parentNode) toast.remove(); }, 300);
            }, 3000);
        };

        const validateFormFields = (form) => {
            const inputs = form.querySelectorAll('input[required], textarea[required]');
            for (let el of inputs) {
                if (!el.value.trim()) {
                    const name = el.getAttribute('data-name') || el.nextElementSibling?.innerText || 'Обязательное поле';
                    showToast(`Пожалуйста, заполните: ${name}`);
                    el.focus();
                    return false;
                }
                if (el.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value)) {
                    showToast('Введите корректный Email');
                    el.focus();
                    return false;
                }
                if (el.minLength && el.value.length < el.minLength) {
                    showToast(`Минимальная длина поля — ${el.minLength} символов`);
                    el.focus();
                    return false;
                }
            }
            return true;
        };

        const requireAuthClient = () => {
            if(!currentUser) {
                showToast("Для этого действия необходимо войти");
                setTimeout(() => navigate('/login'), 1500);
                return false;
            }
            return true;
        };

        let confirmActionCb = null;
        const showConfirm = (title, text, onConfirm) => {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmText').textContent = text;
            confirmActionCb = onConfirm;
            openModal('confirmModal');
        };

        document.getElementById('confirmActionBtn').onclick = () => {
            closeModal('confirmModal');
            if(confirmActionCb) confirmActionCb();
        };

        const linkify = (text) => {
            const urlRegex = /(https?:\/\/[^\s]+)/g;
            let safeText = text.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener noreferrer" style="color: #60a5fa; text-decoration: underline; word-break: break-word;" onclick="event.stopPropagation()">$1</a>');
            
            const hashRegex = /(^|\s)(#[a-zA-Zа-яА-ЯёЁ0-9_]+)/g;
            safeText = safeText.replace(hashRegex, '$1<span class="hashtag" onclick="event.stopPropagation(); triggerHashtagSearch(\'$2\')">$2</span>');
            return safeText;
        };

        window.triggerHashtagSearch = (tag) => {
            document.getElementById('searchInput').value = tag;
            openModal('searchModal', 'searchInput');
            performSearch();
        };

        const getPlural = (number, one, two, five) => {
            let n = Math.abs(number) % 100, n1 = n % 10;
            if (n > 10 && n < 20) return five;
            if (n1 > 1 && n1 < 5) return two;
            if (n1 === 1) return one;
            return five;
        };

        const timeAgo = (dateStr) => {
            const date = new Date((dateStr + ' UTC').replace(/-/g, '/'));
            const seconds = Math.floor((new Date() - date) / 1000);
            if (seconds < 60) return "Только что";
            let i = Math.floor(seconds / 31536000); if (i >= 1) return `${i} ${getPlural(i, 'год', 'года', 'лет')} назад`;
            i = Math.floor(seconds / 2592000); if (i >= 1) return `${i} ${getPlural(i, 'мес.', 'мес.', 'мес.')} назад`;
            i = Math.floor(seconds / 86400); if (i >= 1) return `${i} ${getPlural(i, 'день', 'дня', 'дней')} назад`;
            i = Math.floor(seconds / 3600); if (i >= 1) return `${i} ${getPlural(i, 'час', 'часа', 'часов')} назад`;
            i = Math.floor(seconds / 60); return `${i} ${getPlural(i, 'мин.', 'мин.', 'мин.')} назад`;
        };

        const maskIp = (ip) => {
            if(!ip || ip === 'Unknown') return 'Скрыт';
            const parts = ip.split('.');
            if(parts.length === 4) return `${parts[0]}.${parts[1]}.***.***`;
            if(ip.includes(':')) return ip.substring(0, 9) + '****:****';
            return '***.***.***.***';
        };

        const resizeTextarea = (el) => { el.style.height = 'auto'; el.style.height = (el.scrollHeight) + 'px'; };

        const switchView = (viewId) => {
            ['loginView', 'registerView', 'feedView', 'profileView'].forEach(id => {
                const el = document.getElementById(id);
                if(el) { if (id === viewId) el.classList.add('active'); else el.classList.remove('active'); }
            });
        };

        const updateSeoTitleDynamic = (authorName = null) => {
            if (authorName) {
                document.title = `Публикация от @${authorName} | Dump`;
            } else {
                document.title = Math.random() > 0.5 ? "Dump" : "Настоящий Dump";
            }
        };

        const navigate = (path, replace = false) => {
            if (!path.startsWith('/')) path = '/' + path;
            const fullPath = BASE_PATH + path;
            if (replace) window.history.replaceState(null, '', fullPath);
            else window.history.pushState(null, '', fullPath);
            handleRoute();
        };

        window.addEventListener('popstate', () => handleRoute());

        function goHome() {
            updateSeoTitleDynamic();
            navigate('/', true);
            loadFeed();
        }

        const handleRoute = () => {
            let path = window.location.pathname;
            if (BASE_PATH && path.startsWith(BASE_PATH)) path = path.substring(BASE_PATH.length);
            if (!path) path = '/';

            const nav = document.getElementById('mainNav');
            const feedTabs = document.getElementById('feedTabs');
            
            const isGuest = !currentUser;
            const isPostRoute = path.startsWith('/post/');

            const navBackBtn = document.getElementById('navBackBtn');
            const navLogo = document.getElementById('navLogo');
            if(navBackBtn) navBackBtn.classList.add('hidden');
            if(navLogo) navLogo.classList.remove('hidden');

            if (isGuest && !isPostRoute && path !== '/' && path !== '/login' && path !== '/register') {
                navigate('/login', true);
                return;
            }

            if (isGuest && path === '/login') { switchView('loginView'); if(nav) nav.classList.remove('visible'); return; }
            if (isGuest && path === '/register') { switchView('registerView'); if(nav) nav.classList.remove('visible'); return; }

            if(nav) nav.classList.add('visible');
            
            if (isGuest) {
                document.getElementById('navUserBtn').onclick = () => navigate('/login');
                document.getElementById('navUserBtn').innerHTML = '<i class="ph ph-sign-in"></i>';
                document.getElementById('navCreateBtn').classList.add('hidden');
            } else {
                document.getElementById('navUserBtn').onclick = () => navigate('/profile');
                document.getElementById('navUserBtn').innerHTML = '<i class="ph ph-user"></i>';
                document.getElementById('navCreateBtn').classList.remove('hidden');
            }
            
            if (path.startsWith('/profile') && !isGuest) {
                switchView('profileView');
                if(feedTabs) feedTabs.classList.add('hidden');
                const parts = path.split('/');
                const uid = (parts[parts.length - 1] && parts[parts.length - 1] !== 'profile') ? parseInt(parts[parts.length - 1]) : currentUser.id;
                openProfileData(uid);
                window.scrollTo(0,0);
            } 
            else if (path === '/create' && !isGuest) {
                if(feedTabs) feedTabs.classList.add('hidden');
                openModal('createView', 'postContent');
            } 
            else { 
                switchView('feedView');
                if(feedTabs) feedTabs.classList.remove('hidden');
                if(isGuest && feedTabs) feedTabs.classList.add('hidden'); 
                initTabIndicator(); 
                
                const createView = document.getElementById('createView');
                if (createView && createView.classList.contains('open')) closeModal('createView');
                
                if (document.getElementById('feedView').innerHTML === '' || isPostRoute) {
                    loadFeed();
                }
            }
        };

        const openModal = (id, focusId = null) => {
            const modal = document.getElementById(id);
            if(!modal) return;
            modal.classList.remove('hidden');
            void modal.offsetWidth;
            modal.classList.add('open');
            if(focusId) setTimeout(() => document.getElementById(focusId).focus(), 300);
        };

        const closeModal = (id) => {
            const modal = document.getElementById(id);
            if(!modal) return;
            modal.classList.remove('open');
            setTimeout(() => { modal.classList.add('hidden'); }, 300);
            if(id === 'commentsModal') {
                currentOpenPostId = null;
                cancelReply();
            }
        };

        const closeCreatePost = () => {
            closeModal('createView');
            if(window.history.length > 2) window.history.back();
            else navigate('/', true);
        };

        const closeModalOnOutsideClick = (e, modalId, routeBack = false, isCreate = false) => {
            if (e.target.id === modalId) { 
                if (isCreate) closeCreatePost();
                else if (routeBack) navigate('/'); 
                else closeModal(modalId); 
            }
        };

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                ['postOptionsModal', 'commentsModal', 'settingsModal', 'cropModal', 'searchModal', 'passwordModal', 'confirmModal', 'textWarningModal', 'tfaSettingsModal', 'tfaLoginModal'].forEach(id => {
                    const m = document.getElementById(id);
                    if(m && m.classList.contains('open')) closeModal(id);
                });
                const create = document.getElementById('createView');
                if(create && create.classList.contains('open')) closeCreatePost();
            }
        });

        // ==========================
        // JS: 2FA LOGIC 
        // ==========================
        
        async function handleAuth(e, action) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;

            if (isProcessing) return;
            isProcessing = true;
            
            const fd = new FormData(form);
            fd.append('csrf_token', csrfToken || '');
            
            const btn = setFormState(form, true);
            const origText = btn.textContent;
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            
            try {
                const res = await fetch(`?api=${action}`, { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    if (data.require_2fa) {
                        tfaLoginTempToken = data.temp_token;
                        tfaLoginMethod = data.method;
                        
                        const icon = document.getElementById('tfaLoginIcon');
                        const desc = document.getElementById('tfaLoginDesc');
                        if (tfaLoginMethod === 'email') {
                            icon.className = 'ph ph-envelope-simple mb-4';
                            desc.textContent = 'Мы отправили код подтверждения на ваш Email.';
                        } else {
                            icon.className = 'ph ph-device-mobile mb-4';
                            desc.textContent = 'Введите код из приложения (Authenticator).';
                        }
                        
                        document.getElementById('tfaLoginCode').value = '';
                        openModal('tfaLoginModal', 'tfaLoginCode');
                    } else {
                        form.reset();
                        await init(); 
                        navigate('/', true);
                    }
                } else showToast(data.error || 'Ошибка');
            } catch (err) { showToast('Ошибка соединения'); } 
            finally { btn.textContent = origText; setFormState(form, false); isProcessing = false; }
        }

        async function verifyTfaLogin(e) {
            e.preventDefault();
            const code = document.getElementById('tfaLoginCode').value.trim();
            if (code.length !== 6) { showToast('Введите 6 цифр'); return; }
            
            const btn = document.getElementById('tfaLoginBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            btn.disabled = true;

            try {
                const fd = new FormData();
                fd.append('temp_token', tfaLoginTempToken);
                fd.append('code', code);
                
                const res = await fetch(apiCall('tfa_verify_login'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    closeModal('tfaLoginModal');
                    await init();
                    navigate('/', true);
                } else {
                    showToast(data.error || 'Неверный код');
                }
            } catch (err) {
                showToast('Ошибка при проверке кода');
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        }

        function cancelTfaLogin() {
            closeModal('tfaLoginModal');
            tfaLoginTempToken = '';
        }

        async function openTfaSettingsModal() {
            try {
                const res = await fetch(apiCall('tfa_settings'));
                const data = await res.json();
                
                const toggle = document.getElementById('tfaToggle');
                const setupContainer = document.getElementById('tfaSetupContainer');
                const verifyContainer = document.getElementById('tfaVerifyContainer');
                
                toggle.checked = data.data.tfa_enabled == 1;
                setupContainer.classList.add('hidden');
                verifyContainer.classList.add('hidden');
                
                updateTfaBadgeStatus(toggle.checked);
                openModal('tfaSettingsModal');
            } catch (e) {
                showToast("Ошибка загрузки настроек 2FA");
            }
        }

        function updateTfaBadgeStatus(isEnabled) {
            const badge = document.getElementById('tfaStatusBadge');
            if (isEnabled) {
                badge.textContent = 'Вкл';
                badge.style.background = 'rgba(52, 211, 153, 0.2)';
                badge.style.color = '#34d399';
            } else {
                badge.textContent = 'Выкл';
                badge.style.background = 'var(--surface-hover)';
                badge.style.color = 'var(--text-muted)';
            }
        }

        function handleTfaToggleChange() {
            const toggle = document.getElementById('tfaToggle');
            const setupContainer = document.getElementById('tfaSetupContainer');
            const verifyContainer = document.getElementById('tfaVerifyContainer');
            
            verifyContainer.classList.add('hidden');
            
            if (toggle.checked) {
                setupContainer.classList.remove('hidden');
                changeTfaMethodPreview();
            } else {
                setupContainer.classList.add('hidden');
                showConfirm('Отключение 2FA', 'Вы уверены, что хотите отключить двухфакторную аутентификацию? Ваша учетная запись станет менее защищенной.', () => {
                    disableTfa();
                });
                toggle.checked = true; // Возвращаем визуально пока не подтвердит
            }
        }

        function changeTfaMethodPreview() {
            const method = document.querySelector('input[name="tfaMethod"]:checked').value;
            if (method === 'email') {
                document.getElementById('tfaPreviewEmail').classList.remove('hidden');
                document.getElementById('tfaPreviewApp').classList.add('hidden');
            } else {
                document.getElementById('tfaPreviewEmail').classList.add('hidden');
                document.getElementById('tfaPreviewApp').classList.remove('hidden');
            }
        }

        async function startTfaSetup() {
            const method = document.querySelector('input[name="tfaMethod"]:checked').value;
            const btn = document.getElementById('tfaStartSetupBtn');
            const orig = btn.innerHTML;
            
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            btn.disabled = true;
            
            try {
                const fd = new FormData();
                fd.append('method', method);
                fd.append('csrf_token', csrfToken);
                
                const res = await fetch(apiCall('tfa_setup_start'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    tfaSetupTempToken = data.temp_token;
                    document.getElementById('tfaSetupContainer').classList.add('hidden');
                    document.getElementById('tfaVerifyContainer').classList.remove('hidden');
                    document.getElementById('tfaSetupCode').value = '';
                    
                    if (method === 'app') {
                        document.getElementById('tfaAppQrContainer').classList.remove('hidden');
                        document.getElementById('tfaEmailSentMessage').classList.add('hidden');
                        document.getElementById('tfaQrImage').src = data.qr_url;
                        document.getElementById('tfaSecretKey').textContent = data.secret;
                    } else {
                        document.getElementById('tfaAppQrContainer').classList.add('hidden');
                        document.getElementById('tfaEmailSentMessage').classList.remove('hidden');
                    }
                    
                    setTimeout(() => document.getElementById('tfaSetupCode').focus(), 100);
                } else {
                    showToast(data.error || 'Ошибка');
                }
            } catch (e) {
                showToast('Ошибка соединения');
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        }

        async function confirmTfaSetup() {
            const code = document.getElementById('tfaSetupCode').value.trim();
            if (code.length !== 6) { showToast('Введите 6 цифр'); return; }
            
            const btn = document.getElementById('tfaConfirmSetupBtn');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            btn.disabled = true;
            
            try {
                const fd = new FormData();
                fd.append('temp_token', tfaSetupTempToken);
                fd.append('code', code);
                fd.append('csrf_token', csrfToken);
                
                const res = await fetch(apiCall('tfa_setup_verify'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Двухфакторная аутентификация включена!');
                    closeModal('tfaSettingsModal');
                    updateTfaBadgeStatus(true);
                    currentUser.tfa_enabled = 1;
                } else {
                    showToast(data.error || 'Неверный код. Попробуйте еще раз.');
                }
            } catch (e) {
                showToast('Ошибка при подтверждении');
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        }

        async function disableTfa() {
            try {
                const fd = new FormData();
                fd.append('csrf_token', csrfToken);
                const res = await fetch(apiCall('tfa_disable'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Двухфакторная аутентификация отключена');
                    document.getElementById('tfaToggle').checked = false;
                    document.getElementById('tfaSetupContainer').classList.add('hidden');
                    updateTfaBadgeStatus(false);
                    currentUser.tfa_enabled = 0;
                }
            } catch(e) {
                showToast("Ошибка отключения 2FA");
            }
        }

        // ==========================
        // КОНЕЦ JS: 2FA LOGIC 
        // ==========================

        function markPostAsSeen(postId) {
            if (!currentUser || !postId) return;
            const fd = new FormData(); 
            fd.append('post_id', postId); 
            fd.append('csrf_token', csrfToken);
            fetch(apiCall('mark_seen'), { method: 'POST', body: fd, keepalive: true }).catch(()=>{});
        }

        const feedViewEl = document.getElementById('feedView');
        
        feedViewEl.addEventListener('wheel', (e) => {
            if (!feedViewEl.classList.contains('active') || isScrollingFeed) return;
            
            const scrollable = e.target.closest('.scrollable-overlay') || e.target.closest('.post-text-content');
            if (scrollable) {
                const atTop = scrollable.scrollTop <= 0;
                const atBottom = scrollable.scrollTop + scrollable.clientHeight >= scrollable.scrollHeight - 1;
                if (e.deltaY > 0 && !atBottom) return;
                if (e.deltaY < 0 && !atTop) return;
            }

            if (Math.abs(e.deltaX) > 20 || Math.abs(e.deltaY) > 20) {
                const isNext = (Math.abs(e.deltaX) > Math.abs(e.deltaY)) ? e.deltaX > 0 : e.deltaY > 0;
                isScrollingFeed = true;
                if (isNext) goToFeedPost(currentFeedIndex + 1);
                else goToFeedPost(currentFeedIndex - 1);

                clearTimeout(wheelTimeout);
                wheelTimeout = setTimeout(() => { isScrollingFeed = false; }, 500);
            }
        }, {passive: true});

        function handleSwipeStart(e) {
            if (!feedViewEl.classList.contains('active') || isScrollingFeed) return;
            
            if (e.target.closest('button') || e.target.closest('.action-btn') || e.target.closest('.scrollable-overlay') || e.target.closest('.post-author') || e.target.closest('a') || e.target.closest('.hashtag')) return;
            
            isDragging = true;
            startX = e.type.includes('mouse') ? e.pageX : e.touches[0].clientX;
            currentX = startX;
            
            const wrapper = document.getElementById('feedWrapper');
            if (wrapper) wrapper.style.transition = 'none';
        }

        function handleSwipeMove(e) {
            if (!isDragging) return;
            currentX = e.type.includes('mouse') ? e.pageX : e.touches[0].clientX;
            const diff = currentX - startX;
            const wrapper = document.getElementById('feedWrapper');
            if (wrapper) wrapper.style.transform = `translateX(calc(-${currentFeedIndex * 100}vw + ${diff}px))`;
        }

        function handleSwipeEnd(e) {
            if (!isDragging) return;
            isDragging = false;
            
            const diff = currentX - startX;
            const wrapper = document.getElementById('feedWrapper');
            if (wrapper) wrapper.style.transition = 'transform 0.4s cubic-bezier(0.25, 1, 0.5, 1)';
            
            if (Math.abs(diff) > window.innerWidth / 5 || Math.abs(diff) > 100) {
                if (diff < 0) goToFeedPost(currentFeedIndex + 1);
                else goToFeedPost(currentFeedIndex - 1);
            } else {
                goToFeedPost(currentFeedIndex);
            }
            currentX = startX;
        }

        feedViewEl.addEventListener('touchstart', handleSwipeStart, {passive: true});
        feedViewEl.addEventListener('touchmove', handleSwipeMove, {passive: true});
        feedViewEl.addEventListener('touchend', handleSwipeEnd);
        
        feedViewEl.addEventListener('mousedown', handleSwipeStart);
        feedViewEl.addEventListener('mousemove', handleSwipeMove);
        feedViewEl.addEventListener('mouseup', handleSwipeEnd);
        feedViewEl.addEventListener('mouseleave', handleSwipeEnd);

        function goToFeedPost(index) {
            const wrapper = document.getElementById('feedWrapper');
            if (!wrapper) return;
            
            const maxIndex = wrapper.children.length - 1;
            if (index < 0) index = 0;
            if (index > maxIndex) index = maxIndex;
            
            isScrollingFeed = true;
            
            const oldPostId = wrapper.children[currentFeedIndex]?.dataset?.id;
            if (oldPostId) stopFloatingComments(oldPostId);

            currentFeedIndex = index;
            wrapper.style.transform = `translateX(-${currentFeedIndex * 100}vw)`;
            
            const newPostId = wrapper.children[currentFeedIndex]?.dataset?.id;
            const newPostSlug = wrapper.children[currentFeedIndex]?.dataset?.slug;
            
            setTimeout(() => {
                isScrollingFeed = false;
                if (newPostId) {
                    startFloatingComments(newPostId);
                    markPostAsSeen(newPostId);
                }
                
                if (window.postSliderInterval) { clearInterval(window.postSliderInterval); }
                const activeSlider = wrapper.children[currentFeedIndex]?.querySelector('.image-slider');
                if (activeSlider && activeSlider.children.length > 1) {
                    let isSliderPaused = false;
                    const imagesCount = activeSlider.children.length;
                    const dotsContainer = activeSlider.nextElementSibling;
                    const dots = dotsContainer?.querySelectorAll('.slider-dot');
                    
                    if(dots) {
                        dots.forEach(d => { d.classList.remove('active', 'paused'); void d.offsetWidth; });
                        if(dots[0]) dots[0].classList.add('active');
                    }
                    activeSlider.style.transform = `translateX(0%)`; 
                    let currentSlideIndex = 0;

                    window.autoSlideLogic = () => {
                        if (isSliderPaused) return;
                        
                        currentSlideIndex = (currentSlideIndex + 1) % imagesCount;
                        activeSlider.style.transform = `translateX(-${currentSlideIndex * 100}%)`;
                        
                        if(dots) {
                            dots.forEach((d, i) => {
                                d.classList.remove('active', 'paused');
                                if (i === currentSlideIndex) { void d.offsetWidth; d.classList.add('active'); }
                            });
                        }
                    };

                    window.postSliderInterval = setInterval(window.autoSlideLogic, 2000);

                    const setPause = (state) => {
                        isSliderPaused = state;
                        const activeDot = dotsContainer?.querySelector('.slider-dot.active');
                        if (activeDot) {
                            if (state) activeDot.classList.add('paused');
                            else activeDot.classList.remove('paused');
                        }
                    };

                    const postWrapper = wrapper.children[currentFeedIndex]?.querySelector('.post-wrapper');
                    if (postWrapper) {
                        postWrapper.addEventListener('pointerdown', () => setPause(true));
                        postWrapper.addEventListener('pointerup', () => setPause(false));
                        postWrapper.addEventListener('pointercancel', () => setPause(false));
                        postWrapper.addEventListener('pointerleave', () => setPause(false));
                    }
                }
                
                const postAuthor = wrapper.children[currentFeedIndex]?.querySelector('.author-name')?.textContent;
                updateSeoTitleDynamic(postAuthor);

                if (newPostSlug && window.location.pathname !== BASE_PATH + `/post/${newPostSlug}`) {
                    window.history.replaceState(history.state, '', BASE_PATH + `/post/${newPostSlug}`);
                }
            }, 420);
        }

        async function init() {
            try {
                const res = await fetch(apiCall('me'), { cache: 'no-store' });
                if(!res.ok) throw new Error('API Error');
                const data = await res.json();
                csrfToken = data.csrf || '';
                currentUser = data.user || null;
            } catch (e) { showToast('Не удалось связаться с сервером'); } 
            finally { handleRoute(); }
        }

        const setFormState = (form, disabled) => {
            const btn = form.querySelector('button[type="submit"]');
            form.querySelectorAll('input, textarea, button').forEach(i => i.disabled = disabled);
            return btn;
        };

        async function logout() {
            const fd = new FormData(); fd.append('csrf_token', csrfToken);
            await fetch(apiCall('logout'), { method: 'POST', body: fd });
            currentUser = null; csrfToken = ''; postCommentsCache = {};
            document.getElementById('feedView').innerHTML = ''; 
            closeModal('settingsModal');
            navigate('/login', true);
        }

        async function uploadToImgBB(file) {
            const fd = new FormData(); 
            fd.append('image', file);
            fd.append('csrf_token', csrfToken);
            try {
                const res = await fetch(apiCall('upload_image'), { method: 'POST', body: fd });
                const data = await res.json();
                return data.success ? data.url : null;
            } catch { 
                showToast('Ошибка загрузки медиа'); 
                return null; 
            }
        }

        function openSettings() {
            document.getElementById('settingsAvatarPreview').src = currentUser.avatar_url || `https://ui-avatars.com/api/?name=${currentUser.username}&background=random`;
            document.getElementById('settingsBio').value = currentUser.bio || '';
            document.getElementById('accUsername').value = currentUser.username || '';
            document.getElementById('accEmail').value = currentUser.email || '';
            switchSettingsTab('profile');
            openModal('settingsModal');
        }

        function switchSettingsTab(tab) {
            ['profile', 'account', 'sessions'].forEach(t => {
                document.getElementById('pane' + t.charAt(0).toUpperCase() + t.slice(1)).classList.add('hidden');
                document.getElementById('tabBtn' + t.charAt(0).toUpperCase() + t.slice(1)).classList.remove('active');
            });
            document.getElementById('pane' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.remove('hidden');
            document.getElementById('tabBtn' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
            
            if(tab === 'sessions') loadSessions();
        }

        function openPasswordModal() {
            document.getElementById('chPassCurrent').value = '';
            document.getElementById('chPassNew').value = '';
            document.getElementById('chPassConfirm').value = '';
            openModal('passwordModal', 'chPassCurrent');
        }

        let cropper = null;
        let pendingSettingsAvatarBlob = null;

        function initCrop(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > MAX_FILE_SIZE) { showToast('Файл слишком большой (макс 5 МБ)'); return; }
            
            const reader = new FileReader();
            reader.onload = (event) => {
                document.getElementById('cropImage').src = event.target.result;
                openModal('cropModal');
                if (cropper) cropper.destroy();
                cropper = new Cropper(document.getElementById('cropImage'), {
                    aspectRatio: 1, viewMode: 1, background: false, autoCropArea: 0.8, responsive: true
                });
            };
            reader.readAsDataURL(file);
            e.target.value = ''; 
        }

        function cancelCrop() { closeModal('cropModal'); if (cropper) { cropper.destroy(); cropper = null; } }

        function doCrop() {
            if (!cropper) return;
            const btn = document.getElementById('cropBtn');
            btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner spin"></i>';
            
            cropper.getCroppedCanvas({ width: 400, height: 400 }).toBlob(async (blob) => {
                pendingSettingsAvatarBlob = blob;
                document.getElementById('settingsAvatarPreview').src = URL.createObjectURL(blob);
                closeModal('cropModal');
                cropper.destroy(); cropper = null;
                btn.disabled = false; btn.innerHTML = 'Применить';
            }, 'image/jpeg', 0.9);
        }

        async function saveProfile(e) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            isProcessing = true;
            
            const btn = setFormState(form, true);
            const orig = btn.textContent;
            btn.innerHTML = 'Сохранение...';

            try {
                let avatarUrl = '';
                if (pendingSettingsAvatarBlob) {
                    const file = new File([pendingSettingsAvatarBlob], "avatar.jpg", { type: "image/jpeg" });
                    avatarUrl = await uploadToImgBB(file) || '';
                    pendingSettingsAvatarBlob = null;
                }

                const fd = new FormData();
                fd.append('bio', document.getElementById('settingsBio').value);
                fd.append('avatar_url', avatarUrl);
                fd.append('csrf_token', csrfToken);

                const res = await fetch(apiCall('update_profile'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Профиль обновлен');
                    await init();
                    if(window.location.pathname.includes('profile')) openProfileData(currentUser.id); 
                } else showToast(data.error || 'Ошибка');
            } finally { btn.textContent = orig; setFormState(form, false); isProcessing = false; }
        }

        async function saveAccount(e) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            isProcessing = true;
            
            const btn = setFormState(form, true);
            const orig = btn.textContent;
            btn.innerHTML = 'Сохранение...';

            try {
                const fd = new FormData();
                fd.append('username', document.getElementById('accUsername').value);
                fd.append('email', document.getElementById('accEmail').value);
                fd.append('csrf_token', csrfToken);

                const res = await fetch(apiCall('update_account'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Аккаунт сохранён');
                    await init();
                    if(window.location.pathname.includes('profile')) openProfileData(currentUser.id); 
                } else showToast(data.error || 'Ошибка сохранения');
            } finally { btn.textContent = orig; setFormState(form, false); isProcessing = false; }
        }

        async function changePassword(e) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            isProcessing = true;
            
            const btn = setFormState(form, true);
            const orig = btn.textContent;
            btn.innerHTML = 'Проверка...';

            try {
                const fd = new FormData();
                fd.append('current_password', document.getElementById('chPassCurrent').value);
                fd.append('new_password', document.getElementById('chPassNew').value);
                fd.append('confirm_password', document.getElementById('chPassConfirm').value);
                fd.append('csrf_token', csrfToken);

                const res = await fetch(apiCall('change_password'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    showToast('Пароль успешно изменён');
                    closeModal('passwordModal');
                } else showToast(data.error || 'Ошибка смены пароля');
            } finally { btn.textContent = orig; setFormState(form, false); isProcessing = false; }
        }

        async function loadSessions() {
            const list = document.getElementById('sessionsList');
            list.innerHTML = '<div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>';
            try {
                const res = await fetch(apiCall('get_sessions'));
                const data = await res.json();
                if(data.sessions) {
                    list.innerHTML = data.sessions.map(s => {
                        let device = "Неизвестное устройство";
                        if(s.user_agent.includes('Windows')) device = "Windows";
                        else if(s.user_agent.includes('Mac OS')) device = "MacOS";
                        else if(s.user_agent.includes('Android')) device = "Android";
                        else if(s.user_agent.includes('iPhone') || s.user_agent.includes('iPad')) device = "iOS";
                        
                        let browser = "";
                        if(s.user_agent.includes('Chrome')) browser = "Chrome";
                        else if(s.user_agent.includes('Safari')) browser = "Safari";
                        else if(s.user_agent.includes('Firefox')) browser = "Firefox";
                        
                        const title = `${device} ${browser}`.trim() || 'Устройство';

                        return `
                        <div class="session-item smooth-fade-in" id="sess-${s.id}">
                            <div>
                                <div class="font-bold text-sm mb-1">${title} ${s.is_current ? '<span class="text-error ml-1 text-xs px-1" style="background:rgba(255,42,95,0.1); border-radius:4px;">Текущая</span>' : ''}</div>
                                <div class="text-xs text-muted transition cursor-help hover:text-white" onmouseover="this.innerText='IP: ${s.ip_address}'" onmouseout="this.innerText='IP: ${maskIp(s.ip_address)}'">IP: ${maskIp(s.ip_address)}</div>
                                <div class="text-xs text-muted mt-1">Вход: ${timeAgo(s.created_at)}</div>
                            </div>
                            ${!s.is_current ? `<button type="button" class="vc-btn-outline" style="padding: 6px 12px; border-radius: 8px; font-size:0.8rem; color:var(--error); border-color:rgba(255,42,95,0.3);" onclick="confirmRevokeSession('${s.id}')">Завершить</button>` : ''}
                        </div>`;
                    }).join('');
                }
            } catch(e) { list.innerHTML = '<div class="text-center py-4 text-error">Ошибка загрузки</div>'; }
        }

        function confirmRevokeSession(id) {
            showConfirm('Завершение сессии', 'Устройство будет отключено. Вы уверены?', () => revokeSession(id));
        }

        async function revokeSession(id) {
            const fd = new FormData(); fd.append('id', id); fd.append('csrf_token', csrfToken);
            const res = await fetch(apiCall('revoke_session'), { method: 'POST', body: fd });
            const data = await res.json();
            if(data.success) {
                document.getElementById(`sess-${id}`).remove();
                showToast('Сессия завершена');
            } else showToast('Ошибка завершения сессии');
        }

        let searchTimeout = null;
        function openSearch() {
            document.getElementById('searchInput').value = '';
            document.getElementById('searchResults').innerHTML = '<div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Что будем искать?</p></div>';
            openModal('searchModal', 'searchInput');
        }

        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 500);
        }

        async function performSearch() {
            const q = document.getElementById('searchInput').value.trim();
            const list = document.getElementById('searchResults');
            if(!q) { list.innerHTML = '<div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Что будем искать?</p></div>'; return; }
            
            list.innerHTML = '<div class="loader-screen" style="min-height: 20vh;"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>';
            try {
                const res = await fetch(apiCall('search') + `&q=${encodeURIComponent(q)}`);
                const data = await res.json();
                
                if(data.posts.length === 0 && data.users.length === 0) {
                    list.innerHTML = '<div class="empty-state"><i class="ph ph-ghost"></i><p>Ничего не найдено</p></div>';
                    return;
                }
                
                let html = '';
                
                if(data.posts.length > 0) {
                    html += `<div class="search-section-title smooth-fade-in">Публикации</div>`;
                    html += data.posts.map(p => {
                        const snippet = p.content.length > 50 ? p.content.substring(0, 50) + '...' : p.content;
                        const firstImage = p.image_url ? p.image_url.split(',')[0] : null;
                        const cover = firstImage ? `<img src="${getProxyUrl(firstImage)}" class="search-result-post object-cover">` : `<div class="search-result-post flex items-center justify-center bg-surface-hover"><i class="ph ph-text-aa text-muted"></i></div>`;
                        return `
                        <div class="search-result-item smooth-fade-in mb-1" onclick="navigate('/post/${p.slug}'); closeModal('searchModal');">
                            ${cover}
                            <div class="flex-1 overflow-hidden">
                                <div class="font-bold text-sm truncate">${p.username}</div>
                                <div class="text-xs text-muted truncate">${snippet || 'Фото'}</div>
                            </div>
                        </div>`;
                    }).join('');
                }

                if(data.users.length > 0) {
                    html += `<div class="search-section-title smooth-fade-in mt-4">Пользователи</div>`;
                    html += data.users.map(u => `
                        <div class="search-result-item smooth-fade-in mb-1" onclick="navigate('/profile/${u.id}'); closeModal('searchModal');">
                            <img src="${u.avatar_url || 'https://ui-avatars.com/api/?name='+u.username+'&background=random'}" class="search-result-img">
                            <div class="font-bold flex-1">${u.username}</div>
                        </div>
                    `).join('');
                }
                
                list.innerHTML = html;
            } catch(e) { list.innerHTML = '<div class="text-error text-center py-4">Ошибка поиска</div>'; }
        }

        window.switchProfileTab = (tab) => {
            const isPosts = tab === 'posts';
            document.getElementById('tabBtnPosts').classList.toggle('active', isPosts);
            document.getElementById('tabBtnBookmarks').classList.toggle('active', !isPosts);
            
            const indicator = document.getElementById('profileTabIndicator');
            if (indicator) {
                indicator.style.transform = isPosts ? 'translateX(0)' : `translateX(${document.getElementById('tabBtnPosts').offsetWidth}px)`;
            }

            document.getElementById('profileGridPosts').classList.toggle('hidden', !isPosts);
            document.getElementById('profileGridBookmarks').classList.toggle('hidden', isPosts);
        };

        window.openLocalFeed = function(source, startIndex) {
            const posts = source === 'posts' ? window.currentProfilePosts : window.currentProfileBookmarks;
            if (!posts || !posts.length) return;

            switchView('feedView');
            
            const feedTabs = document.getElementById('feedTabs');
            if(feedTabs) feedTabs.classList.add('hidden');
            
            document.getElementById('navBackBtn').classList.remove('hidden');
            document.getElementById('navLogo').classList.add('hidden');

            const container = document.getElementById('feedView');
            container.innerHTML = '';
            
            const wrapper = document.createElement('div');
            wrapper.id = 'feedWrapper';
            wrapper.className = 'feed-wrapper';

            posts.forEach(post => {
                localBookmarksState[post.id] = post.is_bookmarked > 0;
                wrapper.appendChild(createPostElement(post));
                fetchCommentsForVibe(post.id);
            });

            const endCard = document.createElement('div');
            endCard.className = 'post-card smooth-fade-in';
            endCard.innerHTML = `<div class="empty-state"><i class="ph ph-check-circle" style="font-size:4rem; color:var(--text-muted);"></i><p class="mt-4" style="font-size:1.1rem; color:white;">Вы посмотрели все публикации</p></div>`;
            wrapper.appendChild(endCard);

            container.appendChild(wrapper);

            currentFeedIndex = startIndex;
            wrapper.style.transition = 'none';
            wrapper.style.transform = `translateX(-${currentFeedIndex * 100}vw)`;

            const newPostSlug = posts[currentFeedIndex].slug;
            window.history.pushState({localFeed: true}, '', BASE_PATH + `/post/${newPostSlug}`);

            setTimeout(() => {
                wrapper.style.transition = 'transform 0.4s cubic-bezier(0.25, 1, 0.5, 1)';
                const initPostId = posts[currentFeedIndex].id;
                startFloatingComments(initPostId);
                markPostAsSeen(initPostId);
            }, 50);
        };

        async function openProfileData(targetId) {
            const container = document.getElementById('profileView');
            const isMe = currentUser && targetId === currentUser.id;
            container.innerHTML = '<div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 3.5rem; color: var(--text-muted);"></i></div>';
            
            try {
                const res = await fetch(apiCall('user_profile') + `&id=${targetId}`);
                const data = await res.json();
                const p = data.profile;
                const avatarUrl = p.avatar_url || `https://ui-avatars.com/api/?name=${p.username}&background=random`;
                
                window.currentProfilePosts = data.posts || [];
                window.currentProfileBookmarks = data.bookmarks || [];
                
                let actionBtnHTML = '';
                if (isMe) {
                    actionBtnHTML = `<button onclick="openSettings()" class="vc-btn vc-btn-outline flex items-center justify-center gap-2" style="padding: 8px 24px; width:auto; border-radius:99px; font-size:0.9rem;"><i class="ph ph-gear"></i> Настройки</button>`;
                } else {
                    const isFollowed = p.is_followed > 0;
                    actionBtnHTML = `<button onclick="toggleFollow(${p.id}, this)" class="vc-btn ${isFollowed ? 'vc-btn-outline' : ''}" style="padding: 8px 24px; width:auto; border-radius:99px; font-size:0.9rem;">${isFollowed ? 'Вы подписаны' : 'Подписаться'}</button>`;
                }

                const joinDate = new Date((p.created_at + ' UTC').replace(/-/g, '/'));
                const months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
                const joinStr = `В Dump с ${months[joinDate.getMonth()]} ${joinDate.getFullYear()}`;

                const createGridHtml = (items, emptyMsg, emptyIcon, source) => {
                    if(!items || items.length === 0) return `<div class="empty-state smooth-fade-in" style="grid-column: span 3;"><i class="ph ${emptyIcon}"></i><p>${emptyMsg}</p></div>`;
                    let html = '';
                    items.forEach((post, index) => {
                        const hasImg = post.image_url && post.image_url !== '';
                        const isMultiple = hasImg && post.image_url.includes(',');
                        const firstImage = hasImg ? post.image_url.split(',')[0] : null;
                        const multiIcon = isMultiple ? '<i class="ph-fill ph-files multi-img-icon"></i>' : '';
                        const content = hasImg ? `${multiIcon}<img src="${getProxyUrl(firstImage)}" loading="lazy" onload="this.classList.add('loaded')">` : `<div class="text-preview p-2 flex items-center justify-center text-center w-full h-full text-xs">${post.content}</div>`;
                        html += `<div class="grid-item smooth-fade-in" onclick="openLocalFeed('${source}', ${index})">${content}</div>`;
                    });
                    return html;
                };

                let tabsHtml = '';
                let gridsHtml = `<div id="profileGridPosts" class="profile-grid">${createGridHtml(data.posts, 'Нет публикаций', 'ph-images', 'posts')}</div>`;

                if (isMe) {
                    tabsHtml = `
                    <div class="profile-tabs-wrapper smooth-fade-in">
                        <div class="profile-tabs">
                            <div id="profileTabIndicator" class="profile-tab-indicator"></div>
                            <button id="tabBtnPosts" class="profile-tab active" onclick="switchProfileTab('posts')"><i class="ph ph-grid-four"></i> Публикации</button>
                            <button id="tabBtnBookmarks" class="profile-tab" onclick="switchProfileTab('bookmarks')"><i class="ph ph-bookmark-simple"></i> Сохранённые</button>
                        </div>
                    </div>`;
                    gridsHtml += `<div id="profileGridBookmarks" class="profile-grid hidden">${createGridHtml(data.bookmarks, 'Нет сохранённых постов', 'ph-bookmark', 'bookmarks')}</div>`;
                }

                container.innerHTML = `
                    <div class="profile-header smooth-fade-in">
                        <img src="${avatarUrl}" class="profile-avatar">
                        <div class="w-full">
                            <h2 class="font-bold" style="font-size:1.4rem;">@${p.username}</h2>
                            <p class="text-muted" style="margin: 6px 0 10px; font-size: 0.95rem; line-height: 1.4; word-break: break-word;">${p.bio || 'Нет информации.'}</p>
                            <div style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 6px 12px; border-radius: 99px; background-color: var(--surface-hover); color: var(--text-muted); font-size: 0.8rem; font-weight: 500; margin-bottom: 1.5rem;"><i class="ph ph-calendar-blank"></i> ${joinStr}</div>
                            <div class="mb-4">${actionBtnHTML}</div>
                            <div class="profile-stats">
                                <div class="stat-item"><div class="stat-val">${p.posts_count}</div><div class="stat-lbl">Посты</div></div>
                                <div class="stat-item"><div class="stat-val" id="statFollowers">${p.followers_count}</div><div class="stat-lbl">Подписчики</div></div>
                                <div class="stat-item"><div class="stat-val">${p.following_count}</div><div class="stat-lbl">Подписки</div></div>
                            </div>
                        </div>
                    </div>
                    ${tabsHtml}
                    ${gridsHtml}
                `;
            } catch(e) { container.innerHTML = `<div class="empty-state"><p>Ошибка загрузки профиля</p></div>`; }
        }

        async function toggleFollow(userId, btn) {
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            isProcessing = true;
            const fd = new FormData(); fd.append('id', userId); fd.append('csrf_token', csrfToken);
            const res = await fetch(apiCall('toggle_follow'), { method: 'POST', body: fd });
            const data = await res.json();
            const followersEl = document.getElementById('statFollowers');
            
            if (data.followed) {
                btn.className = 'vc-btn vc-btn-outline'; btn.textContent = 'Вы подписаны';
                if(followersEl) followersEl.textContent = parseInt(followersEl.textContent) + 1;
            } else {
                btn.className = 'vc-btn'; btn.textContent = 'Подписаться';
                if(followersEl) followersEl.textContent = parseInt(followersEl.textContent) - 1;
            }
            isProcessing = false;
        }

        function processPostImageFiles(files) {
            if (!files.length) return;
            
            let validFiles = [];
            const signatures = new Set();

            for(let f of files) {
                if (f.size > MAX_FILE_SIZE) { showToast(`Файл ${f.name} слишком большой`); continue; }
                if (!f.type.startsWith('image/')) { showToast(`Файл ${f.name} не картинка`); continue; }
                
                const sig = f.name + f.size;
                if(signatures.has(sig)) continue;
                signatures.add(sig);

                validFiles.push(f);
            }

            if(validFiles.length > 5) {
                showToast("Максимум 5 изображений");
                validFiles = validFiles.slice(0, 5);
            }

            pendingPostImageFiles = [...pendingPostImageFiles, ...validFiles].slice(0, 5);
            renderMultiImagePreview();
        }

        function handleDropPhotos(e) {
            if(e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                processPostImageFiles(Array.from(e.dataTransfer.files));
            }
        }

        function handleMultiplePostImages(e) {
            processPostImageFiles(Array.from(e.target.files));
            e.target.value = '';
        }

        function renderMultiImagePreview() {
            const uploadZone = document.getElementById('uploadZone');
            const multiContainer = document.getElementById('multiImagePreviewContainer');

            if (pendingPostImageFiles.length === 0) {
                multiContainer.classList.add('hidden');
                multiContainer.innerHTML = '';
                uploadZone.classList.remove('hidden');
                return;
            }

            uploadZone.classList.add('hidden');
            multiContainer.classList.remove('hidden');

            if (pendingPostImageFiles.length === 1) {
                const url = URL.createObjectURL(pendingPostImageFiles[0]);
                multiContainer.innerHTML = `
                    <div class="big-preview smooth-fade-in">
                        <img src="${url}">
                        <div class="remove-btn" onclick="event.stopPropagation(); removePendingImage(0)"><i class="ph ph-x"></i></div>
                        <div class="overlay-btn" onclick="document.getElementById('postImageUpload').click()"><i class="ph ph-plus" style="font-size: 3rem; color: white; filter: drop-shadow(0 2px 10px rgba(0,0,0,0.5));"></i></div>
                    </div>
                `;
            } else {
                let html = '<div class="preview-grid smooth-fade-in">';
                pendingPostImageFiles.forEach((file, index) => {
                    const url = URL.createObjectURL(file);
                    html += `
                        <div class="preview-item">
                            <img src="${url}">
                            <div class="remove-btn" onclick="removePendingImage(${index})"><i class="ph ph-x"></i></div>
                        </div>
                    `;
                });
                if (pendingPostImageFiles.length < 5) {
                    html += `<div class="add-more-grid-item" onclick="document.getElementById('postImageUpload').click()"><i class="ph ph-plus"></i></div>`;
                }
                html += '</div>';
                multiContainer.innerHTML = html;
            }
        }

        function removePendingImage(index) {
            pendingPostImageFiles.splice(index, 1);
            document.getElementById('postImageUpload').value = ''; 
            renderMultiImagePreview();
        }

        function handleCreatePostSubmit(e) {
            e.preventDefault();
            if(!requireAuthClient()) return;
            
            const content = document.getElementById('postContent').value.trim();
            if(content === '' && pendingPostImageFiles.length > 0) {
                openModal('textWarningModal');
                return;
            }
            
            forceCreatePost();
        }

        async function forceCreatePost() {
            closeModal('textWarningModal');
            if (isProcessing) return;
            isProcessing = true;
            
            const form = document.getElementById('createPostForm');
            const btn = setFormState(form, true);
            const orig = btn.textContent;
            btn.innerHTML = '<i class="ph ph-circle-notch spin"></i>';
            
            try {
                let uploadedUrls = [];
                if (pendingPostImageFiles.length > 0) {
                    btn.innerHTML = `<span style="font-size:0.9rem;">Загрузка фото...</span>`;
                    for(let file of pendingPostImageFiles) {
                        const url = await uploadToImgBB(file);
                        if(url) uploadedUrls.push(url);
                    }
                }

                const fd = new FormData();
                fd.append('content', document.getElementById('postContent').value);
                fd.append('image_url', [...new Set(uploadedUrls)].join(',')); 
                fd.append('csrf_token', csrfToken);

                btn.innerHTML = '<i class="ph ph-circle-notch spin"></i>';
                const res = await fetch(apiCall('create_post'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    document.getElementById('postContent').value = '';
                    pendingPostImageFiles = [];
                    renderMultiImagePreview();
                    showToast('Успешно опубликовано'); 
                    fireConfetti();
                    closeCreatePost();
                    if(window.location.pathname === BASE_PATH + '/' || window.location.pathname === BASE_PATH) loadFeed();
                } else showToast(data.error || 'Ошибка публикации');
            } catch(e) {
                showToast("Произошла ошибка");
            } finally { 
                btn.textContent = orig; 
                setFormState(form, false); 
                isProcessing = false; 
            }
        }

        function initTabIndicator() {
            const tabAll = document.getElementById('tab-all');
            const tabFollowing = document.getElementById('tab-following');
            const indicator = document.getElementById('tabIndicator');
            
            if(!tabAll || !indicator) return;
            
            const activeTab = activeFeedType === 'all' ? tabAll : tabFollowing;
            indicator.style.width = activeTab.offsetWidth + 'px';
            indicator.style.transform = `translateX(${activeTab.offsetLeft - 4}px)`; 
        }

        function setFeedType(type) {
            activeFeedType = type;
            document.getElementById('tab-all').classList.toggle('active', type === 'all');
            document.getElementById('tab-following').classList.toggle('active', type === 'following');
            initTabIndicator();
            loadFeed();
        }

        async function loadFeed() {
            const container = document.getElementById('feedView');
            container.innerHTML = `<div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 3.5rem; color: var(--text-muted);"></i></div>`;
            
            let currentSlug = '';
            const path = window.location.pathname;
            if (path.includes('/post/')) {
                currentSlug = path.split('/').pop();
            }
            
            try {
                const res = await fetch(apiCall('posts') + `&type=${activeFeedType}&slug=${currentSlug}`);
                const data = await res.json();
                
                if (data.error) throw new Error(data.error);
                
                const posts = data.posts || [];
                if(posts.length === 0) {
                    const isFollowing = activeFeedType === 'following';
                    const msg = isFollowing ? 'Вы еще ни на кого не подписаны' : 'Вы посмотрели все новые посты. Загляните позже!';
                    const icon = isFollowing ? 'ph-ghost' : 'ph-check-circle';
                    container.innerHTML = `<div class="empty-state smooth-fade-in"><i class="ph ${icon}" style="color:var(--text-muted); font-size:4rem;"></i><p class="mt-4" style="font-size:1.1rem; color:white;">${msg}</p></div>`;
                    return;
                }
                
                container.innerHTML = '';
                const wrapper = document.createElement('div');
                wrapper.id = 'feedWrapper';
                wrapper.className = 'feed-wrapper';
                
                let initialIndex = 0;
                if (currentSlug) {
                    const idx = posts.findIndex(p => p.slug === currentSlug);
                    if (idx !== -1) initialIndex = idx;
                }

                posts.forEach(post => { 
                    localBookmarksState[post.id] = post.is_bookmarked > 0;
                    wrapper.appendChild(createPostElement(post)); 
                    fetchCommentsForVibe(post.id); 
                });
                
                const endCard = document.createElement('div');
                endCard.className = 'post-card smooth-fade-in';
                endCard.innerHTML = `<div class="empty-state"><i class="ph ph-check-circle" style="font-size:4rem; color:var(--text-muted);"></i><p class="mt-4" style="font-size:1.1rem; color:white;">Вы посмотрели все новые посты</p><p class="text-muted mt-2 text-sm">Возвращайтесь позже за свежими обновлениями</p></div>`;
                wrapper.appendChild(endCard);

                container.appendChild(wrapper);

                currentFeedIndex = initialIndex;
                wrapper.style.transform = `translateX(-${currentFeedIndex * 100}vw)`;
                
                const initPostId = posts[currentFeedIndex]?.id;
                if (initPostId) {
                    startFloatingComments(initPostId);
                    markPostAsSeen(initPostId); 
                }

            } catch(e) { 
                console.error(e);
                container.innerHTML = `<div class="empty-state smooth-fade-in"><p>Ошибка загрузки ленты</p></div>`; 
            }
        }

        function sharePost(slug) {
            const url = window.location.origin + BASE_PATH + '/post/' + slug;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url)
                    .then(() => showToast('Ссылка на пост скопирована'))
                    .catch(() => fallbackCopyTextToClipboard(url));
            } else {
                fallbackCopyTextToClipboard(url);
            }
        }
        
        function fallbackCopyTextToClipboard(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.top = "0"; textArea.style.left = "0"; textArea.style.position = "fixed";
            document.body.appendChild(textArea);
            textArea.focus(); textArea.select();
            try {
                const successful = document.execCommand('copy');
                if (successful) showToast('Ссылка на пост скопирована');
                else showToast('Не удалось скопировать ссылку');
            } catch (err) { showToast('Не удалось скопировать ссылку'); }
            document.body.removeChild(textArea);
        }

        let currentOptionsPostId = null;
        let currentOptionsPostSlug = null;
        
        function openPostOptions(postId, slug, isMyPost) {
            currentOptionsPostId = postId;
            currentOptionsPostSlug = slug;
            
            const isBookmarked = localBookmarksState[postId];
            const bBtnText = document.getElementById('poBookmarkText');
            const bBtnIcon = document.querySelector('#poBookmarkBtn i');
            
            if (isBookmarked) {
                bBtnText.textContent = 'Убрать из сохранённого';
                bBtnIcon.className = 'ph-fill ph-bookmark text-warning';
            } else {
                bBtnText.textContent = 'Сохранить пост';
                bBtnIcon.className = 'ph ph-bookmark';
            }

            const delBtn = document.getElementById('poDeleteBtn');
            if (isMyPost) delBtn.classList.remove('hidden');
            else delBtn.classList.add('hidden');

            openModal('postOptionsModal');
        }

        async function doBookmarkFromOptions() {
            if(!requireAuthClient()) return;
            const postId = currentOptionsPostId;
            closeModal('postOptionsModal');
            
            try {
                const fd = new FormData(); fd.append('post_id', postId); fd.append('csrf_token', csrfToken);
                const res = await fetch(apiCall('toggle_bookmark'), { method: 'POST', body: fd });
                const data = await res.json();
                
                localBookmarksState[postId] = data.bookmarked;
                showToast(data.bookmarked ? 'Пост сохранён' : 'Пост убран из сохранённого');
            } catch(e) {}
        }
        
        function doShareFromOptions() {
            const slug = currentOptionsPostSlug;
            closeModal('postOptionsModal');
            sharePost(slug);
        }
        
        function doDeleteFromOptions() {
            const postId = currentOptionsPostId;
            closeModal('postOptionsModal');
            const card = document.querySelector(`.post-card[data-id="${postId}"]`);
            showConfirm('Удаление', 'Точно удалить этот пост?', () => deletePost(postId, card));
        }

        function createPostElement(post) {
            const div = document.createElement('div');
            div.className = 'post-card smooth-fade-in'; div.dataset.id = post.id; div.dataset.slug = post.slug;
            const avatar = post.avatar_url || `https://ui-avatars.com/api/?name=${post.username}&background=random`;
            const hasImages = post.image_url && post.image_url !== '';
            const isMyPost = currentUser && post.user_id === currentUser.id;
            const isLongText = post.content && post.content.length > 250;
            const safeContent = post.content ? linkify(post.content) : '';
            
            let contentHtml = '';
            
            if (hasImages) {
                const images = post.image_url.split(',');
                
                contentHtml += `<div class="absolute inset-0 bg-surface" style="z-index: 0;"></div>`;
                
                if (images.length === 1) {
                    contentHtml += `<img src="${getProxyUrl(images[0])}" class="post-img relative z-10" style="object-fit: contain; width: 100%; height: 100%; pointer-events: none;" loading="lazy" onload="this.classList.add('loaded')">`;
                } else {
                    let slidesHtml = '';
                    let dotsHtml = '';
                    images.forEach((img, idx) => {
                        slidesHtml += `<img src="${getProxyUrl(img)}" class="slider-img" loading="lazy">`;
                        dotsHtml += `<div class="slider-dot ${idx===0 ? 'active':''}"></div>`;
                    });
                    contentHtml += `
                        <div class="image-slider">${slidesHtml}</div>
                        <div class="slider-dots">${dotsHtml}</div>
                    `;
                }

                if (post.content) {
                    contentHtml += `<div class="post-overlay-bottom relative z-20 ${isLongText ? 'scrollable-overlay' : ''}">${safeContent}</div>`;
                }
            } else {
                let fontSize = post.content.length < 100 ? '2rem' : (post.content.length < 300 ? '1.4rem' : '1.1rem');
                contentHtml = `<div class="post-text-content w-full h-full flex items-center justify-center p-6 text-center relative z-10" style="font-size: ${fontSize}; overflow-y: auto;">${safeContent}</div>`;
            }

            div.innerHTML = `
                <div class="post-wrapper" ondblclick="handleDoubleTap(${post.id}, this)">
                    <div id="floatArea-${post.id}" style="position:absolute; inset:0; overflow:hidden; pointer-events:none; z-index:0;"></div>
                    ${contentHtml}
                    <div class="post-author" onclick="navigate('/profile/${post.user_id}')">
                        <img src="${avatar}" loading="lazy"><span class="author-name">${post.username}</span><span class="post-time">• ${timeAgo(post.created_at)}</span>
                    </div>
                    <div class="post-actions">
                        <div class="action-btn" onclick="toggleLike(${post.id}, this)">
                            <div class="icon-bg"><i class="${post.is_liked > 0 ? 'ph-fill text-error' : 'ph'} ph-heart" id="likeIcon-${post.id}" style="${post.is_liked > 0 ? 'color: var(--error);' : ''}"></i></div>
                            <span id="likeCount-${post.id}">${post.likes_count}</span>
                        </div>
                        <div class="action-btn" onclick="openComments(${post.id}, '${post.slug}')">
                            <div class="icon-bg"><i class="ph ph-chat-circle"></i></div>
                            <span id="commentCount-${post.id}">${post.comments_count}</span>
                        </div>
                        <div class="action-btn" onclick="openPostOptions(${post.id}, '${post.slug}', ${isMyPost})">
                            <div class="icon-bg"><i class="ph ph-dots-three"></i></div>
                        </div>
                    </div>
                </div>
            `;
            return div;
        }

        let tapTimeout = null;
        function handleDoubleTap(postId, el) {
            if(!requireAuthClient()) return;
            if (tapTimeout) return;
            let heart = el.querySelector('.double-tap-heart');
            if(!heart) { heart = document.createElement('i'); heart.className = 'ph-fill ph-heart double-tap-heart'; el.appendChild(heart); }
            heart.classList.remove('animating'); void heart.offsetWidth; heart.classList.add('animating');
            const icon = document.getElementById(`likeIcon-${postId}`);
            if(!icon.classList.contains('ph-fill')) toggleLike(postId, el.querySelector('.action-btn'), true);
            tapTimeout = setTimeout(() => { tapTimeout = null; }, 1000);
        }

        async function deletePost(postId, elementContext) {
            const fd = new FormData(); fd.append('post_id', postId); fd.append('csrf_token', csrfToken);
            try {
                const res = await fetch(apiCall('delete_post'), { method: 'POST', body: fd });
                const data = await res.json();
                if(data.success) {
                    showToast('Пост удален');
                    const card = elementContext && elementContext.classList.contains('post-card') ? elementContext : elementContext?.closest('.post-card');
                    if(card) {
                        card.style.transition = 'opacity 0.3s ease, transform 0.3s ease'; 
                        card.style.opacity = '0'; 
                        card.style.transform = 'scale(0.9)';
                        setTimeout(() => {
                            card.remove();
                            if(document.querySelectorAll('.post-card').length <= 1) navigate('/', true);
                        }, 300);
                    } else {
                        navigate('/', true);
                    }
                } else showToast(data.error || 'Ошибка удаления');
            } catch(e) { showToast('Ошибка удаления'); }
        }

        let isLiking = false;
        async function toggleLike(postId, btn, forceLike = false) {
            if(!requireAuthClient()) return;
            if (isLiking) return; isLiking = true;
            const icon = document.getElementById(`likeIcon-${postId}`), countEl = document.getElementById(`likeCount-${postId}`);
            let count = parseInt(countEl.textContent); const isLiked = icon.classList.contains('ph-fill');
            if (forceLike && isLiked) { isLiking = false; return; }
            if (isLiked) { icon.className = 'ph ph-heart'; icon.style.color = 'inherit'; countEl.textContent = count - 1; } 
            else { icon.className = 'ph-fill ph-heart'; icon.style.color = 'var(--error)'; countEl.textContent = count + 1; }
            try {
                const fd = new FormData(); fd.append('post_id', postId); fd.append('csrf_token', csrfToken);
                await fetch(apiCall('toggle_like'), { method: 'POST', body: fd });
            } finally { isLiking = false; }
        }

        async function fetchCommentsForVibe(postId) {
            const res = await fetch(apiCall('comments') + `&post_id=${postId}`);
            const data = await res.json(); postCommentsCache[postId] = data.comments; 
        }

        function startFloatingComments(postId) {
            if (floatIntervals[postId]) return;
            floatIntervals[postId] = setInterval(() => {
                const comments = postCommentsCache[postId];
                if (!comments || comments.length === 0) return;
                const area = document.getElementById(`floatArea-${postId}`);
                if (!area) return;
                const c = comments[Math.floor(Math.random() * comments.length)];
                const div = document.createElement('div'); div.className = 'floating-comment'; div.style.left = `${10 + Math.random() * 40}%`; 
                div.innerHTML = `<img src="${c.avatar_url || 'https://ui-avatars.com/api/?name='+c.username+'&background=random'}"><div class="fc-text"><span class="fc-name">${c.username}</span><span class="fc-msg">${c.content}</span></div>`;
                area.appendChild(div); setTimeout(() => { if(div.parentNode) div.remove(); }, 5000);
            }, 3000 + Math.random() * 2000); 
        }

        function stopFloatingComments(postId) { clearInterval(floatIntervals[postId]); delete floatIntervals[postId]; }

        function openComments(postId, postSlug = null) {
            currentOpenPostId = postId;
            document.getElementById('commentsList').innerHTML = '<div class="loader-screen" style="min-height: 20vh;"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>';
            if(postSlug && window.location.pathname !== BASE_PATH + `/post/${postSlug}`) {
                window.history.pushState(null, '', BASE_PATH + `/post/${postSlug}`);
            }
            openModal('commentsModal', 'commentInput');
            fetchCommentsForVibe(postId).then(renderCommentsList);
        }

        function renderCommentNode(c, isReply = false) {
            const imgHtml = c.image_url ? `<div style="margin-top: 0.6rem; border-radius: 12px; overflow: hidden; background: var(--surface); border: 1px solid var(--surface-hover); display: flex; justify-content: center;"><img src="${getProxyUrl(c.image_url)}" style="max-width: 100%; max-height: 350px; object-fit: contain; display: block;"></div>` : '';
            const textHtml = c.content ? `<div class="comment-text mt-1" style="margin-top: 4px;">${linkify(c.content)}</div>` : '';
            const likeIcon = c.is_liked > 0 ? 'ph-fill text-error' : 'ph';
            const likeStyle = c.is_liked > 0 ? 'color: var(--error);' : '';
            const canDelete = currentUser && (c.user_id === currentUser.id || isMyPost(currentOpenPostId));
            
            return `
            <div class="comment-item smooth-fade-in ${isReply ? 'is-reply' : ''}" id="comment-node-${c.id}">
                <img src="${c.avatar_url || 'https://ui-avatars.com/api/?name='+c.username+'&background=random'}" onclick="navigate('/profile/${c.user_id}'); closeModal('commentsModal');">
                <div class="comment-content">
                    <div class="comment-header">
                        <div>
                            <span class="comment-author" onclick="navigate('/profile/${c.user_id}'); closeModal('commentsModal');">${c.username}</span> 
                            <span class="comment-time">${timeAgo(c.created_at)}</span>
                        </div>
                        <i class="ph ph-trash comment-delete" style="${!canDelete ? 'display:none;' : ''}" onclick="confirmDeleteComment(${c.id})"></i>
                    </div>
                    ${textHtml}
                    ${imgHtml}
                    <div class="comment-actions">
                        <div class="c-action-btn" onclick="replyToComment(${isReply ? c.parent_id : c.id}, '${c.username}')">
                            <i class="ph ph-arrow-u-down-left"></i> Ответить
                        </div>
                        <div class="c-action-btn" onclick="toggleCommentLike(${c.id}, this)">
                            <i class="${likeIcon} ph-heart" style="${likeStyle}"></i> <span class="like-count-span" style="${likeStyle}">${c.likes_count > 0 ? c.likes_count : '0'}</span>
                        </div>
                    </div>
                </div>
            </div>`;
        }

        function isMyPost(postId) {
            const postCard = document.querySelector(`.post-card[data-id="${postId}"]`);
            if(!postCard) return false;
            return !!postCard.querySelector('.ph-dots-three');
        }

        function renderCommentsList() {
            const list = document.getElementById('commentsList');
            const comments = postCommentsCache[currentOpenPostId] || [];
            
            if(comments.length === 0) { list.innerHTML = '<div class="empty-state smooth-fade-in"><i class="ph ph-chat-teardrop"></i><p>Нет комментариев</p></div>'; return; }
            
            const parents = comments.filter(c => !c.parent_id);
            let html = '';
            
            parents.forEach(p => {
                html += renderCommentNode(p, false);
                const children = comments.filter(c => c.parent_id === p.id);
                children.forEach(child => {
                    html += renderCommentNode(child, true);
                });
            });
            
            list.innerHTML = html;
            setTimeout(() => { list.scrollTo({ top: list.scrollHeight, behavior: 'smooth' }); }, 50);
        }

        async function toggleCommentLike(commentId, btnWrapper) {
            if(!requireAuthClient()) return;
            const icon = btnWrapper.querySelector('i');
            const countSpan = btnWrapper.querySelector('.like-count-span');
            let isLiked = icon.classList.contains('ph-fill');
            let count = parseInt(countSpan.textContent) || 0;
            
            if(isLiked) {
                icon.className = 'ph ph-heart'; icon.style.color = ''; 
                countSpan.style.color = ''; countSpan.textContent = count - 1 || '0';
            } else {
                icon.className = 'ph-fill ph-heart text-error'; icon.style.color = 'var(--error)'; 
                countSpan.style.color = 'var(--error)'; countSpan.textContent = count + 1;
            }
            
            const fd = new FormData(); fd.append('comment_id', commentId); fd.append('csrf_token', csrfToken);
            try { await fetch(apiCall('toggle_comment_like'), { method: 'POST', body: fd }); } catch(e) {}
        }

        function replyToComment(id, username) {
            replyingToCommentId = id;
            document.getElementById('replyingToName').textContent = username;
            document.getElementById('replyingToIndicator').classList.remove('hidden');
            document.getElementById('commentInput').focus();
        }

        function cancelReply() {
            replyingToCommentId = null;
            document.getElementById('replyingToIndicator').classList.add('hidden');
        }

        function confirmDeleteComment(commentId) {
            showConfirm('Удаление', 'Удалить этот комментарий?', () => deleteComment(commentId));
        }

        async function deleteComment(commentId) {
            const fd = new FormData(); fd.append('id', commentId); fd.append('csrf_token', csrfToken);
            const res = await fetch(apiCall('delete_comment'), { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                postCommentsCache[currentOpenPostId] = postCommentsCache[currentOpenPostId].filter(c => c.id !== commentId);
                renderCommentsList();
                const countEl = document.getElementById(`commentCount-${currentOpenPostId}`);
                if(countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent) - 1);
            } else {
                showToast("Нет прав на удаление");
            }
        }

        let pendingCommentImageFile = null;
        function handleCommentImage(e) {
            const file = e.target.files[0];
            if(!file) return;
            if (file.size > MAX_FILE_SIZE) { showToast('Файл слишком большой. Максимум 5 МБ.'); e.target.value = ''; return; }
            pendingCommentImageFile = file;
            document.getElementById('commentImagePreview').src = URL.createObjectURL(file);
            document.getElementById('commentImagePreviewContainer').classList.remove('hidden');
        }

        function clearCommentImage() {
            pendingCommentImageFile = null;
            document.getElementById('commentImageUpload').value = '';
            document.getElementById('commentImagePreviewContainer').classList.add('hidden');
        }

        async function sendComment(e) {
            e.preventDefault();
            const form = e.target;
            if (!validateFormFields(form)) return;
            if(!requireAuthClient()) return;
            if (isProcessing) return;
            
            const btn = document.getElementById('sendCommentBtn');
            const input = document.getElementById('commentInput');
            const content = input.value.trim();
            
            if(!content && !pendingCommentImageFile) return;
            
            isProcessing = true; 
            btn.disabled = true; 
            input.disabled = true;
            btn.innerHTML = '<i class="ph ph-circle-notch spin"></i>';
            
            try {
                let imageUrl = '';
                if(pendingCommentImageFile) {
                    imageUrl = await uploadToImgBB(pendingCommentImageFile);
                }

                const fd = new FormData(); 
                fd.append('post_id', currentOpenPostId); 
                fd.append('content', content); 
                fd.append('image_url', imageUrl || '');
                fd.append('parent_id', replyingToCommentId || '');
                fd.append('csrf_token', csrfToken);
                
                const res = await fetch(apiCall('add_comment'), { method: 'POST', body: fd });
                const data = await res.json();
                
                if(data.success) {
                    input.value = ''; 
                    input.style.height = 'auto';
                    clearCommentImage();
                    cancelReply();

                    const countEl = document.getElementById(`commentCount-${currentOpenPostId}`);
                    if(countEl) countEl.textContent = parseInt(countEl.textContent) + 1;
                    
                    await fetchCommentsForVibe(currentOpenPostId);
                    renderCommentsList();
                } else {
                    showToast(data.error || 'Ошибка');
                }
            } finally { 
                btn.disabled = false; 
                input.disabled = false;
                btn.innerHTML = '<i class="ph-fill ph-paper-plane-right"></i>';
                isProcessing = false; 
                input.focus(); 
            }
        }

        window.addEventListener('resize', () => {
            initTabIndicator();
            const wrapper = document.getElementById('feedWrapper');
            if(wrapper) wrapper.style.transform = `translateX(-${currentFeedIndex * 100}vw)`;
        });
        window.onload = init;
    </script>
</body>
</html>