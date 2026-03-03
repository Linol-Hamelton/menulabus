<?php
/**
 * generate-payment-link.php
 *
 * POST endpoint: creates a ЮKassa payment link for an existing order.
 * Used by employees/admins from the order management panel.
 *
 * Input (JSON body):
 *   order_id   int     Required.
 *   csrf_token string  Required (or X-CSRF-Token header).
 *
 * Output (JSON):
 *   { "success": true, "paymentUrl": "https://...", "orderId": 123 }
 *   { "error": "...", ... } on failure
 */

ob_start();

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';

$response = ['success' => false];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // ── 1. CSRF ────────────────────────────────────────────────────────────
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $response['error'] = 'Ошибка безопасности (CSRF)';
        http_response_code(403);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 2. Auth: employee / admin / owner only ─────────────────────────────
    if (!isset($_SESSION['user_id'])) {
        $response['error'] = 'Требуется авторизация';
        http_response_code(401);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db   = Database::getInstance();
    $user = $db->getUserById((int)$_SESSION['user_id']);
    if (!$user || !$user['is_active'] || !in_array($user['role'], ['owner', 'admin', 'employee'], true)) {
        $response['error'] = 'Доступ запрещён';
        http_response_code(403);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 3. Validate input ──────────────────────────────────────────────────
    $orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
    if ($orderId <= 0) {
        $response['error'] = 'Неверный order_id';
        http_response_code(400);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 4. Load order ──────────────────────────────────────────────────────
    $order = $db->getOrderById($orderId);
    if (!$order) {
        $response['error'] = 'Заказ не найден';
        http_response_code(404);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 5. Guard ───────────────────────────────────────────────────────────
    $paymentStatus = $order['payment_status'] ?? 'not_required';

    if ($paymentStatus === 'paid') {
        $response['error'] = 'Заказ уже оплачен';
        http_response_code(409);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Если уже есть pending-платёж — пробуем переиспользовать существующую ссылку
    if ($paymentStatus === 'pending' && !empty($order['payment_id'])) {
        $shopId    = json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true) ?? '';
        $secretKey = json_decode($db->getSetting('yookassa_secret_key') ?? '""', true) ?? '';

        if ($shopId !== '' && $secretKey !== '') {
            $ch = curl_init('https://api.yookassa.ru/v3/payments/' . urlencode($order['payment_id']));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => "$shopId:$secretKey",
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 8,
            ]);
            $existResult = curl_exec($ch);
            $existCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $existErrNo  = curl_errno($ch);
            $existErr    = curl_error($ch);
            curl_close($ch);

            if ($existCode === 200 && $existResult) {
                $existPayment = json_decode($existResult, true);
                $existUrl = $existPayment['confirmation']['confirmation_url'] ?? null;
                if ($existUrl) {
                    $response['success']    = true;
                    $response['paymentUrl'] = $existUrl;
                    $response['orderId']    = $orderId;
                    $response['reused']     = true;
                    ob_end_clean();
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($response, JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            if ($existErrNo !== 0 || $existCode >= 400) {
                $existSnippet = is_string($existResult) ? mb_substr($existResult, 0, 500, 'UTF-8') : '';
                error_log(sprintf(
                    'generate-payment-link: lookup existing payment failed orderId=%d paymentId=%s http=%d curl_errno=%d curl_error="%s" body="%s"',
                    $orderId,
                    (string)$order['payment_id'],
                    (int)$existCode,
                    (int)$existErrNo,
                    $existErr,
                    $existSnippet
                ));
            }
        }
        // Не удалось получить существующую ссылку — создаём новую
    }

    // ── 6. ЮKassa credentials ──────────────────────────────────────────────
    $shopId    = json_decode($db->getSetting('yookassa_shop_id')    ?? '""', true) ?? '';
    $secretKey = json_decode($db->getSetting('yookassa_secret_key') ?? '""', true) ?? '';
    $enabled   = json_decode($db->getSetting('yookassa_enabled')    ?? '"false"', true) ?? 'false';

    if ($shopId === '' || $secretKey === '' || $enabled !== 'true') {
        $response['error'] = 'Онлайн-оплата не настроена';
        http_response_code(503);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 7. Create ЮKassa payment ───────────────────────────────────────────
    $returnUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . '/payment-return.php?order_id=' . $orderId;

    $paymentBodyArr = [
        'amount'       => [
            'value'    => number_format((float)$order['total'], 2, '.', ''),
            'currency' => 'RUB',
        ],
        'confirmation' => [
            'type'       => 'redirect',
            'return_url' => $returnUrl,
        ],
        'capture'      => true,
        'description'  => 'Заказ #' . $orderId . ' (ссылка от сотрудника)',
        'metadata'     => ['order_id' => $orderId],
    ];

    $idempKey = uniqid('waiter_' . $orderId . '_', true);
    $ch = curl_init('https://api.yookassa.ru/v3/payments');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($paymentBodyArr, JSON_UNESCAPED_UNICODE),
        CURLOPT_USERPWD        => "$shopId:$secretKey",
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Idempotence-Key: ' . $idempKey,
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $ykResult = curl_exec($ch);
    $ykCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ykErrNo  = curl_errno($ch);
    $ykErr    = curl_error($ch);
    curl_close($ch);

    if ($ykCode !== 200 || !$ykResult) {
        $ykSnippet = is_string($ykResult) ? mb_substr($ykResult, 0, 500, 'UTF-8') : '';
        error_log(sprintf(
            'generate-payment-link: create payment failed orderId=%d http=%d curl_errno=%d curl_error="%s" body="%s"',
            $orderId,
            (int)$ykCode,
            (int)$ykErrNo,
            $ykErr,
            $ykSnippet
        ));
        $response['error'] = 'Ошибка создания платежа в ЮKassa';
        http_response_code(502);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ykPayment  = json_decode($ykResult, true);
    $ykId       = $ykPayment['id'] ?? null;
    $paymentUrl = $ykPayment['confirmation']['confirmation_url'] ?? null;

    if (!$ykId || !$paymentUrl) {
        error_log(sprintf(
            'generate-payment-link: malformed payment response orderId=%d body="%s"',
            $orderId,
            mb_substr((string)$ykResult, 0, 500, 'UTF-8')
        ));
        $response['error'] = 'ЮKassa не вернула ссылку оплаты';
        http_response_code(502);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 8. Persist payment info ────────────────────────────────────────────
    $db->updateOrderPayment($orderId, $ykId, 'pending', 'online');

    $response['success']    = true;
    $response['paymentUrl'] = $paymentUrl;
    $response['orderId']    = $orderId;

} catch (Throwable $e) {
    error_log("generate-payment-link error: " . $e->getMessage());
    $response['error'] = 'Внутренняя ошибка сервера';
    http_response_code(500);
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
