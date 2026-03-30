<?php
// process-quotation-request.php
session_start();
require_once 'includes/db-config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: quotation-requests.php');
    exit();
}

// Get database connection
$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// Get logged in user info
$user_id = (int)$_SESSION['employee_id'];
$user_name = $_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'Unknown User';

// Helper function for input sanitization
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

// Generate unique request number
function generateRequestNo($conn) {
    $prefix = 'QR';
    $year = date('Y');
    $month = date('m');
    
    // Get the latest request number for this month
    $query = "SELECT request_no FROM quotation_requests 
              WHERE request_no LIKE '$prefix-$year$month%' 
              ORDER BY id DESC LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $last_no = intval(substr($row['request_no'], -4));
        $new_no = str_pad($last_no + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $new_no = '0001';
    }
    
    return "$prefix-$year$month-$new_no";
}

// Handle file uploads
function uploadFile($file, $target_dir = 'uploads/quotation_requests/') {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $target_path = $target_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return $target_path;
    }
    
    return null;
}

// Handle multiple file uploads
function uploadMultipleFiles($files, $target_dir = 'uploads/quotation_requests/additional/') {
    $uploaded_files = [];
    
    if (!isset($files) || empty($files['name'][0])) {
        return json_encode([]);
    }
    
    // Create directory if it doesn't exist
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_count = count($files['name']);
    
    for ($i = 0; $i < $file_count; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $extension = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . time() . '_' . $i . '.' . $extension;
            $target_path = $target_dir . $filename;
            
            if (move_uploaded_file($files['tmp_name'][$i], $target_path)) {
                $uploaded_files[] = [
                    'original_name' => $files['name'][$i],
                    'file_path' => $target_path,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];
            }
        }
    }
    
    return json_encode($uploaded_files);
}

// Begin transaction
mysqli_begin_transaction($conn);

try {
    // Generate request number
    $request_no = generateRequestNo($conn);
    
    // Get and sanitize form data
    $title = sanitizeInput($_POST['title'] ?? '');
    $quotation_type = sanitizeInput($_POST['quotation_type'] ?? '');
    $site_id = intval($_POST['site_id'] ?? 0);
    $priority = sanitizeInput($_POST['priority'] ?? 'Medium');
    $request_date = sanitizeInput($_POST['request_date'] ?? date('Y-m-d'));
    $required_by_date = !empty($_POST['required_by_date']) ? sanitizeInput($_POST['required_by_date']) : null;
    $description = sanitizeInput($_POST['description'] ?? '');
    $specifications = !empty($_POST['specifications']) ? sanitizeInput($_POST['specifications']) : null;
    $drawing_number = !empty($_POST['drawing_number']) ? sanitizeInput($_POST['drawing_number']) : null;
    
    // Check if saving as draft
    $is_draft = isset($_POST['save_as_draft']) && $_POST['save_as_draft'] == '1';
    $status = $is_draft ? 'Draft' : 'Pending Assignment';
    
    // Validate required fields
    if (empty($title) || empty($quotation_type) || empty($site_id) || empty($description)) {
        throw new Exception('Please fill in all required fields');
    }
    
    // Validate site exists and user is assigned as Project Engineer
    $site_check_query = "SELECT s.id, s.project_name 
                         FROM sites s 
                         INNER JOIN site_project_engineers spe ON spe.site_id = s.id
                         WHERE s.id = ? 
                         AND spe.employee_id = ? 
                         AND s.deleted_at IS NULL";
    $stmt = mysqli_prepare($conn, $site_check_query);
    mysqli_stmt_bind_param($stmt, "ii", $site_id, $user_id);
    mysqli_stmt_execute($stmt);
    $site_result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($site_result) === 0) {
        throw new Exception('Invalid site selected or you do not have access to this site. Only Project Engineers and Team Leads can create requests for their assigned sites.');
    }
    
    $site_data = mysqli_fetch_assoc($site_result);
    $site_name = $site_data['project_name'];
    mysqli_stmt_close($stmt);
    
    // Handle drawing file upload
    $drawing_file = null;
    if (isset($_FILES['drawing_file']) && $_FILES['drawing_file']['error'] === UPLOAD_ERR_OK) {
        $drawing_file = uploadFile($_FILES['drawing_file'], 'uploads/quotation_requests/drawings/');
        if (!$drawing_file) {
            throw new Exception('Failed to upload drawing file');
        }
    }
    
    // Handle additional files upload
    $additional_files_json = null;
    if (isset($_FILES['additional_files'])) {
        $additional_files_json = uploadMultipleFiles($_FILES['additional_files'], 'uploads/quotation_requests/additional/');
    }
    
    // Prepare attachments JSON
    $attachments_json = null;
    if ($drawing_file || ($additional_files_json && $additional_files_json !== '[]')) {
        $attachments = [];
        
        if ($drawing_file) {
            $attachments['drawing'] = [
                'file_path' => $drawing_file,
                'uploaded_at' => date('Y-m-d H:i:s')
            ];
        }
        
        if ($additional_files_json && $additional_files_json !== '[]') {
            $attachments['additional'] = json_decode($additional_files_json, true);
        }
        
        $attachments_json = json_encode($attachments);
    }
    
    // ============================================================
    // FIXED: Insert without 'notes' field - matching your actual schema
    // ============================================================
    $insert_query = "INSERT INTO quotation_requests (
        request_no, 
        quotation_type, 
        site_id, 
        requested_by, 
        requested_by_name,
        request_date, 
        required_by_date, 
        priority, 
        title, 
        description,
        specifications, 
        drawing_number, 
        drawing_file, 
        additional_documents_json,
        status,
        created_at
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, NOW()
    )";
    
    $stmt = mysqli_prepare($conn, $insert_query);
    
    if (!$stmt) {
        throw new Exception('Database error: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, 'ssiisssssssssss', 
        $request_no,
        $quotation_type,
        $site_id,
        $user_id,
        $user_name,
        $request_date,
        $required_by_date,
        $priority,
        $title,
        $description,
        $specifications,
        $drawing_number,
        $drawing_file,
        $attachments_json,
        $status
    );
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Failed to save quotation request: ' . mysqli_stmt_error($stmt));
    }
    
    $request_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);
    
    // Log the activity
    $log_query = "INSERT INTO activity_logs (
        user_id, user_name, user_role, action_type, module,
        module_id, module_name, description, created_at
    ) VALUES (
        ?, ?, (SELECT designation FROM employees WHERE id = ?), 'CREATE', 'quotation_requests',
        ?, ?, ?, NOW()
    )";
    
    $log_stmt = mysqli_prepare($conn, $log_query);
    if ($log_stmt) {
        $log_description = ($is_draft ? 'Draft saved' : 'Quotation request created') . ': ' . $request_no . ' - ' . $title;
        
        mysqli_stmt_bind_param($log_stmt, 'isisis', 
            $user_id,
            $user_name,
            $user_id,
            $request_id,
            $title,
            $log_description
        );
        mysqli_stmt_execute($log_stmt);
        mysqli_stmt_close($log_stmt);
    }
    
    // Commit transaction
    mysqli_commit($conn);
    
    // Set success message and redirect
    $message = $is_draft ? 'Quotation request saved as draft successfully!' : 'Quotation request submitted successfully!';
    $status = 'success';
    
    // Send notification to Team Lead/Manager if not draft
    if (!$is_draft) {
        // Get the manager/team lead for this site to notify
        $notify_query = "SELECT e.id, e.full_name, e.email 
                         FROM sites s
                         LEFT JOIN employees e ON (e.id = s.manager_employee_id OR e.id = s.team_lead_employee_id)
                         WHERE s.id = ? 
                         AND e.id IS NOT NULL
                         LIMIT 1";
        $notify_stmt = mysqli_prepare($conn, $notify_query);
        
        if ($notify_stmt) {
            mysqli_stmt_bind_param($notify_stmt, 'i', $site_id);
            mysqli_stmt_execute($notify_stmt);
            $notify_result = mysqli_stmt_get_result($notify_stmt);
            $notify_count = mysqli_num_rows($notify_result);
            
            error_log("Quotation request #$request_no created for site '$site_name'. Found $notify_count managers to notify.");
            
            mysqli_stmt_close($notify_stmt);
        }
    }
    
    header("Location: quotation-requests.php?status=$status&message=" . urlencode($message));
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    mysqli_rollback($conn);
    
    // Log error
    error_log('Quotation Request Error: ' . $e->getMessage());
    
    // Set error message and redirect
    $status = 'error';
    $message = 'Error: ' . $e->getMessage();
    header("Location: quotation-requests.php?status=$status&message=" . urlencode($message));
    exit();
} finally {
    // Close connection
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
}
?>