<?php
// add-employee.php
session_start();
require_once 'includes/db-config.php';

// Initialize variables
$success = '';
$error = '';
$validation_errors = [];

// Get database connection
$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

// Departments and designations arrays (match your ENUM values)
$departments = ['PM', 'CM', 'IFM', 'QS', 'HR', 'ACCOUNTS'];
$designations = [
    'Vice President',
    'General Manager',
    'Director', // ✅ Added Director
    'Manager',
    'QS Manager',
    'QS Engineer',
    'QS & Contracts',
    'Team Lead',
    'Sr. Engineer',
    'Project Engineer Grade 1',
    'Project Engineer Grade 2',
    'HR',
    'Accountant',
    'employee'
];
$genders = ['Male', 'Female', 'Other'];
$blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$employee_statuses = ['active', 'inactive', 'resigned'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect all form data with default empty strings
    $full_name = trim($_POST['full_name'] ?? '');
    $employee_code = trim($_POST['employee_code'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $blood_group = trim($_POST['blood_group'] ?? '');
    
    $mobile_number = trim($_POST['mobile_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $current_address = trim($_POST['current_address'] ?? '');
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
    
    $date_of_joining = trim($_POST['date_of_joining'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $reporting_manager = trim($_POST['reporting_manager'] ?? '');
    $work_location = trim($_POST['work_location'] ?? '');
    $site_name = trim($_POST['site_name'] ?? '');
    $employee_status = trim($_POST['employee_status'] ?? 'active');
    
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    $aadhar_card_number = trim($_POST['aadhar_card_number'] ?? '');
    $pancard_number = trim($_POST['pancard_number'] ?? '');
    
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $ifsc_code = trim($_POST['ifsc_code'] ?? '');
    
    // Validate required fields (ONLY ESSENTIAL ONES)
    if (empty($full_name)) $validation_errors[] = "Full name is required";
    if (empty($employee_code)) $validation_errors[] = "Employee code is required";
    if (empty($mobile_number)) $validation_errors[] = "Mobile number is required";
    if (empty($date_of_joining)) $validation_errors[] = "Date of joining is required";
    if (empty($department)) $validation_errors[] = "Department is required";
    if (empty($designation)) $validation_errors[] = "Designation is required";
    if (empty($username)) $validation_errors[] = "Username is required";
    if (empty($password)) $validation_errors[] = "Password is required";
    
    // Validate mobile number format (if provided)
    if (!empty($mobile_number) && !preg_match('/^[0-9]{10}$/', $mobile_number)) {
        $validation_errors[] = "Mobile number must be 10 digits";
    }
    
    // Validate email if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = "Invalid email format";
    }
    
    // Validate Aadhar number if provided
    if (!empty($aadhar_card_number) && !preg_match('/^[0-9]{12}$/', $aadhar_card_number)) {
        $validation_errors[] = "Aadhar number must be 12 digits";
    }
    
    // Validate PAN card number if provided
    if (!empty($pancard_number) && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pancard_number)) {
        $validation_errors[] = "PAN card number must be in format: ABCDE1234F";
    }

    // ✅ Validate designation matches allowed list (including Director)
    if (!empty($designation) && !in_array($designation, $designations, true)) {
        $validation_errors[] = "Invalid designation selected";
    }

    // Check if employee code already exists
    if (!empty($employee_code)) {
        $check_stmt = mysqli_prepare($conn, "SELECT id FROM employees WHERE employee_code = ?");
        mysqli_stmt_bind_param($check_stmt, "s", $employee_code);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $validation_errors[] = "Employee code already exists";
        }
        mysqli_stmt_close($check_stmt);
    }
    
    // Handle file uploads (optional) - CORRECT COLUMN NAMES
    $photo = '';
    $passbook_photo = '';
    
    // Upload photo (optional)
    if (!empty($_FILES['photo']['name'])) {
        $photo_upload = handleFileUpload('photo', 'employees/photos/');
        if ($photo_upload['success']) {
            $photo = $photo_upload['path'];
        } else {
            $validation_errors[] = $photo_upload['error'];
        }
    }
    
    // Upload passbook photo (optional)
    if (!empty($_FILES['passbook_photo']['name'])) {
        $passbook_upload = handleFileUpload('passbook_photo', 'employees/passbook/');
        if ($passbook_upload['success']) {
            $passbook_photo = $passbook_upload['path'];
        } else {
            $validation_errors[] = $passbook_upload['error'];
        }
    }
    
    // If no validation errors, insert into database
    if (empty($validation_errors)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Prepare SQL statement
        $sql = "INSERT INTO employees (
            full_name, employee_code, photo, date_of_birth, gender, blood_group,
            mobile_number, email, current_address, emergency_contact_name, emergency_contact_phone,
            date_of_joining, department, designation, reporting_manager, work_location, site_name, employee_status,
            username, password,
            aadhar_card_number, pancard_number,
            bank_account_number, ifsc_code, passbook_photo
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sssssssssssssssssssssssss", 
                $full_name, $employee_code, $photo, $date_of_birth, $gender, $blood_group,
                $mobile_number, $email, $current_address, $emergency_contact_name, $emergency_contact_phone,
                $date_of_joining, $department, $designation, $reporting_manager, $work_location, $site_name, $employee_status,
                $username, $hashed_password,
                $aadhar_card_number, $pancard_number,
                $bank_account_number, $ifsc_code, $passbook_photo
            );
            
            if (mysqli_stmt_execute($stmt)) {
                $success = "Employee added successfully!";
                $_POST = array();
            } else {
                $error = "Error adding employee: " . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = "Database error: " . mysqli_error($conn);
        }
    }
}

// Function to handle file uploads
function handleFileUpload($field_name, $target_dir) {
    $upload_dir = 'uploads/' . $target_dir;
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file = $_FILES[$field_name];
    $file_name = basename($file['name']);
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_error = $file['error'];
    
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $new_file_name = uniqid() . '_' . time() . '.' . $file_ext;
    $target_path = $upload_dir . $new_file_name;
    
    if ($file_error !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error: ' . $file_error];
    }
    
    if ($file_size > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File size too large (max 5MB)'];
    }
    
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($file_ext, $allowed_extensions, true)) {
        return ['success' => false, 'error' => 'Only image files are allowed'];
    }
    
    if (move_uploaded_file($file_tmp, $target_path)) {
        return ['success' => true, 'path' => $target_path];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
}

// Function to get existing employees for reporting manager dropdown
function getExistingEmployees($conn) {
    $employees = [];
    $result = mysqli_query($conn, "SELECT id, full_name, employee_code, designation FROM employees WHERE employee_status = 'active' ORDER BY full_name");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $employees[] = $row;
        }
        mysqli_free_result($result);
    }
    return $employees;
}

$existing_employees = getExistingEmployees($conn);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Add Employee - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <!-- TEK-C Custom Styles -->
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
    /* Content Styles - Inline */
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }

    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; }
    .table td{ vertical-align:middle; border-color: var(--border); font-weight:650; color:#374151; padding-top:14px; padding-bottom:14px; }

    .muted-link{ color:#6b7280; font-weight:800; text-decoration:none; }
    .muted-link:hover{ color:#374151; }

    /* Add Employee Specific Styles */
    .btn-add {
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
    }
    .btn-add:hover {
      background: #2a8bc9;
      color: white;
      box-shadow: 0 12px 24px rgba(45, 156, 219, 0.25);
    }

    .btn-back {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 8px 16px;
      color: #4a5568;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
    }
    .btn-back:hover {
      background: var(--bg);
      color: var(--blue);
      border-color: var(--blue);
    }

    .form-panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 25px;
      margin-bottom: 30px;
    }

    .section-header {
      display: flex;
      align-items: center;
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 2px solid #f0f4f8;
    }

    .section-icon {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      background: var(--blue);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 15px;
      font-size: 20px;
      color: white;
    }

    .section-title {
      font-size: 18px;
      font-weight: 800;
      color: #2d3748;
      margin: 0;
    }

    .section-subtitle {
      font-size: 14px;
      color: #718096;
      margin-top: 4px;
    }

    .form-label {
      font-weight: 700;
      color: #4a5568;
      margin-bottom: 8px;
      font-size: 14px;
    }

    .required-label::after {
      content: " *";
      color: #e53e3e;
      font-weight: 900;
    }

    .form-control, .form-select {
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      padding: 12px 15px;
      font-size: 14px;
      transition: all 0.3s;
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
    }

    .form-control.is-invalid {
      border-color: #fc8181;
      background: #fff5f5;
    }

    .file-upload-container {
      border: 2px dashed #cbd5e0;
      border-radius: 12px;
      padding: 20px;
      text-align: center;
      background: #f8fafc;
      cursor: pointer;
      transition: all 0.3s;
      margin-top: 5px;
    }

    .file-upload-container:hover {
      border-color: var(--blue);
      background: #f0f4ff;
    }

    .file-upload-icon {
      font-size: 40px;
      color: #a0aec0;
      margin-bottom: 10px;
    }

    .file-upload-text {
      font-size: 14px;
      color: #718096;
      margin-bottom: 5px;
    }

    .file-upload-subtext {
      font-size: 12px;
      color: #a0aec0;
    }

    .file-preview {
      width: 120px;
      height: 120px;
      border-radius: 12px;
      overflow: hidden;
      margin: 10px auto;
      border: 3px solid #e2e8f0;
      background: white;
      position: relative;
    }

    .file-preview img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .file-remove {
      position: absolute;
      top: -8px;
      right: -8px;
      width: 28px;
      height: 28px;
      background: #e53e3e;
      color: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      cursor: pointer;
      border: 2px solid white;
      box-shadow: 0 2px 8px rgba(229, 62, 62, 0.3);
    }

    .btn-submit {
      background: var(--blue);
      color: white;
      border: none;
      padding: 14px 35px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 15px;
      display: flex;
      align-items: center;
      gap: 10px;
      box-shadow: 0 8px 20px rgba(45, 156, 219, 0.2);
      transition: all 0.3s;
      margin: 40px auto;
    }

    .btn-submit:hover {
      background: #2a8bc9;
      transform: translateY(-2px);
      box-shadow: 0 12px 25px rgba(45, 156, 219, 0.3);
      color: white;
    }

    .form-section-heading {
      font-size: 16px;
      font-weight: 800;
      color: #4a5568;
      margin: 20px 0 15px;
      padding-left: 12px;
      border-left: 4px solid var(--blue);
    }

    .alert {
      border-radius: var(--radius);
      border: none;
      box-shadow: var(--shadow);
      margin-bottom: 20px;
    }

    .form-helper {
      font-size: 12px;
      color: #718096;
      margin-top: 5px;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .form-helper i {
      font-size: 14px;
    }

    .optional-badge {
      font-size: 11px;
      color: #718096;
      font-weight: 600;
      margin-left: 5px;
    }

    @media (max-width: 768px) {
      .content-scroll {
        padding: 18px;
      }
      
      .form-panel {
        padding: 20px;
      }
      
      .section-header {
        flex-direction: column;
        text-align: center;
      }
      
      .section-icon {
        margin-right: 0;
        margin-bottom: 15px;
      }
    }
    @media (max-width: 768px) {
  .content-scroll {
    padding: 12px 10px 12px !important;   /* was 22px */
  }

  .container-fluid.maxw {
    padding-left: 6px !important;
    padding-right: 6px !important;
  }

  .panel {
    padding: 12px !important;
    margin-bottom: 12px;
    border-radius: 14px;
  }

  .sec-head {
    padding: 10px !important;
    border-radius: 12px;
  }
}
    </style>
</head>
<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main" aria-label="Main">
        <?php include 'includes/topbar.php'; ?>
        
        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid maxw">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Add New Employee</h1>
                        <p class="text-muted mb-0">Complete essential details to register a new team member</p>
                    </div>
                    <a href="employees.php" class="btn-back">
                        <i class="bi bi-arrow-left"></i> Back to Directory
                    </a>
                </div>
                
                <!-- Alerts -->
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <strong>Success!</strong> <?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Error!</strong> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($validation_errors)): ?>
                    <div class="alert alert-warning alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Please fix the following errors:</strong>
                        <ul class="mb-0 mt-2 ps-3">
                            <?php foreach ($validation_errors as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Employee Form -->
                <form method="POST" enctype="multipart/form-data" id="employeeForm" novalidate>
                    
                    <!-- Section 1: Identity Details -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <div>
                                <h3 class="section-title">Identity Details</h3>
                                <p class="section-subtitle">Basic personal information</p>
                            </div>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="full_name" class="form-label required-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                                       placeholder="Enter full name" required>
                                <div class="form-helper">
                                    <i class="bi bi-info-circle"></i> Enter employee's full name
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="employee_code" class="form-label required-label">Employee Code</label>
                                <input type="text" class="form-control" id="employee_code" name="employee_code" 
                                       value="<?php echo htmlspecialchars($_POST['employee_code'] ?? ''); ?>" 
                                       placeholder="EMP001" required>
                                <div class="form-helper">
                                    <i class="bi bi-hash"></i> Unique identifier for the employee
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="date_of_birth" class="form-label">Date of Birth <span class="optional-badge">(Optional)</span></label>
                                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                       value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>">
                                <div class="form-helper">
                                    <i class="bi bi-calendar"></i> Format: DD/MM/YYYY
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="gender" class="form-label">Gender <span class="optional-badge">(Optional)</span></label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="">Select Gender</option>
                                    <?php foreach ($genders as $g): ?>
                                        <option value="<?php echo $g; ?>" <?php echo (($_POST['gender'] ?? '') === $g) ? 'selected' : ''; ?>>
                                            <?php echo $g; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="blood_group" class="form-label">Blood Group <span class="optional-badge">(Optional)</span></label>
                                <select class="form-select" id="blood_group" name="blood_group">
                                    <option value="">Select Blood Group</option>
                                    <?php foreach ($blood_groups as $bg): ?>
                                        <option value="<?php echo $bg; ?>" <?php echo (($_POST['blood_group'] ?? '') === $bg) ? 'selected' : ''; ?>>
                                            <?php echo $bg; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="photo" class="form-label">Profile Photo <span class="optional-badge">(Optional)</span></label>
                                <div class="file-upload-container" onclick="document.getElementById('photo').click()" 
                                     id="photoUploadContainer">
                                    <div class="file-upload-icon">
                                        <i class="bi bi-person-square"></i>
                                    </div>
                                    <div class="file-upload-text">Click to upload photo</div>
                                    <div class="file-upload-subtext">JPG, PNG, WebP (Max 5MB)</div>
                                    <input type="file" class="d-none" id="photo" name="photo" accept="image/*">
                                </div>
                                <div class="file-preview d-none" id="photoPreview">
                                    <img src="" alt="Photo Preview">
                                    <div class="file-remove" onclick="removeFile('photo')">
                                        <i class="bi bi-x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 2: Contact Details -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon" style="background: #f5576c;">
                                <i class="bi bi-telephone"></i>
                            </div>
                            <div>
                                <h3 class="section-title">Contact Details</h3>
                                <p class="section-subtitle">Communication information</p>
                            </div>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="mobile_number" class="form-label required-label">Mobile Number</label>
                                <input type="tel" class="form-control" id="mobile_number" name="mobile_number" 
                                       value="<?php echo htmlspecialchars($_POST['mobile_number'] ?? ''); ?>" 
                                       placeholder="9876543210" required>
                                <div class="form-helper">
                                    <i class="bi bi-phone"></i> 10 digits without country code
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email Address <span class="optional-badge">(Optional)</span></label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                       placeholder="employee@company.com">
                                <div class="form-helper">
                                    <i class="bi bi-envelope"></i> Official email address
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="current_address" class="form-label">Current Address <span class="optional-badge">(Optional)</span></label>
                                <textarea class="form-control" id="current_address" name="current_address" 
                                          rows="2" placeholder="Enter complete address"><?php echo htmlspecialchars($_POST['current_address'] ?? ''); ?></textarea>
                            </div>
                            
                            <h5 class="form-section-heading">Emergency Contact <span class="optional-badge">(Optional)</span></h5>
                            
                            <div class="col-md-6">
                                <label for="emergency_contact_name" class="form-label">Contact Name</label>
                                <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name" 
                                       value="<?php echo htmlspecialchars($_POST['emergency_contact_name'] ?? ''); ?>" 
                                       placeholder="Relative or friend's name">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="emergency_contact_phone" class="form-label">Contact Phone</label>
                                <input type="tel" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" 
                                       value="<?php echo htmlspecialchars($_POST['emergency_contact_phone'] ?? ''); ?>" 
                                       placeholder="9876543210">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 3: Employment Details -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon" style="background: #4facfe;">
                                <i class="bi bi-briefcase"></i>
                            </div>
                            <div>
                                <h3 class="section-title">Employment Details</h3>
                                <p class="section-subtitle">Professional information</p>
                            </div>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="date_of_joining" class="form-label required-label">Date of Joining</label>
                                <input type="date" class="form-control" id="date_of_joining" name="date_of_joining" 
                                       value="<?php echo htmlspecialchars($_POST['date_of_joining'] ?? ''); ?>" required>
                                <div class="form-helper">
                                    <i class="bi bi-calendar-check"></i> Official joining date
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="department" class="form-label required-label">Department</label>
                                <select class="form-select" id="department" name="department" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept; ?>" <?php echo (($_POST['department'] ?? '') === $dept) ? 'selected' : ''; ?>>
                                            <?php echo $dept; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="designation" class="form-label required-label">Designation</label>
                                <select class="form-select" id="designation" name="designation" required>
                                    <option value="">Select Designation</option>
                                    <?php foreach ($designations as $des): ?>
                                        <option value="<?php echo $des; ?>" <?php echo (($_POST['designation'] ?? '') === $des) ? 'selected' : ''; ?>>
                                            <?php echo $des; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="employee_status" class="form-label">Employment Status <span class="optional-badge">(Optional)</span></label>
                                <select class="form-select" id="employee_status" name="employee_status">
                                    <?php foreach ($employee_statuses as $status): ?>
                                        <option value="<?php echo $status; ?>" <?php echo (($_POST['employee_status'] ?? 'active') === $status) ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <h5 class="form-section-heading">Reporting & Location <span class="optional-badge">(Optional)</span></h5>
                            
                            <div class="col-md-6">
                                <label for="reporting_manager" class="form-label">Reporting Manager</label>
                                <select class="form-select" id="reporting_manager" name="reporting_manager">
                                    <option value="">Select Reporting Manager</option>
                                    <?php foreach ($existing_employees as $emp): ?>
                                        <option value="<?php echo htmlspecialchars($emp['full_name']); ?>" <?php echo (($_POST['reporting_manager'] ?? '') === $emp['full_name']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['full_name'] . ' - ' . $emp['designation']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="work_location" class="form-label">Work Location</label>
                                <input type="text" class="form-control" id="work_location" name="work_location" 
                                       value="<?php echo htmlspecialchars($_POST['work_location'] ?? ''); ?>" 
                                       placeholder="City or Office location">
                            </div>
                            
                            <div class="col-md-12">
                                <label for="site_name" class="form-label">Site/Project Name</label>
                                <input type="text" class="form-control" id="site_name" name="site_name" 
                                       value="<?php echo htmlspecialchars($_POST['site_name'] ?? ''); ?>" 
                                       placeholder="Current project or site assignment">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 4: Login Details -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon" style="background: #fa709a;">
                                <i class="bi bi-key"></i>
                            </div>
                            <div>
                                <h3 class="section-title">Login Credentials</h3>
                                <p class="section-subtitle">System access credentials</p>
                            </div>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="username" class="form-label required-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                                       placeholder="username" required>
                                <div class="form-helper">
                                    <i class="bi bi-person-circle"></i> Usually same as employee code
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="password" class="form-label required-label">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="Minimum 8 characters" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary" type="button" id="generatePasswordBtn">
                                        <i class="bi bi-shuffle"></i>
                                    </button>
                                </div>
                                <div class="form-helper">
                                    <i class="bi bi-shield-lock"></i> Strong password recommended
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 5: Compliance Documents -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon" style="background: #30cfd0;">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <div>
                                <h3 class="section-title">Compliance Documents <span class="optional-badge">(Optional)</span></h3>
                                <p class="section-subtitle">Official documents (can be added later)</p>
                            </div>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="aadhar_card_number" class="form-label">Aadhar Card Number</label>
                                <input type="text" class="form-control" id="aadhar_card_number" name="aadhar_card_number" 
                                       value="<?php echo htmlspecialchars($_POST['aadhar_card_number'] ?? ''); ?>" 
                                       placeholder="1234 5678 9012">
                                <div class="form-helper">
                                    <i class="bi bi-card-text"></i> 12-digit Aadhar number
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="pancard_number" class="form-label">PAN Card Number</label>
                                <input type="text" class="form-control" id="pancard_number" name="pancard_number" 
                                       value="<?php echo htmlspecialchars($_POST['pancard_number'] ?? ''); ?>" 
                                       placeholder="ABCDE1234F">
                                <div class="form-helper">
                                    <i class="bi bi-card-text"></i> Format: ABCDE1234F
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Section 6: Banking Details -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon" style="background: #43e97b;">
                                <i class="bi bi-bank"></i>
                            </div>
                            <div>
                                <h3 class="section-title">Banking Information <span class="optional-badge">(Optional)</span></h3>
                                <p class="section-subtitle">Salary details (can be added later)</p>
                            </div>
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="bank_account_number" class="form-label">Bank Account Number</label>
                                <input type="text" class="form-control" id="bank_account_number" name="bank_account_number" 
                                       value="<?php echo htmlspecialchars($_POST['bank_account_number'] ?? ''); ?>" 
                                       placeholder="123456789012">
                                <div class="form-helper">
                                    <i class="bi bi-credit-card"></i> Employee's personal account
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="ifsc_code" class="form-label">IFSC Code</label>
                                <input type="text" class="form-control" id="ifsc_code" name="ifsc_code" 
                                       value="<?php echo htmlspecialchars($_POST['ifsc_code'] ?? ''); ?>" 
                                       placeholder="SBIN0001234">
                                <div class="form-helper">
                                    <i class="bi bi-bank"></i> 11-character bank code
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label for="passbook_photo" class="form-label">Passbook/Cancelled Cheque</label>
                                <div class="file-upload-container" onclick="document.getElementById('passbook_photo').click()" 
                                     id="passbook_photoUploadContainer">
                                    <div class="file-upload-icon">
                                        <i class="bi bi-file-image"></i>
                                    </div>
                                    <div class="file-upload-text">Upload passbook or cheque</div>
                                    <div class="file-upload-subtext">First page or cancelled cheque (Max 5MB)</div>
                                    <input type="file" class="d-none" id="passbook_photo" name="passbook_photo" accept="image/*">
                                </div>
                                <div class="file-preview d-none" id="passbook_photoPreview">
                                    <img src="" alt="Passbook Preview">
                                    <div class="file-remove" onclick="removeFile('passbook_photo')">
                                        <i class="bi bi-x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="text-center mt-5">
                        <button type="submit" class="btn-submit">
                            <i class="bi bi-person-plus"></i> Add Employee to System
                        </button>
                        <p class="text-muted mt-3" style="font-size: 14px;">
                            <i class="bi bi-info-circle me-1"></i>
                            Fields marked with * are required. Other fields can be added later.
                        </p>
                    </div>
                    
                </form>
                
            </div>
        </div>
        
        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // File upload functionality (optional fields)
        const fileInputs = ['photo', 'passbook_photo'];
        
        fileInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            const container = document.getElementById(inputId + 'UploadContainer');
            const preview = document.getElementById(inputId + 'Preview');
            const previewImg = preview.querySelector('img');
            
            if (!input || !container || !preview || !previewImg) return;

            input.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(ev) {
                        previewImg.src = ev.target.result;
                        preview.classList.remove('d-none');
                        container.classList.add('d-none');
                    }
                    reader.readAsDataURL(this.files[0]);
                }
            });
        });
        
        // Remove file function
        window.removeFile = function(inputId) {
            const input = document.getElementById(inputId);
            const container = document.getElementById(inputId + 'UploadContainer');
            const preview = document.getElementById(inputId + 'Preview');
            if (!input || !container || !preview) return;

            input.value = '';
            preview.classList.add('d-none');
            container.classList.remove('d-none');
        };
        
        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
            });
        }
        
        // Generate password
        const generatePasswordBtn = document.getElementById('generatePasswordBtn');
        if (generatePasswordBtn && passwordInput) {
            generatePasswordBtn.addEventListener('click', function() {
                const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
                let password = '';
                for (let i = 0; i < 12; i++) {
                    password += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                passwordInput.value = password;
                passwordInput.setAttribute('type', 'text');
                if (togglePassword) togglePassword.innerHTML = '<i class="bi bi-eye-slash"></i>';
            });
        }
        
        // Auto-fill username from employee code
        const employeeCodeInput = document.getElementById('employee_code');
        const usernameInput = document.getElementById('username');
        
        if (employeeCodeInput && usernameInput) {
            employeeCodeInput.addEventListener('blur', function() {
                if (!usernameInput.value && this.value) {
                    usernameInput.value = this.value.toLowerCase()
                        .replace(/\s+/g, '')
                        .replace(/[^a-z0-9]/g, '');
                }
            });
        }
        
        // Form validation (only for required fields)
        const form = document.getElementById('employeeForm');
        form.addEventListener('submit', function(e) {
            let valid = true;
            const requiredFields = form.querySelectorAll('[required]');
            const errorMessages = [];
            
            requiredFields.forEach(field => field.classList.remove('is-invalid'));
            
            requiredFields.forEach(field => {
                if (!String(field.value || '').trim()) {
                    valid = false;
                    field.classList.add('is-invalid');
                    const label = form.querySelector(`label[for="${field.id}"]`);
                    const fieldName = label ? label.textContent.replace(' *', '') : field.name;
                    errorMessages.push(`${fieldName} is required`);
                }
            });

            const mobileInput = document.getElementById('mobile_number');
            if (mobileInput && mobileInput.value && !/^[0-9]{10}$/.test(mobileInput.value)) {
                valid = false;
                mobileInput.classList.add('is-invalid');
                errorMessages.push('Mobile number must be 10 digits');
            }
            
            const emailInput = document.getElementById('email');
            if (emailInput && emailInput.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value)) {
                valid = false;
                emailInput.classList.add('is-invalid');
                errorMessages.push('Invalid email format');
            }
            
            const aadharInput = document.getElementById('aadhar_card_number');
            if (aadharInput && aadharInput.value && !/^[0-9]{12}$/.test(aadharInput.value)) {
                valid = false;
                aadharInput.classList.add('is-invalid');
                errorMessages.push('Aadhar number must be 12 digits');
            }
            
            const pancardInput = document.getElementById('pancard_number');
            if (pancardInput && pancardInput.value && !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pancardInput.value)) {
                valid = false;
                pancardInput.classList.add('is-invalid');
                errorMessages.push('PAN card must be in format: ABCDE1234F');
            }
            
            if (passwordInput && passwordInput.value.length < 8) {
                valid = false;
                passwordInput.classList.add('is-invalid');
                errorMessages.push('Password must be at least 8 characters');
            }
            
            if (!valid) {
                e.preventDefault();
                
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                alertDiv.innerHTML = `
                    <div class="d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div>
                            <strong>Form Validation Error</strong>
                            <ul class="mb-0 mt-2 ps-3">
                                ${errorMessages.map(msg => `<li>${msg}</li>`).join('')}
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                const formContainer = document.querySelector('.container-fluid.maxw');
                const firstChild = formContainer ? formContainer.children[2] : null;
                if (formContainer && firstChild) formContainer.insertBefore(alertDiv, firstChild);
                
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
        
        // Real-time validation for PAN card (uppercase - only if provided)
        const pancardInput = document.getElementById('pancard_number');
        if (pancardInput) {
            pancardInput.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        }
        
        // Real-time validation for mobile (numbers only - only if provided)
        const mobileInput = document.getElementById('mobile_number');
        if (mobileInput) {
            mobileInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 10);
            });
        }
        
        // Real-time validation for Aadhar (numbers only - only if provided)
        const aadharInput = document.getElementById('aadhar_card_number');
        if (aadharInput) {
            aadharInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 12);
            });
        }
        
        // Real-time validation for bank account (numbers only - only if provided)
        const bankInput = document.getElementById('bank_account_number');
        if (bankInput) {
            bankInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '');
            });
        }
    });
</script>

</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>
