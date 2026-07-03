<?php
declare(strict_types=1);

/**
 * Сборка приложения: подключение БД и регистрация всех библиотек.
 * Точка подключения — index.php.
 */

require_once __DIR__ . '/database.php';

require_once __DIR__ . '/lib/client.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/email.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/legal.php';
require_once __DIR__ . '/lib/sitemap.php';
