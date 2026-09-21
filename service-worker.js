// CHTEO service worker
// Network-first: always tries the live site so wallet/balance data is never stale.
// The cache is used only as an offline fallback for page navigations.

const CACHE_NAME = "chteo-v1";
const OFFLINE_URLS = ["/Website-/index.html"];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(OFFLINE_URLS))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Never touch non-GET, or anything from another domain
  // (Supabase, chteo-api.onrender.com, images CDN, etc.)
  if (req.method !== "GET" || url.origin !== self.location.origin) return;

  // Only handle page navigations: live first, cached page if offline
  if (req.mode === "navigate") {
    event.respondWith(
      fetch(req).catch(() =>
        caches.match(req).then((res) => res || caches.match("/Website-/index.html"))
      )
    );
  }
});
