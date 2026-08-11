<?php
require_once __DIR__ . '/db.php';
session_start();

header_remove('Content-Type');
header('Content-Type: text/html; charset=UTF-8');

date_default_timezone_set('Asia/Kolkata');

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function inr($value, $usdToInrRate) {
    return 'Rs. ' . number_format(((float) $value) * (float) $usdToInrRate, 2);
}

function compact_number($value) {
    $value = (float) $value;

    if ($value >= 1000000) {
        return number_format($value / 1000000, 2) . 'M';
    }

    if ($value >= 1000) {
        return number_format($value / 1000, 1) . 'K';
    }

    return number_format($value);
}

$adminLoginUsername = 'digi';
$adminPasswordHash = '$2y$10$OXzAR7p5dSUgeh10H6tDFuY/djJ6fNGZqVxgs1M08UxjzJP8uYuB6';

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $_SESSION = [];
    session_destroy();
    header('Location: master_control_center.php');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['auth_action'] ?? '') === 'admin_login') {
    $submittedUsername = trim($_POST['admin_username'] ?? '');
    $submittedPassword = trim($_POST['admin_password'] ?? '');

    if ($submittedUsername === $adminLoginUsername && password_verify($submittedPassword, $adminPasswordHash)) {
        $_SESSION['mastercrm_admin_logged_in'] = true;
        $_SESSION['mastercrm_admin_username'] = $submittedUsername;
        header('Location: master_control_center.php');
        exit;
    }

    $loginError = 'Invalid username or password.';
}

$isAdminLoggedIn = !empty($_SESSION['mastercrm_admin_logged_in']);

if (!$isAdminLoggedIn):
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#f4f5f7]">
<head>
    <meta charset="UTF-8">
    <title>Login | DigiChefs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(99,102,241,0.12), transparent 24%),
                radial-gradient(circle at right, rgba(45,212,191,0.10), transparent 20%),
                #f4f5f7;
        }

        .heading-font {
            font-family: 'Outfit', sans-serif;
        }
    </style>
</head>
<body class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-md rounded-[28px] border border-slate-200/70 bg-white p-8 shadow-[0_24px_60px_-18px_rgba(15,23,42,0.14)]">
        <div class="mb-8 text-center">
            <img src="2019_dc-logo_low.png" alt="DigiChefs" class="mx-auto h-12 w-auto object-contain">
            <h1 class="heading-font mt-5 text-3xl font-bold tracking-tight text-slate-900">Admin Login</h1>
            <p class="mt-2 text-sm text-slate-400">Sign in to access the Master Control Center.</p>
        </div>

        <?php if ($loginError !== ''): ?>
            <div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                <?= h($loginError) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <input type="hidden" name="auth_action" value="admin_login">
            <label class="block text-sm font-bold text-slate-600">
                Username
                <input type="text" name="admin_username" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 outline-none focus:border-indigo-300 focus:bg-white" placeholder="Enter username" required>
            </label>
            <label class="block text-sm font-bold text-slate-600">
                Password
                <input type="password" name="admin_password" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 outline-none focus:border-indigo-300 focus:bg-white" placeholder="Enter password" required>
            </label>
            <button type="submit" class="w-full rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700">
                Login
            </button>
        </form>
    </div>
</body>
</html>
<?php
exit;
endif;

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));
$selectedToolId = max(0, (int) ($_GET['tool_id'] ?? 0));
$usdToInrRate = (float) ($_GET['usd_inr'] ?? 95.40);
if ($usdToInrRate <= 0) {
    $usdToInrRate = 95.40;
}

$stmt = $pdo->query("
    SELECT
        id,
        tool_name,
        is_active,
        created_at
    FROM ai_tools
    ORDER BY tool_name ASC
");
$tools = $stmt->fetchAll();

$selectedToolName = '';
foreach ($tools as $toolRow) {
    if ((int) $toolRow['id'] === $selectedToolId) {
        $selectedToolName = $toolRow['tool_name'];
        break;
    }
}

$summarySql = "
    SELECT
        COUNT(*) AS total_requests,
        COALESCE(SUM(input_tokens), 0) AS input_tokens,
        COALESCE(SUM(output_tokens), 0) AS output_tokens,
        COALESCE(SUM(total_tokens), 0) AS total_tokens,
        COALESCE(SUM(image_count), 0) AS total_images,
        COALESCE(SUM(input_cost), 0) AS input_cost,
        COALESCE(SUM(output_cost), 0) AS output_cost,
        COALESCE(SUM(total_cost), 0) AS total_cost
    FROM ai_usage_logs
    WHERE DATE(created_at) BETWEEN :from_date AND :to_date
";
$summaryParams = [
    ':from_date' => $from,
    ':to_date' => $to
];
if ($selectedToolName !== '') {
    $summarySql .= " AND tool_name = :tool_name";
    $summaryParams[':tool_name'] = $selectedToolName;
}
$stmt = $pdo->prepare($summarySql);
$stmt->execute($summaryParams);
$summary = $stmt->fetch() ?: [
    'total_requests' => 0,
    'input_tokens' => 0,
    'output_tokens' => 0,
    'total_tokens' => 0,
    'total_images' => 0,
    'input_cost' => 0,
    'output_cost' => 0,
    'total_cost' => 0
];

$stmt = $pdo->query("
    SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN UPPER(role) = 'ADMIN' THEN 1 ELSE 0 END) AS total_admins,
        SUM(CASE WHEN UPPER(role) = 'USER' THEN 1 ELSE 0 END) AS total_standard_users
    FROM user_accounts
");
$userSummary = $stmt->fetch() ?: [
    'total_users' => 0,
    'total_admins' => 0,
    'total_standard_users' => 0
];

$systemSql = "
    SELECT
        at.id,
        at.tool_name,
        at.is_active,
        COUNT(DISTINCT ua.id) AS user_count,
        COALESCE(COUNT(aul.id), 0) AS request_count,
        COALESCE(SUM(aul.total_tokens), 0) AS total_tokens,
        COALESCE(SUM(aul.image_count), 0) AS total_images,
        COALESCE(SUM(aul.input_cost), 0) AS input_cost,
        COALESCE(SUM(aul.output_cost), 0) AS output_cost,
        COALESCE(SUM(aul.total_cost), 0) AS total_cost,
        MAX(aul.created_at) AS last_activity
    FROM ai_tools at
    LEFT JOIN user_accounts ua
        ON ua.tool_id = at.id
    LEFT JOIN ai_usage_logs aul
        ON aul.tool_name = at.tool_name
        AND DATE(aul.created_at) BETWEEN :from_date AND :to_date
";
$systemParams = [
    ':from_date' => $from,
    ':to_date' => $to
];
if ($selectedToolId > 0) {
    $systemSql .= " WHERE at.id = :selected_tool_id";
    $systemParams[':selected_tool_id'] = $selectedToolId;
}
$systemSql .= "
    GROUP BY at.id, at.tool_name, at.is_active
    ORDER BY total_cost DESC, at.tool_name ASC
";
$stmt = $pdo->prepare($systemSql);
$stmt->execute($systemParams);
$systemCards = $stmt->fetchAll();

$userSql = "
    SELECT
        ua.id,
        ua.username,
        ua.password,
        ua.role,
        ua.tool_id,
        ua.created_at,
        at.tool_name,
        at.is_active,
        COALESCE(stats.request_count, 0) AS system_request_count,
        COALESCE(stats.total_tokens, 0) AS system_total_tokens,
        COALESCE(stats.total_images, 0) AS system_total_images,
        COALESCE(stats.system_total_cost, 0) AS system_total_cost,
        stats.last_activity
    FROM user_accounts ua
    LEFT JOIN ai_tools at
        ON at.id = ua.tool_id
    LEFT JOIN (
        SELECT
            tool_name,
            COUNT(*) AS request_count,
            COALESCE(SUM(total_tokens), 0) AS total_tokens,
            COALESCE(SUM(image_count), 0) AS total_images,
            COALESCE(SUM(total_cost), 0) AS system_total_cost,
            MAX(created_at) AS last_activity
        FROM ai_usage_logs
        WHERE DATE(created_at) BETWEEN :from_date AND :to_date
        GROUP BY tool_name
    ) stats
        ON stats.tool_name = at.tool_name
";
$userParams = [
    ':from_date' => $from,
    ':to_date' => $to
];
if ($selectedToolId > 0) {
    $userSql .= " WHERE ua.tool_id = :selected_tool_id";
    $userParams[':selected_tool_id'] = $selectedToolId;
}
$userSql .= " ORDER BY at.tool_name ASC, ua.id DESC";
$stmt = $pdo->prepare($userSql);
$stmt->execute($userParams);
$users = $stmt->fetchAll();

$dailySql = "
    SELECT
        DATE(created_at) AS usage_date,
        COUNT(*) AS requests,
        COALESCE(SUM(total_cost), 0) AS total_cost
    FROM ai_usage_logs
    WHERE DATE(created_at) BETWEEN :from_date AND :to_date
";
$dailyParams = [
    ':from_date' => $from,
    ':to_date' => $to
];
if ($selectedToolName !== '') {
    $dailySql .= " AND tool_name = :tool_name";
    $dailyParams[':tool_name'] = $selectedToolName;
}
$dailySql .= "
    GROUP BY DATE(created_at)
    ORDER BY usage_date ASC
";
$stmt = $pdo->prepare($dailySql);
$stmt->execute($dailyParams);
$dailyRows = $stmt->fetchAll();

$logsSql = "
    SELECT
        id,
        tool_name,
        model_name,
        total_tokens,
        image_count,
        input_cost,
        output_cost,
        total_cost,
        status,
        notes,
        created_at
    FROM ai_usage_logs
    WHERE DATE(created_at) BETWEEN :from_date AND :to_date
";
$logsParams = [
    ':from_date' => $from,
    ':to_date' => $to
];
if ($selectedToolName !== '') {
    $logsSql .= " AND tool_name = :tool_name";
    $logsParams[':tool_name'] = $selectedToolName;
}
$logsSql .= "
    ORDER BY id DESC
    LIMIT 100
";
$stmt = $pdo->prepare($logsSql);
$stmt->execute($logsParams);
$logs = $stmt->fetchAll();

$toolLabels = [];
$toolCosts = [];
$toolColors = ['#6366f1', '#f43f5e', '#10b981', '#06b6d4', '#f59e0b', '#8b5cf6'];
$toolLegend = [];

foreach ($systemCards as $index => $system) {
    $toolLabels[] = $system['tool_name'];
    $toolCosts[] = round((float) $system['total_cost'], 6);
    $toolLegend[] = [
        'name' => $system['tool_name'],
        'color' => $toolColors[$index % count($toolColors)]
    ];
}

$dailyLabels = [];
$dailyRequestData = [];
$dailyCostData = [];
foreach ($dailyRows as $dailyRow) {
    $dailyLabels[] = date('M d', strtotime($dailyRow['usage_date']));
    $dailyRequestData[] = (int) $dailyRow['requests'];
    $dailyCostData[] = round((float) $dailyRow['total_cost'], 6);
}

$avgTokens = (int) $summary['total_requests'] > 0 ? ((float) $summary['total_tokens'] / (int) $summary['total_requests']) : 0;
$avgCost = (int) $summary['total_requests'] > 0 ? ((float) $summary['total_cost'] / (int) $summary['total_requests']) : 0;
$inputShare = (float) $summary['total_cost'] > 0 ? round(((float) $summary['input_cost'] / (float) $summary['total_cost']) * 100) : 0;
$outputShare = (float) $summary['total_cost'] > 0 ? 100 - $inputShare : 0;
$rangeLabel = $from === $to ? $from : ($from . ' to ' . $to);
$headerLabel = $selectedToolName !== '' ? $selectedToolName . ' • ' . $rangeLabel : $rangeLabel;
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#f4f5f7]">
<head>
    <meta charset="UTF-8">
    <title>Master Control Center | DigiChefs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        outfit: ['"Outfit"', 'sans-serif']
                    },
                    boxShadow: {
                        premium: '0 10px 40px -10px rgba(15, 23, 42, 0.05)',
                        card: '0 12px 40px -12px rgba(15, 23, 42, 0.08)'
                    }
                }
            }
        };
    </script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f4f5f7;
            -webkit-font-smoothing: antialiased;
        }

        .heading-font {
            font-family: 'Outfit', sans-serif;
        }

        .nice-scroll::-webkit-scrollbar {
            height: 6px;
            width: 6px;
        }

        .nice-scroll::-webkit-scrollbar-thumb {
            background: #d8deea;
            border-radius: 999px;
        }

        .transition-soft {
            transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .nav-active {
            background-color: #edf2fb;
            color: #1e293b;
            font-weight: 600;
        }
    </style>
</head>
<body class="h-full text-slate-800 antialiased">
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,_rgba(99,102,241,0.08),_transparent_22%),radial-gradient(circle_at_right,_rgba(45,212,191,0.08),_transparent_18%)]"></div>

    <div id="mobile-sidebar-backdrop" class="fixed inset-0 z-40 hidden bg-slate-900/40 backdrop-blur-sm" onclick="toggleMobileSidebar()"></div>

    <aside id="sidebar-container" class="fixed inset-y-0 left-0 z-50 flex w-[286px] max-w-[86vw] flex-col border-r border-slate-200/70 bg-white px-5 py-5 transition-transform duration-300 -translate-x-full">
        <div class="mb-6 flex items-center justify-end">
            <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-soft hover:text-slate-900" onclick="toggleMobileSidebar()" aria-label="Close menu">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="mb-8 flex items-center">
            <img src="2019_dc-logo_low.png" alt="DigiChefs" class="h-10 w-auto object-contain">
        </div>

        <nav class="flex-1 space-y-2 px-1">
            <a href="#analytics" class="nav-active flex items-center gap-3 rounded-2xl px-4 py-3 text-[0.95rem] text-slate-500 transition-soft hover:bg-slate-50 hover:text-slate-900">
                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2v-4z" />
                </svg>
                <span>Analytics</span>
            </a>

            <a href="#systems" class="flex items-center gap-3 rounded-2xl px-4 py-3 text-[0.95rem] text-slate-500 transition-soft hover:bg-slate-50 hover:text-slate-900">
                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.003 9.003 0 1020.945 13H11V3.055z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                </svg>
                <span>System Split</span>
            </a>

            <a href="#usage-dynamics" class="flex items-center gap-3 rounded-2xl px-4 py-3 text-[0.95rem] text-slate-500 transition-soft hover:bg-slate-50 hover:text-slate-900">
                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
                <span>Daily Dynamics</span>
            </a>

            <a href="#data-tables" class="flex items-center gap-3 rounded-2xl px-4 py-3 text-[0.95rem] text-slate-500 transition-soft hover:bg-slate-50 hover:text-slate-900">
                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <span>Logs & Tables</span>
            </a>

            <div class="my-5 h-px bg-slate-100"></div>

            <a href="#filters" class="flex items-center gap-3 rounded-2xl px-4 py-3 text-[0.95rem] text-slate-500 transition-soft hover:bg-slate-50 hover:text-slate-900">
                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                <span>Filter Panel</span>
            </a>

            <a href="#directory" class="flex items-center gap-3 rounded-2xl px-4 py-3 text-[0.95rem] text-slate-500 transition-soft hover:bg-slate-50 hover:text-slate-900">
                <svg class="h-6 w-6 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5V4H2v16h5m10 0v-2a4 4 0 00-4-4H9a4 4 0 00-4 4v2m12 0H7m10-10a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <span>User Directory</span>
            </a>
        </nav>

    </aside>

    <div class="min-h-screen">
        <header class="sticky top-0 z-30 flex h-[76px] items-center justify-between border-b border-slate-200/60 bg-[#f4f5f7]/90 px-6 backdrop-blur-md">
            <div class="flex items-center gap-4">
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-soft hover:text-slate-900" onclick="toggleMobileSidebar()" aria-label="Open menu">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <h1 class="heading-font text-3xl font-bold tracking-tight text-slate-900">Analytics</h1>
                <div class="hidden items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-500 shadow-sm sm:flex">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span><?= h($headerLabel) ?></span>
                </div>
            </div>

            <div class="flex items-center gap-5">
                <div class="hidden items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm sm:flex">
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                    Live Data
                </div>
                <a href="?logout=1" class="hidden rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition-soft hover:text-slate-900 sm:inline-flex">
                    Logout
                </a>
                <div class="hidden h-6 w-px bg-slate-200 sm:block"></div>
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-xs font-extrabold text-white shadow-md shadow-indigo-500/20">
                        SM
                    </div>
                    <div class="hidden text-left sm:block">
                        <p class="text-sm font-bold text-slate-900">Sajan M.</p>
                        <p class="text-xs font-medium text-slate-400">DigiChefs Team</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="space-y-6 overflow-x-hidden px-5 py-6 lg:px-7" id="analytics">
            <section class="grid gap-6 xl:grid-cols-4">
                <div class="grid gap-6 md:grid-cols-2 xl:col-span-2">
                    <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-extrabold uppercase tracking-[0.2em] text-slate-400">Requests</span>
                            <div class="flex h-12 w-12 items-center justify-center rounded-3xl bg-indigo-50 text-indigo-500">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                            </div>
                        </div>
                        <div class="mt-6">
                            <p class="heading-font text-[2rem] font-bold tracking-tight text-slate-900 xl:text-[2.35rem]"><?= compact_number($summary['total_requests']) ?></p>
                            <div class="mt-3 flex items-center gap-2 text-sm font-bold text-emerald-500">
                                <span>↑ Live Log</span>
                                <span class="text-slate-400">total requests</span>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-extrabold uppercase tracking-[0.2em] text-slate-400">Tokens</span>
                            <div class="flex h-12 w-12 items-center justify-center rounded-3xl bg-violet-50 text-violet-500">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h10M7 12h10M7 17h10M5 4h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z" />
                                </svg>
                            </div>
                        </div>
                        <div class="mt-6">
                            <p class="heading-font text-[2rem] font-bold tracking-tight text-slate-900 xl:text-[2.35rem]"><?= compact_number($summary['total_tokens']) ?></p>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm font-bold">
                                <span class="rounded-lg bg-indigo-50 px-2 py-1 text-indigo-500">Avg: <?= number_format($avgTokens, 0) ?></span>
                                <span class="text-slate-400">tokens/request</span>
                            </div>
                        </div>
                    </article>

                    <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-extrabold uppercase tracking-[0.2em] text-slate-400">Images</span>
                            <div class="flex h-12 w-12 items-center justify-center rounded-3xl bg-amber-50 text-amber-500">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2 1.586-1.586a2 2 0 012.828 0L20 14m-6-10h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                        </div>
                        <div class="mt-6">
                            <p class="heading-font text-[2rem] font-bold tracking-tight text-slate-900 xl:text-[2.35rem]"><?= compact_number($summary['total_images']) ?></p>
                            <div class="mt-3 text-sm font-bold text-slate-400">generated media items</div>
                        </div>
                    </article>

                    <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-extrabold uppercase tracking-[0.2em] text-slate-400">Estimated Cost</span>
                            <div class="flex h-12 w-12 items-center justify-center rounded-3xl bg-emerald-50 text-emerald-500">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V6m0 12v-2m0 0c-1.657 0-3-.895-3-2m6 0c0 1.105-1.343 2-3 2" />
                                </svg>
                            </div>
                        </div>
                        <div class="mt-6">
                            <p class="heading-font text-[2rem] font-bold tracking-tight text-slate-900 xl:text-[2.2rem]"><?= inr($summary['total_cost'], $usdToInrRate) ?></p>
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm font-bold">
                                <span class="rounded-lg bg-emerald-50 px-2 py-1 text-emerald-600">Avg: <?= inr($avgCost, $usdToInrRate) ?></span>
                                <span class="text-slate-400">per request in INR</span>
                            </div>
                        </div>
                    </article>
                </div>

                <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium" id="systems">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="heading-font text-[1.15rem] font-bold text-slate-900 xl:text-[1.3rem]">System Spend Share</h3>
                            <p class="mt-2 max-w-[220px] text-sm leading-6 text-slate-400">Spending share per custom AI integration</p>
                        </div>
                        <span class="rounded-xl bg-indigo-50 px-3 py-1 text-sm font-bold text-indigo-500">COST SPLIT</span>
                    </div>
                    <div class="mt-6 flex items-center justify-center">
                        <div class="h-[190px] w-[190px] xl:h-[220px] xl:w-[220px]">
                            <canvas id="chart-tool-share"></canvas>
                        </div>
                    </div>
                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <div class="flex flex-wrap gap-5 text-sm font-bold text-slate-500">
                            <?php foreach ($toolLegend as $legendItem): ?>
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full" style="background-color: <?= h($legendItem['color']) ?>"></span>
                                    <span><?= h($legendItem['name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </article>

                <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="heading-font text-[1.15rem] font-bold text-slate-900 xl:text-[1.3rem]">Token Volume Split</h3>
                            <p class="mt-2 max-w-[220px] text-sm leading-6 text-slate-400">Proportion of input vs output tokens</p>
                        </div>
                        <span class="rounded-xl bg-violet-50 px-3 py-1 text-sm font-bold text-violet-500">VOLUME</span>
                    </div>
                    <div class="mt-6 flex items-center justify-center">
                        <div class="h-[190px] w-[190px] xl:h-[220px] xl:w-[220px]">
                            <canvas id="chart-token-share"></canvas>
                        </div>
                    </div>
                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <div class="flex flex-wrap gap-8 text-sm font-bold text-slate-500">
                            <div class="flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-indigo-500"></span><span>Input</span></div>
                            <div class="flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-cyan-500"></span><span>Output</span></div>
                        </div>
                    </div>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-[1.45fr_0.45fr]" id="usage-dynamics">
                <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="heading-font text-[2rem] font-bold text-slate-900">Usage & Spending Dynamics</h3>
                            <p class="mt-2 text-[1.1rem] text-slate-400">Daily spending (INR) and request volume plotted chronologically</p>
                        </div>
                        <div class="hidden rounded-full border border-slate-200 px-4 py-2 text-base font-semibold text-slate-500 sm:block">
                            Calendar
                        </div>
                    </div>
                    <div class="mt-5 h-[300px] xl:h-[320px]">
                        <canvas id="chart-dynamics"></canvas>
                    </div>
                </article>

                <div class="space-y-6">
                    <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-extrabold uppercase tracking-[0.2em] text-slate-400">Input Spend</p>
                                <p class="mt-3 heading-font text-[1.8rem] font-bold text-slate-900"><?= inr($summary['input_cost'], $usdToInrRate) ?></p>
                                <p class="mt-1 text-sm text-slate-400">Input token expense</p>
                            </div>
                            <div class="flex h-14 w-14 items-center justify-center rounded-full border-4 border-indigo-100 text-sm font-extrabold text-indigo-500">
                                <?= h($inputShare) ?>%
                            </div>
                        </div>
                    </article>

                    <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-extrabold uppercase tracking-[0.2em] text-slate-400">Output Spend</p>
                                <p class="mt-3 heading-font text-[1.8rem] font-bold text-slate-900"><?= inr($summary['output_cost'], $usdToInrRate) ?></p>
                                <p class="mt-1 text-sm text-slate-400">Output + media expense</p>
                            </div>
                            <div class="flex h-14 w-14 items-center justify-center rounded-full border-4 border-emerald-100 text-sm font-extrabold text-emerald-500">
                                <?= h($outputShare) ?>%
                            </div>
                        </div>
                    </article>

                    <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium" id="filters">
                        <p class="text-xs font-extrabold uppercase tracking-[0.2em] text-slate-400">Filter Panel</p>
                        <form method="get" class="mt-5 space-y-4">
                            <label class="block text-sm font-bold text-slate-500">
                                From
                                <input type="date" name="from" value="<?= h($from) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-700 outline-none focus:border-indigo-300 focus:bg-white">
                            </label>
                            <label class="block text-sm font-bold text-slate-500">
                                To
                                <input type="date" name="to" value="<?= h($to) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-700 outline-none focus:border-indigo-300 focus:bg-white">
                            </label>
                            <input type="hidden" name="tool_id" value="<?= h($selectedToolId) ?>">
                            <button type="submit" class="w-full rounded-2xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition-soft hover:bg-indigo-700">
                                Apply Filter
                            </button>
                            <label class="block text-sm font-bold text-slate-500">
                                USD to INR
                                <input type="number" step="0.01" min="0" name="usd_inr" value="<?= h($usdToInrRate) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 outline-none focus:border-indigo-300 focus:bg-white">
                            </label>
                            <p class="text-sm text-slate-400">Current range: <?= h($rangeLabel) ?> • Rate: <?= h(number_format($usdToInrRate, 2)) ?></p>
                        </form>
                    </article>
                </div>
            </section>

            <section class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium" id="tool-selection">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-5">
                    <div>
                        <h3 class="heading-font text-[1.55rem] font-bold text-slate-900">Tool Selection</h3>
                        <p class="mt-1 text-sm text-slate-400">Select one system tab to filter all charts, tables, and logs.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500"><?= $selectedToolName !== '' ? h($selectedToolName) : 'All Systems' ?></span>
                </div>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&usd_inr=<?= urlencode($usdToInrRate) ?>" class="rounded-2xl px-5 py-3 text-sm font-bold transition-soft <?= $selectedToolId === 0 ? 'bg-slate-900 text-white shadow-lg shadow-slate-900/15' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">All Systems</a>
                    <?php foreach ($tools as $toolTab): ?>
                        <a href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&tool_id=<?= urlencode($toolTab['id']) ?>&usd_inr=<?= urlencode($usdToInrRate) ?>" class="rounded-2xl px-5 py-3 text-sm font-bold transition-soft <?= $selectedToolId === (int) $toolTab['id'] ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/20' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                            <?= h($toolTab['tool_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]" id="data-tables">
                <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-5">
                        <div>
                            <h3 class="heading-font text-[1.55rem] font-bold text-slate-900">System Breakdown Table</h3>
                            <p class="mt-1 text-sm text-slate-400">Each tool with mapped users, total uses, tokens, images, and spend</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500"><?= count($systemCards) ?> active</span>
                    </div>
                    <div class="nice-scroll overflow-x-auto">
                        <table class="mt-4 w-full min-w-[760px] text-left text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 text-xs uppercase tracking-[0.18em] text-slate-400">
                                    <th class="py-3 font-bold">System</th>
                                    <th class="py-3 text-right font-bold">Users</th>
                                    <th class="py-3 text-right font-bold">Uses</th>
                                    <th class="py-3 text-right font-bold">Tokens</th>
                                    <th class="py-3 text-right font-bold">Images</th>
                                    <th class="py-3 text-right font-bold">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($systemCards as $index => $row): ?>
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="py-4">
                                            <div class="flex items-center gap-3">
                                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl text-xs font-extrabold text-white" style="background-color: <?= h($toolColors[$index % count($toolColors)]) ?>">
                                                    <?= h(substr($row['tool_name'], 0, 2)) ?>
                                                </span>
                                                <div>
                                                    <div class="font-bold text-slate-900"><?= h($row['tool_name']) ?></div>
                                                    <div class="text-xs text-slate-400">ID <?= h($row['id']) ?> • <?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-4 text-right font-semibold text-slate-700"><?= number_format((int) $row['user_count']) ?></td>
                                        <td class="py-4 text-right font-semibold text-slate-700"><?= number_format((int) $row['request_count']) ?></td>
                                        <td class="py-4 text-right font-semibold text-slate-700"><?= compact_number($row['total_tokens']) ?></td>
                                        <td class="py-4 text-right font-semibold text-slate-700"><?= number_format((int) $row['total_images']) ?></td>
                                        <td class="py-4 text-right font-extrabold text-indigo-600"><?= inr($row['total_cost'], $usdToInrRate) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                    <div class="border-b border-slate-100 pb-5">
                        <h3 class="heading-font text-[1.55rem] font-bold text-slate-900">Coverage Snapshot</h3>
                        <p class="mt-1 text-sm text-slate-400">What this master page includes at a glance</p>
                    </div>
                    <div class="mt-5 space-y-4">
                        <div class="rounded-3xl bg-slate-50 p-5">
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-400">Total Users</p>
                            <p class="mt-2 heading-font text-[1.9rem] font-bold text-slate-900"><?= number_format((int) $userSummary['total_users']) ?></p>
                            <p class="mt-2 text-sm text-slate-400">Every login from <code>user_accounts</code>.</p>
                        </div>
                        <div class="rounded-3xl bg-slate-50 p-5">
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-400">Admins / Users</p>
                            <p class="mt-2 heading-font text-[1.9rem] font-bold text-slate-900"><?= number_format((int) $userSummary['total_admins']) ?> / <?= number_format((int) $userSummary['total_standard_users']) ?></p>
                            <p class="mt-2 text-sm text-slate-400">Role split from the current login table.</p>
                        </div>
                        <div class="rounded-3xl bg-slate-50 p-5">
                            <p class="text-xs font-extrabold uppercase tracking-[0.18em] text-slate-400">Recent Logs Loaded</p>
                            <p class="mt-2 heading-font text-[1.9rem] font-bold text-slate-900"><?= number_format(count($logs)) ?></p>
                            <p class="mt-2 text-sm text-slate-400">Last 100 rows from <code>ai_usage_logs</code>.</p>
                        </div>
                    </div>
                </article>
            </section>

            <section class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium" id="user-management">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-5">
                    <div>
                        <h3 class="heading-font text-[1.45rem] font-bold text-slate-900">User Management</h3>
                        <p class="mt-1 text-sm text-slate-400">Add or remove both USER and ADMIN accounts in a separate clean admin section.</p>
                    </div>
                </div>

                <div class="mt-6 grid gap-6 xl:grid-cols-2">
                    <article class="rounded-3xl bg-slate-50 p-6">
                        <h4 class="text-base font-extrabold text-slate-900">Create Account</h4>
                        <div id="create-user-status" class="mt-4 hidden rounded-2xl px-4 py-3 text-sm font-semibold"></div>
                        <form id="create-user-form" action="create_user.php" method="post" class="mt-5 grid gap-4 md:grid-cols-2">
                            <label class="block text-sm font-bold text-slate-500 md:col-span-2">
                                Username
                                <input type="text" name="username" required class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-indigo-300" placeholder="name@digichefs.com">
                            </label>
                            <label class="block text-sm font-bold text-slate-500 md:col-span-2">
                                Password
                                <input type="text" name="password" required class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-indigo-300" placeholder="Enter password">
                            </label>
                            <label class="block text-sm font-bold text-slate-500">
                                Role
                                <select name="role" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-indigo-300">
                                    <option value="USER">USER</option>
                                    <option value="ADMIN">ADMIN</option>
                                </select>
                            </label>
                            <label class="block text-sm font-bold text-slate-500">
                                Tool
                                <select name="tool_id" required class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-indigo-300">
                                    <option value="">Select tool</option>
                                    <?php foreach ($tools as $toolOption): ?>
                                        <option value="<?= h($toolOption['id']) ?>" <?= $selectedToolId === (int) $toolOption['id'] ? 'selected' : '' ?>><?= h($toolOption['tool_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="md:col-span-2">
                                <button type="submit" class="w-full rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition-soft hover:bg-indigo-700">
                                    Create User
                                </button>
                            </div>
                        </form>
                    </article>

                    <article class="rounded-3xl bg-rose-50 p-6">
                        <h4 class="text-base font-extrabold text-slate-900">Delete Account</h4>
                        <div id="delete-user-status" class="mt-4 hidden rounded-2xl px-4 py-3 text-sm font-semibold"></div>
                        <form id="delete-user-form" action="user_management.php" method="post" class="mt-5 space-y-4">
                            <input type="hidden" name="action" value="delete">
                            <label class="block text-sm font-bold text-slate-500">
                                Username
                                <input type="text" name="username" required class="mt-2 w-full rounded-2xl border border-rose-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-rose-300" placeholder="Exact username">
                            </label>
                            <button type="submit" class="w-full rounded-2xl bg-rose-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-rose-600/20 transition-soft hover:bg-rose-700">
                                Delete User
                            </button>
                        </form>
                        <p class="mt-3 text-xs leading-6 text-rose-600">Deletion works for both USER and ADMIN roles. Use the exact username.</p>
                    </article>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-1" id="directory">
                <article class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-5">
                        <div>
                            <h3 class="heading-font text-[1.3rem] font-bold text-slate-900">User Directory</h3>
                            <p class="mt-1 text-sm text-slate-400">Users, credentials, role mapping, and system-level usage totals</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500"><?= count($users) ?> users</span>
                    </div>
                    <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                        Usage is shown from the mapped system totals because logs are stored by <code>tool_name</code>, not by <code>user_id</code>.
                    </div>
                    <div class="nice-scroll overflow-x-auto">
                        <table class="mt-4 w-full min-w-[980px] text-left text-[12px]">
                            <thead>
                                <tr class="border-b border-slate-100 text-[11px] uppercase tracking-[0.16em] text-slate-400">
                                    <th class="py-3 font-bold">User</th>
                                    <th class="py-3 font-bold">Password</th>
                                    <th class="py-3 font-bold">Role</th>
                                    <th class="py-3 font-bold">System</th>
                                    <th class="py-3 text-right font-bold">Uses</th>
                                    <th class="py-3 text-right font-bold">Tokens</th>
                                    <th class="py-3 text-right font-bold">Spend</th>
                                    <th class="py-3 font-bold">Last Seen</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($users as $user): ?>
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="py-3.5">
                                        <div class="text-[13px] font-bold text-slate-900"><?= h($user['username']) ?></div>
                                            <div class="text-[11px] text-slate-400">ID #<?= h($user['id']) ?></div>
                                        </td>
                                        <td class="py-3.5 min-w-[170px]">
                                            <div class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Stored Password</div>
                                            <code class="mt-2 inline-block break-all rounded-xl bg-slate-100 px-3 py-2 text-[11px] font-bold text-slate-700"><?= h($user['password']) ?></code>
                                        </td>
                                        <td class="py-3.5">
                                            <span class="rounded-full bg-slate-900 px-2.5 py-1 text-[10px] font-bold text-white"><?= h($user['role']) ?></span>
                                        </td>
                                        <td class="py-3.5">
                                            <div class="text-[13px] font-bold text-slate-900"><?= h($user['tool_name'] ?: 'Unassigned') ?></div>
                                            <div class="text-[11px] text-slate-400">tool_id: <?= h($user['tool_id'] ?: '-') ?></div>
                                        </td>
                                        <td class="py-3.5 text-right font-semibold text-slate-700"><?= number_format((int) $user['system_request_count']) ?></td>
                                        <td class="py-3.5 text-right font-semibold text-slate-700"><?= compact_number($user['system_total_tokens']) ?></td>
                                        <td class="py-3.5 text-right font-extrabold text-indigo-600"><?= inr($user['system_total_cost'], $usdToInrRate) ?></td>
                                        <td class="py-3.5 text-[12px] text-slate-500"><?= h($user['last_activity'] ?: 'No activity') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="rounded-[28px] border border-slate-200/60 bg-white p-5 shadow-premium">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-5">
                    <div>
                        <h3 class="heading-font text-[1.35rem] font-bold text-slate-900">Latest Usage Logs</h3>
                        <p class="mt-1 text-sm text-slate-400">Recent log activity for the selected date range</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500"><?= count($logs) ?> logs</span>
                </div>
                <div class="nice-scroll overflow-x-auto max-h-[420px] overflow-y-auto">
                    <table class="mt-4 w-full min-w-[1080px] text-left text-[13px]">
                        <thead class="sticky top-0 bg-white">
                            <tr class="border-b border-slate-100 text-xs uppercase tracking-[0.18em] text-slate-400">
                                <th class="py-3 font-bold">ID</th>
                                <th class="py-3 font-bold">System</th>
                                <th class="py-3 font-bold">Model</th>
                                <th class="py-3 text-right font-bold">Tokens</th>
                                <th class="py-3 text-right font-bold">Images</th>
                                <th class="py-3 text-right font-bold">Input Cost</th>
                                <th class="py-3 text-right font-bold">Output Cost</th>
                                <th class="py-3 text-right font-bold">Total Cost</th>
                                <th class="py-3 font-bold">Status</th>
                                <th class="py-3 font-bold">Notes</th>
                                <th class="py-3 font-bold">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="11" class="py-8 text-center text-sm font-semibold text-slate-400">No logs found for the selected range.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-slate-50/60">
                                    <td class="py-4 font-semibold text-slate-400">#<?= h($log['id']) ?></td>
                                    <td class="py-4 font-bold text-slate-900"><?= h($log['tool_name']) ?></td>
                                    <td class="py-4 text-slate-500"><?= h($log['model_name']) ?></td>
                                    <td class="py-4 text-right font-semibold text-slate-700"><?= number_format((int) $log['total_tokens']) ?></td>
                                    <td class="py-4 text-right font-semibold text-slate-700"><?= number_format((int) $log['image_count']) ?></td>
                                    <td class="py-4 text-right font-semibold text-slate-700"><?= inr($log['input_cost'], $usdToInrRate) ?></td>
                                    <td class="py-4 text-right font-semibold text-slate-700"><?= inr($log['output_cost'], $usdToInrRate) ?></td>
                                    <td class="py-4 text-right font-extrabold text-indigo-600"><?= inr($log['total_cost'], $usdToInrRate) ?></td>
                                    <td class="py-4">
                                        <?php $statusClass = $log['status'] === 'success' ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-rose-50 text-rose-600 border border-rose-100'; ?>
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-extrabold <?= $statusClass ?>"><?= h($log['status']) ?></span>
                                    </td>
                                    <td class="py-4 text-slate-500"><?= h($log['notes']) ?></td>
                                    <td class="py-4 text-slate-500"><?= h($log['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('sidebar-container');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');

            if (sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
            } else {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            }
        }

        const toolLabels = <?= json_encode($toolLabels) ?>;
        const toolCosts = <?= json_encode($toolCosts) ?>;
        const toolColors = <?= json_encode(array_slice($toolColors, 0, max(count($toolLabels), 1))) ?>;

        new Chart(document.getElementById('chart-tool-share'), {
            type: 'doughnut',
            data: {
                labels: toolLabels,
                datasets: [{
                    data: toolCosts.length ? toolCosts : [1],
                    backgroundColor: toolCosts.length ? toolColors : ['#e2e8f0'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ' ' + context.label + ': $' + Number(context.raw).toFixed(6);
                            }
                        }
                    }
                }
            }
        });

        new Chart(document.getElementById('chart-token-share'), {
            type: 'doughnut',
            data: {
                labels: ['Input', 'Output'],
                datasets: [{
                    data: [<?= (float) $summary['input_tokens'] ?>, <?= (float) $summary['output_tokens'] ?>],
                    backgroundColor: ['#6366f1', '#06b6d4'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { display: false }
                }
            }
        });

        new Chart(document.getElementById('chart-dynamics'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($dailyLabels) ?>,
                datasets: [
                    {
                        label: 'Estimated Spend',
                        type: 'line',
                        data: <?= json_encode($dailyCostData) ?>,
                        borderColor: '#6366f1',
                        borderWidth: 3,
                        pointBackgroundColor: '#6366f1',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 6,
                        tension: 0.35,
                        fill: false,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Requests',
                        type: 'bar',
                        data: <?= json_encode($dailyRequestData) ?>,
                        backgroundColor: 'rgba(226, 232, 240, 0.9)',
                        hoverBackgroundColor: 'rgba(99, 102, 241, 0.18)',
                        borderRadius: 10,
                        barThickness: 16,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                if (context.datasetIndex === 0) {
                                    return ' Spend: $' + Number(context.raw).toFixed(6);
                                }
                                return ' Requests: ' + context.raw;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 11, weight: 'bold' },
                            color: '#94a3b8'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: {
                            color: '#eef2f7',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 10, weight: 'bold' },
                            color: '#94a3b8',
                            callback: function(value) {
                                return '$' + Number(value).toFixed(3);
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: {
                            font: { size: 10, weight: 'bold' },
                            color: '#94a3b8',
                            stepSize: 1
                        }
                    }
                }
            }
        });

        function setFormStatus(elementId, type, message) {
            const statusBox = document.getElementById(elementId);
            if (!statusBox) {
                return;
            }

            statusBox.classList.remove(
                'hidden',
                'bg-emerald-50',
                'text-emerald-700',
                'border',
                'border-emerald-200',
                'bg-rose-50',
                'text-rose-700',
                'border-rose-200'
            );

            if (type === 'success') {
                statusBox.classList.add('bg-emerald-50', 'text-emerald-700', 'border', 'border-emerald-200');
            } else {
                statusBox.classList.add('bg-rose-50', 'text-rose-700', 'border', 'border-rose-200');
            }

            statusBox.textContent = message;
        }

        async function submitDashboardForm(formId, statusId) {
            const form = document.getElementById(formId);
            if (!form) {
                return;
            }

            form.addEventListener('submit', async function(event) {
                event.preventDefault();

                const submitButton = form.querySelector('button[type="submit"]');
                const originalButtonText = submitButton ? submitButton.innerHTML : '';

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.classList.add('opacity-70', 'cursor-not-allowed');
                    submitButton.innerHTML = 'Processing...';
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form)
                    });

                    const result = await response.json();

                    if (result.status) {
                        setFormStatus(statusId, 'success', result.message || 'Action completed successfully.');
                        form.reset();
                        window.setTimeout(() => {
                            window.location.reload();
                        }, 900);
                    } else {
                        setFormStatus(statusId, 'error', result.message || 'Something went wrong.');
                    }
                } catch (error) {
                    setFormStatus(statusId, 'error', 'Request failed. Please try again.');
                }

                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
                    submitButton.innerHTML = originalButtonText;
                }
            });
        }

        submitDashboardForm('create-user-form', 'create-user-status');
        submitDashboardForm('delete-user-form', 'delete-user-status');
    </script>
</body>
</html>
