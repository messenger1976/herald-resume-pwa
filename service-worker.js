const CACHE_NAME = "hfolio-cache-v25";
const CORE_ASSETS = [
  "./",
  "./index.html",
  "./experience.html",
  "./projects.html",
  "./portfolio.html",
  "./pension-house-details.html",
  "./lakambini-details.html",
  "./bohol-island-tours-details.html",
  "./unifiedar-details.html",
  "./tapstemco-details.html",
  "./roxas-water-district-details.html",
  "./labason-water-district-details.html",
  "./contact.html",
  "./offline.html",
  "./favicon.ico",
  "./css/styles.css",
  "./js/app.js",
  "./js/render.js",
  "./js/pension-gallery.js",
  "./js/lakambini-gallery.js",
  "./js/bohol-tours-gallery.js",
  "./js/unifiedar-gallery.js",
  "./js/tapstemco-gallery.js",
  "./js/roxas-gallery.js",
  "./js/labason-gallery.js",
  "./assets/data/resume.json",
  "./manifest.json",
  "./assets/icons/favicon-16.png",
  "./assets/icons/favicon-32.png",
  "./assets/icons/apple-touch-icon.png",
  "./assets/icons/icon-192.png",
  "./assets/icons/icon-512.png",
  "./assets/images/profile.jpg"
];

self.addEventListener("install", function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(CORE_ASSETS);
    })
  );
  self.skipWaiting();
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys
          .filter(function (key) {
            return key !== CACHE_NAME;
          })
          .map(function (oldKey) {
            return caches.delete(oldKey);
          })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener("fetch", function (event) {
  if (event.request.method !== "GET") {
    return;
  }

  const isAppShellRequest =
    event.request.mode === "navigate" ||
    /\.(?:html|css|js|json)$/i.test(new URL(event.request.url).pathname);

  event.respondWith(
    (isAppShellRequest ? fetch(event.request) : caches.match(event.request).then(function (cached) { return cached || fetch(event.request); }))
      .then(function (response) {
        if (response && response.ok) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(event.request, responseClone);
          });
        }
        return response;
      })
      .catch(function () {
        if (event.request.mode === "navigate") {
          return caches.match("./offline.html");
        }
        return caches.match(event.request).then(function (cached) {
          return cached || new Response("", { status: 503, statusText: "Offline" });
        });
      })
  );
});
