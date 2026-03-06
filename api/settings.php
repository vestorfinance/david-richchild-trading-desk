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

$instruments = $db->query("SELECT symbol, price_points FROM instruments ORDER BY symbol")->fetchAll();
$usettings   = $db->query("SELECT key, value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'ok'                    => true,
    'instruments'           => $instruments,
    'good_price_expansion'  => intval($usettings['good_price_expansion'] ?? 20),
    'max_trades'            => intval($usettings['max_trades']           ?? 100),
    'default_num_trades'    => intval($usettings['default_num_trades']   ?? 1),
]);
