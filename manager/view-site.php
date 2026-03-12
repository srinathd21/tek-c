<?php
// view-site.php (MANAGER) — VIEW ONLY
// ✅ Manager can view only his assigned site
// ✅ No edit buttons, no assign form
// ✅ Shows full project/site details + team read-only

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$site   = null;
$error  = '';
$success = '';

// Assigned engineers (PE Grade 1/2)
$assigned_engineers_rows = [];

// ---------- Helpers ----------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function showVal($v, $dash='—'){
  $v = trim((string)$v);
  return $v === '' ? $dash : e($v);
}

function showMoney($v, $dash='—'){
  if ($v === null) return $dash;
  $v = trim((string)$v);
  if ($v === '') return $dash;
  if (!is_numeric($v)) return e($v);
  return number_format((float)$v, 2);
}

function showDateVal($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function initials($name){
  $name = trim((string)$name);
  if ($name === '') return 'S';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'S', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return ($last && count($parts) > 1) ? ($first.$last) : $first;
}

function fileLinkBtn($path){
  $path = trim((string)$path);
  if ($path === '') return '—';
  $safe = e($path);
  return '<a class="btn btn-sm btn-outline-primary" href="'.$safe.'" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-arrow-down"></i> View / Download
          </a>';
}

// ---------- Auth ----------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}
$managerId = (int)$_SESSION['employee_id'];

// If you want strict designation check:
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
if ($designation !== 'manager') {
  header("Location: index.php");
  exit;
}

// ---------- Fetch site ID ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  $error = "Invalid site ID.";
} else {

  // Fetch site ONLY if assigned to this manager
  $sql = "
    SELECT
      s.*,
      c.client_name,
      c.client_type,
      c.company_name,
      c.state,
      c.mobile_number AS client_mobile,
      c.email AS client_email,

      m.full_name AS manager_name,
      m.employee_code AS manager_code,

      tl.full_name AS team_lead_name,
      tl.employee_code AS team_lead_code

    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    LEFT JOIN employees m ON m.id = s.manager_employee_id
    LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id
    WHERE s.id = ?
      AND s.manager_employee_id = ?
    LIMIT 1
  ";

  $stmt = mysqli_prepare($conn, $sql);
  if (!$stmt) {
    $error = "Database error: " . mysqli_error($conn);
  } else {
    mysqli_stmt_bind_param($stmt, "ii", $id, $managerId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $site = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$site) {
      $error = "Site not found or not assigned to you.";
    }
  }

  // Assigned engineers (PE Grade 1/2 only)
  if ($site) {
    $eng = mysqli_prepare($conn, "
      SELECT e.id, e.full_name, e.employee_code, e.designation
      FROM site_project_engineers spe
      INNER JOIN employees e ON e.id = spe.employee_id
      WHERE spe.site_id=?
        AND e.employee_status='active'
        AND e.designation IN ('Project Engineer Grade 1','Project Engineer Grade 2')
      ORDER BY e.full_name ASC
    ");
    if ($eng) {
      mysqli_stmt_bind_param($eng, "i", $id);
      mysqli_stmt_execute($eng);
      $rr = mysqli_stmt_get_result($eng);
      while ($row = mysqli_fetch_assoc($rr)) {
        $assigned_engineers_rows[] = $row;
      }
      mysqli_stmt_close($eng);
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>View Project - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{
      background:#fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding: 18px;
      margin-bottom: 16px;
    }

    .btn-back{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 14px;
      color:#111827;
      font-weight: 900;
      display:inline-flex;
      align-items:center;
      gap:8px;
      text-decoration:none;
    }
    .btn-back:hover{ background:#f9fafb; color: var(--blue); border-color: rgba(45,156,219,.25); }

    .client-hero{
      display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;
    }
    .client-id{ display:flex; gap:14px; align-items:center; min-width:260px; }

    .site-avatar{
      width:54px;height:54px;border-radius: 16px;
      background: linear-gradient(135deg, rgba(45,156,219,.95), rgba(99,102,241,.95));
      color:#fff; display:flex; align-items:center; justify-content:center;
      font-weight:1000; letter-spacing:.5px; flex:0 0 auto;
    }

    .hero-title{ margin:0; font-weight:1000; color:#111827; font-size:18px; line-height:1.2; }
    .hero-sub{ margin:4px 0 0; color:#6b7280; font-weight:700; font-size:13px; }

    .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      font-size:12px; font-weight:900;
      border:1px solid #e5e7eb;
      background:#f9fafb;
      color:#111827;
      white-space:nowrap;
    }
    .chip-primary{
      border-color: rgba(45,156,219,.22);
      background: rgba(45,156,219,.08);
      color: var(--blue);
    }

    .section-card{
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      overflow:hidden;
      background:#fff;
      margin-bottom: 14px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
    }
    .section-head{
      padding: 14px 16px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      background:#f9fafb;
      border-bottom: 1px solid #eef2f7;
    }
    .section-head .left{ display:flex; align-items:center; gap:10px; }
    .sec-ic{
      width:36px;height:36px;border-radius: 12px;
      display:flex; align-items:center; justify-content:center;
      background: rgba(45,156,219,.12);
      color: var(--blue);
      font-size: 16px;
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:14px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:700; font-size:12px; }

    .kv-row{
      display:grid;
      grid-template-columns: 170px 1fr;
      gap:12px;
      padding: 12px 16px;
      border-bottom: 1px solid #eef2f7;
      align-items:start;
    }
    .kv-row:last-child{ border-bottom:none; }
    .kv-k{
      color:#6b7280;
      font-weight:900;
      font-size:12px;
      text-transform: uppercase;
      letter-spacing:.35px;
    }
    .kv-v{
      text-align:right;
      color:#111827;
      font-weight:800;
      font-size:13px;
      word-break: break-word;
    }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .kv-row{ grid-template-columns: 150px 1fr; }
      .kv-v{ text-align:left; }
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
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">View Project</h1>
            <p class="text-muted mb-0">Project details and assigned team (View only)</p>
          </div>
          <div class="d-flex gap-2">
            <a href="my-projects.php" class="btn-back">
              <i class="bi bi-arrow-left"></i> Back
            </a>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($site): ?>

          <!-- Summary -->
          <div class="panel">
            <div class="client-hero">
              <div class="client-id">
                <div class="site-avatar"><?php echo e(initials($site['project_name'] ?? 'Site')); ?></div>
                <div>
                  <p class="hero-title"><?php echo showVal($site['project_name']); ?></p>
                  <p class="hero-sub">
                    <?php echo showVal($site['client_name']); ?>
                    <?php if (!empty($site['state'])): ?> • <?php echo e($site['state']); ?><?php endif; ?>
                  </p>

                  <div class="d-flex flex-wrap gap-2 mt-2">
                    <span class="chip chip-primary"><i class="bi bi-person-badge"></i> Manager:
                      <strong class="ms-1"><?php echo showVal($site['manager_name'] ?? ''); ?></strong>
                    </span>
                    <span class="chip"><i class="bi bi-person-check"></i> Team Lead:
                      <strong class="ms-1"><?php echo showVal($site['team_lead_name'] ?? ''); ?></strong>
                    </span>
                    <span class="chip"><i class="bi bi-people"></i> Engineers:
                      <strong class="ms-1"><?php echo (int)count($assigned_engineers_rows); ?></strong>
                    </span>
                  </div>
                </div>
              </div>

              <div class="d-flex flex-wrap gap-2">
                <?php if (!empty($site['client_email'])): ?>
                  <a class="btn btn-sm btn-outline-dark" href="mailto:<?php echo e($site['client_email']); ?>"><i class="bi bi-envelope"></i> Email</a>
                <?php endif; ?>
                <?php if (!empty($site['client_mobile'])): ?>
                  <a class="btn btn-sm btn-outline-dark" href="tel:<?php echo e($site['client_mobile']); ?>"><i class="bi bi-telephone"></i> Call</a>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- TEAM (VIEW ONLY) -->
          <div class="section-card">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-people"></i></div>
                <div>
                  <p class="sec-title mb-0">Assigned Team</p>
                  <p class="sec-sub mb-0">Read only</p>
                </div>
              </div>
            </div>

            <div class="kv">
              <div class="kv-row">
                <div class="kv-k">Manager</div>
                <div class="kv-v">
                  <?php echo showVal($site['manager_name'] ?? ''); ?>
                  <?php if (!empty($site['manager_code'])): ?>
                    <span class="text-muted" style="font-weight:800;">(<?php echo e($site['manager_code']); ?>)</span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="kv-row">
                <div class="kv-k">Team Lead</div>
                <div class="kv-v">
                  <?php echo showVal($site['team_lead_name'] ?? ''); ?>
                  <?php if (!empty($site['team_lead_code'])): ?>
                    <span class="text-muted" style="font-weight:800;">(<?php echo e($site['team_lead_code']); ?>)</span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="kv-row">
                <div class="kv-k">Project Engineers</div>
                <div class="kv-v">
                  <?php if (!empty($assigned_engineers_rows)): ?>
                    <?php foreach ($assigned_engineers_rows as $row): ?>
                      <div>
                        <?php echo e($row['full_name']); ?>
                        <span class="text-muted" style="font-weight:800;">(<?php echo e($row['designation']); ?>)</span>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- PROJECT DETAILS -->
          <div class="section-card">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-kanban"></i></div>
                <div>
                  <p class="sec-title mb-0">Project Details</p>
                  <p class="sec-sub mb-0">Complete information</p>
                </div>
              </div>
            </div>

            <div class="kv">
              <div class="kv-row"><div class="kv-k">Project Name</div><div class="kv-v"><?php echo showVal($site['project_name']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Project Type</div><div class="kv-v"><?php echo showVal($site['project_type']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Location</div><div class="kv-v"><?php echo showVal($site['project_location']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Scope of Work</div><div class="kv-v"><?php echo showVal($site['scope_of_work']); ?></div></div>

              <div class="kv-row"><div class="kv-k">Start Date</div><div class="kv-v"><?php echo showDateVal($site['start_date']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Expected End Date</div><div class="kv-v"><?php echo showDateVal($site['expected_completion_date']); ?></div></div>

              <div class="kv-row"><div class="kv-k">Contract Value</div><div class="kv-v">₹ <?php echo e(showMoney($site['contract_value'])); ?></div></div>
              <div class="kv-row"><div class="kv-k">PMC Charges</div><div class="kv-v">₹ <?php echo e(showMoney($site['pmc_charges'])); ?></div></div>

              <div class="kv-row"><div class="kv-k">Agreement No</div><div class="kv-v"><?php echo showVal($site['agreement_number']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Agreement Date</div><div class="kv-v"><?php echo showDateVal($site['agreement_date']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Work Order Date</div><div class="kv-v"><?php echo showDateVal($site['work_order_date']); ?></div></div>

              <div class="kv-row"><div class="kv-k">BOQ Details</div><div class="kv-v"><?php echo showVal($site['boq_details']); ?></div></div>

              <div class="kv-row">
                <div class="kv-k">Contract Document</div>
                <div class="kv-v"><?php echo fileLinkBtn($site['contract_document']); ?></div>
              </div>

              <div class="kv-row"><div class="kv-k">Authorized Signatory</div><div class="kv-v"><?php echo showVal($site['authorized_signatory_name']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Signatory Contact</div><div class="kv-v"><?php echo showVal($site['authorized_signatory_contact']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Contact Person Designation</div><div class="kv-v"><?php echo showVal($site['contact_person_designation']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Contact Person Email</div><div class="kv-v"><?php echo showVal($site['contact_person_email']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Approval Authority</div><div class="kv-v"><?php echo showVal($site['approval_authority']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Site In-charge (Client)</div><div class="kv-v"><?php echo showVal($site['site_in_charge_client_side']); ?></div></div>
            </div>
          </div>

          <!-- CLIENT DETAILS -->
          <div class="section-card">
            <div class="section-head">
              <div class="left">
                <div class="sec-ic"><i class="bi bi-building"></i></div>
                <div>
                  <p class="sec-title mb-0">Client Details</p>
                  <p class="sec-sub mb-0">Read only</p>
                </div>
              </div>
            </div>

            <div class="kv">
              <div class="kv-row"><div class="kv-k">Client Name</div><div class="kv-v"><?php echo showVal($site['client_name']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Client Type</div><div class="kv-v"><?php echo showVal($site['client_type']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Company Name</div><div class="kv-v"><?php echo showVal($site['company_name']); ?></div></div>
              <div class="kv-row"><div class="kv-k">State</div><div class="kv-v"><?php echo showVal($site['state']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Mobile</div><div class="kv-v"><?php echo showVal($site['client_mobile']); ?></div></div>
              <div class="kv-row"><div class="kv-k">Email</div><div class="kv-v"><?php echo showVal($site['client_email']); ?></div></div>
            </div>
          </div>

        <?php endif; ?>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>
</body>
</html>

<?php
if (isset($conn)) { mysqli_close($conn); }
?>
