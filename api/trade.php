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
$user = $stmt->fetch();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid API key']);
    exit;
}
$user_id = $user['id'];

$action = trim($_POST['action'] ?? $_GET['action'] ?? 'queue');

// ── Queue a new trade (called from the web app) ───────────────────────────────
if ($action === 'queue') {
    $symbol     = strtoupper(trim($_POST['symbol']     ?? ''));
    $direction  = strtolower(trim($_POST['direction']  ?? ''));
    $lot        = floatval($_POST['lot'] ?? 0);
    $num_trades = max(1, min(99, intval($_POST['num_trades'] ?? 1)));

    if (!$symbol || !in_array($direction, ['buy', 'sell']) || $lot <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
        exit;
    }

    $db->prepare("INSERT INTO trades (user_id, symbol, direction, lot, num_trades) VALUES (?, ?, ?, ?, ?)")
       ->execute([$user_id, $symbol, $direction, $lot, $num_trades]);

    echo json_encode(['ok' => true, 'trade_id' => $db->lastInsertId()]);
    exit;
}

// ── Poll for pending trades (called from the EA every 500ms) ─────────────────
if ($action === 'poll') {
    $trades = $db->prepare(
        "SELECT id, symbol, direction, lot, num_trades FROM trades
         WHERE user_id = ? AND status = 'pending'
         ORDER BY created_at ASC LIMIT 10"
    );
    $trades->execute([$user_id]);
    echo json_encode(['ok' => true, 'trades' => $trades->fetchAll()]);
    exit;
}

// ── Confirm execution result (called from the EA after OrderSend) ─────────────
if ($action === 'confirm') {
    $trade_id  = intval($_POST['trade_id'] ?? 0);
    $status    = in_array($_POST['status'] ?? '', ['executed', 'failed', 'rejected'])
                 ? $_POST['status'] : 'failed';
    $error_msg = trim($_POST['error_msg'] ?? '');

    $db->prepare(
        "UPDATE trades SET status = ?, error_msg = ?, executed_at = strftime('%s','now')
         WHERE id = ? AND user_id = ?"
    )->execute([$status, $error_msg ?: null, $trade_id, $user_id]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── Queue a manage command (called from the web app) ──────────────────────────
if ($action === 'manage') {
    $command = strtolower(trim($_POST['command'] ?? ''));
    $allowed = ['break_even', 'delete_sl', 'close_losing', 'close_profitable', 'close_all'];
    if (!in_array($command, $allowed)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid command']);
        exit;
    }
    $db->prepare("INSERT INTO manage_commands (user_id, command) VALUES (?, ?)")
       ->execute([$user_id, $command]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Poll manage commands (called from EA) ────────────────────────────────────-
if ($action === 'poll_manage') {
    $cmds = $db->prepare(
        "SELECT id, command FROM manage_commands
         WHERE user_id = ? AND status = 'pending'
         ORDER BY created_at ASC LIMIT 5"
    );
    $cmds->execute([$user_id]);
    echo json_encode(['ok' => true, 'commands' => $cmds->fetchAll()]);
    exit;
}

// ── Confirm manage command result (called from EA) ───────────────────────────
if ($action === 'confirm_manage') {
    $cmd_id     = intval($_POST['cmd_id']     ?? 0);
    $status     = in_array($_POST['status'] ?? '', ['done', 'failed']) ? $_POST['status'] : 'done';
    $result_msg = trim($_POST['result_msg']   ?? '');
    $db->prepare(
        "UPDATE manage_commands SET status = ?, result_msg = ? WHERE id = ? AND user_id = ?"
    )->execute([$status, $result_msg ?: null, $cmd_id, $user_id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
