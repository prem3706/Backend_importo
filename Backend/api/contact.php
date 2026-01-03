<?php

header("Content-Type: application/json");

header("Access-Control-Allow-Origin: *");
include("../config/dbConnection.php");

$name = $_POST['contact_name'] ?? '';
$email = $_POST['contact_email'] ?? '';
$message = $_POST['contact_msg'] ?? '';

if ($name == "" || $email == "" || $message == "") {
    echo json_encode(["status" => "error", "message" => "Missing fields"]);
    exit;
}

$sql = "INSERT INTO contact (contact_name, contact_email, contact_msg) VALUES ('$name', '$email', '$message')";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => "success", "message" => "Message send successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to save: " . $conn->error]);
}
?>