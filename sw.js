// sw.js — финальная версия с офлайн-страницей и push-уведомлениями
const CACHE_NAME = "labus-v10-minified";
const DYNAMIC_CACHE = "labus-dynamic-v10";
const OFFLINE_URL = '/offline.html';   // добавляем офлайн-страницу

// 1. Расширяем APP_SHELL офлайн-страницей и её ресурсами
const APP_SHELL = [
  "/",
  OFFLINE_URL,                          // ← новое
  "/css/fa-styles.min.css",
  "/css/account-styles.min.css",
  "/css/fa-purged.min.css",
  "/css/menu-alt.min.css",
  "/css/menu-content-info.min.css",
  "/fonts/Inter-SemiBold.woff2",
  "/fonts/proxima-nova-medium.woff2",
  "/fonts/Magistral-Medium.woff2",
  "/js/app.min.js",
  "/js/cart.min.js",
  "/js/security.min.js",
  "/js/account.min.js",
  "/js/ws-orders.min.js",
  "/js/version-checker.min.js",
  "/js/pwa-install.min.js",
  "/icons/favicon.ico",
  "/manifest.webmanifest",
  "/version.json",
];

// 2. Установка – с обработкой ошибок для каждого ресурса
self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => {
        // Кэшируем каждый ресурс отдельно, чтобы ошибка одного не ломала всю установку
        const promises = APP_SHELL.map(url =>
          cache.add(url).catch(error => {
            // ignore cache miss during install, continue caching others
            // Пропускаем ошибку, продолжаем с остальными
            return Promise.resolve();
          })
        );
        return Promise.all(promises);
      })
      .then(() => {
        return self.skipWaiting();
      })
  );
});

// 3. Активация – без изменений
self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((cacheNames) =>
        Promise.all(
          cacheNames.map((cacheName) => {
            if (cacheName !== CACHE_NAME && cacheName !== DYNAMIC_CACHE) {
              return caches.delete(cacheName);
            }
          })
        )
      )
      .then(() => self.clients.claim())
  );
});

// 4. FETCH — добавляем только офлайн-страницу, всё остальное оставляем
const STATIC_DESTINATIONS = new Set(['script', 'image', 'font']);

function isHtmlRequest(request) {
  if (request.mode === 'navigate') return true;
  const accept = request.headers.get('accept') || '';
  return accept.includes('text/html');
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  const response = await fetch(request);
  if (response && shouldStore(response)) {
    const cache = await caches.open(CACHE_NAME);
    cache.put(request, response.clone());
  }
  return response;
}

async function networkFirst(request) {
  try {
    const response = await fetch(request);
    if (response && shouldStore(response)) {
      const cache = await caches.open(DYNAMIC_CACHE);
      cache.put(request, response.clone());
    }
    return response;
  } catch (error) {
    const cached = await caches.match(request);
    return cached || caches.match(OFFLINE_URL);
  }
}

function shouldStore(response) {
  if (!response || response.status !== 200) return false;
  const cc = (response.headers.get('Cache-Control') || '').toLowerCase();
  // Respect server intent: never cache no-store/no-cache pages (auth/account).
  if (cc.includes('no-store') || cc.includes('no-cache')) return false;
  return true;
}

self.addEventListener('fetch', (event) => {
  // 1. ???????????????????? ????-GET ?? chrome-extension
  if (event.request.method !== 'GET' ||
      event.request.url.startsWith('chrome-extension://')) {
    return;
  }

    // 2. ?????????????????? POST-?????????????? ???? customer_orders.php
  if (
    event.request.method === 'POST' &&
    event.request.url.includes('customer_orders.php')
  ) {
    return; // ???????????? ????????????????, SW ???? ????????????
  }

  // ???????????????????? ?????????????? (?????? ??????????????????)
  if (event.request.url.includes('version.json')) {
    event.respondWith(
      fetch(event.request)
        .then(r => r)
        .catch(() =>
          caches.match(event.request) ||
          new Response(JSON.stringify({ version: '1.0.0' }), {
            headers: { 'Content-Type': 'application/json' }
          })
        )
    );
    return;
  }

  // Never let SW cache/serve auth and OAuth flow HTML.
  // This prevents stale auth.php markup (e.g. old <form action=...>) after deploy.
  try {
    const url = new URL(event.request.url);
    const bypassPaths = new Set([
      '/auth.php',
      '/google-oauth-start.php',
      '/google-oauth-callback.php',
    ]);
    if (bypassPaths.has(url.pathname)) {
      event.respondWith(fetch(event.request, { cache: 'no-store' }));
      return;
    }
  } catch (e) {
    // ignore URL parse issues
  }

  if (event.request.url.includes('/api/') ||
      event.request.url.includes('/auth/')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Never cache dynamic style endpoints and live update channels.
  if (event.request.url.includes('/auto-colors.php') ||
      event.request.url.includes('/auto-fonts.php')) {
    event.respondWith(fetch(event.request, { cache: 'no-store' }));
    return;
  }

  if (event.request.url.includes('/orders-sse.php') ||
      event.request.url.includes('/ws-poll.php')) {
    event.respondWith(fetch(event.request, { cache: 'no-store' }));
    return;
  }

  // Styles should prefer network to avoid stale theme/layout after deploys.
  if (event.request.destination === 'style') {
    event.respondWith(networkFirst(event.request));
    return;
  }

  if (isHtmlRequest(event.request)) {
    event.respondWith(networkFirst(event.request));
    return;
  }

  if (STATIC_DESTINATIONS.has(event.request.destination)) {
    event.respondWith(cacheFirst(event.request));
    return;
  }

  event.respondWith(networkFirst(event.request));
});

// 5. Обработка сообщений — без изменений
self.addEventListener('message', (event) => {
  if (event.data.type === 'SKIP_WAITING') self.skipWaiting();
  if (event.data.type === 'CLEAR_CACHE') {
    caches.keys()
      .then(names => Promise.all(names.map(n => caches.delete(n))))
      .then(() => Promise.resolve());
  }
});

// 6. Push-уведомления
self.addEventListener('push', (event) => {
  if (!event.data) return;

  try {
    const data = event.data.json();
    const title = data.title || 'Статус заказа обновлён';
    const options = {
      body: data.body || 'Проверьте статус вашего заказа',
      icon: '/icons/icon-192x192.png',
      badge: '/icons/icon-128x128.png',
      tag: data.tag || 'order-update',
      data: data,
      actions: [
        {
          action: 'open',
          title: 'Открыть заказы'
        },
        {
          action: 'close',
          title: 'Закрыть'
        }
      ]
    };

    event.waitUntil(
      self.registration.showNotification(title, options)
    );
  } catch (e) {
    console.error('Push parsing error:', e);
    // Fallback для простого текста
    const title = 'Статус заказа обновлён';
    const options = {
      body: event.data.text() || 'Проверьте статус вашего заказа',
      icon: '/icons/icon-192x192.png',
      badge: '/icons/icon-128x128.png',
      tag: 'order-update'
    };
    event.waitUntil(self.registration.showNotification(title, options));
  }
});

// 7. Клик по уведомлению
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const data = event.notification.data || {};
  const orderId = data.orderId;

  let url = '/customer_orders.php';
  if (orderId) {
    url += `?order=${orderId}`;
  }

  const promiseChain = clients.matchAll({
    type: 'window',
    includeUncontrolled: true
  }).then((windowClients) => {
    // Ищем открытое окно с нашим сайтом
    for (let client of windowClients) {
      if (client.url.includes('/customer_orders.php') && 'focus' in client) {
        return client.focus();
      }
    }
    // Если окно не найдено, открываем новое
    if (clients.openWindow) {
      return clients.openWindow(url);
    }
  });

  event.waitUntil(promiseChain);
});
