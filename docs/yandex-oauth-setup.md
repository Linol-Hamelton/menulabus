# Настройка Yandex ID OAuth

## 1. Регистрация приложения в Yandex OAuth

1. Перейдите на [Yandex OAuth](https://oauth.yandex.ru/)
2. Нажмите "Создать приложение"
3. Заполните форму:
   - **Название**: MenuLabus
   - **Платформы**: Выберите "Веб-сервисы"
   - **Redirect URI**: `https://menu.labus.pro/yandex-oauth-callback.php`
   - **Доступ к данным**:
     - ✅ `login:email` — доступ к email пользователя
     - ✅ `login:info` — доступ к имени и аватару
4. Сохраните приложение
5. Скопируйте **ClientID** и **ClientSecret** из настроек приложения

## 2. Настройка переменных окружения на сервере

Добавьте в PHP-FPM pool конфигурацию (`/etc/php/8.1/fpm/pool.d/menu.labus.pro-web.conf`):

```ini
env[YANDEX_OAUTH_CLIENT_ID] = ваш-client-id
env[YANDEX_OAUTH_CLIENT_SECRET] = ваш-client-secret
```

**Или** через `.env` файл (если используется):

```bash
YANDEX_OAUTH_CLIENT_ID=ваш-client-id
YANDEX_OAUTH_CLIENT_SECRET=ваш-client-secret
```

## 3. Перезапуск PHP-FPM

После добавления переменных окружения перезапустите PHP-FPM:

```bash
sudo systemctl restart php8.1-fpm
```

## 4. Проверка работы

1. Откройте https://menu.labus.pro/auth.php
2. Нажмите "Войти через Яндекс ID"
3. Должен открыться экран авторизации Yandex
4. После успешной авторизации произойдёт редирект на `/account.php`

## Технические детали

### Поток авторизации

1. **Инициация**: `/yandex-oauth-start.php?mode=login|register`
   - Генерирует `state` с HMAC подписью
   - Устанавливает cookie `y_oauth_state` (SameSite=Lax)
   - Редиректит на `https://oauth.yandex.ru/authorize`

2. **Callback**: `/yandex-oauth-callback.php`
   - Проверяет `state` через cookie (защита от CSRF)
   - Обменивает `code` на `access_token` через `https://oauth.yandex.ru/token`
   - Получает информацию о пользователе через `https://login.yandex.ru/info`
   - Находит или создаёт пользователя в БД
   - Создаёт сессию и редиректит на `/account.php`

### Данные пользователя

Yandex ID возвращает:
- `id` — уникальный идентификатор (subject)
- `default_email` — email (всегда верифицирован)
- `display_name` — отображаемое имя
- `real_name` — полное имя
- `first_name` — имя

### Безопасность

- CSRF защита через signed state + SameSite=Lax cookie
- Email от Yandex всегда верифицирован (требуется подтверждение при регистрации)
- Access token используется только для получения информации о пользователе
- Таблица `oauth_identities` связывает Yandex ID с локальным user_id

## Структура файлов

```
/yandex-oauth-start.php       — инициация OAuth потока
/yandex-oauth-callback.php    — обработка callback от Yandex
/lib/OAuthYandex.php          — хелпер для работы с Yandex ID API
```

## Troubleshooting

### Ошибка "Yandex OAuth is not configured"
Убедитесь, что переменные окружения `YANDEX_OAUTH_CLIENT_ID` и `YANDEX_OAUTH_CLIENT_SECRET` установлены и PHP-FPM перезапущен.

### Ошибка "invalid state (cookie mismatch)"
Проблема с cookie. Проверьте:
- Домен cookie должен быть `menu.labus.pro`
- Используется HTTPS (secure cookie)
- SameSite=Lax установлен корректно

### Ошибка "Yandex /info missing default_email"
Пользователь не предоставил доступ к email. Убедитесь, что scope `login:email` запрашивается в настройках приложения.

### Redirect URI mismatch
Убедитесь, что в настройках Yandex OAuth добавлен точный URL:
```
https://menu.labus.pro/yandex-oauth-callback.php
```
