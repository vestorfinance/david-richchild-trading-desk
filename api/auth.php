<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Accept key from POST body or query string
$api_key = trim($_POST['api_key'] ?? $_GET['api_key'] ?? '');

if ($api_key === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'API key required']);
    exit;
}

$db   = get_db();
$stmt = $db->prepare("SELECT username FROM users WHERE api_key = ?");
$stmt->execute([$api_key]);
$user = $stmt->fetch();

if ($user) {
    echo json_encode(['ok' => true, 'username' => $user['username']]);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid API key']);
}
