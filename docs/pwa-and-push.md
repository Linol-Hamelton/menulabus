# PWA and Web Push

## Implementation Status

- Status: `Implemented`
- Last reviewed: `2026-04-11`
- Verified against code: [manifest.php](../manifest.php), [sw.js](../sw.js), [js/push-notifications.min.js](../js/push-notifications.min.js), [api/save-push-subscription.php](../api/save-push-subscription.php), [update_order_status.php](../update_order_status.php).

## Purpose

Engineer-facing reference for the PWA (installable app shell) and the web-push notification pipeline that keeps guests informed about their order state without requiring a native wrapper. Also clarifies what the service worker does **not** do (it is intentionally minimal).

## Two surfaces

| Surface | What it does | Source of truth |
|---|---|---|
| **Dynamic manifest** (`/manifest.webmanifest` → `manifest.php`) | Tells the browser this site is installable, which name/colors/icons to use, what the start URL is. Read per-tenant from `settings`. | [manifest.php](../manifest.php) |
| **Service worker** (`/sw.js`) | Navigation fallback to `/offline.html` when the network is down, and push + notification click handlers. Does **not** proxy API/JS/CSS requests. | [sw.js](../sw.js) |
| **Push subscription client** (`js/push-notifications.min.js`) | Registers the SW, asks for Notification permission, calls `pushManager.subscribe()`, POSTs the subscription to `api/save-push-subscription.php`. | [js/push-notifications.min.js](../js/push-notifications.min.js) |
| **Push sender** (`sendPushNotificationsForOrder()` in `update_order_status.php`) | Reads all subscriptions tied to an order and fans out a WebPush message with a friendly title/body per new status. | [update_order_status.php](../update_order_status.php) |

## Dynamic manifest (`manifest.php`)

Served at `/manifest.webmanifest` via rewrite, actually rendered by [manifest.php](../manifest.php). Key points:

- `Content-Type: application/manifest+json; charset=utf-8`, `Cache-Control: public, max-age=3600` (1h edge cache).
- Tenant name and description come from the `settings` table:
  - `app_name` → manifest `name` + `short_name` (first 12 chars).
  - `app_description` → manifest `description`.
- Brand colors come from per-tenant color settings (same keys that feed the brand CSS):
  - `color_primary-color` → `theme_color` (defaults to `#cd1719`).
  - `color_secondary-color` → `background_color` (defaults to `#121212`).
- Values in `settings` are **JSON-encoded**, so every read goes through `json_decode($db->getSetting(...), true)` — plain string reads would return the outer quotes.
- `start_url: '/?source=pwa'` — the `source=pwa` marker is useful for analytics to distinguish PWA launches from normal browser sessions.
- `scope: '/'` — the PWA owns the whole origin, including custom-domain tenant deployments.
- Icons: `128`, `192`, `256`, `384`, `512` px from `/icons/`. `192` and `512` additionally declare `purpose: 'any maskable'` for Android adaptive icons.
- Screenshots: `narrow` (1080×1920, `screenshot1.webp`) and `wide` (1920×1080, `screenshot2.webp`) for richer install prompts.

Because the manifest is PHP-rendered, changing a tenant's brand in the admin panel takes effect within one cache cycle (1h) without any file deploy.

## Service worker (`sw.js`)

Intentionally minimal — this is not a caching SW, it is a **navigation fallback + push handler**. The choice is documented in the header comment:

> Intercept ONLY navigations (HTML documents) to provide offline fallback. Never proxy/intercept API/SSE/JS/CSS/fonts/assets (avoid subtle perf/protocol issues). Keep push notifications working.

### Precache

On `install`, the SW precaches three resources into `labus-static-v13`:

- `/offline.html`
- `/manifest.webmanifest`
- `/icons/favicon.ico`

That's it. All other resources are fetched fresh from the network every time.

### Cache versioning

`CACHE_VERSION = "v13"` — bump this string in [sw.js](../sw.js) whenever the precache list or offline page changes. On `activate` the SW deletes any cache whose key does not match `labus-static-v13`, then calls `self.clients.claim()`.

### Navigation fallback

The `fetch` handler only intercepts navigation requests (`request.mode === 'navigate'` or `Accept` includes `text/html`). Everything else — API calls, SSE streams, JS, CSS, images — passes straight through to the network with no SW involvement. On navigation failure, the SW serves `/offline.html` from the precache; if even that is missing, it returns a bare HTTP 503 `"Offline"` response.

### Message channel

The main thread can send two control messages to the SW:

- `{ type: 'SKIP_WAITING' }` — forces the waiting worker to activate immediately, useful right after a deploy.
- `{ type: 'CLEAR_CACHE' }` — wipes all `caches` entries, used by a "hard refresh" button if one is added later.

### Push handlers

- `push` — parses the payload as JSON (fallback: text), calls `self.registration.showNotification(title, options)` with icon `/icons/icon-192x192.png`, badge `/icons/icon-128x128.png`, tag `order-update` (so repeat notifications collapse on the OS level).
- `notificationclick` — closes the notification, finds an existing window client and focuses + navigates it to the payload `url`, or opens a new window with `clients.openWindow(target)` if no client exists. Target defaults to `/menu.php` if the payload has no `url`.

## Client-side push subscription (`js/push-notifications.min.js`)

Exposed as `window.PushNotifications` with three methods: `init`, `subscribe`, `requestPermission`.

- `init()` — registers `/sw.js`, asks for `Notification.permission`, then either reuses an existing `pushManager.getSubscription()` or calls `subscribe()`.
- `subscribe()` — calls `pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: <VAPID public key, URL-base64 decoded> })`, POSTs the result to `/api/save-push-subscription.php` with optional `phone` and `order_id` context.
- The VAPID public key is currently **baked into the minified JS bundle** (look for the `applicationServerKey` arg in [js/push-notifications.min.js](../js/push-notifications.min.js)). Rotating it means rebuilding and redeploying this file.

Subscription is gated on both `'serviceWorker' in navigator` and `'PushManager' in window` so it silently no-ops on browsers without either.

## Subscription storage

Endpoint: `POST /api/save-push-subscription.php` ([api/save-push-subscription.php](../api/save-push-subscription.php)). Accepts JSON:

```json
{
  "subscription": { "endpoint": "...", "keys": { "p256dh": "...", "auth": "..." } },
  "phone": "+7...",
  "order_id": 1234
}
```

Validation:

- `subscription.endpoint`, `subscription.keys.p256dh`, `subscription.keys.auth` are all required.
- If the session has no `user_id` (guest), both `phone` and `order_id` are required so the record is tied to a specific order.
- Stored in the `push_subscriptions` table with columns `(user_id, phone, order_id, endpoint, p256dh, auth, created_at)`.
- De-dup is by the tuple `(endpoint, p256dh, auth)` — the same device/browser updates the existing row rather than creating duplicates.

## VAPID keys

- Location: `data/vapid-keys.json` — **not** committed to git (listed in `.gitignore`), one file per deployment.
- Structure: `{ "subject": "mailto:ops@…", "publicKey": "...", "privateKey": "..." }`.
- Generate once per deployment with the `Minishlink\WebPush` library or a CLI like `web-push generate-vapid-keys`, then place the JSON under `data/` and ensure it is readable by the PHP-FPM user.
- The **public key must also be baked into `js/push-notifications.min.js`** (see the subscribe flow above) — the server-side and client-side keys must match, otherwise the browser will reject the push.
- Rotation is disruptive: any existing subscribers stop receiving pushes on the next flush. Plan to re-prompt users after a rotation.

## Push send flow (`sendPushNotificationsForOrder`)

Lives inside [update_order_status.php](../update_order_status.php) (not a helper library yet — that's on the cleanup list). Called after a successful status update, in-process, so a slow WebPush flush briefly blocks the response. Steps:

1. Short-circuit if `PHP_VERSION_ID < 80200` — the Minishlink WebPush library requires PHP ≥ 8.2.
2. Load `data/vapid-keys.json`. Missing file or invalid JSON → log + return silently.
3. Query `push_subscriptions` for rows matching the order id **or** matching the user id that owns the order. This covers both logged-in customers (subscribed via their account) and guests (subscribed against a specific order).
4. Require `vendor/autoload.php` and `Minishlink\WebPush\WebPush` / `Subscription`. Any missing class → log + return silently (keeps the site functional even on PHP < 8.2 hosts where the library did not install).
5. Map the new order status to a friendly `{title, body}`:
   - `готовим` → "Готовим ваш заказ #N"
   - `доставляем` → "Заказ #N готов!"
   - `завершён` → "Заказ #N завершён"
   - `отказ` → "Заказ #N отменён"
   - anything else → `"Статус заказа #N"` / `"Новый статус: X"`
6. Queue one `WebPush::queueNotification()` per subscription with payload `{ title, body, icon, data: { orderId, status, url: '/order-track.php?id=N' } }`.
7. Flush. Any non-success report is `error_log`'d but does not throw — dead subscriptions are not auto-pruned today (possible cleanup task).

## Test flow

1. Generate VAPID keys, drop them into `data/vapid-keys.json`, restart PHP-FPM.
2. Rebuild `js/push-notifications.min.js` with the new public key baked in (or verify the existing bundle matches).
3. Open the tenant in Chrome/Edge, accept the Notification permission prompt, confirm the `service-worker` is registered in DevTools → Application.
4. Place a test order as a guest, provide a phone number when prompted, verify a row appears in `push_subscriptions`.
5. In `employee.php`, move the order to `готовим`. A push notification should fire within ~1 second and show "Готовим ваш заказ #N".
6. Click the notification, confirm it focuses/opens `/order-track.php?id=N`.
7. Simulate offline: DevTools → Network → Offline, reload a menu page — you should see `/offline.html` instead of a browser error page.

## Common failure modes

| Symptom | Likely cause | Where to look |
|---|---|---|
| Install prompt never appears | Manifest didn't load (404 or wrong `Content-Type`), SW didn't register, or the site isn't on HTTPS | DevTools → Application → Manifest; Service Workers; Console |
| Subscription succeeds but pushes never arrive | VAPID public key baked into JS does not match `data/vapid-keys.json` on the server | Re-check both values; the browser does not give a clear error |
| PHP error "Minishlink WebPush classes are unavailable" | `composer install` was not run on the deploy host, or PHP < 8.2 | `vendor/autoload.php` exists? Minishlink installed? `php -v`? |
| Only some subscribers receive the push | The other endpoints are dead (user uninstalled the PWA or cleared data) and still sit in `push_subscriptions`. They are logged but not pruned | `error_log` entries from `sendPushNotificationsForOrder`; prune stale rows by `created_at` |
| Icons look generic / theme color wrong after a brand change | Manifest is still the old 1h-cached copy — either wait, or force a bump by rewriting a settings key (touching `mtime` on the underlying row is enough since `Cache-Control: max-age=3600` is edge-level) | `manifest.php`; tenant settings row |
| SW stuck on an old `CACHE_VERSION` | After a deploy, the new SW is "waiting" until the old one dies. Dispatch `{type: 'SKIP_WAITING'}` from the page or force-reload | DevTools → Application → Service Workers → "Update on reload" |

## What this layer does NOT do

- **Does not cache dynamic pages.** Menu pages always come from the network. We rejected offline-first to avoid serving stale prices and stock.
- **Does not proxy API or SSE traffic.** The SW `fetch` handler ignores non-navigation requests entirely. Don't add API caching here without re-reading the SW header comment first.
- **Does not retry dead push endpoints.** There is no background sync / retry. A single `queueNotification` is attempted per status transition.
- **Does not deep-link into the Telegram bot or mobile wrapper.** Those are separate surfaces (see [telegram-bot-setup.md](./telegram-bot-setup.md) and [mobile/capacitor-wrapper.md](./mobile/capacitor-wrapper.md)).

## Related docs

- [order-lifecycle-contract.md](./order-lifecycle-contract.md) — the status transitions that trigger push messages.
- [telegram-bot-setup.md](./telegram-bot-setup.md) — the staff-side notification channel that shares the same status transitions.
- [mobile/capacitor-wrapper.md](./mobile/capacitor-wrapper.md) — the alternative native wrapper for push on iOS, which doesn't support web push today.
