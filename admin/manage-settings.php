<?php
// company-settings.php - Company Details Management
// Roles allowed: Admin, Manager, Team Lead

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
// if (empty($_SESSION['employee_id'])) {
//   header("Location: ../login.php");
//   exit;
// }

$employeeId  = (int)$_SESSION['employee_id'];
// $designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
// $role = strtolower(trim((string)($_SESSION['role'] ?? '')));

// $allowed = ['admin', 'manager', 'team lead'];
// if (!in_array($designation, $allowed, true) && !in_array($role, $allowed, true)) {
//   header("Location: index.php");
//   exit;
// }

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------------- Get Employee Name ----------------
$empName = $_SESSION['employee_name'] ?? '';

// ---------------- Fetch Company Details ----------------
$company = null;
$query = "SELECT * FROM company_details WHERE id = 1 LIMIT 1";
$result = mysqli_query($conn, $query);
if ($result && mysqli_num_rows($result) > 0) {
  $company = mysqli_fetch_assoc($result);
}

// ---------------- Handle Form Submission ----------------
$toast_message = '';
$toast_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company'])) {
  
  $company_name = trim($_POST['company_name'] ?? '');
  $company_address = trim($_POST['company_address'] ?? '');
  $company_phone = trim($_POST['company_phone'] ?? '');
  $company_email = trim($_POST['company_email'] ?? '');
  $company_website = trim($_POST['company_website'] ?? '');
  $gst_number = trim($_POST['gst_number'] ?? '');
  $pan_number = trim($_POST['pan_number'] ?? '');
  $ceo_name = trim($_POST['ceo_name'] ?? '');
  $ceo_designation = trim($_POST['ceo_designation'] ?? '');
  $established_date = trim($_POST['established_date'] ?? '');
  
  $errors = [];
  
  // Validation
  if (empty($company_name)) $errors[] = "Company name is required.";
  if (empty($company_address)) $errors[] = "Company address is required.";
  if (empty($company_phone)) $errors[] = "Company phone is required.";
  if (empty($company_email)) $errors[] = "Company email is required.";
  if (empty($ceo_name)) $errors[] = "CEO name is required.";
  
  if (!filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format.";
  }
  
  if (!empty($company_website) && !filter_var($company_website, FILTER_VALIDATE_URL)) {
    $errors[] = "Invalid website URL format.";
  }
  
  if (empty($errors)) {
    if ($company) {
      // Update existing
      $stmt = mysqli_prepare($conn, "
        UPDATE company_details SET
          company_name = ?,
          company_address = ?,
          company_phone = ?,
          company_email = ?,
          company_website = ?,
          gst_number = ?,
          pan_number = ?,
          ceo_name = ?,
          ceo_designation = ?,
          established_date = ?,
          updated_by = ?
        WHERE id = 1
      ");
      
      mysqli_stmt_bind_param(
        $stmt,
        "ssssssssssi",
        $company_name,
        $company_address,
        $company_phone,
        $company_email,
        $company_website,
        $gst_number,
        $pan_number,
        $ceo_name,
        $ceo_designation,
        $established_date,
        $employeeId
      );
    } else {
      // Insert new
      $stmt = mysqli_prepare($conn, "
        INSERT INTO company_details (
          company_name, company_address, company_phone, company_email, 
          company_website, gst_number, pan_number, ceo_name, 
          ceo_designation, established_date, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      
      mysqli_stmt_bind_param(
        $stmt,
        "ssssssssssi",
        $company_name,
        $company_address,
        $company_phone,
        $company_email,
        $company_website,
        $gst_number,
        $pan_number,
        $ceo_name,
        $ceo_designation,
        $established_date,
        $employeeId
      );
    }
    
    if (mysqli_stmt_execute($stmt)) {
      $toast_message = "Company details updated successfully!";
      $toast_type = "success";
      
      // Refresh company data
      $result = mysqli_query($conn, "SELECT * FROM company_details WHERE id = 1 LIMIT 1");
      $company = mysqli_fetch_assoc($result);
    } else {
      $toast_message = "Error updating company details: " . mysqli_stmt_error($stmt);
      $toast_type = "error";
    }
    mysqli_stmt_close($stmt);
  } else {
    $toast_message = implode("<br>", $errors);
    $toast_type = "error";
  }
}

// Handle logo upload separately (optional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo']) && isset($_FILES['company_logo'])) {
  $target_dir = "uploads/company/";
  if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
  }
  
  $file_extension = strtolower(pathinfo($_FILES["company_logo"]["name"], PATHINFO_EXTENSION));
  $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
  
  if (in_array($file_extension, $allowed_extensions)) {
    $new_filename = "company_logo_" . time() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    if (move_uploaded_file($_FILES["company_logo"]["tmp_name"], $target_file)) {
      // Update database with logo path
      $stmt = mysqli_prepare($conn, "UPDATE company_details SET logo_path = ? WHERE id = 1");
      mysqli_stmt_bind_param($stmt, "s", $target_file);
      if (mysqli_stmt_execute($stmt)) {
        $toast_message = "Logo uploaded successfully!";
        $toast_type = "success";
        
        // Refresh company data
        $result = mysqli_query($conn, "SELECT * FROM company_details WHERE id = 1 LIMIT 1");
        $company = mysqli_fetch_assoc($result);
      }
      mysqli_stmt_close($stmt);
    } else {
      $toast_message = "Error uploading logo.";
      $toast_type = "error";
    }
  } else {
    $toast_message = "Invalid file type. Allowed: JPG, JPEG, PNG, GIF, WEBP";
    $toast_type = "error";
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Company Settings - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  
  <!-- Toastr CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .panel{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding:16px;
      margin-bottom:14px;
    }
    .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .h-title{ margin:0; font-weight:1000; color:#111827; }
    .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

    .form-label{ font-weight:900; color:#374151; font-size:13px; }
    .form-control, .form-select{
      border:2px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 12px;
      font-weight: 750;
      font-size: 14px;
    }
    .form-control:focus, .form-select:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45,156,219,.1);
    }

    .sec-head{
      display:flex; align-items:center; gap:10px;
      padding: 10px 12px;
      border-radius: 14px;
      background:#f9fafb;
      border:1px solid #eef2f7;
      margin-bottom:10px;
    }
    .sec-ic{
      width:34px;height:34px;border-radius: 12px;
      display:grid;place-items:center;
      background: rgba(45,156,219,.12);
      color: var(--blue);
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

    .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
    .grid-3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; }
    .grid-4{ display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:12px; }
    @media (max-width: 992px){
      .grid-2, .grid-3, .grid-4{ grid-template-columns: 1fr; }
    }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
    }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

    .btn-primary-tek{
      background: var(--blue);
      border:none;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 1000;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
      color:#fff;
    }
    .btn-primary-tek:hover{ background:#2a8bc9; color:#fff; }
    .btn-outline-tek{
      border:2px solid var(--blue);
      background: transparent;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 1000;
      color: var(--blue);
    }
    .btn-outline-tek:hover{ background: var(--blue); color:#fff; }

    .logo-preview{
      width: 120px;
      height: 120px;
      border: 2px solid #e5e7eb;
      border-radius: 16px;
      object-fit: cover;
      background: #f9fafb;
    }
    .info-card{
      background: #f9fafb;
      border-radius: 14px;
      padding: 12px;
      border: 1px solid #eef2f7;
    }
    .info-label{
      font-weight: 900;
      color: #6b7280;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .info-value{
      font-weight: 1000;
      color: #111827;
      font-size: 15px;
      margin-top: 4px;
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

    <div class="content-scroll">
      <div class="container-fluid maxw">

        <div class="title-row mb-3">
          <div>
            <h1 class="h-title">Company Settings</h1>
            <p class="h-sub">Manage company details and information</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-building"></i> Company Profile</span>
            <span class="badge-pill"><i class="bi bi-shield-lock"></i> Admin Only</span>
          </div>
        </div>

        <!-- COMPANY INFO PANEL -->
        <form method="POST" id="companyForm">
          <input type="hidden" name="update_company" value="1">
          
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-building"></i></div>
              <div>
                <p class="sec-title mb-0">Basic Information</p>
                <p class="sec-sub mb-0">Company name, contact details, and registration</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="company_name" 
                       value="<?php echo e($company['company_name'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="form-label">Company Phone <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="company_phone" 
                       value="<?php echo e($company['company_phone'] ?? ''); ?>" required>
              </div>
            </div>

            <div class="grid-2 mt-2">
              <div>
                <label class="form-label">Company Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" name="company_email" 
                       value="<?php echo e($company['company_email'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="form-label">Company Website</label>
                <input type="url" class="form-control" name="company_website" 
                       value="<?php echo e($company['company_website'] ?? ''); ?>" placeholder="https://">
              </div>
            </div>

            <div class="mt-2">
              <label class="form-label">Company Address <span class="text-danger">*</span></label>
              <textarea class="form-control" name="company_address" rows="2" required><?php echo e($company['company_address'] ?? ''); ?></textarea>
            </div>
          </div>

          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-file-earmark-text"></i></div>
              <div>
                <p class="sec-title mb-0">Registration Details</p>
                <p class="sec-sub mb-0">GST, PAN, and other identifiers</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">GST Number</label>
                <input type="text" class="form-control" name="gst_number" 
                       value="<?php echo e($company['gst_number'] ?? ''); ?>" placeholder="27ABCDE1234F1Z5">
              </div>
              <div>
                <label class="form-label">PAN Number</label>
                <input type="text" class="form-control" name="pan_number" 
                       value="<?php echo e($company['pan_number'] ?? ''); ?>" placeholder="ABCDE1234F">
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-person-badge"></i></div>
              <div>
                <p class="sec-title mb-0">Leadership Information</p>
                <p class="sec-sub mb-0">CEO details and establishment</p>
              </div>
            </div>

            <div class="grid-3">
              <div>
                <label class="form-label">CEO Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="ceo_name" 
                       value="<?php echo e($company['ceo_name'] ?? ''); ?>" required>
              </div>
              <div>
                <label class="form-label">CEO Designation</label>
                <input type="text" class="form-control" name="ceo_designation" 
                       value="<?php echo e($company['ceo_designation'] ?? 'Chief Executive Officer'); ?>">
              </div>
              <div>
                <label class="form-label">Established Date</label>
                <input type="date" class="form-control" name="established_date" 
                       value="<?php echo e($company['established_date'] ?? ''); ?>">
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek">
                <i class="bi bi-save"></i> Save Company Details
              </button>
            </div>
          </div>
        </form>

        <!-- LOGO UPLOAD PANEL (Optional) -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-image"></i></div>
            <div>
              <p class="sec-title mb-0">Company Logo</p>
              <p class="sec-sub mb-0">Upload company logo (JPG, PNG, GIF, WEBP)</p>
            </div>
          </div>

          <div class="row">
            <div class="col-md-3 text-center">
              <?php if (!empty($company['logo_path']) && file_exists($company['logo_path'])): ?>
                <img src="<?php echo e($company['logo_path']); ?>" class="logo-preview" alt="Company Logo">
              <?php else: ?>
                <div class="logo-preview d-flex align-items-center justify-content-center bg-light">
                  <i class="bi bi-building" style="font-size: 40px; color: #ccc;"></i>
                </div>
              <?php endif; ?>
            </div>
            <div class="col-md-9">
              <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="upload_logo" value="1">
                <div class="mb-3">
                  <label class="form-label">Select Logo Image</label>
                  <input type="file" class="form-control" name="company_logo" accept="image/*" required>
                </div>
                <button type="submit" class="btn-outline-tek">
                  <i class="bi bi-upload"></i> Upload Logo
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- PREVIEW PANEL -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-eye"></i></div>
            <div>
              <p class="sec-title mb-0">Company Information Preview</p>
              <p class="sec-sub mb-0">How company details will appear</p>
            </div>
          </div>

          <div class="info-card">
            <div class="row">
              <div class="col-md-8">
                <div class="info-label">Company Name</div>
                <div class="info-value"><?php echo e($company['company_name'] ?? 'Not set'); ?></div>
                
                <div class="row mt-3">
                  <div class="col-md-6">
                    <div class="info-label">Phone</div>
                    <div class="info-value"><?php echo e($company['company_phone'] ?? 'Not set'); ?></div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo e($company['company_email'] ?? 'Not set'); ?></div>
                  </div>
                </div>
                
                <div class="mt-3">
                  <div class="info-label">Address</div>
                  <div class="info-value"><?php echo e($company['company_address'] ?? 'Not set'); ?></div>
                </div>
              </div>
              <div class="col-md-4">
                <div class="info-label">CEO</div>
                <div class="info-value"><?php echo e($company['ceo_name'] ?? 'Not set'); ?></div>
                <div class="info-label mt-2">Designation</div>
                <div class="info-value"><?php echo e($company['ceo_designation'] ?? 'Not set'); ?></div>
                <?php if (!empty($company['gst_number'])): ?>
                  <div class="info-label mt-2">GST</div>
                  <div class="info-value"><?php echo e($company['gst_number']); ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- LAST UPDATED INFO -->
        <?php if (!empty($company['updated_at'])): ?>
          <div class="text-end small-muted">
            Last updated: <?php echo date('d M Y h:i A', strtotime($company['updated_at'])); ?>
            <?php if (!empty($company['updated_by'])): ?>
              <?php
                $updater = mysqli_query($conn, "SELECT full_name FROM employees WHERE id = {$company['updated_by']}");
                if ($updater && $u = mysqli_fetch_assoc($updater)) {
                  echo " by " . e($u['full_name']);
                }
              ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<!-- Toastr JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
// Toastr configuration
toastr.options = {
  "closeButton": true,
  "progressBar": true,
  "positionClass": "toast-top-right",
  "timeOut": "5000",
  "extendedTimeOut": "2000",
  "showMethod": "slideDown",
  "hideMethod": "slideUp",
  "tapToDismiss": false
};

// Show toast messages if any
<?php if (!empty($toast_message)): ?>
  <?php if ($toast_type === 'success'): ?>
    toastr.success('<?php echo addslashes($toast_message); ?>', 'Success');
  <?php else: ?>
    toastr.error('<?php echo addslashes($toast_message); ?>', 'Error');
  <?php endif; ?>
<?php endif; ?>

// Form validation before submit
document.getElementById('companyForm')?.addEventListener('submit', function(e) {
  const companyName = document.querySelector('[name="company_name"]').value.trim();
  const companyPhone = document.querySelector('[name="company_phone"]').value.trim();
  const companyEmail = document.querySelector('[name="company_email"]').value.trim();
  const companyAddress = document.querySelector('[name="company_address"]').value.trim();
  const ceoName = document.querySelector('[name="ceo_name"]').value.trim();
  
  let errors = [];
  
  if (!companyName) errors.push('Company name is required');
  if (!companyPhone) errors.push('Company phone is required');
  if (!companyEmail) errors.push('Company email is required');
  if (!companyAddress) errors.push('Company address is required');
  if (!ceoName) errors.push('CEO name is required');
  
  if (companyEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(companyEmail)) {
    errors.push('Invalid email format');
  }
  
  if (errors.length > 0) {
    e.preventDefault();
    toastr.error(errors.join('<br>'), 'Validation Error');
  }
});
</script>

</body>
</html>