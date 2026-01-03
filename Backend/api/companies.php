<?php
include("../config/dbConnection.php");

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS, DELETE");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

/* ===================== POST : CREATE ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = json_decode(file_get_contents("php://input"), true);

  $company_name = trim($data['company_name'] ?? '');
  $contact = trim($data['contact'] ?? '');
  $email = trim($data['email'] ?? '');
  $gst = trim($data['gst'] ?? '');
  $address = trim($data['address'] ?? '');
  $user_id = (int)($data['user_id'] ?? 0);

  if (!$company_name || !$user_id) {
    echo json_encode(["success" => false, "message" => "Company name aur user id required hai"]);
    exit();
  }

  $stmt = $conn->prepare(
    "INSERT INTO companies (user_id, company_name, contact, email, gst, address)
     VALUES (?, ?, ?, ?, ?, ?)"
  );
  $stmt->bind_param("isssss", $user_id, $company_name, $contact, $email, $gst, $address);

  if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Company created"]);
  } else {
    echo json_encode(["success" => false, "message" => $stmt->error]);
  }

  $stmt->close();
  $conn->close();
  exit();
}

/* ===================== PUT : UPDATE ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
  $data = json_decode(file_get_contents("php://input"), true);

  $company_id = (int)($data['company_id'] ?? 0);
  $company_name = trim($data['company_name'] ?? '');
  $contact = trim($data['contact'] ?? '');
  $email = trim($data['email'] ?? '');
  $gst = trim($data['gst'] ?? '');
  $address = trim($data['address'] ?? '');
  $user_id = (int)($data['user_id'] ?? 0);

  if (!$company_id || !$company_name || !$user_id) {
    echo json_encode(["success" => false, "message" => "company_id, company_name, user_id required"]);
    exit();
  }

  $stmt = $conn->prepare(
    "UPDATE companies SET
        company_name = ?,
        contact = ?,
        email = ?,
        gst = ?,
        address = ?
     WHERE company_id = ? AND user_id = ?"
  );

  $stmt->bind_param(
    "sssssii",
    $company_name,
    $contact,
    $email,
    $gst,
    $address,
    $company_id,
    $user_id
  );

  if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Company updated"]);
  } else {
    echo json_encode(["success" => false, "message" => $stmt->error]);
  }

  $stmt->close();
  $conn->close();
  exit();
}

/* ===================== GET : LIST / SINGLE ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $user_id = (int)($_GET['user_id'] ?? 0);
  $company_id = (int)($_GET['company_id'] ?? 0);

  if ($company_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM companies WHERE company_id = ? LIMIT 1");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode($row ? $row : []);
    $stmt->close();
    $conn->close();
    exit();
  }

  if ($user_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM companies WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($rows);
    $stmt->close();
    $conn->close();
    exit();
  }

  echo json_encode([]);
  $conn->close();
  exit();
}

/* ===================== DELETE ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
  parse_str(file_get_contents("php://input"), $_DELETE);
  $company_id = (int)($_DELETE['company_id'] ?? $_GET['company_id'] ?? 0);

  if (!$company_id) {
    echo json_encode(["success" => false, "message" => "company_id required"]);
    exit();
  }

  $stmt = $conn->prepare("DELETE FROM companies WHERE company_id = ?");
  $stmt->bind_param("i", $company_id);

  if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Company deleted"]);
  } else {
    echo json_encode(["success" => false, "message" => $stmt->error]);
  }

  $stmt->close();
  $conn->close();
  exit();
}

http_response_code(405);
echo json_encode(["success" => false, "message" => "Method not allowed"]);
