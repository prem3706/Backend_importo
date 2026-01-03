<?php
// api/user_profile.php
session_start();
include("../config/dbConnection.php");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (!isset($_SESSION['user_id']) || intval($_SESSION['user_id']) <= 0) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not logged in"]);
    exit();
}

$user_id = intval($_SESSION['user_id']);

$sql = "SELECT id, transportName, transportArea, city, state, country, mobile, email, photo FROM users WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "DB prepare failed: " . $conn->error]);
    exit();
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(["success" => false, "message" => "User not found"]);
    exit();
}

// ensure mobile key exists
if (!isset($user['mobile']) || $user['mobile'] === null) {
    $user['mobile'] = "";
}

// Build photo_url robustly
// Assumes uploads are accessible at: http://<host>/<project_path>/Backend/uploads/users/<file>
// We'll derive "<project_path>/Backend" from SCRIPT_NAME (e.g. "/my_app/Backend/api/user_profile.php")
$photo_url = null;
if (!empty($user['photo'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];

    // SCRIPT_NAME => /my_app/Backend/api/user_profile.php
    $scriptPath = $_SERVER['SCRIPT_NAME'];
    // go up two levels to /my_app/Backend
    $backendBase = dirname(dirname($scriptPath)); // "/my_app/Backend"

    // final URL: http(s)://host + backendBase + "/uploads/users/" + filename
    $uploadsPath = rtrim($backendBase, '/') . '/uploads/users/' . ltrim($user['photo'], '/');
    $photo_url = $scheme . '://' . $host . $uploadsPath;
}

$user['photo_url'] = $photo_url;

echo json_encode(["success" => true, "data" => $user]);
exit();
