<?php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/require_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    
    if (!empty($name)) {
        $_SESSION['project_name'] = $name;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Empty name']);
    }
    exit;
}