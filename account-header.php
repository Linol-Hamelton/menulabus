<section id="menu" class="section menu">
    <div class="container">
        <div class="section-header-menu">
            <?php if ($user['role'] === 'customer'): ?>
                <h2>Аккаунт</h2>
            <?php endif; ?>
            <div>
                <?php if (in_array($user['role'], ['owner', 'admin'])): ?>
                    <a href="admin-menu.php" class="account-admin" aria-label="Панель администратора">
                        <i class="fas fa-cog"></i>
                    </a>
                <?php endif; ?>
                <?php if ($user['role'] === 'owner'): ?>
                    <a href="owner.php" class="account-owner">₽</a>
                <?php endif; ?>
            </div>
            <div>
                <?php if (in_array($user['role'], ['owner', 'employee', 'admin'])): ?>
                    <a href="employee.php" class="back-to-menu-btn">Заказы</a>
                <?php endif; ?>
                <a href="customer_orders.php" class="back-to-menu-btn">История</a>
            </div>
        </div>
    </div>
</section>