<?php
session_start();

// -------------------------------
// 1. Clear session variables
// -------------------------------
$_SESSION = [];
session_unset();

// -------------------------------
// 2. Destroy session completely
// -------------------------------
session_destroy();

// -------------------------------
// 3. Remove session cookie from browser
// -------------------------------
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    
    // overwrite cookie with old timestamp
    setcookie(
        session_name(), 
        '', 
        time() - 3600,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// -------------------------------
// 4. CORS + JSON headers
// -------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true");

header("Content-Type: application/json");

echo json_encode([
    "success" => true,
    "message" => "Logged out successfully"
]);
?>
