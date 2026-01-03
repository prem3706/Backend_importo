<?php
include("../config/dbConnection.php");

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, DELETE, PUT, OPTIONS");

function get_json() {
    return json_decode(file_get_contents("php://input"), true);
}

/* -------------------- OPTIONS (CORS preflight) -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Return 200 for preflight
    http_response_code(200);
    exit();
}

/* -------------------- CREATE (POST) -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = get_json();

    $name = $data['name'] ?? '';
    $weight = $data['weight'] ?? '';
    $description = $data['description'] ?? '';
    $user_id = $data['user_id'] ?? null;

    if (!$name || !$user_id) {
        echo json_encode(["success" => false, "message" => "Name and user_id required"]);
        exit();
    }

    $q = $conn->prepare("INSERT INTO goods (name, weight, description, user_id) VALUES (?, ?, ?, ?)");
    $q->bind_param("sssi", $name, $weight, $description, $user_id);

    if ($q->execute()) {
        echo json_encode(["success" => true, "goods_id" => $q->insert_id]);
    } else {
        echo json_encode(["success" => false, "message" => $q->error]);
    }

    $q->close();
    $conn->close();
    exit();
}

/* -------------------- UPDATE (PUT) -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = get_json();

    $goods_id = $data['goods_id'] ?? null;
    if (!$goods_id) {
        echo json_encode(["success" => false, "message" => "goods_id required"]);
        exit();
    }

    $name = $data['name'] ?? '';
    $weight = $data['weight'] ?? '';
    $description = $data['description'] ?? '';

    $q = $conn->prepare("UPDATE goods SET name=?, weight=?, description=? WHERE goods_id=?");
    $q->bind_param("sssi", $name, $weight, $description, $goods_id);

    if ($q->execute()) echo json_encode(["success" => true]);
    else echo json_encode(["success" => false, "message" => $q->error]);

    $q->close();
    $conn->close();
    exit();
}

/* -------------------- DELETE -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str(file_get_contents("php://input"), $_D);
    $goods_id = $_D['goods_id'] ?? null;

    if (!$goods_id) {
        echo json_encode(["success" => false, "message" => "goods_id required"]);
        exit();
    }

    $q = $conn->prepare("DELETE FROM goods WHERE goods_id=?");
    $q->bind_param("i", $goods_id);
    $ok = $q->execute();

    echo json_encode(["success" => (bool)$ok]);
    $q->close();
    $conn->close();
    exit();
}

/* -------------------- GET (SINGLE + LIST + SEARCH) -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // single record by goods_id
    if (isset($_GET['goods_id']) && $_GET['goods_id'] !== '') {
        $goods_id = intval($_GET['goods_id']);
        $stmt = $conn->prepare("SELECT goods_id, name, weight, description, user_id, created_at FROM goods WHERE goods_id = ?");
        $stmt->bind_param("i", $goods_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        echo json_encode($row ?: (object)[]);
        $stmt->close();
        $conn->close();
        exit();
    }

    // otherwise list by user_id (optionally search by q)
    $user_id = $_GET['user_id'] ?? null;
    $qParam = $_GET['q'] ?? null;

    if (!$user_id) {
        echo json_encode([]);
        $conn->close();
        exit();
    }

    if ($qParam) {
        $like = "%{$qParam}%";
        $stmt = $conn->prepare("SELECT goods_id, name, weight, description, user_id, created_at FROM goods WHERE user_id=? AND name LIKE ? ORDER BY name ASC");
        $stmt->bind_param("is", $user_id, $like);
    } else {
        $stmt = $conn->prepare("SELECT goods_id, name, weight, description, user_id, created_at FROM goods WHERE user_id=? ORDER BY name ASC");
        $stmt->bind_param("i", $user_id);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;

    echo json_encode($rows);
    $stmt->close();
    $conn->close();
    exit();
}
?>
