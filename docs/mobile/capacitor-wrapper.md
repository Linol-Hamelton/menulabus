# Mobile Wrapper (Capacitor)

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-17`
- Current implementation notes:
  - Capacitor wrapper files exist and build instructions are valid.
  - Current `server.url` is provider-centric and points to `https://menu.labus.pro/menu.php`.
  - This is not yet a tenant-aware mobile strategy.

## Goal

Wrap the existing web app in Android/iOS shells with minimal engineering cost.

## Prerequisites

- Node.js 20+
- Android Studio for Android builds
- Xcode for iOS builds on macOS

## Install

```bash
cd mobile
npm install
```

## Current Configuration

Default config loads the provider deployment:

- `server.url = https://menu.labus.pro/menu.php`

This is enough for a provider-centric wrapper or internal demo app.

## Current Gap

If the product needs tenant-aware mobile behavior, the wrapper must stop assuming a single provider URL and adopt a tenant selection or tenant-specific build strategy.

## Android

```bash
cd mobile
npx cap add android
npx cap sync android
npx cap open android
```

## iOS

```bash
cd mobile
npx cap add ios
npx cap sync ios
npx cap open ios
```

## Notes

- If you switch from remote `server.url` to bundled local assets, in-app origin becomes `capacitor://localhost`.
- In a local-origin app, cookie sessions are unreliable because of `SameSite=Strict`; prefer token auth via `/api/v1/auth/*`.
