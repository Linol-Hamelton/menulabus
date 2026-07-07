<?php
/**
 * scripts/tenant/seed-roles.php — Phase L103.9 QA role accounts (no demo content).
 *
 * Unlike scripts/tenant/seed.php (which refuses provider-mode tenants because
 * it rewrites menu/orders demo data), this script ONLY upserts login accounts
 * for the four staff/customer roles — safe on any tenant including the
 * provider host (labus). It never touches menu_items / orders / settings.
 *
 * Usage:
 *   php scripts/tenant/seed-roles.php                       # seed with default password
 *   php scripts/tenant/seed-roles.php --password='Secret1!' # custom password
 *   php scripts/tenant/seed-roles.php --deactivate          # is_active=0 for all QA accounts
 *   php scripts/tenant/seed-roles.php --list                # show current state
 *
 * Targets the DEFAULT tenant DB of this install (the one Database::getInstance()
 * connects to) — on the labus prod that is the labus tenant itself. For other
 * tenants use scripts/tenant/seed.php with --brand-slug (tenant mode only).
 *
 * Accounts (idempotent by email):
 *   qa.owner@tenant.local      owner
 *   demo.admin@tenant.local    admin
 *   demo.employee@tenant.local employee
 *   guest.anna@tenant.local    customer
 *   guest.igor@tenant.local    customer
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../db.php';

$opts = getopt('', ['password::', 'deactivate', 'list']);
$password   = (string)($opts['password'] ?? 'DemoTenant2026!');
$deactivate = array_key_exists('deactivate', $opts);
$listOnly   = array_key_exists('list', $opts);

$accounts = [
    ['email' => 'qa.owner@tenant.local',      'role' => 'owner',    'name' => 'QA Owner'],
    ['email' => 'demo.admin@tenant.local',    'role' => 'admin',    'name' => 'Demo Admin'],
    ['email' => 'demo.employee@tenant.local', 'role' => 'employee', 'name' => 'Demo Employee'],
    ['email' => 'guest.anna@tenant.local',    'role' => 'customer', 'name' => 'Anna QA'],
    ['email' => 'guest.igor@tenant.local',    'role' => 'customer', 'name' => 'Igor QA'],
];

$db  = Database::getInstance();
$pdo = $db->getConnection();

if ($listOnly) {
    $in = implode(',', array_fill(0, count($accounts), '?'));
    $stmt = $pdo->prepare("SELECT id, email, role, is_active, created_at FROM users WHERE email IN ($in) ORDER BY id");
    $stmt->execute(array_column($accounts, 'email'));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo "[seed-roles] no QA accounts present\n"; exit(0); }
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    exit(0);
}

if ($deactivate) {
    $in = implode(',', array_fill(0, count($accounts), '?'));
    $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE email IN ($in)");
    $stmt->execute(array_column($accounts, 'email'));
    echo "[seed-roles] deactivated " . $stmt->rowCount() . " QA accounts\n";
    exit(0);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "[seed-roles] password must be >= 8 chars\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);

$find   = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$insert = $pdo->prepare(
    'INSERT INTO users (email, password_hash, name, phone, is_active, email_verified_at, role, created_at)
     VALUES (:email, :hash, :name, NULL, 1, NOW(), :role, NOW())'
);
$update = $pdo->prepare(
    'UPDATE users SET password_hash = :hash, role = :role, is_active = 1, email_verified_at = COALESCE(email_verified_at, NOW())
     WHERE id = :id'
);

$created = 0;
$updated = 0;
foreach ($accounts as $acc) {
    $find->execute([':email' => $acc['email']]);
    $existingId = $find->fetchColumn();
    if ($existingId) {
        $update->execute([':hash' => $hash, ':role' => $acc['role'], ':id' => (int)$existingId]);
        $updated++;
        echo "[seed-roles] updated  {$acc['email']} ({$acc['role']}, id={$existingId})\n";
    } else {
        $insert->execute([':email' => $acc['email'], ':hash' => $hash, ':name' => $acc['name'], ':role' => $acc['role']]);
        $created++;
        echo "[seed-roles] created  {$acc['email']} ({$acc['role']}, id=" . $pdo->lastInsertId() . ")\n";
    }
}

echo "[seed-roles] done — created={$created} updated={$updated}, password set for all 5\n";
echo "[seed-roles] to disable after testing: php scripts/tenant/seed-roles.php --deactivate\n";
exit(0);
