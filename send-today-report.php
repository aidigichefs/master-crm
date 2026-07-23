<?php
/**
 * Send Today's AI Usage Email
 * Upload to: public_html/ai-usage-api/send-today-report.php
 *
 * From dashboard:
 * POST /ai-usage-api/send-today-report.php
 * Body:
 * {
 *   "to": ["email1@example.com", "email2@example.com"]
 * }
 *
 * Direct URL:
 * https://digichefs.in/ai-usage-api/send-today-report.php?direct=1&key=YOUR_API_SECRET
 */

require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Kolkata');

$mailApiUrl = 'https://digichefs.in/sajan/mail.php';
$today = date('Y-m-d');

function json_response($success, $message, $extra = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money($value) {
    return '$' . number_format((float)$value, 6);
}

function send_mail_api($mailApiUrl, $to, $subject, $body) {
    $payload = json_encode([
        'to' => $to,
        'subject' => $subject,
        'body' => $body
    ]);

    $ch = curl_init($mailApiUrl);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($error) {
        return [
            'success' => false,
            'message' => 'Mail API cURL error: ' . $error,
            'http_code' => $httpCode,
            'response' => $response
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'message' => 'Mail API returned HTTP ' . $httpCode,
            'http_code' => $httpCode,
            'response' => $response
        ];
    }

    return [
        'success' => true,
        'message' => 'Mail API called successfully',
        'http_code' => $httpCode,
        'response' => $response
    ];
}

/**
 * Default direct recipients
 */
$defaultRecipients = [
    'sajan.m@digichefs.com',
    'sajanmajrekar14@gmail.com'
];

/**
 * Recipient logic
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['direct'] ?? '') === '1') {
    $key = $_GET['key'] ?? '';

    if (!defined('API_SECRET') || $key !== API_SECRET) {
        json_response(false, 'Unauthorized direct email trigger.');
    }

    $to = $defaultRecipients;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $to = $input['to'] ?? [];

    if (!is_array($to)) {
        json_response(false, 'Invalid to email list.');
    }

    $to = array_values(array_filter(array_map('trim', $to)));

    if (!$to) {
        json_response(false, 'Please provide at least one recipient email.');
    }

    foreach ($to as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(false, 'Invalid email ID: ' . $email);
        }
    }
} else {
    json_response(false, 'Only POST allowed, or use direct URL with ?direct=1&key=dgcf_ai_usage_2026_x7Kp92LmQ4vN8zR1tB6sY3wE');
}

/**
 * Today's overall summary
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
    WHERE DATE(created_at) = :today
");
$stmt->execute([':today' => $today]);
$summary = $stmt->fetch();

/**
 * Today's tool-wise summary
 */
$stmt = $pdo->prepare("
    SELECT
        tool_name,
        COUNT(*) AS requests,
        COALESCE(SUM(input_tokens), 0) AS input_tokens,
        COALESCE(SUM(output_tokens), 0) AS output_tokens,
        COALESCE(SUM(total_tokens), 0) AS total_tokens,
        COALESCE(SUM(image_count), 0) AS images,
        COALESCE(SUM(total_cost), 0) AS cost
    FROM ai_usage_logs
    WHERE DATE(created_at) = :today
    GROUP BY tool_name
    ORDER BY cost DESC
");
$stmt->execute([':today' => $today]);
$tools = $stmt->fetchAll();

/**
 * Pricing
 */
$stmt = $pdo->query("
    SELECT
        model_name,
        input_price_per_1m,
        image_output_price_per_1m,
        currency
    FROM ai_model_price
    WHERE model_name = 'gemini-3-pro-image-preview'
    LIMIT 1
");
$price = $stmt->fetch();

$subject = 'Today AI Usage Report - ' . $today;

$toolRowsHtml = '';

if ($tools) {
    foreach ($tools as $row) {
        $toolRowsHtml .= '
            <tr>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;font-weight:700;">' . h($row['tool_name']) . '</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;">' . number_format((int)$row['requests']) . '</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;">' . number_format((int)$row['images']) . '</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;">' . number_format((int)$row['input_tokens']) . '</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;">' . number_format((int)$row['output_tokens']) . '</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;">' . number_format((int)$row['total_tokens']) . '</td>
                <td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:700;">' . money($row['cost']) . '</td>
            </tr>
        ';
    }
} else {
    $toolRowsHtml = '
        <tr>
            <td colspan="7" style="padding:16px;text-align:center;color:#6b7280;">No usage found for today.</td>
        </tr>
    ';
}

$priceHtml = '';

if ($price) {
    $priceHtml = '
        <p style="margin:6px 0;color:#475569;">
            Model: <strong>' . h($price['model_name']) . '</strong><br>
            Input Price: <strong>' . h($price['currency']) . ' ' . h($price['input_price_per_1m']) . ' / 1M tokens</strong><br>
            Output Price: <strong>' . h($price['currency']) . ' ' . h($price['image_output_price_per_1m']) . ' / 1M tokens</strong>
        </p>
    ';
}

$body = '
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">
    <div style="max-width:860px;margin:0 auto;padding:24px;">
        <div style="background:linear-gradient(135deg,#0f172a,#312e81);color:#ffffff;border-radius:20px;padding:24px;margin-bottom:18px;">
            <h1 style="margin:0;font-size:28px;">Today AI Usage Report</h1>
            <p style="margin:8px 0 0;color:#cbd5e1;">Date: ' . h($today) . '</p>
        </div>

        <div style="background:#ffffff;border-radius:18px;padding:20px;margin-bottom:18px;border:1px solid #e5e7eb;">
            <h2 style="margin:0 0 12px;font-size:20px;">Overall Summary</h2>

            <table style="width:100%;border-collapse:separate;border-spacing:8px;">
                <tr>
                    <td style="padding:14px;background:#f1f5f9;border-radius:12px;">Total Requests<br><strong style="font-size:22px;">' . number_format((int)$summary['total_requests']) . '</strong></td>
                    <td style="padding:14px;background:#f1f5f9;border-radius:12px;">Images<br><strong style="font-size:22px;">' . number_format((int)$summary['images']) . '</strong></td>
                    <td style="padding:14px;background:#f1f5f9;border-radius:12px;">Total Tokens<br><strong style="font-size:22px;">' . number_format((int)$summary['total_tokens']) . '</strong></td>
                    <td style="padding:14px;background:#ecfeff;border-radius:12px;">Total Cost<br><strong style="font-size:22px;color:#0369a1;">' . money($summary['total_cost']) . '</strong></td>
                </tr>
            </table>

            <div style="margin-top:14px;">
                ' . $priceHtml . '
            </div>
        </div>

        <div style="background:#ffffff;border-radius:18px;padding:20px;border:1px solid #e5e7eb;">
            <h2 style="margin:0 0 12px;font-size:20px;">Tool-wise Usage</h2>

            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#0f172a;color:#ffffff;">
                        <th style="padding:10px;text-align:left;">Tool</th>
                        <th style="padding:10px;text-align:center;">Requests</th>
                        <th style="padding:10px;text-align:center;">Images</th>
                        <th style="padding:10px;text-align:center;">Input</th>
                        <th style="padding:10px;text-align:center;">Output</th>
                        <th style="padding:10px;text-align:center;">Total Tokens</th>
                        <th style="padding:10px;text-align:right;">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $toolRowsHtml . '
                </tbody>
            </table>
        </div>

        <p style="font-size:12px;color:#64748b;margin-top:18px;">
            This report was generated automatically from DigiChefs AI usage tracking dashboard.
        </p>
    </div>
</body>
</html>
';

$mailResult = send_mail_api($mailApiUrl, $to, $subject, $body);

if (!$mailResult['success']) {
    json_response(false, $mailResult['message'], [
        'mail_response' => $mailResult
    ]);
}

json_response(true, 'Today usage report sent successfully.', [
    'to' => $to,
    'date' => $today,
    'mail_response' => $mailResult
]);
