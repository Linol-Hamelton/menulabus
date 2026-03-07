<?php
// Partial for employee.php: renders only the <div class="account-sections"> block.
// Expects: $db (Database instance), $_SESSION['csrf_token'], $activeTab.

$normalizeDeliveryType = static function (?string $type): string {
    $value = trim((string)$type);
    return $value !== '' ? mb_strtolower($value, 'UTF-8') : 'unknown';
};

$formatDeliveryLabel = static function (?string $type): string {
    $value = trim((string)$type);
    if ($value === '') {
        return 'Не указано';
    }

    return match (mb_strtolower($value, 'UTF-8')) {
        'delivery' => 'Доставка',
        'takeaway' => 'Самовывоз',
        'table' => 'Стол',
        'bar' => 'Бар',
        default => $value,
    };
};

$getNextActionLabel = static function (string $status): string {
    return match ($status) {
        'Приём' => 'На кухню',
        'готовим' => 'В доставку',
        'доставляем' => 'Принято',
        default => $status,
    };
};
?>

<div class="account-sections">
    <section class="account-section">
        <h2>Управление заказами</h2>
        <?php
        $orders = $db->getAllOrders();
        $statuses = [
            ['status' => 'Приём'],
            ['status' => 'готовим'],
            ['status' => 'доставляем'],
            ['status' => 'завершён'],
            ['status' => 'отказ'],
        ];

        $activeOrders = array_values(array_filter($orders, static fn($o) => !in_array($o['status'], ['завершён', 'отказ'], true)));
        $ordersInWork = count(array_values(array_filter($orders, static fn($o) => in_array($o['status'], ['готовим', 'доставляем'], true))));
        $tableOrdersCount = count(array_values(array_filter($orders, static fn($o) => ($o['delivery_type'] ?? '') === 'table')));
        $totalOpenRevenue = array_reduce($activeOrders, static fn($sum, $o) => $sum + (float)($o['total'] ?? 0), 0.0);
        ?>

        <?php if (empty($orders)): ?>
            <p>Нет активных заказов</p>
        <?php else: ?>
            <div class="employee-queue-overview">
                <article class="employee-queue-metric">
                    <span class="employee-queue-metric__label">Активные заказы</span>
                    <strong class="employee-queue-metric__value"><?= count($activeOrders) ?></strong>
                </article>
                <article class="employee-queue-metric">
                    <span class="employee-queue-metric__label">В работе</span>
                    <strong class="employee-queue-metric__value"><?= $ordersInWork ?></strong>
                </article>
                <article class="employee-queue-metric">
                    <span class="employee-queue-metric__label">Столы / зал</span>
                    <strong class="employee-queue-metric__value"><?= $tableOrdersCount ?></strong>
                </article>
                <article class="employee-queue-metric">
                    <span class="employee-queue-metric__label">Сумма открытых</span>
                    <strong class="employee-queue-metric__value"><?= number_format($totalOpenRevenue, 0, '.', ' ') ?> ₽</strong>
                </article>
            </div>

            <?php foreach ($statuses as $s): ?>
                <?php
                $filtered = array_values(array_filter($orders, fn($o) => $o['status'] === $s['status']));
                $deliveryBuckets = [];
                foreach ($filtered as $order) {
                    $deliveryKey = $normalizeDeliveryType($order['delivery_type'] ?? null);
                    $deliveryBuckets[$deliveryKey] = ($deliveryBuckets[$deliveryKey] ?? 0) + 1;
                }
                $visibleSummary = count($filtered) . ' заказ' . (count($filtered) === 1 ? '' : (count($filtered) < 5 ? 'а' : 'ов'));
                ?>
                <div class="orders-list tab-content <?= $s['status'] === $activeTab ? 'active' : '' ?>"
                     id="<?= htmlspecialchars($s['status']) ?>"
                     data-employee-board
                     data-status-name="<?= htmlspecialchars($s['status']) ?>">
                    <?php if (empty($filtered)): ?>
                        <p>Нет заказов со статусом «<?= htmlspecialchars($s['status']) ?>»</p>
                    <?php else: ?>
                        <div class="employee-triage-toolbar">
                            <div class="employee-triage-toolbar__main">
                                <label class="employee-triage-search" for="employee-search-<?= md5($s['status']) ?>">
                                    <span class="employee-triage-search__label">Быстрый поиск</span>
                                    <input
                                        id="employee-search-<?= md5($s['status']) ?>"
                                        type="search"
                                        class="employee-triage-search__input"
                                        placeholder="№ заказа, имя, телефон, адрес"
                                        data-employee-search>
                                </label>
                                <div class="employee-triage-summary">
                                    <span class="employee-triage-summary__status"><?= htmlspecialchars($s['status']) ?></span>
                                    <span class="employee-triage-summary__count" data-employee-visible-count><?= htmlspecialchars($visibleSummary) ?></span>
                                </div>
                            </div>

                            <div class="employee-triage-filters" aria-label="Фильтр по типу заказа">
                                <button type="button" class="employee-filter-chip active" data-filter-type="all">Все</button>
                                <?php foreach ($deliveryBuckets as $deliveryType => $count): ?>
                                    <button type="button"
                                            class="employee-filter-chip"
                                            data-filter-type="<?= htmlspecialchars($deliveryType) ?>">
                                        <?= htmlspecialchars($formatDeliveryLabel($deliveryType)) ?>
                                        <span class="employee-filter-chip__count"><?= (int)$count ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="employee-filter-empty" data-employee-empty hidden>
                            Нет заказов, подходящих под текущий поиск или фильтр.
                        </div>

                        <?php foreach ($filtered as $o): ?>
                            <?php
                            $deliveryType = $normalizeDeliveryType($o['delivery_type'] ?? null);
                            $deliveryLabel = $formatDeliveryLabel($o['delivery_type'] ?? null);
                            $itemsCount = array_reduce($o['items'], static fn($sum, $item) => $sum + (int)($item['quantity'] ?? 0), 0);
                            $searchBlob = implode(' ', array_filter([
                                '#' . $o['id'],
                                $o['user_name'] ?? '',
                                $o['user_phone'] ?? '',
                                $deliveryLabel,
                                $o['delivery_details'] ?? '',
                            ]));
                            ?>
                            <article class="order-item employee-order-card"
                                     data-order-id="<?= $o['id'] ?>"
                                     data-status="<?= htmlspecialchars($o['status']) ?>"
                                     data-delivery-type="<?= htmlspecialchars($deliveryType) ?>"
                                     data-order-search="<?= htmlspecialchars(mb_strtolower($searchBlob, 'UTF-8')) ?>">
                                <div class="order-header" data-toggle-order>
                                    <div class="employee-order-main">
                                        <div class="employee-order-primary">
                                            <span class="order-id">#<?= $o['id'] ?></span>
                                            <span class="order-status <?= strtolower($o['status']) ?>"><?= $o['status'] ?></span>
                                            <span class="employee-order-delivery employee-order-delivery--<?= htmlspecialchars($deliveryType) ?>"><?= htmlspecialchars($deliveryLabel) ?></span>
                                        </div>
                                        <div class="employee-order-secondary">
                                            <span class="employee-order-customer"><?= htmlspecialchars($o['user_name']) ?></span>
                                            <?php if ($o['user_phone']): ?>
                                                <span class="employee-order-phone"><?= htmlspecialchars($o['user_phone']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="employee-order-meta">
                                        <span class="order-date"><?= date('d.m H:i', strtotime($o['created_at'])) ?></span>
                                        <span class="employee-order-items-count"><?= $itemsCount ?> поз.</span>
                                        <span class="order-total"><?= number_format($o['total'], 0, '.', ' ') ?> ₽</span>
                                        <span class="employee-toggle-icon">Состав</span>
                                    </div>
                                </div>

                                <div class="employee-order-glance">
                                    <span class="employee-order-glance__item">
                                        <strong>Тип:</strong> <?= htmlspecialchars($deliveryLabel) ?>
                                    </span>
                                    <?php if (!empty($o['delivery_details'])): ?>
                                        <span class="employee-order-glance__item employee-order-glance__item--details">
                                            <strong>Детали:</strong> <?= htmlspecialchars($o['delivery_details']) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($o['updater_name']): ?>
                                        <span class="employee-order-glance__item">
                                            <strong>Обновил:</strong> <?= htmlspecialchars($o['updater_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

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
                                                'bar'      => 'fa-glass-cheers',
                                            ];
                                            $rawType = $o['delivery_type'] ?? 'Не указано';
                                            $icon = $iconMap[$rawType] ?? 'fa-question-circle';
                                            ?>
                                            <i class="fas <?= $icon ?>"></i>
                                            <?= htmlspecialchars($deliveryLabel) ?>
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
                                <hr class="divider">
                                <div class="order-items">
                                    <?php foreach ($o['items'] as $item): ?>
                                        <div class="order-product">
                                            <span class="product-name"><?= htmlspecialchars($item['name']) ?></span>
                                            <span class="product-quantity"><?= $item['quantity'] ?> × <?= $item['price'] ?> ₽</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="order-actions">
                                    <form method="POST" class="update-order-form" data-order-id="<?= $o['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                        <?php if (!in_array($o['status'], ['завершён', 'отказ'])): ?>
                                            <button type="button" class="status-btn"
                                                    data-action="update_status"
                                                    data-order-id="<?= $o['id'] ?>"
                                                    data-current-status="<?= $o['status'] ?>">
                                                <?= htmlspecialchars($getNextActionLabel($o['status'])) ?>
                                            </button>
                                            <button type="button" class="status-btn-r"
                                                    data-action="reject"
                                                    data-order-id="<?= $o['id'] ?>">
                                                Отказ
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                    <?php
                                    $pStatus = $o['payment_status'] ?? 'not_required';
                                    if (!in_array($o['status'], ['завершён', 'отказ'])
                                        && $pStatus !== 'paid'
                                        && ($paymentEnabled ?? false)):
                                    ?>
                                    <button type="button"
                                            class="status-btn pay-link-btn"
                                            data-order-id="<?= (int)$o['id'] ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true"><path d="M224,104a8,8,0,0,1-16,0V59.32l-82.34,82.34a8,8,0,0,1-11.32-11.32L196.68,48H152a8,8,0,0,1,0-16h64a8,8,0,0,1,8,8Zm-40,24a8,8,0,0,0-8,8v72H48V80h72a8,8,0,0,0,0-16H48A16,16,0,0,0,32,80V208a16,16,0,0,0,16,16H176a16,16,0,0,0,16-16V136A8,8,0,0,0,184,128Z"/></svg>
                                        Оплата
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="orders-list tab-content <?= $activeTab === 'столы' ? 'active' : '' ?>" id="столы">
            <?php $tableOrders = $db->getActiveTableOrders(); ?>
            <div class="admin-form-group">
                <a href="/qr-print.php" class="checkout-btn" target="_blank">Распечатать QR-коды столов</a>
            </div>
            <?php if (empty($tableOrders)): ?>
                <p>Нет активных заказов за столиками</p>
            <?php else: ?>
                <table class="owner-table">
                    <thead>
                        <tr>
                            <th>Стол</th>
                            <th>Заказов</th>
                            <th>Сумма</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableOrders as $row): ?>
                        <tr>
                            <td>Стол <?= htmlspecialchars($row['table_num']) ?></td>
                            <td><?= (int)$row['order_count'] ?></td>
                            <td><?= number_format((float)$row['total_sum'], 0, '.', ' ') ?> ₽</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
</div>
