<?php
declare(strict_types=1);

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
    ]);

    $pdo->exec("USE `$db_name`");

} catch (PDOException $e) {
    die("Критическая ошибка БД: Сервис временно недоступен.");
}
