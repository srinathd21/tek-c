<?php
// approve-quotation.php
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
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown User';
$user_designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

// Allow only managers and directors
$allowed = ['manager', 'director', 'vice president', 'general manager'];
if (!in_array($user_designation, $allowed, true)) {
    header('Location: index.php');
    exit();
}

// Get request ID from URL
$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

if ($request_id === 0) {
    header('Location: pending-approvals.php?status=error&message=' . urlencode('Invalid request ID'));
    exit();
}

// Fetch request details
$query = "
    SELECT qr.*, s.project_name, s.project_code 
    FROM quotation_requests qr
    JOIN sites s ON qr.site_id = s.id
    WHERE qr.id = ? AND qr.requested_by = ? AND qr.status = 'QS Finalized'
";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $request_id, $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$request = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$request) {
    header('Location: pending-approvals.php?status=error&message=' . urlencode('Request not found or not ready for approval'));
    exit();
}

// Get quotations for this request
$quotes_query = "
    SELECT * FROM quotations 
    WHERE quotation_request_id = ? 
    ORDER BY 
        CASE 
            WHEN status = 'Finalized' THEN 1
            WHEN status = 'QS Negotiated' THEN 2
            ELSE 3
        END,
        grand_total ASC
";

$stmt = mysqli_prepare($conn, $quotes_query);
mysqli_stmt_bind_param($stmt, "i", $request_id);
mysqli_stmt_execute($stmt);
$quotes_result = mysqli_stmt_get_result($stmt);
$quotations = mysqli_fetch_all($quotes_result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Approve Quotation - TEK-C</title>
    
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
        
        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        
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
        
        .quotation-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.2s;
        }
        .quotation-card:hover {
            border-color: var(--blue);
            box-shadow: var(--shadow);
        }
        .quotation-card.finalized {
            border-left: 4px solid #10b981;
            background: #f0fdf4;
        }
        .quotation-card.recommended {
            border-left: 4px solid var(--blue);
            background: #f0f9ff;
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
                            <h1 class="h3 fw-bold text-dark mb-1">Approve Quotation Request</h1>
                            <p class="text-muted mb-0">
                                Request #<?php echo htmlspecialchars($request['request_no']); ?> • <?php echo htmlspecialchars($request['title']); ?>
                            </p>
                        </div>
                        <div>
                            <a href="pending-approvals.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>
                    
                    <!-- Request Summary -->
                    <div class="panel mb-4">
                        <div class="panel-header">
                            <h3 class="panel-title">Request Summary</h3>
                        </div>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="fw-800 text-muted small">Project</div>
                                <div><?php echo htmlspecialchars($request['project_name']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="fw-800 text-muted small">Type</div>
                                <div><?php echo htmlspecialchars($request['quotation_type']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="fw-800 text-muted small">Priority</div>
                                <div><?php echo htmlspecialchars($request['priority']); ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="fw-800 text-muted small">Estimated Budget</div>
                                <div>₹ <?php echo number_format($request['estimated_budget'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quotations List -->
                    <div class="panel mb-4">
                        <div class="panel-header">
                            <h3 class="panel-title">Quotations Received</h3>
                        </div>
                        
                        <?php if (empty($quotations)): ?>
                            <p class="text-muted text-center py-3">No quotations found for this request.</p>
                        <?php else: ?>
                            <?php foreach ($quotations as $quote): 
                                $cardClass = '';
                                if ($quote['status'] === 'Finalized') $cardClass = 'finalized';
                                if (!empty($quote['is_recommended'])) $cardClass = 'recommended';
                            ?>
                                <div class="quotation-card <?php echo $cardClass; ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-4">
                                            <div class="fw-900"><?php echo htmlspecialchars($quote['quotation_no']); ?></div>
                                            <div class="small text-muted">
                                                From: <?php echo htmlspecialchars($quote['dealer_name'] ?? 'Dealer'); ?>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="fw-900">₹ <?php echo number_format($quote['grand_total'], 2); ?></div>
                                            <?php if (!empty($quote['finalized_amount'])): ?>
                                                <div class="small text-success">
                                                    Finalized: ₹ <?php echo number_format($quote['finalized_amount'], 2); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-3">
                                            <span class="badge bg-info"><?php echo htmlspecialchars($quote['status']); ?></span>
                                            <?php if ($quote['status'] === 'Finalized'): ?>
                                                <span class="badge bg-success ms-1">QS Recommended</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-2 text-end">
                                            <a href="<?php echo htmlspecialchars($quote['quotation_document'] ?? '#'); ?>" target="_blank" class="btn-action">
                                                <i class="bi bi-file-pdf"></i> View
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Decision Form -->
                    <div class="panel">
                        <div class="panel-header">
                            <h3 class="panel-title">Make Decision</h3>
                        </div>
                        
                        <form action="process-quotation-decision.php" method="POST" class="row g-3">
                            <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-800">Decision</label>
                                <div class="d-flex gap-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="decision" id="approveRadio" value="approve" checked>
                                        <label class="form-check-label fw-700" for="approveRadio">
                                            <span class="text-success">Approve</span> - Accept the QS recommendation
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="decision" id="rejectRadio" value="reject">
                                        <label class="form-check-label fw-700" for="rejectRadio">
                                            <span class="text-danger">Reject</span> - Send back for revision
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-12" id="rejectionReasonField" style="display: none;">
                                <label class="form-label required fw-800">Reason for Rejection</label>
                                <textarea class="form-control" name="rejection_reason" rows="3" placeholder="Please explain why this quotation is being rejected..."></textarea>
                                <div class="form-text text-muted small">
                                    This reason will be visible to the QS team and project engineers.
                                </div>
                            </div>
                            
                            <div class="col-12 mt-4 d-flex justify-content-end gap-2">
                                <a href="pending-approvals.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary" id="submitBtn">Submit Decision</button>
                            </div>
                        </form>
                    </div>
                    
                </div>
            </div>
            
            <?php include 'includes/footer.php'; ?>
        </main>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="assets/js/sidebar-toggle.js"></script>
    
    <script>
        $(document).ready(function() {
            // Show/hide rejection reason field based on decision
            $('input[name="decision"]').change(function() {
                if ($(this).val() === 'reject') {
                    $('#rejectionReasonField').slideDown();
                } else {
                    $('#rejectionReasonField').slideUp();
                }
            });
            
            // Form validation
            $('form').submit(function(e) {
                const decision = $('input[name="decision"]:checked').val();
                
                if (decision === 'reject') {
                    const reason = $('textarea[name="rejection_reason"]').val().trim();
                    if (reason === '') {
                        e.preventDefault();
                        alert('Please provide a reason for rejection');
                        $('textarea[name="rejection_reason"]').focus();
                    }
                }
            });
        });
    </script>
</body>
</html>