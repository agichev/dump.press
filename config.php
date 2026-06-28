<?php
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
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
