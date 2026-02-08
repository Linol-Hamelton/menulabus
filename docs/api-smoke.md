# API Smoke Checklist

## Manual curl checks (quick)

1) Login:
```bash
curl -sS -X POST https://menu.labus.pro/api/v1/auth/login.php \
  -H "Content-Type: application/json" \
  -d '{"email":"<email>","password":"<password>","device_name":"smoke"}'
```

2) Refresh:
```bash
curl -sS -X POST https://menu.labus.pro/api/v1/auth/refresh.php \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"<refresh_token>","device_name":"smoke"}'
```

3) Me:
```bash
curl -sS https://menu.labus.pro/api/v1/auth/me.php \
  -H "Authorization: Bearer <access_token>"
```

4) Menu:
```bash
curl -sS "https://menu.labus.pro/api/v1/menu.php?category=%D0%9F%D0%B8%D1%86%D1%86%D0%B0"
```

5) Create order (idempotent):
```bash
curl -sS -X POST https://menu.labus.pro/api/v1/orders/create.php \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-001" \
  -d '{"items":[{"id":1,"name":"Test","price":100,"quantity":1}],"total":100,"delivery_type":"bar"}'
```

6) Order status:
```bash
curl -sS "https://menu.labus.pro/api/v1/orders/status.php?order_id=<order_id>" \
  -H "Authorization: Bearer <access_token>"
```

7) Push subscribe:
```bash
curl -sS -X POST https://menu.labus.pro/api/v1/push/subscribe.php \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"subscription":{"endpoint":"https://example.invalid/x","keys":{"p256dh":"x","auth":"y"}}}'
```

8) CORS preflight (example for Capacitor):
```bash
curl -i -X OPTIONS https://menu.labus.pro/api/v1/auth/login.php \
  -H "Origin: capacitor://localhost" \
  -H "Access-Control-Request-Method: POST"
```

## Automated runner

```bash
php scripts/api-smoke-runner.php --base=https://menu.labus.pro --email=<email> --password=<password> --run-order=1
```

If local PHP has no CA bundle (Windows/OpenServer), add:
```bash
php scripts/api-smoke-runner.php --base=https://menu.labus.pro --email=<email> --password=<password> --run-order=1 --insecure=1
```

