<?php
declare(strict_types=1);

$action = $_GET['api'] ?? '';

function checkRateLimit(string $key, int $max, int $windowSec): bool {
    $dir = sys_get_temp_dir() . '/dump_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = "$dir/" . md5($key) . '.rl';
    $now = time();
    $data = @file_get_contents($file);
    $log = $data ? json_decode($data, true) : [];
    $log = array_filter($log, fn($t) => $t > $now - $windowSec);
    if (count($log) >= $max) return false;
    $log[] = $now;
    @file_put_contents($file, json_encode($log));
    return true;
}

/* ----------------------------------------------------------------------
 |  Медиа-прокси (отдаётся раньше JSON-блока)
 | --------------------------------------------------------------------- */
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
        if (isset($parsed['port']) && !in_array((int)$parsed['port'], [80, 443], true)) { $is_allowed_host = false; }
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

// CSRF для всех POST, кроме публичных эндпоинтов аутентификации.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['login', 'register', 'tfa_verify_login', 'register_fcm_token_native'], true)) {
    $client_csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!$current_session || !hash_equals($current_session['csrf_token'], $client_csrf)) {
        echo json_encode(['success' => false, 'error' => 'Ошибка безопасности (CSRF). Пожалуйста, обновите страницу.']);
        exit;
    }
}

$GLOBALS['__pending_pushes'] = [];

function dispatchPendingPushes(): void {
    $pushes = $GLOBALS['__pending_pushes'] ?? [];
    if (empty($pushes)) return;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    require_once __DIR__ . '/lib/push.php';
    foreach ($pushes as $args) {
        sendFcmPush(...$args);
    }
}

try {
    function createNotification($pdo, $user_id, $from_user_id, $type, $post_id = null, $post_slug = null, $allow_self = false) {
        if (!$allow_self && $user_id == $from_user_id) return;
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, from_user_id, type, post_id, post_slug) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $from_user_id, $type, $post_id, $post_slug]);
        $GLOBALS['__pending_pushes'][] = [$pdo, $user_id, $from_user_id, $type, $post_id, $post_slug];
    }

    function extractMentions($text) {
        preg_match_all('/(?<=^|\s)@([^<>"\'(){}[\]|\\\\^,.!?;:\-]+)/u', $text, $matches);
        return array_unique(array_map('trim', $matches[1]));
    }

    switch ($action) {

        /* ---------------- АУТЕНТИФИКАЦИЯ ---------------- */
        case 'register': {
            if (!checkRateLimit('reg_' . getClientIp(), 3, 3600)) {
                throw new Exception('Слишком много попыток регистрации. Попробуйте позже.');
            }
            if (!verifyRecaptcha(trim($_POST['recaptcha_token'] ?? ''))) {
                throw new Exception('Проверка капчи не пройдена. Обновите страницу.');
            }
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $password = $_POST['password'] ?? '';
            if (!$email || strlen($password) < 6) throw new Exception('Укажите корректный email и пароль (минимум 6 символов).');

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) throw new Exception('Регистрация временно недоступна. Попробуйте позже.');

            $username = 'dump_' . bin2hex(random_bytes(4));
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (email, username, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$email, $username, $hash]);
            createSession($pdo->lastInsertId());
            echo json_encode(['success' => true]);
            break;
        }

        case 'login': {
            if (!checkRateLimit('login_' . getClientIp(), 10, 60)) {
                throw new Exception('Слишком много попыток входа. Попробуйте через минуту.');
            }

            $recaptchaToken = trim($_POST['recaptcha_token'] ?? '');
            $turnstileToken = trim($_POST['turnstile_token'] ?? '');
            $isDumpApp = strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'DumpApp') !== false;

            if ($isDumpApp) {
                // DumpApp — без каптчи
            } elseif ($turnstileToken) {
                if (!verifyTurnstile($turnstileToken)) {
                    throw new Exception('Проверка капчи не пройдена.');
                }
            } else {
                if (!verifyRecaptcha($recaptchaToken)) {
                    echo json_encode(['success' => false, 'require_turnstile' => true]);
                    exit;
                }
            }
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
                if ($user['tfa_enabled']) {
                    $tempToken = bin2hex(random_bytes(32));
                    $code = '';

                    if ($user['tfa_method'] === 'email') {
                        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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
                createNotification($pdo, $user['id'], $user['id'], 'login', null, null, true);
                echo json_encode(['success' => true, 'require_2fa' => false]);
            } else {
                usleep(random_int(300000, 500000));
                throw new Exception('Неверный email или пароль.');
            }
            break;
        }

        case 'tfa_verify_login': {
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
                createNotification($pdo, $authData['user_id'], $authData['user_id'], 'login', null, null, true);
                echo json_encode(['success' => true]);
            } else {
                throw new Exception('Неверный код.');
            }
            break;
        }

        case 'logout':
            destroySession();
            echo json_encode(['success' => true]);
            break;

        case 'me':
            if ($current_session) {
                $stmt = $pdo->prepare("SELECT id, username, email, avatar_url, bio, created_at, tfa_enabled, bookmarks_public, privacy_searchable, privacy_messages, privacy_beta, captcha_required FROM users WHERE id = ?");
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

        /* ---------------- 2FA НАСТРОЙКИ ---------------- */
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
                $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
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

        /* ---------------- СЕССИИ / IP ---------------- */
        case 'update_ip':
            requireAuth();
            $ip = substr($_POST['ip'] ?? '', 0, 45);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $stmt = $pdo->prepare("UPDATE sessions SET ip_address = ? WHERE token = ?");
                $stmt->execute([$ip, $current_session['token']]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid IP']);
            }
            break;

        /* ---------------- ПОИСК ---------------- */
        case 'search': {
            $q = trim($_GET['q'] ?? '');
            if (!$q) { echo json_encode(['users' => [], 'posts' => []]); break; }

            $stmt_posts = $pdo->prepare("
                SELECT p.id, p.slug, p.content, p.image_url, u.username, u.avatar_url
                FROM posts p JOIN users u ON p.user_id = u.id
                WHERE p.content LIKE ? ORDER BY p.created_at DESC LIMIT 15
            ");
            $stmt_posts->execute(["%$q%"]);
            $posts = $stmt_posts->fetchAll();

            $stmt_users = $pdo->prepare("SELECT id, username, avatar_url FROM users WHERE username LIKE ? AND privacy_searchable = 1 LIMIT 10");
            $stmt_users->execute(["%$q%"]);
            $users = $stmt_users->fetchAll();

            foreach ($posts as &$post) {
                $post['content'] = htmlspecialchars($post['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $post['username'] = htmlspecialchars($post['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $post['avatar_url'] = htmlspecialchars($post['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $post['image_url'] = htmlspecialchars($post['image_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            foreach ($users as &$u) {
                $u['username'] = htmlspecialchars($u['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $u['avatar_url'] = htmlspecialchars($u['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            echo json_encode(['users' => $users, 'posts' => $posts]);
            break;
        }

        /* ---------------- ПРОФИЛЬ ---------------- */
        case 'user_profile': {
            $user_id = (int)($_GET['id'] ?? 0);
            $current_user_id = $current_session ? (int)$current_session['user_id'] : 0;

            $stmt = $pdo->prepare("
                SELECT id, username, avatar_url, bio, created_at, bookmarks_public,
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
            $show_bookmarks = ($current_user_id === $user_id) || ($profile['bookmarks_public'] ?? 0) == 1;
            if ($show_bookmarks) {
                $stmt_bookmarks = $pdo->prepare("
                    SELECT p.*, u.username, u.avatar_url,
                        (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as likes_count,
                        (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = ?) as is_liked,
                        (SELECT COUNT(*) FROM bookmarks WHERE post_id = p.id AND user_id = ?) as is_bookmarked,
                        (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count
                    FROM posts p
                    JOIN bookmarks b ON p.id = b.post_id
                    JOIN users u ON p.user_id = u.id
                    WHERE b.user_id = ? ORDER BY b.created_at DESC LIMIT 50
                ");
                $stmt_bookmarks->execute([$current_user_id, $current_user_id, $user_id]);
                $bookmarks = $stmt_bookmarks->fetchAll();
            }

            foreach ($posts as &$post) {
                $post['content'] = htmlspecialchars($post['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $post['username'] = htmlspecialchars($post['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $post['avatar_url'] = htmlspecialchars($post['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $post['image_url'] = htmlspecialchars($post['image_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            foreach ($bookmarks as &$bmark) {
                $bmark['content'] = htmlspecialchars($bmark['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $bmark['username'] = htmlspecialchars($bmark['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $bmark['avatar_url'] = htmlspecialchars($bmark['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $bmark['image_url'] = htmlspecialchars($bmark['image_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            echo json_encode(['profile' => $profile, 'posts' => $posts, 'bookmarks' => $bookmarks]);
            break;
        }

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
                createNotification($pdo, $following_id, $follower_id, 'follow');
                echo json_encode(['followed' => true]);
            }
            break;

        case 'get_following':
            requireAuth();
            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.avatar_url
                FROM follows f
                JOIN users u ON f.following_id = u.id
                WHERE f.follower_id = ?
                ORDER BY u.username ASC
            ");
            $stmt->execute([$current_session['user_id']]);
            $following = $stmt->fetchAll();
            foreach ($following as &$u) {
                $u['username'] = htmlspecialchars($u['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $u['avatar_url'] = htmlspecialchars($u['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            echo json_encode(['success' => true, 'following' => $following]);
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

        /* ---------------- ЛЕНТА ---------------- */
        case 'posts': {
            $type = $_GET['type'] ?? 'all';
            $limit = 35;
            $user_id = $current_session ? (int)$current_session['user_id'] : 0;
            $requested_slug = $_GET['slug'] ?? '';

            $posts = [];
            $exclude_ids = [];

            // Просмотренные посты гостя (localStorage) — исключаем из выдачи,
            // чтобы после перезагрузки/клика по лого лента не начиналась заново.
            $exclude_param = $_GET['exclude'] ?? '';
            if ($exclude_param !== '') {
                foreach (explode(',', $exclude_param) as $_pid) {
                    $_pid = (int)trim($_pid);
                    if ($_pid > 0) $exclude_ids[$_pid] = $_pid;
                }
            }

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
                    $exclude_ids[(int)$single['id']] = (int)$single['id'];
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

            foreach ($posts as &$post) {
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
        }

        /* ---------------- МЕДИА ---------------- */
        case 'upload_image':
            requireAuth();
            if (!checkRateLimit('upload_' . $current_session['user_id'], 20, 3600)) {
                throw new Exception('Слишком много загрузок. Попробуйте позже.');
            }
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Ошибка загрузки файла.');
            }
            $file = $_FILES['image'];
            if ($file['size'] > 20 * 1024 * 1024) throw new Exception('Файл слишком большой (макс 20 МБ).');

            $mime = mime_content_type($file['tmp_name']);
            if (strpos($mime, 'image/') !== 0) throw new Exception('Недопустимый формат файла.');
            if ($mime === 'image/svg+xml') throw new Exception('SVG не поддерживается.');

            $apiKey = $GLOBALS['IMGBB_API_KEY'] ?? '';
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

        /* ---------------- ПУБЛИКАЦИИ ---------------- */
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

            $uid = (int)$current_session['user_id'];

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute([$uid]);
            if ((int)$stmt->fetchColumn() >= 45) throw new Exception('Слишком много постов. Попробуйте позже.');

            $stmt = $pdo->prepare("SELECT MAX(created_at) FROM posts WHERE user_id = ?");
            $stmt->execute([$uid]);
            $last = $stmt->fetchColumn();
            if ($last && time() - strtotime($last) < 15) throw new Exception('Слишком часто. Подождите немного.');

            $slug = bin2hex(random_bytes(5));

            $stmt = $pdo->prepare("INSERT INTO posts (user_id, content, image_url, slug) VALUES (?, ?, ?, ?)");
            $stmt->execute([$uid, $content, $image_url, $slug]);
            $new_post_id = (int)$pdo->lastInsertId();
            $stmt_followers = $pdo->prepare("SELECT follower_id FROM follows WHERE following_id = ?");
            $stmt_followers->execute([$current_session['user_id']]);
            $followers = $stmt_followers->fetchAll(PDO::FETCH_COLUMN);
            foreach ($followers as $fid) {
                createNotification($pdo, (int)$fid, (int)$current_session['user_id'], 'new_post', $new_post_id, $slug);
            }

            $mentions = extractMentions($content);
            foreach ($mentions as $username) {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $mentioned_user = $stmt->fetch();
                if ($mentioned_user) {
                    createNotification($pdo, (int)$mentioned_user['id'], (int)$current_session['user_id'], 'mention', $new_post_id, $slug);
                }
            }

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
                $stmt_post = $pdo->prepare("SELECT user_id, slug FROM posts WHERE id = ?");
                $stmt_post->execute([$post_id]);
                $post_info = $stmt_post->fetch();
                if ($post_info) {
                    createNotification($pdo, (int)$post_info['user_id'], $user_id, 'like', $post_id, $post_info['slug']);
                }
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

        /* ---------------- КОММЕНТАРИИ ---------------- */
        case 'comments': {
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

            foreach ($comments as &$comment) {
                $comment['content'] = htmlspecialchars($comment['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $comment['username'] = htmlspecialchars($comment['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $comment['avatar_url'] = htmlspecialchars($comment['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $comment['image_url'] = htmlspecialchars($comment['image_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            echo json_encode(['comments' => $comments]);
            break;
        }

        case 'add_comment':
            requireAuth();
            if (!checkRateLimit('comment_' . $current_session['user_id'], 10, 60)) {
                throw new Exception('Слишком много комментариев. Подождите минуту.');
            }
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
            $stmt_post = $pdo->prepare("SELECT user_id, slug FROM posts WHERE id = ?");
            $stmt_post->execute([$post_id]);
            $post_info = $stmt_post->fetch();
            if ($post_info) {
                createNotification($pdo, (int)$post_info['user_id'], (int)$current_session['user_id'], 'comment', $post_id, $post_info['slug']);

                $mentions = extractMentions($content);
                foreach ($mentions as $username) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $mentioned_user = $stmt->fetch();
                    if ($mentioned_user) {
                        createNotification($pdo, (int)$mentioned_user['id'], (int)$current_session['user_id'], 'mention', $post_id, $post_info['slug']);
                    }
                }
            }
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

        /* ---------------- НАСТРОЙКИ ПРОФИЛЯ ---------------- */
        case 'update_profile':
            requireAuth();
            $bio = trim($_POST['bio'] ?? '');
            $avatar_url = trim($_POST['avatar_url'] ?? '');
            $bookmarks_public = isset($_POST['bookmarks_public']) && $_POST['bookmarks_public'] === '1' ? 1 : 0;
            if ($avatar_url && (!filter_var($avatar_url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $avatar_url))) { $avatar_url = ''; }

            if ($avatar_url) {
                $stmt = $pdo->prepare("UPDATE users SET bio = ?, avatar_url = ?, bookmarks_public = ? WHERE id = ?");
                $stmt->execute([$bio, $avatar_url, $bookmarks_public, $current_session['user_id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET bio = ?, bookmarks_public = ? WHERE id = ?");
                $stmt->execute([$bio, $bookmarks_public, $current_session['user_id']]);
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

            foreach ($sessions as &$s) {
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

        /* ---------------- ЮРИДИЧЕСКИЕ ДОКУМЕНТЫ ---------------- */
        case 'resolve_username': {
            $username = trim($_GET['username'] ?? '');
            if (!$username) throw new Exception('Username is required');
            $stmt = $pdo->prepare("SELECT id, username, avatar_url FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user) throw new Exception('Пользователь не найден');
            $user['username'] = htmlspecialchars($user['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $user['avatar_url'] = htmlspecialchars($user['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            echo json_encode(['success' => true, 'id' => (int)$user['id'], 'username' => $user['username'], 'avatar_url' => $user['avatar_url']]);
            break;
        }

        case 'legal': {
            $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_GET['doc'] ?? '')));
            $doc = getLegalDoc($slug);
            if (!$doc) {
                echo json_encode(['success' => false, 'error' => 'Документ не найден']);
                break;
            }
            echo json_encode([
                'success' => true,
                'slug'    => $doc['slug'],
                'title'   => $doc['title'],
                'html'    => $doc['html'],
            ]);
            break;
        }

        /* ---------------- УВЕДОМЛЕНИЯ ---------------- */
        case 'get_notifications':
            requireAuth();
            $offset = (int)($_GET['offset'] ?? 0);
            $limit = min((int)($_GET['limit'] ?? 20), 50);
            $stmt = $pdo->prepare("
                SELECT n.id, n.type, n.post_id, n.post_slug, n.is_read, n.created_at,
                    u.id as from_id, u.username as from_username, u.avatar_url as from_avatar_url
                FROM notifications n
                LEFT JOIN users u ON n.from_user_id = u.id
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$current_session['user_id'], $limit, $offset]);
            $notifications = $stmt->fetchAll();
            foreach ($notifications as &$n) {
                $n['from_username'] = htmlspecialchars($n['from_username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $n['from_avatar_url'] = htmlspecialchars($n['from_avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $n['post_slug'] = htmlspecialchars($n['post_slug'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $stmt_unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt_unread->execute([$current_session['user_id']]);
            $unread_count = (int)$stmt_unread->fetchColumn();
            echo json_encode(['success' => true, 'notifications' => $notifications, 'unread_count' => $unread_count]);
            break;

        case 'mark_notifications_read':
            requireAuth();
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $current_session['user_id']]);
            } else {
                $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$current_session['user_id']]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete_notification':
            requireAuth();
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $current_session['user_id']]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'get_unread_count':
            requireAuth();
            $last_id = (int)($_GET['last_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$current_session['user_id']]);
            $unread_count = (int)$stmt->fetchColumn();
            $new_notifications = [];
            if ($last_id > 0) {
                $stmt_new = $pdo->prepare("
                    SELECT n.id, n.type, u.username as from_username
                    FROM notifications n
                    LEFT JOIN users u ON n.from_user_id = u.id
                    WHERE n.user_id = ? AND n.id > ?
                    ORDER BY n.id DESC
                    LIMIT 5
                ");
                $stmt_new->execute([$current_session['user_id'], $last_id]);
                $new_notifications = $stmt_new->fetchAll();
                foreach ($new_notifications as &$nn) {
                    $nn['from_username'] = htmlspecialchars($nn['from_username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            echo json_encode(['success' => true, 'unread_count' => $unread_count, 'new_notifications' => $new_notifications]);
            break;

        /* ---------------- FCM TOKENS ---------------- */
        case 'register_fcm_token':
            requireAuth();
            $token = trim($_POST['token'] ?? '');
            if (strlen($token) < 50) throw new Exception('Некорректный токен');
            $pdo->prepare("DELETE FROM fcm_tokens WHERE user_id = ? AND token = ?")->execute([$current_session['user_id'], $token]);
            $stmt = $pdo->prepare("INSERT INTO fcm_tokens (user_id, token) VALUES (?, ?)");
            $stmt->execute([$current_session['user_id'], $token]);
            echo json_encode(['success' => true]);
            break;

        case 'unregister_fcm_token':
            requireAuth();
            $token = trim($_POST['token'] ?? '');
            if ($token) {
                $pdo->prepare("DELETE FROM fcm_tokens WHERE user_id = ? AND token = ?")->execute([$current_session['user_id'], $token]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'register_fcm_token_native':
            $current_session = getActiveSession();
            if (!$current_session) {
                echo json_encode(['success' => false, 'error' => 'Не авторизован']);
                break;
            }
            $token = trim($_POST['token'] ?? '');
            if (strlen($token) < 50) { echo json_encode(['success' => false, 'error' => 'Некорректный токен']); break; }
            $pdo->prepare("DELETE FROM fcm_tokens WHERE user_id = ? AND token = ?")->execute([$current_session['user_id'], $token]);
            $stmt = $pdo->prepare("INSERT INTO fcm_tokens (user_id, token) VALUES (?, ?)");
            $stmt->execute([$current_session['user_id'], $token]);
            echo json_encode(['success' => true]);
            break;

        /* ---------------- МЕССЕНДЖЕР ---------------- */
        case 'conversations':
            requireAuth();
            $stmt = $pdo->prepare("
                SELECT c.id,
                    (SELECT m.content FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message,
                    (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_message_at,
                    (SELECT m.sender_id FROM messages m WHERE m.conversation_id = c.id ORDER BY m.created_at DESC LIMIT 1) as last_sender_id,
                    (SELECT COUNT(*) FROM message_status ms JOIN messages m ON ms.message_id = m.id WHERE m.conversation_id = c.id AND ms.user_id = ? AND ms.status IN ('sent','delivered')) as unread_count,
                    cp.last_read_at
                FROM conversations c
                JOIN conversation_participants cp ON c.id = cp.conversation_id AND cp.user_id = ?
                WHERE cp.is_deleted = 0
                ORDER BY c.updated_at DESC
            ");
            $stmt->execute([$current_session['user_id'], $current_session['user_id']]);
            $conversations = $stmt->fetchAll();

            foreach ($conversations as &$conv) {
                $stmt2 = $pdo->prepare("
                    SELECT u.id, u.username, u.avatar_url
                    FROM conversation_participants cp JOIN users u ON cp.user_id = u.id
                    WHERE cp.conversation_id = ? AND cp.user_id != ?
                ");
                $stmt2->execute([$conv['id'], $current_session['user_id']]);
                $conv['participants'] = $stmt2->fetchAll();
                foreach ($conv['participants'] as &$p) {
                    $p['username'] = htmlspecialchars($p['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $p['avatar_url'] = htmlspecialchars($p['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if ($conv['last_message']) {
                    $conv['last_message'] = htmlspecialchars($conv['last_message'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            echo json_encode(['success' => true, 'conversations' => $conversations]);
            break;

        case 'messages':
            requireAuth();
            $conv_id = (int)($_GET['conversation_id'] ?? 0);
            $before = (int)($_GET['before'] ?? 0);
            $limit = min((int)($_GET['limit'] ?? 50), 100);

            $stmt_check = $pdo->prepare("SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
            $stmt_check->execute([$conv_id, $current_session['user_id']]);
            if (!$stmt_check->fetch()) throw new Exception('Доступ запрещен');

            $uid = (int)$current_session['user_id'];
            $sql = "
                SELECT m.*, u.username, u.avatar_url
                FROM messages m JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ? AND m.deleted_at IS NULL
            ";
            $params = [$conv_id];

            if ($before > 0) {
                $sql .= ' AND m.id < ?';
                $params[] = $before;
            }

            $sql .= ' ORDER BY m.id DESC LIMIT ?';
            $params[] = $limit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $messages = $stmt->fetchAll();
            $messages = array_reverse($messages);

            $msg_ids = array_column($messages, 'id');
            if (!empty($msg_ids)) {
                $in_placeholders = implode(',', array_fill(0, count($msg_ids), '?'));
                $stmt_status = $pdo->prepare("SELECT message_id, user_id, status FROM message_status WHERE message_id IN ($in_placeholders)");
                $stmt_status->execute($msg_ids);
                $status_rows = $stmt_status->fetchAll();

                foreach ($messages as &$msg) {
                    $other = null;
                    $my = null;
                    foreach ($status_rows as $sr) {
                        if ((int)$sr['message_id'] === (int)$msg['id']) {
                            if ((int)$sr['user_id'] === $uid) $my = $sr['status'];
                            else $other = $sr['status'];
                        }
                    }
                    if ((int)$msg['sender_id'] === $uid) {
                        $msg['my_status'] = $other ?: 'sent';
                    } else {
                        $msg['my_status'] = $my ?: 'sent';
                    }
                }
            }

            foreach ($messages as &$msg) {
                $msg['content'] = htmlspecialchars($msg['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $msg['username'] = htmlspecialchars($msg['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $msg['avatar_url'] = htmlspecialchars($msg['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            echo json_encode(['success' => true, 'messages' => $messages]);
            break;

        case 'update_privacy':
            requireAuth();
            $searchable = isset($_POST['privacy_searchable']) ? (int)(bool)$_POST['privacy_searchable'] : null;
            $messages = isset($_POST['privacy_messages']) ? (int)(bool)$_POST['privacy_messages'] : null;
            $beta = isset($_POST['privacy_beta']) ? (int)(bool)$_POST['privacy_beta'] : null;

            $updates = [];
            $params = [];
            if ($searchable !== null) {
                $updates[] = 'privacy_searchable = ?';
                $params[] = $searchable;
            }
            if ($messages !== null) {
                $updates[] = 'privacy_messages = ?';
                $params[] = $messages;
            }
            if ($beta !== null) {
                $updates[] = 'privacy_beta = ?';
                $params[] = $beta;
            }

            if (!empty($updates)) {
                $params[] = $current_session['user_id'];
                $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
            }

            echo json_encode(['success' => true]);
            break;

        case 'can_message':
            requireAuth();
            $target_id = (int)($_GET['user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT privacy_messages FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch();
            if (!$target) throw new Exception('Пользователь не найден');

            $is_following = false;
            $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$current_session['user_id'], $target_id]);
            $is_following = (bool)$stmt->fetch();

            echo json_encode([
                'success' => true,
                'can_message' => (bool)$target['privacy_messages'] || $is_following,
                'privacy_messages' => (bool)$target['privacy_messages'],
                'is_following' => $is_following,
            ]);
            break;

        case 'check_privacy':
            requireAuth();
            $stmt = $pdo->prepare("SELECT privacy_searchable, privacy_messages, privacy_beta FROM users WHERE id = ?");
            $stmt->execute([$current_session['user_id']]);
            $privacy = $stmt->fetch();
            echo json_encode(['success' => true, 'privacy' => $privacy]);
            break;

        case 'signal_upload':
            requireAuth();
            $uid = (int)$current_session['user_id'];
            $id_dh_pub = trim($_POST['identity_dh_pub'] ?? '');
            $id_ds_pub = trim($_POST['identity_ds_pub'] ?? '');
            $spk_pub = trim($_POST['spk_pub'] ?? '');
            $spk_sig = trim($_POST['spk_sig'] ?? '');
            $otpks_json = trim($_POST['otpks'] ?? '[]');

            if ($id_dh_pub) {
                $pdo->prepare("INSERT INTO signal_prekeys (user_id, key_id, public_key, is_signed) VALUES (?, 0, ?, 0) ON DUPLICATE KEY UPDATE public_key = ?")
                    ->execute([$uid, $id_dh_pub, $id_dh_pub]);
            }
            if ($id_ds_pub) {
                $pdo->prepare("INSERT INTO signal_prekeys (user_id, key_id, public_key, is_signed) VALUES (?, 1, ?, 0) ON DUPLICATE KEY UPDATE public_key = ?")
                    ->execute([$uid, $id_ds_pub, $id_ds_pub]);
            }
            if ($spk_pub && $spk_sig) {
                $pdo->prepare("INSERT INTO signal_prekeys (user_id, key_id, public_key, signature, is_signed) VALUES (?, 2, ?, ?, 1) ON DUPLICATE KEY UPDATE public_key = ?, signature = ?")
                    ->execute([$uid, $spk_pub, $spk_sig, $spk_pub, $spk_sig]);
            }

            $otpks = json_decode($otpks_json, true);
            if (is_array($otpks)) {
                $stmt_del = $pdo->prepare("DELETE FROM signal_prekeys WHERE user_id = ? AND is_signed = 0 AND key_id > 2 AND is_used = 0");
                $stmt_del->execute([$uid]);
                foreach ($otpks as $i => $pub) {
                    if (!is_string($pub) || !$pub) continue;
                    $kid = 100 + $i;
                    $pdo->prepare("INSERT INTO signal_prekeys (user_id, key_id, public_key, is_signed) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE public_key = ?")
                        ->execute([$uid, $kid, $pub, $pub]);
                }
            }

            echo json_encode(['success' => true]);
            break;

        case 'signal_bundle':
            requireAuth();
            $target_id = (int)($_GET['user_id'] ?? 0);
            if (!$target_id) throw new Exception('Invalid user');

            $stmt = $pdo->prepare("SELECT public_key FROM signal_prekeys WHERE user_id = ? AND key_id = 0 LIMIT 1");
            $stmt->execute([$target_id]);
            $id_dh = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT public_key FROM signal_prekeys WHERE user_id = ? AND key_id = 1 LIMIT 1");
            $stmt->execute([$target_id]);
            $id_ds = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT public_key, signature FROM signal_prekeys WHERE user_id = ? AND key_id = 2 LIMIT 1");
            $stmt->execute([$target_id]);
            $spk = $stmt->fetch();

            $otpk_pub = null;
            $otpk_id = null;
            $stmt = $pdo->prepare("SELECT id, key_id, public_key FROM signal_prekeys WHERE user_id = ? AND is_signed = 0 AND key_id > 2 AND is_used = 0 ORDER BY key_id ASC LIMIT 1");
            $stmt->execute([$target_id]);
            $otpk = $stmt->fetch();
            if ($otpk) {
                $otpk_pub = $otpk['public_key'];
                $otpk_id = $otpk['key_id'];
            }

            echo json_encode([
                'success' => true,
                'identity_dh_pub' => $id_dh ?: '',
                'identity_ds_pub' => $id_ds ?: '',
                'spk_pub' => $spk ? $spk['public_key'] : '',
                'spk_sig' => $spk ? $spk['signature'] : '',
                'otpk_pub' => $otpk_pub,
                'otpk_id' => $otpk_id,
            ]);
            break;

        case 'signal_consume':
            requireAuth();
            $otpk_id = (int)($_POST['otpk_id'] ?? 0);
            if ($otpk_id) {
                $pdo->prepare("UPDATE signal_prekeys SET is_used = 1 WHERE user_id = ? AND key_id = ?")
                    ->execute([$current_session['user_id'], $otpk_id]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'signal_key':
            requireAuth();
            $target_id = (int)($_GET['user_id'] ?? $current_session['user_id']);
            $stmt = $pdo->prepare("SELECT public_key FROM signal_prekeys WHERE user_id = ? AND key_id = 0 LIMIT 1");
            $stmt->execute([$target_id]);
            $key = $stmt->fetchColumn();
            echo json_encode(['success' => true, 'public_key' => $key ?: '']);
            break;

        case 'message_send':
            requireAuth();
            $conv_id = (int)($_POST['conversation_id'] ?? 0);
            $content = trim($_POST['content'] ?? '');
            $reply_to = !empty($_POST['reply_to']) ? (int)$_POST['reply_to'] : null;

            if (empty($content)) throw new Exception('Пустое сообщение');
            if (mb_strlen($content) > 5000) throw new Exception('Сообщение слишком длинное (максимум 5000 символов)');

            $uid = (int)$current_session['user_id'];

            $stmt_cap = $pdo->prepare("SELECT captcha_required FROM users WHERE id = ?");
            $stmt_cap->execute([$uid]);
            $cap_row = $stmt_cap->fetch();
            if ($cap_row && !empty($cap_row['captcha_required'])) {
                echo json_encode(['success' => false, 'require_captcha' => true, 'error' => 'Требуется прохождение капчи']);
                exit;
            }

            if (!checkRateLimit('chat_' . $uid, 5, 10)) {
                $pdo->prepare("UPDATE users SET captcha_required = 1 WHERE id = ?")->execute([$uid]);
                echo json_encode(['success' => false, 'require_captcha' => true, 'error' => 'Слишком много сообщений. Пройдите проверку.']);
                exit;
            }

            $stmt_check = $pdo->prepare("SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
            $stmt_check->execute([$conv_id, $uid]);
            if (!$stmt_check->fetch()) throw new Exception('Доступ запрещен');

            $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, content, reply_to) VALUES (?, ?, ?, ?)");
            $stmt->execute([$conv_id, $uid, $content, $reply_to]);
            $msg_id = (int)$pdo->lastInsertId();

            $stmt_participants = $pdo->prepare("SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?");
            $stmt_participants->execute([$conv_id, $current_session['user_id']]);
            while ($p = $stmt_participants->fetch()) {
                $pdo->prepare("INSERT INTO message_status (message_id, user_id, status) VALUES (?, ?, 'sent')")->execute([$msg_id, $p['user_id']]);
            }

            $stmt_msg = $pdo->prepare("
                SELECT m.*, u.username, u.avatar_url
                FROM messages m JOIN users u ON m.sender_id = u.id
                WHERE m.id = ?
            ");
            $stmt_msg->execute([$msg_id]);
            $message = $stmt_msg->fetch();

            $stmt_s = $pdo->prepare("SELECT user_id, status FROM message_status WHERE message_id = ?");
            $stmt_s->execute([$msg_id]);
            $srows = $stmt_s->fetchAll();
            $others = []; $my = null;
            foreach ($srows as $sr) {
                if ((int)$sr['user_id'] === $uid) $my = $sr['status'];
                else $others[] = $sr['status'];
            }
            $message['my_status'] = ((int)$message['sender_id'] === $uid)
                ? ($others[0] ?? 'sent')
                : ($my ?? 'sent');

            $message['content'] = htmlspecialchars($message['content'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $message['username'] = htmlspecialchars($message['username'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $message['avatar_url'] = htmlspecialchars($message['avatar_url'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $pdo->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$conv_id]);

            echo json_encode(['success' => true, 'message' => $message]);
            break;

        case 'check_spam_status':
            requireAuth();
            $uid = (int)$current_session['user_id'];
            $stmt = $pdo->prepare("SELECT captcha_required FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $row = $stmt->fetch();
            echo json_encode(['success' => true, 'captcha_required' => !empty($row['captcha_required'])]);
            break;

        case 'unlock_captcha':
            requireAuth();
            $uid = (int)$current_session['user_id'];
            $turnstileToken = trim($_POST['turnstile_token'] ?? '');
            if (!$turnstileToken) {
                echo json_encode(['success' => false, 'error' => 'Токен капчи не предоставлен']);
                break;
            }
            require_once __DIR__ . '/lib/security.php';
            if (!verifyTurnstile($turnstileToken)) {
                echo json_encode(['success' => false, 'error' => 'Проверка капчи не пройдена']);
                break;
            }
            $pdo->prepare("UPDATE users SET captcha_required = 0 WHERE id = ?")->execute([$uid]);
            echo json_encode(['success' => true]);
            break;

        case 'conversation_create':
            requireAuth();
            $target_id = (int)($_POST['user_id'] ?? 0);
            if ($target_id === (int)$current_session['user_id']) throw new Exception('Нельзя создать чат с собой');

            $stmt = $pdo->prepare("SELECT privacy_messages FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch();
            if (!$target) throw new Exception('Пользователь не найден');

            $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$current_session['user_id'], $target_id]);
            $is_following = (bool)$stmt->fetch();

            if (!$target['privacy_messages'] && !$is_following) {
                throw new Exception('Этот пользователь не принимает личные сообщения');
            }

            $stmt = $pdo->prepare("
                SELECT c.id FROM conversations c
                JOIN conversation_participants cp1 ON c.id = cp1.conversation_id AND cp1.user_id = ?
                JOIN conversation_participants cp2 ON c.id = cp2.conversation_id AND cp2.user_id = ?
                WHERE (SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = c.id) = 2
            ");
            $stmt->execute([$current_session['user_id'], $target_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                $pdo->prepare("UPDATE conversation_participants SET is_deleted = 0 WHERE conversation_id = ? AND user_id IN (?, ?)")
                    ->execute([$existing['id'], $current_session['user_id'], $target_id]);
                echo json_encode(['success' => true, 'conversation_id' => (int)$existing['id'], 'existing' => true]);
                break;
            }

            $pdo->prepare("INSERT INTO conversations () VALUES ()")->execute();
            $conv_id = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id) VALUES (?, ?), (?, ?)")
                ->execute([$conv_id, $current_session['user_id'], $conv_id, $target_id]);

            echo json_encode(['success' => true, 'conversation_id' => $conv_id, 'existing' => false]);
            break;

        case 'can_start_conversation':
            requireAuth();
            $target_id = (int)($_GET['user_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT privacy_messages FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $target = $stmt->fetch();
            if (!$target) throw new Exception('Пользователь не найден');

            $stmt = $pdo->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$current_session['user_id'], $target_id]);
            $is_following = (bool)$stmt->fetch();

            echo json_encode([
                'success' => true,
                'can_message' => (bool)$target['privacy_messages'] || $is_following,
            ]);
            break;

        case 'leave_conversation':
            requireAuth();
            $conv_id = (int)($_POST['conversation_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE conversation_participants SET is_deleted = 1 WHERE conversation_id = ? AND user_id = ?");
            $stmt->execute([$conv_id, $current_session['user_id']]);
            echo json_encode(['success' => true]);
            break;

        case 'clear_conversation':
            requireAuth();
            $conv_id = (int)($_POST['conversation_id'] ?? 0);
            $stmt_check = $pdo->prepare("SELECT 1 FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
            $stmt_check->execute([$conv_id, $current_session['user_id']]);
            if (!$stmt_check->fetch()) throw new Exception('Доступ запрещен');
            $pdo->prepare("DELETE FROM messages WHERE conversation_id = ?")->execute([$conv_id]);
            echo json_encode(['success' => true]);
            break;

        case 'block_user':
            requireAuth();
            $blocked_id = (int)($_POST['user_id'] ?? 0);
            $conv_id = (int)($_POST['conversation_id'] ?? 0);
            if ($blocked_id === (int)$current_session['user_id']) throw new Exception('Нельзя заблокировать себя');
            $pdo->prepare("INSERT IGNORE INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)")
                ->execute([$current_session['user_id'], $blocked_id]);
            if ($conv_id > 0) {
                $pdo->prepare("UPDATE conversation_participants SET is_deleted = 1 WHERE conversation_id = ? AND user_id = ?")
                    ->execute([$conv_id, $current_session['user_id']]);
            }
            echo json_encode(['success' => true]);
            break;

        /* ---------------- SITEMAP / ROBOTS ---------------- */
        case 'sitemap':
            require_once __DIR__ . '/lib/sitemap.php';
            outputSitemapXml();
            break;

        case 'robots':
            require_once __DIR__ . '/lib/sitemap.php';
            outputRobotsTxt();
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'API route not found']);
    }
} catch (PDOException $e) {
    error_log("DB_ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка базы данных']);
} catch (Exception $e) {
    error_log("API_ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log("FATAL: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
dispatchPendingPushes();
exit;
