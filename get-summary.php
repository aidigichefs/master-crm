<?php

require_once __DIR__ . '/db.php';

$headers = getallheaders();
$apiKey = $headers['X-API-KEY'] ?? $headers['x-api-key'] ?? '';

if ($apiKey !== API_SECRET) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "message" => "Unauthorized"
    ]);
    exit;
}

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_requests,
        SUM(input_tokens) AS total_input_tokens,
        SUM(output_tokens) AS total_output_tokens,
        SUM(total_tokens) AS total_tokens,
        SUM(image_count) AS total_images,
        ROUND(SUM(total_cost), 6) AS total_cost_usd
    FROM ai_usage_logs
    WHERE DATE(created_at) BETWEEN :from_date AND :to_date
");

$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to
]);

$summary = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT
        tool_name,
        COUNT(*) AS total_requests,
        SUM(input_tokens) AS input_tokens,
        SUM(output_tokens) AS output_tokens,
        SUM(total_tokens) AS total_tokens,
        SUM(image_count) AS images,
        ROUND(SUM(total_cost), 6) AS cost_usd
    FROM ai_usage_logs
    WHERE DATE(created_at) BETWEEN :from_date AND :to_date
    GROUP BY tool_name
    ORDER BY cost_usd DESC
");

$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to
]);

$tools = $stmt->fetchAll();

echo json_encode([
    "success" => true,
    "from" => $from,
    "to" => $to,
    "summary" => $summary,
    "tools" => $tools
]);