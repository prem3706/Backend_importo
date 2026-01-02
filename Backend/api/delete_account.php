<?php
// api/delete_account.php
session_start();
include("../config/dbConnection.php");

header("Content-Type: application/json");
$allowed_origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:3000';
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/* ================= HELPERS ================= */
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function error($msg, $code = 400) {
    respond(["success" => false, "message" => $msg], $code);
}

/* ================= AUTH CHECK ================= */
if (empty($_SESSION['user_id'])) {
    error("Not authenticated", 401);
}
$user_id = (int)$_SESSION['user_id'];

/* ================= INPUT ================= */
$input = json_decode(file_get_contents("php://input"), true);
if (!$input || empty($input['password'])) {
    error("Password is required");
}
$password = $input['password'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    /* ================= FETCH USER ================= */
    $stmt = $conn->prepare("SELECT password, photo FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        error("User not found", 404);
    }

    if (!password_verify($password, $user['password'])) {
        error("Incorrect password", 403);
    }

    /* ================= TRANSACTION ================= */
    $conn->begin_transaction();

    /* 🔥 DELETE CHILD DATA FIRST (VERY IMPORTANT) */
    // agar user_id reference hai in tables me
    $conn->query("DELETE FROM lr_goods WHERE lr_id IN (SELECT lr_id FROM lrs WHERE user_id = $user_id)");
    $conn->query("DELETE FROM lrs WHERE user_id = $user_id");
    $conn->query("DELETE FROM branches WHERE user_id = $user_id");
    $conn->query("DELETE FROM companies WHERE user_id = $user_id");
    $conn->query("DELETE FROM vehicles WHERE user_id = $user_id");
    $conn->query("DELETE FROM goods WHERE user_id = $user_id");

    /* ================= DELETE USER PHOTO ================= */
    if (!empty($user['photo'])) {
        $path = __DIR__ . "/../uploads/users/" . $user['photo'];
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /* ================= DELETE USER ================= */
    $dstmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $dstmt->bind_param("i", $user_id);
    $dstmt->execute();
    $dstmt->close();

    $conn->commit();

    /* ================= DESTROY SESSION ================= */
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();

    respond([
        "success" => true,
        "message" => "Account deleted successfully"
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    respond([
        "success" => false,
        "message" => "Server error",
        "debug" => $e->getMessage() // ❗ production me hata dena
    ], 500);
}
