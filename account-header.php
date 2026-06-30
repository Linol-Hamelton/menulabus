<?php
/**
 * account-header.php — общая шапка авторизованных страниц.
 * Phase L103.2 — добавлен switcher активной торговой точки для
 * owner/admin/employee (см. /api/set-active-location.php).
 */

$_role = (string)($user['role'] ?? '');
$_isStaff = in_array($_role, ['owner', 'admin', 'employee'], true);

$_locations = [];
$_activeLocationId = 0;
if ($_isStaff && isset($GLOBALS['db']) && is_object($GLOBALS['db'])) {
    try {
        $_locations = $GLOBALS['db']->listLocations(true);
        $_activeLocationId = $GLOBALS['db']->activeLocationId();
    } catch (Throwable $_) {
        $_locations = [];
    }
} elseif ($_isStaff && class_exists('Database')) {
    try {
        $_db = Database::getInstance();
        $_locations = $_db->listLocations(true);
        $_activeLocationId = $_db->activeLocationId();
    } catch (Throwable $_) {
        $_locations = [];
    }
}
$_showSwitcher = $_isStaff && count($_locations) > 1;
$_csrf = class_exists('Csrf') ? Csrf::token() : (string)($GLOBALS['csrfToken'] ?? '');
$_scriptNonce = (string)($GLOBALS['scriptNonce'] ?? '');
?>
<?php if ($_showSwitcher): ?>
<link rel="stylesheet" href="/css/location-switcher.css?v=<?= htmlspecialchars((string)($GLOBALS['appVersion'] ?? '1')) ?>">
<?php endif; ?>
<section id="menu" class="section menu account-header-bar">
    <div class="container">
        <div class="section-header-menu">
            <?php if ($_role === 'customer'): ?>
                <h2>Аккаунт</h2>
            <?php elseif ($_showSwitcher): ?>
                <form class="location-switcher" action="/api/set-active-location.php" method="POST" id="locationSwitcherForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_csrf) ?>">
                    <label class="location-switcher-label">
                        <span class="location-switcher-caption">Точка</span>
                        <select name="location_id" class="location-switcher-select" id="locationSwitcherSelect" required>
                            <?php foreach ($_locations as $_loc): ?>
                                <option value="<?= (int)$_loc['id'] ?>"<?= ((int)$_loc['id'] === $_activeLocationId) ? ' selected' : '' ?>><?= htmlspecialchars((string)$_loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <noscript>
                        <button type="submit" class="location-switcher-submit">Сменить</button>
                    </noscript>
                </form>
            <?php endif; ?>

            <div class="section-header-quick-actions">
                <?php if (in_array($_role, ['owner', 'admin'], true)): ?>
                    <a href="/admin/menu.php" class="account-admin" aria-label="Панель администратора" title="Панель администратора">
                        <svg class="account-action-icon" aria-hidden="true" viewBox="0 0 256 256">
                            <use href="/images/icons/phosphor-sprite.svg#gear-six"></use>
                        </svg>
                    </a>
                <?php endif; ?>

                <?php if ($_role === 'owner'): ?>
                    <a href="/owner.php" class="account-owner" aria-label="Аналитика владельца" title="Аналитика владельца">
                        <svg class="account-action-icon" aria-hidden="true" viewBox="0 0 256 256">
                            <use href="/images/icons/phosphor-sprite.svg#chart-bar"></use>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>

            <div class="section-header-nav-actions">
                <?php if (in_array($_role, ['owner', 'employee', 'admin'], true)): ?>
                    <a href="/employee.php" class="back-to-menu-btn">Заказы</a>
                    <a href="/kds/index.php" class="back-to-menu-btn" target="_blank" rel="noopener">Кухня</a>
                    <a href="/admin/waitlist.php" class="back-to-menu-btn">Очередь</a>
                    <a href="/admin/staff.php" class="back-to-menu-btn">Смены</a>
                    <?php if (in_array($_role, ['owner', 'admin'], true)): ?>
                        <a href="/admin/inventory.php" class="back-to-menu-btn">Склад</a>
                        <a href="/admin/semi-finished.php" class="back-to-menu-btn">Заготовки</a>
                        <a href="/admin/stocktake.php" class="back-to-menu-btn">Инвент.</a>
                        <a href="/admin/vsd.php" class="back-to-menu-btn">ВСД</a>
                        <a href="/admin/egais.php" class="back-to-menu-btn">ЕГАИС</a>
                    <?php endif; ?>
                    <a href="/help.php" class="back-to-menu-btn">Помощь</a>
                <?php endif; ?>
                <a href="/customer_orders.php" class="back-to-menu-btn">История</a>
            </div>
        </div>
    </div>
</section>
<?php if ($_showSwitcher && $_scriptNonce !== ''): ?>
<script src="/js/location-switcher.js?v=<?= htmlspecialchars((string)($GLOBALS['appVersion'] ?? '1')) ?>" defer nonce="<?= htmlspecialchars($_scriptNonce) ?>"></script>
<?php endif; ?>
