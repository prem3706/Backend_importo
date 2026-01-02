<?php
session_start(); 
$allowed_origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:3000';
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Credentials: true");

include("../config/dbConnection.php");

// Receive JSON
$data = json_decode(file_get_contents("php://input"), true);

if (isset($data["email"], $data["password"])) {
    $email = $conn->real_escape_string($data["email"]);
    $password = $data["password"];

    // Check user
    $sql = "SELECT * FROM users WHERE email='$email' LIMIT 1";
    $result = $conn->query($sql);

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user["password"])) {

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["transportName"] = $user["transportName"];

            echo json_encode([
                "success" => true,
                "message" => "Login successful",
                "user_id" => $user["id"]
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Invalid password"
            ]);
        }
    } else {
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "message" => "Invalid input"
    ]);
}
?>
