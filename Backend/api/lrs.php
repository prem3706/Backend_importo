<?php
include("../config/dbConnection.php");

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= HELPERS ================= */

function get_json()
{
    return json_decode(file_get_contents("php://input"), true);
}

function respond($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit();
}

/* =====================================================
   AUTO NEXT LR NUMBER
   ===================================================== */
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['action'])
    && $_GET['action'] === 'next_lr_number'
) {
    $user_id = (int) ($_GET['user_id'] ?? 0);
    if ($user_id <= 0) {
        respond(["success" => false, "message" => "Invalid user_id"], 400);
    }

    $stmt = $conn->prepare(
        "SELECT MAX(lr_number) AS last_lr FROM lrs WHERE user_id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = ((int) ($row['last_lr'] ?? 0)) + 1;
    respond(["success" => true, "next_lr_number" => $next]);
}

/* =====================================================
   GET (LIST / SINGLE)
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $lr_id = (int) ($_GET['lr_id'] ?? 0);
    $user_id = (int) ($_GET['user_id'] ?? 0);

    // ---- SINGLE LR ----
    if ($lr_id > 0 && $user_id > 0) {

        $stmt = $conn->prepare(
            "SELECT * FROM lrs WHERE lr_id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $lr_id, $user_id);
        $stmt->execute();
        $lr = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lr) {
            respond(["success" => false, "message" => "LR not found"], 404);
        }

        $gstmt = $conn->prepare(
            "SELECT id, lr_id, goods_id, name, qty, weight, price
             FROM lr_goods WHERE lr_id = ? ORDER BY id ASC"
        );
        $gstmt->bind_param("i", $lr_id);
        $gstmt->execute();
        $lr['goods'] = $gstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $gstmt->close();

        respond($lr);
    }

    // ---- LIST ----
    if ($user_id > 0) {
        $stmt = $conn->prepare(
            "SELECT * FROM lrs WHERE user_id = ? ORDER BY created_at DESC"
        );
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        respond($rows);
    }

    respond([]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = get_json();

    // ✅ user_id ko variable me lo
    $user_id = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    if ($user_id <= 0) {
        respond(["success" => false, "message" => "user_id required"], 400);
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "INSERT INTO lrs (
                lr_number, date, user_id,
                consignor_id, consignor_name, consignor_gst,
                consignee_id, consignee_name, consignee_gst,
                vehicle_id, vehicle_no,
                driver_id, driver_name,
                source_branch, destination_branch,
                total_packages, total_value
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $stmt->bind_param(
            "ssiississisisssid",
            $data['lr_number'],
            $data['date'],
            $user_id,                    // ✅ yahan variable
            $data['consignor_id'],
            $data['consignor'],
            $data['consignor_gst'],
            $data['consignee_id'],
            $data['consignee'],
            $data['consignee_gst'],
            $data['vehicle_id'],
            $data['vehicle'],
            $data['driver_id'],
            $data['driver'],
            $data['source'],
            $data['destination'],
            $data['packages'],
            $data['total']
        );

        $stmt->execute();
        $lr_id = $stmt->insert_id;
        $stmt->close();

        // GOODS
        if (!empty($data['goods'])) {
            $gstmt = $conn->prepare(
                "INSERT INTO lr_goods (lr_id, goods_id, name, qty, weight, price)
                 VALUES (?,?,?,?,?,?)"
            );

            foreach ($data['goods'] as $g) {
                $goods_id = $g['goods_id'] ?? null;
                $name     = trim($g['name'] ?? '');

                if (empty($goods_id)) {
                    if ($name === '') {
                        throw new Exception("Goods name is required");
                    }

                    // 🔥 AUTO CREATE GOODS – yahan bhi $user_id use karo
                    $cstmt = $conn->prepare(
                        "INSERT INTO goods (name, user_id) VALUES (?, ?)"
                    );
                    $cstmt->bind_param("si", $name, $user_id);   // ✅ FIX
                    $cstmt->execute();
                    $goods_id = $cstmt->insert_id;
                    $cstmt->close();
                }

                $qty    = (int)($g['qty'] ?? 0);
                $weight = (float)($g['weight'] ?? 0);
                $price  = (float)($g['price'] ?? 0);

                $gstmt->bind_param(
                    "iisisd",
                    $lr_id,
                    $goods_id,
                    $name,
                    $qty,
                    $weight,
                    $price
                );

                $gstmt->execute();
            }

            $gstmt->close();
        }

        $conn->commit();
        respond(["success" => true, "lr_id" => $lr_id], 201);

    } catch (Exception $e) {
        $conn->rollback();
        respond(["success" => false, "message" => $e->getMessage()], 500);
    }
}


// /* =====================================================
//    PUT (UPDATE LR)  ✅ DRIVER NOT UPDATED
//    ===================================================== */
// if ($_SERVER['REQUEST_METHOD'] === 'PUT') {

//     $data = get_json();
//     $lr_id = (int) ($data['lr_id'] ?? 0);
//     $user_id = (int) ($data['user_id'] ?? 0);

//     if ($lr_id <= 0 || $user_id <= 0) {
//         respond(["success" => false, "message" => "lr_id & user_id required"], 400);
//     }

//     $conn->begin_transaction();

//     try {
//         $stmt = $conn->prepare(
//             "UPDATE lrs SET
//                 lr_number=?,
//                 date=?,
//                 consignor_id=?,
//                 consignor_name=?,
//                 consignor_gst=?,
//                 consignee_id=?,
//                 consignee_name=?,
//                 consignee_gst=?,
//                 vehicle_id=?,
//                 vehicle_no=?,
//                 source_branch=?,
//                 destination_branch=?,
//                 total_packages=?,
//                 total_value=?
//              WHERE lr_id=? AND user_id=?"
//         );

//         $stmt->bind_param(
//             "ssisssisssssidii",
//             $data['lr_number'],
//             $data['date'],
//             $data['consignor_id'],
//             $data['consignor'],
//             $data['consignor_gst'],
//             $data['consignee_id'],
//             $data['consignee'],
//             $data['consignee_gst'],
//             $data['vehicle_id'],
//             $data['vehicle'],
//             $data['source'],
//             $data['destination'],
//             $data['packages'],
//             $data['total'],
//             $lr_id,
//             $user_id
//         );

//         $stmt->execute();
//         if ($stmt->errno) {
//             throw new Exception($stmt->error);
//         }
//         $stmt->close();

//         // RESET GOODS
//         $dstmt = $conn->prepare("DELETE FROM lr_goods WHERE lr_id = ?");
//         $dstmt->bind_param("i", $lr_id);
//         $dstmt->execute();
//         $dstmt->close();

//         // INSERT GOODS AGAIN
//         if (!empty($data['goods'])) {
//             $gstmt = $conn->prepare(
//                 "INSERT INTO lr_goods (lr_id, goods_id, name, qty, weight, price)
//                  VALUES (?,?,?,?,?,?)"
//             );

//             foreach ($data['goods'] as $g) {

//                 $goods_id = $g['goods_id'] ?? null;
//                 $name = trim($g['name'] ?? '');
//                 // ✅ VALIDATION (YAHI ADD KARO)
//                 if (empty($goods_id)) {
//                     // 🆕 manual goods → auto create
//                     if ($name === '') {
//                         throw new Exception("Goods name required");
//                     }

//                     $cstmt = $conn->prepare(
//                         "INSERT INTO goods (name, user_id) VALUES (?, ?)"
//                     );
//                     $cstmt->bind_param("si", $name, $data['user_id']);
//                     $cstmt->execute();
//                     $goods_id = $cstmt->insert_id;
//                     $cstmt->close();
//                 }

//                 $gstmt->bind_param(
//                     "iisisd",
//                     $lr_id,
//                     $goods_id,
//                     $g['name'],
//                     $g['qty'],
//                     $g['weight'],
//                     $g['price']
//                 );

//                 $gstmt->execute();
//             }

//             $gstmt->close();
//         }

//         $conn->commit();
//         respond(["success" => true]);

//     } catch (Exception $e) {
//         $conn->rollback();
//         respond(["success" => false, "message" => $e->getMessage()], 500);
//     }
// }

/* =====================================================
   DELETE (OPTIONAL)
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    $lr_id = (int) ($_GET['lr_id'] ?? 0);
    $user_id = (int) ($_GET['user_id'] ?? 0);

    if ($lr_id <= 0 || $user_id <= 0) {
        respond(["success" => false, "message" => "lr_id & user_id required"], 400);
    }

    $stmt = $conn->prepare(
        "DELETE FROM lrs WHERE lr_id = ? AND user_id = ?"
    );
    $stmt->bind_param("ii", $lr_id, $user_id);

    if ($stmt->execute()) {
        respond(["success" => true]);
    } else {
        respond(["success" => false, "message" => $stmt->error], 500);
    }
}
