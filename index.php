<?php
// v2026.03.06h
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$db          = get_db();
$instruments = $db->query("SELECT * FROM instruments ORDER BY symbol")->fetchAll();

$lot_defaults = [];
foreach ($instruments as $inst) {
    $lot_defaults[$inst['symbol']] = $inst['default_lot'] ?? 0.10;
}
$default_lot_value = number_format($lot_defaults['USOIL'] ?? 0.10, 2);

// Fetch the user's API key for the JS trade submit
$api_key_row = $db->prepare("SELECT api_key FROM users WHERE id = ?");
$api_key_row->execute([$_SESSION['user_id']]);
$user_api_key = $api_key_row->fetchColumn();

// Fetch universal settings defaults for the form
$usettings_row      = $db->query("SELECT key, value FROM app_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$default_num_trades = intval($usettings_row['default_num_trades'] ?? 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Trading Terminal</title>
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
        /* ── CSS custom properties ───────────────────────────────────────── */
        :root {
            --bg-primary:   #0f0f0f;
            --bg-secondary: #1a1a1a;
            --bg-tertiary:  #2d2d2d;
            --text-primary: #ffffff;
            --text-secondary:#a0a0a0;
            --accent:       #4f46e5;
            --accent-hover: #6366f1;
            --border:       #3a3a3a;
            --success:      #10b981;
            --danger:       #ef4444;
            --warning:      #f59e0b;
            --card-bg:      #1f1f1f;
            --input-bg:     #262626;
            --input-border: #404040;
        }

        body { background-color: var(--bg-primary); color: var(--text-primary); font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }

        /* ── Navbar ──────────────────────────────────────────────────── */
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

        /* Card — full bleed on mobile, card on sm+ */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 1.5rem;
        }
        @media (max-width: 639px) {
            .card {
                background-color: transparent;
                border: none;
                border-radius: 0;
            }
            .card-pad { padding: 0; }
        }
        @media (min-width: 640px) {
            .card-pad { padding: 1.5rem; }
        }

        /* Inputs / selects */
        .field {
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--text-primary);
            border-radius: 9999px;
            padding: 0.625rem 1rem;
            width: 100%;
            outline: none;
            transition: border-color 0.2s;
        }
        .field:focus { border-color: var(--accent); }
        .field option { background-color: var(--input-bg); }

        /* Radio-style direction buttons */
        .dir-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            flex: 1;
            padding: 0.75rem 1rem;
            border-radius: 9999px;
            border: 2px solid;
            cursor: pointer;
            font-weight: 600;
            letter-spacing: 0.05em;
            transition: background-color 0.2s, border-color 0.2s, color 0.2s;
            user-select: none;
        }
        /* Buy — always green tinted, solid when active */
        .dir-buy-label {
            border-color: var(--success);
            color: var(--success);
            background-color: rgba(16, 185, 129, 0.08);
        }
        .dir-buy-label.active {
            background-color: var(--success);
            color: #fff;
        }
        .dir-buy-label:hover         { background-color: rgba(16, 185, 129, 0.18); }
        .dir-buy-label.active:hover  { background-color: var(--success); }
        /* Sell — always red tinted, solid when active */
        .dir-sell-label {
            border-color: var(--danger);
            color: var(--danger);
            background-color: rgba(239, 68, 68, 0.08);
        }
        .dir-sell-label.active {
            background-color: var(--danger);
            color: #fff;
        }
        .dir-sell-label:hover        { background-color: rgba(239, 68, 68, 0.18); }
        .dir-sell-label.active:hover { background-color: var(--danger); }

        /* Primary button */
        .btn-primary {
            background-color: var(--accent);
            color: #fff;
            border-radius: 9999px;
            padding: 0.75rem 1.5rem;
            font-weight: 700;
            width: 100%;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }
        .btn-primary:hover   { background-color: var(--accent-hover); }
        .btn-primary:active  { transform: scale(0.98); }

        /* Badge */
        .badge-buy  { background-color: var(--success); border-radius: 9999px; }
        .badge-sell { background-color: var(--danger);  border-radius: 9999px; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- ── Navbar ──────────────────────────────────────────────────────── -->
    <nav class="app-nav">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between">
            <a href="index.php" class="font-bold tracking-tight text-sm" style="color:var(--text-primary)">Trading Terminal</a>
            <div class="flex items-center gap-1">
                <span class="hidden sm:inline-flex text-xs px-3 py-1.5 font-medium mr-1" style="background-color:var(--bg-tertiary);color:var(--text-secondary);border-radius:9999px;border:1px solid var(--border)"><?= htmlspecialchars($_SESSION['username']) ?></span>
                <a href="settings.php" class="nav-pill">
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

    <!-- ── Update Banner ──────────────────────────────────────────────────── -->
    <div id="update-banner" style="display:none;
         background:rgba(245,158,11,0.12);
         border-bottom:1px solid rgba(245,158,11,0.3);
         padding:.55rem 1rem;transition:opacity .4s">
        <div class="max-w-5xl mx-auto flex items-center justify-between gap-3 flex-wrap">
            <span id="update-banner-msg"
                  style="color:#fbbf24;font-size:.8rem;font-weight:500;display:flex;align-items:center;gap:.4rem">
                <!-- icon swapped by JS -->
                <svg id="update-banner-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.36-3.36L23 10M1 14l5.13 4.36A9 9 0 0 0 20.49 15"/></svg>
                <span id="update-banner-text">Update available</span>
            </span>
            <button id="pull-updates-btn" onclick="pullUpdates()"
                style="background:#b45309;color:#fff;border:none;border-radius:9999px;
                       padding:.35rem 1rem;font-size:.78rem;font-weight:700;
                       cursor:pointer;white-space:nowrap;transition:opacity .15s;display:flex;align-items:center;gap:.35rem">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="21" x2="12" y2="3"/></svg>
                <span id="pull-btn-text">Pull Update</span>
            </button>
        </div>
    </div>

    <div class="flex-1 flex items-start sm:items-center justify-center p-6 pt-6">
    <div class="w-full max-w-md space-y-4">

        <!-- ── Card ────────────────────────────────────────────────────── -->
        <div class="card card-pad space-y-6">

            <!-- P&L Display -->
            <div id="pnl-bar" class="rounded-2xl p-4 text-center"
                 style="background:var(--bg-tertiary);border:1px solid var(--border);transition:background .5s,border-color .5s">
                <p class="text-xs font-medium" style="color:var(--text-secondary);margin-bottom:.25rem">Account Floating P&amp;L</p>
                <p id="pnl-value" class="text-3xl font-bold tabular-nums" style="color:var(--text-secondary)">—</p>
            </div>

            <form id="trade-form" class="space-y-5">

                <!-- Instrument -->
                <div class="space-y-1.5">
                    <label for="instrument" class="block text-sm font-medium" style="color:var(--text-secondary)">
                        Instrument
                    </label>
                    <select name="instrument" id="instrument" class="field" required>
                        <option value="" disabled>
                            Select instrument…
                        </option>
                        <?php foreach ($instruments as $inst): ?>
                        <option value="<?= htmlspecialchars($inst['symbol']) ?>"
                            <?= (($_POST['instrument'] ?? 'USOIL') === $inst['symbol']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($inst['symbol']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Direction -->
                <div class="space-y-1.5">
                    <span class="block text-sm font-medium" style="color:var(--text-secondary)">Direction</span>
                    <div class="flex gap-3" id="direction-group">

                        <label class="dir-label dir-buy-label" id="label-buy" for="dir-buy">
                            <input type="radio" name="direction" id="dir-buy" value="buy"
                                class="sr-only"
                                <?= (($_POST['direction'] ?? '') === 'buy') ? 'checked' : '' ?>>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7 7 7M12 3v18"/>
                            </svg>
                            Buy
                        </label>

                        <label class="dir-label dir-sell-label" id="label-sell" for="dir-sell">
                            <input type="radio" name="direction" id="dir-sell" value="sell"
                                class="sr-only"
                                <?= (($_POST['direction'] ?? '') === 'sell') ? 'checked' : '' ?>>
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7-7-7M12 21V3"/>
                            </svg>
                            Sell
                        </label>

                    </div>
                </div>

                <!-- Lot Size -->
                <div class="space-y-1.5">
                    <label for="lot_size" class="block text-sm font-medium" style="color:var(--text-secondary)">
                        Lot Size
                    </label>
                    <div class="flex items-center gap-2">
                        <button type="button" id="lot-dec"
                            class="flex-shrink-0 w-9 h-9 rounded-full font-bold text-lg flex items-center justify-center transition-colors"
                            style="background-color:var(--bg-tertiary);color:var(--text-primary);border:1px solid var(--border)">
                            −
                        </button>
                        <input type="number" name="lot_size" id="lot_size"
                            class="field text-center font-semibold text-lg"
                            min="0.01" max="100" step="0.01"
                            value="<?= htmlspecialchars($_POST['lot_size'] ?? $default_lot_value) ?>"
                            required />
                        <button type="button" id="lot-inc"
                            class="flex-shrink-0 w-9 h-9 rounded-full font-bold text-lg flex items-center justify-center transition-colors"
                            style="background-color:var(--bg-tertiary);color:var(--text-primary);border:1px solid var(--border)">
                            +
                        </button>
                    </div>
                </div>

                <!-- Number of Trades -->
                <div class="space-y-1.5">
                    <label for="num_trades" class="block text-sm font-medium" style="color:var(--text-secondary)">
                        Number of Trades
                    </label>
                    <div class="flex items-center gap-2">
                        <button type="button" id="nt-dec"
                            class="flex-shrink-0 w-9 h-9 rounded-full font-bold text-lg flex items-center justify-center transition-colors"
                            style="background-color:var(--bg-tertiary);color:var(--text-primary);border:1px solid var(--border)">
                            −
                        </button>
                        <input type="number" name="num_trades" id="num_trades"
                            class="field text-center font-semibold text-lg"
                            min="1" max="99" step="1"
                            value="<?= $default_num_trades ?>"
                            required />
                        <button type="button" id="nt-inc"
                            class="flex-shrink-0 w-9 h-9 rounded-full font-bold text-lg flex items-center justify-center transition-colors"
                            style="background-color:var(--bg-tertiary);color:var(--text-primary);border:1px solid var(--border)">
                            +
                        </button>
                    </div>
                </div>

                <!-- Submit -->
                <button type="submit" id="submit-btn" class="btn-primary">
                    Place Order
                </button>

                <!-- Manage -->
                <button type="button" id="manage-btn" class="btn-primary flex items-center justify-center gap-2"
                    style="background-color:var(--accent)">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                    </svg>
                    Manage Positions
                </button>

            </form>
        </div>

        <p class="text-center text-xs" style="color:var(--text-secondary)">
            Trading Terminal &copy; <?= date('Y') ?>
        </p>
    </div>
    </div>

    <!-- ── Trade Toast ─────────────────────────────────────────────────── -->
    <div id="trade-status"
        style="position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%) translateY(120%);z-index:100;
               min-width:260px;max-width:calc(100vw - 2rem);padding:.75rem 1.25rem;
               border-radius:9999px;font-size:.875rem;font-weight:500;text-align:center;
               pointer-events:none;transition:transform .35s cubic-bezier(.34,1.56,.64,1),opacity .35s ease;
               opacity:0;"></div>

    <!-- ── Manage Modal ──────────────────────────────────────────────────── -->
    <div id="manage-modal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center"
        style="background:rgba(0,0,0,0.7);backdrop-filter:blur(4px)">
        <div class="w-full sm:max-w-sm sm:mx-4 overflow-y-auto"
            style="background-color:var(--card-bg);border:1px solid var(--border);max-height:100dvh;border-radius:1.5rem 1.5rem 0 0;">

            <!-- Header -->
            <div class="flex items-center justify-between px-5 py-4"
                style="border-bottom:1px solid var(--border)">
                <span class="font-semibold text-base">Manage Positions</span>
                <button type="button" id="manage-close"
                    class="flex items-center justify-center w-8 h-8 rounded-full"
                    style="background:var(--bg-tertiary);color:var(--text-secondary);border:none;cursor:pointer">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Status -->
            <div id="manage-status" class="hidden mx-5 mt-4 rounded-2xl p-3 text-sm"></div>

            <!-- Actions -->
            <div class="p-5 space-y-3">

                <button type="button" class="manage-btn w-full flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium text-left"
                    style="background:var(--bg-tertiary);border:1px solid var(--border);color:var(--text-primary);cursor:pointer"
                    onclick="sendManage('break_even')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12h18M3 12l4-4M3 12l4 4"/>
                    </svg>
                    <div><div class="font-semibold">Break Even</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary)">Move SL to entry on all profitable positions</div></div>
                </button>

                <button type="button" class="manage-btn w-full flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium text-left"
                    style="background:var(--bg-tertiary);border:1px solid var(--border);color:var(--text-primary);cursor:pointer"
                    onclick="sendManage('delete_sl')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                    </svg>
                    <div><div class="font-semibold">Delete SL</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary)">Remove stop loss from all open positions</div></div>
                </button>

                <button type="button" class="manage-btn w-full flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium text-left"
                    style="background:var(--bg-tertiary);border:1px solid var(--border);color:var(--text-primary);cursor:pointer"
                    onclick="sendManage('close_losing')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" style="color:var(--danger)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7-7-7"/>
                    </svg>
                    <div><div class="font-semibold" style="color:var(--danger)">Close Losing</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary)">Close all positions currently in loss</div></div>
                </button>

                <button type="button" class="manage-btn w-full flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium text-left"
                    style="background:var(--bg-tertiary);border:1px solid var(--border);color:var(--text-primary);cursor:pointer"
                    onclick="sendManage('close_profitable')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" style="color:var(--success)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7 7 7"/>
                    </svg>
                    <div><div class="font-semibold" style="color:var(--success)">Close Profitable</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary)">Close all positions currently in profit</div></div>
                </button>

                <button type="button" class="manage-btn w-full flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium text-left"
                    style="background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.4);color:var(--danger);cursor:pointer"
                    onclick="sendManage('close_all')">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    <div><div class="font-semibold">Close All</div>
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary)">Close every open position immediately</div></div>
                </button>

            </div>
        </div>
    </div>

    <script>
        const lotDefaults = <?= json_encode($lot_defaults) ?>;
        const USER_API_KEY = <?= json_encode($user_api_key) ?>;

        // ── Trade form AJAX submit ──────────────────────────────────────────
        document.getElementById('trade-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const symbol    = document.getElementById('instrument').value;
            const direction = [...document.querySelectorAll('input[name="direction"]')].find(r => r.checked)?.value;
            const lot        = parseFloat(document.getElementById('lot_size').value);
            const num_trades = Math.max(1, Math.min(99, parseInt(document.getElementById('num_trades').value) || 1));
            const btn        = document.getElementById('submit-btn');
            const status     = document.getElementById('trade-status');

            // Client-side validation
            if (!symbol)                          return showStatus('error', '&#9888; Please select an instrument.');
            if (!direction)                       return showStatus('error', '&#9888; Please choose Buy or Sell.');
            if (!lot || lot <= 0)                 return showStatus('error', '&#9888; Lot size must be greater than 0.');

            btn.disabled = true;
            btn.textContent = 'Sending…';

            try {
                const fd = new FormData();
                fd.append('api_key',   USER_API_KEY);
                fd.append('action',    'queue');
                fd.append('symbol',    symbol);
                fd.append('direction', direction);
                fd.append('lot',       lot.toFixed(2));
                fd.append('num_trades', num_trades);

                const res  = await fetch('api/trade.php', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.ok) {
                    showStatus('success', '&#10003; Order queued &mdash; EA will execute shortly.');
                } else {
                    showStatus('error', '&#9888; ' + (data.error || 'Failed to queue order.'));
                }
            } catch(err) {
                showStatus('error', '&#9888; Network error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.textContent = 'Place Order';
            }
        });

        let _toastTimer = null;
        function showStatus(type, msg) {
            const el = document.getElementById('trade-status');
            if (type === 'success') {
                el.style.background = '#0d2b20';
                el.style.border     = '1px solid var(--success)';
                el.style.color      = 'var(--success)';
            } else {
                el.style.background = '#3b1a1a';
                el.style.border     = '1px solid var(--danger)';
                el.style.color      = 'var(--danger)';
            }
            el.innerHTML = msg;
            el.style.opacity   = '1';
            el.style.transform = 'translateX(-50%) translateY(0)';
            if (_toastTimer) clearTimeout(_toastTimer);
            _toastTimer = setTimeout(() => {
                el.style.opacity   = '0';
                el.style.transform = 'translateX(-50%) translateY(120%)';
            }, 5000);
        }

        // ── Instrument change → load its default lot ────────────────────────
        document.getElementById('instrument').addEventListener('change', function () {
            const def = lotDefaults[this.value];
            if (def !== undefined) {
                document.getElementById('lot_size').value = parseFloat(def).toFixed(2);
            }
        });

        // ── Radio visual state ──────────────────────────────────────────────
        const radios = document.querySelectorAll('input[name="direction"]');
        const lblBuy  = document.getElementById('label-buy');
        const lblSell = document.getElementById('label-sell');

        function syncLabels() {
            const val = [...radios].find(r => r.checked)?.value;
            lblBuy.classList.toggle('active',  val === 'buy');
            lblSell.classList.toggle('active', val === 'sell');
        }
        radios.forEach(r => r.addEventListener('change', syncLabels));
        syncLabels(); // hydrate on page load (PHP post-back)

        // ── Lot-size stepper ───────────────────────────────────────────────
        const lotInput = document.getElementById('lot_size');
        const STEP = 0.01;

        document.getElementById('lot-inc').addEventListener('click', () => {
            lotInput.value = Math.min(100, parseFloat(lotInput.value || 0) + STEP).toFixed(2);
        });
        document.getElementById('lot-dec').addEventListener('click', () => {
            lotInput.value = Math.max(0.01, parseFloat(lotInput.value || 0) - STEP).toFixed(2);
        });

        // ── Num-trades stepper ─────────────────────────────────────────────────────
        const ntInput = document.getElementById('num_trades');
        document.getElementById('nt-inc').addEventListener('click', () => {
            ntInput.value = Math.min(99, (parseInt(ntInput.value) || 1) + 1);
        });
        document.getElementById('nt-dec').addEventListener('click', () => {
            ntInput.value = Math.max(1, (parseInt(ntInput.value) || 1) - 1);
        });

        // ── Manage modal ──────────────────────────────────────────────────
        async function sendManage(command) {
            const statusEl = document.getElementById('manage-status');
            const btns     = document.querySelectorAll('.manage-btn');
            btns.forEach(b => b.disabled = true);

            try {
                const fd = new FormData();
                fd.append('api_key', USER_API_KEY);
                fd.append('action',  'manage');
                fd.append('command', command);

                const res  = await fetch('api/trade.php', { method: 'POST', body: fd });
                const data = await res.json();

                statusEl.className = 'mx-5 mt-4 rounded-2xl p-3 text-sm';
                if (data.ok) {
                    manageModal.classList.add('hidden');
                    statusEl.style.cssText = 'background-color:#0d2b20;border:1px solid var(--success);color:var(--success)';
                    statusEl.textContent   = '✓ Command queued — EA will execute shortly.';
                } else {
                    statusEl.style.cssText = 'background-color:#3b1a1a;border:1px solid var(--danger);color:var(--danger)';
                    statusEl.textContent   = '⚠ ' + (data.error || 'Failed to queue command.');
                }
                statusEl.classList.remove('hidden');
                setTimeout(() => statusEl.classList.add('hidden'), 5000);
            } catch(err) {
                statusEl.style.cssText = 'background-color:#3b1a1a;border:1px solid var(--danger);color:var(--danger)';
                statusEl.textContent   = '⚠ Network error: ' + err.message;
                statusEl.classList.remove('hidden');
            } finally {
                btns.forEach(b => b.disabled = false);
            }
        }

        // Open / close manage modal
        const manageModal = document.getElementById('manage-modal');
        document.getElementById('manage-btn').addEventListener('click', () => manageModal.classList.remove('hidden'));
        document.getElementById('manage-close').addEventListener('click', () => manageModal.classList.add('hidden'));
        manageModal.addEventListener('click', function(e) {
            if (e.target === this) this.classList.add('hidden');
        });

        // ── Update check ───────────────────────────────────────────────────
        async function checkForUpdates() {
            try {
                const fd = new FormData();
                fd.append('api_key', USER_API_KEY);
                fd.append('action',  'check');
                const res  = await fetch('api/update.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.ok || !data.git_available) return;
                const banner = document.getElementById('update-banner');
                if (data.has_update) {
                    const n = data.commits_behind || 0;
                    const label = n ? ` — ${n} new commit${n > 1 ? 's' : ''}` : '';
                    document.getElementById('update-banner-text').textContent = 'Update available' + label;
                    // restore to update icon in case it was swapped
                    document.getElementById('update-banner-icon').innerHTML = '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.36-3.36L23 10M1 14l5.13 4.36A9 9 0 0 0 20.49 15"/>';
                    document.getElementById('update-banner-icon').setAttribute('viewBox','0 0 24 24');
                    banner.style.background = 'rgba(245,158,11,0.12)';
                    banner.style.borderBottom = '1px solid rgba(245,158,11,0.3)';
                    document.getElementById('update-banner-msg').style.color = '#fbbf24';
                    const btn = document.getElementById('pull-updates-btn');
                    btn.style.display = 'flex';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    document.getElementById('pull-btn-text').textContent = 'Pull Update';
                    banner.style.opacity = '1';
                    banner.style.display = 'block';
                } else {
                    const banner = document.getElementById('update-banner');
                    if (banner.dataset.pulled !== '1') banner.style.display = 'none';
                }
            } catch(e) {}
        }

        async function pullUpdates() {
            const btn     = document.getElementById('pull-updates-btn');
            const banner  = document.getElementById('update-banner');
            btn.disabled  = true;
            btn.style.opacity = '0.5';
            document.getElementById('pull-btn-text').textContent = 'Pulling...';
            try {
                const fd = new FormData();
                fd.append('api_key', USER_API_KEY);
                fd.append('action',  'pull');
                const res  = await fetch('api/update.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) {
                    // Show success state in banner, then fade out and reload
                    banner.dataset.pulled = '1';
                    banner.style.background    = 'rgba(34,197,94,0.12)';
                    banner.style.borderBottom  = '1px solid rgba(34,197,94,0.3)';
                    document.getElementById('update-banner-msg').style.color = '#4ade80';
                    document.getElementById('update-banner-icon').innerHTML  = '<polyline points="20 6 9 17 4 12"/>';
                    document.getElementById('update-banner-text').textContent = 'Update applied — reloading...';
                    btn.style.display = 'none';
                    setTimeout(() => {
                        banner.style.opacity = '0';
                        setTimeout(() => { banner.style.display = 'none'; location.reload(); }, 500);
                    }, 1800);
                } else {
                    document.getElementById('update-banner-text').textContent = 'Pull failed — try again';
                    document.getElementById('update-banner-msg').style.color  = '#f87171';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    document.getElementById('pull-btn-text').textContent = 'Retry';
                    showStatus('error', 'Update failed: ' + (data.error || 'unknown error'));
                }
            } catch(e) {
                document.getElementById('update-banner-text').textContent = 'Network error — try again';
                document.getElementById('update-banner-msg').style.color  = '#f87171';
                btn.disabled = false;
                btn.style.opacity = '1';
                document.getElementById('pull-btn-text').textContent = 'Retry';
            }
        }

        checkForUpdates();
        setInterval(checkForUpdates, 60000);

        // ── Live P&L polling ────────────────────────────────────────────
        async function fetchPnL() {
            try {
                const fd = new FormData();
                fd.append('api_key', USER_API_KEY);
                fd.append('action',  'get');
                const res  = await fetch('api/stats.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (!data.ok) return;
                const profit = data.profit;
                const el  = document.getElementById('pnl-value');
                const bar = document.getElementById('pnl-bar');
                el.textContent = (profit >= 0 ? '+' : '') + profit.toFixed(2);
                if (profit > 0) {
                    el.style.color        = 'var(--success)';
                    bar.style.background  = 'rgba(16,185,129,0.1)';
                    bar.style.borderColor = 'rgba(16,185,129,0.35)';
                } else if (profit < 0) {
                    el.style.color        = 'var(--danger)';
                    bar.style.background  = 'rgba(239,68,68,0.1)';
                    bar.style.borderColor = 'rgba(239,68,68,0.35)';
                } else {
                    el.style.color        = 'var(--text-secondary)';
                    bar.style.background  = 'var(--bg-tertiary)';
                    bar.style.borderColor = 'var(--border)';
                }
            } catch(e) {}
        }
        fetchPnL();
        setInterval(fetchPnL, 1000);
    </script>
</body>
</html>
