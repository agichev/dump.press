<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__, 2) . '/config/config.php';
$_ENV['DB_AUTO_MIGRATE'] = '1';
putenv('DB_AUTO_MIGRATE=1');
require dirname(__DIR__) . '/database.php';
echo "Database migration completed.\n";
