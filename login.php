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

$sql = "SELECT * FROM user_accounts WHERE username = ?";
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
            "role" => $user['role']
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