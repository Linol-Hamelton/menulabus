<?php

ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Idempotency.php';

$response = ['success' => false];
$input = [];

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $response['error'] = 'Ошибка безопасности (CSRF)';
        http_response_code(403);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        $response['error'] = 'Требуется авторизация';
        http_response_code(401);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = Database::getInstance();
    $user = $db->getUserById((int)$_SESSION['user_id']);
    if (!$user) {
        $response['error'] = 'Доступ запрещен';
        http_response_code(403);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (isset($input['items'])) {
        $items = $input['items'];
        $total = (float)($input['total'] ?? 0);
        $deliveryType   = (string)($input['delivery_type']   ?? 'bar');
        $deliveryDetail = (string)($input['delivery_details'] ?? '');
        $paymentMethod = is_string($input['payment_method'] ?? null)
            ? trim((string)$input['payment_method'])
            : '';
        $allowedPaymentMethods = ['cash', 'online', 'sbp', 'tbank_sbp'];
        $tips = max(0.0, (float)($input['tips'] ?? 0));

        if (!is_array($items) || empty($items) || $total <= 0) {
            $response['error'] = 'Неверные параметры заказа';
            http_response_code(400);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($deliveryType === 'delivery' && trim($deliveryDetail) === '') {
            $response['error'] = 'Укажите адрес доставки';
            http_response_code(400);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($deliveryType === 'table' && trim($deliveryDetail) === '') {
            $response['error'] = 'Укажите номер стола';
            http_response_code(400);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
            $response['error'] = 'Выберите корректный способ оплаты';
            http_response_code(400);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $idempotencyKey = Idempotency::getHeaderKey();
        $requestHash = Idempotency::hashPayload([
            'user_id'         => (int)$_SESSION['user_id'],
            'items'           => $items,
            'total'           => $total,
            'delivery_type'   => $deliveryType,
            'delivery_details' => $deliveryDetail,
            'payment_method'  => $paymentMethod,
        ]);

        if ($idempotencyKey !== null) {
            $existing = Idempotency::find($db->getConnection(), 'web_order_create', $idempotencyKey, $requestHash);
            if ($existing && !empty($existing['conflict'])) {
                $response['error'] = 'Idempotency-Key уже использован с другим payload';
                http_response_code(409);
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($existing && is_array($existing['response'])) {
                $cached = $existing['response'];
                $cached['idempotent_replay'] = true;
                $response = array_merge(['success' => true], $cached);
                http_response_code(200);
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // Pre-check: verify payment gateway is configured BEFORE creating the order
        if ($paymentMethod === 'online' || $paymentMethod === 'sbp') {
            $ykShopId    = json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true) ?? '';
            $ykSecretKey = json_decode($db->getSetting('yookassa_secret_key') ?? '""', true) ?? '';
            $ykEnabled   = json_decode($db->getSetting('yookassa_enabled')    ?? '"false"', true) ?? 'false';
            if ($ykShopId === '' || $ykSecretKey === '' || $ykEnabled !== 'true') {
                $response['error'] = 'Онлайн-оплата временно недоступна. Выберите другой способ оплаты.';
                http_response_code(200);
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        if ($paymentMethod === 'tbank_sbp') {
            $tbKey  = json_decode($db->getSetting('tbank_terminal_key') ?? '""', true) ?? '';
            $tbPass = json_decode($db->getSetting('tbank_password')     ?? '""', true) ?? '';
            $tbOn   = json_decode($db->getSetting('tbank_enabled')      ?? '"false"', true) ?? 'false';
            if ($tbKey === '' || $tbPass === '' || $tbOn !== 'true') {
                $response['error'] = 'СБП через Т-Банк временно недоступен. Выберите другой способ оплаты.';
                http_response_code(200);
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $orderId = $db->createOrder((int)$_SESSION['user_id'], $items, $total, $deliveryType, $deliveryDetail, $tips);
        if (!$orderId) {
            $response['error'] = 'Ошибка при создании заказа';
            http_response_code(500);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $paymentUrl = null;

        // ── Telegram notification with Accept/Reject buttons ──────────────
        try {
            require_once __DIR__ . '/config.php';
            require_once __DIR__ . '/telegram-notifications.php';
            $orderRow = $db->getOrderById((int)$orderId);
            if ($orderRow) {
                $orderRow['tips'] = $tips;
                sendOrderToTelegram((int)$orderId, $orderRow, $db);
            }
        } catch (Throwable $tgEx) {
            error_log("Telegram notify error: " . $tgEx->getMessage());
        }

        // ── Online payment via ЮKassa (card redirect or SBP) ─────────────
        if ($paymentMethod === 'online' || $paymentMethod === 'sbp') {
            $shopId    = json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true) ?? '';
            $secretKey = json_decode($db->getSetting('yookassa_secret_key') ?? '""', true) ?? '';
            $enabled   = json_decode($db->getSetting('yookassa_enabled')    ?? '"false"', true) ?? 'false';

            if ($shopId !== '' && $secretKey !== '' && $enabled === 'true') {
                $returnUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST']
                    . '/payment-return.php?order_id=' . (int)$orderId;

                $paymentBodyArr = [
                    'amount'       => [
                        'value'    => number_format((float)$total + (float)$tips, 2, '.', ''),
                        'currency' => 'RUB',
                    ],
                    'confirmation' => [
                        'type'       => 'redirect',
                        'return_url' => $returnUrl,
                    ],
                    'capture'      => true,
                    'description'  => 'Заказ #' . (int)$orderId,
                    'metadata'     => ['order_id' => (int)$orderId],
                ];

                // СБП: указываем ЮKassa использовать Систему быстрых платежей
                if ($paymentMethod === 'sbp') {
                    $paymentBodyArr['payment_method_data'] = ['type' => 'sbp'];
                }

                $paymentBody = json_encode($paymentBodyArr, JSON_UNESCAPED_UNICODE);

                $idempKey = uniqid('order_' . $orderId . '_', true);
                $ch = curl_init('https://api.yookassa.ru/v3/payments');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $paymentBody,
                    CURLOPT_USERPWD        => "$shopId:$secretKey",
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Idempotence-Key: ' . $idempKey,
                    ],
                    CURLOPT_TIMEOUT        => 10,
                ]);
                $ykResult = curl_exec($ch);
                $ykCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($ykCode === 200 && $ykResult) {
                    $ykPayment = json_decode($ykResult, true);
                    $ykId      = $ykPayment['id'] ?? null;
                    $paymentUrl = $ykPayment['confirmation']['confirmation_url'] ?? null;

                    if ($ykId) {
                        // Сохраняем фактический метод ('online' или 'sbp')
                        $db->updateOrderPayment((int)$orderId, $ykId, 'pending', $paymentMethod);
                    }
                    if ($paymentUrl === null || $paymentUrl === '') {
                        $db->updateOrderStatus((int)$orderId, 'отказ');
                        $response['error'] = 'Не удалось создать ссылку для оплаты. Попробуйте позже или выберите «Оплата на месте».';
                        http_response_code(200);
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                } else {
                    error_log("ЮKassa create payment failed: code=$ykCode body=$ykResult");
                    $db->updateOrderStatus((int)$orderId, 'отказ');
                    $response['error'] = 'Не удалось создать ссылку для оплаты. Попробуйте позже или выберите «Оплата на месте».';
                    http_response_code(200);
                    echo json_encode($response, JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }
        // ── T-Bank SBP payment via Tinkoff Acquiring ─────────────────────
        if ($paymentMethod === 'tbank_sbp') {
            require_once __DIR__ . '/lib/TBank.php';
            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            $tbParams = [
                'TerminalKey'     => $tbKey,
                'Amount'          => (int)(round(($total + $tips) * 100)),
                'OrderId'         => (string)$orderId,
                'Description'     => 'Заказ #' . (int)$orderId,
                'PaymentMethod'   => 'SBP',
                'SuccessURL'      => $baseUrl . '/payment-return.php?order_id=' . (int)$orderId,
                'FailURL'         => $baseUrl . '/payment-return.php?order_id=' . (int)$orderId,
                'NotificationURL' => $baseUrl . '/payment-webhook.php',
            ];
            $tbResult = tBankRequest('Init', $tbParams, $tbPass);
            if ($tbResult && !empty($tbResult['Success']) && !empty($tbResult['PaymentURL'])) {
                $paymentUrl = $tbResult['PaymentURL'];
                $db->updateOrderPayment((int)$orderId, (string)($tbResult['PaymentId'] ?? ''), 'pending', 'tbank_sbp');
            } else {
                $tbErr = $tbResult['Message'] ?? ($tbResult['Details'] ?? 'Ошибка Т-Банк');
                error_log("T-Bank Init failed for order $orderId: " . json_encode($tbResult));
                $db->updateOrderStatus((int)$orderId, 'отказ');
                $response['error'] = 'Не удалось создать СБП-ссылку. Попробуйте позже или выберите «Оплата на месте».';
                http_response_code(200);
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        // ─────────────────────────────────────────────────────────────────

        $payload = ['orderId' => (int)$orderId];
        if ($paymentUrl) {
            $payload['paymentUrl'] = $paymentUrl;
        }
        if ($idempotencyKey !== null) {
            Idempotency::store($db->getConnection(), 'web_order_create', $idempotencyKey, $requestHash, $payload);
        }

        $response['success'] = true;
        $response['orderId'] = (int)$orderId;
        if ($paymentUrl) {
            $response['paymentUrl'] = $paymentUrl;
        }
        http_response_code(200);
    } elseif (isset($input['order_id']) && isset($input['action'])) {
        if (($user['role'] ?? '') !== 'employee') {
            $response['error'] = 'Недостаточно прав для изменения статуса';
            http_response_code(403);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $orderId = (int)$input['order_id'];
        $currentStatus = $db->getOrderStatus($orderId);
        $statusFlow = ['Приём', 'готовим', 'доставляем', 'завершён'];
        $currentIndex = array_search($currentStatus, $statusFlow, true);

        if ($currentStatus === false) {
            $response['error'] = 'Заказ не найден';
            http_response_code(404);
        } elseif ($currentIndex === false) {
            $response['error'] = 'Неизвестный текущий статус заказа';
            http_response_code(400);
        } elseif ($currentIndex >= count($statusFlow) - 1) {
            $response['error'] = 'Заказ уже завершён';
            http_response_code(400);
        } else {
            $newStatus = $statusFlow[$currentIndex + 1];
            $success = $db->updateOrderStatus($orderId, $newStatus, (int)$_SESSION['user_id']);

            if ($success) {
                $response['success'] = true;
                $response['new_status'] = $newStatus;
                $response['order_id'] = $orderId;
                http_response_code(200);
            } else {
                $response['error'] = 'Ошибка обновления статуса';
                http_response_code(500);
            }
        }
    } else {
        $response['error'] = 'Неверные параметры запроса';
        http_response_code(400);
    }
} catch (Throwable $e) {
    error_log("Order processing error: " . $e->getMessage());
    error_log("Input data: " . print_r($input, true));
    error_log("Session data: " . print_r($_SESSION, true));
    $response['error'] = 'Внутренняя ошибка сервера';
    http_response_code(500);
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
