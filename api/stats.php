<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

$api_key = trim($_POST['api_key'] ?? $_GET['api_key'] ?? '');
if ($api_key === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'API key required']);
    exit;
}

$db   = get_db();
$stmt = $db->prepare("SELECT id FROM users WHERE api_key = ?");
$stmt->execute([$api_key]);
if (!$stmt->fetch()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid API key']);
    exit;
}

$action = trim($_POST['action'] ?? 'get');

if ($action === 'push') {
    $profit  = floatval($_POST['profit']  ?? 0);
    $equity  = floatval($_POST['equity']  ?? 0);
    $balance = floatval($_POST['balance'] ?? 0);
    $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('account_profit',  ?)")->execute([$profit]);
    $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('account_equity',  ?)")->execute([$equity]);
    $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('account_balance', ?)")->execute([$balance]);
    $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('stats_updated_at',?)")->execute([time()]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'get') {
    $s = $db->query(
        "SELECT key, value FROM app_settings
         WHERE key IN ('account_profit','account_equity','account_balance','stats_updated_at')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    echo json_encode([
        'ok'         => true,
        'profit'     => floatval($s['account_profit']  ?? 0),
        'equity'     => floatval($s['account_equity']  ?? 0),
        'balance'    => floatval($s['account_balance'] ?? 0),
        'updated_at' => intval($s['stats_updated_at']  ?? 0),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
