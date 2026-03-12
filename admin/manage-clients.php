<?php
// manage-clients.php (TEK-C style like manage-sites.php)
// ✅ UPDATED:
// 1) PRG redirect + flash messages (no resubmit on refresh)
// 2) MOBILE cards view (like manage-employees/manage-sites)
// 3) FIXED "latest project" logic (previous MAX() columns were wrong)
//    - Now uses a derived table to fetch latest site per client by created_at
// 4) Keeps DataTable for desktop

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$clients = [];
$success = '';
$error = '';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// Helpers
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safeVal($v, $dash='—'){
  $v = trim((string)$v);
  return $v === '' ? $dash : e($v);
}

// ---------------- DELETE CLIENT (PRG) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
  $id = (int)$_POST['delete_id'];

  $stmt = mysqli_prepare($conn, "DELETE FROM clients WHERE id = ? LIMIT 1");
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $id);
    if (mysqli_stmt_execute($stmt)) {
      $_SESSION['flash_success'] = "Client deleted successfully! (Related sites/projects removed automatically)";
    } else {
      $_SESSION['flash_error'] = "Error deleting client: " . mysqli_stmt_error($stmt);
    }
    mysqli_stmt_close($stmt);
  } else {
    $_SESSION['flash_error'] = "Database error: " . mysqli_error($conn);
  }

  header("Location: manage-clients.php");
  exit;
}

// ---------------- Flash messages ----------------
$success = (string)($_SESSION['flash_success'] ?? '');
$error   = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ---------------- FETCH CLIENTS + PROJECT INFO ----------------
// ✅ Correct "latest site" per client using derived table
$sql = "
  SELECT
    c.*,
    COALESCE(pc.project_count, 0) AS project_count,
    ls.created_at       AS last_project_created_at,
    ls.project_name     AS last_project_name,
    ls.project_type     AS last_project_type,
    ls.project_location AS last_project_location,
    ls.scope_of_work    AS last_scope_of_work
  FROM clients c
  LEFT JOIN (
    SELECT client_id, COUNT(*) AS project_count
    FROM sites
    GROUP BY client_id
  ) pc ON pc.client_id = c.id
  LEFT JOIN (
    SELECT s1.*
    FROM sites s1
    INNER JOIN (
      SELECT client_id, MAX(created_at) AS max_created
      FROM sites
      GROUP BY client_id
    ) t ON t.client_id = s1.client_id AND t.max_created = s1.created_at
  ) ls ON ls.client_id = c.id
  ORDER BY c.id DESC
";

$res = mysqli_query($conn, $sql);
if ($res) {
  $clients = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_free_result($res);
} else {
  $error = "Error fetching clients: " . mysqli_error($conn);
}

// ---------------- STATS ----------------
$total_clients = count($clients);

$type_counts = [
  'Individual' => 0,
  'Builder' => 0,
  'Developer' => 0,
  'Govt' => 0,
  'Corporate' => 0,
  'Company' => 0,
  'Organization' => 0
];

foreach ($clients as $c) {
  $t = $c['client_type'] ?? '';
  if (isset($type_counts[$t])) $type_counts[$t]++;
}

$individual_clients = (int)($type_counts['Individual'] ?? 0);
$company_like_clients = (int)($type_counts['Corporate'] ?? 0) + (int)($type_counts['Company'] ?? 0) + (int)($type_counts['Organization'] ?? 0);

// Top type
$topType = '—'; $topCnt = 0;
foreach ($type_counts as $k => $v) {
  if ($v > $topCnt) { $topCnt = $v; $topType = $k; }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage Clients - TEK-C</title>

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
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.purple{ background: #7c3aed; }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table-responsive { overflow-x: hidden !important; }
    table.dataTable { width:100% !important; }
    .table thead th{
      font-size: 11px; color:#6b7280; font-weight:800;
      border-bottom:1px solid var(--border)!important;
      padding: 10px 10px !important;
      white-space: normal !important;
    }
    .table td{
      vertical-align: top;
      border-color: var(--border);
      font-weight:650; color:#374151;
      padding: 10px 10px !important;
      white-space: normal !important;
      word-break: break-word;
    }

    .btn-add{
      background: var(--blue);
      color: white;
      border: none;
      padding: 10px 16px;
      border-radius: 12px;
      font-weight: 800;
      font-size: 13px;
      display:flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 8px 18px rgba(45, 156, 219, 0.18);
      text-decoration:none;
      white-space:nowrap;
    }
    .btn-add:hover { background:#2a8bc9; color:#fff; }

    .btn-action{
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 6px 9px;
      color: var(--muted);
      font-size: 12px;
      margin-left: 4px;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      font-weight:900;
    }
    .btn-action:hover { background: var(--bg); color: var(--blue); }

    .btn-delete{
      background: transparent;
      border: 1px solid rgba(235,87,87,.2);
      border-radius: 10px;
      padding: 6px 9px;
      color:#ef4444;
      font-size:12px;
      margin-left:4px;
      font-weight:900;
    }
    .btn-delete:hover{ background: rgba(239,68,68,.1); color:#d32f2f; }

    .client-title{ font-weight:900; font-size:13px; color:#1f2937; margin-bottom:2px; line-height:1.2; }
    .client-sub{ font-size:11px; color:#6b7280; font-weight:700; line-height:1.2; }

    .contact-info{
      font-size: 11px;
      color: #6b7280;
      display:flex;
      align-items:center;
      gap:6px;
      margin-top:2px;
      line-height:1.2;
      font-weight:800;
    }
    .contact-info i{ font-size: 11px; }

    .type-badge{
      padding: 3px 8px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: .3px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space: nowrap;
      border:1px solid rgba(45,156,219,.22);
      background: rgba(45,156,219,.08);
      color: var(--blue);
      text-transform: uppercase;
    }

    .proj-pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding: 3px 8px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 900;
      border:1px solid rgba(16,185,129,.22);
      background: rgba(16,185,129,.08);
      color:#10b981;
      white-space:nowrap;
      text-transform: uppercase;
      letter-spacing: .3px;
    }

    .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

    div.dataTables_wrapper .dataTables_length select,
    div.dataTables_wrapper .dataTables_filter input{
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 7px 10px;
      font-weight: 650;
      outline:none;
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

    th.actions-col, td.actions-col { width: 160px !important; white-space: nowrap !important; }

    /* ✅ MOBILE CARDS */
    .client-card{
      border:1px solid var(--border);
      border-radius:16px;
      background: var(--surface);
      box-shadow: var(--shadow);
      padding:12px;
    }
    .client-top{ display:flex; gap:10px; align-items:flex-start; justify-content:space-between; }
    .client-main{ flex:1 1 auto; }
    .cc-title{ font-weight:1000; font-size:14px; color:#111827; line-height:1.25; }
    .cc-sub{ font-size:12px; color:#6b7280; font-weight:800; margin-top:2px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .cc-kv{ margin-top:10px; display:grid; gap:8px; }
    .cc-row{ display:flex; gap:10px; }
    .cc-key{ flex:0 0 92px; color:#6b7280; font-weight:1000; font-size:12px; }
    .cc-val{ flex:1 1 auto; font-weight:900; color:#111827; font-size:13px; line-height:1.25; word-break:break-word; }
    .cc-actions{ margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; }
    .cc-actions a, .cc-actions button{ flex:1 1 auto; border-radius:12px; justify-content:center; font-weight:900; }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
    }
    @media (max-width: 768px){
      .content-scroll{ padding: 12px 10px 12px !important; }
      .container-fluid.maxw{ padding-left:6px !important; padding-right:6px !important; }
      .panel{ padding:12px !important; margin-bottom:12px; border-radius:14px; }
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
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
          <div>
            <h1 class="h3 fw-bold text-dark mb-1">Manage Clients</h1>
            <p class="text-muted mb-0">View and manage all client records</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="add-client.php" class="btn-add">
              <i class="bi bi-person-plus"></i> Add Client
            </a>
          </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-people-fill"></i></div>
              <div>
                <div class="stat-label">Total Clients</div>
                <div class="stat-value"><?php echo (int)$total_clients; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-person-fill"></i></div>
              <div>
                <div class="stat-label">Individuals</div>
                <div class="stat-value"><?php echo (int)$individual_clients; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-buildings"></i></div>
              <div>
                <div class="stat-label">Company / Corporate</div>
                <div class="stat-value"><?php echo (int)$company_like_clients; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic purple"><i class="bi bi-diagram-3"></i></div>
              <div>
                <div class="stat-label">Top Type</div>
                <div class="stat-value" style="font-size:18px;"><?php echo e($topType); ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- ✅ MOBILE: Client Cards -->
        <div class="d-block d-md-none mb-4">
          <?php if (empty($clients)): ?>
            <div class="panel text-muted" style="font-weight:900;">No clients found.</div>
          <?php else: ?>
            <div class="d-grid gap-3">
              <?php foreach ($clients as $c): ?>
                <?php
                  $clientName = trim((string)($c['client_name'] ?? ''));
                  $company    = trim((string)($c['company_name'] ?? ''));
                  $state      = trim((string)($c['state'] ?? ''));
                  $subLine    = $company !== '' ? $company : ($state !== '' ? $state : '—');

                  $projCount = (int)($c['project_count'] ?? 0);

                  $latestParts = [];
                  if (!empty($c['last_project_name'])) $latestParts[] = $c['last_project_name'];
                  if (!empty($c['last_project_type'])) $latestParts[] = $c['last_project_type'];
                  if (!empty($c['last_project_location'])) $latestParts[] = $c['last_project_location'];
                  $latestText = $latestParts ? implode(' • ', $latestParts) : '—';

                  $typeText = trim((string)($c['client_type'] ?? '—'));
                ?>
                <div class="client-card">
                  <div class="client-top">
                    <div class="client-main">
                      <div class="cc-title"><?php echo e($clientName); ?></div>
                      <div class="cc-sub">
                        <span><i class="bi bi-geo-alt"></i> <?php echo e($subLine); ?></span>
                        <span>•</span>
                        <span class="type-badge"><i class="bi bi-tags"></i> <?php echo e($typeText); ?></span>
                      </div>
                    </div>
                    <span class="proj-pill"><i class="bi bi-kanban"></i> <?php echo (int)$projCount; ?> <?php echo $projCount === 1 ? 'Project' : 'Projects'; ?></span>
                  </div>

                  <div class="cc-kv">
                    <div class="cc-row">
                      <div class="cc-key">Mobile</div>
                      <div class="cc-val"><?php echo safeVal($c['mobile_number'] ?? '', '—'); ?></div>
                    </div>
                    <div class="cc-row">
                      <div class="cc-key">Email</div>
                      <div class="cc-val"><?php echo safeVal($c['email'] ?? '', '—'); ?></div>
                    </div>
                    <div class="cc-row">
                      <div class="cc-key">Latest</div>
                      <div class="cc-val"><?php echo e($latestText); ?></div>
                    </div>
                    <?php if (!empty($c['last_scope_of_work'])): ?>
                      <div class="cc-row">
                        <div class="cc-key">Scope</div>
                        <div class="cc-val"><?php echo e($c['last_scope_of_work']); ?></div>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="cc-actions">
                    <a href="view-client.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-outline-primary btn-sm">
                      <i class="bi bi-eye"></i> View
                    </a>
                    <a href="edit-client.php?id=<?php echo (int)$c['id']; ?>" class="btn btn-outline-secondary btn-sm">
                      <i class="bi bi-pencil"></i> Edit
                    </a>
                    <a href="manage-sites.php?client_id=<?php echo (int)$c['id']; ?>" class="btn btn-outline-success btn-sm">
                      <i class="bi bi-kanban"></i> Projects
                    </a>
                    <form method="POST" style="margin:0;flex:1 1 auto;" onsubmit="return confirm('Delete this client? All related sites/projects will also be deleted.');">
                      <input type="hidden" name="delete_id" value="<?php echo (int)$c['id']; ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                        <i class="bi bi-trash"></i> Delete
                      </button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- ✅ DESKTOP: Table -->
        <div class="panel mb-4 d-none d-md-block">
          <div class="panel-header">
            <h3 class="panel-title">Clients Directory</h3>
            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
          </div>

          <div class="table-responsive">
            <table id="clientsTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
              <thead>
                <tr>
                  <th>Client</th>
                  <th>Type</th>
                  <th>Contact</th>
                  <th>Projects</th>
                  <th class="text-end actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($clients as $c): ?>
                <?php
                  $clientName = trim((string)($c['client_name'] ?? ''));
                  $company    = trim((string)($c['company_name'] ?? ''));
                  $state      = trim((string)($c['state'] ?? ''));

                  $subLine = $company !== '' ? $company : ($state !== '' ? $state : '—');

                  $projCount = (int)($c['project_count'] ?? 0);

                  $latestParts = [];
                  if (!empty($c['last_project_name'])) $latestParts[] = $c['last_project_name'];
                  if (!empty($c['last_project_type'])) $latestParts[] = $c['last_project_type'];
                  if (!empty($c['last_project_location'])) $latestParts[] = $c['last_project_location'];
                  $latestText = $latestParts ? implode(' • ', $latestParts) : '—';
                ?>
                <tr>
                  <td>
                    <div class="client-title"><?php echo e($clientName); ?></div>
                    <div class="client-sub"><i class="bi bi-geo-alt"></i> <?php echo e($subLine); ?></div>
                  </td>

                  <td>
                    <span class="type-badge">
                      <i class="bi bi-tags"></i>
                      <?php echo e($c['client_type'] ?? '—'); ?>
                    </span>
                  </td>

                  <td>
                    <?php if (!empty($c['mobile_number'])): ?>
                      <div class="contact-info"><i class="bi bi-telephone"></i> <?php echo e($c['mobile_number']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($c['email'])): ?>
                      <div class="contact-info"><i class="bi bi-envelope"></i> <?php echo e($c['email']); ?></div>
                    <?php endif; ?>
                    <?php if (empty($c['mobile_number']) && empty($c['email'])): ?>
                      —
                    <?php endif; ?>
                  </td>

                  <td>
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                      <span class="proj-pill"><i class="bi bi-kanban"></i> <?php echo (int)$projCount; ?> Project<?php echo $projCount === 1 ? '' : 's'; ?></span>
                    </div>

                    <div class="client-sub" style="font-size:13px;color:#111827;font-weight:800;">
                      <?php echo e($latestText); ?>
                    </div>

                    <?php if (!empty($c['last_scope_of_work'])): ?>
                      <div class="client-sub"><i class="bi bi-list-check"></i> <?php echo e($c['last_scope_of_work']); ?></div>
                    <?php endif; ?>
                  </td>

                  <td class="text-end actions-col">
                    <a href="view-client.php?id=<?php echo (int)$c['id']; ?>" class="btn-action" title="View Client">
                      <i class="bi bi-eye"></i>
                    </a>

                    <a href="edit-client.php?id=<?php echo (int)$c['id']; ?>" class="btn-action" title="Edit Client">
                      <i class="bi bi-pencil"></i>
                    </a>

                    <a href="manage-sites.php?client_id=<?php echo (int)$c['id']; ?>" class="btn-action" title="View Projects">
                      <i class="bi bi-kanban"></i>
                    </a>

                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this client? All related sites/projects will also be deleted.');">
                      <input type="hidden" name="delete_id" value="<?php echo (int)$c['id']; ?>">
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

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
(function () {
  $(function () {
    $('#clientsTable').DataTable({
      responsive: true,
      autoWidth: false,
      scrollX: false,
      pageLength: 10,
      lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
      order: [[0, 'asc']],
      columnDefs: [
        { targets: [4], orderable: false, searchable: false }
      ],
      language: {
        zeroRecords: "No matching clients found",
        info: "Showing _START_ to _END_ of _TOTAL_ clients",
        infoEmpty: "No clients to show",
        lengthMenu: "Show _MENU_",
        search: "Search:"
      }
    });

    setTimeout(function() {
      $('.dataTables_filter input').trigger('focus');
    }, 400);
  });
})();
</script>

</body>
</html>

<?php
if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
?>