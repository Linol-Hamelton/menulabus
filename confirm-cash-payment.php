<?php

ob_start();

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Idempotency.php';

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
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (!$csrfToken || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $response['error'] = 'Ошибка безопасности (CSRF)';
        http_response_code(403);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        $response['error'] = 'Требуется авторизация';
        http_response_code(401);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = Database::getInstance();
    $user = $db->getUserById((int)$_SESSION['user_id']);
    if (!$user || !$user['is_active'] || !in_array($user['role'], ['owner', 'admin', 'employee'], true)) {
        $response['error'] = 'Доступ запрещён';
        http_response_code(403);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
    if ($orderId <= 0) {
        $response['error'] = 'Неверный order_id';
        http_response_code(400);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $idempotencyKey = Idempotency::getHeaderKey();
    $requestHash = Idempotency::hashPayload([
        'action' => 'confirm_cash_payment',
        'order_id' => $orderId,
        'user_id' => (int)$_SESSION['user_id'],
    ]);

    if ($idempotencyKey !== null) {
        $existing = Idempotency::find($db->getConnection(), 'employee_cash_confirm', $idempotencyKey, $requestHash);
        if ($existing && !empty($existing['conflict'])) {
            $response['error'] = 'Idempotency-Key уже использован с другим payload';
            http_response_code(409);
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($existing && is_array($existing['response'])) {
            $cached = $existing['response'];
            $cached['idempotent_replay'] = true;
            $response = array_merge(['success' => true], $cached);
            http_response_code(200);
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $order = $db->getOrderById($orderId);
    if (!$order) {
        $response['error'] = 'Заказ не найден';
        http_response_code(404);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $paymentMethod = trim((string)($order['payment_method'] ?? 'cash'));
    $paymentStatus = trim((string)($order['payment_status'] ?? 'not_required'));
    $orderStatus = trim((string)($order['status'] ?? ''));

    if ($paymentMethod !== 'cash') {
        $response['error'] = 'Подтверждение доступно только для наличной оплаты';
        http_response_code(409);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($orderStatus === 'отказ') {
        $response['error'] = 'Нельзя подтвердить оплату для отклонённого заказа';
        http_response_code(409);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = [
        'orderId' => $orderId,
        'paymentStatus' => 'paid',
        'already_paid' => false,
    ];

    if ($paymentStatus === 'paid') {
        $payload['already_paid'] = true;
        if ($idempotencyKey !== null) {
            Idempotency::store($db->getConnection(), 'employee_cash_confirm', $idempotencyKey, $requestHash, $payload);
        }
        $response = array_merge(['success' => true], $payload);
        http_response_code(200);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$db->confirmCashPayment($orderId)) {
        $freshOrder = $db->getOrderById($orderId);
        if (($freshOrder['payment_status'] ?? '') === 'paid') {
            $payload['already_paid'] = true;
            if ($idempotencyKey !== null) {
                Idempotency::store($db->getConnection(), 'employee_cash_confirm', $idempotencyKey, $requestHash, $payload);
            }
            $response = array_merge(['success' => true], $payload);
            http_response_code(200);
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $response['error'] = 'Не удалось подтвердить оплату наличными';
        http_response_code(409);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($idempotencyKey !== null) {
        Idempotency::store($db->getConnection(), 'employee_cash_confirm', $idempotencyKey, $requestHash, $payload);
    }

    $response = array_merge(['success' => true], $payload);
    http_response_code(200);
} catch (Throwable $e) {
    error_log('confirm-cash-payment error: ' . $e->getMessage());
    $response['error'] = 'Внутренняя ошибка сервера';
    http_response_code(500);
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
