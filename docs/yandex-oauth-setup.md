# Yandex OAuth Setup

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-17`
- Current implementation notes:
  - Yandex OAuth flow is implemented in code.
  - Runtime behavior depends on valid production credentials and redirect-URI setup.
  - This document uses `menu.labus.pro` as the provider example; tenant-specific domains require matching redirect URIs.

## Files

- `/yandex-oauth-start.php`
- `/yandex-oauth-callback.php`
- `/lib/OAuthYandex.php`

## Runtime Variables

Add to the PHP-FPM pool or environment:

```ini
env[YANDEX_OAUTH_CLIENT_ID] = your-client-id
env[YANDEX_OAUTH_CLIENT_SECRET] = your-client-secret
```

Or:

```bash
YANDEX_OAUTH_CLIENT_ID=your-client-id
YANDEX_OAUTH_CLIENT_SECRET=your-client-secret
```

## Provider Example

- site URL: `https://menu.labus.pro`
- redirect URI: `https://menu.labus.pro/yandex-oauth-callback.php`

If you use a different domain, register that exact callback URL in Yandex OAuth.

## Flow Summary

1. `/yandex-oauth-start.php?mode=login|register`
   - generates signed `state`
   - stores `y_oauth_state` cookie
   - redirects to Yandex authorization
2. `/yandex-oauth-callback.php`
   - validates `state`
   - exchanges `code` for `access_token`
   - fetches user profile
   - finds or creates a local account
   - creates the app session and redirects to `/account.php`

## Verification

1. Open `https://menu.labus.pro/auth.php`
2. Start Yandex login
3. Complete authorization
4. Confirm redirect to `/account.php`

## Notes

- Yandex usually returns a verified email, but the app should still validate required fields.
- Treat this document as `implemented in code, runtime-config dependent`.
