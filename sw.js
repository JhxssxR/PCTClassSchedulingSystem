const CACHE_VERSION = '2026-04-13-v2';
const STATIC_CACHE = 'pct-static-' + CACHE_VERSION;
const RUNTIME_CACHE = 'pct-runtime-' + CACHE_VERSION;
const CDN_CACHE = 'pct-cdn-' + CACHE_VERSION;

function appBasePath() {
    const scopeUrl = new URL(self.registration.scope);
    return scopeUrl.pathname.endsWith('/')
        ? scopeUrl.pathname.slice(0, -1)
        : scopeUrl.pathname;
}

function appUrl(path) {
    const base = appBasePath();
    return base + (path.startsWith('/') ? path : '/' + path);
}

const CORE_ASSETS = [
    appUrl('/offline.html'),
    appUrl('/assets/css/device-responsive.css'),
    appUrl('/assets/css/universal-ui.css'),
    appUrl('/assets/js/device-responsive.js'),
    appUrl('/assets/js/offline-register.js'),
    appUrl('/pctlogo.png')
];

const CDN_HOSTS = new Set([
    'cdn.jsdelivr.net',
    'unpkg.com',
    'fonts.googleapis.com',
    'fonts.gstatic.com',
    'cdn.tailwindcss.com'
]);

const CDN_PRECACHE_URLS = [
    'https://cdn.tailwindcss.com',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css',
    'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css',
    'https://unpkg.com/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
    'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap'
];

async function precacheCdnAssets() {
    const cache = await caches.open(CDN_CACHE);

    await Promise.allSettled(
        CDN_PRECACHE_URLS.map(async (url) => {
            try {
                const response = await fetch(new Request(url, { mode: 'no-cors' }));
                if (response) {
                    await cache.put(url, response.clone());
                }
            } catch (_) {
                // Ignore network failures during install; runtime caching still applies.
            }
        })
    );
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => Promise.allSettled(CORE_ASSETS.map((url) => cache.add(url))))
            .then(() => precacheCdnAssets())
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys
                .filter((key) => key.startsWith('pct-') && ![STATIC_CACHE, RUNTIME_CACHE, CDN_CACHE].includes(key))
                .map((key) => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

function isStaticAssetRequest(requestUrl, request) {
    if (request.destination && ['style', 'script', 'image', 'font'].includes(request.destination)) {
        return true;
    }

    return /\.(css|js|png|jpg|jpeg|svg|webp|gif|woff|woff2|ttf|eot)$/i.test(requestUrl.pathname);
}

async function staleWhileRevalidate(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    const networkFetch = fetch(request)
        .then((response) => {
            if (response && (response.ok || response.type === 'opaque')) {
                cache.put(request, response.clone());
            }
            return response;
        })
        .catch(() => null);

    if (cached) {
        networkFetch.catch(() => null);
        return cached;
    }

    const networkResponse = await networkFetch;
    if (networkResponse) {
        return networkResponse;
    }

    return Response.error();
}

async function cdnNetworkWithOfflineCache(request) {
    const cache = await caches.open(CDN_CACHE);

    try {
        const response = await fetch(request);
        if (response && (response.ok || response.type === 'opaque')) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (_) {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }

        // Some pages request CDN URLs as plain strings in markup.
        const fallbackCached = await cache.match(request.url);
        if (fallbackCached) {
            return fallbackCached;
        }

        return Response.error();
    }
}

async function networkFirstNavigation(request) {
    const cache = await caches.open(RUNTIME_CACHE);

    try {
        const response = await fetch(request);
        if (response && response.ok) {
            cache.put(request, response.clone());
        }
        return response;
    } catch (_) {
        const cachedPage = await cache.match(request);
        if (cachedPage) {
            return cachedPage;
        }

        const fallback = await caches.match(appUrl('/offline.html'));
        if (fallback) {
            return fallback;
        }

        return new Response('Offline', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=UTF-8' }
        });
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const requestUrl = new URL(request.url);

    if (request.mode === 'navigate') {
        event.respondWith(networkFirstNavigation(request));
        return;
    }

    if (CDN_HOSTS.has(requestUrl.hostname)) {
        event.respondWith(cdnNetworkWithOfflineCache(request));
        return;
    }

    if (requestUrl.origin === self.location.origin && isStaticAssetRequest(requestUrl, request)) {
        event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE));
    }
});

self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
