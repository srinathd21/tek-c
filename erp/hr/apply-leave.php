<?php
// hr/apply-leave.php - Apply for Leave
session_start();
require_once 'includes/db-config.php';
require_once 'includes/activity-logger.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$current_employee_id = $_SESSION['employee_id'];

// Get employee details
$emp_stmt = mysqli_prepare($conn, "SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
mysqli_stmt_bind_param($emp_stmt, "i", $current_employee_id);
mysqli_stmt_execute($emp_stmt);
$emp_res = mysqli_stmt_get_result($emp_stmt);
$employee = mysqli_fetch_assoc($emp_res);
mysqli_stmt_close($emp_stmt);

if (!$employee) {
    die("Employee not found.");
}

// Get reporting manager details
$reporting_manager = null;
if (!empty($employee['reporting_to'])) {
    $manager_stmt = mysqli_prepare($conn, "SELECT id, full_name, email, mobile_number, designation FROM employees WHERE id = ?");
    mysqli_stmt_bind_param($manager_stmt, "i", $employee['reporting_to']);
    mysqli_stmt_execute($manager_stmt);
    $manager_res = mysqli_stmt_get_result($manager_stmt);
    $reporting_manager = mysqli_fetch_assoc($manager_res);
    mysqli_stmt_close($manager_stmt);
}

// Get leave balance (you may have a separate table for this)
// For now, we'll calculate from leave_requests table
$leave_balance_query = "
    SELECT 
        SUM(CASE WHEN leave_type = 'CL' AND status = 'Approved' THEN total_days ELSE 0 END) as cl_taken,
        SUM(CASE WHEN leave_type = 'PL' AND status = 'Approved' THEN total_days ELSE 0 END) as pl_taken,
        SUM(CASE WHEN leave_type = 'SL' AND status = 'Approved' THEN total_days ELSE 0 END) as sl_taken
    FROM leave_requests 
    WHERE employee_id = ? AND YEAR(from_date) = YEAR(CURDATE())
";
$balance_stmt = mysqli_prepare($conn, $leave_balance_query);
mysqli_stmt_bind_param($balance_stmt, "i", $current_employee_id);
mysqli_stmt_execute($balance_stmt);
$balance_res = mysqli_stmt_get_result($balance_stmt);
$leave_taken = mysqli_fetch_assoc($balance_res);
mysqli_stmt_close($balance_stmt);

// Default leave quotas (adjust as per company policy)
$leave_quotas = [
    'CL' => ['name' => 'Casual Leave', 'quota' => 12, 'taken' => (float)($leave_taken['cl_taken'] ?? 0), 'color' => 'success'],
    'PL' => ['name' => 'Privilege Leave', 'quota' => 15, 'taken' => (float)($leave_taken['pl_taken'] ?? 0), 'color' => 'primary'],
    'SL' => ['name' => 'Sick Leave', 'quota' => 10, 'taken' => (float)($leave_taken['sl_taken'] ?? 0), 'color' => 'warning'],
    'LWP' => ['name' => 'Leave Without Pay', 'quota' => 0, 'taken' => 0, 'color' => 'secondary']
];

foreach ($leave_quotas as $type => &$quota) {
    $quota['balance'] = max(0, $quota['quota'] - $quota['taken']);
    $quota['percentage'] = $quota['quota'] > 0 ? round(($quota['taken'] / $quota['quota']) * 100) : 0;
}

// Get pending leave requests
$pending_stmt = mysqli_prepare($conn, "
    SELECT * FROM leave_requests 
    WHERE employee_id = ? AND status = 'Pending' 
    ORDER BY from_date ASC
");
mysqli_stmt_bind_param($pending_stmt, "i", $current_employee_id);
mysqli_stmt_execute($pending_stmt);
$pending_res = mysqli_stmt_get_result($pending_stmt);
$pending_leaves = [];
while ($row = mysqli_fetch_assoc($pending_res)) {
    $pending_leaves[] = $row;
}
mysqli_stmt_close($pending_stmt);

// Get holidays for the current year
$holidays = [];
$holiday_stmt = mysqli_prepare($conn, "
    SELECT * FROM holidays 
    WHERE YEAR(holiday_date) = YEAR(CURDATE()) 
    ORDER BY holiday_date
");
mysqli_stmt_execute($holiday_stmt);
$holiday_res = mysqli_stmt_get_result($holiday_stmt);
while ($row = mysqli_fetch_assoc($holiday_res)) {
    $holidays[] = $row;
}
mysqli_stmt_close($holiday_stmt);

// ---------------- HANDLE FORM SUBMISSION ----------------
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type = $_POST['leave_type'] ?? '';
    $from_date = $_POST['from_date'] ?? '';
    $to_date = $_POST['to_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $contact_during_leave = trim($_POST['contact_during_leave'] ?? '');
    $handover_to = trim($_POST['handover_to'] ?? '');
    $half_day_dates = $_POST['half_day'] ?? [];

    // Validation
    if (empty($leave_type)) {
        $errors[] = "Please select leave type.";
    }

    if (empty($from_date)) {
        $errors[] = "From date is required.";
    }

    if (empty($to_date)) {
        $errors[] = "To date is required.";
    }

    if (empty($reason)) {
        $errors[] = "Reason for leave is required.";
    }

    // Date validation
    if (!empty($from_date) && !empty($to_date)) {
        $from_ts = strtotime($from_date);
        $to_ts = strtotime($to_date);
        $today_ts = strtotime(date('Y-m-d'));

        if ($from_ts < $today_ts) {
            $errors[] = "From date cannot be in the past.";
        }

        if ($to_ts < $from_ts) {
            $errors[] = "To date must be on or after from date.";
        }
    }

    // Calculate total days
    $total_days = 0;
    $selected_dates = [];

    if (empty($errors) && !empty($from_date) && !empty($to_date)) {
        $current = strtotime($from_date);
        $end = strtotime($to_date);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $day_of_week = date('w', $current); // 0 = Sunday, 6 = Saturday
            
            // Skip Sundays (assuming 6-day work week)
            // Modify this based on your company's working days
            if ($day_of_week != 0) {
                $is_half_day = in_array($date, $half_day_dates);
                $selected_dates[] = [
                    'date' => $date,
                    'half_day' => $is_half_day ? 'HD' : null
                ];
                $total_days += $is_half_day ? 0.5 : 1;
            }
            
            $current = strtotime('+1 day', $current);
        }
    }

    // Check leave balance
    if (!empty($leave_type) && isset($leave_quotas[$leave_type])) {
        $quota = $leave_quotas[$leave_type];
        if ($quota['quota'] > 0 && ($quota['taken'] + $total_days) > $quota['quota']) {
            $errors[] = "Insufficient leave balance. Available: {$quota['balance']} days, Requested: {$total_days} days.";
        }
    }

    // Check for overlapping leave requests
    if (empty($errors) && !empty($from_date) && !empty($to_date)) {
        $overlap_query = "
            SELECT * FROM leave_requests 
            WHERE employee_id = ? 
            AND status IN ('Pending', 'Approved')
            AND (
                (from_date BETWEEN ? AND ?) OR
                (to_date BETWEEN ? AND ?) OR
                (? BETWEEN from_date AND to_date) OR
                (? BETWEEN from_date AND to_date)
            )
        ";
        $overlap_stmt = mysqli_prepare($conn, $overlap_query);
        mysqli_stmt_bind_param($overlap_stmt, "issssss", 
            $current_employee_id, 
            $from_date, $to_date,
            $from_date, $to_date,
            $from_date, $to_date
        );
        mysqli_stmt_execute($overlap_stmt);
        $overlap_res = mysqli_stmt_get_result($overlap_stmt);
        
        if (mysqli_num_rows($overlap_res) > 0) {
            $errors[] = "You already have a pending or approved leave request for this period.";
        }
        mysqli_stmt_close($overlap_stmt);
    }

    // If no errors, insert leave request
    if (empty($errors)) {
        $request_no = 'LEAVE-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $insert_stmt = mysqli_prepare($conn, "
            INSERT INTO leave_requests 
            (employee_id, leave_type, from_date, to_date, total_days, reason, 
             contact_during_leave, handover_to, selected_dates_json, status, applied_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        
        $selected_dates_json = json_encode($selected_dates);
        
        mysqli_stmt_bind_param($insert_stmt, "isssdssss",
            $current_employee_id,
            $leave_type,
            $from_date,
            $to_date,
            $total_days,
            $reason,
            $contact_during_leave,
            $handover_to,
            $selected_dates_json
        );

        if (mysqli_stmt_execute($insert_stmt)) {
            $leave_id = mysqli_insert_id($conn);
            
            logActivity(
                $conn,
                'CREATE',
                'leave',
                "Applied for {$total_days} days {$leave_type} leave",
                $leave_id,
                null,
                null,
                json_encode([
                    'leave_type' => $leave_type,
                    'from_date' => $from_date,
                    'to_date' => $to_date,
                    'total_days' => $total_days
                ])
            );

            $_SESSION['flash_success'] = "Leave application submitted successfully. Your request is pending approval.";
            header("Location: my-leaves.php");
            exit;
        } else {
            $errors[] = "Failed to submit leave application: " . mysqli_error($conn);
        }
        mysqli_stmt_close($insert_stmt);
    }
}

// ---------------- HELPERS ----------------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function getStatusBadge($status) {
    switch($status) {
        case 'Approved':
            return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Approved</span>';
        case 'Rejected':
            return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rejected</span>';
        case 'Pending':
            return '<span class="badge bg-warning text-dark"><i class="bi bi-clock"></i> Pending</span>';
        case 'Cancelled':
            return '<span class="badge bg-secondary"><i class="bi bi-x"></i> Cancelled</span>';
        default:
            return '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    }
}

$loggedName = $_SESSION['employee_name'] ?? $employee['full_name'];
$current_date = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Apply for Leave - TEK-C</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px; }
        .panel{ background:#fff; border:1px solid #e5e7eb; border-radius:16px; box-shadow:0 8px 24px rgba(17,24,39,.06); padding:20px; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }

        .form-label{ font-weight:800; font-size:13px; color:#4b5563; margin-bottom:6px; }
        .form-control, .form-select{ border:1px solid #d1d5db; border-radius:10px; padding:10px 12px; font-weight:500; }
        .form-control:focus, .form-select:focus{ border-color: #3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }
        .form-control.is-invalid, .form-select.is-invalid{ border-color:#dc3545; }

        .leave-balance-card{ background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:16px; }
        .balance-item{ display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #e5e7eb; }
        .balance-item:last-child{ border-bottom:0; }
        .balance-label{ font-weight:700; color:#374151; }
        .balance-value{ font-weight:800; }
        .progress{ height:8px; border-radius:4px; }

        .info-card{ background:#eef2ff; border-radius:12px; padding:16px; margin-bottom:20px; }
        .info-icon{ width:40px; height:40px; border-radius:10px; background:#3b82f6; color:white; display:grid; place-items:center; font-size:20px; margin-bottom:12px; }

        .date-range{ background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin:16px 0; }
        .day-selector{ display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; }

        .pending-card{ background:#fff3e0; border-left:4px solid #ff9800; border-radius:8px; padding:12px; margin-bottom:10px; }
        
        .required::after{ content:" *"; color:#ef4444; font-weight:800; }

        .holiday-badge{ background:#e6f7ff; border:1px solid #91d5ff; color:#0050b3; padding:4px 10px; border-radius:20px; font-size:12px; display:inline-block; margin:2px; }

        @media (max-width: 768px) {
            .content-scroll{ padding:12px; }
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

                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h1 class="h3 fw-bold mb-1">Apply for Leave</h1>
                        <p class="text-muted mb-0">Submit a leave request for approval</p>
                    </div>
                    <div>
                        <a href="my-leaves.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Back to My Leaves
                        </a>
                    </div>
                </div>

                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Please fix the following errors:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Flash Messages -->
                <?php if (isset($_SESSION['flash_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                        <?= $_SESSION['flash_success']; unset($_SESSION['flash_success']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Main Form Column -->
                    <div class="col-lg-8">
                        <div class="panel">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-calendar-plus me-2"></i>Leave Application Form
                                </h5>
                            </div>

                            <form method="POST" action="" id="leaveForm">
                                <!-- Leave Type -->
                                <div class="mb-3">
                                    <label class="form-label required">Leave Type</label>
                                    <select name="leave_type" id="leave_type" class="form-select <?= in_array('leave_type', array_map(function($e) { return strpos($e, 'type') !== false; }, $errors)) ? 'is-invalid' : '' ?>" required>
                                        <option value="">Select Leave Type</option>
                                        <?php foreach ($leave_quotas as $code => $quota): ?>
                                            <option value="<?= $code ?>" <?= (isset($_POST['leave_type']) && $_POST['leave_type'] === $code) ? 'selected' : '' ?>
                                                    data-balance="<?= $quota['balance'] ?>">
                                                <?= e($quota['name']) ?> (<?= $quota['balance'] ?> days available)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Date Range -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label required">From Date</label>
                                        <input type="date" name="from_date" id="from_date" 
                                               class="form-control <?= in_array('from', array_map(function($e) { return strpos($e, 'from') !== false; }, $errors)) ? 'is-invalid' : '' ?>"
                                               value="<?= e($_POST['from_date'] ?? '') ?>" 
                                               min="<?= $current_date ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required">To Date</label>
                                        <input type="date" name="to_date" id="to_date" 
                                               class="form-control <?= in_array('to', array_map(function($e) { return strpos($e, 'to') !== false; }, $errors)) ? 'is-invalid' : '' ?>"
                                               value="<?= e($_POST['to_date'] ?? '') ?>" 
                                               min="<?= $current_date ?>" required>
                                    </div>
                                </div>

                                <!-- Half Day Selection (will be populated by JavaScript) -->
                                <div id="halfDayContainer" class="date-range" style="display:none;">
                                    <label class="form-label fw-bold">Select Half Days (if any)</label>
                                    <p class="text-muted small mb-2">Check the dates you want to mark as half day</p>
                                    <div id="halfDayList" class="day-selector"></div>
                                </div>

                                <!-- Total Days Display -->
                                <div class="alert alert-info mb-3" id="totalDaysDisplay" style="display:none;">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <span id="totalDaysText">Total: 0 days</span>
                                </div>

                                <!-- Reason -->
                                <div class="mb-3">
                                    <label class="form-label required">Reason for Leave</label>
                                    <textarea name="reason" id="reason" rows="3" 
                                              class="form-control <?= in_array('reason', array_map(function($e) { return strpos($e, 'reason') !== false; }, $errors)) ? 'is-invalid' : '' ?>"
                                              required><?= e($_POST['reason'] ?? '') ?></textarea>
                                </div>

                                <!-- Contact During Leave -->
                                <div class="mb-3">
                                    <label class="form-label">Contact Number During Leave</label>
                                    <input type="text" name="contact_during_leave" id="contact_during_leave" 
                                           class="form-control" 
                                           value="<?= e($_POST['contact_during_leave'] ?? $employee['mobile_number'] ?? '') ?>"
                                           placeholder="Mobile number where you can be reached">
                                </div>

                                <!-- Handover To -->
                                <div class="mb-3">
                                    <label class="form-label">Work Handover To</label>
                                    <input type="text" name="handover_to" id="handover_to" 
                                           class="form-control" 
                                           value="<?= e($_POST['handover_to'] ?? '') ?>"
                                           placeholder="Name of colleague handling your work">
                                </div>

                                <!-- Documents (optional - can be implemented later) -->
                                <div class="mb-3">
                                    <label class="form-label">Supporting Documents (Optional)</label>
                                    <input type="file" class="form-control" disabled>
                                    <small class="text-muted">File upload will be available soon</small>
                                </div>

                                <hr>

                                <!-- Submit Buttons -->
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary px-4 py-2">
                                        <i class="bi bi-send me-2"></i>Submit Application
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary px-4 py-2" onclick="window.location.href='my-leaves.php'">
                                        Cancel
                                    </button>
                                </div>

                                <!-- Terms -->
                                <p class="text-muted small mt-3 mb-0">
                                    <i class="bi bi-info-circle me-1"></i>
                                    By submitting this application, you confirm that the information provided is accurate 
                                    and you understand that leave is subject to approval by your reporting manager.
                                </p>
                            </form>
                        </div>
                    </div>

                    <!-- Sidebar Column -->
                    <div class="col-lg-4">
                        <!-- Employee Info Card -->
                        <div class="info-card">
                            <div class="info-icon">
                                <i class="bi bi-person-badge"></i>
                            </div>
                            <h6 class="fw-bold mb-2">Employee Information</h6>
                            <div class="mb-1"><strong>Name:</strong> <?= e($employee['full_name']) ?></div>
                            <div class="mb-1"><strong>Code:</strong> <?= e($employee['employee_code']) ?></div>
                            <div class="mb-1"><strong>Designation:</strong> <?= e($employee['designation'] ?? 'N/A') ?></div>
                            <div class="mb-1"><strong>Department:</strong> <?= e($employee['department'] ?? 'N/A') ?></div>
                            <?php if ($reporting_manager): ?>
                                <div class="mt-2 pt-2 border-top">
                                    <strong>Reporting To:</strong><br>
                                    <?= e($reporting_manager['full_name']) ?><br>
                                    <small><?= e($reporting_manager['designation'] ?? 'Manager') ?></small>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Leave Balance Card -->
                        <div class="panel mb-4">
                            <div class="panel-header">
                                <h5 class="panel-title">
                                    <i class="bi bi-pie-chart me-2"></i>Leave Balance
                                </h5>
                            </div>
                            <div class="leave-balance-card">
                                <?php foreach ($leave_quotas as $code => $quota): ?>
                                    <?php if ($quota['quota'] > 0): ?>
                                        <div class="balance-item">
                                            <div>
                                                <span class="balance-label"><?= e($quota['name']) ?> (<?= $code ?>)</span>
                                                <div class="progress mt-1">
                                                    <div class="progress-bar bg-<?= $quota['color'] ?>" 
                                                         style="width: <?= $quota['percentage'] ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="balance-value">
                                                <?= number_format($quota['balance'], 1) ?>/<?= $quota['quota'] ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="balance-item">
                                            <span class="balance-label"><?= e($quota['name']) ?> (<?= $code ?>)</span>
                                            <span class="balance-value text-muted">Unlimited</span>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Pending Leave Requests -->
                        <?php if (!empty($pending_leaves)): ?>
                            <div class="panel mb-4">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-clock-history me-2"></i>Pending Requests
                                    </h5>
                                </div>
                                <?php foreach ($pending_leaves as $pending): ?>
                                    <div class="pending-card">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <span class="badge bg-warning text-dark mb-1">Pending</span>
                                                <div class="fw-bold"><?= e($pending['leave_type']) ?> Leave</div>
                                                <small><?= date('d M', strtotime($pending['from_date'])) ?> - <?= date('d M', strtotime($pending['to_date'])) ?></small>
                                                <div><small><?= $pending['total_days'] ?> days</small></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Holidays -->
                        <?php if (!empty($holidays)): ?>
                            <div class="panel">
                                <div class="panel-header">
                                    <h5 class="panel-title">
                                        <i class="bi bi-calendar-heart me-2"></i>Upcoming Holidays
                                    </h5>
                                </div>
                                <div>
                                    <?php 
                                    $upcoming = array_filter($holidays, function($h) {
                                        return strtotime($h['holiday_date']) >= strtotime(date('Y-m-d'));
                                    });
                                    $upcoming = array_slice($upcoming, 0, 5);
                                    ?>
                                    <?php if (!empty($upcoming)): ?>
                                        <?php foreach ($upcoming as $holiday): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                                <div>
                                                    <span class="fw-bold"><?= e($holiday['holiday_name']) ?></span>
                                                    <br>
                                                    <small class="text-muted"><?= date('d M Y', strtotime($holiday['holiday_date'])) ?></small>
                                                </div>
                                                <span class="holiday-badge"><?= e($holiday['holiday_type']) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">No upcoming holidays</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/sidebar-toggle.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fromDate = document.getElementById('from_date');
    const toDate = document.getElementById('to_date');
    const leaveType = document.getElementById('leave_type');
    const halfDayContainer = document.getElementById('halfDayContainer');
    const halfDayList = document.getElementById('halfDayList');
    const totalDaysDisplay = document.getElementById('totalDaysDisplay');
    const totalDaysText = document.getElementById('totalDaysText');

    // Function to calculate days between two dates (excluding Sundays)
    function calculateWorkingDays(start, end) {
        const startDate = new Date(start);
        const endDate = new Date(end);
        const days = [];
        const halfDays = [];
        
        let currentDate = new Date(startDate);
        
        while (currentDate <= endDate) {
            const dayOfWeek = currentDate.getDay(); // 0 = Sunday
            // Exclude Sundays (you can modify this based on company policy)
            if (dayOfWeek !== 0) {
                days.push(new Date(currentDate));
            }
            currentDate.setDate(currentDate.getDate() + 1);
        }
        
        return days;
    }

    // Update half day selection and total days
    function updateDateRange() {
        if (!fromDate.value || !toDate.value) {
            halfDayContainer.style.display = 'none';
            totalDaysDisplay.style.display = 'none';
            return;
        }

        const start = new Date(fromDate.value);
        const end = new Date(toDate.value);
        
        if (end < start) {
            alert('To date cannot be before from date');
            toDate.value = fromDate.value;
            return;
        }

        const workingDays = calculateWorkingDays(fromDate.value, toDate.value);
        
        if (workingDays.length === 0) {
            halfDayContainer.style.display = 'none';
            totalDaysDisplay.style.display = 'none';
            return;
        }

        // Build half day checkboxes
        let html = '';
        workingDays.forEach(date => {
            const dateStr = date.toISOString().split('T')[0];
            const formattedDate = date.toLocaleDateString('en-US', { 
                weekday: 'short', 
                day: 'numeric', 
                month: 'short' 
            });
            
            const checked = (typeof halfDaySelected !== 'undefined' && halfDaySelected.includes(dateStr)) ? 'checked' : '';
            
            html += `
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="half_day[]" 
                           value="${dateStr}" id="half_${dateStr}" ${checked}>
                    <label class="form-check-label" for="half_${dateStr}">
                        ${formattedDate}
                    </label>
                </div>
            `;
        });

        halfDayList.innerHTML = html;
        halfDayContainer.style.display = 'block';

        // Calculate total days
        calculateTotalDays();
    }

    // Calculate total days (full days = 1, half days = 0.5)
    function calculateTotalDays() {
        const checkboxes = document.querySelectorAll('input[name="half_day[]"]');
        let total = 0;
        
        checkboxes.forEach(cb => {
            total += cb.checked ? 0.5 : 1;
        });

        totalDaysText.textContent = `Total: ${total.toFixed(1)} days`;
        totalDaysDisplay.style.display = 'block';

        // Check leave balance
        const selectedType = leaveType.options[leaveType.selectedIndex];
        if (selectedType && selectedType.value) {
            const balance = parseFloat(selectedType.dataset.balance || 0);
            if (total > balance) {
                totalDaysDisplay.className = 'alert alert-danger mb-3';
                totalDaysText.innerHTML += ` <i class="bi bi-exclamation-triangle"></i> Exceeds balance by ${(total - balance).toFixed(1)} days`;
            } else {
                totalDaysDisplay.className = 'alert alert-info mb-3';
            }
        }
    }

    // Event listeners
    fromDate.addEventListener('change', updateDateRange);
    toDate.addEventListener('change', updateDateRange);
    
    // Delegate half day checkbox changes
    halfDayList.addEventListener('change', function(e) {
        if (e.target.name === 'half_day[]') {
            calculateTotalDays();
        }
    });

    // Form validation
    document.getElementById('leaveForm').addEventListener('submit', function(e) {
        const selectedType = leaveType.value;
        if (!selectedType) {
            e.preventDefault();
            alert('Please select leave type');
            return;
        }

        if (!fromDate.value || !toDate.value) {
            e.preventDefault();
            alert('Please select date range');
            return;
        }

        const reason = document.getElementById('reason').value.trim();
        if (!reason) {
            e.preventDefault();
            alert('Please provide reason for leave');
            return;
        }

        // Check balance
        const selectedOption = leaveType.options[leaveType.selectedIndex];
        const balance = parseFloat(selectedOption.dataset.balance || 0);
        
        // Calculate total days
        let total = 0;
        document.querySelectorAll('input[name="half_day[]"]').forEach(cb => {
            total += cb.checked ? 0.5 : 1;
        });

        if (total > balance) {
            e.preventDefault();
            alert(`Insufficient leave balance. Required: ${total.toFixed(1)} days, Available: ${balance.toFixed(1)} days`);
            return;
        }
    });

    // Initialize if we have POST data
    <?php if (isset($_POST['from_date']) && isset($_POST['to_date'])): ?>
    setTimeout(updateDateRange, 100);
    <?php endif; ?>
});
</script>

</body>
</html>
<?php
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
?>