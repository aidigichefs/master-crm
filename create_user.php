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

$insertSql = "INSERT INTO user_accounts (username, password, role) VALUES (?, ?, ?)";
$insertStmt = $conn->prepare($insertSql);
$insertStmt->bind_param("sss", $username, $password, $role);

if ($insertStmt->execute()) {
    echo json_encode([
        "status" => true,
        "message" => "User created successfully",
        "user_id" => $insertStmt->insert_id,
        "username" => $username,
        "role" => $role
    ]);
} else {
    echo json_encode([
        "status" => false,
        "message" => "Failed to create user"
    ]);
}

$checkStmt->close();
$insertStmt->close();
$conn->close();