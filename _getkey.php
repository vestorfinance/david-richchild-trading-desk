<?php
$p = new PDO('sqlite:/var/www/trading-desk/trader.db');
$key = $p->query('SELECT api_key FROM users LIMIT 1')->fetchColumn();
echo $key . "\n";

// Also test the update check directly
$appdir = '/var/www/trading-desk';
$head = trim(shell_exec('git -C ' . escapeshellarg($appdir) . ' rev-parse HEAD 2>/dev/null'));
$origin = trim(shell_exec('git -C ' . escapeshellarg($appdir) . ' config --get remote.origin.url 2>/dev/null'));
echo "HEAD: $head\n";
echo "Origin: $origin\n";

preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?$#', $origin, $m);
$api_url = 'https://api.github.com/repos/' . $m[1] . '/commits/main';
$ctx = stream_context_create(['http' => ['header' => "User-Agent: test\r\n", 'timeout' => 6, 'ignore_errors' => true]]);
$body = @file_get_contents($api_url, false, $ctx);
$gh = json_decode($body, true);
$remote = $gh['sha'] ?? 'FAILED';
echo "Remote SHA: $remote\n";
echo "Match: " . ($head === $remote ? 'UP TO DATE' : 'UPDATE AVAILABLE') . "\n";
echo "Cache: " . (is_file('/tmp/td_update_check') ? file_get_contents('/tmp/td_update_check') : 'none') . "\n";
