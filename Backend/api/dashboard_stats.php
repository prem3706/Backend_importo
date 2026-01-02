<?php
session_start();
include("../config/dbConnection.php");

header('Content-Type: application/json');
$allowed_origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:3000';
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function respond($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    respond(["success" => false, "message" => "Unauthorized"], 401);
}

$user_id = intval($_SESSION['user_id']);

$data = [
    "this_month_lrs" => 0,
    "total_branches" => 0,
    "total_companies" => 0,
    "monthly_lrs" => []   // <<-- chart data yaha aayega
];

try {

    /* ------------------ 1) This Month LRs (User Specific) ------------------ */
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt 
        FROM lrs 
        WHERE user_id = ? 
          AND YEAR(date) = YEAR(CURDATE()) 
          AND MONTH(date) = MONTH(CURDATE())
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $data['this_month_lrs'] = intval($r['cnt'] ?? 0);
    $stmt->close();

    /* ------------------ 2) Total Branches (User Specific) ------------------ */
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM branches WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $data['total_branches'] = intval($r['cnt'] ?? 0);
    $stmt->close();

    /* ------------------ 3) Total Companies (User Specific) ------------------ */
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM companies WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $data['total_companies'] = intval($r['cnt'] ?? 0);
    $stmt->close();

    /* ------------------ 4) Monthly LR counts for last 6 months ------------- */
    // yaha date condition optional hai; agar sirf last 6 months chahiye to uncomment WHERE wala part.
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(date, '%b')   AS month,   -- 'Jul'
            DATE_FORMAT(date, '%Y-%m') AS ym,
            COUNT(*)                  AS lrs
        FROM lrs
        WHERE user_id = ?
        GROUP BY ym, month
        ORDER BY ym DESC
        LIMIT 6
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // reverse so that oldest month first
    $rows = array_reverse($rows);

    // normalize types
    $monthly = [];
    foreach ($rows as $row) {
        $monthly[] = [
            'month' => $row['month'],
            'lrs' => intval($row['lrs'] ?? 0),
        ];
    }
    $data['monthly_lrs'] = $monthly;

    respond(["success" => true, "data" => $data]);

} catch (Exception $ex) {
    respond([
        "success" => false,
        "message" => "Server error",
        "error" => $ex->getMessage()
    ], 500);
}
