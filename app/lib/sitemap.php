<?php
declare(strict_types=1);

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

    // Страница загрузки приложения
    $urls[] = ['loc' => $base . '/download', 'priority' => '0.8', 'freq' => 'monthly'];

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

    // Публикации (последние 100000)
    try {
        $stmt = $pdo->query("SELECT slug, created_at, image_url FROM posts WHERE slug IS NOT NULL AND slug <> '' ORDER BY created_at DESC LIMIT 100000");
        foreach ($stmt->fetchAll() as $row) {
            $lastmod = !empty($row['created_at']) ? date('c', strtotime((string)$row['created_at'])) : '';
            $entry = [
                'loc'      => $base . '/post/' . rawurlencode((string)$row['slug']),
                'priority' => '0.8',
                'freq'     => 'monthly',
                'lastmod'  => $lastmod,
            ];
            if (!empty($row['image_url'])) {
                $images = explode(',', $row['image_url']);
                $entry['images'] = array_map('trim', array_slice($images, 0, 5));
            }
            $urls[] = $entry;
        }
    } catch (Throwable $e) {}

    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=1800');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="https://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    foreach ($urls as $u) {
        echo "  <url>\n";
        echo "    <loc>" . url_escape($u['loc']) . "</loc>\n";
        if (!empty($u['lastmod'])) echo "    <lastmod>" . $u['lastmod'] . "</lastmod>\n";
        echo "    <changefreq>" . $u['freq'] . "</changefreq>\n";
        echo "    <priority>" . $u['priority'] . "</priority>\n";
        if (!empty($u['images'])) {
            foreach ($u['images'] as $img) {
                echo "    <image:image><image:loc>" . url_escape($img) . "</image:loc></image:image>\n";
            }
        }
        echo "  </url>\n";
    }
    echo "</urlset>\n";
    exit;
}
