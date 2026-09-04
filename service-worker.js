const CACHE_NAME = "hfolio-cache-v6";
const CORE_ASSETS = [
  "./",
  "./index.html",
  "./experience.html",
  "./projects.html",
  "./portfolio.html",
  "./contact.html",
  "./offline.html",
  "./css/styles.css",
  "./js/app.js",
  "./js/render.js",
  "./assets/data/resume.json",
  "./manifest.json",
  "./assets/icons/icon-192.svg",
  "./assets/icons/icon-512.svg",
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

  event.respondWith(
    caches.match(event.request).then(function (cached) {
      if (cached) {
        return cached;
      }

      return fetch(event.request)
        .then(function (response) {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then(function (cache) {
            cache.put(event.request, responseClone);
          });
          return response;
        })
        .catch(function () {
          if (event.request.mode === "navigate") {
            return caches.match("./offline.html");
          }
          return new Response("", { status: 503, statusText: "Offline" });
        });
    })
  );
});
