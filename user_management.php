<?php

header("Content-Type: application/json");

require_once("config.php");

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    echo json_encode([
        "status" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

$action = trim($_POST['action'] ?? '');
$username = trim($_POST['username'] ?? '');

if ($action === '') {
    echo json_encode([
        "status" => false,
        "message" => "Action is required"
    ]);
    exit;
}

if (in_array($action, ["create", "update", "delete"], true) && $username === '') {
    echo json_encode([
        "status" => false,
        "message" => "Username is required"
    ]);
    exit;
}

function userExists($conn, $username) {
    $sql = "SELECT id FROM user_accounts WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

if ($action === "create") {
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'USER');

    if ($password === '') {
        echo json_encode([
            "status" => false,
            "message" => "Password is required"
        ]);
        exit;
    }

    $allowedRoles = ["USER", "ADMIN"];
    if (!in_array($role, $allowedRoles, true)) {
        echo json_encode([
            "status" => false,
            "message" => "Invalid role"
        ]);
        exit;
    }

    if (userExists($conn, $username)) {
        echo json_encode([
            "status" => false,
            "message" => "User already exists"
        ]);
        exit;
    }

    $sql = "INSERT INTO user_accounts (username, password, role) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $username, $password, $role);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => true,
            "message" => "User created successfully",
            "user_id" => $stmt->insert_id,
            "username" => $username,
            "role" => $role
        ]);
    } else {
        echo json_encode([
            "status" => false,
            "message" => "Failed to create user"
        ]);
    }

    $stmt->close();
    $conn->close();
    exit;
}

if ($action === "update") {
    $newUsername = trim($_POST['new_username'] ?? '');

    if ($newUsername === '') {
        echo json_encode([
            "status" => false,
            "message" => "New username is required"
        ]);
        exit;
    }

    if (!userExists($conn, $username)) {
        echo json_encode([
            "status" => false,
            "message" => "User not found"
        ]);
        exit;
    }

    if ($username !== $newUsername && userExists($conn, $newUsername)) {
        echo json_encode([
            "status" => false,
            "message" => "New username already exists"
        ]);
        exit;
    }

    $sql = "UPDATE user_accounts SET username = ? WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $newUsername, $username);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => true,
            "message" => "User updated successfully",
            "username" => $newUsername
        ]);
    } else {
        echo json_encode([
            "status" => false,
            "message" => "Failed to update user"
        ]);
    }

    $stmt->close();
    $conn->close();
    exit;
}

if ($action === "delete") {
    if (!userExists($conn, $username)) {
        echo json_encode([
            "status" => false,
            "message" => "User not found"
        ]);
        exit;
    }

    $sql = "DELETE FROM user_accounts WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => true,
            "message" => "User deleted successfully",
            "username" => $username
        ]);
    } else {
        echo json_encode([
            "status" => false,
            "message" => "Failed to delete user"
        ]);
    }

    $stmt->close();
    $conn->close();
    exit;
}
if ($action === "list") {
    $sql = "SELECT id, username, role FROM user_accounts ORDER BY id DESC";
    $result = $conn->query($sql);

    $users = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }

    echo json_encode([
        "status" => true,
        "users" => $users
    ]);

    $conn->close();
    exit;
}
echo json_encode([
    "status" => false,
    "message" => "Invalid action"
]);

$conn->close();