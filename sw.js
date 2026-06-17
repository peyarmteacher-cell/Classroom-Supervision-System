// Minimal Service Worker to enable Chrome/iOS "Add to Home Screen" installation
const CACHE_NAME = 'classroom-supervision-v1';
const ASSETS = [
  '/',
  '/dashboard.php',
  '/manifest.json',
  '/src/assets/images/school_crest_logo_1781666281619.jpg'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS).catch(() => {});
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      return cachedResponse || fetch(event.request);
    })
  );
});
