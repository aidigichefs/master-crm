<?php
/**
 * Professional Light Theme AI Usage Dashboard
 * Upload/replace this file at:
 * public_html/ai-usage-api/dashboard.php
 *
 * Required existing files in same folder:
 * - config.php
 * - db.php
 * - send-today-report.php
 */

require_once __DIR__ . '/db.php';

/**
 * db.php/config.php may set JSON header for API files.
 * Dashboard is HTML, so we override it here.
 */
header_remove('Content-Type');
header('Content-Type: text/html; charset=UTF-8');

date_default_timezone_set('Asia/Kolkata');

$allowedTools = ['ABFRL', 'SocialMill', 'Sizewise'];

$tool = trim($_GET['tool'] ?? '');
$from = trim($_GET['from'] ?? date('Y-m-d'));
$to = trim($_GET['to'] ?? date('Y-m-d'));

if (!in_array($tool, $allowedTools, true)) {
    $tool = '';
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return '$' . number_format((float)$value, 6);
}

function compact_number($value) {
    $value = (float)$value;

    if ($value >= 1000000) {
        return number_format($value / 1000000, 2) . 'M';
    }

    if ($value >= 1000) {
        return number_format($value / 1000, 1) . 'K';
    }

    return number_format($value);
}

function safe_percent($value, $max) {
    if ((float)$max <= 0) {
        return 0;
    }

    return max(3, min(100, round(((float)$value / (float)$max) * 100)));
}

$where = "WHERE DATE(created_at) BETWEEN :from_date AND :to_date";
$params = [
    ':from_date' => $from,
    ':to_date' => $to
];

if ($tool !== '') {
    $where .= " AND tool_name = :tool_name";
    $params[':tool_name'] = $tool;
}

/**
 * Overall summary
 */
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_requests,
        COALESCE(SUM(input_tokens), 0) AS input_tokens,
        COALESCE(SUM(output_tokens), 0) AS output_tokens,
        COALESCE(SUM(total_tokens), 0) AS total_tokens,
        COALESCE(SUM(image_count), 0) AS images,
        COALESCE(SUM(input_cost), 0) AS input_cost,
        COALESCE(SUM(output_cost), 0) AS output_cost,
        COALESCE(SUM(total_cost), 0) AS total_cost
    FROM ai_usage_logs
    $where
");
$stmt->execute($params);
$summary = $stmt->fetch();

/**
 * Tool-wise summary
 */
$stmt = $pdo->prepare("
    SELECT
        tool_name,
        COUNT(*) AS requests,
        COALESCE(SUM(input_tokens), 0) AS input_tokens,
        COALESCE(SUM(output_tokens), 0) AS output_tokens,
        COALESCE(SUM(total_tokens), 0) AS total_tokens,
        COALESCE(SUM(image_count), 0) AS images,
        COALESCE(SUM(input_cost), 0) AS input_cost,
        COALESCE(SUM(output_cost), 0) AS output_cost,
        COALESCE(SUM(total_cost), 0) AS cost
    FROM ai_usage_logs
    $where
    GROUP BY tool_name
    ORDER BY cost DESC
");
$stmt->execute($params);
$toolRowsRaw = $stmt->fetchAll();

$toolMap = [];
foreach ($allowedTools as $toolName) {
    $toolMap[$toolName] = [
        'tool_name' => $toolName,
        'requests' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
        'images' => 0,
        'input_cost' => 0,
        'output_cost' => 0,
        'cost' => 0
    ];
}

foreach ($toolRowsRaw as $row) {
    if (isset($toolMap[$row['tool_name']])) {
        $toolMap[$row['tool_name']] = $row;
    }
}

$toolRows = array_values($toolMap);
usort($toolRows, function ($a, $b) {
    return (float)$b['cost'] <=> (float)$a['cost'];
});

$maxToolCost = 0;
$topTool = null;
foreach ($toolRows as $row) {
    if ((float)$row['cost'] > $maxToolCost) {
        $maxToolCost = (float)$row['cost'];
        $topTool = $row;
    }
}

/**
 * Daily usage
 */
$stmt = $pdo->prepare("
    SELECT
        DATE(created_at) AS usage_date,
        COUNT(*) AS requests,
        COALESCE(SUM(total_tokens), 0) AS total_tokens,
        COALESCE(SUM(image_count), 0) AS images,
        COALESCE(SUM(total_cost), 0) AS cost
    FROM ai_usage_logs
    $where
    GROUP BY DATE(created_at)
    ORDER BY usage_date ASC
");
$stmt->execute($params);
$dailyRows = $stmt->fetchAll();

$maxDailyCost = 0;
foreach ($dailyRows as $row) {
    $maxDailyCost = max($maxDailyCost, (float)$row['cost']);
}

/**
 * Latest logs
 */
$stmt = $pdo->prepare("
    SELECT
        id,
        tool_name,
        model_name,
        input_tokens,
        output_tokens,
        total_tokens,
        image_count,
        total_cost,
        status,
        notes,
        created_at
    FROM ai_usage_logs
    $where
    ORDER BY id DESC
    LIMIT 50
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

/**
 * Pricing
 */
$stmt = $pdo->query("
    SELECT
        model_name,
        input_price_per_1m,
        image_output_price_per_1m,
        currency,
        updated_at
    FROM ai_model_price
    WHERE model_name = 'gemini-3-pro-image-preview'
    LIMIT 1
");
$price = $stmt->fetch();

$defaultToEmails = "sajan.m@digichefs.com, sajanmajrekar14@gmail.com";

$today = date('Y-m-d');
$thisMonthStart = date('Y-m-01');
$last7Start = date('Y-m-d', strtotime('-6 days'));
$yesterday = date('Y-m-d', strtotime('-1 day'));

$rangeLabel = $from === $to ? $from : ($from . ' to ' . $to);
$avgCost = (int)$summary['total_requests'] > 0 ? ((float)$summary['total_cost'] / (int)$summary['total_requests']) : 0;
$avgTokens = (int)$summary['total_requests'] > 0 ? ((float)$summary['total_tokens'] / (int)$summary['total_requests']) : 0;
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#f4f5f7]">
<head>
    <meta charset="UTF-8">
    <title>Analytics Dashboard | DigiChefs AI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Google Fonts: Outfit for titles, Plus Jakarta Sans for body -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        outfit: ['"Outfit"', 'sans-serif'],
                    },
                    boxShadow: {
                        premium: '0 8px 30px rgb(0 0 0 / 0.02)',
                        glow: '0 0 20px rgba(99, 102, 241, 0.15)',
                        card: '0 10px 40px -10px rgba(0,0,0,0.03)'
                    },
                    colors: {
                        brand: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            950: '#09090b',
                        }
                    }
                }
            }
        }
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

        /* Custom Scrollbars */
        .nice-scroll::-webkit-scrollbar {
            height: 6px;
            width: 6px;
        }

        .nice-scroll::-webkit-scrollbar-thumb {
            background: #e2e8f0;
            border-radius: 999px;
        }

        .nice-scroll::-webkit-scrollbar-thumb:hover {
            background: #cbd5e1;
        }

        .nice-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        /* Transition effects */
        .transition-soft {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Sidebar active link styling */
        .nav-active {
            background-color: #f1f5f9;
            color: #0f172a;
            font-weight: 600;
        }
    </style>
</head>

<body class="h-full text-slate-800 antialiased">

    <!-- Mobile Drawer Overlay -->
    <div id="mobile-sidebar-backdrop" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm hidden transition-opacity lg:hidden" onclick="toggleMobileSidebar()"></div>

    <!-- Left Sidebar -->
    <aside id="sidebar-container" class="fixed inset-y-0 left-0 z-50 flex w-[260px] flex-col border-r border-slate-200/60 bg-white px-5 py-6 transition-transform duration-300 -translate-x-full lg:translate-x-0">
        <!-- Brand / Logo -->
        <div class="flex items-center gap-3 px-2 mb-8">
            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 shadow-md shadow-indigo-500/10">
                <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
            </div>
            <div>
                <span class="heading-font text-lg font-bold tracking-tight text-slate-900">DigiChefs</span>
                <span class="ml-1 heading-font text-xs font-semibold bg-brand-50 text-indigo-600 px-1.5 py-0.5 rounded-md">AI</span>
            </div>
        </div>

        <!-- Navigation Menu -->
        <nav class="flex-1 space-y-1.5 px-1">
            <a href="#overview" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-500 hover:bg-slate-50 hover:text-slate-900 transition-soft nav-active">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2v-4z" />
                </svg>
                <span>Analytics</span>
            </a>

            <a href="#tool-split" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-500 hover:bg-slate-50 hover:text-slate-900 transition-soft">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.003 9.003 0 1020.945 13H11V3.055z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                </svg>
                <span>Tool Split</span>
            </a>

            <a href="#dynamics" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-500 hover:bg-slate-50 hover:text-slate-900 transition-soft">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
                <span>Daily Dynamics</span>
            </a>

            <a href="#log-tables" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-500 hover:bg-slate-50 hover:text-slate-900 transition-soft">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                </svg>
                <span>Logs & Tables</span>
            </a>

            <div class="h-px bg-slate-100 my-4"></div>

            <a href="#filters" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-500 hover:bg-slate-50 hover:text-slate-900 transition-soft">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                <span>Filter Panel</span>
            </a>

            <a href="#email-reports" class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium text-slate-500 hover:bg-slate-50 hover:text-slate-900 transition-soft">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                <span>Email Reports</span>
            </a>
        </nav>

        <!-- Help Widget -->
        <div class="mt-auto rounded-2xl bg-indigo-50/60 p-4 border border-indigo-100/40 relative overflow-hidden">
            <div class="absolute -right-6 -bottom-6 h-20 w-20 rounded-full bg-indigo-200/20 blur-lg"></div>
            <p class="text-xs font-semibold text-indigo-900">Need assistance?</p>
            <p class="text-[11px] text-indigo-600/80 mt-1 leading-normal">Feel free to contact the administrator.</p>
            <a href="mailto:sajan.m@digichefs.com" class="mt-3 block w-full rounded-xl bg-indigo-600 px-3 py-2 text-center text-xs font-bold text-white shadow-sm shadow-indigo-600/10 hover:bg-indigo-700 transition-soft">
                Get support
            </a>
        </div>
    </aside>

    <!-- Main Workspace Container -->
    <div class="lg:pl-[260px] flex flex-col min-h-screen">

        <!-- Top Header Navigation Bar -->
        <header class="sticky top-0 z-30 flex h-[70px] items-center justify-between border-b border-slate-200/50 bg-[#f4f5f7]/80 backdrop-blur-md px-6 lg:px-8">
            <div class="flex items-center gap-4">
                <!-- Mobile burger menu trigger -->
                <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 hover:text-slate-900 lg:hidden shadow-sm transition-soft" onclick="toggleMobileSidebar()">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <div>
                    <h1 class="heading-font text-xl font-bold tracking-tight text-slate-900">Analytics</h1>
                </div>

                <!-- Date Range Pill -->
                <div class="hidden sm:flex items-center gap-2 rounded-full border border-slate-200 bg-white/70 backdrop-blur-sm px-3.5 py-1 text-xs font-medium text-slate-600 shadow-sm ml-2">
                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span><?= h($rangeLabel) ?><?= $tool ? ' (' . h($tool) . ')' : '' ?></span>
                </div>
            </div>

            <!-- Profile and Status Area -->
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-1.5 rounded-full border border-slate-200 bg-white/70 px-3 py-1 text-[11px] font-semibold text-slate-600 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    Live Data
                </div>

                <div class="h-4 w-px bg-slate-200"></div>

                <!-- User Profile -->
                <div class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 font-bold text-xs text-white shadow-md shadow-indigo-500/10">
                        SM
                    </div>
                    <div class="hidden md:block text-left">
                        <p class="text-xs font-bold text-slate-800">Sajan M.</p>
                        <p class="text-[10px] font-medium text-slate-400">DigiChefs Team</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Body Content -->
        <main class="flex-1 px-6 py-6 lg:px-8 space-y-6">

            <!-- Dynamic Background Glow Elements (Decorative, SaaS theme) -->
            <div class="absolute top-1/4 left-1/3 -z-10 h-72 w-72 rounded-full bg-indigo-300/10 blur-3xl"></div>
            <div class="absolute top-1/2 right-1/4 -z-10 h-96 w-96 rounded-full bg-violet-300/10 blur-3xl"></div>

            <!-- Row 1: KPI Stats Grid & Doughnut Chart Columns -->
            <section class="grid gap-5 lg:grid-cols-4" id="overview">
                <!-- Left side KPIs (Takes 2 Columns out of 4) -->
                <div class="grid gap-5 sm:grid-cols-2 lg:col-span-2">
                    
                    <!-- Requests Card -->
                    <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium transition-soft hover:-translate-y-1 hover:shadow-card">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Requests</span>
                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-500">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h3 class="heading-font text-3xl font-bold tracking-tight text-slate-900"><?= compact_number($summary['total_requests']) ?></h3>
                            <div class="mt-2.5 flex items-center gap-1 text-[11px] font-bold text-slate-400">
                                <span class="text-emerald-500 flex items-center">
                                    <svg class="h-3 w-3 inline mr-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                                    Live Log
                                </span>
                                <span>total requests</span>
                            </div>
                        </div>
                    </div>

                    <!-- Tokens Card -->
                    <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium transition-soft hover:-translate-y-1 hover:shadow-card">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Tokens</span>
                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-violet-50 text-violet-500">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.58 4 8 4s8-1.79 8-4M4 7c0-2.21 3.58-4 8-4s8 1.79 8 4m0 5c0 2.21-3.58 4-8 4s-8-1.79-8-4" />
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h3 class="heading-font text-3xl font-bold tracking-tight text-slate-900"><?= compact_number($summary['total_tokens']) ?></h3>
                            <div class="mt-2.5 flex items-center gap-1 text-[11px] font-bold text-slate-400">
                                <span class="text-indigo-600 bg-indigo-50 px-1 rounded">Avg: <?= number_format($avgTokens, 0) ?></span>
                                <span>tokens/request</span>
                            </div>
                        </div>
                    </div>

                    <!-- Images Card -->
                    <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium transition-soft hover:-translate-y-1 hover:shadow-card">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Images</span>
                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-50 text-amber-500">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h3 class="heading-font text-3xl font-bold tracking-tight text-slate-900"><?= compact_number($summary['images']) ?></h3>
                            <div class="mt-2.5 flex items-center gap-1 text-[11px] font-bold text-slate-400">
                                <span>generated media items</span>
                            </div>
                        </div>
                    </div>

                    <!-- Estimated Cost Card -->
                    <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium transition-soft hover:-translate-y-1 hover:shadow-card">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Estimated Cost</span>
                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-500">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M12 16V5" />
                                </svg>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h3 class="heading-font text-3xl font-bold tracking-tight text-slate-900"><?= money($summary['total_cost']) ?></h3>
                            <div class="mt-2.5 flex items-center gap-1 text-[11px] font-bold text-slate-400">
                                <span class="text-emerald-600 bg-emerald-50 px-1 rounded">Avg: <?= money($avgCost) ?></span>
                                <span>per request</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Side: Two beautiful Doughnut Cards (Takes 2 Columns) -->
                <!-- Tool Cost Share Card -->
                <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium lg:col-span-1 flex flex-col justify-between" id="tool-split">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="heading-font text-sm font-bold text-slate-800">Tool Spend Share</h3>
                            <span class="text-[10px] font-bold uppercase text-indigo-500 bg-indigo-50 px-1.5 py-0.5 rounded">Cost Split</span>
                        </div>
                        <p class="text-xs text-slate-400">Spending share per custom AI integration</p>
                    </div>

                    <!-- Doughnut Canvas Container -->
                    <div class="relative my-4 flex justify-center items-center h-[140px]">
                        <?php if ((float)$summary['total_cost'] <= 0): ?>
                            <div class="text-center text-xs font-semibold text-slate-400">No spending data</div>
                        <?php else: ?>
                            <canvas id="chart-tool-share" class="max-w-[130px]"></canvas>
                        <?php endif; ?>
                    </div>

                    <!-- Compact Legend indicators -->
                    <div class="grid grid-cols-3 gap-1.5 text-[10px] font-bold text-slate-500 border-t border-slate-100 pt-3">
                        <div class="flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-indigo-500"></span>
                            <span class="truncate">ABFRL</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                            <span class="truncate">SocialMill</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            <span class="truncate">Sizewise</span>
                        </div>
                    </div>
                </div>

                <!-- Token Distribution Card -->
                <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium lg:col-span-1 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="heading-font text-sm font-bold text-slate-800">Token Volume split</h3>
                            <span class="text-[10px] font-bold uppercase text-violet-500 bg-violet-50 px-1.5 py-0.5 rounded">Volume</span>
                        </div>
                        <p class="text-xs text-slate-400">Proportion of input vs output tokens</p>
                    </div>

                    <!-- Doughnut Canvas Container -->
                    <div class="relative my-4 flex justify-center items-center h-[140px]">
                        <?php if ((int)$summary['total_tokens'] <= 0): ?>
                            <div class="text-center text-xs font-semibold text-slate-400">No token data</div>
                        <?php else: ?>
                            <canvas id="chart-token-share" class="max-w-[130px]"></canvas>
                        <?php endif; ?>
                    </div>

                    <!-- Legend -->
                    <div class="flex justify-around text-[10px] font-bold text-slate-500 border-t border-slate-100 pt-3">
                        <div class="flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-indigo-500"></span>
                            <span>Input</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="h-2 w-2 rounded-full bg-cyan-400"></span>
                            <span>Output</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Row 2: Sales Dynamics Bar/Line Chart & Circular Progress Ring Widgets -->
            <section class="grid gap-5 lg:grid-cols-4" id="dynamics">
                <!-- Daily Cost Dynamics Chart (Takes 3 Columns out of 4) -->
                <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium lg:col-span-3 flex flex-col justify-between">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
                        <div>
                            <h3 class="heading-font text-base font-bold text-slate-900">Usage & Spending dynamics</h3>
                            <p class="text-xs text-slate-400">Daily spending (USD) and request volume plotted chronologically</p>
                        </div>
                        
                        <!-- Mini Year Badge -->
                        <span class="self-start rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-bold text-slate-600 shadow-sm flex items-center gap-1">
                            Calendar
                            <svg class="h-3 w-3 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                        </span>
                    </div>

                    <!-- Chart.js dynamic line/bar container -->
                    <div class="relative w-full h-[220px]">
                        <?php if (!$dailyRows): ?>
                            <div class="absolute inset-0 flex items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 text-sm font-semibold text-slate-400">
                                No dynamics recorded for this filter.
                            </div>
                        <?php else: ?>
                            <canvas id="chart-dynamics"></canvas>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Circular Progress Ring widgets (Takes 1 Column) -->
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-1 lg:col-span-1">
                    
                    <?php
                    $inputPct = $summary['total_cost'] > 0 ? round(($summary['input_cost'] / $summary['total_cost']) * 100) : 0;
                    $outputPct = $summary['total_cost'] > 0 ? round(($summary['output_cost'] / $summary['total_cost']) * 100) : 0;
                    
                    // Fallbacks for circles
                    $inputDash = round(2 * pi() * 18); // Circle radius = 18
                    $inputOffset = $inputDash - ($inputDash * ($inputPct / 100));
                    
                    $outputDash = round(2 * pi() * 18);
                    $outputOffset = $outputDash - ($outputDash * ($outputPct / 100));
                    ?>

                    <!-- Input Spend Widget -->
                    <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium flex flex-col justify-between relative overflow-hidden transition-soft hover:shadow-card">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Input Spend</span>
                                <h4 class="heading-font text-xl font-black text-slate-900 mt-1"><?= money($summary['input_cost']) ?></h4>
                                <p class="text-[10px] text-slate-400 mt-0.5">Input token expense</p>
                            </div>
                            
                            <!-- Custom SVG Circular Progress Ring -->
                            <div class="relative h-11 w-11 flex items-center justify-center">
                                <svg class="h-full w-full -rotate-90">
                                    <circle cx="22" cy="22" r="18" class="stroke-slate-100 fill-none" stroke-width="3" />
                                    <circle cx="22" cy="22" r="18" class="stroke-indigo-500 fill-none" stroke-width="3" 
                                            stroke-dasharray="<?= $inputDash ?>" stroke-dashoffset="<?= $inputOffset ?>" stroke-linecap="round" />
                                </svg>
                                <span class="absolute text-[9px] font-extrabold text-indigo-600"><?= $inputPct ?>%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Output Spend Widget -->
                    <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium flex flex-col justify-between relative overflow-hidden transition-soft hover:shadow-card">
                        <div class="flex items-start justify-between">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Output Spend</span>
                                <h4 class="heading-font text-xl font-black text-slate-900 mt-1"><?= money($summary['output_cost']) ?></h4>
                                <p class="text-[10px] text-slate-400 mt-0.5">Output + Media expense</p>
                            </div>
                            
                            <!-- Custom SVG Circular Progress Ring -->
                            <div class="relative h-11 w-11 flex items-center justify-center">
                                <svg class="h-full w-full -rotate-90">
                                    <circle cx="22" cy="22" r="18" class="stroke-slate-100 fill-none" stroke-width="3" />
                                    <circle cx="22" cy="22" r="18" class="stroke-emerald-500 fill-none" stroke-width="3" 
                                            stroke-dasharray="<?= $outputDash ?>" stroke-dashoffset="<?= $outputOffset ?>" stroke-linecap="round" />
                                </svg>
                                <span class="absolute text-[9px] font-extrabold text-emerald-600"><?= $outputPct ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Row 3: Filter Form Control & Email Sender Control Grid -->
            <section class="grid gap-5 lg:grid-cols-12" id="filters">
                <!-- Filters Controls (Takes 8 Columns) -->
                <form method="GET" class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium lg:col-span-8 flex flex-col justify-between">
                    <div>
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
                            <div>
                                <h3 class="heading-font text-base font-bold text-slate-900">Control & Filter panel</h3>
                                <p class="text-xs text-slate-400">Select specific tools, timeframe boundaries, or quick presets</p>
                            </div>
                            
                            <!-- Preset indicators -->
                            <div class="flex flex-wrap gap-1.5">
                                <?php
                                $todayActive = ($from === $today && $to === $today);
                                $yesterdayActive = ($from === $yesterday && $to === $yesterday);
                                $last7Active = ($from === $last7Start && $to === $today);
                                $thisMonthActive = ($from === $thisMonthStart && $to === $today);
                                ?>
                                <a href="dashboard.php?from=<?= h($today) ?>&to=<?= h($today) ?>" class="rounded-full px-3 py-1 text-[11px] font-bold transition-soft <?= $todayActive ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/10' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">
                                    Today
                                </a>
                                <a href="dashboard.php?from=<?= h($yesterday) ?>&to=<?= h($yesterday) ?>" class="rounded-full px-3 py-1 text-[11px] font-bold transition-soft <?= $yesterdayActive ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/10' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">
                                    Yesterday
                                </a>
                                <a href="dashboard.php?from=<?= h($last7Start) ?>&to=<?= h($today) ?>" class="rounded-full px-3 py-1 text-[11px] font-bold transition-soft <?= $last7Active ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/10' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">
                                    Last 7d
                                </a>
                                <a href="dashboard.php?from=<?= h($thisMonthStart) ?>&to=<?= h($today) ?>" class="rounded-full px-3 py-1 text-[11px] font-bold transition-soft <?= $thisMonthActive ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/10' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">
                                    This Month
                                </a>
                            </div>
                        </div>

                        <!-- Filter inputs grid -->
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-slate-400">Tool Name</label>
                                <div class="relative">
                                    <select name="tool" class="w-full appearance-none rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs font-semibold text-slate-800 outline-none transition-soft focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
                                        <option value="">All Custom Tools</option>
                                        <?php foreach ($allowedTools as $toolOption): ?>
                                            <option value="<?= h($toolOption) ?>" <?= $tool === $toolOption ? 'selected' : '' ?>>
                                                <?= h($toolOption) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-slate-400">From Date</label>
                                <input type="date" name="from" value="<?= h($from) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs font-semibold text-slate-800 outline-none transition-soft focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
                            </div>

                            <div>
                                <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-slate-400">To Date</label>
                                <input type="date" name="to" value="<?= h($to) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs font-semibold text-slate-800 outline-none transition-soft focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100">
                            </div>
                        </div>
                    </div>

                    <!-- Buttons block -->
                    <div class="mt-5 flex items-center justify-end gap-2.5 pt-4 border-t border-slate-100">
                        <a href="dashboard.php" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-500 hover:bg-slate-50 hover:text-slate-700 transition-soft">
                            Reset View
                        </a>
                        <button type="submit" class="rounded-xl bg-slate-900 px-5 py-2.5 text-xs font-bold text-white shadow-md shadow-slate-900/10 hover:bg-indigo-600 hover:shadow-indigo-500/10 transition-soft">
                            Apply Filters
                        </button>
                    </div>
                </form>

                <!-- Email Report (Takes 4 Columns) -->
                <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium lg:col-span-4 flex flex-col justify-between" id="email-reports">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="heading-font text-base font-bold text-slate-900">Email dispatch</h3>
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-extrabold uppercase text-emerald-600 tracking-wider">Mailing</span>
                        </div>
                        <p class="text-xs text-slate-400 mb-4">Send a synthesized, formatted usage report directly to the team.</p>

                        <div>
                            <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-slate-400">To Emails (Comma-separated)</label>
                            <textarea id="toEmails" rows="2" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-xs font-semibold text-slate-800 outline-none transition-soft focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"><?= h($defaultToEmails) ?></textarea>
                        </div>
                    </div>

                    <div class="mt-4 pt-3">
                        <button id="sendMailBtn" type="button" class="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-md shadow-indigo-600/10 hover:bg-indigo-700 transition-soft flex items-center justify-center gap-2">
                            <span>Send Today Report</span>
                        </button>
                        <div id="mailStatus" class="mt-2.5 hidden rounded-xl border px-3.5 py-2 text-[11px] font-bold"></div>
                    </div>
                </div>
            </section>

            <!-- Optional Pricing Section -->
            <section class="grid gap-5 md:grid-cols-3">
                <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium md:col-span-2">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3.5 mb-4">
                        <div>
                            <h3 class="heading-font text-sm font-bold text-slate-800">Model specifications & Pricing monitor</h3>
                            <p class="text-[11px] text-slate-400">Official rate card from MySQL configuration for Gemini</p>
                        </div>
                        <span class="rounded-lg bg-slate-50 border border-slate-200 px-2 py-1 text-[10px] font-bold text-slate-500">
                            <?= $price ? h($price['currency']) : 'USD' ?>
                        </span>
                    </div>

                    <?php if ($price): ?>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-100 bg-slate-50/40 p-4">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Input pricing</span>
                                <p class="heading-font text-lg font-black text-slate-900 mt-1"><?= h($price['currency']) ?> <?= h($price['input_price_per_1m']) ?></p>
                                <p class="text-[10px] text-slate-400 mt-0.5">Per 1 Million Input Tokens</p>
                            </div>
                            
                            <div class="rounded-2xl border border-slate-100 bg-slate-50/40 p-4">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Output / Image pricing</span>
                                <p class="heading-font text-lg font-black text-slate-900 mt-1"><?= h($price['currency']) ?> <?= h($price['image_output_price_per_1m']) ?></p>
                                <p class="text-[10px] text-slate-400 mt-0.5">Per 1 Million Output Tokens or Images</p>
                            </div>
                        </div>
                        <div class="mt-3.5 flex items-center justify-between text-[10px] font-semibold text-slate-400">
                            <span>Target Model: <strong class="text-slate-600 font-bold"><?= h($price['model_name']) ?></strong></span>
                            <span>Updated: <?= h(date('d M Y, h:i A', strtotime($price['updated_at']))) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="rounded-xl border border-rose-100 bg-rose-50/40 p-4 text-center text-xs font-semibold text-rose-700">
                            Model pricing rates not active in database.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Top spend tool snippet (Takes 1 Column) -->
                <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium flex flex-col justify-between">
                    <div>
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Top Spent integration</span>
                        <h4 class="heading-font text-2xl font-black text-slate-900 mt-1.5">
                            <?= $topTool && (float)$topTool['cost'] > 0 ? h($topTool['tool_name']) : 'N/A' ?>
                        </h4>
                        <p class="text-[11px] text-slate-400 mt-1">Leading total expenditure in current range</p>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-100 pt-3 mt-4">
                        <span class="text-[10px] font-bold text-slate-500">Range Spend:</span>
                        <span class="heading-font text-sm font-extrabold text-indigo-600"><?= $topTool ? money($topTool['cost']) : '$0.000000' ?></span>
                    </div>
                </div>
            </section>

            <!-- Row 4: Datagrids - Tool-wise Usage & Latest Logs -->
            <section class="grid gap-5 xl:grid-cols-2" id="log-tables">
                
                <!-- Tool-wise Performance Table -->
                <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-4">
                        <div>
                            <h3 class="heading-font text-base font-bold text-slate-900">Tool Breakdown Table</h3>
                            <p class="text-xs text-slate-400">Comprehensive metric split per unique client tool</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500">
                            <?= count($toolRows) ?> active
                        </span>
                    </div>

                    <div class="nice-scroll overflow-x-auto">
                        <table class="w-full min-w-[500px] text-left text-xs border-collapse">
                            <thead>
                                <tr class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-2">
                                    <th class="py-2.5 font-bold">Integration</th>
                                    <th class="py-2.5 text-right font-bold">Requests</th>
                                    <th class="py-2.5 text-right font-bold">Tokens</th>
                                    <th class="py-2.5 text-right font-bold">Images</th>
                                    <th class="py-2.5 text-right font-bold">Total Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($toolRows as $row): ?>
                                    <tr class="group hover:bg-slate-50/50 transition-soft">
                                        <td class="py-3">
                                            <div class="flex items-center gap-2.5">
                                                <?php
                                                // Unique badge colors
                                                $badgeStyle = 'bg-indigo-50 text-indigo-600 border-indigo-100/50';
                                                if ($row['tool_name'] === 'SocialMill') {
                                                    $badgeStyle = 'bg-rose-50 text-rose-600 border-rose-100/50';
                                                } elseif ($row['tool_name'] === 'Sizewise') {
                                                    $badgeStyle = 'bg-emerald-50 text-emerald-600 border-emerald-100/50';
                                                }
                                                ?>
                                                <span class="flex h-8 w-8 items-center justify-center rounded-xl font-bold border text-[10px] <?= $badgeStyle ?>">
                                                    <?= h(substr($row['tool_name'], 0, 2)) ?>
                                                </span>
                                                <span class="font-bold text-slate-900 group-hover:text-indigo-600 transition-soft"><?= h($row['tool_name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3 text-right font-semibold text-slate-700"><?= number_format((int)$row['requests']) ?></td>
                                        <td class="py-3 text-right font-semibold text-slate-700"><?= compact_number($row['total_tokens']) ?></td>
                                        <td class="py-3 text-right font-semibold text-slate-700"><?= number_format((int)$row['images']) ?></td>
                                        <td class="py-3 text-right font-extrabold text-indigo-600"><?= money($row['cost']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Latest Usage Logs Table (Slightly larger layout) -->
                <div class="rounded-3xl border border-slate-200/50 bg-white p-6 shadow-premium">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-4">
                        <div>
                            <h3 class="heading-font text-base font-bold text-slate-900">Latest Usage Logs</h3>
                            <p class="text-xs text-slate-400">Showing last 50 logged transactions in selected filter</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500">
                            <?= count($logs) ?> logs
                        </span>
                    </div>

                    <div class="nice-scroll overflow-x-auto max-h-[300px] overflow-y-auto">
                        <table class="w-full min-w-[600px] text-left text-xs border-collapse">
                            <thead>
                                <tr class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 border-b border-slate-100 sticky top-0 bg-white z-10 pb-2">
                                    <th class="py-2.5 font-bold">ID</th>
                                    <th class="py-2.5 font-bold">Tool</th>
                                    <th class="py-2.5 font-bold">Model</th>
                                    <th class="py-2.5 text-right font-bold">Tokens</th>
                                    <th class="py-2.5 text-right font-bold">Cost</th>
                                    <th class="py-2.5 font-bold pl-4">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php if (!$logs): ?>
                                    <tr>
                                        <td colspan="6" class="py-8 text-center text-slate-400 font-semibold">
                                            No logs active for this query.
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($logs as $log): ?>
                                    <tr class="hover:bg-slate-50/50 transition-soft">
                                        <td class="py-3 font-semibold text-slate-400">#<?= h($log['id']) ?></td>
                                        <td class="py-3 font-bold text-slate-800"><?= h($log['tool_name']) ?></td>
                                        <td class="py-3 text-slate-500 font-medium truncate max-w-[120px]" title="<?= h($log['model_name']) ?>">
                                            <?= h($log['model_name']) ?>
                                        </td>
                                        <td class="py-3 text-right font-semibold text-slate-600"><?= number_format((int)$log['total_tokens']) ?></td>
                                        <td class="py-3 text-right font-extrabold text-slate-900"><?= money($log['total_cost']) ?></td>
                                        <td class="py-3 pl-4">
                                            <?php if ($log['status'] === 'success'): ?>
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-extrabold text-emerald-600 border border-emerald-100/30">
                                                    <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                                                    Success
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[9px] font-extrabold text-rose-600 border border-rose-100/30">
                                                    <span class="h-1 w-1 rounded-full bg-rose-500"></span>
                                                    Failed
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <!-- Premium Footer -->
        <footer class="border-t border-slate-200/50 bg-white/60 backdrop-blur px-8 py-5 text-center text-[10px] font-bold text-slate-400">
            DIGICHEFS AI DEPLOYMENT CENTRE · DYNAMIC PHP LIVE FEED · DATA FROM TABLE [AI_USAGE_LOGS]
        </footer>
    </div>

    <!-- Scripting Area for Layout and Chart.js -->
    <script>
        // Mobile Sidebar Controls
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

        // --- CHART JS BINDINGS ---
        
        // 1. Tool cost share doughnut chart
        <?php if ((float)$summary['total_cost'] > 0): ?>
        const rawToolData = <?php echo json_encode($toolRows); ?>;
        const toolLabels = rawToolData.map(r => r.tool_name);
        const toolCosts = rawToolData.map(r => parseFloat(r.cost));
        
        // Map premium color gradients
        const toolColors = toolLabels.map(label => {
            if (label === 'ABFRL') return '#6366f1'; // Indigo
            if (label === 'SocialMill') return '#f43f5e'; // Rose
            if (label === 'Sizewise') return '#10b981'; // Emerald
            return '#94a3b8';
        });

        new Chart(document.getElementById('chart-tool-share'), {
            type: 'doughnut',
            data: {
                labels: toolLabels,
                datasets: [{
                    data: toolCosts,
                    backgroundColor: toolColors,
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let val = context.raw;
                                return ' ' + context.label + ': $' + val.toFixed(6);
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // 2. Token split volume doughnut chart
        <?php if ((int)$summary['total_tokens'] > 0): ?>
        new Chart(document.getElementById('chart-token-share'), {
            type: 'doughnut',
            data: {
                labels: ['Input Tokens', 'Output Tokens'],
                datasets: [{
                    data: [
                        <?php echo (float)$summary['input_tokens']; ?>, 
                        <?php echo (float)$summary['output_tokens']; ?>
                    ],
                    backgroundColor: ['#6366f1', '#06b6d4'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let val = context.raw;
                                return ' ' + context.label + ': ' + val.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // 3. Dynamic usage daily bar & line chart (Multi-axis)
        <?php if ($dailyRows): ?>
        const rawDailyData = <?php echo json_encode($dailyRows); ?>;
        // Format dates as dd MMM
        const dailyLabels = rawDailyData.map(r => {
            const dateObj = new Date(r.usage_date);
            return dateObj.toLocaleDateString('en-US', { day: '2-digit', month: 'short' });
        });
        const dailyCosts = rawDailyData.map(r => parseFloat(r.cost));
        const dailyRequests = rawDailyData.map(r => parseInt(r.requests));

        new Chart(document.getElementById('chart-dynamics'), {
            type: 'bar',
            data: {
                labels: dailyLabels,
                datasets: [
                    {
                        label: 'Estimated Spend',
                        type: 'line',
                        data: dailyCosts,
                        borderColor: '#6366f1',
                        borderWidth: 3,
                        pointBackgroundColor: '#6366f1',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.35,
                        fill: false,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Requests',
                        type: 'bar',
                        data: dailyRequests,
                        backgroundColor: 'rgba(226, 232, 240, 0.65)',
                        hoverBackgroundColor: 'rgba(99, 102, 241, 0.15)',
                        borderRadius: 6,
                        barThickness: 12,
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
                                let label = context.dataset.label || '';
                                if (context.datasetIndex === 0) {
                                    return ' Spend: $' + context.raw.toFixed(6);
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
                            font: { size: 9, weight: 'bold' },
                            color: '#94a3b8'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: {
                            color: '#f1f5f9',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 9, weight: 'bold' },
                            color: '#94a3b8',
                            callback: function(value) {
                                return '$' + value.toFixed(3);
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: {
                            font: { size: 9, weight: 'bold' },
                            color: '#94a3b8',
                            stepSize: 1
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Email Sending Action Logic
        const sendMailBtn = document.getElementById('sendMailBtn');
        const mailStatus = document.getElementById('mailStatus');

        function showMailStatus(type, message) {
            mailStatus.classList.remove(
                'hidden',
                'border-emerald-200',
                'bg-emerald-50',
                'text-emerald-800',
                'border-rose-200',
                'bg-rose-50',
                'text-rose-800'
            );

            if (type === 'success') {
                mailStatus.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-800', 'border');
            } else {
                mailStatus.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-800', 'border');
            }

            mailStatus.textContent = message;
        }

        sendMailBtn.addEventListener('click', async () => {
            const toEmails = document.getElementById('toEmails').value
                .split(',')
                .map(email => email.trim())
                .filter(Boolean);

            if (!toEmails.length) {
                showMailStatus('error', 'Please define at least one valid email address.');
                return;
            }

            // Button Loading State
            sendMailBtn.disabled = true;
            sendMailBtn.innerHTML = `
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Sending Report...</span>
            `;
            sendMailBtn.classList.add('opacity-75', 'cursor-not-allowed');

            try {
                const response = await fetch('send-today-report.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ to: toEmails })
                });

                const result = await response.json();

                if (result.success) {
                    showMailStatus('success', result.message || 'Today\'s usage report successfully emailed.');
                } else {
                    showMailStatus('error', result.message || 'Email dispatch failed.');
                }
            } catch (error) {
                showMailStatus('error', 'Email request error. Please verify send-today-report.php setup.');
            }

            // Restore button state
            sendMailBtn.disabled = false;
            sendMailBtn.innerHTML = '<span>Send Today Report</span>';
            sendMailBtn.classList.remove('opacity-75', 'cursor-not-allowed');
        });
    </script>
</body>
</html>

</html>
