<?php
// api/change_password.php
session_start();
include("../config/dbConnection.php"); // must set $conn (mysqli)

// CORS + JSON
$allowed_origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:3000';
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

function bad($msg, $code = 400) {
    respond(["success" => false, "message" => $msg], $code);
}

// require session user
if (empty($_SESSION['user_id'])) {
    bad("Not authenticated", 401);
}
$user_id = intval($_SESSION['user_id']);

// accept JSON body
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) bad("Invalid JSON payload", 400);

$current = isset($input['current_password']) ? $input['current_password'] : null;
$new = isset($input['new_password']) ? $input['new_password'] : null;

if (!$current || !$new) bad("Both current_password and new_password are required", 400);

// basic validation for new password — adjust rules if needed
if (strlen($new) < 8) bad("New password must be at least 8 characters", 400);
// optional: require number and special char
if (!preg_match('/[0-9]/', $new) || !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new)) {
    bad("New password must include a number and a special character", 400);
}

try {
    // fetch stored hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) bad("User not found", 404);

    $hash = $res['password'] ?? '';

    if (!password_verify($current, $hash)) {
        bad("Current password is incorrect", 403);
    }

    // Hash new password and update
    $newHash = password_hash($new, PASSWORD_BCRYPT);

    $ustmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    if (!$ustmt) throw new Exception("DB prepare failed: " . $conn->error);
    $ustmt->bind_param("si", $newHash, $user_id);
    if (!$ustmt->execute()) {
        $err = $ustmt->error;
        $ustmt->close();
        throw new Exception("Update failed: " . $err);
    }
    $ustmt->close();

    respond(["success" => true, "message" => "Password changed successfully"]);
} catch (Exception $ex) {
    respond(["success" => false, "message" => $ex->getMessage()], 500);
}
