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
    // Check exec() is usable
    $exec_disabled = in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
    if ($exec_disabled) {
        echo json_encode(['ok' => true, 'has_update' => false, 'git_available' => false]);
        exit;
    }

    // www-data needs HOME for git config; set PATH so git/curl are findable
    $env = 'HOME=/tmp GIT_TERMINAL_PROMPT=0 GIT_ASKPASS=echo ';
    $cd  = 'cd ' . escapeshellarg($app_dir);
    $sep = ' && ';

    // Rate-limit fetches to once every 5 minutes
    $cache_file = sys_get_temp_dir() . '/td_update_check';
    $cache_data = (file_exists($cache_file) && is_readable($cache_file))
        ? json_decode(file_get_contents($cache_file), true)
        : [];
    $last_check = (int) ($cache_data['ts'] ?? 0);

    if (time() - $last_check > 300) {
        exec($env . $cd . $sep . 'git fetch origin 2>/dev/null', $fetch_out, $fetch_code);
        @file_put_contents($cache_file, json_encode(['ts' => time()]));
        @chmod($cache_file, 0666);
    }

    // Local HEAD
    exec($env . $cd . $sep . 'git rev-parse HEAD 2>/dev/null', $local_out, $local_code);
    $local = trim($local_out[0] ?? '');
    if (!$local || $local_code !== 0) {
        echo json_encode(['ok' => true, 'has_update' => false, 'git_available' => false]);
        exit;
    }

    // Remote HEAD — try origin/main, origin/HEAD, origin/master
    $remote = '';
    foreach (['origin/main', 'origin/HEAD', 'origin/master'] as $ref) {
        $ref_out = [];
        exec($env . $cd . $sep . 'git rev-parse ' . escapeshellarg($ref) . ' 2>/dev/null', $ref_out, $ref_code);
        $val = trim($ref_out[0] ?? '');
        if ($ref_code === 0 && preg_match('/^[0-9a-f]{40}$/i', $val)) {
            $remote = $val;
            break;
        }
    }

    if (!$remote) {
        echo json_encode(['ok' => true, 'has_update' => false, 'git_available' => true]);
        exit;
    }

    $has_update     = ($local !== $remote);
    $commits_behind = 0;

    if ($has_update) {
        exec($env . $cd . $sep . 'git rev-list ' . escapeshellarg($local) . '..' . escapeshellarg($remote) . ' --count 2>/dev/null', $cnt_out);
        $commits_behind = (int) trim($cnt_out[0] ?? '0');
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

    exec('sudo ' . escapeshellarg($script) . ' 2>&1', $out, $exit_code);
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
