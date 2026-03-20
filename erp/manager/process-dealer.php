<?php
// process-dealer.php
date_default_timezone_set('Asia/Kolkata');
session_start();
require_once 'includes/db-config.php';

// ==================== LOGIN CHECK ====================
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

// ==================== METHOD CHECK ====================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dealers.php');
    exit();
}

// ==================== DB CONNECTION ====================
$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// ==================== USER INFO ====================
$user_id = (int)$_SESSION['employee_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown User';
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Allowed roles
$allowed = ['manager', 'director', 'vice president', 'general manager'];
if (!in_array($user_designation, $allowed, true)) {
    header('Location: index.php');
    exit();
}

// ==================== HELPERS ====================
function sanitizeInput($data) {
    return htmlspecialchars(trim($data));
}

function generateDealerCode($conn) {
    $prefix = 'DL';
    $year = date('y');

    $query = "SELECT dealer_code FROM quotation_dealers 
              WHERE dealer_code LIKE '$prefix$year%' 
              ORDER BY id DESC LIMIT 1";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $last_no = intval(substr($row['dealer_code'], -4));
        $new_no = str_pad($last_no + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_no = '0001';
    }

    return "$prefix$year-$new_no";
}

// ==================== GET DATA ====================
$dealer_id = intval($_POST['dealer_id'] ?? 0);
$action = sanitizeInput($_POST['action'] ?? 'add');

$dealer_code = sanitizeInput($_POST['dealer_code'] ?? '');
$dealer_name = sanitizeInput($_POST['dealer_name'] ?? '');
$contact_person = sanitizeInput($_POST['contact_person'] ?? '');
$mobile_number = sanitizeInput($_POST['mobile_number'] ?? '');
$alternate_phone = sanitizeInput($_POST['alternate_phone'] ?? '');
$email = sanitizeInput($_POST['email'] ?? '');
$gst_number = sanitizeInput($_POST['gst_number'] ?? '');
$pan_number = sanitizeInput($_POST['pan_number'] ?? '');
$address = sanitizeInput($_POST['address'] ?? '');
$city = sanitizeInput($_POST['city'] ?? '');
$state = sanitizeInput($_POST['state'] ?? '');
$pincode = sanitizeInput($_POST['pincode'] ?? '');
$dealer_type = $_POST['dealer_type'] ?? [];
$payment_terms = sanitizeInput($_POST['payment_terms'] ?? '');
$credit_limit = isset($_POST['credit_limit']) ? floatval($_POST['credit_limit']) : null;
$remarks = sanitizeInput($_POST['remarks'] ?? '');
$status = sanitizeInput($_POST['status'] ?? 'Active');

// ==================== VALIDATION ====================
if (empty($dealer_name)) {
    header('Location: dealers.php?status=error&message=Dealer name is required');
    exit();
}

if (!preg_match('/^[0-9]{10}$/', $mobile_number)) {
    header('Location: dealers.php?status=error&message=Invalid mobile number');
    exit();
}

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: dealers.php?status=error&message=Invalid email');
    exit();
}

// ==================== TRANSACTION ====================
mysqli_begin_transaction($conn);

try {

    // ================= DUPLICATE CHECK =================
    if ($action === 'add') {

        $check = mysqli_prepare($conn, "SELECT id FROM quotation_dealers WHERE mobile_number = ?");
        mysqli_stmt_bind_param($check, "s", $mobile_number);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);

        if (mysqli_stmt_num_rows($check) > 0) {
            throw new Exception('Mobile already exists');
        }

        mysqli_stmt_close($check);

        if (empty($dealer_code)) {
            $dealer_code = generateDealerCode($conn);
        }

    } else {

        $check = mysqli_prepare($conn, "SELECT id FROM quotation_dealers WHERE mobile_number = ? AND id != ?");
        mysqli_stmt_bind_param($check, "si", $mobile_number, $dealer_id);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);

        if (mysqli_stmt_num_rows($check) > 0) {
            throw new Exception('Mobile already exists');
        }

        mysqli_stmt_close($check);
    }

    // ================= DEALER TYPE =================
    $dealer_type_string = !empty($dealer_type) ? implode(',', $dealer_type) : '';

    // ================= INSERT =================
    if ($action === 'add') {

        $sql = "INSERT INTO quotation_dealers (
            dealer_code, dealer_name, contact_person, mobile_number, alternate_phone,
            email, gst_number, pan_number, address, city, state, pincode,
            dealer_type, payment_terms, credit_limit, status, remarks,
            created_by, created_by_name, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )";

        $stmt = mysqli_prepare($conn, $sql);

        // ✅ FIXED bind_param
        mysqli_stmt_bind_param($stmt, "ssssssssssssssdssis",
            $dealer_code,
            $dealer_name,
            $contact_person,
            $mobile_number,
            $alternate_phone,
            $email,
            $gst_number,
            $pan_number,
            $address,
            $city,
            $state,
            $pincode,
            $dealer_type_string,
            $payment_terms,
            $credit_limit,
            $status,
            $remarks,
            $user_id,
            $user_name
        );

        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_errno($stmt)) {
            throw new Exception(mysqli_stmt_error($stmt));
        }

        $module_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $message = "Dealer added successfully";
        $log_action = "CREATE";

    } else {

        // ================= UPDATE =================
        $sql = "UPDATE quotation_dealers SET
            dealer_name=?, contact_person=?, mobile_number=?, alternate_phone=?,
            email=?, gst_number=?, pan_number=?, address=?, city=?, state=?, pincode=?,
            dealer_type=?, payment_terms=?, credit_limit=?, status=?, remarks=?,
            updated_at=NOW()
            WHERE id=?";

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param($stmt, "sssssssssssssdssi",
            $dealer_name,
            $contact_person,
            $mobile_number,
            $alternate_phone,
            $email,
            $gst_number,
            $pan_number,
            $address,
            $city,
            $state,
            $pincode,
            $dealer_type_string,
            $payment_terms,
            $credit_limit,
            $status,
            $remarks,
            $dealer_id
        );

        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_errno($stmt)) {
            throw new Exception(mysqli_stmt_error($stmt));
        }

        mysqli_stmt_close($stmt);

        $module_id = $dealer_id;
        $message = "Dealer updated successfully";
        $log_action = "UPDATE";
    }

    // ================= LOG =================
    $log = mysqli_prepare($conn, "
        INSERT INTO activity_logs (
            user_id, user_name, user_role, action_type,
            module, module_id, module_name, description, created_at
        ) VALUES (?, ?, ?, ?, 'quotation_dealers', ?, ?, ?, NOW())
    ");

    $desc = $log_action . " dealer: " . $dealer_code . " - " . $dealer_name;

    mysqli_stmt_bind_param($log, "isssiss",
        $user_id,
        $user_name,
        $user_designation,
        $log_action,
        $module_id,
        $dealer_name,
        $desc
    );

    mysqli_stmt_execute($log);
    mysqli_stmt_close($log);

    // ================= COMMIT =================
    mysqli_commit($conn);

    header("Location: dealers.php?status=success&message=" . urlencode($message));
    exit();

} catch (Exception $e) {

    mysqli_rollback($conn);

    header("Location: dealers.php?status=error&message=" . urlencode($e->getMessage()));
    exit();

} finally {
    mysqli_close($conn);
}
?>