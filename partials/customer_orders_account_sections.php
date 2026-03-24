<?php
// Partial for customer_orders.php: renders only the <div class="account-sections"> block.
// Expects: $db (Database instance), $_SESSION['user_id'], $_SESSION['csrf_token'], $orderView.
require_once __DIR__ . '/../lib/orders/lifecycle.php';

$orders = $db->getUserOrders($_SESSION['user_id']);
$orderView = ($orderView ?? 'active') === 'history' ? 'history' : 'active';

$activeStatuses = cleanmenu_order_open_statuses();
$historyStatuses = cleanmenu_order_closed_statuses();

$deliveryTypeLabels = [
    'takeaway' => 'Самовывоз',
    'delivery' => 'Доставка',
    'table' => 'На стол',
    'bar' => 'К стойке бара',
];

$statusTitles = [
    'Приём' => 'Принят',
    'готовим' => 'Готовим',
    'доставляем' => 'В пути',
    'завершён' => 'Завершён',
    'отказ' => 'Отказ',
];

$statusToneMap = [
    'Приём' => 'is-intake',
    'готовим' => 'is-cooking',
    'доставляем' => 'is-delivery',
    'завершён' => 'is-done',
    'отказ' => 'is-cancelled',
];

$activeOrders = array_values(array_filter($orders, static fn($order) => in_array((string)($order['status'] ?? ''), $activeStatuses, true)));
$historyOrders = array_values(array_filter($orders, static fn($order) => in_array((string)($order['status'] ?? ''), $historyStatuses, true)));
$lifecycleSummary = cleanmenu_order_lifecycle_summary($orders);

$getDeliveryTitle = static function (array $order) use ($deliveryTypeLabels): string {
    $type = (string)($order['delivery_type'] ?? '');
    return $deliveryTypeLabels[$type] ?? ucfirst($type ?: 'Заказ');
};

$getDeliverySummary = static function (array $order): string {
    $type = (string)($order['delivery_type'] ?? '');
    $raw = trim((string)($order['delivery_details'] ?? ''));

    return match ($type) {
        'table' => $raw !== '' ? 'Стол ' . $raw : 'Номер стола уточняется',
        'bar' => 'Выдача у барной стойки',
        'takeaway' => 'Выдача в заведении',
        'delivery' => $raw !== '' ? 'Адрес доставки указан' : 'Адрес доставки уточняется',
        default => $raw !== '' ? 'Детали указаны' : 'Детали появятся после обновления статуса',
    };
};

$getDeliveryDetail = static function (array $order): string {
    $type = (string)($order['delivery_type'] ?? '');
    $raw = trim((string)($order['delivery_details'] ?? ''));

    if ($type === 'table' && $raw !== '') {
        return 'Стол ' . $raw;
    }
    if ($type === 'bar') {
        return 'Выдача у барной стойки';
    }
    if ($type === 'takeaway') {
        return 'Заберите заказ в заведении в удобное время';
    }
    if ($raw !== '') {
        return $raw;
    }

    return $type === 'delivery'
        ? 'Адрес доставки уточняется'
        : 'Детали выдачи появятся после обновления статуса';
};

$renderOrderCard = static function (array $order, bool $isHistory) use ($getDeliveryTitle, $getDeliverySummary, $getDeliveryDetail, $statusTitles, $statusToneMap): void {
    $status = (string)($order['status'] ?? '');
    $statusTitle = $statusTitles[$status] ?? $status;
    $statusTone = $statusToneMap[$status] ?? '';
    $deliveryTitle = $getDeliveryTitle($order);
    $deliverySummary = $getDeliverySummary($order);
    $deliveryDetail = $getDeliveryDetail($order);
    $createdAt = date('d.m H:i', strtotime((string)($order['created_at'] ?? 'now')));
    $total = number_format((float)($order['total'] ?? 0), 0, '.', ' ') . ' ₽';
    $itemsCount = array_reduce($order['items'] ?? [], static fn($sum, $item) => $sum + (int)($item['quantity'] ?? 0), 0);
    $detailsId = 'order-items-' . (int)$order['id'];
    $lifecycleMeta = cleanmenu_order_lifecycle_meta($order);
    ?>
    <article class="order-item order-item--customer <?= $isHistory ? 'order-item--history' : 'order-item--active' ?>"
             data-order-id="<?= (int)$order['id'] ?>"
             data-status="<?= htmlspecialchars($status) ?>"
             data-order-lifecycle="<?= htmlspecialchars($lifecycleMeta['lifecycle_bucket']) ?>">
        <div class="customer-order-card">
            <div class="customer-order-summary">
                <div class="customer-order-primary">
                    <div class="customer-order-topline">
                        <span class="order-id">#<?= (int)$order['id'] ?></span>
                        <span class="order-status-chip <?= htmlspecialchars($statusTone) ?>"><?= htmlspecialchars($statusTitle) ?></span>
                        <?php if (!$isHistory && !$lifecycleMeta['is_closed']): ?>
                            <span class="account-badge account-badge--<?= htmlspecialchars($lifecycleMeta['lifecycle_bucket']) ?>"><?= htmlspecialchars($lifecycleMeta['lifecycle_label']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="customer-order-meta">
                        <span class="order-date"><?= htmlspecialchars($createdAt) ?></span>
                        <span class="employee-order-age employee-order-age--<?= htmlspecialchars($lifecycleMeta['age_tone']) ?>"><?= htmlspecialchars($lifecycleMeta['age_label']) ?></span>
                        <span class="customer-order-items-count"><?= (int)$itemsCount ?> поз.</span>
                        <span class="order-total"><?= htmlspecialchars($total) ?></span>
                    </div>
                    <div class="customer-order-delivery">
                        <span class="customer-order-delivery-type"><?= htmlspecialchars($deliveryTitle) ?></span>
                        <span class="customer-order-delivery-detail"><?= htmlspecialchars($deliverySummary) ?></span>
                    </div>
                </div>
                <div class="customer-order-actions-top">
                    <?php if ($isHistory): ?>
                        <form class="repeat-order-form" data-order-id="<?= (int)$order['id'] ?>">
                            <input type="hidden" name="action" value="repeat_order">
                            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <button type="submit" class="checkout-btn customer-order-action" title="Добавить все товары из этого заказа в корзину">
                                Повторить
                            </button>
                        </form>
                    <?php else: ?>
                        <a href="order-track.php?id=<?= (int)$order['id'] ?>" class="checkout-btn customer-order-action" title="Отследить статус заказа">
                            Отследить
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <button type="button" class="customer-order-expand" data-toggle-order aria-controls="<?= htmlspecialchars($detailsId) ?>" aria-expanded="false">
                <span class="customer-order-expand-text">Состав и детали</span>
                <span class="order-toggle-icon" aria-hidden="true"></span>
            </button>

            <div class="order-items customer-order-details" id="<?= htmlspecialchars($detailsId) ?>">
                <div class="customer-order-detail-line">
                    <span class="customer-order-detail-label">Получение</span>
                    <span class="customer-order-detail-value"><?= htmlspecialchars($deliveryDetail) ?></span>
                </div>

                <?php if (!empty($order['user_phone'])): ?>
                    <div class="customer-order-detail-line">
                        <span class="customer-order-detail-label">Контакт</span>
                        <span class="customer-order-detail-value"><?= htmlspecialchars((string)$order['user_phone']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['updater_name'])): ?>
                    <div class="customer-order-detail-line">
                        <span class="customer-order-detail-label">Последнее обновление</span>
                        <span class="customer-order-detail-value"><?= htmlspecialchars((string)$order['updater_name']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!$isHistory && !$lifecycleMeta['is_closed']): ?>
                    <div class="customer-order-detail-line">
                        <span class="customer-order-detail-label">Следующий шаг</span>
                        <span class="customer-order-detail-value"><?= htmlspecialchars($lifecycleMeta['next_action_label']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="customer-order-products">
                    <?php foreach (($order['items'] ?? []) as $item): ?>
                        <div class="order-product">
                            <span class="product-name"><?= htmlspecialchars((string)($item['name'] ?? 'Блюдо')) ?></span>
                            <span class="product-quantity"><?= (int)($item['quantity'] ?? 1) ?> × <?= htmlspecialchars((string)($item['price'] ?? '0')) ?> ₽</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </article>
    <?php
};
?>

<div class="account-sections">
    <section class="account-section customer-orders-section">
        <div class="account-section-head">
            <div class="account-section-heading">
                <span class="account-section-kicker">Orders</span>
                <h3>Ваши заказы</h3>
                <p class="account-section-copy">Активные заказы вынесены наверх, история оставлена отдельно, чтобы быстрее находить нужное действие.</p>
            </div>
            <div class="customer-orders-switch" role="tablist" aria-label="Переключение списка заказов">
                <button type="button"
                        class="customer-orders-switch-btn <?= $orderView === 'active' ? 'active' : '' ?>"
                        data-order-view="active"
                        aria-pressed="<?= $orderView === 'active' ? 'true' : 'false' ?>">
                    Активные
                    <span class="customer-orders-switch-count"><?= count($activeOrders) ?></span>
                </button>
                <button type="button"
                        class="customer-orders-switch-btn <?= $orderView === 'history' ? 'active' : '' ?>"
                        data-order-view="history"
                        aria-pressed="<?= $orderView === 'history' ? 'true' : 'false' ?>">
                    История
                    <span class="customer-orders-switch-count"><?= count($historyOrders) ?></span>
                </button>
            </div>
        </div>

        <?php if (!empty($activeOrders)): ?>
            <div class="account-kpi-grid customer-orders-kpi-grid">
                <article class="account-kpi-card">
                    <span class="account-kpi-label">Активные</span>
                    <strong class="account-kpi-value"><?= count($activeOrders) ?></strong>
                </article>
                <article class="account-kpi-card">
                    <span class="account-kpi-label">Требуют внимания</span>
                    <strong class="account-kpi-value"><?= (int)$lifecycleSummary['attention'] ?></strong>
                </article>
                <article class="account-kpi-card">
                    <span class="account-kpi-label">Просрочены</span>
                    <strong class="account-kpi-value"><?= (int)$lifecycleSummary['stale'] ?></strong>
                </article>
            </div>
        <?php endif; ?>

        <?php if (empty($orders)): ?>
            <div class="customer-orders-empty">
                <h4>Заказов пока нет</h4>
                <p>Когда появится первый заказ, здесь можно будет быстро отследить статус или повторить удачный сценарий.</p>
                <a href="menu.php" class="checkout-btn customer-order-action">Открыть меню</a>
            </div>
        <?php else: ?>
            <div class="customer-orders-view <?= $orderView === 'active' ? 'active' : '' ?>" data-orders-view="active">
                <div class="customer-orders-group-head">
                    <h4>Активные заказы</h4>
                    <p>Здесь собраны заказы, по которым ещё нужно действие или ожидание.</p>
                </div>

                <?php if (empty($activeOrders)): ?>
                    <div class="customer-orders-empty customer-orders-empty--compact">
                        <p>Сейчас нет активных заказов. Все текущие заказы уже завершены или отменены.</p>
                    </div>
                <?php else: ?>
                    <div class="customer-orders-list customer-orders-list--active">
                        <?php foreach ($activeOrders as $order): ?>
                            <?php $renderOrderCard($order, false); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="customer-orders-view <?= $orderView === 'history' ? 'active' : '' ?>" data-orders-view="history">
                <div class="customer-orders-group-head">
                    <h4>История заказов</h4>
                    <p>Завершённые и отменённые заказы хранятся отдельно, чтобы можно было быстро повторить удачный заказ.</p>
                </div>

                <?php if (empty($historyOrders)): ?>
                    <div class="customer-orders-empty customer-orders-empty--compact">
                        <p>История заказов пока пуста.</p>
                    </div>
                <?php else: ?>
                    <div class="customer-orders-list customer-orders-list--history">
                        <?php foreach ($historyOrders as $order): ?>
                            <?php $renderOrderCard($order, true); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
