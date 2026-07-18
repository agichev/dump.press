<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/lib/cache.php';

require_once __DIR__ . '/lib/client.php';
require_once __DIR__ . '/lib/session.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/email.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/legal.php';
require_once __DIR__ . '/lib/sitemap.php';
require_once __DIR__ . '/lib/seo.php';
require_once __DIR__ . '/lib/s3.php';
