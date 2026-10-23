const CACHE = 'jm-portal-static-v10';
const ASSETS = ['/portal-offline.html', '/css/authentication.css', '/js/authentication.js', '/images/portal-icon-192.png', '/images/portal-icon-512.png'];
self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
    event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('jm-portal-static-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;
    // Authenticated pages and private files are never stored in Cache Storage.
    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(() => caches.match('/portal-offline.html')));
    } else if (ASSETS.includes(url.pathname)) {
        event.respondWith(fetch(event.request).catch(() => caches.match(url.pathname)));
    }
});
