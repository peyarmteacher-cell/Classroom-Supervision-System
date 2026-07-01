// Service Worker for Classroom Supervision Systems (Optimized for Dynamic PHP environment)
const CACHE_NAME = 'classroom-supervision-v3';

// Cache ONLY static assets that do not require server-side PHP session or auth
const STATIC_ASSETS = [];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.warn('Pre-cache warning:', err);
      });
    })
  );
  self.skipWaiting();
});

// Clean up any old dynamic page caches (like '/' and '/dashboard.php' from classroom-supervision-v1)
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // 1. BYPASS INTERCEPTION for PHP files, POST requests, and third-party APIs
  // This guarantees cookies, sessions, and HTTP redirects (e.g., login.php -> dashboard.php) work flawlessly and prevents ERR_FAILED.
  if (
    event.request.method !== 'GET' ||
    url.pathname.endsWith('.php') ||
    url.pathname === '/' ||
    !url.origin.includes(self.location.host)
  ) {
    // Returning without calling event.respondWith() allows the browser to perform a native network fetch
    return;
  }

  // 2. Network-First / Cache-Fall-Back for static assets only (images, icons, manifest etc.)
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      if (cachedResponse) {
        return cachedResponse;
      }
      return fetch(event.request);
    }).catch(() => {
      return Response.error();
    })
  );
});
