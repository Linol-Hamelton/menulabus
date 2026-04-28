<section id="menu" class="section menu account-header-bar">
    <div class="container">
        <div class="section-header-menu">
            <?php if (($user['role'] ?? '') === 'customer'): ?>
                <h2>Аккаунт</h2>
            <?php endif; ?>

            <div class="section-header-quick-actions">
                <?php if (in_array(($user['role'] ?? ''), ['owner', 'admin'], true)): ?>
                    <a href="admin/menu.php" class="account-admin" aria-label="Панель администратора" title="Панель администратора">
                        <svg class="account-action-icon" aria-hidden="true" viewBox="0 0 256 256">
                            <use href="/images/icons/phosphor-sprite.svg#gear-six"></use>
                        </svg>
                    </a>
                <?php endif; ?>

                <?php if (($user['role'] ?? '') === 'owner'): ?>
                    <a href="owner.php" class="account-owner" aria-label="Аналитика владельца" title="Аналитика владельца">
                        <svg class="account-action-icon" aria-hidden="true" viewBox="0 0 256 256">
                            <use href="/images/icons/phosphor-sprite.svg#chart-bar"></use>
                        </svg>
                    </a>
                <?php endif; ?>
            </div>

            <div class="section-header-nav-actions">
                <?php if (in_array(($user['role'] ?? ''), ['owner', 'employee', 'admin'], true)): ?>
                    <a href="employee.php" class="back-to-menu-btn">Заказы</a>
                    <a href="kds.php" class="back-to-menu-btn" target="_blank" rel="noopener">Кухня</a>
                    <a href="admin/waitlist.php" class="back-to-menu-btn">Очередь</a>
                    <a href="admin/staff.php" class="back-to-menu-btn">Смены</a>
                    <a href="help.php" class="back-to-menu-btn">Помощь</a>
                <?php endif; ?>
                <a href="customer_orders.php" class="back-to-menu-btn">История</a>
            </div>
        </div>
    </div>
</section>
