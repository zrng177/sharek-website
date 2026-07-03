/**
 * Sharek v1.5 - Service Worker
 * Strategy: Cache-first for static assets, network-first for API
 */

const CACHE_NAME = 'sharek-shell-v2';
const API_CACHE  = 'sharek-api-v1';

// Static shell files to cache immediately (admin.php intentionally excluded)
const SHELL_FILES = [
    './',
    './index.html',
    './how-it-works.html',
    './about.html',
    './offers.html',
    './contact.php',
    './manifest.json',
    './icons/icon-192.png',
    './icons/icon-512.png',
    './css/variables.css',
    './css/kurdish-typography.css',
    './css/main.css',
    './css/auth.css',
    './css/landing.css',
    './css/components.css',
    './css/responsive-fixes.css',
    './css/dashboard.css',
    './css/map.css',
    './css/footer.css',
    './js/main.js',
    './js/app.js',
    './js/stats.js',
    './js/offers.js',
    './js/map-preview.js',
];

// ── Install: cache shell ───────────────────────────────────────────────
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache =>
                // cache.addAll() is all-or-nothing: a single 404 in
                // SHELL_FILES used to reject the whole call, so nothing
                // got pre-cached (audit finding #34). Promise.allSettled
                // over individual cache.add() calls means one missing
                // asset can no longer take down offline support for
                // every other file.
                Promise.allSettled(SHELL_FILES.map(url => cache.add(url)))
                    .then(results => {
                        results.forEach((result, i) => {
                            if (result.status === 'rejected') {
                                console.warn('[SW] Failed to cache', SHELL_FILES[i], result.reason);
                            }
                        });
                    })
            )
            .then(() => self.skipWaiting())
            .catch(err => console.warn('[SW] Install cache error:', err))
    );
});

// ── Activate: clear old caches ─────────────────────────────────────────
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(k => k !== CACHE_NAME && k !== API_CACHE)
                    .map(k => caches.delete(k))
            )
        ).then(() => self.clients.claim())
    );
});

// ── Fetch ──────────────────────────────────────────────────────────────
self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);

    // Only handle same-origin requests
    if (url.origin !== location.origin) return;

    // API requests: network-first, fall back to cached response
    if (url.pathname.includes('api.php')) {
        e.respondWith(networkFirst(e.request, API_CACHE));
        return;
    }

    // PHP pages: network-first (always fresh content)
    if (url.pathname.endsWith('.php')) {
        e.respondWith(networkFirst(e.request, CACHE_NAME));
        return;
    }

    // Static assets: cache-first
    e.respondWith(cacheFirst(e.request));
});

async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        return offlineFallback(request);
    }
}

async function networkFirst(request, cacheName) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        const cached = await caches.match(request);
        if (cached) return cached;
        return offlineFallback(request);
    }
}

async function offlineFallback(request) {
    // For navigation requests, show offline page
    if (request.mode === 'navigate') {
        const cached = await caches.match('./offline.html');
        if (cached) return cached;
        // Inline minimal offline page if offline.html not cached
        return new Response(
            `<!DOCTYPE html><html lang="ku" dir="rtl">
            <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>بێ ئینتەرنێت | شەریک</title>
            <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f4ff;margin:0;direction:rtl}
            .box{text-align:center;padding:2rem;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.1);max-width:360px;width:90%}
            h1{color:#0f2557;font-size:1.5rem}p{color:#6b7280;margin-bottom:1.5rem}
            button{background:#1e3a8a;color:#fff;border:none;padding:.875rem 2rem;border-radius:10px;font-size:1rem;cursor:pointer}</style></head>
            <body><div class="box"><div style="font-size:3rem">📡</div>
            <h1>ئینتەرنێتت بڕاوە</h1>
            <p>تکایە پەیوەندی بکەرەوە و دووبارە هەوڵ بدەرەوە</p>
            <button onclick="location.reload()">🔄 دووبارە هەوڵ بدەرەوە</button></div></body></html>`,
            { headers: { 'Content-Type': 'text/html;charset=UTF-8' } }
        );
    }
    return new Response('', { status: 503 });
}
