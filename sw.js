// sw.js - minimal, stability-first service worker.
// Goals:
// - Intercept ONLY navigations (HTML documents) to provide offline fallback.
// - Never proxy/intercept API/SSE/JS/CSS/fonts/assets (avoid subtle perf/protocol issues).
// - Keep push notifications working.

const CACHE_VERSION = "v13";
const CACHE_NAME = `labus-static-${CACHE_VERSION}`;
const OFFLINE_URL = "/offline.html";

const PRECACHE = [
  OFFLINE_URL,
  "/manifest.webmanifest",
  "/icons/favicon.ico",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(CACHE_NAME);
      await Promise.all(
        PRECACHE.map((url) =>
          cache.add(url).catch(() => undefined)
        )
      );
      await self.skipWaiting();
    })()
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys.map((k) => (k === CACHE_NAME ? undefined : caches.delete(k)))
      );
      await self.clients.claim();
    })()
  );
});

function isNavigation(request) {
  if (request.mode === "navigate") return true;
  const accept = request.headers.get("accept") || "";
  return accept.includes("text/html");
}

self.addEventListener("fetch", (event) => {
  const req = event.request;

  if (req.method !== "GET") {
    return;
  }

  // Intercept ONLY navigations. Everything else goes directly to network.
  if (!isNavigation(req)) {
    return;
  }

  event.respondWith(
    (async () => {
      try {
        // Do not cache HTML; just pass-through.
        return await fetch(req, { cache: "no-store" });
      } catch {
        return (await caches.match(OFFLINE_URL)) || new Response("Offline", { status: 503 });
      }
    })()
  );
});

self.addEventListener("message", (event) => {
  const data = event.data || {};
  if (data.type === "SKIP_WAITING") {
    self.skipWaiting();
    return;
  }
  if (data.type === "CLEAR_CACHE") {
    event.waitUntil(
      (async () => {
        const keys = await caches.keys();
        await Promise.all(keys.map((k) => caches.delete(k)));
      })()
    );
  }
});

// Push notifications.
self.addEventListener("push", (event) => {
  if (!event.data) return;
  let payload;
  try {
    payload = event.data.json();
  } catch {
    payload = { title: "Update", body: String(event.data.text() || "") };
  }

  const title = payload.title || "Order update";
  const options = {
    body: payload.body || "",
    icon: "/icons/icon-192x192.png",
    badge: "/icons/icon-128x128.png",
    tag: payload.tag || "order-update",
    data: payload,
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || "/menu.php";

  event.waitUntil(
    (async () => {
      const allClients = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
      for (const client of allClients) {
        if ("focus" in client) {
          await client.focus();
          if ("navigate" in client) {
            await client.navigate(target);
          }
          return;
        }
      }
      if (self.clients.openWindow) {
        await self.clients.openWindow(target);
      }
    })()
  );
});
