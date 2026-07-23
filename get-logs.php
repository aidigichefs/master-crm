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

$page = max(1, intval($_GET['page'] ?? 1));
$limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$tool = trim($_GET['tool'] ?? '');

$where = "";
$params = [];

if ($tool !== '') {
    $where = "WHERE tool_name = :tool_name";
    $params[':tool_name'] = $tool;
}

$sql = "
    SELECT
        id,
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
        notes,
        created_at
    FROM ai_usage_logs
    $where
    ORDER BY id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();

echo json_encode([
    "success" => true,
    "page" => $page,
    "limit" => $limit,
    "data" => $stmt->fetchAll()
]);