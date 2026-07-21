<?php
declare(strict_types=1);

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$base_url = app_base_url();
$apk_url = $base . '/dump.apk';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dump — Мобильное приложение</title>
    <meta name="description" content="Мобильное приложение Dump — социальная сеть для фотографий и общения. Скачайте APK для Android и присоединяйтесь к сообществу.">
    <meta name="keywords" content="Dump, мобильное приложение, социальная сеть, скачать, Android, APK, dump соцсеть, дамп, dump.press, фото, общение">
    <meta property="og:title" content="Dump — Мобильное приложение">
    <meta property="og:description" content="Скачайте мобильное приложение Dump и оставайтесь на связи с сообществом. Социальная сеть для фото и общения.">
    <meta property="og:image" content="<?= $base_url ?>/watchindump.png">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ru_RU">
    <?= buildJsonLd('download', ['apk_url' => $apk_url]) ?>
    <meta property="og:site_name" content="Dump">
    <meta property="og:url" content="<?= $base_url ?>/download">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@dump">
    <meta name="theme-color" content="#000000">
    <meta name="msapplication-TileColor" content="#000000">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><circle cx='50' cy='50' r='50' fill='%23000000'/><text x='50' y='55' dominant-baseline='middle' text-anchor='middle' font-size='76' font-family='-apple-system, BlinkMacSystemFont, sans-serif' font-weight='800' fill='%23ffffff'>D</text></svg>">
    <link rel="icon" href="<?= $base ?>/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="<?= $base ?>/logo.png">
    <link rel="manifest" href="<?= $base ?>/site.webmanifest">
    <script>
    (function(){try{if(localStorage.getItem('dump_no_track')==='1'){window.__dumpNoTrack=true}}catch(e){}})();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <!-- WireBoard tag -->
    <script type="text/javascript">
    if (!window.__dumpNoTrack) {
    ;(function(w,i,r,e,b,oar,d){if(!w[b]){w.WireBoardNamespace=w.WireBoardNamespace||[];
    w.WireBoardNamespace.push(b);w[b]=function(){(w[b].q=w[b].q||[]).push(arguments)};
    w[b].q=w[b].q||[];oar=i.createElement(r);d=i.getElementsByTagName(r)[0];oar.async=1;
    oar.src=e;d.parentNode.insertBefore(oar,d)}}(window,document,"script","https://static.wireboard.io/wireboard.js","wireboard"));
    wireboard('newTracker', 'wb', 'pipeline-0.collector.wireboard.io', {
        appId: 'FZpCjcao',
        forceSecureTracker: true,
        contexts: {
          performanceTiming: true,
        }
    });
    window.wireboard('enableActivityTracking', 5, 10);
    var customContext=[{schema:'wb:io.wireboard/publisher',data:{publisher:'0c66f34d-03e9-42bf-ae40-0c109f6d4aa0'}}]
    window.wireboard('trackPageView', null, customContext);
    }
    </script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
        body {
            background: #000;
            color: #fff;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .container {
            width: 100%;
            max-width: 420px;
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
        }
        .logo {
            width: 96px;
            height: 96px;
            border-radius: 24px;
            background: #111;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .brand {
            font-size: 3.2rem;
            font-weight: 800;
            letter-spacing: -2px;
            margin-bottom: 0.5rem;
            user-select: none;
        }
        .tagline {
            color: #808080;
            font-size: 1.05rem;
            text-align: center;
            line-height: 1.5;
            margin-bottom: 2.5rem;
            max-width: 320px;
        }
        .features {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            width: 100%;
            margin-bottom: 2.5rem;
        }
        .feature {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #111;
            border-radius: 14px;
        }
        .feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #1a1a1a;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .feature-icon svg {
            width: 22px;
            height: 22px;
            stroke: #fff;
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .feature-text {
            font-weight: 600;
            font-size: 0.95rem;
        }
        .feature-desc {
            color: #808080;
            font-size: 0.82rem;
            margin-top: 2px;
        }
        .download-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 18px 24px;
            background: #fff;
            color: #000;
            font-weight: 700;
            font-size: 1.1rem;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .download-btn:active { transform: scale(0.97); }
        .download-btn svg { width: 24px; height: 24px; flex-shrink: 0; }
        .version { color: #808080; font-size: 0.8rem; margin-top: 0.75rem; text-align: center; }
        .links {
            display: flex;
            gap: 12px;
            margin-top: 3rem;
            padding-top: 1rem;
            border-top: 1px solid #1a1a1a;
            width: 100%;
            justify-content: center;
        }
        .links a {
            color: #808080;
            font-size: 0.85rem;
            text-decoration: underline;
            text-underline-offset: 2px;
            text-decoration-color: rgba(255,255,255,0.15);
            transition: color 0.2s;
        }
        .links a:hover { color: #fff; }
        .attribution { color: #444; font-size: 0.72rem; margin-top: 1rem; text-align: center; }
        @media (max-width: 420px) {
            .container { padding: 1.5rem 1rem; }
            .brand { font-size: 2.8rem; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="<?= $base ?>/logo.png" alt="Dump">
        </div>
        <div class="brand">Dump</div>
        <p class="tagline">Делитесь фотографиями, мыслями и находите крутой контент от других людей.</p>

        <div class="features">
            <div class="feature">
                <div class="feature-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M21 9h-4a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h4"/></svg></div>
                <div>
                    <div class="feature-text">Лента контента</div>
                    <div class="feature-desc">Листайте посты в удобном вертикальном формате</div>
                </div>
            </div>
            <div class="feature">
                <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="9" y1="12" x2="15" y2="12"/></svg></div>
                <div>
                    <div class="feature-text">Комментарии и лайки</div>
                    <div class="feature-desc">Общайтесь и поддерживайте авторов</div>
                </div>
            </div>
            <div class="feature">
                <div class="feature-icon"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg></div>
                <div>
                    <div class="feature-text">Push-уведомления</div>
                    <div class="feature-desc">Мгновенно узнавайте о новых публикациях</div>
                </div>
            </div>
        </div>

        <a href="<?= $apk_url ?>" class="download-btn" download>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Скачать APK
        </a>
        <div class="version">Версия 1.3 · Android 7.0+</div>

        <div class="links">
            <a href="<?= $base ?>/legal/rules">Правила</a>
            <a href="<?= $base ?>/legal/privacy-policy">Политика конфиденциальности</a>
        </div>
        <div class="attribution">Dump © 2026</div>
    </div>
</body>
</html>
