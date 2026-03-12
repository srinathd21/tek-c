<?php
// manage-credentials.php
session_start();
require_once 'includes/db-config.php';

// Initialize variables
$success = '';
$error = '';
$credentials = [];

// Get database connection
$conn = get_db_connection();

// Create credentials table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(100) NOT NULL,
    username_email VARCHAR(150) NOT NULL,
    password VARCHAR(255) NOT NULL,
    requires_otp TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if (!mysqli_query($conn, $createTable)) {
    $error = "Error creating table: " . mysqli_error($conn);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Save
    if (isset($_POST['save_credentials'])) {
        $service_name = mysqli_real_escape_string($conn, $_POST['service_name'] ?? '');
        $username_email = mysqli_real_escape_string($conn, $_POST['username_email'] ?? '');
        $password = mysqli_real_escape_string($conn, $_POST['password'] ?? '');
        $requires_otp = isset($_POST['requires_otp']) ? 1 : 0;

        if (!empty($service_name) && !empty($username_email) && !empty($password)) {
            $query = "INSERT INTO credentials (service_name, username_email, password, requires_otp)
                      VALUES ('$service_name', '$username_email', '$password', $requires_otp)";

            if (mysqli_query($conn, $query)) {
                $success = "Credentials saved successfully!";
            } else {
                $error = "Error saving credentials: " . mysqli_error($conn);
            }
        } else {
            $error = "Service Name, Username/Email and Password are required!";
        }
    }

    // Delete
    if (isset($_POST['delete_id'])) {
        $id = intval($_POST['delete_id']);
        $delete_query = "DELETE FROM credentials WHERE id = $id";
        if (mysqli_query($conn, $delete_query)) {
            $success = "Credentials deleted successfully!";
        } else {
            $error = "Error deleting credentials: " . mysqli_error($conn);
        }
    }
}

// Fetch all credentials
$result = mysqli_query($conn, "SELECT * FROM credentials ORDER BY created_at DESC");
if ($result) {
    $credentials = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_free_result($result);
}

// Get total count
$total_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM credentials");
$total_data = mysqli_fetch_assoc($total_result);
$total_credentials = $total_data['count'] ?? 0;
mysqli_free_result($total_result);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Credentials - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- DataTables (Bootstrap 5 + Responsive) -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

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

    /* Credentials Specific Styles */
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

    .credential-badge {
      background: rgba(45, 156, 219, 0.1);
      color: var(--blue);
      padding: 6px 12px;
      border-radius: 8px;
      font-weight: 800;
      font-size: 12px;
      border: 1px solid rgba(45, 156, 219, 0.2);
    }

    .password-field {
      font-family: 'Courier New', monospace;
      font-size: 13px;
      background: rgba(0,0,0,0.02);
      padding: 4px 8px;
      border-radius: 6px;
      border: 1px solid var(--border);
    }

    .btn-copy {
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 4px 8px;
      color: var(--muted);
      font-size: 12px;
    }
    .btn-copy:hover {
      background: var(--bg);
      color: var(--blue);
    }

    .btn-delete {
      background: transparent;
      border: 1px solid rgba(235, 87, 87, 0.2);
      border-radius: 8px;
      padding: 6px 10px;
      color: var(--red);
      font-size: 12px;
    }
    .btn-delete:hover {
      background: rgba(235, 87, 87, 0.1);
      color: #d32f2f;
    }

    .form-group { margin-bottom: 16px; }
    .form-label {
      font-weight: 800;
      font-size: 13px;
      color: #374151;
      margin-bottom: 6px;
    }
    .form-control {
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 10px 14px;
      font-weight: 650;
    }
    .form-control:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
    }

    .otp-badge {
      background: rgba(242, 201, 76, 0.1);
      color: var(--yellow);
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      border: 1px solid rgba(242, 201, 76, 0.2);
    }

    .service-buttons { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
    .service-btn {
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 6px 12px;
      font-size: 11px;
      font-weight: 700;
      color: var(--muted);
    }
    .service-btn:hover {
      background: var(--blue);
      color: white;
      border-color: var(--blue);
    }

    .empty-state { text-align: center; padding: 30px 16px; }
    .empty-icon { font-size: 44px; color: var(--border); margin-bottom: 12px; }
    .empty-text { color: var(--muted); font-weight: 700; font-size: 14px; }

    .alert {
      border-radius: var(--radius);
      border: none;
      box-shadow: var(--shadow);
      margin-bottom: 20px;
    }

    /* DataTables tweaks to match your UI */
    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 8px 12px;
      font-weight: 650;
      outline: none;
    }
    div.dataTables_wrapper .dataTables_filter input:focus{
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(45, 156, 219, 0.1);
    }
    .dataTables_paginate .pagination .page-link{
      border-radius: 10px;
      margin: 0 3px;
      font-weight: 750;
    }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
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

          <!-- Page Header -->
          <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
              <h1 class="h3 fw-bold text-dark mb-1">Manage Credentials</h1>
              <p class="text-muted mb-0">Store and manage login credentials for various services</p>
            </div>
            <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#addCredentialModal">
              <i class="bi bi-plus-lg"></i> Add New
            </button>
          </div>

          <!-- Alerts -->
          <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
              <i class="bi bi-check-circle-fill me-2"></i>
              <?php echo $success; ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>

          <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <i class="bi bi-exclamation-triangle-fill me-2"></i>
              <?php echo $error; ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
          <?php endif; ?>

          <!-- Stats Card -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-key"></i></div>
                <div>
                  <div class="stat-label">Total Credentials</div>
                  <div class="stat-value"><?php echo $total_credentials; ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Credentials Table -->
          <div class="panel mb-4">
            <div class="panel-header">
              <h3 class="panel-title">Stored Credentials</h3>
              <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
            </div>

            <div class="table-responsive">
              <table id="credentialsTable" class="table align-middle mb-0 dt-responsive nowrap" style="width:100%">
                <thead>
                  <tr>
                    <th style="min-width:150px;">Service Name</th>
                    <th style="min-width:180px;">Username/Email</th>
                    <th style="min-width:140px;">Password</th>
                    <th style="min-width:120px;">OTP Required</th>
                    <th style="min-width:120px;">Added On</th>
                    <th class="text-end" style="width:110px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($credentials as $cred): ?>
                    <tr>
                      <td>
                        <span class="credential-badge"><?php echo htmlspecialchars($cred['service_name']); ?></span>
                      </td>

                      <td>
                        <div class="d-flex flex-column">
                          <span><?php echo htmlspecialchars($cred['username_email']); ?></span>
                          <?php if (filter_var($cred['username_email'], FILTER_VALIDATE_EMAIL)): ?>
                            <small class="text-muted">Email</small>
                          <?php else: ?>
                            <small class="text-muted">Username</small>
                          <?php endif; ?>
                        </div>
                      </td>

                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <span class="password-field" id="password-<?php echo (int)$cred['id']; ?>">
                            <?php echo htmlspecialchars($cred['password']); ?>
                          </span>
                          <button type="button"
                                  class="btn-copy"
                                  data-id="<?php echo (int)$cred['id']; ?>"
                                  title="Copy password">
                            <i class="bi bi-copy"></i>
                          </button>
                        </div>
                      </td>

                      <td>
                        <?php if ((int)$cred['requires_otp'] === 1): ?>
                          <span class="otp-badge"><i class="bi bi-shield-check"></i> Yes</span>
                        <?php else: ?>
                          <span class="text-muted">No</span>
                        <?php endif; ?>
                      </td>

                      <?php
                        $ts = strtotime($cred['created_at'] ?? '');
                        $displayDate = $ts ? date('d M Y', $ts) : '';
                      ?>
                      <td data-order="<?php echo $ts ? $ts : 0; ?>">
                        <?php echo $displayDate; ?>
                      </td>

                      <td class="text-end">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete these credentials?');">
                          <input type="hidden" name="delete_id" value="<?php echo (int)$cred['id']; ?>">
                          <button type="submit" class="btn-delete" title="Delete">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </div>

      <?php include 'includes/footer.php'; ?>

    </main>
  </div>

  <!-- Add Credential Modal -->
  <div class="modal fade" id="addCredentialModal" tabindex="-1" aria-labelledby="addCredentialModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="addCredentialModalLabel">Add New Credentials</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="POST" action="">
          <div class="modal-body">
            <div class="row g-3">
              <div class="col-12">
                <div class="form-group">
                  <label for="service_name" class="form-label">Service Name *</label>
                  <input type="text" class="form-control" id="service_name" name="service_name" required placeholder="e.g., Hostinger, cPanel, AWS, Domain">
                  <div class="service-buttons">
                    <button type="button" class="service-btn" data-service="Hostinger">Hostinger</button>
                    <button type="button" class="service-btn" data-service="cPanel">cPanel</button>
                    <button type="button" class="service-btn" data-service="AWS">AWS</button>
                    <button type="button" class="service-btn" data-service="Domain">Domain</button>
                    <button type="button" class="service-btn" data-service="FTP">FTP</button>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <div class="form-group">
                  <label for="username_email" class="form-label">Username or Email *</label>
                  <input type="text" class="form-control" id="username_email" name="username_email" required placeholder="username or email@example.com">
                </div>
              </div>

              <div class="col-12">
                <div class="form-group">
                  <label for="password" class="form-label">Password *</label>
                  <div class="input-group">
                    <input type="text" class="form-control" id="password" name="password" required placeholder="Enter password">
                    <button type="button" class="btn btn-outline-secondary" id="generatePasswordBtn" title="Generate password">
                      <i class="bi bi-shuffle"></i>
                    </button>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="requires_otp" name="requires_otp" value="1">
                  <label class="form-check-label" for="requires_otp">
                    Requires OTP/2FA
                  </label>
                  <div class="form-text">Check if this service requires One-Time Password or Two-Factor Authentication</div>
                </div>
              </div>

            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" name="save_credentials" class="btn-add">Save Credentials</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- jQuery (required for DataTables 1.x) -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

  <!-- DataTables (Bootstrap 5 + Responsive) -->
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

  <!-- TEK-C Custom JavaScript -->
  <script src="assets/js/sidebar-toggle.js"></script>

  <script>
    (function () {
      // Footer year (if you have #year element)
      const yearElement = document.getElementById("year");
      if (yearElement) yearElement.textContent = new Date().getFullYear();

      // DataTables init
      $(function () {
        $('#credentialsTable').DataTable({
          responsive: true,
          pageLength: 10,
          lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
          order: [[4, 'desc']], // "Added On" column
          columnDefs: [
            { targets: [5], orderable: false, searchable: false }, // Actions
            { targets: [2], orderable: false },                    // Password
            { targets: [3], orderable: false }                     // OTP Required
          ],
          language: {
            emptyTable:
              '<div class="empty-state">' +
                '<i class="bi bi-key empty-icon"></i>' +
                '<p class="empty-text">No credentials stored yet. Add your first credentials!</p>' +
              '</div>',
            zeroRecords: "No matching credentials found",
            info: "Showing _START_ to _END_ of _TOTAL_ credentials",
            infoEmpty: "No credentials to show",
            lengthMenu: "Show _MENU_",
            search: "Search:"
          }
        });

        // Copy password
        $(document).on('click', '.btn-copy', function () {
          const id = $(this).data('id');
          const pw = $('#password-' + id).text().trim();
          const $btn = $(this);

          navigator.clipboard.writeText(pw).then(() => {
            const original = $btn.html();
            $btn.html('<i class="bi bi-check"></i>');
            $btn.css('color', 'var(--green)');
            setTimeout(() => {
              $btn.html(original);
              $btn.css('color', '');
            }, 2000);
          });
        });

        // Quick service buttons
        $(document).on('click', '.service-btn', function () {
          $('#service_name').val($(this).data('service'));
        });

        // Generate password
        $('#generatePasswordBtn').on('click', function () {
          const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
          let password = '';
          for (let i = 0; i < 12; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
          }
          $('#password').val(password);
        });

        // Focus first input when modal opens
        $('#addCredentialModal').on('shown.bs.modal', function () {
          $('#service_name').trigger('focus');
        });

        // Auto-detect email input type
        $('#username_email').on('blur', function () {
          const val = String(this.value || '');
          this.type = (val.includes('@') && val.includes('.')) ? 'email' : 'text';
        });
      });
    })();
  </script>

</body>
</html>
<?php
// Close database connection
if (isset($conn)) {
    mysqli_close($conn);
}
?>
