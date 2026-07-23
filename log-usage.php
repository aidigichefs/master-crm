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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST allowed"
    ]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$toolName = trim($data['tool_name'] ?? '');
$modelName = 'gemini-3-pro-image-preview';

$inputTokens = intval($data['input_tokens'] ?? 0);
$outputTokens = intval($data['output_tokens'] ?? 0);
$totalTokens = intval($data['total_tokens'] ?? 0);
$imageCount = intval($data['image_count'] ?? 1);
$status = trim($data['status'] ?? 'success');
$notes = trim($data['notes'] ?? '');

$allowedTools = ['ABFRL', 'SocialMill', 'Sizewise'];

if (!in_array($toolName, $allowedTools)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid tool name. Allowed: ABFRL, SocialMill, Sizewise"
    ]);
    exit;
}

if ($totalTokens <= 0) {
    $totalTokens = $inputTokens + $outputTokens;
}

$stmt = $pdo->prepare("
    SELECT input_price_per_1m, image_output_price_per_1m 
    FROM ai_model_price 
    WHERE model_name = :model_name
    LIMIT 1
");

$stmt->execute([
    ':model_name' => $modelName
]);

$price = $stmt->fetch();

if (!$price) {
    http_response_code(404);
    echo json_encode([
        "success" => false,
        "message" => "Model price not found"
    ]);
    exit;
}

$inputPrice = floatval($price['input_price_per_1m']);
$outputPrice = floatval($price['image_output_price_per_1m']);

$inputCost = ($inputTokens / 1000000) * $inputPrice;
$outputCost = ($outputTokens / 1000000) * $outputPrice;
$totalCost = $inputCost + $outputCost;

$stmt = $pdo->prepare("
    INSERT INTO ai_usage_logs (
        tool_name,
        model_name,
        input_tokens,
        output_tokens,
        total_tokens,
        image_count,
        input_cost,
        output_cost,
        total_cost,
        status,
        notes
    ) VALUES (
        :tool_name,
        :model_name,
        :input_tokens,
        :output_tokens,
        :total_tokens,
        :image_count,
        :input_cost,
        :output_cost,
        :total_cost,
        :status,
        :notes
    )
");

$stmt->execute([
    ':tool_name' => $toolName,
    ':model_name' => $modelName,
    ':input_tokens' => $inputTokens,
    ':output_tokens' => $outputTokens,
    ':total_tokens' => $totalTokens,
    ':image_count' => $imageCount,
    ':input_cost' => $inputCost,
    ':output_cost' => $outputCost,
    ':total_cost' => $totalCost,
    ':status' => $status,
    ':notes' => $notes
]);

echo json_encode([
    "success" => true,
    "message" => "Usage saved successfully",
    "data" => [
        "id" => $pdo->lastInsertId(),
        "tool_name" => $toolName,
        "model_name" => $modelName,
        "input_tokens" => $inputTokens,
        "output_tokens" => $outputTokens,
        "total_tokens" => $totalTokens,
        "input_cost" => round($inputCost, 8),
        "output_cost" => round($outputCost, 8),
        "total_cost" => round($totalCost, 8),
        "currency" => "USD"
    ]
]);