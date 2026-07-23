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

function getActiveTool($conn, $toolId) {
    $sql = "SELECT id, tool_name FROM ai_tools WHERE id = ? AND is_active = 1 LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $toolId);
    $stmt->execute();
    $result = $stmt->get_result();
    $tool = $result->fetch_assoc();
    $stmt->close();
    return $tool;
}

if ($action === "create") {
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? 'USER');
    $toolId = intval($_POST['tool_id'] ?? 0);

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

    $tool = getActiveTool($conn, $toolId);
    if (!$tool) {
        echo json_encode([
            "status" => false,
            "message" => "Valid active tool_id is required"
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

    $sql = "INSERT INTO user_accounts (username, password, role, tool_id) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $username, $password, $role, $toolId);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => true,
            "message" => "User created successfully",
            "user_id" => $stmt->insert_id,
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

    $stmt->close();
    $conn->close();
    exit;
}

if ($action === "update") {
    $newUsername = trim($_POST['new_username'] ?? '');
    $newRole = trim($_POST['role'] ?? '');
    $toolIdInput = $_POST['tool_id'] ?? null;

    if ($newUsername === '' && $newRole === '' && $toolIdInput === null) {
        echo json_encode([
            "status" => false,
            "message" => "At least one field to update is required"
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

    if ($newUsername !== '' && $username !== $newUsername && userExists($conn, $newUsername)) {
        echo json_encode([
            "status" => false,
            "message" => "New username already exists"
        ]);
        exit;
    }

    $fields = [];
    $types = "";
    $values = [];

    if ($newUsername !== '') {
        $fields[] = "username = ?";
        $types .= "s";
        $values[] = $newUsername;
    }

    if ($newRole !== '') {
        $allowedRoles = ["USER", "ADMIN"];
        if (!in_array($newRole, $allowedRoles, true)) {
            echo json_encode([
                "status" => false,
                "message" => "Invalid role"
            ]);
            exit;
        }

        $fields[] = "role = ?";
        $types .= "s";
        $values[] = $newRole;
    }

    $tool = null;
    if ($toolIdInput !== null && $toolIdInput !== '') {
        $toolId = intval($toolIdInput);
        $tool = getActiveTool($conn, $toolId);

        if (!$tool) {
            echo json_encode([
                "status" => false,
                "message" => "Valid active tool_id is required"
            ]);
            exit;
        }

        $fields[] = "tool_id = ?";
        $types .= "i";
        $values[] = $toolId;
    }

    $sql = "UPDATE user_accounts SET " . implode(", ", $fields) . " WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $types .= "s";
    $values[] = $username;
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        $updatedUsername = $newUsername !== '' ? $newUsername : $username;
        echo json_encode([
            "status" => true,
            "message" => "User updated successfully",
            "username" => $updatedUsername,
            "role" => $newRole !== '' ? $newRole : null,
            "tool_id" => ($toolIdInput !== null && $toolIdInput !== '') ? $toolId : null,
            "tool_name" => $tool ? $tool['tool_name'] : null
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
    $sql = "
        SELECT
            ua.id,
            ua.username,
            ua.role,
            ua.tool_id,
            at.tool_name
        FROM user_accounts ua
        LEFT JOIN ai_tools at ON at.id = ua.tool_id
        ORDER BY ua.id DESC
    ";
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
