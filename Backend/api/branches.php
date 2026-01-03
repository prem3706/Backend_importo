<?php
include("../config/dbConnection.php");


$allowedOrigin = getenv("CORS_ORIGIN") ?: "*";

header("Access-Control-Allow-Origin: $allowedOrigin");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* =========================
   CREATE BRANCH (POST)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = json_decode(file_get_contents("php://input"), true);

    $branch_name = trim($data['branch_name'] ?? '');  // ✅ same as frontend
    $manager = $data['manager'] ?? '';
    $contact = $data['contact'] ?? '';
    $address = $data['address'] ?? '';
    $user_id = isset($data['user_id']) ? (int) $data['user_id'] : 0;

    if ($branch_name === '' || $user_id <= 0) {
        echo json_encode([
            "success" => false,
            "message" => "Branch name aur user_id required hai"
        ]);
        exit();
    }


    $stmt = $conn->prepare(
        "INSERT INTO branches (branch_name, manager, contact, address, user_id)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssssi", $branch_name, $manager, $contact, $address, $user_id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Branch created"]);
    } else {
        echo json_encode(["success" => false, "message" => $stmt->error]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

/* =========================
   READ BRANCH (GET)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // single branch (edit page ke liye)
    if (isset($_GET['branch_id']) && isset($_GET['user_id'])) {
        $branch_id = (int) $_GET['branch_id'];
        $user_id = (int) $_GET['user_id'];

        $stmt = $conn->prepare(
            "SELECT branch_id, branch_name, manager, contact, address
             FROM branches
             WHERE branch_id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $branch_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        if ($res) {
            echo json_encode($res);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Branch not found"
            ]);
        }

        $stmt->close();
        $conn->close();
        exit();
    }

    // list
    $user_id = $_GET['user_id'] ?? null;
    if (!$user_id) {
        echo json_encode([]);
        exit();
    }

    $stmt = $conn->prepare(
        "SELECT branch_id, branch_name, manager, contact, address
         FROM branches WHERE user_id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $branches = [];
    while ($row = $result->fetch_assoc()) {
        $branches[] = $row;
    }

    echo json_encode($branches);

    $stmt->close();
    $conn->close();
    exit();
}

/* =========================
   UPDATE BRANCH (PUT) ✅
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {

    $data = json_decode(file_get_contents("php://input"), true);

    $branch_id = $data['branch_id'] ?? null;
    $branch_name = $data['branch_name'] ?? '';
    $manager = $data['manager'] ?? '';
    $contact = $data['contact'] ?? '';
    $address = $data['address'] ?? '';
    $user_id = $data['user_id'] ?? null;

    if (!$branch_id || !$user_id) {
        echo json_encode([
            "success" => false,
            "message" => "branch_id aur user_id required hai"
        ]);
        exit();
    }

    $stmt = $conn->prepare(
        "UPDATE branches SET
            branch_name = ?,
            manager = ?,
            contact = ?,
            address = ?
         WHERE branch_id = ? AND user_id = ?"
    );

    $stmt->bind_param(
        "ssssii",
        $branch_name,
        $manager,
        $contact,
        $address,
        $branch_id,
        $user_id
    );

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Branch updated"]);
    } else {
        echo json_encode(["success" => false, "message" => $stmt->error]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

/* =========================
   DELETE BRANCH (DELETE)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    $branch_id = $_GET['branch_id'] ?? null;

    if (!$branch_id) {
        echo json_encode([
            "success" => false,
            "message" => "branch_id required"
        ]);
        exit();
    }

    $stmt = $conn->prepare("DELETE FROM branches WHERE branch_id = ?");
    $stmt->bind_param("i", $branch_id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Branch deleted"]);
    } else {
        echo json_encode(["success" => false, "message" => $stmt->error]);
    }

    $stmt->close();
    $conn->close();
    exit();
}
?>