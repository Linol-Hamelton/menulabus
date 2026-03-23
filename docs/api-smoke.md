# API Smoke Checklist

## Implementation Status

- Status: `Partial`
- Last reviewed: `2026-03-23`
- Current implementation notes:
  - Public API v1 endpoints are implemented and covered by the OpenAPI contract.
  - `api/v1/bootstrap.php` exists as an internal helper include and is not a public contract endpoint.
  - The CLI smoke runner exists, but it is an internal script and must remain blocked from web access.
  - Example commands below are parameterized and can target either provider or tenant base URLs.

## Base URL

Set the target first:

```bash
BASE_URL="https://menu.labus.pro"
```

Use a tenant URL when validating a tenant deployment:

```bash
BASE_URL="https://test.milyidom.com"
```

## Manual curl checks

1. Login:

```bash
curl -sS -X POST "$BASE_URL/api/v1/auth/login.php" \
  -H "Content-Type: application/json" \
  -d '{"email":"<email>","password":"<password>","device_name":"smoke"}'
```

2. Refresh:

```bash
curl -sS -X POST "$BASE_URL/api/v1/auth/refresh.php" \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"<refresh_token>","device_name":"smoke"}'
```

3. Me:

```bash
curl -sS "$BASE_URL/api/v1/auth/me.php" \
  -H "Authorization: Bearer <access_token>"
```

4. Menu:

```bash
curl -sS "$BASE_URL/api/v1/menu.php"
```

5. Geocode:

```bash
curl -sS "$BASE_URL/api/v1/geocode.php?lat=42.9764&lon=47.5024"
```

6. Create order (idempotent):

```bash
curl -sS -X POST "$BASE_URL/api/v1/orders/create.php" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: smoke-001" \
  -d '{"items":[{"id":1,"name":"Test","price":100,"quantity":1}],"total":100,"delivery_type":"bar"}'
```

7. Order status:

```bash
curl -sS "$BASE_URL/api/v1/orders/status.php?order_id=<order_id>" \
  -H "Authorization: Bearer <access_token>"
```

8. Push subscribe:

```bash
curl -sS -X POST "$BASE_URL/api/v1/push/subscribe.php" \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"subscription":{"endpoint":"https://example.invalid/x","keys":{"p256dh":"x","auth":"y"}}}'
```

9. CORS preflight:

```bash
curl -i -X OPTIONS "$BASE_URL/api/v1/auth/login.php" \
  -H "Origin: capacitor://localhost" \
  -H "Access-Control-Request-Method: POST"
```

## Automated Runner

```bash
php scripts/api-smoke-runner.php --base="$BASE_URL" --email=<email> --password=<password> --run-order=1
```

If local PHP has no CA bundle, add:

```bash
php scripts/api-smoke-runner.php --base="$BASE_URL" --email=<email> --password=<password> --run-order=1 --insecure=1
```

## Important Notes

- The smoke runner is CLI-only and must not be web-reachable.
- For menu endpoint verification, use `GET`; `HEAD` can return `405` depending on server behavior.
