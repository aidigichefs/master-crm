<?php

header("Content-Type: application/json");

require_once("config.php");

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die(json_encode([
        "status" => false,
        "message" => "Database connection failed"
    ]));
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$role = trim($_POST['role'] ?? 'USER');
$toolId = intval($_POST['tool_id'] ?? 0);

if ($username === '' || $password === '') {
    echo json_encode([
        "status" => false,
        "message" => "Username and password are required"
    ]);
    exit;
}

$allowedRoles = ['USER', 'ADMIN'];
if (!in_array($role, $allowedRoles, true)) {
    echo json_encode([
        "status" => false,
        "message" => "Invalid role"
    ]);
    exit;
}

$toolCheckSql = "SELECT id, tool_name FROM ai_tools WHERE id = ? AND is_active = 1 LIMIT 1";
$toolCheckStmt = $conn->prepare($toolCheckSql);
$toolCheckStmt->bind_param("i", $toolId);
$toolCheckStmt->execute();
$toolCheckResult = $toolCheckStmt->get_result();
$tool = $toolCheckResult->fetch_assoc();

if (!$tool) {
    echo json_encode([
        "status" => false,
        "message" => "Valid active tool_id is required"
    ]);
    $toolCheckStmt->close();
    $conn->close();
    exit;
}

$checkSql = "SELECT id FROM user_accounts WHERE username = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("s", $username);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    echo json_encode([
        "status" => false,
        "message" => "User already exists"
    ]);
    exit;
}

$insertSql = "INSERT INTO user_accounts (username, password, role, tool_id) VALUES (?, ?, ?, ?)";
$insertStmt = $conn->prepare($insertSql);
$insertStmt->bind_param("sssi", $username, $password, $role, $toolId);

if ($insertStmt->execute()) {
    echo json_encode([
        "status" => true,
        "message" => "User created successfully",
        "user_id" => $insertStmt->insert_id,
        "username" => $username,
        "role" => $role,
        "tool_id" => $toolId,
        "tool_name" => $tool['tool_name']
    ]);
} else {
    echo json_encode([
        "status" => false,
        "message" => "Failed to create user"
    ]);
}

$toolCheckStmt->close();
$checkStmt->close();
$insertStmt->close();
$conn->close();
