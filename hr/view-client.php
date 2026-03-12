<?php
// view-client.php (SAME DESIGN as your view-site.php)
// - Shows single client from `clients` table
// - Lists client projects from `sites` table (optional section)
// - Uses TEK-C includes/sidebar/topbar/footer

session_start();
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$client   = null;
$sites    = [];
$error    = '';
$success  = '';

// ---------- Helpers ----------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function showVal($v, $dash='—') {
  $v = trim((string)$v);
  return $v === '' ? $dash : e($v);
}

function initials($name) {
  $name = trim((string)$name);
  if ($name === '') return 'C';
  $parts = preg_split('/\s+/', $name);
  $first = strtoupper(substr($parts[0] ?? 'C', 0, 1));
  $last  = strtoupper(substr(end($parts) ?: '', 0, 1));
  return ($last && count($parts) > 1) ? ($first.$last) : $first;
}

function safeMailTo($email) {
  $email = trim((string)$email);
  if ($email === '') return '';
  return 'mailto:' . rawurlencode($email);
}

function safeTel($phone) {
  $phone = trim((string)$phone);
  if ($phone === '') return '';
  $clean = preg_replace('/[^\d+]/', '', $phone);
  return 'tel:' . $clean;
}

// ---------- Fetch client ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  $error = "Invalid client ID.";
} else {
  $stmt = mysqli_prepare($conn, "SELECT * FROM clients WHERE id = ? LIMIT 1");
  if (!$stmt) {
    $error = "Database error: " . mysqli_error($conn);
  } else {
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $client = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$client) $error = "Client not found.";
  }

  // Fetch client sites/projects (optional)
  if ($client) {
    $stmt2 = mysqli_prepare($conn, "SELECT id, project_name, project_type, project_location, agreement_number, contract_document, created_at
                                   FROM sites WHERE client_id = ? ORDER BY created_at DESC");
    if ($stmt2) {
      mysqli_stmt_bind_param($stmt2, "i", $id);
      mysqli_stmt_execute($stmt2);
      $res2 = mysqli_stmt_get_result($stmt2);
      $sites = mysqli_fetch_all($res2, MYSQLI_ASSOC);
      mysqli_stmt_close($stmt2);
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>View Client - TEK-C</title>

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

    .btn-edit{
      background: var(--blue);
      color:#fff;
      border:none;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 900;
      display:inline-flex;
      align-items:center;
      gap:8px;
      box-shadow: 0 12px 26px rgba(45,156,219,.18);
      text-decoration:none;
    }
    .btn-edit:hover{ background:#2a8bc9; color:#fff; }

    .client-hero{
      display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;
    }

    /* ✅ renamed to avoid affecting topbar */
    .client-hero-id{ display:flex; gap:14px; align-items:center; min-width:260px; }

    /* ✅ renamed avatar class */
    .client-avatar{
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

    .tabs-only-card{
      background:#fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(17,24,39,.05);
      padding: 10px;
      margin-bottom: 14px;
    }
    .tabs-only-card .nav.client-tabs{ gap:8px; flex-wrap:wrap; }
    .tabs-only-card .nav.client-tabs .nav-link{
      border: 1px solid #e5e7eb;
      background:#fff;
      border-radius: 12px;
      font-weight: 900;
      font-size: 13px;
      color: #111827;
      padding: 10px 12px;
      display:inline-flex;
      align-items:center;
      gap: 8px;
    }
    .tabs-only-card .nav.client-tabs .nav-link:hover{
      background:#f9fafb;
      color: var(--blue);
      border-color: rgba(45,156,219,.25);
    }
    .tabs-only-card .nav.client-tabs .nav-link.active{
      background: rgba(45,156,219,.10);
      color: var(--blue);
      border-color: rgba(45,156,219,.28);
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
      grid-template-columns: 260px 1fr;
      gap: 12px;
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

    .qa a{
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 10px;
      border-radius: 12px;
      border:1px solid #e5e7eb;
      background:#fff;
      font-weight:900;
      color:#111827;
      margin-left:8px;
      margin-bottom:8px;
    }
    .qa a:hover{ background:#f9fafb; color: var(--blue); border-color: rgba(45,156,219,.25); }

    .site-list{ display:flex; flex-direction:column; }
    .site-item{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      padding: 12px 16px;
      border-bottom: 1px solid #eef2f7;
    }
    .site-item:last-child{ border-bottom:none; }
    .site-title{ font-weight:1000; color:#111827; font-size:14px; margin:0; }
    .site-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:12px; display:flex; gap:10px; flex-wrap:wrap; }
    .site-actions a{
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:38px; height:38px;
      border-radius: 12px;
      border:1px solid #e5e7eb;
      background:#fff;
      color:#111827;
      margin-left:6px;
    }
    .site-actions a:hover{ background:#f9fafb; color: var(--blue); border-color: rgba(45,156,219,.25); }

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
      .kv-row{ grid-template-columns: 160px 1fr; }
      .kv-v{ text-align:left; }
      .qa a{ margin-left:0; margin-right:8px; }
      .site-item{ flex-direction:column; align-items:flex-start; }
      .site-actions a{ margin-left:0; margin-right:8px; }
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
            <h1 class="h3 fw-bold text-dark mb-1">View Client</h1>
            <p class="text-muted mb-0">Client profile, addresses, and project list</p>
          </div>
          <div class="d-flex gap-2">
            <a href="manage-clients.php" class="btn-back">
              <i class="bi bi-arrow-left"></i> Back
            </a>
            <?php if ($client): ?>
              <a href="edit-client.php?id=<?php echo (int)$client['id']; ?>" class="btn-edit">
                <i class="bi bi-pencil"></i> Edit
              </a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($client): ?>

          <!-- Summary -->
          <div class="panel">
            <div class="client-hero">
              <div class="client-hero-id">
                <div class="client-avatar"><?php echo e(initials($client['client_name'] ?? 'Client')); ?></div>
                <div>
                  <p class="hero-title"><?php echo showVal($client['client_name']); ?></p>
                  <p class="hero-sub">
                    <?php echo showVal($client['client_type']); ?>
                    <?php if (!empty($client['state'])): ?> • <?php echo e($client['state']); ?><?php endif; ?>
                    <?php if (!empty($client['company_name'])): ?> • <?php echo e($client['company_name']); ?><?php endif; ?>
                  </p>

                  <div class="d-flex flex-wrap gap-2 mt-2">
                    <?php if (!empty($client['client_type'])): ?>
                      <span class="chip chip-primary"><i class="bi bi-tags"></i> <?php echo e($client['client_type']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($client['state'])): ?>
                      <span class="chip"><i class="bi bi-geo-alt"></i> <?php echo e($client['state']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($client['mobile_number'])): ?>
                      <span class="chip"><i class="bi bi-telephone"></i> <?php echo e($client['mobile_number']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="qa">
                <?php if (!empty($client['email'])): ?>
                  <a href="<?php echo e(safeMailTo($client['email'])); ?>"><i class="bi bi-envelope"></i> Email</a>
                <?php endif; ?>
                <?php if (!empty($client['mobile_number'])): ?>
                  <a href="<?php echo e(safeTel($client['mobile_number'])); ?>"><i class="bi bi-telephone"></i> Call</a>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Tabs -->
          <div class="tabs-only-card">
            <ul class="nav client-tabs" id="clientTabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-basic" data-bs-toggle="tab" data-bs-target="#pane-basic" type="button" role="tab">
                  <i class="bi bi-person"></i> Basic
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-address" data-bs-toggle="tab" data-bs-target="#pane-address" type="button" role="tab">
                  <i class="bi bi-geo-alt"></i> Addresses
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-kyc" data-bs-toggle="tab" data-bs-target="#pane-kyc" type="button" role="tab">
                  <i class="bi bi-shield-check"></i> KYC
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-projects" data-bs-toggle="tab" data-bs-target="#pane-projects" type="button" role="tab">
                  <i class="bi bi-kanban"></i> Projects
                </button>
              </li>
            </ul>
          </div>

          <div class="tab-content" id="clientTabsContent">

            <!-- BASIC TAB -->
            <div class="tab-pane fade show active" id="pane-basic" role="tabpanel" aria-labelledby="tab-basic">
              <div class="section-card">
                <div class="section-head">
                  <div class="left">
                    <div class="sec-ic"><i class="bi bi-person"></i></div>
                    <div>
                      <p class="sec-title mb-0">Basic Information</p>
                      <p class="sec-sub">Contact + type details</p>
                    </div>
                  </div>
                </div>

                <div class="kv">
                  <div class="kv-row"><div class="kv-k">Client Name</div><div class="kv-v"><?php echo showVal($client['client_name']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">Client Type</div><div class="kv-v"><?php echo showVal($client['client_type']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">Company Name</div><div class="kv-v"><?php echo showVal($client['company_name']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">Mobile Number</div><div class="kv-v"><?php echo showVal($client['mobile_number']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">Email</div><div class="kv-v"><?php echo showVal($client['email']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">State</div><div class="kv-v"><?php echo showVal($client['state']); ?></div></div>
                </div>
              </div>
            </div>

            <!-- ADDRESSES TAB -->
            <div class="tab-pane fade" id="pane-address" role="tabpanel" aria-labelledby="tab-address">
              <div class="section-card">
                <div class="section-head">
                  <div class="left">
                    <div class="sec-ic"><i class="bi bi-geo-alt"></i></div>
                    <div>
                      <p class="sec-title mb-0">Address Details</p>
                      <p class="sec-sub">Office, site, billing and shipping</p>
                    </div>
                  </div>
                </div>

                <div class="kv">
                  <div class="kv-row"><div class="kv-k">Office Address</div><div class="kv-v"><?php echo showVal($client['office_address']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">Site Address</div><div class="kv-v"><?php echo showVal($client['site_address']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">Billing Address</div><div class="kv-v"><?php echo showVal($client['billing_address']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">Shipping Address</div><div class="kv-v"><?php echo showVal($client['shipping_address']); ?></div></div>
                </div>
              </div>
            </div>

            <!-- KYC TAB -->
            <div class="tab-pane fade" id="pane-kyc" role="tabpanel" aria-labelledby="tab-kyc">
              <div class="section-card">
                <div class="section-head">
                  <div class="left">
                    <div class="sec-ic"><i class="bi bi-shield-check"></i></div>
                    <div>
                      <p class="sec-title mb-0">KYC Details</p>
                      <p class="sec-sub">PAN / GST / Aadhaar</p>
                    </div>
                  </div>
                </div>

                <div class="kv">
                  <div class="kv-row"><div class="kv-k">PAN Number</div><div class="kv-v"><?php echo showVal($client['pan_number']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">GST Number</div><div class="kv-v"><?php echo showVal($client['gst_number']); ?></div></div>
                  <div class="kv-row"><div class="kv-k">Aadhaar Number</div><div class="kv-v"><?php echo showVal($client['aadhaar_number']); ?></div></div>
                </div>
              </div>
            </div>

            <!-- PROJECTS TAB -->
            <div class="tab-pane fade" id="pane-projects" role="tabpanel" aria-labelledby="tab-projects">
              <div class="section-card">
                <div class="section-head">
                  <div class="left">
                    <div class="sec-ic"><i class="bi bi-kanban"></i></div>
                    <div>
                      <p class="sec-title mb-0">Projects</p>
                      <p class="sec-sub">All sites linked to this client</p>
                    </div>
                  </div>
                  <div class="chip">
                    <i class="bi bi-list-ul"></i> <?php echo (int)count($sites); ?> Total
                  </div>
                </div>

                <?php if (empty($sites)): ?>
                  <div class="p-3 text-muted" style="font-weight:800;">No projects found for this client.</div>
                <?php else: ?>
                  <div class="site-list">
                    <?php foreach ($sites as $s): ?>
                      <div class="site-item">
                        <div>
                          <p class="site-title"><?php echo showVal($s['project_name'] ?? ''); ?></p>
                          <p class="site-sub">
                            <?php if (!empty($s['project_type'])): ?>
                              <span><i class="bi bi-tags"></i> <?php echo e($s['project_type']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($s['project_location'])): ?>
                              <span><i class="bi bi-geo-alt"></i> <?php echo e($s['project_location']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($s['agreement_number'])): ?>
                              <span><i class="bi bi-file-earmark-text"></i> <?php echo e($s['agreement_number']); ?></span>
                            <?php endif; ?>
                          </p>
                        </div>

                        <div class="site-actions">
                          <a href="view-site.php?id=<?php echo (int)$s['id']; ?>" title="View Site">
                            <i class="bi bi-eye"></i>
                          </a>
                          <?php if (!empty($s['contract_document'])): ?>
                            <a href="<?php echo e($s['contract_document']); ?>" target="_blank" rel="noopener" title="Contract Document">
                              <i class="bi bi-file-earmark-arrow-down"></i>
                            </a>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

              </div>
            </div>

          </div><!-- tab-content -->

        <?php endif; ?>

      </div>
    </div>

    <?php include 'includes/footer.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const key = 'viewClientActiveTab';
  const tabs = document.querySelectorAll('#clientTabs button[data-bs-toggle="tab"]');

  const saved = localStorage.getItem(key);
  if (saved) {
    const btn = document.querySelector('#clientTabs button[data-bs-target="' + saved + '"]');
    if (btn) new bootstrap.Tab(btn).show();
  }

  tabs.forEach(btn => {
    btn.addEventListener('shown.bs.tab', function (e) {
      const target = e.target.getAttribute('data-bs-target');
      if (target) localStorage.setItem(key, target);
    });
  });
});
</script>

</body>
</html>

<?php
if (isset($conn)) { mysqli_close($conn); }
?>
