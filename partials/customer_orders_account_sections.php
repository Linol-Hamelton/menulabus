<?php
// Partial for customer_orders.php: renders only the <div class="account-sections"> block.
// Expects: $db (Database instance), $_SESSION['user_id'], $_SESSION['csrf_token'], $activeTab.
?>

<div class="account-sections">
    <section class="account-section">
        <h3>Ваши заказы</h3>
        <?php
        $orders   = $db->getUserOrders($_SESSION['user_id']);
        $statuses = [
            ['status' => 'Приём'],
            ['status' => 'готовим'],
            ['status' => 'доставляем'],
            ['status' => 'завершён'],
            ['status' => 'отказ']
        ];

        if (empty($orders)): ?>
            <p>Нет активных заказов</p>
        <?php else: ?>
            <?php foreach ($statuses as $s): ?>
                <div class="orders-list tab-content <?= $s['status'] === $activeTab ? 'active' : 'Приём' ?>"
                     id="<?= htmlspecialchars($s['status']) ?>">
                    <?php
                    $filtered = array_values(array_filter($orders, fn($o) => $o['status'] === $s['status']));
                    if (empty($filtered)): ?>
                        <p>Нет заказов со статусом «<?= htmlspecialchars($s['status']) ?>»</p>
                    <?php else: ?>
                        <?php foreach ($filtered as $o): ?>
                            <div class="order-item" data-order-id="<?= $o['id'] ?>" data-status="<?= htmlspecialchars($o['status']) ?>">
                                <div class="order-header" data-toggle-order>
                                    <span class="order-id">#<?= $o['id'] ?></span>
                                    <span class="order-status <?= strtolower($o['status']) ?>"><?= $o['status'] ?></span>
                                    <span class="order-date"><?= date('d.m H:i', strtotime($o['created_at'])) ?></span>
                                    <span class="order-total"><?= number_format($o['total'], 0, '.', ' ') ?> ₽</span>
                                    <i class="fas fa-chevron-down employee-toggle-icon"></i>
                                    <span class="employee-toggle-icon">Состав</span>
                                </div>
                                <hr class="divider">

                                <div class="order-customer-info">
                                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($o['user_name']) ?></span>
                                    <?php if ($o['user_phone']): ?>
                                        <span><i class="fas fa-phone"></i> <?= htmlspecialchars($o['user_phone']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <hr class="divider">
                                <div class="order-customer-info">
                                    <div class="delivery-meta">
                                        <span class="delivery-icon-text">
                                            <?php
                                            $iconMap = [
                                                'takeaway' => 'fa-walking',
                                                'delivery' => 'fa-motorcycle',
                                                'table'    => 'fa-store',
                                                'bar'      => 'fa-glass-cheers'
                                            ];
                                            $type   = $o['delivery_type'] ?? 'Не указано';
                                            $icon   = $iconMap[$type] ?? 'fa-question-circle';
                                            ?>
                                            <i class="fas <?= $icon ?>"></i>
                                            <?= htmlspecialchars($type) ?>
                                        </span>

                                        <?php if (!empty($o['delivery_details'])): ?>
                                            <span class="delivery-details">
                                                <?= htmlspecialchars($o['delivery_details']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($o['updater_name']): ?>
                                        <span><i class="fas fa-user-edit"></i> Обновил: <?= htmlspecialchars($o['updater_name']) ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="order-items">
                                    <hr class="divider">
                                    <?php foreach ($o['items'] as $item): ?>
                                        <div class="order-product">
                                            <span class="product-name"><?= htmlspecialchars($item['name']) ?></span>
                                            <span class="product-quantity"><?= $item['quantity'] ?> × <?= $item['price'] ?> ₽</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="order-actions">
                                    <?php if ($o['status'] === 'завершён' || $o['status'] === 'отказ'): ?>
                                        <form class="repeat-order-form" data-order-id="<?= $o['id'] ?>">
                                            <input type="hidden" name="action" value="repeat_order">
                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                            <button type="submit" class="checkout-btn"
                                                    title="Добавить все товары из этого заказа в корзину">
                                                <i class="fas fa-redo"></i> Повторить
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>

