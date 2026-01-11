const CACHE_NAME = 'shoplist-v3'; // Changed version to v3
const ASSETS = [
  '/',
  '/assets/style.css',
  '/assets/app.js',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/login.php',
  '/day.php',
  '/manifest.json'
];

self.addEventListener('install', (e) => {
  self.skipWaiting(); // FORCE UPDATE: Loads new code immediately
  e.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS)));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.map((k) => {
        if (k !== CACHE_NAME) return caches.delete(k);
      })
    ))
  );
  return self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  e.respondWith(
    fetch(e.request)
      .then((res) => { return res; })
      .catch(() => caches.match(e.request))
  );
});