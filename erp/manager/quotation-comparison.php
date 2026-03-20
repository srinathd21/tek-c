<?php
// quotation-comparison.php
session_start();
require_once 'includes/db-config.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header('Location: login.php');
    exit();
}

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

$user_id = (int)$_SESSION['employee_id'];
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Allow managers and directors
$allowed = ['manager', 'director', 'vice president', 'general manager'];
if (!in_array($user_designation, $allowed, true)) {
    header('Location: index.php');
    exit();
}

// Get request ID from URL
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($request_id === 0) {
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('Invalid request ID'));
    exit();
}

// Helper functions
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safeDate($v, $dash='—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $dash;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function formatCurrency($amount) {
    if ($amount === null || $amount == 0) return '—';
    return '₹ ' . number_format($amount, 2);
}

function getStatusBadge($status) {
    $badges = [
        'Draft' => 'bg-secondary',
        'Pending Submission' => 'bg-warning',
        'Submitted' => 'bg-info',
        'With QS' => 'bg-secondary',
        'QS Negotiated' => 'bg-primary',
        'Finalized' => 'bg-success',
        'Approved' => 'bg-success',
        'Rejected' => 'bg-danger'
    ];
    $class = $badges[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $class . '">' . $status . '</span>';
}

// Fetch quotation request details
$request_query = "
    SELECT 
        qr.*,
        s.project_name,
        s.project_code,
        s.project_location,
        c.client_name,
        c.company_name
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    LEFT JOIN clients c ON s.client_id = c.id
    WHERE qr.id = ? AND qr.requested_by = ?
";

$stmt = mysqli_prepare($conn, $request_query);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $user_id);
mysqli_stmt_execute($stmt);
$request_result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($request_result);
mysqli_stmt_close($stmt);

if (!$request) {
    header('Location: my-quotation-requests.php?status=error&message=' . urlencode('Quotation request not found'));
    exit();
}

// Fetch all quotations for this request with dealer information
$quotations_query = "
    SELECT 
        q.*,
        d.dealer_name,
        d.contact_person,
        d.mobile_number,
        d.email,
        d.gst_number,
        d.city,
        d.state,
        (
            SELECT COUNT(*) 
            FROM quotation_items qi 
            WHERE qi.quotation_id = q.id
        ) AS item_count
    FROM quotations q
    LEFT JOIN quotation_dealers d ON q.dealer_id = d.id
    WHERE q.quotation_request_id = ?
    ORDER BY 
        CASE 
            WHEN q.status = 'Finalized' THEN 1
            WHEN q.status = 'QS Negotiated' THEN 2
            WHEN q.status = 'Approved' THEN 3
            ELSE 4
        END,
        q.grand_total ASC
";

$stmt = mysqli_prepare($conn, $quotations_query);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$quotations_result = mysqli_stmt_get_result($stmt);
$quotations = mysqli_fetch_all($quotations_result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// Calculate statistics
$total_quotations = count($quotations);
$lowest_amount = null;
$highest_amount = null;
$average_amount = 0;
$finalized_quotation = null;
$recommended_quotation = null;

if ($total_quotations > 0) {
    $amounts = array_column($quotations, 'grand_total');
    $lowest_amount = min($amounts);
    $highest_amount = max($amounts);
    $average_amount = array_sum($amounts) / $total_quotations;
    
    foreach ($quotations as $q) {
        if ($q['status'] === 'Finalized') {
            $finalized_quotation = $q;
        }
        if (!empty($q['is_recommended'])) {
            $recommended_quotation = $q;
        }
    }
}

// Get status message if any
$status = $_GET['status'] ?? '';
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Quotation Comparison - TEK-C</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <!-- DataTables -->
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
        .stat-ic.red{ background: #ef4444; }
        .stat-ic.purple{ background: #8b5cf6; }
        .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
        .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }
        .stat-small{ font-size:14px; font-weight:700; color:#6b7280; }

        .comparison-table th {
            background: #f9fafb;
            font-weight: 800;
            font-size: 12px;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .comparison-table td {
            vertical-align: middle;
            padding: 15px 12px;
        }
        .comparison-table .lowest-price {
            background: rgba(16,185,129,.08);
            font-weight: 900;
            color: #10b981;
        }
        .comparison-table .highest-price {
            background: rgba(239,68,68,.08);
            color: #ef4444;
        }
        .comparison-table .finalized-row {
            background: rgba(16,185,129,.05);
            border-left: 4px solid #10b981;
        }
        .comparison-table .recommended-row {
            background: rgba(45,156,219,.05);
            border-left: 4px solid var(--blue);
        }

        .badge-diff {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 900;
        }
        .badge-diff.positive {
            background: rgba(16,185,129,.12);
            color: #10b981;
        }
        .badge-diff.negative {
            background: rgba(239,68,68,.12);
            color: #ef4444;
        }

        .btn-action {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 7px 10px;
            color: var(--muted);
            font-size: 12px;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            font-weight: 900;
        }
        .btn-action:hover { background: var(--bg); color: var(--blue); }
        .btn-action.approve{
            border-color: rgba(16,185,129,.25);
        }
        .btn-action.pdf{
            border-color: rgba(239,68,68,.25);
        }

        .alert { border-radius: var(--radius); border:none; box-shadow: var(--shadow); margin-bottom: 20px; }

        .summary-card {
            background: #f9fafb;
            border-radius: 12px;
            padding: 15px;
            border: 1px solid var(--border);
            height: 100%;
        }
        .summary-label {
            font-size: 11px;
            font-weight: 800;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 5px;
        }
        .summary-value {
            font-size: 20px;
            font-weight: 900;
            color: #1f2937;
        }
        .summary-value small {
            font-size: 13px;
            font-weight: 700;
            color: #6b7280;
        }

        @media (max-width: 991.98px){
            .main{
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            .sidebar{
                position: fixed !important;
                transform: translateX(-100%);
                z-index: 1040 !important;
            }
            .sidebar.open, .sidebar.active, .sidebar.show{
                transform: translateX(0) !important;
            }
        }
        @media (max-width: 768px) {
            .content-scroll { padding: 12px 10px 12px !important; }
            .container-fluid.maxw { padding-left: 6px !important; padding-right: 6px !important; }
            .panel { padding: 12px !important; margin-bottom: 12px; border-radius: 14px; }
            .comparison-table { font-size: 13px; }
            .summary-value { font-size: 16px; }
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
                    
                    <!-- Status Messages -->
                    <?php if ($status && $message): ?>
                        <div class="alert alert-<?php echo $status === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                            <i class="bi bi-<?php echo $status === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Header -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h1 class="h3 fw-bold text-dark mb-1">Quotation Comparison</h1>
                            <p class="text-muted mb-0">
                                <span class="fw-800"><?php echo e($request['request_no']); ?></span> • 
                                <?php echo e($request['title']); ?> • 
                                <?php echo e($request['project_name']); ?>
                                <?php if (!empty($request['project_code'])): ?>
                                    (<?php echo e($request['project_code']); ?>)
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="view-quotation-request.php?id=<?php echo $request_id; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                            <?php if ($request['status'] === 'QS Finalized'): ?>
                                <a href="approve-quotation.php?request_id=<?php echo $request_id; ?>" class="btn btn-primary">
                                    <i class="bi bi-check-lg"></i> Make Decision
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic blue"><i class="bi bi-file-text"></i></div>
                                <div>
                                    <div class="stat-label">Total Quotations</div>
                                    <div class="stat-value"><?php echo $total_quotations; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic green"><i class="bi bi-arrow-down"></i></div>
                                <div>
                                    <div class="stat-label">Lowest Amount</div>
                                    <div class="stat-value"><?php echo formatCurrency($lowest_amount); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic red"><i class="bi bi-arrow-up"></i></div>
                                <div>
                                    <div class="stat-label">Highest Amount</div>
                                    <div class="stat-value"><?php echo formatCurrency($highest_amount); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="stat-card">
                                <div class="stat-ic purple"><i class="bi bi-calculator"></i></div>
                                <div>
                                    <div class="stat-label">Average Amount</div>
                                    <div class="stat-value"><?php echo formatCurrency($average_amount); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="summary-label">Estimated Budget</div>
                                <div class="summary-value"><?php echo formatCurrency($request['estimated_budget']); ?></div>
                                <?php if ($lowest_amount && $request['estimated_budget'] > 0): ?>
                                    <?php $diff_percent = (($lowest_amount - $request['estimated_budget']) / $request['estimated_budget']) * 100; ?>
                                    <div class="mt-2">
                                        <span class="badge-diff <?php echo $diff_percent <= 0 ? 'positive' : 'negative'; ?>">
                                            <?php echo $diff_percent <= 0 ? '↓' : '↑'; ?> 
                                            <?php echo number_format(abs($diff_percent), 1); ?>% from estimate
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="summary-label">Price Range</div>
                                <div class="summary-value">
                                    <?php echo formatCurrency($lowest_amount); ?> 
                                    <small>to</small> 
                                    <?php echo formatCurrency($highest_amount); ?>
                                </div>
                                <?php if ($lowest_amount && $highest_amount && $lowest_amount != $highest_amount): ?>
                                    <?php $range_percent = (($highest_amount - $lowest_amount) / $lowest_amount) * 100; ?>
                                    <div class="mt-2">
                                        <span class="badge-diff negative">
                                            ↑ <?php echo number_format($range_percent, 1); ?>% variation
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card">
                                <div class="summary-label">QS Recommendation</div>
                                <?php if ($finalized_quotation): ?>
                                    <div class="summary-value text-success">
                                        <?php echo formatCurrency($finalized_quotation['grand_total']); ?>
                                    </div>
                                    <div class="mt-1">
                                        <span class="badge bg-success">Finalized</span>
                                        <?php if ($finalized_quotation['finalized_amount'] && $finalized_quotation['finalized_amount'] != $finalized_quotation['grand_total']): ?>
                                            <span class="small text-muted ms-2">
                                                (Originally: <?php echo formatCurrency($finalized_quotation['grand_total']); ?>)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="summary-value text-muted">—</div>
                                    <div class="mt-1 small">No quotation finalized yet</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Comparison Table Panel -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Quotation Comparison</h3>
                            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                        </div>
                        
                        <?php if (empty($quotations)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-inbox" style="font-size: 48px; color: #9ca3af;"></i>
                                <p class="mt-3 fw-800 text-muted">No quotations received yet</p>
                                <a href="quotation-requests.php" class="btn btn-primary btn-sm mt-2">Back to Requests</a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table comparison-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Quotation Details</th>
                                            <th>Dealer</th>
                                            <th>Amount</th>
                                            <th>Finalized Amount</th>
                                            <th>Status</th>
                                            <th>Items</th>
                                            <th>Valid Until</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($quotations as $index => $quote): 
                                            $rowClass = '';
                                            if ($quote['status'] === 'Finalized') $rowClass = 'finalized-row';
                                            elseif (!empty($quote['is_recommended'])) $rowClass = 'recommended-row';
                                            
                                            $priceClass = '';
                                            if ($quote['grand_total'] == $lowest_amount) $priceClass = 'lowest-price';
                                            elseif ($quote['grand_total'] == $highest_amount) $priceClass = 'highest-price';
                                        ?>
                                            <tr class="<?php echo $rowClass; ?>">
                                                <td class="fw-800"><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="fw-900"><?php echo e($quote['quotation_no']); ?></div>
                                                    <div class="proj-sub">
                                                        <i class="bi bi-calendar"></i> <?php echo safeDate($quote['quotation_date']); ?>
                                                    </div>
                                                    <?php if (!empty($quote['dealer_quotation_ref'])): ?>
                                                        <div class="proj-sub">
                                                            Ref: <?php echo e($quote['dealer_quotation_ref']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-800"><?php echo e($quote['dealer_name'] ?? 'Unknown Dealer'); ?></div>
                                                    <?php if (!empty($quote['contact_person'])): ?>
                                                        <div class="proj-sub">
                                                            <i class="bi bi-person"></i> <?php echo e($quote['contact_person']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($quote['mobile_number'])): ?>
                                                        <div class="proj-sub">
                                                            <i class="bi bi-telephone"></i> <?php echo e($quote['mobile_number']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="<?php echo $priceClass; ?> fw-900">
                                                    <?php echo formatCurrency($quote['grand_total']); ?>
                                                    <?php if (!empty($quote['discount_amount']) && $quote['discount_amount'] > 0): ?>
                                                        <div class="proj-sub text-success">
                                                            -<?php echo formatCurrency($quote['discount_amount']); ?> discount
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-800">
                                                    <?php if (!empty($quote['finalized_amount'])): ?>
                                                        <span class="text-success"><?php echo formatCurrency($quote['finalized_amount']); ?></span>
                                                        <?php if ($quote['finalized_amount'] < $quote['grand_total']): ?>
                                                            <div class="proj-sub text-success">
                                                                ↓ <?php echo number_format((($quote['grand_total'] - $quote['finalized_amount']) / $quote['grand_total']) * 100, 1); ?>% saved
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        —
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo getStatusBadge($quote['status']); ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($quote['item_count'] > 0): ?>
                                                        <span class="badge bg-info rounded-pill"><?php echo $quote['item_count']; ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($quote['valid_until'])): ?>
                                                        <?php echo safeDate($quote['valid_until']); ?>
                                                        <?php if (strtotime($quote['valid_until']) < time()): ?>
                                                            <span class="badge bg-danger bg-opacity-10 text-danger ms-1">Expired</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        —
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if (!empty($quote['quotation_document'])): ?>
                                                        <a href="<?php echo e($quote['quotation_document']); ?>" target="_blank" class="btn-action pdf" title="View PDF">
                                                            <i class="bi bi-file-pdf"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="view-quotation.php?id=<?php echo $quote['id']; ?>" class="btn-action" title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Legend -->
                            <div class="d-flex gap-4 mt-3 pt-2 border-top">
                                <div class="d-flex align-items-center gap-2">
                                    <span style="width: 16px; height: 16px; background: rgba(16,185,129,.2); border-radius: 4px;"></span>
                                    <span class="small fw-700">Finalized / QS Recommended</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span style="width: 16px; height: 16px; background: rgba(45,156,219,.2); border-radius: 4px;"></span>
                                    <span class="small fw-700">Recommended (if different)</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span style="width: 16px; height: 16px; background: rgba(16,185,129,.2);" class="border rounded-circle"></span>
                                    <span class="small fw-700">Lowest Price</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span style="width: 16px; height: 16px; background: rgba(239,68,68,.2);" class="border rounded-circle"></span>
                                    <span class="small fw-700">Highest Price</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Comparison Notes Panel (if available) -->
                    <?php if (!empty($request['notes']) || !empty($request['specifications'])): ?>
                    <div class="panel mt-4">
                        <div class="panel-header">
                            <h3 class="panel-title">Request Notes & Specifications</h3>
                            <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                        </div>
                        <div class="row">
                            <?php if (!empty($request['specifications'])): ?>
                            <div class="col-md-6">
                                <div class="fw-800 mb-2">Specifications</div>
                                <div class="p-3 bg-light rounded">
                                    <?php echo nl2br(e($request['specifications'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($request['notes'])): ?>
                            <div class="col-md-6">
                                <div class="fw-800 mb-2">Additional Notes</div>
                                <div class="p-3 bg-light rounded">
                                    <?php echo nl2br(e($request['notes'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                </div>
            </div>
            
            <?php include 'includes/footer.php'; ?>
        </main>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    
    <script src="assets/js/sidebar-toggle.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTable for better sorting/filtering on desktop
            if (window.matchMedia('(min-width: 768px)').matches) {
                $('.comparison-table').DataTable({
                    paging: false,
                    searching: true,
                    ordering: true,
                    info: false,
                    columnDefs: [
                        { orderable: false, targets: [8] } // Actions column
                    ],
                    language: {
                        search: "Filter:",
                        searchPlaceholder: "Search quotations..."
                    }
                });
            }
        });
    </script>
</body>
</html>