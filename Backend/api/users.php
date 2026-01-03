<?php
// api/users.php
include("../config/dbConnection.php"); // must provide $conn (mysqli)
session_start();

header('Content-Type: application/json');

$allowedOrigin = getenv("CORS_ORIGIN") ?: "*";

header("Access-Control-Allow-Origin: $allowedOrigin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function get_json() {
    return json_decode(file_get_contents("php://input"), true);
}
function bad($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(["success" => false, "message" => $msg]);
    exit();
}
function ok($data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(["success" => true], is_array($data) ? $data : ["data" => $data]));
    exit();
}

/* ------------------ Helper ------------------ */
function valid_mobile($m) {
    $m = trim($m);
    if ($m === "") return false;
    // allow +91 or 91 prefix or plain 10 digits; basic check
    $clean = preg_replace('/[\s\-\(\)]/', '', $m);
    $clean = ltrim($clean, '+');
    if (preg_match('/^91[6-9]\d{9}$/', $clean)) { // starts with 91
        return true;
    }
    if (preg_match('/^[6-9]\d{9}$/', $clean)) {
        return true;
    }
    return false;
}

/* ------------------ GET : fetch user ------------------
   Usage:
     GET api/users.php?id=3         -> fetch by id
     GET api/users.php              -> if session user_id exists, fetch that user
*/
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0);
    if (!$id) bad("User id not provided", 400);

    $stmt = $conn->prepare("SELECT id, transportName, transportArea, city, state, country, mobile, email, photo FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) bad("User not found", 404);

    // build photo url if exists
    if (!empty($res['photo'])) {
        $res['photo_url'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/../uploads/users/" . $res['photo'];
    } else {
        $res['photo_url'] = null;
    }

    ok($res);
}

/* ------------------ POST : create user (unchanged-ish) ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    $data = get_json();
    if (!$data) bad("Invalid JSON payload");

    $required = ['transportName','transportArea','city','state','country','email','password','mobile'];
    foreach ($required as $r) {
        if (!isset($data[$r]) || trim($data[$r]) === '') {
            bad("Field {$r} is required");
        }
    }
    $transportName = trim($data['transportName']);
    $transportArea = trim($data['transportArea']);
    $city = trim($data['city']);
    $state = trim($data['state']);
    $country = trim($data['country']);
    $email = trim($data['email']);
    $mobile = trim($data['mobile']);
    $password_raw = $data['password'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) bad("Invalid email format");
    if (!valid_mobile($mobile)) bad("Invalid mobile number");

    // check uniqueness
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0) { $stmt->close(); bad("Email already registered", 409); }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ? LIMIT 1");
    $stmt->bind_param("s", $mobile);
    $stmt->execute(); $stmt->store_result();
    if ($stmt->num_rows > 0) { $stmt->close(); bad("Mobile already registered", 409); }
    $stmt->close();

    $password = password_hash($password_raw, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO users (transportName, transportArea, city, state, country, mobile, email, password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) bad("Prepare failed: " . $conn->error, 500);
    $stmt->bind_param("ssssssss", $transportName, $transportArea, $city, $state, $country, $mobile, $email, $password);
    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        $stmt->close();
        ok(["message" => "Signup successful", "id" => $newId], 201);
    } else {
        $err = $stmt->error; $stmt->close(); bad("Insert failed: " . $err, 500);
    }
}

/* ------------------ ACTION: update_profile (multipart/form-data POST)
   Use when uploading photo or form-data:
   POST api/users.php?action=update_profile
   - requires session user_id OR include id in form-data
   - accepts fields: transportName, transportArea, city, state, country, mobile
   - accepts file field 'photo'
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update_profile') {
    // allow form-data (for images) or JSON by reading both
    $id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0);
    if (!$id) bad("User id required (session or id)");

    // get posted fields (fall back to JSON body)
    $transportName = isset($_POST['transportName']) ? trim($_POST['transportName']) : null;
    $transportArea = isset($_POST['transportArea']) ? trim($_POST['transportArea']) : null;
    $city = isset($_POST['city']) ? trim($_POST['city']) : null;
    $state = isset($_POST['state']) ? trim($_POST['state']) : null;
    $country = isset($_POST['country']) ? trim($_POST['country']) : null;
    $mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : null;

    // validate mobile if provided
    if ($mobile !== null && $mobile !== '') {
        if (!valid_mobile($mobile)) bad("Invalid mobile format");
        // check uniqueness
        $s = $conn->prepare("SELECT id FROM users WHERE mobile = ? AND id <> ? LIMIT 1");
        $s->bind_param("si", $mobile, $id);
        $s->execute(); $s->store_result();
        if ($s->num_rows > 0) { $s->close(); bad("Mobile already used by another account"); }
        $s->close();
    }

    // Build update parts
    $sets = []; $params = []; $types = "";
    if ($transportName !== null) { $sets[] = "transportName = ?"; $types .= "s"; $params[] = $transportName; }
    if ($transportArea !== null) { $sets[] = "transportArea = ?"; $types .= "s"; $params[] = $transportArea; }
    if ($city !== null) { $sets[] = "city = ?"; $types .= "s"; $params[] = $city; }
    if ($state !== null) { $sets[] = "state = ?"; $types .= "s"; $params[] = $state; }
    if ($country !== null) { $sets[] = "country = ?"; $types .= "s"; $params[] = $country; }
    if ($mobile !== null) { $sets[] = "mobile = ?"; $types .= "s"; $params[] = $mobile; }

    // handle photo upload
    $uploadedFileName = null;
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['photo'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $allowed)) bad("Invalid image type. Allowed: jpg,jpeg,png,webp");
        // create uploads dir
        $uploadDir = __DIR__ . "/../uploads/users";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        // unique filename
        $uploadedFileName = "user_{$id}_" . time() . "." . $ext;
        $dest = $uploadDir . "/" . $uploadedFileName;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            bad("Failed to move uploaded file");
        }
        // store filename to DB
        $sets[] = "photo = ?";
        $types .= "s";
        $params[] = $uploadedFileName;
    }

    if (!empty($sets)) {
        $sql = "UPDATE users SET " . implode(", ", $sets) . " WHERE id = ?";
        $types .= "i";
        $params[] = $id;
        $stmt = $conn->prepare($sql);
        if (!$stmt) bad("Prepare failed: " . $conn->error, 500);
        // dynamic bind
        $bind_names[] = $types;
        for ($i=0; $i<count($params); $i++) {
            $bind_name = 'b' . $i;
            $$bind_name = $params[$i];
            $bind_names[] = &$$bind_name;
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
        if (!$stmt->execute()) {
            $err = $stmt->error; $stmt->close(); bad("Update failed: " . $err, 500);
        }
        $stmt->close();
    }

    ok(["message" => "Profile updated"]);
}




bad("Unsupported method", 405);
