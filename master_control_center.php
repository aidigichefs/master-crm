<?php
require_once __DIR__ . '/db.php';

header_remove('Content-Type');
header('Content-Type: text/html; charset=UTF-8');

date_default_timezone_set('Asia/Kolkata');

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return '$' . number_format((float) $value, 6);
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

$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));

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

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_requests,
        COALESCE(SUM(total_tokens), 0) AS total_tokens,
        COALESCE(SUM(image_count), 0) AS total_images,
        COALESCE(SUM(total_cost), 0) AS total_cost
    FROM ai_usage_logs
    WHERE DATE(created_at) BETWEEN :from_date AND :to_date
");
$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to
]);
$summary = $stmt->fetch() ?: [
    'total_requests' => 0,
    'total_tokens' => 0,
    'total_images' => 0,
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

$stmt = $pdo->prepare("
    SELECT
        at.id,
        at.tool_name,
        at.is_active,
        COUNT(DISTINCT ua.id) AS user_count,
        COALESCE(COUNT(aul.id), 0) AS request_count,
        COALESCE(SUM(aul.total_tokens), 0) AS total_tokens,
        COALESCE(SUM(aul.image_count), 0) AS total_images,
        COALESCE(SUM(aul.total_cost), 0) AS total_cost,
        MAX(aul.created_at) AS last_activity
    FROM ai_tools at
    LEFT JOIN user_accounts ua
        ON ua.tool_id = at.id
    LEFT JOIN ai_usage_logs aul
        ON aul.tool_name = at.tool_name
        AND DATE(aul.created_at) BETWEEN :from_date AND :to_date
    GROUP BY at.id, at.tool_name, at.is_active
    ORDER BY total_cost DESC, at.tool_name ASC
");
$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to
]);
$systemCards = $stmt->fetchAll();

$stmt = $pdo->prepare("
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
        COALESCE(stats.total_cost, 0) AS system_total_cost,
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
            COALESCE(SUM(total_cost), 0) AS total_cost,
            MAX(created_at) AS last_activity
        FROM ai_usage_logs
        WHERE DATE(created_at) BETWEEN :from_date AND :to_date
        GROUP BY tool_name
    ) stats
        ON stats.tool_name = at.tool_name
    ORDER BY at.tool_name ASC, ua.id DESC
");
$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to
]);
$users = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        id,
        tool_name,
        model_name,
        total_tokens,
        image_count,
        total_cost,
        status,
        notes,
        created_at
    FROM ai_usage_logs
    WHERE DATE(created_at) BETWEEN :from_date AND :to_date
    ORDER BY id DESC
    LIMIT 100
");
$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to
]);
$logs = $stmt->fetchAll();

$topSystem = null;
$maxSystemRequests = 0;
foreach ($systemCards as $systemCard) {
    if ((int) $systemCard['request_count'] > $maxSystemRequests) {
        $maxSystemRequests = (int) $systemCard['request_count'];
        $topSystem = $systemCard;
    }
}

$rangeLabel = $from === $to ? $from : ($from . ' to ' . $to);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Control Center | DigiChefs AI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --ink: #112218;
            --mist: #eff5ec;
            --panel: rgba(255, 255, 255, 0.92);
            --line: rgba(17, 34, 24, 0.08);
            --accent: #2f7a4b;
            --accent-soft: #d7ecd6;
            --warning: #c7782a;
            --rose: #9e3f59;
        }

        body {
            font-family: 'Manrope', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(117, 184, 127, 0.18), transparent 30%),
                radial-gradient(circle at right 20%, rgba(236, 190, 123, 0.12), transparent 26%),
                linear-gradient(180deg, #f7fbf5 0%, #eef4ee 46%, #f9fcf8 100%);
            min-height: 100vh;
        }

        .display-font {
            font-family: 'Space Grotesk', sans-serif;
        }

        .glass {
            background: var(--panel);
            backdrop-filter: blur(16px);
            border: 1px solid var(--line);
            box-shadow: 0 18px 40px rgba(35, 54, 40, 0.08);
        }

        .table-scroll::-webkit-scrollbar {
            height: 8px;
            width: 8px;
        }

        .table-scroll::-webkit-scrollbar-thumb {
            background: rgba(47, 122, 75, 0.25);
            border-radius: 999px;
        }
    </style>
</head>
<body>
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <section class="glass overflow-hidden rounded-[32px]">
            <div class="grid gap-8 px-6 py-8 lg:grid-cols-[1.2fr_0.8fr] lg:px-8">
                <div class="space-y-5">
                    <div class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.24em] text-emerald-700">
                        Master Control Center
                    </div>
                    <div class="space-y-3">
                        <h1 class="display-font max-w-3xl text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">
                            One admin view for users, systems, usage, and live account coverage.
                        </h1>
                        <p class="max-w-2xl text-sm leading-7 text-slate-600 sm:text-base">
                            This page combines every current system in <code>ai_tools</code>, every login in <code>user_accounts</code>,
                            and usage from <code>ai_usage_logs</code>. User-level usage is shown using the mapped system totals,
                            because logs are currently stored by tool name and not by user ID.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="rounded-full bg-slate-900 px-4 py-2 font-semibold text-white">Range: <?= h($rangeLabel) ?></span>
                        <span class="rounded-full bg-white px-4 py-2 font-semibold text-slate-700 ring-1 ring-slate-200"><?= number_format((int) $userSummary['total_users']) ?> users</span>
                        <span class="rounded-full bg-white px-4 py-2 font-semibold text-slate-700 ring-1 ring-slate-200"><?= number_format(count($tools)) ?> systems</span>
                        <span class="rounded-full bg-white px-4 py-2 font-semibold text-slate-700 ring-1 ring-slate-200"><?= compact_number($summary['total_requests']) ?> total uses</span>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-3xl bg-slate-950 p-5 text-white shadow-2xl">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-300">Total Spend</p>
                        <p class="display-font mt-3 text-3xl font-bold"><?= money($summary['total_cost']) ?></p>
                        <p class="mt-2 text-sm text-slate-300">Across all tracked systems in the selected date range.</p>
                    </div>
                    <div class="rounded-3xl bg-white p-5 ring-1 ring-slate-200">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Token Volume</p>
                        <p class="display-font mt-3 text-3xl font-bold text-slate-900"><?= compact_number($summary['total_tokens']) ?></p>
                        <p class="mt-2 text-sm text-slate-500">Total prompt and output tokens logged.</p>
                    </div>
                    <div class="rounded-3xl bg-white p-5 ring-1 ring-slate-200">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Admins vs Users</p>
                        <p class="mt-3 text-2xl font-extrabold text-slate-900"><?= number_format((int) $userSummary['total_admins']) ?> / <?= number_format((int) $userSummary['total_standard_users']) ?></p>
                        <p class="mt-2 text-sm text-slate-500">Admin accounts compared with standard users.</p>
                    </div>
                    <div class="rounded-3xl bg-gradient-to-br from-emerald-100 to-lime-50 p-5 ring-1 ring-emerald-200/70">
                        <p class="text-xs font-bold uppercase tracking-[0.2em] text-emerald-800">Most Active System</p>
                        <p class="mt-3 text-2xl font-extrabold text-slate-900"><?= h($topSystem['tool_name'] ?? 'No data') ?></p>
                        <p class="mt-2 text-sm text-emerald-900/80">
                            <?= $topSystem ? number_format((int) $topSystem['request_count']) . ' requests in selected range' : 'No usage logged yet.' ?>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-6 glass rounded-[28px] p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Filters</p>
                    <h2 class="display-font mt-2 text-2xl font-bold text-slate-900">Refresh the command view</h2>
                </div>
                <form method="get" class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm font-semibold text-slate-600">
                        From
                        <input type="date" name="from" value="<?= h($from) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 outline-none transition focus:border-emerald-500">
                    </label>
                    <label class="text-sm font-semibold text-slate-600">
                        To
                        <input type="date" name="to" value="<?= h($to) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 outline-none transition focus:border-emerald-500">
                    </label>
                    <div class="flex items-end gap-3">
                        <button type="submit" class="w-full rounded-2xl bg-slate-900 px-5 py-3 text-sm font-bold text-white transition hover:bg-slate-700">Apply</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="mt-6">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">System Overview</p>
                    <h2 class="display-font mt-2 text-2xl font-bold text-slate-900">Every tool in one grid</h2>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <?php foreach ($systemCards as $card): ?>
                    <?php
                    $statusClasses = (int) $card['is_active'] === 1
                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                        : 'bg-rose-50 text-rose-700 ring-rose-200';
                    ?>
                    <article class="glass rounded-[28px] p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">System #<?= h($card['id']) ?></p>
                                <h3 class="display-font mt-2 text-2xl font-bold text-slate-900"><?= h($card['tool_name']) ?></h3>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-bold ring-1 <?= $statusClasses ?>">
                                <?= (int) $card['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>

                        <div class="mt-5 grid grid-cols-2 gap-3">
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">Users</p>
                                <p class="mt-2 text-2xl font-extrabold text-slate-900"><?= number_format((int) $card['user_count']) ?></p>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">Uses</p>
                                <p class="mt-2 text-2xl font-extrabold text-slate-900"><?= compact_number($card['request_count']) ?></p>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">Tokens</p>
                                <p class="mt-2 text-xl font-extrabold text-slate-900"><?= compact_number($card['total_tokens']) ?></p>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-400">Spend</p>
                                <p class="mt-2 text-xl font-extrabold text-slate-900"><?= money($card['total_cost']) ?></p>
                            </div>
                        </div>

                        <div class="mt-5 rounded-2xl bg-gradient-to-r from-emerald-50 to-amber-50 p-4 ring-1 ring-emerald-100">
                            <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Last Activity</p>
                            <p class="mt-2 text-sm font-semibold text-slate-900"><?= h($card['last_activity'] ?: 'No logs found in selected range') ?></p>
                            <p class="mt-1 text-xs leading-6 text-slate-500">Includes all usage logs tied to this system name in <code>ai_usage_logs</code>.</p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.3fr_0.7fr]">
            <div class="glass rounded-[28px] p-5 sm:p-6">
                <div class="mb-4">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">User Directory</p>
                    <h2 class="display-font mt-2 text-2xl font-bold text-slate-900">All users, mapped systems, and stored credentials</h2>
                </div>
                <div class="rounded-2xl bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 ring-1 ring-amber-200">
                    User-level usage is currently shown using the totals of the mapped system, because logs are grouped by tool name, not by user ID.
                </div>
                <div class="table-scroll mt-4 overflow-x-auto">
                    <table class="min-w-[1100px] w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-xs uppercase tracking-[0.2em] text-slate-500">
                                <th class="px-3 py-3 font-bold">User</th>
                                <th class="px-3 py-3 font-bold">Password</th>
                                <th class="px-3 py-3 font-bold">Role</th>
                                <th class="px-3 py-3 font-bold">System</th>
                                <th class="px-3 py-3 font-bold">System Uses</th>
                                <th class="px-3 py-3 font-bold">System Spend</th>
                                <th class="px-3 py-3 font-bold">Last Activity</th>
                                <th class="px-3 py-3 font-bold">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-3 py-4">
                                        <div class="font-extrabold text-slate-900"><?= h($user['username']) ?></div>
                                        <div class="text-xs text-slate-500">ID #<?= h($user['id']) ?></div>
                                    </td>
                                    <td class="px-3 py-4">
                                        <code class="rounded-xl bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-800"><?= h($user['password']) ?></code>
                                    </td>
                                    <td class="px-3 py-4">
                                        <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-bold text-white"><?= h($user['role']) ?></span>
                                    </td>
                                    <td class="px-3 py-4">
                                        <div class="font-bold text-slate-900"><?= h($user['tool_name'] ?: 'Unassigned') ?></div>
                                        <div class="text-xs text-slate-500">tool_id: <?= h($user['tool_id'] ?: '-') ?></div>
                                    </td>
                                    <td class="px-3 py-4 font-bold text-slate-900"><?= number_format((int) $user['system_request_count']) ?></td>
                                    <td class="px-3 py-4 font-bold text-emerald-700"><?= money($user['system_total_cost']) ?></td>
                                    <td class="px-3 py-4 text-slate-600"><?= h($user['last_activity'] ?: 'No activity') ?></td>
                                    <td class="px-3 py-4 text-slate-600"><?= h($user['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-6">
                <div class="glass rounded-[28px] p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Quick Snapshot</p>
                    <h2 class="display-font mt-2 text-2xl font-bold text-slate-900">What this page is surfacing</h2>
                    <ul class="mt-4 space-y-3 text-sm leading-7 text-slate-600">
                        <li>Every user in <code>user_accounts</code> with their role, mapped tool, and stored password.</li>
                        <li>Every system in <code>ai_tools</code> with usage totals and user coverage.</li>
                        <li>All usage from <code>ai_usage_logs</code> across the selected date range.</li>
                        <li>A recent activity table so you can inspect raw log behavior quickly.</li>
                    </ul>
                </div>

                <div class="glass rounded-[28px] p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Totals</p>
                    <div class="mt-4 space-y-4">
                        <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                            <span class="text-sm font-semibold text-slate-500">Total Images</span>
                            <span class="text-lg font-extrabold text-slate-900"><?= compact_number($summary['total_images']) ?></span>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                            <span class="text-sm font-semibold text-slate-500">Recent Logs Loaded</span>
                            <span class="text-lg font-extrabold text-slate-900"><?= number_format(count($logs)) ?></span>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                            <span class="text-sm font-semibold text-slate-500">Active Systems</span>
                            <span class="text-lg font-extrabold text-slate-900"><?= number_format(count(array_filter($tools, function ($toolRow) { return (int) $toolRow['is_active'] === 1; }))) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-6 glass rounded-[28px] p-5 sm:p-6">
            <div class="mb-4">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500">Recent Activity</p>
                <h2 class="display-font mt-2 text-2xl font-bold text-slate-900">Latest usage logs</h2>
            </div>
            <div class="table-scroll overflow-x-auto">
                <table class="min-w-[980px] w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs uppercase tracking-[0.2em] text-slate-500">
                            <th class="px-3 py-3 font-bold">ID</th>
                            <th class="px-3 py-3 font-bold">System</th>
                            <th class="px-3 py-3 font-bold">Model</th>
                            <th class="px-3 py-3 font-bold">Tokens</th>
                            <th class="px-3 py-3 font-bold">Images</th>
                            <th class="px-3 py-3 font-bold">Cost</th>
                            <th class="px-3 py-3 font-bold">Status</th>
                            <th class="px-3 py-3 font-bold">Notes</th>
                            <th class="px-3 py-3 font-bold">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (!$logs): ?>
                            <tr>
                                <td colspan="9" class="px-3 py-8 text-center text-sm font-semibold text-slate-500">No logs found for the selected date range.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-3 py-4 font-bold text-slate-500">#<?= h($log['id']) ?></td>
                                <td class="px-3 py-4 font-bold text-slate-900"><?= h($log['tool_name']) ?></td>
                                <td class="px-3 py-4 text-slate-600"><?= h($log['model_name']) ?></td>
                                <td class="px-3 py-4 font-bold text-slate-900"><?= number_format((int) $log['total_tokens']) ?></td>
                                <td class="px-3 py-4 text-slate-600"><?= number_format((int) $log['image_count']) ?></td>
                                <td class="px-3 py-4 font-bold text-emerald-700"><?= money($log['total_cost']) ?></td>
                                <td class="px-3 py-4">
                                    <?php $statusClass = $log['status'] === 'success' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-rose-50 text-rose-700 ring-rose-200'; ?>
                                    <span class="rounded-full px-3 py-1 text-xs font-bold ring-1 <?= $statusClass ?>"><?= h($log['status']) ?></span>
                                </td>
                                <td class="px-3 py-4 text-slate-600"><?= h($log['notes']) ?></td>
                                <td class="px-3 py-4 text-slate-600"><?= h($log['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>
</html>
