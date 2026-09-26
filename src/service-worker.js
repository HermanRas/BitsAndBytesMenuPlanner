// Install-only service worker: exists purely so the app meets installability
// criteria (Add to Home Screen). No fetch handler — everything always goes
// to the network as normal; this app doesn't do offline caching.

self.addEventListener("install", () => {
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(self.clients.claim());
});
