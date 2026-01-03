<?php
// check_session.php (updated)
// returns { loggedIn: bool, transportName, email, mobile, photo_url }
// expects session cookie; make sure frontend requests withCredentials: true

session_start();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true");

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json");

// handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// include DB connection (must set $conn as mysqli)
include("../config/dbConnection.php");

if (!isset($_SESSION['user_id']) || intval($_SESSION['user_id']) <= 0) {
    echo json_encode(["loggedIn" => false]);
    exit;
}

$user_id = intval($_SESSION['user_id']);

// prepare and fetch user record (only needed columns)
$sql = "SELECT id, transportName, email, mobile, photo FROM users WHERE id = ? LIMIT 1";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user) {
        // session exists but user not found in DB
        echo json_encode(["loggedIn" => false]);
        exit;
    }

    // build absolute photo_url if photo column present
    $photo_url = null;
    if (!empty($user['photo'])) {
        // compute base URL (http/https + host + script dir)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        // path to this script, e.g. /my_app/Backend/api/check_session.php
        $scriptDir = dirname($_SERVER['PHP_SELF']);
        // expected uploads folder relative to API: ../uploads/users/<filename>
        // normalise and create final URL
        $uploadsPath = $scriptDir . "/../uploads/users/" . $user['photo'];
        // remove any double slashes
        $uploadsPath = preg_replace('#/+#','/',$uploadsPath);
        $photo_url = $scheme . "://" . $host . $uploadsPath;
    }

    // prefer session transportName if exists, otherwise DB value
    $transportName = $_SESSION['transportName'] ?? $user['transportName'] ?? "";

    echo json_encode([
        "loggedIn" => true,
        "transportName" => $transportName,
        "email" => $user['email'] ?? ($_SESSION['email'] ?? ""),
        "mobile" => $user['mobile'] ?? "",
        "photo_url" => $photo_url
    ]);
    exit;
} else {
    // prepare failed
    http_response_code(500);
    echo json_encode(["loggedIn" => false, "error" => "DB prepare failed: " . $conn->error]);
    exit;
}
