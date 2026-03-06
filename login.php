<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $db   = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            header('Location: index.php');
            exit;
        }
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login — Trading Terminal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                    colors: {
                        'bg-primary':    '#0f0f0f',
                        'bg-secondary':  '#1a1a1a',
                        'card-bg':       '#1f1f1f',
                        'input-bg':      '#262626',
                        'input-border':  '#404040',
                        'text-primary':  '#ffffff',
                        'text-secondary':'#a0a0a0',
                        'accent':        '#4f46e5',
                        'accent-hover':  '#6366f1',
                        'border-col':    '#3a3a3a',
                        'danger':        '#ef4444',
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --bg-primary:   #0f0f0f;
            --card-bg:      #1f1f1f;
            --input-bg:     #262626;
            --input-border: #404040;
            --text-primary: #ffffff;
            --text-secondary:#a0a0a0;
            --accent:       #4f46e5;
            --accent-hover: #6366f1;
            --border:       #3a3a3a;
            --danger:       #ef4444;
        }
        body { background-color: var(--bg-primary); color: var(--text-primary); font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }
        .app-nav {
            background-color: var(--bg-secondary, #1a1a1a);
            border-bottom: 1px solid var(--border, #3a3a3a);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .card { background-color: var(--card-bg); border: 1px solid var(--border); border-radius: 1.5rem; }
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
        .btn-primary:hover  { background-color: var(--accent-hover); }
        .btn-primary:active { transform: scale(0.98); }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <nav class="app-nav">
        <div class="max-w-5xl mx-auto px-6 h-14 flex items-center">
            <span class="font-bold tracking-tight text-sm" style="color:var(--text-primary)">Trading Terminal</span>
        </div>
    </nav>

    <div class="flex-1 flex items-center justify-center p-6">
    <div class="w-full max-w-sm space-y-5">

        <div class="text-center space-y-1">
            <h1 class="text-2xl font-bold tracking-tight">Trading Terminal</h1>
            <p class="text-sm" style="color:var(--text-secondary)">Sign in to continue</p>
        </div>

        <div class="card p-6 space-y-5">

            <?php if ($error): ?>
            <div class="rounded-2xl p-3 text-sm" style="background-color:#3b1a1a;border:1px solid var(--danger);color:var(--danger)">
                &#9888; <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4">
                <div class="space-y-1.5">
                    <label for="username" class="block text-sm font-medium" style="color:var(--text-secondary)">Username</label>
                    <input type="text" name="username" id="username" class="field" required
                           autocomplete="username" autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" />
                </div>
                <div class="space-y-1.5">
                    <label for="password" class="block text-sm font-medium" style="color:var(--text-secondary)">Password</label>
                    <input type="password" name="password" id="password" class="field" required
                           autocomplete="current-password" />
                </div>
                <button type="submit" class="btn-primary">Sign In</button>
            </form>

        </div>

        <p class="text-center text-xs" style="color:var(--text-secondary)">
            Trading Terminal &copy; <?= date('Y') ?>
        </p>
    </div>
    </div>

</body>
</html>
