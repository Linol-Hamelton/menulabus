<?php
// Partial for employee.php: renders only the <div class="account-sections"> block.
// Expects: $db (Database instance), $_SESSION['csrf_token'], $activeTab.
require_once __DIR__ . '/../lib/orders/lifecycle.php';

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

$formatPaymentMethodLabel = static function (?string $method): string {
    return match (trim((string)$method)) {
        'cash' => 'Наличные',
        'online' => 'Карта',
        'sbp', 'tbank_sbp' => 'СБП',
        default => 'Оплата',
    };
};

$formatPaymentState = static function (?string $method, ?string $status) use ($formatPaymentMethodLabel): array {
    $paymentMethod = trim((string)$method);
    $paymentStatus = trim((string)$status);
    $methodLabel = $formatPaymentMethodLabel($paymentMethod);

    return match ($paymentStatus) {
        'paid' => ['text' => $methodLabel . ' · оплачено', 'tone' => 'paid'],
        'failed', 'cancelled' => ['text' => $methodLabel . ' · не оплачено', 'tone' => 'failed'],
        'pending' => ['text' => $paymentMethod === 'cash' ? $methodLabel . ' · ждём подтверждение' : $methodLabel . ' · ждём оплату', 'tone' => 'pending'],
        default => ['text' => $methodLabel . ' · legacy', 'tone' => 'quiet'],
    };
};

$getDeliveryExpandedDetail = static function (array $order): string {
    $type = trim((string)($order['delivery_type'] ?? ''));
    $raw = trim((string)($order['delivery_details'] ?? ''));

    return match (mb_strtolower($type, 'UTF-8')) {
        'table' => $raw !== '' ? 'Стол ' . $raw : 'Номер стола уточняется',
        'bar' => 'Выдача у барной стойки',
        'takeaway' => 'Забрать в заведении',
        'delivery' => $raw !== '' ? $raw : 'Адрес доставки уточняется',
        default => $raw !== '' ? $raw : 'Детали получения уточняются',
    };
};

$orders = $db->getAllOrders();
$statuses = array_map(
    static fn(string $status): array => ['status' => $status],
    cleanmenu_order_board_statuses()
);
$activeOrders = array_values(array_filter(
    $orders,
    static fn(array $order): bool => cleanmenu_order_is_open((string)($order['status'] ?? ''))
));
$lifecycleSummary = cleanmenu_order_lifecycle_summary($orders);
$ordersInWork = count(array_values(array_filter(
    $orders,
    static fn(array $order): bool => in_array((string)($order['status'] ?? ''), ['готовим', 'доставляем'], true)
)));
$tableOrdersCount = count(array_values(array_filter(
    $orders,
    static fn(array $order): bool => ($order['delivery_type'] ?? '') === 'table'
)));
$totalOpenRevenue = array_reduce(
    $activeOrders,
    static fn(float $sum, array $order): float => $sum + (float)($order['total'] ?? 0),
    0.0
);
$canRunStaleCleanup = in_array((string)($_SESSION['user_role'] ?? ''), ['owner', 'admin'], true);
?>

<div class="account-sections">
    <section class="account-section">
        <div class="account-section-head">
            <div class="account-section-heading">
                <span class="account-section-kicker">Orders</span>
                <h2>Управление заказами</h2>
                <p class="account-section-copy">Единый triage-контур для приёма, кухни, доставки и закрытия смены.</p>
            </div>
            <div class="account-section-actions">
                <a href="/qr-print.php" class="back-to-menu-btn">QR-печать</a>
                <?php if ($canRunStaleCleanup): ?>
                    <form method="POST" action="/stale-order-cleanup.php" class="account-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="return_to" value="employee.php">
                        <button type="submit" class="checkout-btn" <?= (int)$lifecycleSummary['stale'] <= 0 ? 'disabled' : '' ?>>
                            Закрыть просроченные<?= (int)$lifecycleSummary['stale'] > 0 ? ' (' . (int)$lifecycleSummary['stale'] . ')' : '' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($orders)): ?>
            <p>Нет активных заказов</p>
        <?php else: ?>
            <div class="employee-queue-overview account-kpi-grid">
                <article class="employee-queue-metric account-kpi-card">
                    <span class="employee-queue-metric__label account-kpi-label">Активные заказы</span>
                    <strong class="employee-queue-metric__value account-kpi-value"><?= count($activeOrders) ?></strong>
                </article>
                <article class="employee-queue-metric account-kpi-card">
                    <span class="employee-queue-metric__label account-kpi-label">В работе</span>
                    <strong class="employee-queue-metric__value account-kpi-value"><?= $ordersInWork ?></strong>
                </article>
                <article class="employee-queue-metric account-kpi-card">
                    <span class="employee-queue-metric__label account-kpi-label">Требуют внимания</span>
                    <strong class="employee-queue-metric__value account-kpi-value"><?= (int)$lifecycleSummary['attention'] ?></strong>
                </article>
                <article class="employee-queue-metric account-kpi-card">
                    <span class="employee-queue-metric__label account-kpi-label">Просрочены</span>
                    <strong class="employee-queue-metric__value account-kpi-value"><?= (int)$lifecycleSummary['stale'] ?></strong>
                </article>
                <article class="employee-queue-metric account-kpi-card">
                    <span class="employee-queue-metric__label account-kpi-label">Столы / зал</span>
                    <strong class="employee-queue-metric__value account-kpi-value"><?= $tableOrdersCount ?></strong>
                </article>
                <article class="employee-queue-metric account-kpi-card">
                    <span class="employee-queue-metric__label account-kpi-label">Сумма открытых</span>
                    <strong class="employee-queue-metric__value account-kpi-value"><?= number_format($totalOpenRevenue, 0, '.', ' ') ?> ₽</strong>
                </article>
            </div>

            <?php foreach ($statuses as $statusRow): ?>
                <?php
                $boardStatus = (string)$statusRow['status'];
                $filtered = array_values(array_filter(
                    $orders,
                    static fn(array $order): bool => (string)($order['status'] ?? '') === $boardStatus
                ));
                usort($filtered, static function (array $left, array $right) use ($boardStatus): int {
                    $leftTs = strtotime((string)($left['created_at'] ?? '')) ?: 0;
                    $rightTs = strtotime((string)($right['created_at'] ?? '')) ?: 0;
                    return cleanmenu_order_is_closed($boardStatus) ? ($rightTs <=> $leftTs) : ($leftTs <=> $rightTs);
                });
                $deliveryBuckets = [];
                foreach ($filtered as $order) {
                    $deliveryKey = $normalizeDeliveryType($order['delivery_type'] ?? null);
                    $deliveryBuckets[$deliveryKey] = ($deliveryBuckets[$deliveryKey] ?? 0) + 1;
                }
                $visibleSummary = count($filtered) . ' заказ' . (count($filtered) === 1 ? '' : (count($filtered) < 5 ? 'а' : 'ов'));
                ?>
                <div class="orders-list tab-content <?= $boardStatus === $activeTab ? 'active' : '' ?>"
                     id="<?= htmlspecialchars($boardStatus) ?>"
                     data-employee-board
                     data-status-name="<?= htmlspecialchars($boardStatus) ?>">
                    <?php if (empty($filtered)): ?>
                        <p>Нет заказов со статусом «<?= htmlspecialchars($boardStatus) ?>»</p>
                    <?php else: ?>
                        <div class="employee-triage-toolbar">
                            <div class="employee-triage-toolbar__main">
                                <label class="employee-triage-search" for="employee-search-<?= md5($boardStatus) ?>">
                                    <span class="employee-triage-search__label">Быстрый поиск</span>
                                    <input
                                        id="employee-search-<?= md5($boardStatus) ?>"
                                        type="search"
                                        class="employee-triage-search__input"
                                        placeholder="№ заказа, имя, телефон, блюдо"
                                        data-employee-search>
                                </label>
                                <div class="employee-triage-summary">
                                    <span class="employee-triage-summary__status"><?= htmlspecialchars($boardStatus) ?></span>
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

                        <?php foreach ($filtered as $order): ?>
                            <?php
                            $deliveryType = $normalizeDeliveryType($order['delivery_type'] ?? null);
                            $deliveryLabel = $formatDeliveryLabel($order['delivery_type'] ?? null);
                            $itemsCount = array_reduce($order['items'] ?? [], static fn(int $sum, array $item): int => $sum + (int)($item['quantity'] ?? 0), 0);
                            $itemNames = implode(' ', array_map(
                                static fn($item) => trim((string)($item['name'] ?? '')),
                                $order['items'] ?? []
                            ));
                            $lifecycleMeta = cleanmenu_order_lifecycle_meta($order);
                            $paymentMethod = trim((string)($order['payment_method'] ?? 'cash'));
                            $paymentStatus = trim((string)($order['payment_status'] ?? 'not_required'));
                            $paymentState = $formatPaymentState($paymentMethod, $paymentStatus);
                            $deliveryExpandedDetail = $getDeliveryExpandedDetail($order);
                            $searchBlob = implode(' ', array_filter([
                                '#' . $order['id'],
                                $order['user_name'] ?? '',
                                $order['user_phone'] ?? '',
                                $deliveryLabel,
                                $order['delivery_details'] ?? '',
                                $itemNames,
                                $paymentState['text'],
                                $lifecycleMeta['lifecycle_label'],
                            ]));
                            ?>
                            <article class="order-item employee-order-card"
                                     data-order-id="<?= (int)$order['id'] ?>"
                                     data-status="<?= htmlspecialchars((string)$order['status']) ?>"
                                     data-delivery-type="<?= htmlspecialchars($deliveryType) ?>"
                                     data-order-lifecycle="<?= htmlspecialchars($lifecycleMeta['lifecycle_bucket']) ?>"
                                     data-order-search="<?= htmlspecialchars(mb_strtolower($searchBlob, 'UTF-8')) ?>">
                                <div class="order-header" data-toggle-order>
                                    <div class="employee-order-main">
                                        <div class="employee-order-primary">
                                            <span class="order-id">#<?= (int)$order['id'] ?></span>
                                            <span class="order-status <?= strtolower((string)$order['status']) ?>"><?= htmlspecialchars((string)$order['status']) ?></span>
                                            <span class="employee-order-delivery employee-order-delivery--<?= htmlspecialchars($deliveryType) ?>"><?= htmlspecialchars($deliveryLabel) ?></span>
                                        </div>
                                        <div class="employee-order-secondary">
                                            <span class="employee-order-customer"><?= htmlspecialchars((string)($order['user_name'] ?? '')) ?></span>
                                            <?php if (!empty($order['user_phone'])): ?>
                                                <span class="employee-order-phone"><?= htmlspecialchars((string)$order['user_phone']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="employee-order-meta">
                                        <span class="order-date"><?= date('d.m H:i', strtotime((string)$order['created_at'])) ?></span>
                                        <span class="employee-order-age employee-order-age--<?= htmlspecialchars($lifecycleMeta['age_tone']) ?>" title="<?= (int)$lifecycleMeta['age_minutes'] ?> мин"><?= htmlspecialchars($lifecycleMeta['age_label']) ?></span>
                                        <?php if (!$lifecycleMeta['is_closed']): ?>
                                            <span class="account-badge account-badge--<?= htmlspecialchars($lifecycleMeta['lifecycle_bucket']) ?>"><?= htmlspecialchars($lifecycleMeta['lifecycle_label']) ?></span>
                                        <?php endif; ?>
                                        <span class="employee-order-items-count"><?= $itemsCount ?> поз.</span>
                                        <span class="order-total"><?= number_format((float)$order['total'], 0, '.', ' ') ?> ₽</span>
                                        <span class="employee-toggle-icon">Детали</span>
                                    </div>
                                </div>

                                <div class="employee-order-glance">
                                    <span class="employee-order-glance__item">
                                        <strong>Тип:</strong> <?= htmlspecialchars($deliveryLabel) ?>
                                    </span>
                                    <span class="employee-order-glance__item employee-order-glance__item--payment employee-order-glance__item--payment-<?= htmlspecialchars($paymentState['tone']) ?>">
                                        <strong>Оплата:</strong> <?= htmlspecialchars($paymentState['text']) ?>
                                    </span>
                                </div>

                                <div class="order-items">
                                    <div class="employee-order-items-head">Состав заказа</div>
                                    <div class="employee-order-glance">
                                        <span class="employee-order-glance__item employee-order-glance__item--details">
                                            <strong>Детали:</strong> <?= htmlspecialchars($deliveryExpandedDetail) ?>
                                        </span>
                                        <?php if (!empty($order['updater_name'])): ?>
                                            <span class="employee-order-glance__item">
                                                <strong>Обновил:</strong> <?= htmlspecialchars((string)$order['updater_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!$lifecycleMeta['is_closed']): ?>
                                            <span class="employee-order-glance__item employee-order-glance__item--action">
                                                <strong>Следующий шаг:</strong> <?= htmlspecialchars($lifecycleMeta['next_action_label']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php foreach (($order['items'] ?? []) as $item): ?>
                                        <div class="order-product">
                                            <span class="product-name"><?= htmlspecialchars((string)($item['name'] ?? '')) ?></span>
                                            <span class="product-quantity"><?= (int)($item['quantity'] ?? 0) ?> × <?= htmlspecialchars((string)($item['price'] ?? '0')) ?> ₽</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="order-actions">
                                    <form method="POST" class="update-order-form" data-order-id="<?= (int)$order['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
                                        <?php if (!$lifecycleMeta['is_closed']): ?>
                                            <button type="button"
                                                    class="status-btn"
                                                    data-action="update_status"
                                                    data-order-id="<?= (int)$order['id'] ?>"
                                                    data-current-status="<?= htmlspecialchars((string)$order['status']) ?>">
                                                <?= htmlspecialchars($lifecycleMeta['next_action_label']) ?>
                                            </button>
                                            <button type="button"
                                                    class="status-btn-r"
                                                    data-action="reject"
                                                    data-order-id="<?= (int)$order['id'] ?>">
                                                Отказ
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!$lifecycleMeta['is_closed'] && $paymentStatus !== 'paid'): ?>
                                            <?php if ($paymentMethod === 'cash'): ?>
                                                <button type="button"
                                                        class="pay-link-btn confirm-cash-btn"
                                                        data-order-id="<?= (int)$order['id'] ?>"
                                                        data-payment-action="confirm-cash">
                                                    Подтвердить наличные
                                                </button>
                                            <?php elseif ($paymentEnabled ?? false): ?>
                                                <button type="button"
                                                        class="pay-link-btn"
                                                        data-order-id="<?= (int)$order['id'] ?>"
                                                        data-payment-action="generate-link">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true">
                                                        <path d="M224,104a8,8,0,0,1-16,0V59.32l-82.34,82.34a8,8,0,0,1-11.32-11.32L196.68,48H152a8,8,0,0,1,0-16h64a8,8,0,0,1,8,8Zm-40,24a8,8,0,0,0-8,8v72H48V80h72a8,8,0,0,0,0-16H48A16,16,0,0,0,32,80V208a16,16,0,0,0,16,16H176a16,16,0,0,0,16-16V136A8,8,0,0,0,184,128Z" />
                                                    </svg>
                                                    Оплата
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="orders-list tab-content <?= $activeTab === 'столы' ? 'active' : '' ?>" id="столы">
            <?php $tableOrders = $db->getActiveTableOrders(); ?>
            <div class="form-actions">
                <a href="/qr-print.php" class="checkout-btn" target="_blank" rel="noopener">Распечатать QR-коды столов</a>
            </div>
            <?php if (empty($tableOrders)): ?>
                <p>Нет активных заказов за столиками</p>
            <?php else: ?>
                <div class="desktop-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="first-col">Стол</th>
                                <th>Заказов</th>
                                <th class="last-col">Сумма</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tableOrders as $row): ?>
                                <tr class="menu-table">
                                    <td>Стол <?= htmlspecialchars((string)$row['table_num']) ?></td>
                                    <td><?= (int)$row['order_count'] ?></td>
                                    <td><?= number_format((float)$row['total_sum'], 0, '.', ' ') ?> ₽</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="orders-list tab-content <?= $activeTab === 'брони' ? 'active' : '' ?>" id="брони">
            <?php
            $resvFrom = date('Y-m-d 00:00:00');
            $resvTo   = date('Y-m-d 00:00:00', strtotime('+8 days'));
            $reservations = $db->getReservationsByRange($resvFrom, $resvTo);

            $statusLabels = [
                'pending'   => 'Ожидает',
                'confirmed' => 'Подтверждена',
                'seated'    => 'Гость за столом',
                'cancelled' => 'Отменена',
                'no_show'   => 'Не пришёл',
            ];
            ?>
            <div class="reservations-board" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
                <?php if (empty($reservations)): ?>
                    <p>На ближайшую неделю броней нет.</p>
                <?php else: ?>
                    <?php
                    $byDay = [];
                    foreach ($reservations as $r) {
                        $dayKey = substr((string)$r['starts_at'], 0, 10);
                        $byDay[$dayKey][] = $r;
                    }
                    ?>
                    <?php foreach ($byDay as $day => $items): ?>
                        <h3 class="reservations-day"><?= htmlspecialchars(date('d.m.Y, l', strtotime($day))) ?></h3>
                        <ul class="reservations-list">
                            <?php foreach ($items as $r): ?>
                                <?php
                                $startsTs = strtotime((string)$r['starts_at']);
                                $endsTs   = strtotime((string)$r['ends_at']);
                                $status   = (string)$r['status'];
                                $statusLabel = $statusLabels[$status] ?? $status;
                                ?>
                                <li class="reservation-card reservation-status-<?= htmlspecialchars($status) ?>" data-reservation-id="<?= (int)$r['id'] ?>">
                                    <div class="reservation-card-head">
                                        <span class="reservation-time"><?= date('H:i', $startsTs) ?>–<?= date('H:i', $endsTs) ?></span>
                                        <span class="reservation-table">Стол <?= htmlspecialchars((string)$r['table_label']) ?></span>
                                        <span class="reservation-guests"><?= (int)$r['guests_count'] ?> гост.</span>
                                        <span class="reservation-status-badge"><?= htmlspecialchars($statusLabel) ?></span>
                                    </div>
                                    <div class="reservation-card-body">
                                        <?php if (!empty($r['guest_name']) || !empty($r['guest_phone'])): ?>
                                            <div class="reservation-contact">
                                                <?php if (!empty($r['guest_name'])): ?>
                                                    <span><?= htmlspecialchars((string)$r['guest_name']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($r['guest_phone'])): ?>
                                                    <a href="tel:<?= htmlspecialchars((string)$r['guest_phone']) ?>"><?= htmlspecialchars((string)$r['guest_phone']) ?></a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['note'])): ?>
                                            <div class="reservation-note">📝 <?= htmlspecialchars((string)$r['note']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (in_array($status, ['pending', 'confirmed'], true)): ?>
                                        <div class="reservation-actions">
                                            <?php if ($status === 'pending'): ?>
                                                <button type="button" class="btn-resv-action" data-resv-action="confirmed">Подтвердить</button>
                                            <?php endif; ?>
                                            <?php if ($status === 'confirmed'): ?>
                                                <button type="button" class="btn-resv-action" data-resv-action="seated">Рассадить</button>
                                                <button type="button" class="btn-resv-action btn-resv-warn" data-resv-action="no_show">Не пришёл</button>
                                            <?php endif; ?>
                                            <button type="button" class="btn-resv-action btn-resv-danger" data-resv-action="cancelled">Отменить</button>
                                        </div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
