<?php
include("../config/dbConnection.php");

header("Content-Type: application/json");

$allowedOrigin = getenv("CORS_ORIGIN") ?: "*";

header("Access-Control-Allow-Origin: $allowedOrigin");
header("Access-Control-Allow-Methods: PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/* =========================
   Helper functions
   ========================= */
function get_json()
{
    return json_decode(file_get_contents("php://input"), true);
}

function respond($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/* =========================
   ONLY PUT ALLOWED
   ========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    respond(["success" => false, "message" => "Invalid method"], 405);
}

$data = get_json();

/* =========================
   Required IDs
   ========================= */
$lr_id = intval($data['lr_id'] ?? 0);
$user_id = intval($data['user_id'] ?? 0);

if ($lr_id <= 0 || $user_id <= 0) {
    respond(["success" => false, "message" => "lr_id & user_id required"], 400);
}

/* =========================
   Transaction start
   ========================= */
$conn->begin_transaction();

try {
    /* =========================
       UPDATE LR (TABLE: lrs)
       ========================= */
    $stmt = $conn->prepare("
        UPDATE lrs SET
            lr_number = ?,
            date = ?,
            consignor_id = ?,
            consignor_name = ?,
            consignor_gst = ?,
            consignee_id = ?,
            consignee_name = ?,
            consignee_gst = ?,
            vehicle_id = ?,
            vehicle_no = ?,
            source_branch = ?,
            destination_branch = ?,
            total_packages = ?,
            total_value = ?
        WHERE lr_id = ? AND user_id = ?
    ");

    /* Variables (IMPORTANT: no direct expressions) */
    $lrNumber = $data['lr_number'];
    $date = $data['date'];
    $consignorId = intval($data['consignor_id']);
    $consignorName = $data['consignor'];
    $consignorGst = $data['consignor_gst'];
    $consigneeId = intval($data['consignee_id']);
    $consigneeName = $data['consignee'];
    $consigneeGst = $data['consignee_gst'];
    $vehicleId = intval($data['vehicle_id']);
    $vehicleNo = $data['vehicle'];
    $sourceBranch = $data['source'];
    $destinationBranch = $data['destination'];
    $totalPackages = intval($data['packages']);
    $totalValue = floatval($data['total']);
    $lrIdVar = $lr_id;
    $userIdVar = $user_id;

    $stmt->bind_param(
        "ssissississsidii",
        $lrNumber,
        $date,
        $consignorId,
        $consignorName,
        $consignorGst,
        $consigneeId,
        $consigneeName,
        $consigneeGst,
        $vehicleId,
        $vehicleNo,
        $sourceBranch,
        $destinationBranch,
        $totalPackages,
        $totalValue,
        $lrIdVar,
        $userIdVar
    );

    $stmt->execute();
    if ($stmt->errno) {
        throw new Exception($stmt->error);
    }
    $stmt->close();

    /* =========================
       RESET GOODS
       ========================= */
    $dstmt = $conn->prepare("DELETE FROM lr_goods WHERE lr_id = ?");
    $dstmt->bind_param("i", $lr_id);
    $dstmt->execute();
    $dstmt->close();

    /* =========================
       INSERT GOODS AGAIN
       ========================= */
    if (!empty($data['goods']) && is_array($data['goods'])) {

        $gstmt = $conn->prepare("
            INSERT INTO lr_goods
            (lr_id, goods_id, name, qty, weight, price)
            VALUES (?,?,?,?,?,?)
        ");

        foreach ($data['goods'] as $g) {

            $goods_id = $g['goods_id'] ?? null;
            $name = trim($g['name'] ?? '');

            if (empty($goods_id)) {

                if ($name === '') {
                    throw new Exception("Goods name is required");
                }

                // 🔥 AUTO CREATE GOODS
                $cstmt = $conn->prepare(
                    "INSERT INTO goods (name, user_id) VALUES (?, ?)"
                );
                $cstmt->bind_param("si", $name, $user_id);
                $cstmt->execute();
                $goods_id = $cstmt->insert_id;
                $cstmt->close();
            }


            $gstmt->bind_param(
                "iisisd",
                $lr_id,
                $goods_id,
                $g['name'],
                $g['qty'],
                $g['weight'],
                $g['price']
            );

            $gstmt->execute();
        }

        $gstmt->close();
    }

    /* =========================
       COMMIT
       ========================= */
    $conn->commit();

    respond(["success" => true, "message" => "LR updated successfully"]);

} catch (Exception $e) {
    $conn->rollback();
    respond([
        "success" => false,
        "message" => $e->getMessage()
    ], 500);
}
?>