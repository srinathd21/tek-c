<?php
// hr/onboarding.php - Onboarding Management Page
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// ---------------- AUTH (HR/Manager) ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];

// Get current employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$current_employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$current_employee) {
    die("Employee not found.");
}

// Check permissions
$designation = strtolower(trim($current_employee['designation'] ?? ''));
$department = strtolower(trim($current_employee['department'] ?? ''));

$isHr = ($designation === 'hr' || $department === 'hr');
$isManager = in_array($designation, ['manager', 'team lead', 'project manager', 'director', 'administrator']);
$isAdmin = ($designation === 'administrator' || $designation === 'admin' || $designation === 'director');

if (!$isHr && !$isManager && !$isAdmin) {
    $_SESSION['flash_error'] = "You don't have permission to access this page.";
    header("Location: ../dashboard.php");
    exit;
}

// ---------------- HANDLE ONBOARDING ACTIONS ----------------
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Create New Onboarding
    if (isset($_POST['action']) && $_POST['action'] === 'create_onboarding') {
        $candidate_id = (int)$_POST['candidate_id'];
        $offer_id = (int)$_POST['offer_id'];
        $hiring_request_id = (int)$_POST['hiring_request_id'];
        $joining_date = mysqli_real_escape_string($conn, $_POST['joining_date']);
        $reporting_time = mysqli_real_escape_string($conn, $_POST['reporting_time'] ?? '09:00:00');
        $reporting_to = !empty($_POST['reporting_to']) ? (int)$_POST['reporting_to'] : null;
        $department = mysqli_real_escape_string($conn, $_POST['department']);
        $designation = mysqli_real_escape_string($conn, $_POST['designation']);
        
        // Get reporting_to_name
        $reporting_to_name = null;
        if ($reporting_to) {
            $rm_query = "SELECT full_name FROM employees WHERE id = ?";
            $rm_stmt = mysqli_prepare($conn, $rm_query);
            mysqli_stmt_bind_param($rm_stmt, "i", $reporting_to);
            mysqli_stmt_execute($rm_stmt);
            $rm_res = mysqli_stmt_get_result($rm_stmt);
            $rm_row = mysqli_fetch_assoc($rm_res);
            $reporting_to_name = $rm_row['full_name'] ?? null;
            mysqli_stmt_close($rm_stmt);
        }
        
        // Generate onboarding number
        $year = date('Y');
        $month = date('m');
        
        $seq_query = "SELECT COUNT(*) as count FROM onboarding WHERE onboarding_no LIKE 'ONB-{$year}{$month}%'";
        $seq_result = mysqli_query($conn, $seq_query);
        $seq_row = mysqli_fetch_assoc($seq_result);
        $seq_num = str_pad($seq_row['count'] + 1, 4, '0', STR_PAD_LEFT);
        $onboarding_no = "ONB-{$year}{$month}-{$seq_num}";
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Insert onboarding record
            $insert_stmt = mysqli_prepare($conn, "
                INSERT INTO onboarding (
                    onboarding_no, candidate_id, offer_id, hiring_request_id,
                    joining_date, reporting_time, reporting_to, reporting_to_name, department, designation,
                    status, created_by, created_by_name
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)
            ");
            
            mysqli_stmt_bind_param(
                $insert_stmt,
                "siiissssssis",
                $onboarding_no,
                $candidate_id,
                $offer_id,
                $hiring_request_id,
                $joining_date,
                $reporting_time,
                $reporting_to,
                $reporting_to_name,
                $department,
                $designation,
                $current_employee_id,
                $current_employee['full_name']
            );
            
            if (!mysqli_stmt_execute($insert_stmt)) {
                throw new Exception("Failed to create onboarding: " . mysqli_error($conn));
            }
            
            $onboarding_id = mysqli_insert_id($conn);
            
            // Update candidate status to 'Onboarding'
            mysqli_query($conn, "UPDATE candidates SET status = 'Onboarding' WHERE id = {$candidate_id}");
            
            // Update offer status if needed
            mysqli_query($conn, "UPDATE offers SET status = 'Accepted' WHERE id = {$offer_id}");
            
            // Log activity
            logActivity(
                $conn,
                'CREATE',
                'onboarding',
                "Created onboarding: {$onboarding_no}",
                $onboarding_id,
                null,
                null,
                json_encode($_POST)
            );
            
            mysqli_commit($conn);
            
            $message = "Onboarding created successfully! Onboarding Number: {$onboarding_no}";
            $messageType = "success";
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
        }
    }
    
    // Update Onboarding Status
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $onboarding_id = (int)$_POST['onboarding_id'];
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
        
        $update_fields = [];
        $params = [];
        $types = "";
        
        if ($status === 'Completed') {
            $update_fields[] = "status = ?";
            $update_fields[] = "completed_at = CURDATE()";
            $update_fields[] = "completed_by = ?";
            $params[] = $status;
            $params[] = $current_employee_id;
            $types .= "si";
        } else {
            $update_fields[] = "status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        // Add remarks with timestamp
        if (!empty($remarks)) {
            $timestamp = date('Y-m-d H:i:s');
            $update_fields[] = "remarks = CONCAT(IFNULL(remarks, ''), '\n[{$timestamp}] {$remarks}')";
        }
        
        $query = "UPDATE onboarding SET " . implode(", ", $update_fields) . " WHERE id = ?";
        $params[] = $onboarding_id;
        $types .= "i";
        
        $update_stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($update_stmt, $types, ...$params);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'onboarding',
                "Updated onboarding status to {$status}",
                $onboarding_id,
                null,
                null,
                json_encode(['status' => $status, 'remarks' => $remarks])
            );
            
            $message = "Onboarding status updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating status: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }   
    
    // Update Document Submission
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_documents') {
        $onboarding_id = (int)$_POST['onboarding_id'];
        
        // Get current documents JSON
        $doc_query = "SELECT documents_json FROM onboarding WHERE id = ?";
        $doc_stmt = mysqli_prepare($conn, $doc_query);
        mysqli_stmt_bind_param($doc_stmt, "i", $onboarding_id);
        mysqli_stmt_execute($doc_stmt);
        $doc_res = mysqli_stmt_get_result($doc_stmt);
        $doc_row = mysqli_fetch_assoc($doc_res);
        
        $documents = [];
        if (!empty($doc_row['documents_json'])) {
            $documents = json_decode($doc_row['documents_json'], true);
        }
        
        // Handle document uploads
        $upload_dir = '../uploads/onboarding/documents/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $document_types = ['aadhar', 'pan', 'degree', 'experience', 'photo', 'offer_acceptance', 'bank', 'other'];
        
        foreach ($document_types as $doc_type) {
            if (isset($_FILES['doc_' . $doc_type]) && $_FILES['doc_' . $doc_type]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['doc_' . $doc_type];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
                if (in_array($file_ext, $allowed)) {
                    $file_name = 'doc_' . $onboarding_id . '_' . $doc_type . '_' . time() . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($file['tmp_name'], $file_path)) {
                        $documents[$doc_type] = [
                            'file' => 'uploads/onboarding/documents/' . $file_name,
                            'name' => $file['name'],
                            'uploaded_at' => date('Y-m-d H:i:s'),
                            'uploaded_by' => $current_employee_id
                        ];
                    }
                }
            }
        }
        
        // Check if all required docs are submitted
        $required_docs = ['aadhar', 'pan', 'offer_acceptance'];
        $all_submitted = true;
        foreach ($required_docs as $req) {
            if (!isset($documents[$req])) {
                $all_submitted = false;
                break;
            }
        }
        
        $documents_json = json_encode($documents);
        
        $update_stmt = mysqli_prepare($conn, "
            UPDATE onboarding 
            SET documents_json = ?,
                documents_submitted = ?
            WHERE id = ?
        ");
        
        $doc_submitted = $all_submitted ? 1 : 0;
        mysqli_stmt_bind_param($update_stmt, "sii", $documents_json, $doc_submitted, $onboarding_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'onboarding',
                "Updated documents for onboarding ID: {$onboarding_id}",
                $onboarding_id,
                null,
                null,
                null
            );
            
            $message = "Documents updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating documents: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Update Checklist Items
    elseif (isset($_POST['action']) && $_POST['action'] === 'update_checklist') {
        $onboarding_id = (int)$_POST['onboarding_id'];
        
        $checklist_items = [
            'id_card_issued' => isset($_POST['id_card_issued']) ? 1 : 0,
            'email_created' => isset($_POST['email_created']) ? 1 : 0,
            'system_access_given' => isset($_POST['system_access_given']) ? 1 : 0,
            'biometric_enrolled' => isset($_POST['biometric_enrolled']) ? 1 : 0,
            'orientation_completed' => isset($_POST['orientation_completed']) ? 1 : 0,
            'training_completed' => isset($_POST['training_completed']) ? 1 : 0,
            'welcome_kit_issued' => isset($_POST['welcome_kit_issued']) ? 1 : 0
        ];
        
        $update_stmt = mysqli_prepare($conn, "
            UPDATE onboarding SET
                id_card_issued = ?,
                email_created = ?,
                system_access_given = ?,
                biometric_enrolled = ?,
                orientation_completed = ?,
                training_completed = ?,
                welcome_kit_issued = ?
            WHERE id = ?
        ");
        
        mysqli_stmt_bind_param(
            $update_stmt,
            "iiiiiiii",
            $checklist_items['id_card_issued'],
            $checklist_items['email_created'],
            $checklist_items['system_access_given'],
            $checklist_items['biometric_enrolled'],
            $checklist_items['orientation_completed'],
            $checklist_items['training_completed'],
            $checklist_items['welcome_kit_issued'],
            $onboarding_id
        );
        
        if (mysqli_stmt_execute($update_stmt)) {
            logActivity(
                $conn,
                'UPDATE',
                'onboarding',
                "Updated checklist for onboarding ID: {$onboarding_id}",
                $onboarding_id,
                null,
                null,
                json_encode($checklist_items)
            );
            
            $message = "Checklist updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating checklist: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Generate Employee Code
    elseif (isset($_POST['action']) && $_POST['action'] === 'generate_employee_code') {
        $onboarding_id = (int)$_POST['onboarding_id'];
        
        // Get department for prefix
        $dept_query = "SELECT department, id FROM onboarding WHERE id = ?";
        $dept_stmt = mysqli_prepare($conn, $dept_query);
        mysqli_stmt_bind_param($dept_stmt, "i", $onboarding_id);
        mysqli_stmt_execute($dept_stmt);
        $dept_res = mysqli_stmt_get_result($dept_stmt);
        $dept_row = mysqli_fetch_assoc($dept_res);
        
        $dept = $dept_row['department'];
        $dept_code = '';
        
        switch($dept) {
            case 'PM': $dept_code = 'PM'; break;
            case 'CM': $dept_code = 'CM'; break;
            case 'IFM': $dept_code = 'IF'; break;
            case 'QS': $dept_code = 'QS'; break;
            case 'HR': $dept_code = 'HR'; break;
            case 'ACCOUNTS': $dept_code = 'AC'; break;
            default: $dept_code = 'EM';
        }
        
        // Get next sequence number
        $seq_query = "SELECT COUNT(*) as count FROM employees WHERE employee_code LIKE '{$dept_code}%'";
        $seq_result = mysqli_query($conn, $seq_query);
        $seq_row = mysqli_fetch_assoc($seq_result);
        $seq_num = str_pad($seq_row['count'] + 1, 4, '0', STR_PAD_LEFT);
        
        $employee_code = $dept_code . $seq_num;
        
        // Update onboarding with employee code
        $update_stmt = mysqli_prepare($conn, "UPDATE onboarding SET employee_code = ? WHERE id = ?");
        mysqli_stmt_bind_param($update_stmt, "si", $employee_code, $onboarding_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $message = "Employee code generated: {$employee_code}";
            $messageType = "success";
        } else {
            $message = "Error generating employee code: " . mysqli_error($conn);
            $messageType = "danger";
        }
    }
    
    // Complete Onboarding and Create Employee
    elseif (isset($_POST['action']) && $_POST['action'] === 'complete_onboarding') {
        $onboarding_id = (int)$_POST['onboarding_id'];
        
        // Get complete onboarding details with all candidate and offer information
        $details_query = "
            SELECT 
                o.*,
                c.id as candidate_id,
                c.first_name, 
                c.last_name, 
                c.email, 
                c.phone as mobile_number,
                c.current_location,
                c.total_experience, 
                c.current_company,
                c.resume_path,
                c.photo_path as candidate_photo,
                c.source,
                c.status as candidate_status,
                h.id as hiring_request_id,
                h.department, 
                h.designation,
                h.requested_by as reporting_manager_id,
                h.location,
                offr.id as offer_id,
                offr.offer_no,
                offr.ctc as offer_ctc,
                offr.basic_salary,
                offr.hra,
                offr.conveyance,
                offr.medical,
                offr.special_allowance,
                offr.bonus,
                offr.other_benefits,
                offr.offer_document
            FROM onboarding o
            JOIN candidates c ON o.candidate_id = c.id
            JOIN hiring_requests h ON o.hiring_request_id = h.id
            LEFT JOIN offers offr ON o.offer_id = offr.id
            WHERE o.id = ?
        ";
        
        $details_stmt = mysqli_prepare($conn, $details_query);
        mysqli_stmt_bind_param($details_stmt, "i", $onboarding_id);
        mysqli_stmt_execute($details_stmt);
        $details_res = mysqli_stmt_get_result($details_stmt);
        $details = mysqli_fetch_assoc($details_res);
        
        if (!$details) {
            $message = "Onboarding record not found.";
            $messageType = "danger";
        } else {
            // Begin transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Get existing documents from onboarding documents_json
                $documents = [];
                if (!empty($details['documents_json'])) {
                    $documents = json_decode($details['documents_json'], true);
                }
                
                // Prepare file paths for uploaded documents
                $photo_path = '';
                $passbook_photo_path = '';
                
                // Get photo from documents if exists
                if (!empty($documents['photo'])) {
                    $photo_path = $documents['photo']['file'];
                } elseif (!empty($details['candidate_photo'])) {
                    $photo_path = $details['candidate_photo'];
                }
                
                // Get passbook photo from documents if exists (store in uploads)
                if (!empty($documents['bank'])) {
                    $passbook_photo_path = $documents['bank']['file'];
                }
                
                // Generate username from email or name
                if (!empty($details['email'])) {
                    $email_parts = explode('@', $details['email']);
                    $username = strtolower(preg_replace('/[^a-z0-9]/', '', $email_parts[0]));
                } else {
                    $username = strtolower(preg_replace('/[^a-z0-9]/', '', $details['first_name'] . $details['last_name']));
                }
                
                // Ensure username is unique
                $username_original = $username;
                $counter = 1;
                while (true) {
                    $check_stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE username = ?");
                    mysqli_stmt_bind_param($check_stmt, "s", $username);
                    mysqli_stmt_execute($check_stmt);
                    mysqli_stmt_store_result($check_stmt);
                    if (mysqli_stmt_num_rows($check_stmt) == 0) {
                        mysqli_stmt_close($check_stmt);
                        break;
                    }
                    mysqli_stmt_close($check_stmt);
                    $username = $username_original . $counter;
                    $counter++;
                }
                
                // Generate random password (12 characters)
                $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
                $password = '';
                for ($i = 0; $i < 12; $i++) {
                    $password .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Determine reporting_to - prioritize onboarding reporting_to, then hiring request's reporting_manager_id
                $reporting_to = null;
                if (!empty($details['reporting_to'])) {
                    $reporting_to = $details['reporting_to'];
                } elseif (!empty($details['reporting_manager_id'])) {
                    $reporting_to = $details['reporting_manager_id'];
                }
                
                // Get reporting manager name if reporting_to exists
                $reporting_manager_name = null;
                if ($reporting_to) {
                    $rm_query = "SELECT full_name FROM employees WHERE id = ?";
                    $rm_stmt = mysqli_prepare($conn, $rm_query);
                    mysqli_stmt_bind_param($rm_stmt, "i", $reporting_to);
                    mysqli_stmt_execute($rm_stmt);
                    $rm_res = mysqli_stmt_get_result($rm_stmt);
                    $rm_row = mysqli_fetch_assoc($rm_res);
                    $reporting_manager_name = $rm_row['full_name'] ?? null;
                    mysqli_stmt_close($rm_stmt);
                } elseif (!empty($details['reporting_to_name'])) {
                    $reporting_manager_name = $details['reporting_to_name'];
                }
                
                // Determine employee status
                $employee_status = 'active';
                
                // Full name
                $full_name = trim($details['first_name'] . ' ' . $details['last_name']);
                
                // Determine employee code - use generated from onboarding or create new
                $employee_code = $details['employee_code'];
                if (empty($employee_code)) {
                    // Generate employee code based on department
                    $dept_code = '';
                    switch($details['department']) {
                        case 'PM': $dept_code = 'PM'; break;
                        case 'CM': $dept_code = 'CM'; break;
                        case 'IFM': $dept_code = 'IF'; break;
                        case 'QS': $dept_code = 'QS'; break;
                        case 'HR': $dept_code = 'HR'; break;
                        case 'ACCOUNTS': $dept_code = 'AC'; break;
                        default: $dept_code = 'EM';
                    }
                    
                    $seq_query = "SELECT COUNT(*) as count FROM employees WHERE employee_code LIKE '{$dept_code}%'";
                    $seq_result = mysqli_query($conn, $seq_query);
                    $seq_row = mysqli_fetch_assoc($seq_result);
                    $seq_num = str_pad($seq_row['count'] + 1, 4, '0', STR_PAD_LEFT);
                    $employee_code = $dept_code . $seq_num;
                }
                
                // Prepare work location and site name
                $work_location = !empty($details['current_location']) ? $details['current_location'] : (!empty($details['location']) ? $details['location'] : null);
                $site_name = $work_location;
                
                // Prepare date fields
                $date_of_joining = $details['joining_date'] ?? date('Y-m-d');
                
                // Insert into employees table - create variables for all values first
                $full_name_val = $full_name;
                $employee_code_val = $employee_code;
                $photo_path_val = $photo_path;
                $mobile_number_val = $details['mobile_number'] ?? null;
                $email_val = $details['email'] ?? null;
                $date_of_joining_val = $date_of_joining;
                $department_val = $details['department'];
                $designation_val = $details['designation'];
                $reporting_manager_name_val = $reporting_manager_name;
                $reporting_to_val = $reporting_to;
                $work_location_val = $work_location;
                $site_name_val = $site_name;
                $employee_status_val = $employee_status;
                $username_val = $username;
                $password_val = $hashed_password;
                $passbook_photo_path_val = $passbook_photo_path;
                
                $emp_insert = mysqli_prepare($conn, "
                    INSERT INTO employees (
                        full_name, 
                        employee_code, 
                        photo, 
                        mobile_number, 
                        email, 
                        date_of_joining, 
                        department, 
                        designation,
                        reporting_manager, 
                        reporting_to,
                        work_location, 
                        site_name, 
                        employee_status,
                        username, 
                        password,
                        passbook_photo,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                mysqli_stmt_bind_param(
                    $emp_insert,
                    "ssssssssssssssss",
                    $full_name_val,
                    $employee_code_val,
                    $photo_path_val,
                    $mobile_number_val,
                    $email_val,
                    $date_of_joining_val,
                    $department_val,
                    $designation_val,
                    $reporting_manager_name_val,
                    $reporting_to_val,
                    $work_location_val,
                    $site_name_val,
                    $employee_status_val,
                    $username_val,
                    $password_val,
                    $passbook_photo_path_val
                );
                
                if (!mysqli_stmt_execute($emp_insert)) {
                    throw new Exception("Failed to create employee record: " . mysqli_stmt_error($emp_insert));
                }
                
                $employee_id = mysqli_insert_id($conn);
                
                // Update onboarding status to completed
                $update_onboarding = mysqli_prepare($conn, "
                    UPDATE onboarding 
                    SET status = 'Completed',
                        completed_at = CURDATE(),
                        completed_by = ?,
                        employee_code = ?
                    WHERE id = ?
                ");
                mysqli_stmt_bind_param($update_onboarding, "isi", $current_employee_id, $employee_code, $onboarding_id);
                mysqli_stmt_execute($update_onboarding);
                
                // Update candidate status to 'Joined'
                $update_candidate = mysqli_prepare($conn, "UPDATE candidates SET status = 'Joined' WHERE id = ?");
                mysqli_stmt_bind_param($update_candidate, "i", $details['candidate_id']);
                mysqli_stmt_execute($update_candidate);
                
                // Update offer status if exists
                if (!empty($details['offer_id'])) {
                    $update_offer = mysqli_prepare($conn, "UPDATE offers SET status = 'Accepted' WHERE id = ?");
                    mysqli_stmt_bind_param($update_offer, "i", $details['offer_id']);
                    mysqli_stmt_execute($update_offer);
                }
                
                // Log activity
                logActivity(
                    $conn,
                    'CREATE',
                    'employee',
                    "Created employee from onboarding: {$employee_code}",
                    $employee_id,
                    null,
                    null,
                    json_encode([
                        'onboarding_id' => $onboarding_id,
                        'employee_code' => $employee_code,
                        'username' => $username
                    ])
                );
                
                mysqli_commit($conn);
                
                // Store credentials in session for display
                $_SESSION['last_onboarding_credentials'] = [
                    'employee_code' => $employee_code,
                    'username' => $username,
                    'password' => $password,
                    'full_name' => $full_name
                ];
                
                $message = "Employee created successfully!";
                $messageType = "success";
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $message = "Error: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// ---------------- FILTERS ----------------
$status_filter = $_GET['status'] ?? 'all';
$department_filter = $_GET['department'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query
$query = "
    SELECT 
        o.*,
        c.id as candidate_id,
        c.first_name,
        c.last_name,
        c.candidate_code,
        c.photo_path as candidate_photo,
        c.email as candidate_email,
        c.phone as candidate_phone,
        CONCAT(c.first_name, ' ', c.last_name) as candidate_name,
        h.id as hiring_request_id,
        h.request_no,
        h.position_title,
        h.designation as hiring_designation,
        offr.id as offer_id,
        offr.offer_no,
        offr.ctc as offer_ctc,
        reporting_emp.full_name as reporting_to_name,
        reporting_emp.designation as reporting_to_designation,
        creator.full_name as created_by_name
    FROM onboarding o
    JOIN candidates c ON o.candidate_id = c.id
    JOIN hiring_requests h ON o.hiring_request_id = h.id
    LEFT JOIN offers offr ON o.offer_id = offr.id
    LEFT JOIN employees reporting_emp ON o.reporting_to = reporting_emp.id
    LEFT JOIN employees creator ON o.created_by = creator.id
    WHERE 1=1
";

// Filter by status
if ($status_filter !== 'all') {
    $status_filter = mysqli_real_escape_string($conn, $status_filter);
    $query .= " AND o.status = '{$status_filter}'";
}

// Filter by department
if (!empty($department_filter)) {
    $department_filter = mysqli_real_escape_string($conn, $department_filter);
    $query .= " AND o.department = '{$department_filter}'";
}

// Filter by date range
if (!empty($date_from)) {
    $query .= " AND DATE(o.joining_date) >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}
if (!empty($date_to)) {
    $query .= " AND DATE(o.joining_date) <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}

// Search
if (!empty($search)) {
    $search_term = mysqli_real_escape_string($conn, $search);
    $query .= " AND (c.first_name LIKE '%{$search_term}%' 
                    OR c.last_name LIKE '%{$search_term}%' 
                    OR c.email LIKE '%{$search_term}%'
                    OR c.candidate_code LIKE '%{$search_term}%'
                    OR o.onboarding_no LIKE '%{$search_term}%'
                    OR h.position_title LIKE '%{$search_term}%')";
}

// Managers see only onboarding from their requests
if (!$isHr && !$isAdmin && $isManager) {
    $query .= " AND h.requested_by = {$current_employee_id}";
}

$query .= " ORDER BY o.joining_date DESC, o.created_at DESC";

$onboardings = mysqli_query($conn, $query);

// Get accepted candidates for new onboarding (candidates who have accepted offers but not yet onboarded)
$candidates_query = "
    SELECT c.id, c.first_name, c.last_name, c.candidate_code, c.email, c.phone,
           o.id as offer_id, o.offer_no, o.ctc, o.expected_joining_date,
           h.id as hiring_id, h.position_title, h.department, h.designation,
           h.requested_by, h.requested_by_name
    FROM candidates c
    JOIN offers o ON c.id = o.candidate_id
    JOIN hiring_requests h ON c.hiring_request_id = h.id
    LEFT JOIN onboarding ob ON ob.candidate_id = c.id
    WHERE c.status = 'Accepted' 
      AND o.status = 'Accepted'
      AND ob.id IS NULL
    ORDER BY o.response_date DESC
";

if (!$isHr && !$isAdmin && $isManager) {
    $candidates_query .= " AND h.requested_by = {$current_employee_id}";
}

$candidates_result = mysqli_query($conn, $candidates_query);

// Get reporting managers for dropdown
$managers_query = "
    SELECT id, full_name, designation, department 
    FROM employees 
    WHERE employee_status = 'active' 
      AND designation IN ('Manager', 'Team Lead', 'Director', 'Vice President', 'General Manager')
    ORDER BY full_name
";
$managers_result = mysqli_query($conn, $managers_query);

// Get departments for filter
$dept_query = "SELECT DISTINCT department FROM hiring_requests ORDER BY department";
$dept_result = mysqli_query($conn, $dept_query);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_count,
        COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'In Progress' THEN 1 END) as in_progress_count,
        COUNT(CASE WHEN status = 'Completed' THEN 1 END) as completed_count,
        COUNT(CASE WHEN status = 'Cancelled' THEN 1 END) as cancelled_count,
        COUNT(CASE WHEN joining_date >= CURDATE() AND joining_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as upcoming_joining,
        COUNT(CASE WHEN joining_date < CURDATE() AND status != 'Completed' THEN 1 END) as overdue_joining,
        AVG(CASE WHEN status = 'Completed' THEN DATEDIFF(completed_at, joining_date) END) as avg_onboarding_days
    FROM onboarding o
";

if (!$isHr && !$isAdmin && $isManager) {
    $stats_query .= " WHERE o.hiring_request_id IN (SELECT id FROM hiring_requests WHERE requested_by = {$current_employee_id})";
}

$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// ---------------- HELPER FUNCTIONS ----------------
function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount)
{
    if (!$amount)
        return '—';
    return '₹ ' . number_format($amount, 2) . ' LPA';
}

function formatDate($date)
{
    if (!$date || $date == '0000-00-00')
        return '—';
    return date('d M Y', strtotime($date));
}

function getOnboardingStatusBadge($status)
{
    $classes = [
        'Pending' => 'bg-warning text-dark',
        'In Progress' => 'bg-info',
        'Completed' => 'bg-success',
        'Cancelled' => 'bg-danger'
    ];
    $icons = [
        'Pending' => 'bi-clock',
        'In Progress' => 'bi-gear',
        'Completed' => 'bi-check-circle',
        'Cancelled' => 'bi-x-circle'
    ];
    $class = $classes[$status] ?? 'bg-secondary';
    $icon = $icons[$status] ?? 'bi-question';
    return "<span class='badge {$class} px-3 py-2'><i class='bi {$icon} me-1'></i> {$status}</span>";
}

function getProgressClass($completed, $total) {
    $percentage = ($total > 0) ? ($completed / $total * 100) : 0;
    if ($percentage >= 75) return 'bg-success';
    if ($percentage >= 50) return 'bg-info';
    if ($percentage >= 25) return 'bg-warning';
    return 'bg-secondary';
}

function getInitials($name)
{
    $name = trim((string) $name);
    if ($name === '')
        return 'U';
    $parts = preg_split('/\s+/', $name);
    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $last = strtoupper(substr(end($parts) ?: '', 0, 1));
    return (count($parts) > 1) ? ($first . $last) : $first;
}

$loggedName = $_SESSION['employee_name'] ?? $current_employee['full_name'];
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Onboarding Management - TEK-C Hiring</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Favicon -->
    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css"
        rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll {
            flex: 1 1 auto;
            overflow: auto;
            padding: 22px;
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px;
            height: 100%;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .panel-title {
            font-weight: 900;
            font-size: 18px;
            color: #1f2937;
            margin: 0;
        }

        .panel-menu {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
            display: grid;
            place-items: center;
            color: #6b7280;
        }

        /* Stats Cards */
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 14px 16px;
            height: 90px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-ic {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 20px;
            flex: 0 0 auto;
        }

        .stat-ic.pending {
            background: #f59e0b;
        }

        .stat-ic.progress {
            background: #3b82f6;
        }

        .stat-ic.completed {
            background: #10b981;
        }

        .stat-ic.upcoming {
            background: #8b5cf6;
        }

        .stat-ic.overdue {
            background: #ef4444;
        }

        .stat-label {
            color: #4b5563;
            font-weight: 750;
            font-size: 13px;
        }

        .stat-value {
            font-size: 30px;
            font-weight: 900;
            line-height: 1;
            margin-top: 2px;
        }

        /* Filter Card */
        .filter-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 18px;
            margin-bottom: 20px;
        }

        /* Buttons */
        .btn-primary-custom {
            background: var(--blue);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-primary-custom:hover {
            background: #2a8bc9;
            color: #fff;
        }

        .btn-success-custom {
            background: #10b981;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(16, 185, 129, 0.18);
            white-space: nowrap;
        }

        .btn-success-custom:hover {
            background: #0da271;
            color: #fff;
        }

        .btn-filter {
            background: #10b981;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 18px rgba(16, 185, 129, 0.18);
            white-space: nowrap;
        }

        .btn-filter:hover {
            background: #0da271;
            color: #fff;
        }

        .btn-reset {
            background: #6b7280;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn-reset:hover {
            background: #4b5563;
            color: #fff;
        }

        .btn-danger-custom {
            background: #ef4444;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn-danger-custom:hover {
            background: #dc2626;
            color: #fff;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: hidden !important;
        }

        table.dataTable {
            width: 100% !important;
        }

        .table thead th {
            font-size: 11px;
            color: #6b7280;
            font-weight: 800;
            border-bottom: 1px solid var(--border) !important;
            padding: 10px 10px !important;
            white-space: normal !important;
        }

        .table td {
            vertical-align: middle;
            border-color: var(--border);
            font-weight: 650;
            color: #374151;
            padding: 10px 10px !important;
            white-space: normal !important;
            word-break: break-word;
        }

        /* Candidate Avatar */
        .candidate-avatar {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 900;
            font-size: 16px;
            flex: 0 0 auto;
        }

        .candidate-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .candidate-name {
            font-weight: 900;
            font-size: 13px;
            color: #1f2937;
            margin-bottom: 2px;
            line-height: 1.2;
        }

        .candidate-code {
            font-size: 11px;
            color: #6b7280;
            font-weight: 650;
            line-height: 1.2;
        }

        /* Onboarding Details */
        .onboarding-no {
            font-weight: 900;
            font-size: 13px;
            color: #1f2937;
        }

        .joining-date {
            font-size: 11px;
            color: #6b7280;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .employee-code {
            font-weight: 900;
            font-size: 12px;
            color: #059669;
            background: #d1fae5;
            padding: 2px 8px;
            border-radius: 20px;
            display: inline-block;
        }

        /* Position Info */
        .position-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .position-text {
            font-size: 12px;
            font-weight: 800;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 6px;
            line-height: 1.2;
        }

        .position-text i {
            color: var(--blue);
            font-size: 13px;
        }

        .department-badge {
            background: rgba(45, 156, 219, .1);
            color: var(--blue);
            padding: 3px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 900;
            border: 1px solid rgba(45, 156, 219, .2);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            width: fit-content;
        }

        /* Progress Bar */
        .progress-custom {
            height: 6px;
            border-radius: 3px;
            background: #e5e7eb;
            margin: 8px 0 4px;
        }

        .progress-custom-bar {
            height: 6px;
            border-radius: 3px;
        }

        .checklist-count {
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
        }

        /* Action Buttons */
        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 5px 8px;
            color: var(--muted);
            font-size: 12px;
            margin: 0 2px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-action:hover {
            background: var(--bg);
            color: var(--blue);
        }

        .btn-action.edit:hover {
            background: #dbeafe;
            color: #1e40af;
            border-color: #1e40af;
        }

        .btn-action.doc:hover {
            background: #d1fae5;
            color: #065f46;
            border-color: #065f46;
        }

        .btn-action.complete:hover {
            background: #d1fae5;
            color: #065f46;
            border-color: #065f46;
        }

        .btn-action.cancel:hover {
            background: #fee2e2;
            color: #991b1b;
            border-color: #991b1b;
        }

        /* Modal Styles */
        .modal-content {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
        }

        .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 16px 20px;
        }

        .modal-title {
            font-weight: 900;
            font-size: 18px;
            color: #1f2937;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            border-top: 1px solid var(--border);
            padding: 16px 20px;
        }

        /* Form Labels */
        .form-label {
            font-weight: 800;
            font-size: 12px;
            color: #4b5563;
            margin-bottom: 4px;
        }

        .required:after {
            content: " *";
            color: #ef4444;
        }

        /* Alert Styles */
        .alert {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }

        /* Document Upload */
        .document-item {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
        }

        .document-status {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-block;
        }

        .document-status.uploaded {
            background: #10b981;
            color: white;
        }

        .document-status.pending {
            background: #e5e7eb;
        }

        /* Checklist Items */
        .checklist-item {
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .checklist-item:last-child {
            border-bottom: none;
        }

        /* Actions Column Width */
        th.actions-col,
        td.actions-col {
            width: 150px !important;
            white-space: nowrap !important;
        }
    </style>
</head>

<body>
    <div class="app">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main" aria-label="Main">
            <?php include 'includes/topbar.php'; ?>

            <div class="content-scroll">
                <div class="container-fluid maxw">

                    <!-- Flash Messages -->
                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                            <i class="bi bi-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>-fill me-2"></i>
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-dark mb-1">Onboarding Management</h1>
                            <p class="text-muted mb-0">Manage new employee onboarding process</p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($isHr || $isAdmin): ?>
                                <button class="btn-primary-custom" onclick="openCreateModal()">
                                    <i class="bi bi-plus-lg"></i> Start Onboarding
                                </button>
                            <?php endif; ?>
                            <button class="btn-success-custom" onclick="exportToExcel()">
                                <i class="bi bi-file-excel"></i> Export
                            </button>
                        </div>
                    </div>

                    <!-- Stats Cards -->
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic pending"><i class="bi bi-clock"></i></div>
                                <div>
                                    <div class="stat-label">Pending</div>
                                    <div class="stat-value"><?php echo (int) ($stats['pending_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic progress"><i class="bi bi-gear"></i></div>
                                <div>
                                    <div class="stat-label">In Progress</div>
                                    <div class="stat-value"><?php echo (int) ($stats['in_progress_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic completed"><i class="bi bi-check-circle"></i></div>
                                <div>
                                    <div class="stat-label">Completed</div>
                                    <div class="stat-value"><?php echo (int) ($stats['completed_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic upcoming"><i class="bi bi-calendar-check"></i></div>
                                <div>
                                    <div class="stat-label">Upcoming (7d)</div>
                                    <div class="stat-value"><?php echo (int) ($stats['upcoming_joining'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic overdue"><i class="bi bi-exclamation-triangle"></i></div>
                                <div>
                                    <div class="stat-label">Overdue</div>
                                    <div class="stat-value"><?php echo (int) ($stats['overdue_joining'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="stat-card">
                                <div class="stat-ic total"><i class="bi bi-people"></i></div>
                                <div>
                                    <div class="stat-label">Total</div>
                                    <div class="stat-value"><?php echo (int) ($stats['total_count'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Card -->
                    <div class="filter-card">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select form-select-sm">
                                    <option value="all">All Status</option>
                                    <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="In Progress" <?php echo $status_filter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Department</label>
                                <select name="department" class="form-select form-select-sm">
                                    <option value="">All Departments</option>
                                    <?php 
                                    mysqli_data_seek($dept_result, 0);
                                    while ($dept = mysqli_fetch_assoc($dept_result)): ?>
                                        <option value="<?php echo e($dept['department']); ?>" <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                                            <?php echo e($dept['department']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">From Date</label>
                                <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo e($date_from); ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To Date</label>
                                <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo e($date_to); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control form-control-sm"
                                    placeholder="Candidate, Onboarding No, Position..." value="<?php echo e($search); ?>">
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button type="submit" class="btn-filter">
                                    <i class="bi bi-funnel"></i> Apply Filters
                                </button>
                                <a href="onboarding.php" class="btn-reset">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Onboarding Table -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">
                                <i class="bi bi-person-check"></i> Onboarding List
                            </h3>
                            <span class="badge bg-secondary"><?php echo mysqli_num_rows($onboardings); ?> records</span>
                        </div>

                        <div class="table-responsive">
                            <table id="onboardingTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Onboarding Details</th>
                                        <th>Candidate</th>
                                        <th>Position</th>
                                        <th>Joining Date</th>
                                        <th>Reporting To</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th class="text-end actions-col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($onboardings) === 0): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4">No onboarding records found</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php while ($item = mysqli_fetch_assoc($onboardings)): 
                                            // Calculate progress
                                            $checklist_items = [
                                                'id_card_issued' => $item['id_card_issued'],
                                                'email_created' => $item['email_created'],
                                                'system_access_given' => $item['system_access_given'],
                                                'biometric_enrolled' => $item['biometric_enrolled'],
                                                'orientation_completed' => $item['orientation_completed'],
                                                'training_completed' => $item['training_completed'],
                                                'welcome_kit_issued' => $item['welcome_kit_issued']
                                            ];
                                            $completed_count = array_sum($checklist_items);
                                            $total_items = count($checklist_items);
                                            $progress_percentage = ($completed_count / $total_items) * 100;
                                            
                                            // Check if date is upcoming or overdue
                                            $today = date('Y-m-d');
                                            $joining_class = '';
                                            $joining_icon = '';
                                            
                                            if ($item['joining_date'] < $today && $item['status'] != 'Completed') {
                                                $joining_class = 'text-danger';
                                                $joining_icon = '<i class="bi bi-exclamation-triangle-fill text-danger" title="Overdue"></i>';
                                            } elseif ($item['joining_date'] <= date('Y-m-d', strtotime('+7 days')) && $item['status'] != 'Completed') {
                                                $joining_class = 'text-warning';
                                                $joining_icon = '<i class="bi bi-clock-fill text-warning" title="Upcoming"></i>';
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="onboarding-no"><?php echo e($item['onboarding_no']); ?></div>
                                                    <?php if (!empty($item['employee_code'])): ?>
                                                        <div class="employee-code mt-1">
                                                            <i class="bi bi-person-badge"></i> <?php echo e($item['employee_code']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="joining-date mt-1">
                                                        <i class="bi bi-calendar"></i> Created: <?php echo formatDate($item['created_at']); ?>
                                                    </div>
                                                 </div>
                                                </td>

                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="candidate-avatar">
                                                            <?php if (!empty($item['candidate_photo'])): ?>
                                                                <img src="../<?php echo e($item['candidate_photo']); ?>" alt="Photo">
                                                            <?php else: ?>
                                                                <?php echo getInitials($item['candidate_name']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            <div class="candidate-name">
                                                                <a href="view-candidate.php?id=<?php echo $item['candidate_id']; ?>" class="text-decoration-none text-dark">
                                                                    <?php echo e($item['candidate_name']); ?>
                                                                </a>
                                                            </div>
                                                            <div class="candidate-code">
                                                                <i class="bi bi-hash"></i> <?php echo e($item['candidate_code']); ?>
                                                            </div>
                                                            <div class="candidate-code">
                                                                <i class="bi bi-envelope"></i> <?php echo e($item['candidate_email']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                 </div>
                                                </td>

                                                <td>
                                                    <div class="position-info">
                                                        <div class="position-text">
                                                            <i class="bi bi-briefcase"></i>
                                                            <?php echo e($item['position_title']); ?>
                                                        </div>
                                                        <?php if (!empty($item['department'])): ?>
                                                            <div class="department-badge">
                                                                <i class="bi bi-building"></i> <?php echo e($item['department']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if (!empty($item['offer_ctc'])): ?>
                                                            <div class="candidate-code">
                                                                <i class="bi bi-currency-rupee"></i> <?php echo formatCurrency($item['offer_ctc']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                 </div>
                                                </td>

                                                <td>
                                                    <div class="fw-bold <?php echo $joining_class; ?>">
                                                        <?php echo formatDate($item['joining_date']); ?>
                                                        <?php if (!empty($item['reporting_time'])): ?>
                                                            <br><small><?php echo date('h:i A', strtotime($item['reporting_time'])); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php echo $joining_icon; ?>
                                                 </div>
                                                </td>

                                                <td>
                                                    <?php if (!empty($item['reporting_to_name'])): ?>
                                                        <div class="requester-name fw-bold"><?php echo e($item['reporting_to_name']); ?></div>
                                                        <div class="requester-designation small text-muted"><?php echo e($item['reporting_to_designation'] ?: ''); ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                 </div>
                                                </td>

                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="fw-bold"><?php echo $completed_count; ?>/<?php echo $total_items; ?></span>
                                                        <div class="flex-grow-1">
                                                            <div class="progress-custom">
                                                                <div class="progress-custom-bar <?php echo getProgressClass($completed_count, $total_items); ?>" 
                                                                     style="width: <?php echo $progress_percentage; ?>%"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="checklist-count">
                                                        <i class="bi bi-check-circle"></i> ID: <?php echo $item['id_card_issued'] ? 'Yes' : 'No'; ?> | 
                                                        Email: <?php echo $item['email_created'] ? 'Yes' : 'No'; ?>
                                                    </div>
                                                 </div>
                                                </td>

                                                <td>
                                                    <?php echo getOnboardingStatusBadge($item['status']); ?>
                                                    
                                                    <?php if ($item['status'] === 'Completed' && !empty($item['completed_at'])): ?>
                                                        <div class="checklist-count mt-1">
                                                            <i class="bi bi-calendar-check"></i> <?php echo formatDate($item['completed_at']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                 </div>
                                                </td>

                                                <td class="text-end actions-col">
                                                    <a href="view-onboarding.php?id=<?php echo $item['id']; ?>" class="btn-action" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>

                                                    <?php if ($item['status'] !== 'Completed' && $item['status'] !== 'Cancelled'): ?>
                                                        <button class="btn-action edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)" title="Edit Details">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        
                                                        <button class="btn-action doc" onclick="openDocumentModal(<?php echo $item['id']; ?>, '<?php echo e($item['candidate_name']); ?>')" title="Upload Documents">
                                                            <i class="bi bi-file-earmark"></i>
                                                        </button>
                                                        
                                                        <button class="btn-action complete" onclick="openCompleteModal(<?php echo $item['id']; ?>, '<?php echo e($item['candidate_name']); ?>')" title="Complete Onboarding">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($item['status'] === 'Pending' || $item['status'] === 'In Progress'): ?>
                                                        <button class="btn-action cancel" onclick="openCancelModal(<?php echo $item['id']; ?>, '<?php echo e($item['candidate_name']); ?>')" title="Cancel">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                   
                                                 </div>
                                                </tr>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>

            <?php include 'includes/footer.php'; ?>
        </main>
    </div>

    <!-- Create Onboarding Modal -->
    <div class="modal fade" id="createOnboardingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create_onboarding">

                    <div class="modal-header">
                        <h5 class="modal-title">Start New Onboarding</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Candidate Selection -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label required">Select Candidate</label>
                                <select name="candidate_id" class="form-select select2" id="candidate_select" required>
                                    <option value="">Choose candidate who accepted offer...</option>
                                    <?php 
                                    mysqli_data_seek($candidates_result, 0);
                                    while ($candidate = mysqli_fetch_assoc($candidates_result)): 
                                    ?>
                                        <option value="<?php echo $candidate['id']; ?>" 
                                                data-offer-id="<?php echo $candidate['offer_id']; ?>"
                                                data-offer-no="<?php echo e($candidate['offer_no']); ?>"
                                                data-hiring-id="<?php echo $candidate['hiring_id']; ?>"
                                                data-position="<?php echo e($candidate['position_title']); ?>"
                                                data-department="<?php echo e($candidate['department']); ?>"
                                                data-designation="<?php echo e($candidate['designation']); ?>"
                                                data-joining-date="<?php echo $candidate['expected_joining_date']; ?>">
                                            <?php echo e($candidate['first_name'] . ' ' . $candidate['last_name']); ?> 
                                            (<?php echo e($candidate['candidate_code']); ?>) - <?php echo e($candidate['position_title']); ?>
                                            (Offer: <?php echo e($candidate['offer_no']); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <input type="hidden" name="offer_id" id="offer_id">
                                <input type="hidden" name="hiring_request_id" id="hiring_request_id">
                            </div>
                        </div>

                        <!-- Joining Details -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label required">Joining Date</label>
                                <input type="date" name="joining_date" id="joining_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Reporting Time</label>
                                <input type="time" name="reporting_time" class="form-control" value="09:00">
                            </div>
                        </div>

                        <!-- Position Details -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label required">Department</label>
                                <input type="text" name="department" id="department" class="form-control" readonly required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required">Designation</label>
                                <input type="text" name="designation" id="designation" class="form-control" readonly required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Reporting To</label>
                                <select name="reporting_to" class="form-select">
                                    <option value="">Select Manager</option>
                                    <?php 
                                    mysqli_data_seek($managers_result, 0);
                                    while ($manager = mysqli_fetch_assoc($managers_result)): 
                                    ?>
                                        <option value="<?php echo $manager['id']; ?>">
                                            <?php echo e($manager['full_name']); ?> (<?php echo e($manager['designation']); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Offer Summary -->
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Note:</strong> Onboarding will be created based on the accepted offer. 
                            The candidate status will be updated to 'Joined' upon completion.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Start Onboarding</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="onboarding_id" id="status_onboarding_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Update Onboarding Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label required">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="Pending">Pending</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Document Upload Modal -->
    <div class="modal fade" id="documentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_documents">
                    <input type="hidden" name="onboarding_id" id="doc_onboarding_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Upload Documents for <span id="doc_candidate_name"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label required">Aadhar Card</label>
                                <input type="file" name="doc_aadhar" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                <div class="form-text">PDF or Image, max 5MB</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">PAN Card</label>
                                <input type="file" name="doc_pan" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Degree Certificate</label>
                                <input type="file" name="doc_degree" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Experience Letters</label>
                                <input type="file" name="doc_experience" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Photograph</label>
                                <input type="file" name="doc_photo" class="form-control" accept=".jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required">Offer Acceptance</label>
                                <input type="file" name="doc_offer_acceptance" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Bank Details</label>
                                <input type="file" name="doc_bank" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Other Documents</label>
                                <input type="file" name="doc_other" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Upload Documents</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Checklist Modal -->
    <div class="modal fade" id="checklistModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_checklist">
                    <input type="hidden" name="onboarding_id" id="checklist_onboarding_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Onboarding Checklist</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="id_card_issued" id="chk_id_card">
                                <label class="form-check-label" for="chk_id_card">
                                    <strong>ID Card Issued</strong>
                                    <br><small class="text-muted">Employee ID card has been issued</small>
                                </label>
                            </div>
                        </div>

                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="email_created" id="chk_email">
                                <label class="form-check-label" for="chk_email">
                                    <strong>Email Account Created</strong>
                                    <br><small class="text-muted">Company email address created</small>
                                </label>
                            </div>
                        </div>

                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="system_access_given" id="chk_system">
                                <label class="form-check-label" for="chk_system">
                                    <strong>System Access Granted</strong>
                                    <br><small class="text-muted">Access to systems, drives, and software</small>
                                </label>
                            </div>
                        </div>

                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="biometric_enrolled" id="chk_biometric">
                                <label class="form-check-label" for="chk_biometric">
                                    <strong>Biometric Enrolled</strong>
                                    <br><small class="text-muted">Biometric attendance registered</small>
                                </label>
                            </div>
                        </div>

                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="orientation_completed" id="chk_orientation">
                                <label class="form-check-label" for="chk_orientation">
                                    <strong>Orientation Completed</strong>
                                    <br><small class="text-muted">Company orientation session attended</small>
                                </label>
                            </div>
                        </div>

                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="training_completed" id="chk_training">
                                <label class="form-check-label" for="chk_training">
                                    <strong>Training Completed</strong>
                                    <br><small class="text-muted">Role-specific training completed</small>
                                </label>
                            </div>
                        </div>

                        <div class="checklist-item">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="welcome_kit_issued" id="chk_welcome">
                                <label class="form-check-label" for="chk_welcome">
                                    <strong>Welcome Kit Issued</strong>
                                    <br><small class="text-muted">Welcome kit / onboarding kit provided</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom">Update Checklist</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Complete Onboarding Modal -->
    <div class="modal fade" id="completeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="complete_onboarding">
                    <input type="hidden" name="onboarding_id" id="complete_onboarding_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Complete Onboarding</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to complete onboarding for <strong id="complete_candidate_name"></strong>?</p>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>This will:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Create an employee record in the system</li>
                                <li>Generate employee username and password</li>
                                <li>Mark the candidate as 'Joined'</li>
                                <li>Complete the onboarding process</li>
                            </ul>
                        </div>

                        <p class="text-info small">
                            Ensure all documents are uploaded and checklist items are completed before proceeding.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-success-custom">Complete Onboarding</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cancel Onboarding Modal -->
    <div class="modal fade" id="cancelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="onboarding_id" id="cancel_onboarding_id">
                    <input type="hidden" name="status" value="Cancelled">

                    <div class="modal-header">
                        <h5 class="modal-title">Cancel Onboarding</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to cancel onboarding for <strong id="cancel_candidate_name"></strong>?</p>
                        
                        <div class="mb-3">
                            <label class="form-label required">Reason for Cancellation</label>
                            <textarea name="remarks" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-danger-custom">Cancel Onboarding</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Credentials Modal (Shown after successful employee creation) -->
    <div class="modal fade" id="credentialsModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title text-white">
                        <i class="bi bi-check-circle-fill me-2"></i> Employee Created Successfully!
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        Please save these credentials and share them with the employee.
                    </div>
                    
                    <div class="card mb-3 border">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-3">Login Credentials</h6>
                            <div class="mb-3">
                                <label class="fw-bold text-dark mb-1">Employee Code:</label>
                                <div class="input-group">
                                    <input type="text" id="cred_employee_code" class="form-control bg-light" readonly>
                                    <button class="btn btn-outline-primary copy-btn" data-copy="cred_employee_code">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold text-dark mb-1">Username:</label>
                                <div class="input-group">
                                    <input type="text" id="cred_username" class="form-control bg-light" readonly>
                                    <button class="btn btn-outline-primary copy-btn" data-copy="cred_username">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="fw-bold text-dark mb-1">Password:</label>
                                <div class="input-group">
                                    <input type="text" id="cred_password" class="form-control bg-light" readonly>
                                    <button class="btn btn-outline-primary copy-btn" data-copy="cred_password">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary" id="togglePasswordVisibility">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Important:</strong> 
                        <ul class="mb-0 mt-2">
                            <li>The employee will need to change their password on first login</li>
                            <li>Share these credentials securely with the employee</li>
                            <li>You can view the employee details in the Employee Directory</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="employees.php" class="btn-primary-custom">
                        <i class="bi bi-people"></i> View Employee Directory
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#onboardingTable').DataTable({
                responsive: true,
                autoWidth: false,
                scrollX: false,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
                order: [[3, 'asc']],
                language: {
                    zeroRecords: "No matching records found",
                    info: "Showing _START_ to _END_ of _TOTAL_ records",
                    infoEmpty: "No records to show",
                    lengthMenu: "Show _MENU_",
                    search: "Search:"
                },
                columnDefs: [
                    { orderable: false, targets: [5, 7] }
                ]
            });

            // Initialize Select2
            $('.select2').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#createOnboardingModal'),
                width: '100%'
            });

            // Handle candidate selection
            $('#candidate_select').on('change', function() {
                var selected = $(this).find(':selected');
                var offerId = selected.data('offer-id');
                var hiringId = selected.data('hiring-id');
                var position = selected.data('position');
                var department = selected.data('department');
                var designation = selected.data('designation');
                var joiningDate = selected.data('joining-date');
                
                $('#offer_id').val(offerId);
                $('#hiring_request_id').val(hiringId);
                $('#department').val(department);
                $('#designation').val(designation || position);
                
                if (joiningDate) {
                    $('#joining_date').val(joiningDate);
                }
            });

            // Auto-focus search
            setTimeout(function() {
                $('.dataTables_filter input').focus();
            }, 400);
        });

        function openCreateModal() {
            new bootstrap.Modal(document.getElementById('createOnboardingModal')).show();
        }

        function openEditModal(item) {
            $('#status_onboarding_id').val(item.id);
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }

        function openDocumentModal(id, candidateName) {
            $('#doc_onboarding_id').val(id);
            $('#doc_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('documentModal')).show();
        }

        function openCompleteModal(id, candidateName) {
            $('#complete_onboarding_id').val(id);
            $('#complete_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('completeModal')).show();
        }

        function openCancelModal(id, candidateName) {
            $('#cancel_onboarding_id').val(id);
            $('#cancel_candidate_name').text(candidateName);
            new bootstrap.Modal(document.getElementById('cancelModal')).show();
        }

        // Export to Excel function
        function exportToExcel() {
            window.location.href = 'export-onboarding.php?' + window.location.search.substring(1);
        }

        // Check for credentials in session and show modal
        <?php if (isset($_SESSION['last_onboarding_credentials'])): ?>
            $(document).ready(function() {
                var creds = <?php echo json_encode($_SESSION['last_onboarding_credentials']); ?>;
                $('#cred_employee_code').val(creds.employee_code);
                $('#cred_username').val(creds.username);
                $('#cred_password').val(creds.password);
                
                var credentialsModal = new bootstrap.Modal(document.getElementById('credentialsModal'));
                credentialsModal.show();
                
                // Copy functionality
                $('.copy-btn').click(function() {
                    var targetId = $(this).data('copy');
                    var textToCopy = $('#' + targetId).val();
                    
                    navigator.clipboard.writeText(textToCopy).then(function() {
                        var originalHtml = $(this).html();
                        $(this).html('<i class="bi bi-check"></i>');
                        setTimeout(function() {
                            $(this).html(originalHtml);
                        }.bind(this), 2000);
                    }.bind(this));
                });
                
                // Toggle password visibility
                $('#togglePasswordVisibility').click(function() {
                    var passwordInput = $('#cred_password');
                    var icon = $(this).find('i');
                    if (passwordInput.attr('type') === 'password') {
                        passwordInput.attr('type', 'text');
                        icon.removeClass('bi-eye').addClass('bi-eye-slash');
                    } else {
                        passwordInput.attr('type', 'password');
                        icon.removeClass('bi-eye-slash').addClass('bi-eye');
                    }
                });
            });
            <?php unset($_SESSION['last_onboarding_credentials']); ?>
        <?php endif; ?>
    </script>
</body>

</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>