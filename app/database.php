<?php
declare(strict_types=1);

/**
 * Подключение к БД и авто-миграции схемы.
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

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

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

    try { $pdo->exec("CREATE UNIQUE INDEX idx_posts_slug ON posts(slug)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE FULLTEXT INDEX idx_fulltext_content ON posts(content)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_posts_user_time ON posts(user_id, created_at)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_likes_post ON likes(post_id)"); } catch (PDOException $e) {}
    try { $pdo->exec("CREATE INDEX idx_comments_post ON comments(post_id)"); } catch (PDOException $e) {}

    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_enabled TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_method VARCHAR(20) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tfa_secret VARCHAR(255) DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN bookmarks_public TINYINT(1) DEFAULT 1"); } catch (PDOException $e) {}

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

    $pdo->exec("UPDATE posts SET slug = SUBSTRING(MD5(RAND()), 1, 10) WHERE slug IS NULL OR slug = ''");

} catch (PDOException $e) {
    die("Критическая ошибка БД: Сервис временно недоступен.");
}
