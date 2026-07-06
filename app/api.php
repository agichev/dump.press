<?php
declare(strict_types=1);

$action = $_GET['api'] ?? '';

/* ----------------------------------------------------------------------
 |  Медиа-прокси (отдаётся раньше JSON-блока)
 | --------------------------------------------------------------------- */
if ($action === 'proxy') {
    $url = base64_decode($_GET['url'] ?? '');
    $parsed = parse_url($url);
    $is_allowed_host = false;
    if (isset($parsed['host'])) {
        $allowed_domains = ['ibb.co', 'i.ibb.co', 'imgbb.com', 'i.imgbb.com', 'ui-avatars.com', 'www.google.com'];
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

// CSRF для всех POST, кроме публичных эндпоинтов аутентификации.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['login', 'register', 'tfa_verify_login'], true)) {
    $client_csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!$current_session || !hash_equals($current_session['csrf_token'], $client_csrf)) {
        echo json_encode(['success' => false, 'error' => 'Ошибка безопасности (CSRF). Пожалуйста, обновите страницу.']);
        exit;
    }
}

try {
    function createNotification($pdo, $user_id, $from_user_id, $type, $post_id = null, $post_slug = null, $allow_self = false) {
        if (!$allow_self && $user_id == $from_user_id) return;
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, from_user_id, type, post_id, post_slug) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $from_user_id, $type, $post_id, $post_slug]);
    }

    switch ($action) {

        /* ---------------- АУТЕНТИФИКАЦИЯ ---------------- */
        case 'register': {
            // Капча Cloudflare Turnstile (в модалке на клиенте).
            if (!verifyTurnstile(trim($_POST['turnstile_token'] ?? ''), getClientIp())) {
                throw new Exception('Проверка капчи не пройдена. Обновите страницу.');
            }
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
        }

        case 'login': {
            if (!verifyTurnstile(trim($_POST['turnstile_token'] ?? ''), getClientIp())) {
                throw new Exception('Проверка капчи не пройдена. Обновите страницу.');
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
                $stmt = $pdo->prepare("SELECT id, username, email, avatar_url, bio, created_at, tfa_enabled, bookmarks_public FROM users WHERE id = ?");
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

            $stmt_users = $pdo->prepare("SELECT id, username, avatar_url FROM users WHERE username LIKE ? LIMIT 10");
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
            if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Ошибка загрузки файла.');
            }
            $file = $_FILES['image'];
            if ($file['size'] > 20 * 1024 * 1024) throw new Exception('Файл слишком большой (макс 20 МБ).');

            $mime = mime_content_type($file['tmp_name']);
            if (strpos($mime, 'image/') !== 0) throw new Exception('Недопустимый формат файла.');

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

            $slug = bin2hex(random_bytes(5));

            $stmt = $pdo->prepare("INSERT INTO posts (user_id, content, image_url, slug) VALUES (?, ?, ?, ?)");
            $stmt->execute([$current_session['user_id'], $content, $image_url, $slug]);
            $new_post_id = (int)$pdo->lastInsertId();
            $stmt_followers = $pdo->prepare("SELECT follower_id FROM follows WHERE following_id = ?");
            $stmt_followers->execute([$current_session['user_id']]);
            $followers = $stmt_followers->fetchAll(PDO::FETCH_COLUMN);
            foreach ($followers as $fid) {
                createNotification($pdo, (int)$fid, (int)$current_session['user_id'], 'new_post', $new_post_id, $slug);
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
            $stmt = $pdo->prepare("
                SELECT n.id, n.type, n.post_id, n.post_slug, n.is_read, n.created_at,
                    u.id as from_id, u.username as from_username, u.avatar_url as from_avatar_url
                FROM notifications n
                LEFT JOIN users u ON n.from_user_id = u.id
                WHERE n.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$current_session['user_id']]);
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
            $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$current_session['user_id']]);
            echo json_encode(['success' => true]);
            break;

        case 'get_unread_count':
            requireAuth();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$current_session['user_id']]);
            echo json_encode(['success' => true, 'unread_count' => (int)$stmt->fetchColumn()]);
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
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log("FATAL: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера']);
}
exit;
