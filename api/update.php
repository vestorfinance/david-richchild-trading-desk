<?php
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');

// ── Auth ────────────────────────────────────────────────────────────────────
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

$action  = trim($_POST['action'] ?? 'check');
$app_dir = realpath(__DIR__ . '/..');

// ── Check for updates ────────────────────────────────────────────────────────
if ($action === 'check') {
    // Verify we're inside a git repo
    exec('git -C ' . escapeshellarg($app_dir) . ' rev-parse HEAD 2>/dev/null', $head_out, $code);
    if ($code !== 0 || empty($head_out[0])) {
        echo json_encode(['ok' => true, 'has_update' => false, 'git_available' => false]);
        exit;
    }

    // Rate-limit: only fetch from remote once every 5 minutes
    $cache_file = sys_get_temp_dir() . '/td_update_check';
    $last_fetch = file_exists($cache_file) ? (int) file_get_contents($cache_file) : 0;

    if (time() - $last_fetch > 300) {
        exec('git -C ' . escapeshellarg($app_dir) . ' fetch origin 2>/dev/null');
        file_put_contents($cache_file, (string) time());
    }

    exec('git -C ' . escapeshellarg($app_dir) . ' rev-parse HEAD 2>/dev/null',         $local_out);
    exec('git -C ' . escapeshellarg($app_dir) . ' rev-parse origin/main 2>/dev/null',  $remote_out);

    $local  = trim($local_out[0]  ?? '');
    $remote = trim($remote_out[0] ?? '');

    if (!$local || !$remote || $remote === 'origin/main') {
        // remote ref couldn't be resolved — treat as up-to-date
        echo json_encode(['ok' => true, 'has_update' => false, 'git_available' => true]);
        exit;
    }

    $has_update     = ($local !== $remote);
    $commits_behind = 0;

    if ($has_update) {
        exec('git -C ' . escapeshellarg($app_dir) .
             ' rev-list HEAD..origin/main --count 2>/dev/null', $cnt);
        $commits_behind = (int) ($cnt[0] ?? 0);
    }

    echo json_encode([
        'ok'             => true,
        'has_update'     => $has_update,
        'commits_behind' => $commits_behind,
        'git_available'  => true,
    ]);
    exit;
}

// ── Pull updates ─────────────────────────────────────────────────────────────
if ($action === 'pull') {
    $script = $app_dir . '/update.sh';

    if (!file_exists($script)) {
        echo json_encode(['ok' => false, 'error' => 'update.sh not found in app directory']);
        exit;
    }

    exec('bash ' . escapeshellarg($script) . ' 2>&1', $out, $exit_code);
    $output = implode("\n", $out);

    if ($exit_code !== 0) {
        echo json_encode(['ok' => false, 'error' => $output]);
        exit;
    }

    // Bust the fetch cache so the next check immediately sees the updated HEAD
    @unlink(sys_get_temp_dir() . '/td_update_check');

    echo json_encode(['ok' => true, 'output' => $output]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
