# Mobile Wrapper (Capacitor)

Goal: wrap the existing production web app into Android/iOS shells with minimal cost.

## Prereqs

- Node.js 20+
- Android Studio (for Android build)
- Xcode (for iOS build, macOS only)

## Install

```bash
cd mobile
npm install
```

## Configure

Default config loads the production site:
- `server.url = https://menu.labus.pro`

This is the cheapest way to get apps running, but store review may require native value-add.

## Android (first run)

```bash
cd mobile
npx cap add android
npx cap sync android
npx cap open android
```

## iOS (macOS)

```bash
cd mobile
npx cap add ios
npx cap sync ios
npx cap open ios
```

## Notes

- If you later decide to load local `www/` assets instead of `server.url`, your in-app origin becomes
  `capacitor://localhost`, and your API calls to `https://menu.labus.pro/api/v1/*` will rely on CORS.
- For auth in a local-origin app, cookie sessions are not reliable (SameSite=Strict). Prefer token auth (`/api/v1/auth/*`).

