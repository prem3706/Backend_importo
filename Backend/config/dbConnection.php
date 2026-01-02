<?php

$host = 'db';          // 🔥 Docker service name (IMPORTANT)
$username = 'root';
$password = 'root';
$dbname = 'test_project';

$conn = new mysqli(
    $host,
    $username,
    $password,
    $dbname
);

if ($conn->connect_error) {
    die(json_encode([
        "status" => "error",
        "message" => "DB Connection failed",
        "error" => $conn->connect_error
    ]));
}
?>
