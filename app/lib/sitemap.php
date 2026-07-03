<?php
declare(strict_types=1);

/**
 * Динамические sitemap.xml и robots.txt.
 *
 * Хостинг не позволяет редактировать собственные файлы сервиса, поэтому
 * классический статический sitemap.xml недоступен. Эти документы генерируются
 * «на лету» прямо из БД при обращении к /sitemap.xml и /robots.txt
 * (все маршруты падают на index.php, поэтому endpoint отдаётся корректно).
 */

function app_base_url(): string {
    $base = $GLOBALS['APP_URL'] ?? '';
    if ($base) return $base;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $scheme . '://' . $host . $path;
}

function url_escape(string $u): string {
    return htmlspecialchars($u, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function outputRobotsTxt(): void {
    $base = app_base_url();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /create\n";
    echo "Disallow: /login\n";
    echo "Disallow: /register\n";
    echo "\nSitemap: " . $base . "/index.php?api=sitemap\n";
    exit;
}

function outputSitemapXml(): void {
    global $pdo;
    $base = app_base_url();
    $urls = [];

    // Главная
    $urls[] = ['loc' => $base . '/', 'priority' => '1.0', 'freq' => 'hourly'];

    // Статичные документы
    foreach (['privacy-policy', 'rules'] as $slug) {
        $urls[] = ['loc' => $base . '/legal/' . $slug, 'priority' => '0.6', 'freq' => 'yearly'];
    }

    // Профили пользователей
    try {
        $stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 20000");
        foreach ($stmt->fetchAll() as $row) {
            $urls[] = ['loc' => $base . '/profile/' . (int)$row['id'], 'priority' => '0.5', 'freq' => 'weekly'];
        }
    } catch (Throwable $e) {}

    // Публикации (последние 50000)
    try {
        $stmt = $pdo->query("SELECT slug, created_at FROM posts WHERE slug IS NOT NULL AND slug <> '' ORDER BY created_at DESC LIMIT 50000");
        foreach ($stmt->fetchAll() as $row) {
            $lastmod = !empty($row['created_at']) ? date('c', strtotime((string)$row['created_at'])) : '';
            $urls[] = [
                'loc'      => $base . '/post/' . rawurlencode((string)$row['slug']),
                'priority' => '0.8',
                'freq'     => 'monthly',
                'lastmod'  => $lastmod,
            ];
        }
    } catch (Throwable $e) {}

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=1800');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as $u) {
        echo "  <url>\n";
        echo "    <loc>" . url_escape($u['loc']) . "</loc>\n";
        if (!empty($u['lastmod'])) echo "    <lastmod>" . $u['lastmod'] . "</lastmod>\n";
        echo "    <changefreq>" . $u['freq'] . "</changefreq>\n";
        echo "    <priority>" . $u['priority'] . "</priority>\n";
        echo "  </url>\n";
    }
    echo "</urlset>\n";
    exit;
}
