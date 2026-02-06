// sw.js — финальная версия с офлайн-страницей и push-уведомлениями
const CACHE_NAME = "labus-v5-minified";
const DYNAMIC_CACHE = "labus-dynamic-v5";
const OFFLINE_URL = '/offline.html';   // добавляем офлайн-страницу

// 1. Расширяем APP_SHELL офлайн-страницей и её ресурсами
const APP_SHELL = [
  "/",
  OFFLINE_URL,                          // ← новое
  "/css/fa-styles.min.css",
  "/css/account-styles.min.css",
  "/css/fa-purged.min.css",
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
  "/manifest.json",
  "/version.json",
];

// 2. Установка – с обработкой ошибок для каждого ресурса
self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => {
        console.log("Кэшируем основные файлы");
        // Кэшируем каждый ресурс отдельно, чтобы ошибка одного не ломала всю установку
        const promises = APP_SHELL.map(url =>
          cache.add(url).catch(error => {
            console.warn(`Не удалось кэшировать ${url}:`, error);
            // Пропускаем ошибку, продолжаем с остальными
            return Promise.resolve();
          })
        );
        return Promise.all(promises);
      })
      .then(() => {
        console.log("Все ресурсы обработаны (некоторые могли не закэшироваться)");
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
              console.log("Удаляем старый кэш:", cacheName);
              return caches.delete(cacheName);
            }
          })
        )
      )
      .then(() => self.clients.claim())
  );
});

// 4. FETCH — добавляем только офлайн-страницу, всё остальное оставляем
self.addEventListener('fetch', (event) => {
  // 1. Пропускаем не-GET и chrome-extension
  if (event.request.method !== 'GET' ||
      event.request.url.startsWith('chrome-extension://')) {
    return;
  }

    // 2. Исключаем POST-запросы на customer_orders.php
  if (
    event.request.method === 'POST' &&
    event.request.url.includes('customer_orders.php')
  ) {
    return; // отдаём браузеру, SW не мешает
  }

  // Специфичные правила (без изменений)
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

  if (event.request.url.includes('/api/') ||
      event.request.url.includes('/auth/')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Главное: «Сеть сначала, кэш/офлайн потом»
  event.respondWith(
    fetch(event.request)
      .then(networkResponse => {
        const responseToCache = networkResponse.clone();
        if (networkResponse.status === 200 &&
            !event.request.url.includes('/api/') &&
            !event.request.url.includes('/auth/')) {
          caches.open(DYNAMIC_CACHE)
            .then(cache => cache.put(event.request, responseToCache));
        }
        return networkResponse;
      })
      .catch(() =>
        caches.match(event.request)
          .then(cached => cached || caches.match(OFFLINE_URL))
      )
  );
});

// 5. Обработка сообщений — без изменений
self.addEventListener('message', (event) => {
  if (event.data.type === 'SKIP_WAITING') self.skipWaiting();
  if (event.data.type === 'CLEAR_CACHE') {
    caches.keys()
      .then(names => Promise.all(names.map(n => caches.delete(n))))
      .then(() => console.log('Все кэши очищены'));
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