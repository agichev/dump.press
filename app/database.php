<?php
declare(strict_types=1);

/**
 * Подключение к БД.
 * Миграции запускаются отдельно: php app/tools/migrate.php
 */

$db_host     = $GLOBALS['db_host'];
$db_port     = $GLOBALS['db_port'];
$db_name     = $GLOBALS['db_name'];
$db_user     = $GLOBALS['db_user'];
$db_password = $GLOBALS['db_password'];

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;charset=utf8mb4", $db_user, $db_password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true,
    ]);

    // Схема не должна проверяться и изменяться на каждом HTTP-запросе.
    if (!env_bool('DB_AUTO_MIGRATE', false)) {
        $pdo->exec("USE `$db_name`");
        $pdo->exec("SET time_zone = '+00:00'");
    } else {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");
    $pdo->exec("SET time_zone = '+00:00'");

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
            ws_token VARCHAR(128) NULL,
            expires_at DATETIME NOT NULL,
            user_agent VARCHAR(255) DEFAULT '',
            ip_address VARCHAR(45) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY (ws_token)
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

    try { $pdo->exec("CREATE UNIQUE INDEX idx_posts_slug ON posts(slug)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE FULLTEXT INDEX idx_fulltext_content ON posts(content)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_posts_user_time ON posts(user_id, created_at)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_posts_created ON posts(created_at, id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_likes_post ON likes(post_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_comments_post ON comments(post_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_comments_post_created ON comments(post_id, created_at)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_follows_following_follower ON follows(following_id, follower_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_views_post ON views(post_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_sessions_expiry ON sessions(expires_at)"); } catch (PDOException $e) {}

    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_enabled TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_method VARCHAR(20) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_secret VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN bookmarks_public TINYINT(1) DEFAULT 1"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN privacy_searchable TINYINT(1) DEFAULT 1"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN privacy_messages TINYINT(1) DEFAULT 1"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN privacy_beta TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN captcha_required TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN privacy_no_ads TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN privacy_no_track TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN pending_email VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE sessions ADD COLUMN ws_token VARCHAR(128) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE sessions ADD UNIQUE KEY (ws_token)"); } catch (PDOException $e) {}
    try {
        $stmt = $pdo->prepare("SELECT token FROM sessions WHERE ws_token IS NULL OR ws_token = ''");
        $stmt->execute();
        $upd = $pdo->prepare("UPDATE sessions SET ws_token = ? WHERE token = ?");
        foreach ($stmt->fetchAll() as $row) {
            $upd->execute([bin2hex(random_bytes(32)), $row['token']]);
        }
    } catch (PDOException $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS conversation_participants (
            conversation_id INT NOT NULL,
            user_id INT NOT NULL,
            last_read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_deleted TINYINT(1) DEFAULT 0,
            PRIMARY KEY (conversation_id, user_id),
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS messages (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            sender_id INT NOT NULL,
            content TEXT,
            reply_to BIGINT DEFAULT NULL,
            edited_at TIMESTAMP NULL DEFAULT NULL,
            deleted_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (reply_to) REFERENCES messages(id) ON DELETE SET NULL,
            INDEX idx_conv_created (conversation_id, created_at),
            INDEX idx_conv_sender (conversation_id, sender_id)
        );
        CREATE TABLE IF NOT EXISTS message_status (
            message_id BIGINT NOT NULL,
            user_id INT NOT NULL,
            status ENUM('sent','delivered','read') DEFAULT 'sent',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id, user_id),
            FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");
    try { $pdo->exec("CREATE INDEX idx_msgs_conv ON messages(conversation_id, created_at)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_msgs_sender ON messages(sender_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_cp_user ON conversation_participants(user_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_cp_conv ON conversation_participants(conversation_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_ms_status ON message_status(message_id)"); } catch (PDOException $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gifs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            image_url TEXT NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");
    try { $pdo->exec("CREATE INDEX idx_gifs_desc ON gifs(description(255))"); } catch (PDOException $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS temp_auth (
            token VARCHAR(128) PRIMARY KEY,
            user_id INT NOT NULL,
            code VARCHAR(64) DEFAULT '',
            type VARCHAR(20) DEFAULT '',
            expires_at DATETIME NOT NULL
        );
    ");

    try { $pdo->exec("ALTER TABLE temp_auth MODIFY COLUMN code VARCHAR(64) DEFAULT ''"); } catch (PDOException $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            from_user_id INT DEFAULT NULL,
            type VARCHAR(30) NOT NULL,
            post_id INT DEFAULT NULL,
            post_slug VARCHAR(64) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            INDEX idx_notif_user (user_id, is_read, created_at)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fcm_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY (token)
        );
    ");
    try { $pdo->exec("CREATE INDEX idx_notifications_user_created ON notifications(user_id, created_at, id)"); } catch (PDOException $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blocked_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            blocker_id INT NOT NULL,
            blocked_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_block (blocker_id, blocked_id)
        );
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_messages (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            sender_id INT NOT NULL,
            content TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    try { $pdo->exec("ALTER TABLE conversation_participants ADD COLUMN muted TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS uploaded_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            file_name VARCHAR(500) NOT NULL,
            file_size BIGINT NOT NULL,
            file_type VARCHAR(255) NOT NULL,
            r2_key VARCHAR(500) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_uf_user (user_id, created_at),
            INDEX idx_uf_expires (expires_at)
        );
    ");

    $pdo->exec("UPDATE posts SET slug = SUBSTRING(MD5(RAND()), 1, 10) WHERE slug IS NULL OR slug = ''");
    }

} catch (PDOException $e) {
    die("Критическая ошибка БД: Сервис временно недоступен.");
}
