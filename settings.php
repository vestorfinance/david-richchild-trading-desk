<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$db   = get_db();
$msg  = null;
$type = 'success'; // 'success' | 'error'

// ── Handle actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add instrument
    if ($action === 'add') {
        $symbol       = strtoupper(trim($_POST['symbol'] ?? ''));
        $price_points = intval($_POST['price_points']  ?? 1);
        $default_lot  = floatval($_POST['default_lot'] ?? 0.10);

        if (!$symbol) {
            $msg  = 'Symbol is required.';
            $type = 'error';
        } elseif ($price_points <= 0) {
            $msg  = 'Good Price Points must be at least 1.';
            $type = 'error';
        } elseif ($default_lot <= 0) {
            $msg  = 'Default lot size must be greater than 0.';
            $type = 'error';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO instruments (symbol, price_points, default_lot) VALUES (?, ?, ?)");
                $stmt->execute([$symbol, $price_points, $default_lot]);
                $msg = "Instrument <strong>{$symbol}</strong> added successfully.";
            } catch (PDOException $e) {
                $msg  = "Symbol <strong>{$symbol}</strong> already exists.";
                $type = 'error';
            }
        }
    }

    // Delete instrument
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM instruments WHERE id = ?")->execute([$id]);
        $msg = 'Instrument removed.';
    }

    // Edit instrument
    if ($action === 'edit') {
        $id           = intval($_POST['id']           ?? 0);
        $price_points = intval($_POST['price_points'] ?? 1);
        $default_lot  = floatval($_POST['default_lot'] ?? 0.10);

        if ($price_points <= 0 || $default_lot <= 0) {
            $msg  = 'Good Price Points and default lot are required.';
            $type = 'error';
        } else {
            $db->prepare("UPDATE instruments SET price_points=?, default_lot=? WHERE id=?")
               ->execute([$price_points, $default_lot, $id]);
            $msg = 'Instrument updated.';
        }
    }
    // Regenerate API key
    if ($action === 'regenerate_api_key') {
        $new_key = bin2hex(random_bytes(32));
        $db->prepare("UPDATE users SET api_key = ? WHERE id = ?")
           ->execute([$new_key, $_SESSION['user_id']]);
        $msg = 'API key regenerated successfully.';
    }

    // Save universal settings
    if ($action === 'save_universal') {
        $expansion      = max(0,  intval($_POST['good_price_expansion'] ?? 20));
        $max_trades     = max(1,  intval($_POST['max_trades']           ?? 100));
        $default_nt     = max(1, min(99, intval($_POST['default_num_trades'] ?? 1)));
        $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('good_price_expansion', ?)")->execute([$expansion]);
        $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('max_trades', ?)")->execute([$max_trades]);
        $db->prepare("INSERT OR REPLACE INTO app_settings (key, value) VALUES ('default_num_trades', ?)")->execute([$default_nt]);
        $msg = 'Universal settings saved.';
    }
}

$instruments = $db->query("SELECT * FROM instruments ORDER BY symbol")->fetchAll();
$usettings_raw = $db->query("SELECT key, value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$good_price_expansion = intval($usettings_raw['good_price_expansion'] ?? 20);
$max_trades           = intval($usettings_raw['max_trades']           ?? 100);
$default_num_trades   = intval($usettings_raw['default_num_trades']   ?? 1);
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Settings — Instruments</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        'bg-primary':    '#0f0f0f',
                        'bg-secondary':  '#1a1a1a',
                        'bg-tertiary':   '#2d2d2d',
                        'text-primary':  '#ffffff',
                        'text-secondary':'#a0a0a0',
                        'accent':        '#4f46e5',
                        'accent-hover':  '#6366f1',
                        'border-col':    '#3a3a3a',
                        'success':       '#10b981',
                        'danger':        '#ef4444',
                        'warning':       '#f59e0b',
                        'card-bg':       '#1f1f1f',
                        'input-bg':      '#262626',
                        'input-border':  '#404040',
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --bg-primary:    #0f0f0f;
            --bg-secondary:  #1a1a1a;
            --bg-tertiary:   #2d2d2d;
            --text-primary:  #ffffff;
            --text-secondary:#a0a0a0;
            --accent:        #4f46e5;
            --accent-hover:  #6366f1;
            --border:        #3a3a3a;
            --success:       #10b981;
            --danger:        #ef4444;
            --warning:       #f59e0b;
            --card-bg:       #1f1f1f;
            --input-bg:      #262626;
            --input-border:  #404040;
        }

        body { background-color: var(--bg-primary); color: var(--text-primary); font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }

        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 1.5rem;
        }

        .field {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--text-primary);
            border-radius: 9999px;
            padding: 0.5rem 1rem;
            width: 100%;
            outline: none;
            transition: border-color 0.2s;
        }
        .field:focus { border-color: var(--accent); }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }
        .btn:active { transform: scale(0.97); }
        .btn-accent  { background-color: var(--accent);  color: #fff; }
        .btn-accent:hover  { background-color: var(--accent-hover); }
        .btn-danger  { background-color: var(--danger);  color: #fff; }
        .btn-danger:hover  { background-color: #dc2626; }
        .btn-warning { background-color: var(--warning); color: #000; }
        .btn-warning:hover { background-color: #d97706; }
        .btn-ghost {
            background-color: var(--bg-tertiary);
            color: var(--text-secondary);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover { color: var(--text-primary); }

        .tbl-row {
            border-bottom: 1px solid var(--border);
        }
        .tbl-row:last-child { border-bottom: none; }

        /* ── Navbar ─────────────────────────────────────────────────────── */
        .app-nav {
            background-color: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .nav-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--text-secondary);
            border: 1px solid transparent;
            text-decoration: none;
            transition: background-color 0.15s, border-color 0.15s, color 0.15s;
        }
        .nav-pill:hover, .nav-pill.active {
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
            border-color: var(--border);
        }
        @media (max-width: 639px) {
            .nav-pill { padding: 0.5rem; }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- ── Navbar ──────────────────────────────────────────────────────── -->
    <nav class="app-nav">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between">
            <a href="index.php" class="font-bold tracking-tight text-sm" style="color:var(--text-primary)">Trading Terminal</a>
            <div class="flex items-center gap-1">
                <span class="hidden sm:inline-flex text-xs px-3 py-1.5 font-medium mr-1" style="background-color:var(--bg-tertiary);color:var(--text-secondary);border-radius:9999px;border:1px solid var(--border)"><?= htmlspecialchars($_SESSION['username']) ?></span>
                <a href="index.php" class="nav-pill">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 sm:w-4 sm:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span class="hidden sm:inline">Terminal</span>
                </a>
                <a href="settings.php" class="nav-pill active">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 sm:w-4 sm:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span class="hidden sm:inline">Settings</span>
                </a>
                <a href="logout.php" class="nav-pill">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 sm:w-4 sm:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1"/>
                    </svg>
                    <span class="hidden sm:inline">Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="max-w-2xl mx-auto w-full space-y-6 p-6">

        <!-- ── Flash message ───────────────────────────────────────────── -->
        <?php if ($msg): ?>
        <?php $colors = $type === 'error'
            ? 'background-color:#3b1a1a;border-color:var(--danger);color:var(--danger)'
            : 'background-color:#0d2b20;border-color:var(--success);color:var(--success)'; ?>
        <div class="rounded-2xl p-3 text-sm" style="<?= $colors ?>;border-width:1px;border-style:solid;">
            <?= $msg ?>
        </div>
        <?php endif; ?>

        <!-- ── API Key ───────────────────────────────────────────────────── -->
        <div class="card overflow-hidden">
            <button type="button" onclick="toggleSection('api-key-body', this)"
                class="w-full flex items-center justify-between px-5 py-4 text-left"
                style="background:transparent;border:none;cursor:pointer;">
                <span class="font-semibold text-base">API Key</span>
                <svg id="api-key-chevron" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200" style="color:var(--text-secondary);transform:rotate(-90deg)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div id="api-key-body" class="hidden px-5 pb-5 space-y-3" style="border-top:1px solid var(--border)">
                <p class="text-xs pt-3" style="color:var(--text-secondary)">Authenticate API requests with this key. Keep it secret.</p>
                <div class="flex gap-2 items-center">
                    <input type="password" id="api-key-field" readonly
                        value="<?= htmlspecialchars($current_user['api_key']) ?>"
                        class="field font-mono text-xs" style="letter-spacing:0.04em" />
                    <button type="button" id="reveal-btn" onclick="toggleReveal(this)"
                        class="btn btn-ghost flex-shrink-0">Show</button>
                    <button type="button" id="copy-btn" onclick="copyApiKey(this)"
                        class="btn btn-ghost flex-shrink-0">Copy</button>
                </div>
                <form method="POST" onsubmit="return confirm('Regenerate API key? The old key will stop working immediately.')">
                    <input type="hidden" name="action" value="regenerate_api_key">
                    <button type="submit" class="btn btn-warning">Regenerate Key</button>
                </form>
            </div>
        </div>

        <!-- ── Add instrument ──────────────────────────────────────────── -->
        <div class="card overflow-hidden">
            <button type="button" onclick="toggleSection('add-instrument-body', this)"
                class="w-full flex items-center justify-between px-5 py-4 text-left"
                style="background:transparent;border:none;cursor:pointer;">
                <span class="font-semibold text-base">Add Instrument</span>
                <svg id="add-instrument-chevron" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200" style="color:var(--text-secondary);transform:rotate(-90deg)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div id="add-instrument-body" class="hidden px-5 pb-5" style="border-top:1px solid var(--border)">
            <form method="POST" action="" class="grid grid-cols-1 gap-3 sm:grid-cols-3 items-end pt-4">
                <input type="hidden" name="action" value="add">

                <div class="space-y-1">
                    <label class="block text-xs font-medium" style="color:var(--text-secondary)">Symbol</label>
                    <input type="text" name="symbol" placeholder="e.g. BTCUSD"
                        class="field uppercase" maxlength="20" required />
                </div>

                <div class="space-y-1">
                    <label class="block text-xs font-medium" style="color:var(--text-secondary)">Good Price Points</label>
                    <input type="number" name="price_points" placeholder="1"
                        class="field" min="1" step="1" value="1" required />
                </div>

                <div class="space-y-1">
                    <label class="block text-xs font-medium" style="color:var(--text-secondary)">Default Lot</label>
                    <input type="number" name="default_lot" placeholder="0.10"
                        class="field" min="0.01" step="0.01" value="0.10" required />
                </div>

                <div class="sm:col-span-3">
                    <button type="submit" class="btn btn-accent w-full sm:w-auto">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Instrument
                    </button>
                </div>
            </form>
            </div>
        </div>

        <!-- ── Universal Settings ──────────────────────────────────────── -->
        <div class="card overflow-hidden">
            <button type="button" onclick="toggleSection('universal-body', this)"
                class="w-full flex items-center justify-between px-5 py-4 text-left"
                style="background:transparent;border:none;cursor:pointer;">
                <span class="font-semibold text-base">Universal Settings</span>
                <svg id="universal-chevron" xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200" style="color:var(--text-secondary);transform:rotate(-90deg)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div id="universal-body" class="hidden px-5 pb-5" style="border-top:1px solid var(--border)">
                <form method="POST" class="grid grid-cols-1 gap-4 sm:grid-cols-3 items-end pt-4">
                    <input type="hidden" name="action" value="save_universal">

                    <div class="space-y-1">
                        <label class="block text-xs font-medium" style="color:var(--text-secondary)">Good Price Expansion (%)</label>
                        <input type="number" name="good_price_expansion"
                            value="<?= $good_price_expansion ?>"
                            class="field" min="0" step="1" required />
                        <p class="text-xs" style="color:var(--text-secondary)">Each successive good-price interval widens by this %.<br>e.g. 20 → gaps: 100 → 120 → 144 pts…</p>
                    </div>

                    <div class="space-y-1">
                        <label class="block text-xs font-medium" style="color:var(--text-secondary)">Max Number of Trades</label>
                        <input type="number" name="max_trades"
                            value="<?= $max_trades ?>"
                            class="field" min="1" step="1" required />
                        <p class="text-xs" style="color:var(--text-secondary)">EA will not open new trades if total open positions in terminal reaches this limit.</p>
                    </div>

                    <div class="space-y-1">
                        <label class="block text-xs font-medium" style="color:var(--text-secondary)">Default Trades per Order</label>
                        <input type="number" name="default_num_trades"
                            value="<?= $default_num_trades ?>"
                            class="field" min="1" max="99" step="1" required />
                        <p class="text-xs" style="color:var(--text-secondary)">Default number of entries per order on the terminal. EA fires each 60s apart.</p>
                    </div>

                    <div class="sm:col-span-3">
                        <button type="submit" class="btn btn-accent w-full sm:w-auto">Save Universal Settings</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Instruments list ────────────────────────────────────────── -->
        <div class="card overflow-hidden">
            <div class="px-5 py-4 border-b" style="border-color:var(--border)">
                <h2 class="font-semibold text-base">Instruments
                    <span class="ml-2 text-xs px-2 py-0.5 rounded-full font-normal"
                        style="background-color:var(--bg-tertiary);color:var(--text-secondary)">
                        <?= count($instruments) ?>
                    </span>
                </h2>
            </div>

            <?php if (empty($instruments)): ?>
            <p class="p-5 text-sm" style="color:var(--text-secondary)">No instruments yet. Add one above.</p>
            <?php else: ?>
            <div>
                <?php foreach ($instruments as $inst): ?>
                <!-- ── View row ── -->
                <div class="tbl-row px-5 py-3 flex flex-col sm:flex-row sm:items-center gap-3"
                    id="row-<?= $inst['id'] ?>">
                    <div class="flex-1 flex items-center gap-3">
                        <span class="font-bold text-sm px-3 py-1 rounded-full"
                            style="background-color:var(--accent);color:#fff">
                            <?= htmlspecialchars($inst['symbol']) ?>
                        </span>
                        <span class="text-xs ml-auto sm:ml-0" style="color:var(--text-secondary)">
                            price pts: <?= $inst['price_points'] ?> &nbsp;&middot;&nbsp; default lot: <?= number_format($inst['default_lot'] ?? 0.10, 2) ?>
                        </span>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="openEdit(<?= $inst['id'] ?>, <?= $inst['price_points'] ?>, <?= $inst['default_lot'] ?? 0.10 ?>)"
                            class="btn btn-warning" style="padding:0.375rem 0.75rem">
                            Edit
                        </button>
                        <form method="POST" onsubmit="return confirm('Remove <?= htmlspecialchars($inst['symbol']) ?>?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id"     value="<?= $inst['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding:0.375rem 0.75rem">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>

                <!-- ── Edit inline row (hidden by default) ── -->
                <div id="edit-<?= $inst['id'] ?>" class="hidden px-5 py-3"
                    style="background-color:var(--bg-secondary);border-top:1px solid var(--border)">
                    <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id"     value="<?= $inst['id'] ?>">

                        <div class="space-y-1">
                            <label class="block text-xs font-medium" style="color:var(--text-secondary)">Symbol</label>
                            <input type="text" value="<?= htmlspecialchars($inst['symbol']) ?>"
                                class="field opacity-50" disabled />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-medium" style="color:var(--text-secondary)">Good Price Points</label>
                            <input type="number" name="price_points" id="edit-pip-<?= $inst['id'] ?>"
                                class="field" min="1" step="1" required />
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-medium" style="color:var(--text-secondary)">Default Lot</label>
                            <input type="number" name="default_lot" id="edit-lot-<?= $inst['id'] ?>"
                                class="field" min="0.01" step="0.01" required />
                        </div>

                        <div class="sm:col-span-3 flex gap-2">
                            <button type="submit" class="btn btn-accent">Save</button>
                            <button type="button" onclick="closeEdit(<?= $inst['id'] ?>)"
                                class="btn btn-ghost">Cancel</button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        function toggleSection(bodyId, btn) {
            const body    = document.getElementById(bodyId);
            const chevron = btn.querySelector('svg');
            const hidden  = body.classList.toggle('hidden');
            chevron.style.transform = hidden ? 'rotate(-90deg)' : 'rotate(0deg)';
        }
        function toggleReveal(btn) {
            const field = document.getElementById('api-key-field');
            const showing = field.type === 'text';
            field.type   = showing ? 'password' : 'text';
            btn.textContent = showing ? 'Show' : 'Hide';
        }
        function copyApiKey(btn) {
            const field = document.getElementById('api-key-field');
            navigator.clipboard.writeText(field.value).then(() => {
                const orig = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => btn.textContent = orig, 2000);
            });
        }
        function openEdit(id, price_points, lot) {
            document.getElementById('edit-' + id).classList.remove('hidden');
            document.getElementById('edit-pip-' + id).value = price_points;
            document.getElementById('edit-lot-' + id).value = lot;
        }
        function closeEdit(id) {
            document.getElementById('edit-' + id).classList.add('hidden');
        }
    </script>
</body>
</html>
