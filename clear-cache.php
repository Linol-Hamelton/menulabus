<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Просто устанавливаем флаг для принудительного обновления
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['force_reload'] = true;
}

echo json_encode(['status' => 'success', 'timestamp' => time()]);
exit;