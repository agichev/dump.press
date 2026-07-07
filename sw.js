const CACHE = 'dump-v1';
const STATIC_CACHE = 'dump-static-v1';
const IMAGE_CACHE = 'dump-images-v1';
const FONT_CACHE = 'dump-fonts-v1';

const PRECACHE_URLS = [
  '/style.min.css?v=8',
  '/script.min.js?v=8',
  '/logo.png',
  '/watchindump.png',
  '/favicon.ico',
  '/site.webmanifest'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then(cache => cache.addAll(PRECACHE_URLS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== STATIC_CACHE && k !== IMAGE_CACHE && k !== FONT_CACHE)
        .map(k => caches.delete(k))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  if (url.pathname.includes('/index.php') && url.searchParams.get('api') === 'proxy') {
    event.respondWith(cacheFirst(event.request, IMAGE_CACHE));
    return;
  }

  if (url.href.includes('fonts.googleapis.com') || url.href.includes('fonts.gstatic.com')) {
    event.respondWith(cacheFirst(event.request, FONT_CACHE));
    return;
  }

  if (url.href.includes('unpkg.com/@phosphor-icons') || url.href.includes('cdnjs.cloudflare.com/ajax/libs/cropperjs')) {
    event.respondWith(cacheFirst(event.request, STATIC_CACHE));
    return;
  }

  if (url.pathname.match(/\.(css|js|png|ico|webmanifest)$/)) {
    event.respondWith(cacheFirst(event.request, STATIC_CACHE));
    return;
  }
});

async function cacheFirst(request, cacheName) {
  const cached = await caches.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(cacheName);
      cache.put(request, response.clone());
    }
    return response;
  } catch (e) {
    return new Response('Offline', { status: 503 });
  }
}
