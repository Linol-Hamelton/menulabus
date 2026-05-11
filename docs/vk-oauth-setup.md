# VK ID OAuth Setup

## Implementation Status

- Status: `Implemented (VK ID protocol, Phase 33.2 — 2026-05-11)`
- Last reviewed: `2026-05-11`
- Current implementation notes:
  - Code uses the modern **VK ID** protocol (id.vk.com), not the legacy
    `oauth.vk.com/authorize` endpoint. The legacy endpoint now rejects
    requests with `{"error":"invalid_request","error_description":"Security
    Error"}` for apps that have not been migrated.
  - Flow is PKCE-protected (S256) and returns an OIDC-style `id_token` JWT
    with email + name claims.
  - Runtime behaviour depends on valid production credentials AND on the VK
    Developer Console app being registered as a **VK ID** app (not the legacy
    «Standalone» / «Site» application type).

## Files

- `/auth/oauth/vk-start.php` — authorize redirect + PKCE generation
- `/auth/oauth/vk-callback.php` — token exchange (`id.vk.com/oauth2/auth`) + JWT decode
- `/lib/OAuthVK.php` — claim normalization, JWT parsing, `/oauth2/user_info` fallback

## Runtime Variables

Add to the PHP-FPM pool or environment:

```ini
env[VK_OAUTH_CLIENT_ID] = your-vk-id-app-id
env[VK_OAUTH_CLIENT_SECRET] = your-vk-id-app-secret
```

Or:

```bash
VK_OAUTH_CLIENT_ID=your-vk-id-app-id
VK_OAUTH_CLIENT_SECRET=your-vk-id-app-secret
```

## Provider Example

- site URL: `https://menu.labus.pro`
- redirect URI: `https://menu.labus.pro/auth/oauth/vk-callback.php`

If you use a different domain, register that exact callback URL in VK.

## VK Developer Console

Register the app at <https://id.vk.com/about/business/go> as a **VK ID** app
(NOT the legacy «Standalone» / «Site» type — those continue to use
oauth.vk.com and return Security Error for new sessions). Required settings:

1. Trusted redirect URIs must include
   `https://menu.labus.pro/auth/oauth/vk-callback.php` (one per active
   tenant domain that uses VK login).
2. Scopes: `email`, `vkid.personal_info`. Email scope must be enabled in
   the app settings; otherwise users with private VK profiles can't sign up.
3. App must be in «Active» / «Public» state — apps in moderation return
   `application disabled` to non-admin VK users.

If you already have a legacy app and migration is unavailable, create a
new VK ID app, update the env-variables above, and restart php-fpm.

## Flow Summary

1. `GET /auth/oauth/vk-start.php?mode=login|register`
   - generates HMAC-signed `state` and PKCE `code_verifier`/`code_challenge`
   - stores `vk_oauth_state` and `vk_oauth_pkce` cookies (SameSite=Lax,
     scoped to the callback path, 5-minute expiry)
   - redirects to `https://id.vk.com/authorize?...` with
     `response_type=code`, `code_challenge`, `code_challenge_method=S256`,
     `scope=email vkid.personal_info`
2. `GET /auth/oauth/vk-callback.php?code=...&state=...`
   - validates state cookie + signature, reads PKCE verifier from cookie
   - exchanges `code` for tokens at `POST https://id.vk.com/oauth2/auth`
     with `grant_type=authorization_code` + `code_verifier`
   - decodes `id_token` JWT to read `sub`, `email`, `given_name`,
     `family_name`; falls back to `POST https://id.vk.com/oauth2/user_info`
     if claims are incomplete
   - finds or creates a local user (links by `oauth_identities.subject`
     first, then by email), creates the app session, redirects to
     `/account.php`

## JWT note

The `id_token` is parsed without signature verification: it arrives over
our own server-to-server HTTPS POST to id.vk.com (not via the user agent),
so channel integrity is provided by TLS. Signature verification would
only add value if the token were forwarded through an untrusted hop.

## Verification

1. Open `https://menu.labus.pro/auth.php?mode=login`
2. Click «Войти через VK ID»
3. Expect a redirect to `https://id.vk.com/authorize?...` (NOT
   `oauth.vk.com/authorize?...`)
4. Complete authorization in VK
5. Confirm redirect to `/account.php` and active session

## Diagnostic logging (temporary, 2026-05-11)

`vk-start.php` and `vk-callback.php` currently emit diagnostic entries
on every flow (cookie set status, received cookies, state-head match,
host). Logged to BOTH `error_log()` AND `data/logs/vk-debug.log` (the
latter is writable by the webuser regardless of PHP-FPM pool config).
These are temporary — added to debug an "invalid state (cookie
mismatch)" symptom observed after VK ID migration. The log captures
full state strings, diverge index, and raw QUERY_STRING so we can
pinpoint where the URL-vs-cookie state divergence originates. Will be
removed once the cookie roundtrip is confirmed working end-to-end.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| 500 «VK OAuth is not configured» | env vars missing | set `VK_OAUTH_CLIENT_ID` + `VK_OAUTH_CLIENT_SECRET` in php-fpm pool, restart php-fpm |
| VK shows «Security Error» / 401 | app uses legacy oauth.vk.com (not migrated) | register a VK ID app at id.vk.com and update env vars |
| VK shows «invalid_request» | redirect URI not in trusted list | add `https://menu.labus.pro/auth/oauth/vk-callback.php` to VK app settings |
| Callback fails with «missing PKCE verifier» | cookie blocked by browser policy | check `vk_oauth_pkce` cookie path and SameSite (Lax is correct); usually a third-party-cookie blocker |
| Callback fails with «email is required» | user denied email scope in VK consent | tell user to re-grant email; or implement a fallback that prompts for email post-OAuth (out of scope) |
