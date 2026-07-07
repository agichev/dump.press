<?php
header('Content-Type: text/plain');
try {
    require __DIR__ . '/config/config.php';
    require __DIR__ . '/app/bootstrap.php';
    echo "database.php OK\n";
    echo "\$pdo class: " . get_class($pdo) . "\n";

    $test = $pdo->query("SELECT 1 as test")->fetch();
    echo "Basic query: {$test['test']}\n";

    $s = $pdo->prepare("SELECT * FROM sessions WHERE token = ? AND expires_at > ?");
    $s->execute(['nonexistent', date('Y-m-d H:i:s')]);
    echo "Session query: " . ($s->fetch() ? "found" : "not found") . "\n";

    $s2 = $pdo->query("SELECT COUNT(*) as cnt FROM posts");
    echo "Posts count: " . $s2->fetch()['cnt'] . "\n";

    echo "\nALL OK\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}