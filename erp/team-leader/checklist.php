<?php
// checklist.php — Project Engineer Daily Activity Checklist submit
// Roles allowed: Project Engineer Grade 1/2, Sr. Engineer, Team Lead, Manager

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId  = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

$allowed = [
  'project engineer grade 1',
  'project engineer grade 2',
  'sr. engineer',
  'team lead',
  'manager',
];
if (!in_array($designation, $allowed, true)) {
  header("Location: index.php");
  exit;
}

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ymdOrNull($v){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return null;
  return $v;
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = mysqli_prepare($conn, $sql);
    if (!$st) return false;
    mysqli_stmt_bind_param($st, "ss", $table, $column);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $ok = (bool)mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    return $ok;
}

// ---------------- Logged Employee ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, designation FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$preparedBy = $empRow['full_name'] ?? ($_SESSION['employee_name'] ?? '');

// ---------------- Create Checklist Table if Not Exists ----------------
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS checklist_reports (
  id INT(11) NOT NULL AUTO_INCREMENT,
  site_id INT(11) NOT NULL,
  employee_id INT(11) NOT NULL,

  doc_no VARCHAR(80) NOT NULL,
  checklist_date DATE NOT NULL,

  checklist_json LONGTEXT NOT NULL,

  project_engineer VARCHAR(150) NOT NULL,
  pmc_lead VARCHAR(150) NOT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),

  PRIMARY KEY (id),
  KEY idx_chk_site (site_id),
  KEY idx_chk_employee (employee_id),
  KEY idx_chk_date (checklist_date),

  CONSTRAINT fk_chk_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
  CONSTRAINT fk_chk_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ---------------- Checklist Definition (matches your image) ----------------
$checklistSections = [
  'Daily Responsibilities' => [
    'report_time' => 'Report on time, mark attendance in log',
    'review_program' => 'Review daily work program vs baseline schedule',
    'check_manpower' => 'Check manpower, plant & machinery availability',
    'fill_dpr' => 'Fill Daily Progress Report (DPR)',
    'site_photos' => 'Take and submit site photographs',
  ],
  'Quality Control' => [
    'gfc_check' => 'Check execution against GFC drawings & specs',
    'verify_levels' => 'Verify line, level, plumb, and dimensions',
    'material_approval' => 'Confirm material approvals before use',
    'work_checklists' => 'Fill checklists for concreting/plastering/waterproofing',
    'raise_ncr' => 'Raise NCR for deviations',
  ],
  'Coordination' => [
    'attend_huddles' => 'Attend daily huddles/weekly review meetings',
    'coordinate_contractors' => 'Coordinate with contractors/vendors for work sequence',
    'clarifications_pmc' => 'Ensure clarifications routed via PMC lead',
  ],
  'Safety & Compliance' => [
    'ensure_ppe' => 'Ensure PPE usage',
    'check_scaffolding' => 'Check scaffolding, barricades, safety signage',
    'stop_unsafe' => 'Stop unsafe practices immediately',
  ],
  'Documentation' => [
    'maintain_registers' => 'Maintain DPR, material register, inspection checklists',
    'update_drawing_tracker' => 'Update drawing tracker with latest revisions',
    'weekly_client_report' => 'Support weekly client report preparation',
  ],
  'Escalation & Communication' => [
    'report_delays' => 'Report delays, design issues, vendor shortfalls',
    'raise_procurement' => 'Raise procurement needs 7–10 days in advance',
  ],
  'Weekly/Monthly Duties' => [
    'progress_reviews' => 'Assist in weekly progress reviews',
    'quantity_tracker' => 'Update quantity tracker & verify contractor bills',
    'delay_audits' => 'Support delay analysis & audits',
  ],
  'Professional Conduct' => [
    'no_commitments' => 'No direct commitments to client/contractors',
    'professionalism' => 'Maintain professionalism, integrity, confidentiality',
  ],
];

// ---------------- MODIFIED: Assigned Sites (Now matches my-sites.php logic) ----------------
$sites = [];

// Check if team_lead_employee_id column exists
$hasTeamLeadCol = hasColumn($conn, 'sites', 'team_lead_employee_id');

if ($designation === 'manager') {
  // Manager: sites where they are the manager
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.manager_employee_id = ?
    AND s.deleted_at IS NULL
    ORDER BY s.created_at DESC
  ";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
} elseif ($designation === 'team lead' && $hasTeamLeadCol) {
  // Team Lead: sites where they are assigned as team lead (matches my-sites.php)
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name
    FROM sites s
    INNER JOIN clients c ON c.id = s.client_id
    WHERE s.team_lead_employee_id = ?
    AND s.deleted_at IS NULL
    ORDER BY s.created_at DESC
  ";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
} else {
  // Project Engineers: sites where they are assigned as project engineer
  $q = "
    SELECT s.id, s.project_name, s.project_location, c.client_name
    FROM site_project_engineers spe
    INNER JOIN sites s ON s.id = spe.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE spe.employee_id = ?
    AND s.deleted_at IS NULL
    ORDER BY s.created_at DESC
  ";
  $st = mysqli_prepare($conn, $q);
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $sites = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
  }
}

// ---------------- Selected Site ----------------
$siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : 0;
$site = null;

if ($siteId > 0) {
  $isAllowedSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $siteId) { $isAllowedSite = true; break; }
  }

  if ($isAllowedSite) {
    $sql = "
      SELECT
        s.id,
        s.project_name,
        s.project_location,
        c.client_name
      FROM sites s
      INNER JOIN clients c ON c.id = s.client_id
      WHERE s.id = ?
      LIMIT 1
    ";
    $st = mysqli_prepare($conn, $sql);
    if ($st) {
      mysqli_stmt_bind_param($st, "i", $siteId);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $site = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
    }
  }
}

// ---------------- Default Doc No ----------------
$todayYmd = date('Y-m-d');
$defaultDocNo = '';

if ($siteId > 0) {
  // Get the count for this site
  $seq = 1;
  $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM checklist_reports WHERE site_id=?");
  if ($st) {
    mysqli_stmt_bind_param($st, "i", $siteId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($st);
    $seq = ((int)($row['cnt'] ?? 0)) + 1;
  }
  
  // Simple format with site ID and sequence
  $defaultDocNo = '#' . str_pad($seq, 2, '0', STR_PAD_LEFT);
}

// ---------------- SUBMIT ----------------
$_SESSION['success'] = '';
$error = '';

$formDate = $todayYmd;
$formDocNo = $defaultDocNo;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_checklist'])) {

  $site_id = (int)($_POST['site_id'] ?? 0);

  // Validate site assigned
  $okSite = false;
  foreach ($sites as $s) {
    if ((int)$s['id'] === $site_id) { $okSite = true; break; }
  }
  if (!$okSite) $error = "Invalid site selection.";

  $doc_no = trim((string)($_POST['doc_no'] ?? ''));
  $checklist_date = trim((string)($_POST['checklist_date'] ?? ''));

  $project_engineer = trim((string)($_POST['project_engineer'] ?? ''));
  $pmc_lead = trim((string)($_POST['pmc_lead'] ?? ''));

  $checked = $_POST['chk'] ?? []; // array of keys

  if ($error === '' && $site_id <= 0) $error = "Please choose a site.";
  if ($error === '' && $doc_no === '') $error = "Doc No is required.";
  if ($error === '' && $checklist_date === '') $error = "Date is required.";
  if ($error === '' && $project_engineer === '') $error = "Project Engineer name is required.";
  if ($error === '' && $pmc_lead === '') $error = "PMC Lead name is required.";

  // Build JSON (store each item with checked status)
  $data = [];
  foreach ($checklistSections as $secName => $items) {
    foreach ($items as $key => $label) {
      $data[] = [
        'section' => $secName,
        'key' => $key,
        'label' => $label,
        'checked' => in_array($key, $checked, true) ? 1 : 0,
      ];
    }
  }

  // Optional: require at least 1 tick for demo discipline
  $anyChecked = false;
  foreach ($data as $row) {
    if (!empty($row['checked'])) { $anyChecked = true; break; }
  }
  if ($error === '' && !$anyChecked) {
    $error = "Please tick at least one checklist item.";
  }

  if ($error === '') {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

    $d = ymdOrNull($checklist_date);

    $ins = mysqli_prepare($conn, "
      INSERT INTO checklist_reports
      (site_id, employee_id, doc_no, checklist_date, checklist_json, project_engineer, pmc_lead)
      VALUES (?,?,?,?,?,?,?)
    ");
    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "iisssss",
        $site_id, $employeeId, $doc_no, $d, $json, $project_engineer, $pmc_lead
      );
      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to save Checklist: " . mysqli_stmt_error($ins);
      } else {
        $newId = mysqli_insert_id($conn);
        mysqli_stmt_close($ins);
        header("Location: checklist.php?site_id=".$site_id."&saved=1&chk_id=".$newId);
        exit;
      }
      mysqli_stmt_close($ins);
    }
  }

  // Keep posted values
  $formDate = $checklist_date ?: $todayYmd;
  $formDocNo = $doc_no ?: $defaultDocNo;
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $_SESSION['success'] = "Checklist submitted successfully.";
  header("location:report.php");
  exit;
}

// Recent Checklists
$recent = [];
$st = mysqli_prepare($conn, "
  SELECT r.id, r.doc_no, r.checklist_date, s.project_name
  FROM checklist_reports r
  INNER JOIN sites s ON s.id = r.site_id
  WHERE r.employee_id = ?
  ORDER BY r.created_at DESC
  LIMIT 10
");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $recent = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// Defaults (UI)
$pmcName = "M/s. UKB Construction Management Pvt Ltd";
$defaultProjectEngineer = $preparedBy;
$defaultPmcLead = "PMC Lead";

// Get role display name
$roleDisplay = '';
switch($designation) {
  case 'team lead':
    $roleDisplay = 'Team Lead';
    break;
  case 'manager':
    $roleDisplay = 'Manager';
    break;
  default:
    $roleDisplay = 'Project Engineer';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Checklist - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

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
    @media (max-width: 992px){
      .grid-2, .grid-3{ grid-template-columns: 1fr; }
    }

    .badge-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      font-weight:900; font-size:12px;
    }
    .small-muted{ color:#6b7280; font-weight:800; font-size:12px; }

    .check-item{
      display:flex; align-items:flex-start; gap:10px;
      padding:10px 10px;
      border:1px solid #eef2f7;
      border-radius:14px;
      background:#fff;
      margin-bottom:10px;
    }
    .check-item input[type="checkbox"]{
      width:22px; height:22px; margin-top:2px;
      accent-color: var(--blue);
      flex:0 0 auto;
    }
    .check-title{ font-weight:900; color:#111827; }
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
    .site-count-badge {
      background: #f3f4f6;
      border-radius: 20px;
      padding: 2px 10px;
      font-size: 12px;
      font-weight: 800;
      color: #4b5563;
      margin-left: 8px;
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
            <h1 class="h-title">Project Engineer Daily Activity Checklist</h1>
            <p class="h-sub">Tick daily responsibilities and submit</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($preparedBy); ?></span>
            <span class="badge-pill"><i class="bi bi-award"></i> <?php echo e($roleDisplay); ?></span>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($_SESSION['success']): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- SITE PICKER -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-geo-alt"></i></div>
            <div>
              <p class="sec-title mb-0">Project Selection</p>
              <p class="sec-sub mb-0">Choose the site to fill checklist</p>
            </div>
          </div>

          <div class="grid-2">
            <div>
              <label class="form-label">
                My Assigned Sites <span class="text-danger">*</span>
                <span class="site-count-badge"><?php echo count($sites); ?> site<?php echo count($sites) != 1 ? 's' : ''; ?></span>
              </label>
              <select class="form-select" id="sitePicker">
                <option value="">-- Select Site --</option>
                <?php foreach ($sites as $s): ?>
                  <?php $sid = (int)$s['id']; ?>
                  <option value="<?php echo $sid; ?>" <?php echo ($sid === $siteId ? 'selected' : ''); ?>>
                    <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?> (<?php echo e($s['client_name']); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (empty($sites)): ?>
                <div class="small-muted mt-2 text-warning">
                  <i class="bi bi-exclamation-triangle"></i> You are not assigned to any sites. Please contact your manager.
                </div>
              <?php else: ?>
                <div class="small-muted mt-1">Selecting a site will load project & client details.</div>
              <?php endif; ?>
            </div>

            <div class="d-flex align-items-end justify-content-end">
              <a class="btn btn-outline-secondary" href="checklist.php" style="border-radius:12px; font-weight:900;">
                <i class="bi bi-arrow-clockwise"></i> Reset
              </a>
            </div>
          </div>
        </div>

        <!-- FORM -->
        <form method="POST" autocomplete="off">
          <input type="hidden" name="submit_checklist" value="1">
          <input type="hidden" name="site_id" value="<?php echo (int)$siteId; ?>">

          <!-- HEADER FIELDS (Project / Client / PMC / Date / Doc No) -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-card-checklist"></i></div>
              <div>
                <p class="sec-title mb-0">Checklist Header</p>
                <p class="sec-sub mb-0">Project info + doc details</p>
              </div>
            </div>

            <?php if (!$site): ?>
              <div class="text-muted" style="font-weight:800;">
                <i class="bi bi-info-circle"></i> Please select a site above to load details.
              </div>
            <?php else: ?>
              <div class="grid-3">
                <div>
                  <label class="form-label">Project</label>
                  <input class="form-control" value="<?php echo e($site['project_name']); ?>" readonly>
                </div>
                <div>
                  <label class="form-label">Client</label>
                  <input class="form-control" value="<?php echo e($site['client_name']); ?>" readonly>
                </div>
                <div>
                  <label class="form-label">PMC</label>
                  <input class="form-control" value="<?php echo e($pmcName); ?>" readonly>
                </div>
              </div>

              <div class="grid-2 mt-2">
                <div>
                  <label class="form-label">Date <span class="text-danger">*</span></label>
                  <input type="date" class="form-control" name="checklist_date" value="<?php echo e($formDate); ?>" required>
                </div>
                <div>
                  <label class="form-label">Doc No. <span class="text-danger">*</span></label>
                  <input class="form-control" name="doc_no" value="<?php echo e($formDocNo); ?>" required>
                  <div class="small-muted mt-1">Auto generated, but editable.</div>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- CHECKLIST SECTIONS -->
          <?php foreach ($checklistSections as $sectionName => $items): ?>
            <div class="panel">
              <div class="sec-head">
                <div class="sec-ic"><i class="bi bi-check2-square"></i></div>
                <div>
                  <p class="sec-title mb-0"><?php echo e($sectionName); ?></p>
                  <p class="sec-sub mb-0">Tick applicable items</p>
                </div>
              </div>

              <?php foreach ($items as $key => $label): ?>
                <label class="check-item">
                  <input type="checkbox" name="chk[]" value="<?php echo e($key); ?>">
                  <div>
                    <div class="check-title"><?php echo e($label); ?></div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>

          <!-- SIGN-OFF -->
          <div class="panel">
            <div class="sec-head">
              <div class="sec-ic"><i class="bi bi-pen"></i></div>
              <div>
                <p class="sec-title mb-0">Sign-off</p>
                <p class="sec-sub mb-0">Project Engineer & PMC Lead</p>
              </div>
            </div>

            <div class="grid-2">
              <div>
                <label class="form-label">Project Engineer <span class="text-danger">*</span></label>
                <input class="form-control" name="project_engineer" value="<?php echo e($defaultProjectEngineer); ?>" required>
              </div>
              <div>
                <label class="form-label">PMC Lead <span class="text-danger">*</span></label>
                <input class="form-control" name="pmc_lead" value="<?php echo e($defaultPmcLead); ?>" required>
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="submit" class="btn-primary-tek" <?php echo ($siteId<=0 ? 'disabled' : ''); ?>>
                <i class="bi bi-check2-circle"></i> Submit Checklist
              </button>
            </div>

            <?php if ($siteId<=0): ?>
              <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to enable submit.</div>
            <?php endif; ?>
          </div>
        </form>

        <!-- RECENT CHECKLISTS -->
        <div class="panel">
          <div class="sec-head">
            <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
            <div>
              <p class="sec-title mb-0">Recent Checklists</p>
              <p class="sec-sub mb-0">Your last submissions</p>
            </div>
          </div>

          <?php if (empty($recent)): ?>
            <div class="text-muted" style="font-weight:800;">No checklist submitted yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered align-middle mb-0">
                <thead>
                  <tr><th>Doc No</th><th>Date</th><th>Project</th>   </tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $r): ?>
                    <tr>
                      <td style="font-weight:1000;"><?php echo e($r['doc_no']); ?></td>
                      <td><?php echo e($r['checklist_date']); ?></td>
                      <td><?php echo e($r['project_name']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Site change -> reload
  var picker = document.getElementById('sitePicker');
  if (picker) {
    picker.addEventListener('change', function(){
      var v = picker.value || '';
      window.location.href = v ? ('checklist.php?site_id=' + encodeURIComponent(v)) : 'checklist.php';
    });
  }
});
</script>

</body>
</html>
<?php
if (isset($conn)) { mysqli_close($conn); }
?>