<?php

header("Content-Type: application/json");

require_once("config.php");
$conn = new mysqli(
    DB_HOST,
    DB_USER,
    DB_PASS,
    DB_NAME
);
// Check if connection exists
if (!$conn) {
    die(json_encode([
        "status" => false,
        "message" => "Database connection failed"
    ]));
}

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode([
        "status" => false,
        "message" => "Username and password are required"
    ]);
    exit;
}

$sql = "
    SELECT
        ua.id,
        ua.username,
        ua.password,
        ua.role,
        ua.tool_id,
        at.tool_name
    FROM user_accounts ua
    LEFT JOIN ai_tools at ON at.id = ua.tool_id
    WHERE ua.username = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {

    $user = $result->fetch_assoc();

    if ($password == $user['password']) {

        echo json_encode([
            "status" => true,
            "message" => "Login Successful",
            "user_id" => $user['id'],
            "username" => $user['username'],
            "role" => $user['role'],
            "tool_id" => $user['tool_id'],
            "tool_name" => $user['tool_name']
        ]);

    } else {

        echo json_encode([
            "status" => false,
            "message" => "Invalid Password"
        ]);
    }

} else {

    echo json_encode([
        "status" => false,
        "message" => "User Not Found"
    ]);
}

$stmt->close();
$conn->close();
