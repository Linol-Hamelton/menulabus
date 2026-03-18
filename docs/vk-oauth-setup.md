# VK OAuth Setup

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-17`
- Current implementation notes:
  - VK OAuth flow is implemented in code.
  - Runtime behavior depends on valid production credentials and redirect-URI setup.
  - This document uses `menu.labus.pro` as the provider example; tenant-specific domains require matching redirect URIs.

## Files

- `/vk-oauth-start.php`
- `/vk-oauth-callback.php`
- `/lib/OAuthVK.php`

## Runtime Variables

Add to the PHP-FPM pool or environment:

```ini
env[VK_OAUTH_CLIENT_ID] = your-app-id
env[VK_OAUTH_CLIENT_SECRET] = your-app-secret
```

Or:

```bash
VK_OAUTH_CLIENT_ID=your-app-id
VK_OAUTH_CLIENT_SECRET=your-app-secret
```

## Provider Example

- site URL: `https://menu.labus.pro`
- redirect URI: `https://menu.labus.pro/vk-oauth-callback.php`

If you use a different domain, register that exact callback URL in VK.

## Flow Summary

1. `/vk-oauth-start.php?mode=login|register`
   - generates signed `state`
   - stores `vk_oauth_state` cookie
   - redirects to VK authorization
2. `/vk-oauth-callback.php`
   - validates `state`
   - exchanges `code` for `access_token`
   - fetches user profile
   - finds or creates a local account
   - creates the app session and redirects to `/account.php`

## Verification

1. Open `https://menu.labus.pro/auth.php`
2. Start VK login
3. Complete authorization
4. Confirm redirect to `/account.php`

## Notes

- Email from VK can be optional depending on user consent and app scopes.
- If email is mandatory for local account creation, handle missing-email failures explicitly.
- Treat this document as `implemented in code, runtime-config dependent`.
