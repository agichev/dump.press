<?php
header('Content-Type: text/plain');

try {
    require __DIR__ . '/config/config.php';
    require_once __DIR__ . '/app/lib/client.php';
    require_once __DIR__ . '/app/lib/session.php';

    $pdo = new PDO("mysql:host={$GLOBALS['db_host']};port={$GLOBALS['db_port']};charset=utf8mb4", $GLOBALS['db_user'], $GLOBALS['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "DB connected OK\n";

    $pdo->exec("USE `{$GLOBALS['db_name']}`");
    echo "DB selected OK\n";

    // Check posts table structure
    $stmt = $pdo->query("DESCRIBE posts");
    echo "\n=== posts columns ===\n";
    while ($row = $stmt->fetch()) {
        echo "  {$row['Field']} ({$row['Type']})\n";
    }

    // Test a simple query from the feed
    echo "\n=== Testing feed query ===\n";
    $sql = "SELECT p.id, u.username,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.id) as likes_count,
        (SELECT COUNT(*) FROM comments WHERE post_id = p.id) as comments_count
        FROM posts p JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC LIMIT 3";
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch()) {
        echo "  Post {$row['id']} by {$row['username']}: {$row['likes_count']} likes, {$row['comments_count']} comments\n";
    }

    // Check for corrupt tables
    $tables = ['users', 'sessions', 'posts', 'likes', 'comments', 'follows', 'views', 'bookmarks', 'comment_likes', 'temp_auth', 'notifications', 'fcm_tokens'];
    echo "\n=== Table status ===\n";
    foreach ($tables as $t) {
        try {
            $stmt = $pdo->query("CHECK TABLE `$t`");
            $row = $stmt->fetch();
            echo "  $t: {$row['Msg_type']} - {$row['Msg_text']}\n";
        } catch (Exception $e) {
            echo "  $t: ERROR - {$e->getMessage()}\n";
        }
    }

} catch (Exception $e) {
    echo "FATAL: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
