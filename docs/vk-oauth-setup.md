# Настройка VK ID OAuth

## 1. Создание приложения VK

1. Перейдите на [VK Developers](https://dev.vk.com/apps/create)
2. Нажмите "Создать приложение"
3. Заполните форму:
   - **Название**: MenuLabus
   - **Платформа**: Выберите "Сайт"
   - **Адрес сайта**: `https://menu.labus.pro`
   - **Базовый домен**: `menu.labus.pro`
4. Создайте приложение
5. Перейдите в **Настройки** → **OAuth**
6. Добавьте **Redirect URI**: `https://menu.labus.pro/vk-oauth-callback.php`
7. Включите **OAuth 2.0**
8. Скопируйте **ID приложения** (Client ID) и **Защищённый ключ** (Client Secret)

## 2. Настройка переменных окружения на сервере

Добавьте в PHP-FPM pool конфигурацию (`/etc/php/8.1/fpm/pool.d/menu.labus.pro-web.conf`):

```ini
env[VK_OAUTH_CLIENT_ID] = ваш-app-id
env[VK_OAUTH_CLIENT_SECRET] = ваш-app-secret
```

**Или** через `.env` файл (если используется):

```bash
VK_OAUTH_CLIENT_ID=ваш-app-id
VK_OAUTH_CLIENT_SECRET=ваш-app-secret
```

## 3. Перезапуск PHP-FPM

После добавления переменных окружения перезапустите PHP-FPM:

```bash
sudo systemctl restart php8.1-fpm
```

## 4. Проверка работы

1. Откройте https://menu.labus.pro/auth.php
2. Нажмите "Войти через VK ID"
3. Должен открыться экран авторизации VK
4. После успешной авторизации произойдёт редирект на `/account.php`

## Технические детали

### Поток авторизации

1. **Инициация**: `/vk-oauth-start.php?mode=login|register`
   - Генерирует `state` с HMAC подписью
   - Устанавливает cookie `vk_oauth_state` (SameSite=Lax)
   - Редиректит на `https://oauth.vk.com/authorize`

2. **Callback**: `/vk-oauth-callback.php`
   - Проверяет `state` через cookie (защита от CSRF)
   - Обменивает `code` на `access_token` через `https://oauth.vk.com/access_token`
   - Получает информацию о пользователе через `https://api.vk.com/method/users.get`
   - Находит или создаёт пользователя в БД
   - Создаёт сессию и редиректит на `/account.php`

### Данные пользователя

VK ID возвращает:
- `user_id` — уникальный идентификатор (subject)
- `email` — email (опциональный, требуется разрешение)
- `first_name` — имя
- `last_name` — фамилия
- `photo_200` — аватар

**Важно**: Email **опционален** — если пользователь не разрешит доступ к email, авторизация не пройдёт (email обязателен для создания аккаунта).

### Scopes (разрешения)

- `email` — доступ к email пользователя (**обязательно**)
- `phone` — доступ к телефону (требует одобрения VK, не используется в базовой реализации)

### Безопасность

- CSRF защита через signed state + SameSite=Lax cookie
- Email может быть не предоставлен пользователем — обработка ошибки
- Access token используется только для получения информации о пользователе
- Таблица `oauth_identities` связывает VK ID с локальным user_id

## Структура файлов

```
/vk-oauth-start.php           — инициация OAuth потока
/vk-oauth-callback.php        — обработка callback от VK
/lib/OAuthVK.php              — хелпер для работы с VK API
```

## Troubleshooting

### Ошибка "VK OAuth is not configured"
Убедитесь, что переменные окружения `VK_OAUTH_CLIENT_ID` и `VK_OAUTH_CLIENT_SECRET` установлены и PHP-FPM перезапущен.

### Ошибка "invalid state (cookie mismatch)"
Проблема с cookie. Проверьте:
- Домен cookie должен быть `menu.labus.pro`
- Используется HTTPS (secure cookie)
- SameSite=Lax установлен корректно

### Ошибка "email is required"
Пользователь не предоставил доступ к email при авторизации VK. Попросите пользователя разрешить доступ к email в настройках приложения VK или при повторной авторизации.

### Redirect URI mismatch
Убедитесь, что в настройках VK приложения добавлен точный URL:
```
https://menu.labus.pro/vk-oauth-callback.php
```

### Invalid app ID
Проверьте, что:
- VK приложение активировано и не заблокировано
- App ID скопирован корректно (только цифры)
- Client Secret скопирован корректно

## Получение телефона (опционально)

Для получения телефона через VK OAuth:

1. Добавьте scope `phone` в `vk-oauth-start.php`:
   ```php
   'scope' => 'email,phone',
   ```

2. **Важно**: доступ к `phone` scope требует одобрения от VK. Подайте заявку в VK Developers с обоснованием необходимости доступа к телефону.

3. После одобрения VK вернёт телефон в ответе `access_token`:
   ```php
   $phone = (string)($tok['phone'] ?? '');
   ```

4. Обновите `OAuthVK::getUserInfo()` для обработки телефона.

**Примечание**: без одобрения VK scope `phone` не будет работать, и пользователь не увидит запрос доступа к телефону.
