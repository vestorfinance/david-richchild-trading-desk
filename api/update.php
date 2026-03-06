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
    // Verify we're inside a git repo and get local HEAD SHA
    exec('git -C ' . escapeshellarg($app_dir) . ' rev-parse HEAD 2>/dev/null', $head_out, $code);
    if ($code !== 0 || empty($head_out[0])) {
        echo json_encode(['ok' => true, 'has_update' => false, 'git_available' => false]);
        exit;
    }
    $local = trim($head_out[0]);

    // Rate-limit remote checks to once every 5 minutes
    $cache_file  = sys_get_temp_dir() . '/td_update_check';
    $cache_data  = file_exists($cache_file) ? json_decode(file_get_contents($cache_file), true) : [];
    $last_check  = (int) ($cache_data['ts'] ?? 0);
    $remote      = $cache_data['sha'] ?? '';

    if (time() - $last_check > 300 || !$remote) {
        // Derive GitHub API URL from remote origin
        exec('git -C ' . escapeshellarg($app_dir) . ' config --get remote.origin.url 2>/dev/null', $url_out);
        $origin = trim($url_out[0] ?? '');
        $remote = '';

        if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?$#', $origin, $m)) {
            $api_url = 'https://api.github.com/repos/' . $m[1] . '/commits/main';
            $ctx     = stream_context_create(['http' => [
                'header'          => "User-Agent: trading-desk-update-check\r\n",
                'timeout'         => 6,
                'ignore_errors'   => true,
            ]]);
            $body = @file_get_contents($api_url, false, $ctx);
            if ($body) {
                $gh   = json_decode($body, true);
                $remote = $gh['sha'] ?? '';
            }
        }

        // Fallback: git fetch + rev-parse (may fail silently as www-data)
        if (!$remote) {
            putenv('HOME=/tmp');
            exec('git -C ' . escapeshellarg($app_dir) . ' fetch origin 2>/dev/null');
            exec('git -C ' . escapeshellarg($app_dir) . ' rev-parse origin/main 2>/dev/null', $ro);
            $remote = trim($ro[0] ?? '');
        }

        if ($remote) {
            file_put_contents($cache_file, json_encode(['ts' => time(), 'sha' => $remote]));
        }
    }

    if (!$remote) {
        echo json_encode(['ok' => true, 'has_update' => false, 'git_available' => true]);
        exit;
    }

    $has_update     = ($local !== $remote);
    $commits_behind = 0;

    if ($has_update) {
        // Best-effort commit count via git log comparison
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
