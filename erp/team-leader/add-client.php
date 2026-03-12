<?php
// add-client.php (MODIFIED)
// Only these sections/fields:
// 1) Client information:
//    - client_name, mobile_number, email, client_type, company_name (optional),
//      office_address, site_address, state
// 2) Legal & compliance:
//    - pan_number, gst_number (optional), aadhaar_number (required only if Individual),
//      billing_address, shipping_address
//
// NOTE: This code assumes your `clients` table still has many other NOT NULL columns
// like project_name, project_type, etc. If they are still NOT NULL in DB, this insert will FAIL.
// Fix option A (recommended): make those extra columns NULLABLE or give defaults in DB.
// Fix option B: add those missing fields back to the form and insert them.
//
// For now, this code inserts ONLY the above columns (and password for login).

session_start();
require_once 'includes/db-config.php';

$success = '';
$error = '';
$validation_errors = [];

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

/**
 * OPTIONS
 */
$client_types = ['Individual', 'Builder', 'Developer', 'Govt', 'Corporate', 'Company', 'Organization'];

$states = [
    'Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat','Haryana','Himachal Pradesh',
    'Jharkhand','Karnataka','Kerala','Madhya Pradesh','Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha',
    'Punjab','Rajasthan','Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal',
    'Andaman and Nicobar Islands','Chandigarh','Dadra and Nagar Haveli and Daman and Diu','Delhi','Jammu and Kashmir',
    'Ladakh','Lakshadweep','Puducherry'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CLIENT INFO
    $client_name    = trim($_POST['client_name'] ?? '');
    $mobile_number  = trim($_POST['mobile_number'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $client_type    = trim($_POST['client_type'] ?? '');
    $company_name   = trim($_POST['company_name'] ?? ''); // optional
    $office_address = trim($_POST['office_address'] ?? '');
    $site_address   = trim($_POST['site_address'] ?? '');
    $state          = trim($_POST['state'] ?? '');

    // LOGIN
    $password = trim($_POST['password'] ?? '');

    // LEGAL & COMPLIANCE
    $pan_number      = strtoupper(trim($_POST['pan_number'] ?? ''));
    $gst_number      = strtoupper(trim($_POST['gst_number'] ?? '')); // optional
    $aadhaar_number  = trim($_POST['aadhaar_number'] ?? ''); // required only for Individual
    $billing_address = trim($_POST['billing_address'] ?? '');
    $shipping_address= trim($_POST['shipping_address'] ?? '');

    /**
     * VALIDATION
     */
    if ($client_name === '') $validation_errors[] = "Client name is required";
    if ($mobile_number === '') $validation_errors[] = "Mobile number is required";
    if ($email === '') $validation_errors[] = "Mail ID is required";
    if ($client_type === '') $validation_errors[] = "Client type is required";
    if ($office_address === '') $validation_errors[] = "Office address is required";
    if ($site_address === '') $validation_errors[] = "Site address is required";
    if ($state === '') $validation_errors[] = "State is required";

    if ($password === '') $validation_errors[] = "Password is required";
    if ($password !== '' && strlen($password) < 8) $validation_errors[] = "Password must be at least 8 characters";

    if ($pan_number === '') $validation_errors[] = "PAN number is required";
    if ($billing_address === '') $validation_errors[] = "Billing address is required";
    if ($shipping_address === '') $validation_errors[] = "Shipping address is required";

    if ($client_type === 'Individual' && $aadhaar_number === '') {
        $validation_errors[] = "Aadhaar is required for Individual clients";
    }

    // Formats
    if ($mobile_number !== '' && !preg_match('/^[0-9]{10}$/', $mobile_number)) {
        $validation_errors[] = "Mobile number must be 10 digits";
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = "Invalid Mail ID format";
    }
    if ($pan_number !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan_number)) {
        $validation_errors[] = "PAN number must be in format: ABCDE1234F";
    }
    if ($gst_number !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gst_number)) {
        $validation_errors[] = "Invalid GST number format";
    }
    if ($aadhaar_number !== '' && !preg_match('/^[0-9]{12}$/', $aadhaar_number)) {
        $validation_errors[] = "Aadhaar must be 12 digits";
    }

    // Dropdown validation
    if ($client_type !== '' && !in_array($client_type, $client_types, true)) {
        $validation_errors[] = "Invalid client type selected";
    }
    if ($state !== '' && !in_array($state, $states, true)) {
        $validation_errors[] = "Invalid state selected";
    }

    /**
     * DUPLICATE CHECK (mobile/email)
     */
    if ($mobile_number !== '' || $email !== '') {
        $dup_sql = "SELECT id FROM clients WHERE mobile_number = ? OR email = ? LIMIT 1";
        $dup_stmt = mysqli_prepare($conn, $dup_sql);
        if ($dup_stmt) {
            mysqli_stmt_bind_param($dup_stmt, "ss", $mobile_number, $email);
            mysqli_stmt_execute($dup_stmt);
            mysqli_stmt_store_result($dup_stmt);
            if (mysqli_stmt_num_rows($dup_stmt) > 0) {
                $validation_errors[] = "Client already exists with same Mobile or Email";
            }
            mysqli_stmt_close($dup_stmt);
        }
    }

    /**
     * INSERT (ONLY these columns)
     */
    if (empty($validation_errors)) {

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO clients (
            client_name, mobile_number, email, client_type, company_name,
            office_address, site_address, state,
            password,
            pan_number, gst_number, aadhaar_number,
            billing_address, shipping_address
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?,
            ?, ?, ?,
            ?, ?
        )";

        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                "ssssssssssssss",
                $client_name, $mobile_number, $email, $client_type, $company_name,
                $office_address, $site_address, $state,
                $hashed_password,
                $pan_number, $gst_number, $aadhaar_number,
                $billing_address, $shipping_address
            );

            if (mysqli_stmt_execute($stmt)) {
                $success = "Client added successfully!";
                $_POST = [];
            } else {
                $error = "Error adding client: " . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = "Database error: " . mysqli_error($conn);
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Add Client - TEK-C</title>

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
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
    .form-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 25px; margin-bottom: 30px; }
    .section-header { display:flex; align-items:center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #f0f4f8; }
    .section-icon { width: 48px; height: 48px; border-radius: 12px; background: var(--blue); display:flex; align-items:center; justify-content:center; margin-right: 15px; font-size: 20px; color: #fff; }
    .section-title { font-size: 18px; font-weight: 800; color: #2d3748; margin: 0; }
    .section-subtitle { font-size: 14px; color: #718096; margin-top: 4px; }
    .form-label { font-weight: 700; color:#4a5568; margin-bottom:8px; font-size: 14px; }
    .required-label::after { content:" *"; color:#e53e3e; font-weight:900; }
    .optional-badge { font-size: 11px; color:#718096; font-weight: 600; margin-left: 5px; }
    .form-control, .form-select { border:2px solid #e2e8f0; border-radius:10px; padding: 12px 15px; font-size: 14px; transition: all .3s; }
    .form-control:focus, .form-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(45,156,219,.1); }
    .form-control.is-invalid, .form-select.is-invalid { border-color:#fc8181; background:#fff5f5; }
    .form-helper { font-size:12px; color:#718096; margin-top:5px; display:flex; align-items:center; gap:6px; }
    .btn-back { background: transparent; border: 1px solid var(--border); border-radius: 10px; padding: 8px 16px; color: #4a5568; font-weight: 700; display:flex; align-items:center; gap:6px; text-decoration: none; }
    .btn-back:hover { background: var(--bg); color: var(--blue); border-color: var(--blue); }
    .btn-submit { background: var(--blue); color:#fff; border:none; padding: 14px 35px; border-radius: 12px; font-weight: 800; font-size: 15px; display:flex; align-items:center; gap:10px; box-shadow: 0 8px 20px rgba(45,156,219,.2); transition: all .3s; margin: 40px auto; }
    .btn-submit:hover { background:#2a8bc9; transform: translateY(-2px); box-shadow: 0 12px 25px rgba(45,156,219,.3); color:#fff; }
    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }
    @media (max-width: 768px) {
      .content-scroll { padding: 18px; }
      .form-panel { padding: 20px; }
      .section-header { flex-direction: column; text-align: center; }
      .section-icon { margin-right: 0; margin-bottom: 15px; }
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
                        <h1 class="h3 fw-bold text-dark mb-1">Add New Client</h1>
                        <p class="text-muted mb-0">Enter client and legal details</p>
                    </div>
                    <a href="clients.php" class="btn-back">
                        <i class="bi bi-arrow-left"></i> Back to Clients
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

                <form method="POST" id="clientForm" novalidate>

                    <!-- Section 1: Client Information -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon"><i class="bi bi-people"></i></div>
                            <div>
                                <h3 class="section-title">Client Information</h3>
                                <p class="section-subtitle">Basic client details</p>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="client_name" class="form-label required-label">Client Name</label>
                                <input type="text" class="form-control" id="client_name" name="client_name"
                                       value="<?php echo htmlspecialchars($_POST['client_name'] ?? ''); ?>"
                                       placeholder="Enter client name" required>
                            </div>

                            <div class="col-md-6">
                                <label for="client_type" class="form-label required-label">Client Type</label>
                                <select class="form-select" id="client_type" name="client_type" required>
                                    <option value="">Select type</option>
                                    <?php foreach ($client_types as $ct): ?>
                                        <option value="<?php echo htmlspecialchars($ct); ?>"
                                            <?php echo (($_POST['client_type'] ?? '') === $ct) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($ct); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="mobile_number" class="form-label required-label">Mobile Number</label>
                                <input type="tel" class="form-control" id="mobile_number" name="mobile_number"
                                       value="<?php echo htmlspecialchars($_POST['mobile_number'] ?? ''); ?>"
                                       placeholder="9876543210" required>
                                <div class="form-helper"><i class="bi bi-phone"></i> 10 digits without country code</div>
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="form-label required-label">Mail ID</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       placeholder="client@domain.com" required>
                            </div>

                            <!-- Password -->
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
                                <div class="form-helper"><i class="bi bi-shield-lock"></i> Strong password recommended</div>
                            </div>

                            <div class="col-md-6">
                                <label for="company_name" class="form-label">Company Name <span class="optional-badge">(Optional)</span></label>
                                <input type="text" class="form-control" id="company_name" name="company_name"
                                       value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>"
                                       placeholder="Company name (if available)">
                            </div>

                            <div class="col-md-6">
                                <label for="state" class="form-label required-label">State</label>
                                <select class="form-select" id="state" name="state" required>
                                    <option value="">Select state</option>
                                    <?php foreach ($states as $st): ?>
                                        <option value="<?php echo htmlspecialchars($st); ?>"
                                            <?php echo (($_POST['state'] ?? '') === $st) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($st); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label for="office_address" class="form-label required-label">Office Address</label>
                                <textarea class="form-control" id="office_address" name="office_address" rows="2"
                                          placeholder="Enter office address" required><?php echo htmlspecialchars($_POST['office_address'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-12">
                                <label for="site_address" class="form-label required-label">Site Address (if different)</label>
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <input class="form-check-input" type="checkbox" id="siteSameAsOffice">
                                    <label class="form-check-label text-muted" for="siteSameAsOffice" style="font-size: 13px;">
                                        Same as Office Address
                                    </label>
                                </div>
                                <textarea class="form-control" id="site_address" name="site_address" rows="2"
                                          placeholder="Enter site address" required><?php echo htmlspecialchars($_POST['site_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Legal & Compliance -->
                    <div class="form-panel">
                        <div class="section-header">
                            <div class="section-icon" style="background:#30cfd0;"><i class="bi bi-shield-check"></i></div>
                            <div>
                                <h3 class="section-title">Legal & Compliance Details</h3>
                                <p class="section-subtitle">Tax and billing information</p>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="pan_number" class="form-label required-label">PAN Number</label>
                                <input type="text" class="form-control" id="pan_number" name="pan_number"
                                       value="<?php echo htmlspecialchars($_POST['pan_number'] ?? ''); ?>"
                                       placeholder="ABCDE1234F" required>
                                <div class="form-helper"><i class="bi bi-card-text"></i> Format: ABCDE1234F</div>
                            </div>

                            <div class="col-md-6">
                                <label for="gst_number" class="form-label">GST Number <span class="optional-badge">(Optional)</span></label>
                                <input type="text" class="form-control" id="gst_number" name="gst_number"
                                       value="<?php echo htmlspecialchars($_POST['gst_number'] ?? ''); ?>"
                                       placeholder="22ABCDE1234F1Z5">
                                <div class="form-helper"><i class="bi bi-receipt"></i> Validate if provided</div>
                            </div>

                            <div class="col-md-6" id="aadhaarWrap">
                                <label for="aadhaar_number" class="form-label">Aadhaar (Individual clients) <span class="optional-badge">(Required for Individual)</span></label>
                                <input type="text" class="form-control" id="aadhaar_number" name="aadhaar_number"
                                       value="<?php echo htmlspecialchars($_POST['aadhaar_number'] ?? ''); ?>"
                                       placeholder="123456789012">
                                <div class="form-helper"><i class="bi bi-person-vcard"></i> 12 digits</div>
                            </div>

                            <div class="col-12">
                                <label for="billing_address" class="form-label required-label">Billing Address (as per GST)</label>
                                <textarea class="form-control" id="billing_address" name="billing_address" rows="2"
                                          placeholder="Enter billing address" required><?php echo htmlspecialchars($_POST['billing_address'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-12">
                                <label for="shipping_address" class="form-label required-label">Shipping Address</label>
                                <textarea class="form-control" id="shipping_address" name="shipping_address" rows="2"
                                          placeholder="Enter shipping address" required><?php echo htmlspecialchars($_POST['shipping_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="text-center mt-5">
                        <button type="submit" class="btn-submit">
                            <i class="bi bi-person-plus"></i> Add Client to System
                        </button>
                        <p class="text-muted mt-3" style="font-size: 14px;">
                            <i class="bi bi-info-circle me-1"></i>
                            Fields marked with * are mandatory. Company name is optional. Aadhaar required only for Individual clients.
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
    const form = document.getElementById('clientForm');
    const setInvalid = (el) => el && el.classList.add('is-invalid');

    // Same as office -> site address
    const siteSameAsOffice = document.getElementById('siteSameAsOffice');
    const officeAddress = document.getElementById('office_address');
    const siteAddress = document.getElementById('site_address');

    if (siteSameAsOffice && officeAddress && siteAddress) {
        siteSameAsOffice.addEventListener('change', function() {
            if (this.checked) siteAddress.value = officeAddress.value || '';
        });
        officeAddress.addEventListener('input', function() {
            if (siteSameAsOffice.checked) siteAddress.value = officeAddress.value || '';
        });
    }

    // Password toggle + generator
    const passwordInput = document.getElementById('password');
    const togglePassword = document.getElementById('togglePassword');
    const generatePasswordBtn = document.getElementById('generatePasswordBtn');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.innerHTML = type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        });
    }

    if (generatePasswordBtn && passwordInput) {
        generatePasswordBtn.addEventListener('click', function() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
            let pwd = '';
            for (let i = 0; i < 12; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
            passwordInput.value = pwd;
            passwordInput.setAttribute('type', 'text');
            if (togglePassword) togglePassword.innerHTML = '<i class="bi bi-eye-slash"></i>';
        });
    }

    // Uppercase PAN/GST
    const pan = document.getElementById('pan_number');
    const gst = document.getElementById('gst_number');
    if (pan) pan.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });
    if (gst) gst.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });

    // Number-only fields
    const mob = document.getElementById('mobile_number');
    const aadhaar = document.getElementById('aadhaar_number');
    if (mob) mob.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,10); });
    if (aadhaar) aadhaar.addEventListener('input', function(){ this.value = this.value.replace(/\D/g,'').slice(0,12); });

    // Aadhaar required only for Individual
    const clientType = document.getElementById('client_type');
    const aadhaarWrap = document.getElementById('aadhaarWrap');
    function toggleAadhaarRequired() {
        if (!clientType || !aadhaar) return;
        const isIndividual = clientType.value === 'Individual';
        aadhaar.required = isIndividual;
        if (aadhaarWrap) {
            aadhaarWrap.querySelector('label').classList.toggle('required-label', isIndividual);
        }
    }
    if (clientType) {
        clientType.addEventListener('change', toggleAadhaarRequired);
        toggleAadhaarRequired();
    }

    // Submit validation (same style)
    form.addEventListener('submit', function(e) {
        let valid = true;
        const errorMessages = [];

        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

        // Required fields
        const requiredFields = form.querySelectorAll('[required]');
        requiredFields.forEach(field => {
            if (!String(field.value || '').trim()) {
                valid = false;
                setInvalid(field);
                const label = form.querySelector(`label[for="${field.id}"]`);
                const fieldName = label ? label.textContent.replace(' *', '').trim() : field.name;
                errorMessages.push(`${fieldName} is required`);
            }
        });

        // Mobile
        if (mob && mob.value && !/^[0-9]{10}$/.test(mob.value)) {
            valid = false; setInvalid(mob); errorMessages.push('Mobile number must be 10 digits');
        }

        // Email
        const email = document.getElementById('email');
        if (email && email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
            valid = false; setInvalid(email); errorMessages.push('Invalid Mail ID format');
        }

        // PAN
        if (pan && pan.value && !/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pan.value)) {
            valid = false; setInvalid(pan); errorMessages.push('PAN number must be in format: ABCDE1234F');
        }

        // GST optional
        if (gst && gst.value && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/.test(gst.value)) {
            valid = false; setInvalid(gst); errorMessages.push('Invalid GST number format');
        }

        // Aadhaar
        if (aadhaar && aadhaar.value && !/^[0-9]{12}$/.test(aadhaar.value)) {
            valid = false; setInvalid(aadhaar); errorMessages.push('Aadhaar must be 12 digits');
        }

        // Password length
        if (passwordInput && passwordInput.value && passwordInput.value.length < 8) {
            valid = false; setInvalid(passwordInput); errorMessages.push('Password must be at least 8 characters');
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

            const container = document.querySelector('.container-fluid.maxw');
            const headerBlock = container.children[0];
            container.insertBefore(alertDiv, headerBlock.nextSibling);

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
});
</script>

</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>
