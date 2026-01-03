<?php
include("../config/dbConnection.php");

header('Content-Type: application/json; charset=utf-8');
// Change origin if needed (replace with your frontend origin)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true");

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * vehicles.php
 * - GET    => list vehicles by user_id OR single vehicle by vehicle_id
 * - POST   => create vehicle
 * - PUT    => update vehicle
 * - DELETE => delete vehicle
 *
 * Expected table structure (example):
 *  vehicles (
 *    vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
 *    user_id INT NOT NULL,
 *    vehicle_no VARCHAR(100) NOT NULL,
 *    model VARCHAR(200),
 *    capacity VARCHAR(100),
 *    driver_name VARCHAR(200),
 *    driver_contact VARCHAR(100),
 *    note TEXT,
 *    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
 *  )
 */

// Helper: send json and exit
function send($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Single vehicle
        if (!empty($_GET['vehicle_id'])) {
            $vehicle_id = (int) $_GET['vehicle_id'];
            $stmt = $conn->prepare("SELECT vehicle_id, user_id, vehicle_no, model, capacity, driver_name, driver_contact, note, created_at, updated_at FROM vehicles WHERE vehicle_id = ? LIMIT 1");
            $stmt->bind_param("i", $vehicle_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $stmt->close();
            if ($row) {
                send(200, ["success" => true, "data" => $row]);
            } else {
                send(404, ["success" => false, "message" => "Vehicle not found"]);
            }
        }

        // List vehicles for a user
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
        if (!$user_id) {
            // return empty array to match front-end expectation when no user_id provided
            send(200, []);
        }

        $stmt = $conn->prepare("SELECT vehicle_id, user_id, vehicle_no, model, capacity, driver_name, driver_contact, note, created_at FROM vehicles WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) $rows[] = $r;
        $stmt->close();
        send(200, $rows);
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);

        $user_id = isset($data['user_id']) ? (int)$data['user_id'] : null;
        $vehicle_no = trim($data['vehicle_no'] ?? $data['vehicleNo'] ?? '');
        $model = trim($data['model'] ?? '');
        $capacity = trim($data['capacity'] ?? '');
        $driver_name = trim($data['driver_name'] ?? $data['driverName'] ?? '');
        $driver_contact = trim($data['driver_contact'] ?? $data['driverContact'] ?? '');
        $note = trim($data['note'] ?? '');

        if (!$user_id || !$vehicle_no) {
            send(400, ["success" => false, "message" => "user_id aur vehicle_no required hai"]);
        }

        $stmt = $conn->prepare("INSERT INTO vehicles (user_id, vehicle_no, model, capacity, driver_name, driver_contact, note) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            send(500, ["success" => false, "message" => "Prepare failed: " . $conn->error]);
        }
        $stmt->bind_param("issssss", $user_id, $vehicle_no, $model, $capacity, $driver_name, $driver_contact, $note);

        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $stmt->close();

            // fetch created row
            $res = $conn->prepare("SELECT vehicle_id, user_id, vehicle_no, model, capacity, driver_name, driver_contact, note, created_at FROM vehicles WHERE vehicle_id = ? LIMIT 1");
            $res->bind_param("i", $newId);
            $res->execute();
            $row = $res->get_result()->fetch_assoc();
            $res->close();

            send(201, ["success" => true, "message" => "Vehicle created", "data" => $row]);
        } else {
            $err = $stmt->error;
            $stmt->close();
            send(500, ["success" => false, "message" => "Insert failed: " . $err]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {

    $data = json_decode(file_get_contents("php://input"), true);

    $vehicle_id = isset($data['vehicle_id']) ? (int)$data['vehicle_id'] : 0;
    $user_id    = isset($data['user_id']) ? (int)$data['user_id'] : 0;

    if (!$vehicle_id || !$user_id) {
        send(400, [
            "success" => false,
            "message" => "vehicle_id aur user_id required hai"
        ]);
    }

    // 🔍 Check vehicle exists for this user
    $check = $conn->prepare(
        "SELECT * FROM vehicles WHERE vehicle_id = ? AND user_id = ? LIMIT 1"
    );
    $check->bind_param("ii", $vehicle_id, $user_id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$existing) {
        send(404, [
            "success" => false,
            "message" => "Vehicle not found"
        ]);
    }

    // 🧠 Values (fallback to existing if not sent)
    $vehicle_no     = trim($data['vehicle_no']     ?? $existing['vehicle_no']);
    $model          = trim($data['model']          ?? $existing['model']);
    $capacity       = trim($data['capacity']       ?? $existing['capacity']);
    $driver_name    = trim($data['driver_name']    ?? $existing['driver_name']);
    $driver_contact = trim($data['driver_contact'] ?? $existing['driver_contact']);
    $note           = trim($data['note']           ?? $existing['note']);

    // 🔄 Update
    $stmt = $conn->prepare(
        "UPDATE vehicles SET
            vehicle_no = ?,
            model = ?,
            capacity = ?,
            driver_name = ?,
            driver_contact = ?,
            note = ?
         WHERE vehicle_id = ? AND user_id = ?"
    );

    if (!$stmt) {
        send(500, [
            "success" => false,
            "message" => "Prepare failed: " . $conn->error
        ]);
    }

    $stmt->bind_param(
        "ssssssii",
        $vehicle_no,
        $model,
        $capacity,
        $driver_name,
        $driver_contact,
        $note,
        $vehicle_id,
        $user_id
    );

    if ($stmt->execute()) {
        $stmt->close();

        // 🔁 Return updated row
        $res = $conn->prepare(
            "SELECT vehicle_id, user_id, vehicle_no, model, capacity,
                    driver_name, driver_contact, note, created_at, updated_at
             FROM vehicles
             WHERE vehicle_id = ? LIMIT 1"
        );
        $res->bind_param("i", $vehicle_id);
        $res->execute();
        $row = $res->get_result()->fetch_assoc();
        $res->close();

        send(200, [
            "success" => true,
            "message" => "Vehicle updated successfully",
            "data" => $row
        ]);
    } else {
        $err = $stmt->error;
        $stmt->close();
        send(500, [
            "success" => false,
            "message" => "Update failed: " . $err
        ]);
    }
}


    if ($method === 'DELETE') {
        // support query param or body
        parse_str(file_get_contents("php://input"), $_DELETE);
        $vehicle_id = isset($_DELETE['vehicle_id']) ? (int)$_DELETE['vehicle_id'] : (isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0);
        if (!$vehicle_id) send(400, ["success" => false, "message" => "No vehicle_id provided"]);

        $stmt = $conn->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
        if (!$stmt) send(500, ["success" => false, "message" => "Prepare failed: " . $conn->error]);
        $stmt->bind_param("i", $vehicle_id);

        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected > 0) {
                send(200, ["success" => true, "message" => "Vehicle deleted"]);
            } else {
                send(404, ["success" => false, "message" => "Vehicle not found or already deleted"]);
            }
        } else {
            $err = $stmt->error;
            $stmt->close();
            send(500, ["success" => false, "message" => "Delete failed: " . $err]);
        }
    }

    // Method not allowed
    send(405, ["success" => false, "message" => "Method not allowed"]);
} catch (Exception $ex) {
    send(500, ["success" => false, "message" => "Server error: " . $ex->getMessage()]);
}

?>
