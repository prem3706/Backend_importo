<?php
// api/update_profile.php
session_start();
include("../config/dbConnection.php"); // set $conn (mysqli)

// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

function bad($msg, $code = 400) {
    respond(["success" => false, "message" => $msg], $code);
}

// simple mobile validator (compatible with Indian numbers)
function valid_mobile($m) {
    $m = trim($m);
    if ($m === "") return false;
    $clean = preg_replace('/[\s\-\(\)+]/', '', $m); // remove +-() spaces dashes
    if (preg_match('/^91[6-9]\d{9}$/', $clean)) return true;
    if (preg_match('/^[6-9]\d{9}$/', $clean)) return true;
    return false;
}

// must be POST multipart/form-data
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad("Only POST allowed", 405);

// Accept either session user id or form field id
$user_id = null;
if (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) {
    $user_id = intval($_SESSION['user_id']);
} elseif (isset($_POST['id']) && intval($_POST['id']) > 0) {
    $user_id = intval($_POST['id']);
} else {
    bad("User id not provided (session or form 'id')", 401);
}

// Collect form fields (they may be empty strings)
$transportName = isset($_POST['transportName']) ? trim($_POST['transportName']) : null;
$transportArea = isset($_POST['transportArea']) ? trim($_POST['transportArea']) : null;
$city = isset($_POST['city']) ? trim($_POST['city']) : null;
$state = isset($_POST['state']) ? trim($_POST['state']) : null;
$country = isset($_POST['country']) ? trim($_POST['country']) : null;
$mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : null;

// Validate mobile if provided (optional)
if ($mobile !== null && $mobile !== '') {
    if (!valid_mobile($mobile)) {
        bad("Invalid mobile number format");
    }
    // check uniqueness (exclude current user)
    $chk = $conn->prepare("SELECT id FROM users WHERE mobile = ? AND id <> ? LIMIT 1");
    if (!$chk) bad("DB prepare failed: " . $conn->error, 500);
    $chk->bind_param("si", $mobile, $user_id);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows > 0) {
        $chk->close();
        bad("Mobile already used by another account", 409);
    }
    $chk->close();
}

// Prepare updates
$sets = [];
$params = [];
$types = "";

// Only include fields that were provided (not null)
if ($transportName !== null) { $sets[] = "transportName = ?"; $types .= "s"; $params[] = $transportName; }
if ($transportArea !== null)  { $sets[] = "transportArea = ?";  $types .= "s"; $params[] = $transportArea; }
if ($city !== null)           { $sets[] = "city = ?";           $types .= "s"; $params[] = $city; }
if ($state !== null)          { $sets[] = "state = ?";          $types .= "s"; $params[] = $state; }
if ($country !== null)        { $sets[] = "country = ?";        $types .= "s"; $params[] = $country; }
if ($mobile !== null)         { $sets[] = "mobile = ?";         $types .= "s"; $params[] = $mobile; }

// Handle file upload (photo)
$uploadedFilename = null;
if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['photo'];
    // basic validation
    $allowed = ['jpg','jpeg','png','webp'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        bad("Invalid image format. Allowed: jpg,jpeg,png,webp");
    }

    // create upload dir if not exists
    $uploadDir = __DIR__ . "/../uploads/users";
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            bad("Failed to create upload directory", 500);
        }
    }

    // generate unique filename
    $uploadedFilename = "user_{$user_id}_" . time() . "." . $ext;
    $dest = $uploadDir . "/" . $uploadedFilename;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        bad("Failed to move uploaded file", 500);
    }

    // remove old photo if exists (we'll fetch its name)
    $oldstmt = $conn->prepare("SELECT photo FROM users WHERE id = ? LIMIT 1");
    if ($oldstmt) {
        $oldstmt->bind_param("i", $user_id);
        $oldstmt->execute();
        $r = $oldstmt->get_result()->fetch_assoc();
        $oldstmt->close();
        if (!empty($r['photo'])) {
            $oldPath = $uploadDir . "/" . $r['photo'];
            if (file_exists($oldPath)) @unlink($oldPath);
        }
    }

    // set photo column update
    $sets[] = "photo = ?";
    $types .= "s";
    $params[] = $uploadedFilename;
}

// Nothing to update?
if (empty($sets)) {
    // nothing provided, but maybe photo uploaded (handled above) - still check
    if ($uploadedFilename === null) {
        respond(["success" => true, "message" => "Nothing to update"]);
    }
}

// Build query
$types .= "i"; // for id at end
$params[] = $user_id;
$sql = "UPDATE users SET " . implode(", ", $sets) . " WHERE id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) bad("DB prepare failed: " . $conn->error, 500);

// dynamic bind_param
$bind_names[] = $types;
for ($i = 0; $i < count($params); $i++) {
    $bind_name = 'b' . $i;
    $$bind_name = $params[$i];
    $bind_names[] = &$$bind_name;
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    bad("Update failed: " . $err, 500);
}
$stmt->close();

// Return updated user record (including photo_url)
$q = $conn->prepare("SELECT id, transportName, transportArea, city, state, country, mobile, email, photo FROM users WHERE id = ? LIMIT 1");
$q->bind_param("i", $user_id);
$q->execute();
$user = $q->get_result()->fetch_assoc();
$q->close();

if ($user) {
    // build full photo url if exist
    if (!empty($user['photo'])) {
        $user['photo_url'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/../uploads/users/" . $user['photo'];
    } else {
        $user['photo_url'] = null;
    }
    respond(["success" => true, "message" => "Profile updated", "user" => $user]);
} else {
    bad("Updated but failed to fetch user", 500);
}
