<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/app/bootstrap.php';

$req_path_raw = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '\\/');
$_req = $req_path_raw;
if ($base_path && strpos($_req, $base_path) === 0) {
    $_req = substr($_req, strlen($base_path));
}
$_req = trim($_req, '/');

if ($_req === 'robots.txt') {
    outputRobotsTxt();
}
if ($_req === 'sitemap.xml' || isset($_GET['sitemap'])) {
    outputSitemapXml();
}
if ($_req === 'download') {
    require __DIR__ . '/app/download.php';
    exit;
}

if (isset($_GET['api'])) {
    require __DIR__ . '/app/api.php';
    exit;
}

$path_parts = explode('/', $_req);

$recaptcha_site_key   = $GLOBALS['RECAPTCHA_V3_SITE_KEY'] ?? '';
$recaptcha_secret_key = $GLOBALS['RECAPTCHA_V3_SECRET_KEY'] ?? '';
$recaptcha_enabled    = $recaptcha_site_key !== '' && $recaptcha_secret_key !== '';
$turnstile_site_key   = $GLOBALS['TURNSTILE_SITE_KEY'] ?? '';

$random_titles = ["Dump", "Настоящий Dump"];
$seo_title = $random_titles[array_rand($random_titles)];
$seo_desc = "Dump — социальная сеть, где можно делиться фотографиями, мыслями и находить интересный контент. Присоединяйтесь к сообществу Dump.";
$seo_image = '';
$seo_keywords = 'Dump, социальная сеть, dump соцсеть, социальная сеть дамп, дамп, dump.press, фото, контент, общение, социальная платформа';
$seo_jsonld = '';
$legal_prerender = ['slug' => '', 'title' => '', 'html' => ''];
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$current_url = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'];
$base_url = app_base_url();
$og_image_url = $base_url . '/watchindump.png';
$seo_image = $og_image_url;

try {

    if (isset($path_parts[0]) && $path_parts[0] === 'post' && !empty($path_parts[1])) {
        $slug = $path_parts[1];
        $postCacheKey = 'seo:post:' . hash('sha256', $slug);
        $post = dumpCacheGetJson($postCacheKey);
        if ($post === null) {
            $stmt = $pdo->prepare("SELECT p.content, p.image_url, p.created_at, u.username FROM posts p JOIN users u ON p.user_id = u.id WHERE p.slug = ?");
            $stmt->execute([$slug]);
            $post = $stmt->fetch() ?: [];
            if ($post) dumpCacheSetJson($postCacheKey, $post, 60);
        }
        if ($post) {
            $seo_title = "Публикация от @" . $post['username'] . " | Dump";
            $text_clean = trim(preg_replace('/\s+/', ' ', strip_tags($post['content'])));
            if ($text_clean) {
                $seo_desc = mb_substr($text_clean, 0, 150) . (mb_strlen($text_clean) > 150 ? '...' : '');
            }
            $seo_keywords = generateSeoKeywords($text_clean);
            $seo_jsonld = buildJsonLd('article', [
                'title' => $seo_title,
                'description' => $seo_desc,
                'image' => $og_image_url,
                'author' => '@' . $post['username'],
                'date' => !empty($post['created_at']) ? date('c', strtotime($post['created_at'])) : date('c'),
                'url' => $current_url,
                'keywords' => $seo_keywords,
            ]);
        }
    } elseif (isset($path_parts[0]) && $path_parts[0] === 'profile' && !empty($path_parts[1])) {
        $uid = (int)$path_parts[1];
        $userCacheKey = 'seo:profile:' . $uid;
        $user = dumpCacheGetJson($userCacheKey);
        if ($user === null) {
            $stmt = $pdo->prepare("SELECT username, bio, avatar_url FROM users WHERE id = ?");
            $stmt->execute([$uid]);
            $user = $stmt->fetch() ?: [];
            if ($user) dumpCacheSetJson($userCacheKey, $user, 60);
        }
        if ($user) {
            $seo_title = "@" . $user['username'] . " | Профиль Dump";
            $bio_clean = trim(preg_replace('/\s+/', ' ', strip_tags((string)$user['bio'])));
            $seo_desc = $bio_clean ? (mb_substr($bio_clean, 0, 150) . '...') : "Смотрите публикации пользователя @" . $user['username'] . " на Dump.";
            $seo_keywords = 'профиль, @' . $user['username'] . ', ' . ($bio_clean ? mb_substr($bio_clean, 0, 60) : 'Dump');
            $seo_jsonld = buildJsonLd('profile', [
                'title' => $seo_title,
                'description' => $seo_desc,
                'image' => $og_image_url,
                'username' => $user['username'],
            ]);
        }
    } elseif (isset($path_parts[0]) && $path_parts[0] === 'legal' && !empty($path_parts[1])) {
        $doc = getLegalDoc(preg_replace('/[^a-z0-9-]/', '', $path_parts[1]));
        if ($doc) {
            $legal_prerender = $doc;
            $seo_title = $doc['title'] . " | Dump";
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($doc['html'])));
            $seo_desc = $plain ? (mb_substr($plain, 0, 150) . '...') : $doc['title'];
            $seo_keywords = $doc['title'] . ', Dump, правила, политика конфиденциальности';
        }
    } else {
        $seo_jsonld = buildJsonLd('website') . "\n    " . buildJsonLd('organization');
    }
} catch (Exception $e) {}

$asset_base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

$is_dump_app = strpos($_SERVER['HTTP_USER_AGENT'] ?? '', 'DumpApp') !== false;
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_mobile = !$is_dump_app && preg_match('/Android.*Mobile|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $ua);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="<?= $is_dump_app ? 'width=390, initial-scale=1.0, maximum-scale=1.0, user-scalable=no' : 'width=device-width, initial-scale=1.0, viewport-fit=cover' ?>">
    <title><?= htmlspecialchars($seo_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></title>

    <meta name="description" content="<?= htmlspecialchars($seo_desc, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($seo_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seo_desc, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($seo_image, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="Dump">
    <meta property="og:url" content="<?= htmlspecialchars($protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@dump">
    <meta name="keywords" content="<?= htmlspecialchars($seo_keywords, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
    <link rel="canonical" href="<?= htmlspecialchars(rtrim(app_base_url(), '/') . '/' . ltrim($_req, '/'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">

    <link rel="preconnect" href="https://unpkg.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://www.google.com">
    <link rel="preconnect" href="https://www.clarity.ms">
    <link rel="preconnect" href="https://i.ibb.co">
    <link rel="dns-prefetch" href="https://i.ibb.co">
    <link rel="dns-prefetch" href="https://ui-avatars.com">

    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='50' cy='50' r='50' fill='%23000000'/><text x='50' y='55' dominant-baseline='middle' text-anchor='middle' font-size='76' font-family='-apple-system, BlinkMacSystemFont, sans-serif' font-weight='800' fill='%23ffffff'>D</text></svg>">
    <link rel="icon" href="<?= htmlspecialchars($asset_base) ?>/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($asset_base) ?>/logo.png">
    <link rel="manifest" href="<?= htmlspecialchars($asset_base) ?>/site.webmanifest">
    <meta name="theme-color" content="#000000">
    <meta name="msapplication-TileColor" content="#000000">
    <meta name="msapplication-TileImage" content="<?= htmlspecialchars($asset_base) ?>/favicon.ico">

    <link rel="stylesheet" type="text/css" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/fill/style.css">

    <link rel="preload" href="<?= htmlspecialchars($asset_base) ?>/style.css?v=50" as="style">
    <link rel="preload" href="<?= htmlspecialchars($asset_base) ?>/script.js?v=50" as="script">
    <link rel="stylesheet" href="<?= htmlspecialchars($asset_base) ?>/style.css?v=50">

    <?php if ($recaptcha_enabled): ?>
    <style>.grecaptcha-badge{visibility:hidden!important;opacity:0!important}</style>
    <script src="https://www.google.com/recaptcha/api.js?render=<?= htmlspecialchars($recaptcha_site_key, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" async defer></script>
    <?php endif; ?>
    <?php if ($turnstile_site_key): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
    <?php if ($seo_jsonld): ?>
    <?= $seo_jsonld ?>
    <?php endif; ?>
    <?php if ($is_dump_app): ?>
    <style>.bottom-nav{display:flex!important}#mainNav .icon-btn{display:none!important}#navBackBtn{display:flex!important}#mainNav.show-notif-btn #navNotifBtn{display:flex!important}.post-wrapper{max-width:100%!important;width:100%!important;border-radius:0!important}body{padding-bottom:56px!important}</style>
    <?php endif; ?>
    <!-- Yandex.Metrika counter -->
    <script type="text/javascript">
        (function(m,e,t,r,i,k,a){
            m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
            m[i].l=1*new Date();
            for (var j = 0; j < document.scripts.length; j++) {if (document.scripts[j].src === r) { return; }}
            k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)
        })(window, document,'script','https://mc.yandex.ru/metrika/tag.js?id=110594045', 'ym');

        ym(110594045, 'init', {ssr:true, webvisor:true, clickmap:true, ecommerce:"dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce:true, trackLinks:true});
    </script>
    <noscript><div><img src="https://mc.yandex.ru/watch/110594045" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
    <!-- /Yandex.Metrika counter -->
    <!-- Top.Mail.Ru counter -->
    <script type="text/javascript">
    var _tmr = window._tmr || (window._tmr = []);
    _tmr.push({id: "3779137", type: "pageView", start: (new Date()).getTime()});
    (function (d, w, id) {
      if (d.getElementById(id)) return;
      var ts = d.createElement("script"); ts.type = "text/javascript"; ts.async = true; ts.id = id;
      ts.src = "https://top-fwz1.mail.ru/js/code.js";
      var f = function () {var s = d.getElementsByTagName("script")[0]; s.parentNode.insertBefore(ts, s);};
      if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); }
    })(document, window, "tmr-code");
    </script>
    <noscript><div><img src="https://top-fwz1.mail.ru/counter?id=3779137;js=na" style="position:absolute;left:-9999px;" alt="Top.Mail.Ru" /></div></noscript>
    <!-- /Top.Mail.Ru counter -->
</head>
<body>
    <script>
        const BASE_PATH = '<?php echo rtrim(dirname($_SERVER["SCRIPT_NAME"]), "\\/"); ?>';
        const apiCall = (action) => BASE_PATH + '/index.php?api=' + action;
        const MAX_FILE_SIZE = 20 * 1024 * 1024;

        const RECAPTCHA_ENABLED = <?php echo $recaptcha_enabled ? 'true' : 'false'; ?>;
        window.RecaptchaSiteKey = '<?php echo htmlspecialchars($recaptcha_site_key, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>';
        const TURNSTILE_SITE_KEY = '<?php echo htmlspecialchars($turnstile_site_key, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>';
        const WS_URL = '<?php echo htmlspecialchars($GLOBALS['WS_URL'] ?? 'ws://localhost:9090', ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>';

        const getProxyUrl = (url) => {
            if (!url) return '';
            if (url.includes('/index.php?api=proxy')) return url;
            try { return BASE_PATH + '/index.php?api=proxy&url=' + encodeURIComponent(btoa(url)); }
            catch(e) { return url; }
        };

        // Определяем реальный IPv4 клиента через внешнее IPv4-only API.
        let __clientIp = '';
        let __clientIpPromise = fetch('https://checkip.amazonaws.com/', { mode: 'cors' })
            .then(r => r.text())
            .then(t => { window.__clientIp = t.trim(); })
            .catch(() => {});
        const __waitForIp = () => Promise.race([
            __clientIpPromise,
            new Promise(r => setTimeout(r, 3000))
        ]);

        function fireConfetti() {
            const colors = ['#ff2a5f', '#f5a623', '#ffffff', '#60a5fa', '#34d399'];
            const canvas = document.createElement('canvas');
            canvas.style.position = 'fixed';
            canvas.style.top = '0'; canvas.style.left = '0';
            canvas.style.width = '100vw'; canvas.style.height = '100vh';
            canvas.style.pointerEvents = 'none'; canvas.style.zIndex = '999999';
            document.body.appendChild(canvas);
            const ctx = canvas.getContext('2d');
            let w = window.innerWidth, h = window.innerHeight;
            canvas.width = w; canvas.height = h;
            const pieces = [];
            for(let i=0; i<80; i++) {
                pieces.push({
                    x: w / 2, y: h / 2,
                    vx: (Math.random() - 0.5) * (w / 25), vy: (Math.random() - 1) * (h / 35) - 5,
                    size: Math.random() * 10 + 6, color: colors[Math.floor(Math.random() * colors.length)],
                    rot: Math.random() * 360, rotSpeed: (Math.random() - 0.5) * 10
                });
            }
            function animate() {
                ctx.clearRect(0, 0, w, h);
                let active = false;
                pieces.forEach(p => {
                    p.x += p.vx; p.y += p.vy; p.vy += 0.4; p.rot += p.rotSpeed;
                    if(p.y < h + 50) active = true;
                    ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.rot * Math.PI / 180);
                    ctx.fillStyle = p.color; ctx.fillRect(-p.size/2, -p.size/2, p.size, p.size); ctx.restore();
                });
                if(active) requestAnimationFrame(animate); else canvas.remove();
            }
            animate();
        }
    </script>
    <div id="toastContainer"></div>

    <?php if ($legal_prerender['html'] !== ''): ?>
    <div id="legalCache-<?= htmlspecialchars($legal_prerender['slug'], ENT_QUOTES) ?>"
         data-title="<?= htmlspecialchars($legal_prerender['title'], ENT_QUOTES) ?>"
         data-html="<?= htmlspecialchars($legal_prerender['html'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
         style="display:none;"></div>
    <?php endif; ?>

    <div id="mainNav" class="nav-bar">
        <div class="flex items-center gap-2">
            <button id="navBackBtn" class="icon-btn hidden" onclick="window.history.back()"><i class="ph ph-arrow-left"></i></button>
            <h1 class="nav-brand dump-logo" id="navLogo" onclick="goHome()">Dump</h1>
        </div>

        <div class="nav-tabs hidden" id="feedTabs">
            <div id="tabIndicator" class="tab-indicator"></div>
            <button onclick="handleFeedTabClick('all')" id="tab-all" class="nav-tab active">Глобально</button>
            <button onclick="handleFeedTabClick('following')" id="tab-following" class="nav-tab">Подписки</button>
        </div>
        <div class="flex gap-2">
            <button onclick="openSearch()" class="icon-btn"><i class="ph ph-magnifying-glass"></i></button>
            <button onclick="navigate('/messages')" id="navMsgBtn" class="icon-btn hidden" style="position:relative;"><i class="ph ph-chat-circle"></i><span id="msgBadgeTop" class="notif-badge hidden">0</span></button>
            <button onclick="navigate('/notifications')" id="navNotifBtn" class="icon-btn hidden" style="position:relative;"><i class="ph ph-bell"></i><span id="notifBadge" class="notif-badge hidden">0</span></button>
            <button onclick="navigate('/create')" id="navCreateBtn" class="icon-btn"><i class="ph ph-plus"></i></button>
            <button id="navUserBtn" class="icon-btn"><i class="ph ph-user"></i></button>
        </div>
    </div>

    <a id="bannerLeft" href="https://my.govnohost.net/aff.php?aff=9" target="_blank" rel="nofollow noopener" class="left-banner">
        <img src="/banner.jpg" alt="" width="1037" height="1517" loading="lazy">
    </a>

    <div id="loginView" class="view-section auth-container flex items-center justify-center">
        <div class="auth-card">
            <div class="text-center mb-8">
                <h2 class="dump-logo" style="font-size: 4.2rem;">Dump</h2>
                <p class="text-muted mt-2">Войдите в свой аккаунт</p>
            </div>
            <form onsubmit="handleAuth(event, 'login')" novalidate>
                <div class="input-group">
                    <input type="email" name="email" id="loginEmail" class="vc-input" placeholder=" " required autocomplete="username">
                    <label for="loginEmail" class="vc-label">Email</label>
                </div>
                <div class="input-group">
                    <input type="password" name="password" id="loginPassword" class="vc-input" placeholder=" " required autocomplete="current-password">
                    <label for="loginPassword" class="vc-label">Пароль</label>
                </div>
                <button type="submit" class="vc-btn mb-4">Войти</button>
                <div class="text-center">
                    <button type="button" class="vc-btn-text" onclick="navigate('/register')">У меня еще нет аккаунта</button>
                </div>
            </form>
            <div class="auth-footer-links">
                <a class="legal-link" onclick="event.preventDefault();openLegal('rules')">Правила</a>
                <span class="text-muted">·</span>
                <a class="legal-link" onclick="event.preventDefault();openLegal('privacy-policy')">Политика конфиденциальности</a>
            </div>
        </div>
    </div>

    <div id="registerView" class="view-section auth-container flex items-center justify-center">
        <div class="auth-card">
            <div class="text-center mb-8">
                <h2 class="dump-logo" style="font-size: 4.2rem;">Dump</h2>
                <p class="text-muted mt-2">Присоединяйтесь к Dump</p>
            </div>
            <form onsubmit="handleAuth(event, 'register')" novalidate>
                <div class="input-group">
                    <input type="email" name="email" id="regEmail" class="vc-input" placeholder=" " required autocomplete="username">
                    <label for="regEmail" class="vc-label">Ваш Email</label>
                </div>
                <div class="input-group">
                    <input type="password" name="password" id="regPassword" class="vc-input" placeholder=" " required minlength="8" autocomplete="new-password">
                    <label for="regPassword" class="vc-label">Пароль</label>
                </div>
                <button type="submit" class="vc-btn">Создать аккаунт</button>
                <p class="legal-consent" style="color:#444;font-size:0.72rem;margin:0.75rem 0;border:none;padding:0;line-height:1.4;text-align:center">
                    Нажимая «Создать аккаунт», вы соглашаетесь с
                    <a class="legal-link" href="#" onclick="event.preventDefault();openLegal('rules')" style="color:#555;font-size:0.72rem">Правилами</a> и
                    <a class="legal-link" href="#" onclick="event.preventDefault();openLegal('privacy-policy')" style="color:#555;font-size:0.72rem">Политикой конфиденциальности</a>.
                </p>
            </form>
            <div class="auth-footer-links">
                <button type="button" class="vc-btn-text" onclick="navigate('/login')" style="margin-top:0">Я уже зарегистрирован</button>
            </div>
        </div>
    </div>

    <div id="feedView" class="view-section feed-container"></div>
    <div id="profileView" class="view-section profile-container"></div>

    <div id="postOptionsModal" class="modal-overlay modal-bottom" onclick="closeModalOnOutsideClick(event, 'postOptionsModal')">
        <div class="modal-content" style="padding-bottom: 2rem; max-height: auto; height: auto;">
            <div class="flex justify-center mb-6"><div style="width:40px; height:5px; background:var(--surface-hover); border-radius:4px;"></div></div>
            <div class="flex flex-col gap-3">
                <button class="vc-btn-outline flex items-center justify-start gap-3" style="border:none; background:var(--surface-elevated); padding: 18px 24px;" id="poBookmarkBtn" onclick="doBookmarkFromOptions()">
                    <i class="ph ph-bookmark" style="font-size:1.5rem;"></i> <span id="poBookmarkText" style="font-size:1.05rem;">Сохранить</span>
                </button>
                <button class="vc-btn-outline flex items-center justify-start gap-3" style="border:none; background:var(--surface-elevated); padding: 18px 24px;" onclick="doShareFromOptions()">
                    <i class="ph ph-share-network" style="font-size:1.5rem;"></i> <span style="font-size:1.05rem;">Поделиться</span>
                </button>
                <button class="vc-btn-outline flex items-center justify-start gap-3" style="border:none; background:var(--surface-elevated); padding: 18px 24px;" onclick="doDownloadFromOptions()">
                    <i class="ph ph-download-simple" style="font-size:1.5rem;"></i> <span style="font-size:1.05rem;">Скачать</span>
                </button>
                <button id="poDeleteBtn" class="vc-btn-outline flex items-center justify-start gap-3 hidden" style="border:none; background:rgba(255,42,95,0.1); color:var(--error); padding: 18px 24px; margin-top: 10px;" onclick="doDeleteFromOptions()">
                    <i class="ph ph-trash" style="font-size:1.5rem;"></i> <span style="font-size:1.05rem;">Удалить пост</span>
                </button>
            </div>
        </div>
    </div>

    <div id="searchModal" class="modal-overlay modal-bottom" onclick="closeModalOnOutsideClick(event, 'searchModal')">
        <div class="modal-content" style="max-height: 85vh;">
            <div class="flex justify-between items-center" style="padding:1.25rem 1.5rem; border-bottom:1px solid var(--surface-hover);">
                <h3 class="font-bold" style="font-size:1.1rem;">Поиск</h3>
                <button type="button" onclick="closeModal('searchModal')" style="color:var(--text-muted);"><i class="ph ph-caret-down" style="font-size:1.4rem;"></i></button>
            </div>
            <div class="p-4 border-b border-surface-hover">
                <div class="input-group mb-0">
                    <input type="text" id="searchInput" class="vc-input" placeholder=" " oninput="debounceSearch()">
                    <label for="searchInput" class="vc-label">Найти посты или людей...</label>
                </div>
            </div>
            <div id="searchResults" class="overflow-y-auto" style="flex:1; padding: 0.5rem 1rem 1.5rem;">
                <div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Что будем искать?</p></div>
            </div>
        </div>
    </div>

    <div id="followingModal" class="modal-overlay modal-bottom" onclick="closeModalOnOutsideClick(event, 'followingModal')">
        <div class="modal-content" style="max-height: 85vh;">
            <div class="flex justify-between items-center" style="padding:1.25rem 1.5rem; border-bottom:1px solid var(--surface-hover);">
                <h3 class="font-bold" style="font-size:1.1rem;">Подписки</h3>
                <button type="button" onclick="closeModal('followingModal')" style="color:var(--text-muted);"><i class="ph ph-caret-down" style="font-size:1.4rem;"></i></button>
            </div>
            <div id="followingList" class="overflow-y-auto" style="flex:1; padding: 0.5rem 1rem 1.5rem;">
                <div class="loader-screen" style="min-height: 20vh;"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>
            </div>
        </div>
    </div>

    <div id="notificationsView" class="view-section notifications-container">
        <div class="notif-page-header">
            <h2 class="font-bold" style="font-size:1.3rem;">Уведомления</h2>
        </div>
        <div id="notificationsList" class="notif-scroll-area">
            <div class="empty-state"><i class="ph ph-bell"></i><p>Нет уведомлений</p></div>
        </div>
    </div>

    <div id="settingsModal" class="modal-overlay" onclick="closeModalOnOutsideClick(event, 'settingsModal')">
        <div class="modal-content" style="max-width: 500px; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="flex justify-between items-center mb-4" style="flex-shrink: 0;">
                <h2 class="font-bold" style="font-size:1.5rem;">Настройки</h2>
                <button type="button" onclick="closeModal('settingsModal')" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.4rem;"></i></button>
            </div>

            <div class="settings-tabs">
                <button class="settings-tab active" id="tabBtnProfile" onclick="switchSettingsTab('profile')">Профиль</button>
                <button class="settings-tab" id="tabBtnAccount" onclick="switchSettingsTab('account')">Аккаунт</button>
                <button class="settings-tab" id="tabBtnPrivacy" onclick="switchSettingsTab('privacy')">Приватность</button>
                <button class="settings-tab" id="tabBtnSessions" onclick="switchSettingsTab('sessions')">Сессии</button>
            </div>

            <div class="settings-panes">
                <div id="paneProfile" class="block smooth-fade-in">
                    <form onsubmit="saveProfile(event)" novalidate>
                        <div class="flex justify-center mb-6">
                            <div style="position:relative; width:100px; height:100px; cursor:pointer;" onclick="document.getElementById('settingsAvatarFile').click()">
                                <img id="settingsAvatarPreview" src="" class="w-full h-full rounded-full object-cover" style="background:var(--surface-elevated);">
                                <div class="absolute inset-0 flex items-center justify-center rounded-full" style="background:rgba(0,0,0,0.5); top:0; left:0; width:100%; height:100%; position:absolute;"><i class="ph ph-camera text-white" style="font-size:1.5rem;"></i></div>
                            </div>
                            <input type="file" id="settingsAvatarFile" class="hidden" accept="image/png, image/jpeg, image/webp" onchange="initCrop(event)">
                        </div>
                        <div class="input-group">
                            <textarea id="settingsBio" class="vc-input" placeholder=" " style="height: 100px; resize:none; padding-top:24px;"></textarea>
                            <label class="vc-label">О себе</label>
                        </div>
                        <div class="flex justify-between items-center" style="background: var(--surface-elevated); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin-bottom: 0.75rem;">
                            <span class="font-bold" style="font-size:0.95rem;">Публичные сохранёнки</span>
                            <label class="toggle-switch">
                                <input type="checkbox" id="settingsBookmarksPublic" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <button type="submit" class="vc-btn mb-4">Обновить профиль</button>
                    </form>
                </div>

                <div id="paneAccount" class="hidden smooth-fade-in">
                    <form onsubmit="saveAccount(event)" novalidate>
                        <div class="input-group">
                            <input type="text" id="accUsername" class="vc-input" placeholder=" " required data-name="Имя пользователя">
                            <label class="vc-label">Имя пользователя</label>
                        </div>
                        <div class="input-group">
                            <input type="email" id="accEmail" class="vc-input" placeholder=" " required data-name="Email">
                            <label class="vc-label">Email</label>
                        </div>

                        <div style="border-top: 1px solid var(--surface-hover); border-bottom: 1px solid var(--surface-hover); margin: 1rem 0 1.5rem; padding: 1rem 0; display:flex; flex-direction:column; gap:0.5rem;">
                            <button type="button" class="vc-btn-outline w-full flex justify-between items-center" style="border: none; padding: 0.5rem; font-weight: 500;" onclick="openPasswordModal()">
                                <span class="flex items-center gap-2"><i class="ph ph-lock-key" style="font-size:1.2rem;"></i> Изменить пароль</span>
                                <i class="ph ph-caret-right text-muted"></i>
                            </button>
                            <button type="button" class="vc-btn-outline w-full flex justify-between items-center" style="border: none; padding: 0.5rem; font-weight: 500;" onclick="openTfaSettingsModal()">
                                <span class="flex items-center gap-2"><i class="ph ph-shield-check" style="font-size:1.2rem;"></i> Настройка 2FA</span>
                                <div class="flex items-center gap-2">
                                    <span id="tfaStatusBadge" style="font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; background: var(--surface-hover); color: var(--text-muted);">Выкл</span>
                                    <i class="ph ph-caret-right text-muted"></i>
                                </div>
                            </button>
                        </div>

                        <button type="submit" class="vc-btn mb-2">Сохранить изменения</button>
                    </form>
                </div>

                <div id="panePrivacy" class="hidden smooth-fade-in">
                    <div class="flex justify-between items-center mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md); padding: 0.75rem 1rem;">
                        <div>
                            <div class="font-bold" style="font-size:0.95rem;">Поиск в Dump</div>
                            <div class="text-xs text-muted mt-1">Другие пользователи могут найти меня в поиске</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="privacySearchable" checked onchange="togglePrivacySearchable(this)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="flex justify-between items-center mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md); padding: 0.75rem 1rem;">
                        <div>
                            <div class="font-bold" style="font-size:0.95rem;">Личные сообщения</div>
                            <div class="text-xs text-muted mt-1">Пользователи могут писать мне первыми</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="privacyMessages" checked onchange="togglePrivacyMessages(this)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="flex justify-between items-center mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md); padding: 0.75rem 1rem;">
                        <div>
                            <div class="font-bold" style="font-size:0.95rem;">Функции бетатестирования</div>
                            <div class="text-xs text-muted mt-1">Доступ к новым функциям до официального релиза</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="privacyBeta" onchange="togglePrivacyBeta(this)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="flex justify-between items-center mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md); padding: 0.75rem 1rem;">
                        <div>
                            <div class="font-bold" style="font-size:0.95rem;">Не показывайте мне рекламу</div>
                            <div class="text-xs text-muted mt-1">Скрывает рекламные баннеры на сайте</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="privacyNoAds" onchange="togglePrivacyNoAds(this)">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <div id="paneSessions" class="hidden smooth-fade-in">
                    <div id="sessionsList" class="flex flex-col mb-4" style="min-height: 150px; position: relative;">
                        <div class="loader-screen"><i class="ph ph-circle-notch spin" style="font-size: 2.5rem; color: var(--text-muted);"></i></div>
                    </div>
                    <button type="button" onclick="showConfirm('Выход', 'Точно выйти из текущего аккаунта?', logout)" class="vc-btn vc-btn-outline w-full" style="color:var(--error); border-color:rgba(255,42,95,0.3);">Выйти из текущего аккаунта</button>
                </div>
            </div>

            <div class="auth-footer-links" style="flex-shrink: 0; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--surface-hover);">
                <a class="legal-link" onclick="event.preventDefault();openLegal('rules')">Правила</a>
                <span class="text-muted">·</span>
                <a class="legal-link" onclick="event.preventDefault();openLegal('privacy-policy')">Политика конфиденциальности</a>
            </div>
        </div>
    </div>

    <div id="passwordModal" class="modal-overlay" style="z-index: 110;" onclick="closeModalOnOutsideClick(event, 'passwordModal')">
        <div class="modal-content" style="max-width: 400px;">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold" style="font-size:1.3rem;">Смена пароля</h3>
                <button type="button" onclick="closeModal('passwordModal')" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.4rem;"></i></button>
            </div>
            <form onsubmit="changePassword(event)" novalidate>
                <div class="input-group mb-4">
                    <input type="password" id="chPassCurrent" class="vc-input" placeholder=" " required data-name="Текущий пароль">
                    <label class="vc-label">Текущий пароль</label>
                </div>
                <div class="input-group mb-4">
                    <input type="password" id="chPassNew" class="vc-input" placeholder=" " required minlength="6" data-name="Новый пароль">
                    <label class="vc-label">Новый пароль</label>
                </div>
                <div class="input-group mb-6">
                    <input type="password" id="chPassConfirm" class="vc-input" placeholder=" " required minlength="6" data-name="Повторите пароль">
                    <label class="vc-label">Повторите новый пароль</label>
                </div>
                <button type="submit" class="vc-btn">Сохранить пароль</button>
            </form>
        </div>
    </div>

    <div id="tfaSettingsModal" class="modal-overlay" style="z-index: 110;" onclick="closeModalOnOutsideClick(event, 'tfaSettingsModal')">
        <div class="modal-content" style="max-width: 450px;">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold flex items-center gap-2" style="font-size:1.3rem;"><i class="ph-fill ph-shield-check"></i> Двухфакторная аутентификация</h3>
                <button type="button" onclick="closeModal('tfaSettingsModal')" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.4rem;"></i></button>
            </div>

            <div class="flex justify-between p-4 mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                <div>
                    <div class="font-bold mb-1">Использовать 2FA</div>
                    <div class="text-xs text-muted">Дополнительная защита аккаунта</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="tfaToggle" onchange="handleTfaToggleChange()">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <div id="tfaSetupContainer" class="hidden smooth-fade-in">
                <div class="radio-group">
                    <label class="radio-label">
                        <input type="radio" name="tfaMethod" value="email" checked onchange="changeTfaMethodPreview()">
                        <span>Email код</span>
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="tfaMethod" value="app" onchange="changeTfaMethodPreview()">
                        <span>Приложение</span>
                    </label>
                </div>

                <div id="tfaPreviewEmail" class="text-center p-4 mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                    <i class="ph ph-envelope-simple text-muted mb-2" style="font-size:2.5rem;"></i>
                    <p class="text-sm text-muted">Мы будем отправлять 6-значный код на ваш email при каждом входе в аккаунт.</p>
                </div>

                <div id="tfaPreviewApp" class="hidden text-center p-4 mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                    <i class="ph ph-device-mobile text-muted mb-2" style="font-size:2.5rem;"></i>
                    <p class="text-sm text-muted">Используйте Google Authenticator или подобное приложение для генерации кодов без интернета.</p>
                </div>

                <button id="tfaStartSetupBtn" class="vc-btn" onclick="startTfaSetup()">Продолжить</button>
            </div>

            <div id="tfaVerifyContainer" class="hidden smooth-fade-in">
                <div id="tfaAppQrContainer" class="hidden flex flex-col items-center mb-4 p-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                    <p class="text-sm text-center mb-3">Отсканируйте этот QR-код в приложении аутентификаторе:</p>
                    <div class="bg-white p-2 rounded-lg mb-3 inline-block">
                        <img id="tfaQrImage" src="" style="width: 150px; height: 150px; display: block;">
                    </div>
                    <div class="text-xs text-muted">Или введите ключ вручную:</div>
                    <div id="tfaSecretKey" class="font-bold mt-1 tracking-widest text-accent" style="user-select: all;"></div>
                </div>

                <div id="tfaEmailSentMessage" class="hidden text-center p-4 mb-4" style="background: var(--surface-elevated); border-radius: var(--radius-md);">
                    <i class="ph-fill ph-envelope-open text-accent mb-2" style="font-size:2.5rem;"></i>
                    <p class="text-sm">Мы отправили письмо с кодом подтверждения на ваш Email. Пожалуйста, проверьте почту.</p>
                </div>

                <div class="input-group mb-4">
                    <input type="text" id="tfaSetupCode" class="vc-input text-center font-bold tracking-widest" placeholder=" " required maxlength="6" style="font-size: 1.5rem; letter-spacing: 10px;">
                    <label class="vc-label" style="text-align: center; width: 100%; left: 0;">Введите 6-значный код</label>
                </div>

                <button id="tfaConfirmSetupBtn" class="vc-btn" onclick="confirmTfaSetup()">Подтвердить и Включить</button>
            </div>
        </div>
    </div>

    <div id="tfaLoginModal" class="modal-overlay" style="z-index: 200; background: var(--bg);" onclick="closeModalOnOutsideClick(event, 'tfaLoginModal')">
        <div class="modal-content" style="max-width: 400px; text-align: center; background: transparent; padding: 2rem;">
            <i id="tfaLoginIcon" class="ph ph-shield-check mb-4" style="font-size: 4rem; color: var(--accent);"></i>
            <h2 class="font-bold mb-2" style="font-size: 1.5rem;">Подтверждение входа</h2>
            <p id="tfaLoginDesc" class="text-muted mb-6 text-sm">Введите код из приложения.</p>

            <form onsubmit="verifyTfaLogin(event)" novalidate>
                <div class="input-group mb-6">
                    <input type="text" id="tfaLoginCode" class="vc-input text-center font-bold" placeholder=" " required maxlength="6" style="font-size: 1.5rem; letter-spacing: 10px; background: var(--surface-elevated);">
                    <label class="vc-label" style="text-align: center; width: 100%; left: 0;">6-значный код</label>
                </div>
                <button type="submit" id="tfaLoginBtn" class="vc-btn w-full">Войти</button>
                <button type="button" class="vc-btn-text w-full mt-4" onclick="cancelTfaLogin()">Отмена</button>
            </form>
        </div>
    </div>

    <div id="turnstileModal" class="modal-overlay" style="z-index: 200;" onclick="if(event.target===this){pendingTurnstileAuth=null;closeModal('turnstileModal')}">
        <div class="modal-content" style="max-width:360px">
            <div class="modal-header">
                <h3>Подтверждение</h3>
                <button class="modal-close" onclick="pendingTurnstileAuth=null;closeModal('turnstileModal')">&times;</button>
            </div>
            <div class="modal-body text-center">
                <div id="turnstileWidget" style="min-height:72px"></div>
            </div>
        </div>
    </div>

    <div id="spamCaptchaModal" class="modal-overlay" style="z-index: 999; background: rgba(0,0,0,0.85); cursor: default;">
        <div class="modal-content" style="max-width:380px; border: 2px solid var(--danger);">
            <div class="modal-body text-center" style="padding: 2rem 1.5rem;">
                <i class="ph ph-shield-warning" style="font-size: 3rem; color: var(--danger); display: block; margin-bottom: 1rem;"></i>
                <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 0.5rem;">Антиспам</h3>
                <p class="text-muted" style="font-size: 0.9rem; margin-bottom: 1.5rem;">Вы отправляете сообщения слишком быстро. Пожалуйста, пройдите проверку, чтобы продолжить.</p>
                <div id="spamTurnstileWidget" style="min-height:72px; display: flex; justify-content: center;"></div>
            </div>
        </div>
    </div>

    <div id="legalModal" class="modal-overlay" onclick="if(event.target.id==='legalModal') closeLegal()">
        <div class="modal-content" style="max-width: 680px; max-height: 90vh; display:flex; flex-direction:column;">
            <div class="flex justify-between items-center" style="padding-bottom: 1rem; border-bottom: 1px solid var(--surface-hover); margin-bottom: 1rem;">
                <h2 id="legalTitle" class="font-bold" style="font-size:1.3rem;">Загрузка…</h2>
                <button type="button" onclick="closeLegal()" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.4rem;"></i></button>
            </div>
            <div id="legalBody" class="legal-body"></div>
        </div>
    </div>

    <div id="cropModal" class="modal-overlay" style="z-index: 200;">
        <div class="modal-content">
            <h3 class="font-bold mb-4" style="font-size:1.2rem;">Обрезать аватар</h3>
            <div style="width: 100%; height: 300px; background: #000; margin-bottom: 1rem;">
                <img id="cropImage" src="">
            </div>
            <div class="flex gap-2">
                <button class="vc-btn-outline flex-1" onclick="cancelCrop()">Отмена</button>
                <button class="vc-btn flex-1" id="cropBtn" onclick="doCrop()">Применить</button>
            </div>
        </div>
    </div>

    <div id="confirmModal" class="modal-overlay" style="z-index: 9999;">
        <div class="modal-content text-center smooth-fade-in" style="max-width: 320px; padding: 2rem 1.5rem;">
            <div style="margin-bottom: 1rem; color: var(--error);"><i class="ph ph-warning-circle" style="font-size: 3rem;"></i></div>
            <h3 class="font-bold mb-2" id="confirmTitle" style="font-size: 1.2rem;">Подтверждение</h3>
            <p class="text-muted mb-6" id="confirmText" style="line-height: 1.4;">Вы уверены?</p>
            <div class="flex gap-3">
                <button class="vc-btn-outline flex-1" style="padding: 12px; border-color: rgba(255,255,255,0.1);" onclick="closeModal('confirmModal')">Отмена</button>
                <button class="vc-btn flex-1" id="confirmActionBtn" style="padding: 12px; background: var(--error); color: white;">Да</button>
            </div>
        </div>
    </div>

    <div id="textWarningModal" class="modal-overlay" style="z-index: 9999;">
        <div class="modal-content text-center smooth-fade-in" style="max-width: 340px; padding: 2rem 1.5rem;">
            <div style="margin-bottom: 1rem; color: var(--warning);"><i class="ph ph-info" style="font-size: 3rem;"></i></div>
            <h3 class="font-bold mb-2" style="font-size: 1.2rem;">Пост без текста?</h3>
            <p class="text-muted mb-6" style="line-height: 1.4; font-size: 0.95rem;">Публикации с описанием чаще рекомендуются другим пользователям на основе алгоритмов. Уверены, что хотите оставить пост пустым?</p>
            <div class="flex gap-3 flex-col">
                <button class="vc-btn" style="padding: 12px;" onclick="closeModal('textWarningModal'); document.getElementById('postContent').focus();">Добавить текст</button>
                <button class="vc-btn-outline w-full" style="padding: 12px; border: none; color: var(--text-muted);" onclick="forceCreatePost()">Всё равно опубликовать</button>
            </div>
        </div>
    </div>

    <div id="createView" class="modal-overlay" style="background:var(--bg);" onclick="closeModalOnOutsideClick(event, 'createView', false, true)">
        <div class="modal-content" style="background:transparent; max-width: 500px; padding: 1rem;">
            <div class="flex justify-between items-center mb-8">
                <h2 class="font-bold" style="font-size:1.6rem;">Новый пост</h2>
                <button type="button" onclick="closeCreatePost()" style="color:var(--text-muted);"><i class="ph ph-x" style="font-size:1.6rem;"></i></button>
            </div>

            <form id="createPostForm" onsubmit="handleCreatePostSubmit(event)" novalidate>
                <input id="postImageUpload" type="file" multiple class="hidden" accept="image/png, image/jpeg, image/gif, image/webp" onchange="handleMultiplePostImages(event)" />

                <div id="multiImagePreviewContainer" class="hidden"></div>

                <div id="uploadZone" class="mb-4">
                    <label id="uploadLabel" class="flex flex-col items-center justify-center" style="height:80px; background:var(--surface-elevated); border-radius:var(--radius-md); cursor:pointer; transition:var(--transition);"
                           ondragover="event.preventDefault(); this.style.backgroundColor='var(--surface-active)';"
                           ondragleave="event.preventDefault(); this.style.backgroundColor='var(--surface-elevated)';"
                           ondrop="event.preventDefault(); this.style.backgroundColor='var(--surface-elevated)'; handleDropPhotos(event);"
                           onclick="document.getElementById('postImageUpload').click()">
                        <div class="flex items-center gap-2 text-muted">
                            <i class="ph ph-image" style="font-size:1.5rem;"></i>
                            <span style="font-size:0.95rem; font-weight:500;">Прикрепить фото</span>
                        </div>
                    </label>
                </div>

                <div class="input-group">
                    <textarea id="postContent" class="vc-input auto-resize" placeholder=" " style="min-height: 140px; resize:none; padding-top:24px; font-size:1.1rem;" oninput="resizeTextarea(this)"></textarea>
                    <label class="vc-label">Напишите что-нибудь</label>
                </div>
                <button type="submit" id="submitPostBtn" class="vc-btn">Опубликовать</button>
            </form>
        </div>
    </div>

    <div id="messengerView" class="view-section">
        <div class="messenger-container">
        <div id="convListSection" class="msgr-section">
            <div class="msgr-header">
                <h2 class="font-bold" style="font-size:1.3rem;">Сообщения</h2>
                <button class="icon-btn-sm" onclick="openNewChat()" aria-label="Новый чат"><i class="ph ph-plus"></i></button>
            </div>
            <div id="convListItems" class="msgr-list"></div>
        </div>
        <div id="chatSection" class="msgr-section hidden">
            <div class="chat-card">
                <div class="chat-card-header">
                    <button class="chat-back-btn-msg" onclick="showConvList()" aria-label="Назад"><i class="ph ph-arrow-left"></i></button>
                    <img id="chatPartnerAvatar" src="" class="chat-partner-avatar" alt="" style="cursor:pointer;" onclick="showConvContextMenu(getCurrentConvId(), event)" oncontextmenu="event.preventDefault(); showConvContextMenu(getCurrentConvId(), event)">
                    <div style="flex:1;min-width:0;cursor:pointer;" onclick="showConvContextMenu(getCurrentConvId(), event)" oncontextmenu="event.preventDefault(); showConvContextMenu(getCurrentConvId(), event)">
                        <span id="chatPartnerName" class="font-bold" style="display:block;font-size:0.95rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                    </div>
                </div>
                <div id="chatMessages" class="chat-card-messages">
                    <div id="chatLoadMore" class="chat-load-more hidden"><button class="load-more-btn" onclick="loadMoreMessages()">Загрузить ещё</button></div>
                    <div id="chatMessagesInner"></div>
                    <div id="typingIndicator" class="typing-indicator hidden"><div class="typing-dots"><span></span><span></span><span></span></div><span id="typingText"></span></div>
                </div>
                <div id="replyIndicator" class="reply-indicator hidden">
                    <div class="reply-info"><i class="ph ph-arrow-u-down-left"></i><span>Ответ <b id="replyToName"></b></span></div>
                    <button onclick="cancelReply()" aria-label="Отменить"><i class="ph ph-x"></i></button>
                </div>
                <div id="editIndicator" class="reply-indicator hidden" style="border-left-color:var(--warning);">
                    <div class="reply-info"><i class="ph ph-pencil"></i><span>Редактирование</span></div>
                    <button onclick="cancelEdit()" aria-label="Отменить"><i class="ph ph-x"></i></button>
                </div>
                <div id="chatBlockedBanner" class="hidden" style="padding:1rem;text-align:center;color:var(--text-muted);border-top:1px solid var(--surface-hover);font-size:0.85rem;">
                    <i class="ph ph-prohibit" style="font-size:1.2rem;display:block;margin-bottom:0.3rem;"></i>
                    Пользователь заблокирован
                </div>
                <div id="emojiPicker" class="emoji-picker hidden">
                    <div class="emoji-picker-tabs">
                        <button type="button" class="emoji-tab active" onclick="switchPickerTab('emoji')" id="tabEmoji"><i class="ph ph-smiley"></i></button>
                        <button type="button" class="emoji-tab" onclick="switchPickerTab('gif')" id="tabGif"><i class="ph ph-gif"></i></button>
                    </div>
                    <div class="emoji-panel" id="emojiPanel">
                        <div class="emoji-grid" id="emojiGrid"></div>
                    </div>
                    <div class="emoji-panel hidden" id="gifPanel">
                        <div class="gif-search"><input type="text" id="gifSearchInput" placeholder="Поиск GIF..." oninput="debounceGifSearch()"></div>
                        <div class="gif-grid" id="gifGrid"></div>
                    </div>
                </div>
                <form id="chatForm" class="chat-input-area" onsubmit="sendChatMessage(event)" novalidate>
                    <input type="file" id="chatFileInput" accept="*/*" multiple style="display:none" onchange="handleChatFiles(this)">
                    <button type="button" class="chat-attach-btn" id="chatAttachBtn" onclick="document.getElementById('chatFileInput').click()" aria-label="Прикрепить"><i class="ph ph-paperclip"></i></button>
                    <button type="button" class="chat-attach-btn" onclick="openChatEmoji()" aria-label="Эмодзи"><i class="ph ph-smiley"></i></button>
                    <textarea id="chatInput" class="chat-input" placeholder="Сообщение..." rows="1" maxlength="5000" enterkeyhint="send" oninput="resizeTextarea(this); onChatTyping();" onkeydown="chatInputKeydown(event)"></textarea>
                    <button type="submit" id="chatSendBtn" class="chat-send-btn" aria-label="Отправить"><i class="ph-fill ph-paper-plane-right"></i></button>
                </form>
            </div>
        </div>
        </div>
    </div>

    <div id="newChatModal" class="modal-overlay modal-bottom" onclick="closeModalOnOutsideClick(event, 'newChatModal')">
        <div class="modal-content" style="max-height:85vh;">
            <div class="flex justify-between items-center" style="padding:1.25rem 1.5rem; border-bottom:1px solid var(--surface-hover);">
                <h3 class="font-bold" style="font-size:1.1rem;">Новый чат</h3>
                <button type="button" onclick="closeModal('newChatModal')" style="color:var(--text-muted);"><i class="ph ph-caret-down" style="font-size:1.4rem;"></i></button>
            </div>
            <div class="p-4 border-b border-surface-hover">
                <div class="input-group mb-0">
                    <input type="text" id="newChatSearch" class="vc-input" placeholder=" " oninput="debounceNewChatSearch()">
                    <label for="newChatSearch" class="vc-label">Поиск пользователей...</label>
                </div>
            </div>
            <div id="newChatResults" class="overflow-y-auto" style="flex:1;padding:0.5rem 1rem 1.5rem;">
                <div class="empty-state"><i class="ph ph-magnifying-glass"></i><p>Начните вводить имя</p></div>
            </div>
        </div>
    </div>

    <div id="bottomNav" class="bottom-nav">
        <button class="bottom-nav-item active" data-nav="feed" onclick="bottomNavClick('feed')">
            <i class="ph ph-house"></i>
        </button>
        <button class="bottom-nav-item" data-nav="search" onclick="bottomNavClick('search')">
            <i class="ph ph-magnifying-glass"></i>
        </button>
        <button class="bottom-nav-item" data-nav="messenger" onclick="bottomNavClick('messenger')">
            <i class="ph ph-chat-circle"></i>
            <span id="msgBadgeBottom" class="notif-badge-bottom hidden">0</span>
        </button>
        <button class="bottom-nav-item" data-nav="create" onclick="bottomNavClick('create')">
            <i class="ph ph-plus"></i>
        </button>
        <button class="bottom-nav-item" data-nav="profile" onclick="bottomNavClick('profile')">
            <i class="ph ph-user"></i>
        </button>
    </div>

    <div id="commentsModal" class="modal-overlay modal-bottom" onclick="closeModalOnOutsideClick(event, 'commentsModal')">
        <div class="modal-content">
            <div class="flex justify-between items-center" style="padding:1.25rem 1.5rem; border-bottom:1px solid var(--surface-hover);">
                <h3 class="font-bold" style="font-size:1.1rem;">Комментарии</h3>
                <button type="button" onclick="closeModal('commentsModal')" style="color:var(--text-muted);"><i class="ph ph-caret-down" style="font-size:1.4rem;"></i></button>
            </div>

            <div id="commentsList" class="comments-list smooth-fade-in"></div>

            <form onsubmit="sendComment(event)" class="comment-input-area" novalidate>
                <div id="replyingToIndicator" class="hidden" style="width: 100%; padding: 10px 16px; margin-bottom: 12px; background-color: var(--surface-elevated); border-radius: 12px; font-size: 0.85rem; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--surface-hover);">
                    <div class="flex items-center gap-2">
                        <i class="ph ph-arrow-u-down-left" style="font-size: 1.1rem;"></i>
                        <span>В ответ <b id="replyingToName" style="color: white; margin-left: 2px;"></b></span>
                    </div>
                    <button type="button" onclick="cancelReply()" style="color: var(--text-muted); font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: color 0.2s;" onmouseover="this.style.color='white'" onmouseout="this.style.color='var(--text-muted)'">
                        <i class="ph ph-x"></i>
                    </button>
                </div>

                <div id="commentImagePreviewContainer" class="hidden w-full mb-3 relative">
                    <div style="position: relative; display: inline-block;">
                        <img id="commentImagePreview" src="" style="max-height: 140px; border-radius: 12px; object-fit: contain; background: black;">
                        <button type="button" onclick="clearCommentImage()" style="position: absolute; top: -8px; right: -8px; background: var(--surface-elevated); color: white; border-radius: 50%; padding: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.5); border: 1px solid var(--surface-hover);"><i class="ph ph-x"></i></button>
                    </div>
                </div>

                <div class="comment-input-wrapper">
                    <button type="button" onclick="document.getElementById('commentImageUpload').click()" style="width: 44px; height: 44px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--text-muted); transition: 0.2s;"><i class="ph ph-plus-circle"></i></button>
                    <input type="file" id="commentImageUpload" class="hidden" accept="image/png, image/jpeg, image/gif, image/webp" onchange="handleCommentImage(event)">

                    <textarea id="commentInput" placeholder="Добавить комментарий..." autocomplete="off" rows="1" oninput="resizeTextarea(this)"></textarea>

                    <button type="submit" id="sendCommentBtn" style="width: 44px; height: 44px; border-radius: 50%; background: var(--accent); color: var(--accent-bg); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; padding-left: 4px;"><i class="ph-fill ph-paper-plane-right"></i></button>
                </div>
            </form>
        </div>
    </div>
    <script src="<?= htmlspecialchars($asset_base) ?>/script.js?v=50"></script>

    <?php if ($is_mobile): ?>
    <style>
        #installModal {
            position: fixed; top: 0; left: 0; width: 100vw; height: 100dvh;
            background: rgba(0,0,0,0.7);
            z-index: 200;
            display: flex; align-items: flex-end; justify-content: center;
            opacity: 0; visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        #installModal.open { opacity: 1; visibility: visible; }
        #installModal .sheet {
            width: 100%; max-width: 400px;
            background: #0a0a0a;
            border-radius: 24px 24px 0 0;
            padding: 2rem 1.5rem;
            padding-bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));
            transform: translateY(100%);
            transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
            text-align: center;
        }
        #installModal.open .sheet { transform: translateY(0); }
        #installModal .handle {
            width: 40px; height: 5px; border-radius: 4px;
            background: #1a1a1a; margin: 0 auto 1.5rem;
        }
        #installModal .icon {
            width: 72px; height: 72px; border-radius: 20px;
            background: #111; margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        #installModal .icon img { width: 100%; height: 100%; object-fit: cover; }
        #installModal h2 {
            font-size: 1.5rem; font-weight: 800;
            letter-spacing: -0.5px; margin-bottom: 0.3rem;
        }
        #installModal .desc {
            color: #808080; font-size: 0.95rem;
            line-height: 1.5; margin-bottom: 1.5rem;
        }
        #installModal .benefits {
            display: flex; flex-direction: column; gap: 0.6rem;
            margin-bottom: 1.5rem; text-align: left;
        }
        #installModal .benefit {
            display: flex; align-items: center; gap: 0.75rem;
            font-size: 0.9rem; font-weight: 500;
        }
        #installModal .benefit svg {
            width: 20px; height: 20px; flex-shrink: 0;
            stroke: #fff; fill: none; stroke-width: 1.8;
            stroke-linecap: round; stroke-linejoin: round;
        }
        #installModal .btn {
            display: block; width: 100%;
            background: #fff; color: #000;
            font-weight: 700; font-size: 1rem;
            padding: 16px 24px; border-radius: 14px;
            text-decoration: none; cursor: pointer;
            border: none; transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        #installModal .btn:active { transform: scale(0.97); }
        #installModal .skip {
            display: block; width: 100%;
            background: none; border: none;
            color: #808080; font-size: 0.85rem;
            padding: 12px; cursor: pointer; margin-top: 0.5rem;
            transition: color 0.2s;
        }
        #installModal .skip:hover { color: #fff; }
    </style>
    <div id="installModal" onclick="if(event.target===this) dismissInstallModal()">
        <div class="sheet">
            <div class="handle"></div>
            <div class="icon"><img src="<?= $asset_base ?>/logo.png" alt="Dump"></div>
            <h2>Dump</h2>
            <div class="desc">Установите приложение — быстрее, удобнее, с уведомлениями</div>
            <div class="benefits">
                <div class="benefit">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Мгновенные push-уведомления
                </div>
                <div class="benefit">
                    <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    Высокая скорость и плавность
                </div>
                <div class="benefit">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Удобная интеграция
                </div>
            </div>
            <a href="<?= $asset_base ?>/download" class="btn">Скачать</a>
            <button type="button" class="skip" onclick="dismissInstallModal()">Не сейчас</button>
        </div>
    </div>
    <script>
    (function() {
        if (!localStorage.getItem('dump_install_dismissed')) {
            setTimeout(function() {
                var m = document.getElementById('installModal');
                if (m) m.classList.add('open');
            }, 2000);
        }
    })();
    function dismissInstallModal() {
        var m = document.getElementById('installModal');
        if (m) {
            m.classList.remove('open');
            try { localStorage.setItem('dump_install_dismissed', '1'); } catch(e) {}
        }
    }
    </script>
    <?php endif; ?>
    <script>
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
            y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
        })(window, document, "clarity", "script", "xj975d6qmr");
    </script>
    <audio id="notifAudio" preload="auto" src="<?= htmlspecialchars($asset_base) ?>/notification.wav" volume="0.5"></audio>
</body>
</html>
