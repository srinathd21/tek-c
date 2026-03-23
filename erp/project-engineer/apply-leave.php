<?php
// apply-leave.php
// Enhanced version with better UI, validation, and calendar functionality
// ✅ Updated to match my-leave-history.php design system

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) { die("Database connection failed."); }

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId    = (int)$_SESSION['employee_id'];
$designation   = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$employeeName  = (string)($_SESSION['employee_name'] ?? '');

// ---------------- CONFIG ----------------
$EXCLUDE_SUNDAYS = true;   // set false if Sundays allowed
$BLOCK_PAST_DAYS = true;   // set false if past dates allowed
$MAX_LEAVE_DAYS = 30;      // maximum consecutive leave days

// ---------------- HELPERS ----------------
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ymd($v){
  $v = trim((string)$v);
  if ($v === '') return '';
  $dt = DateTime::createFromFormat('Y-m-d', $v);
  return ($dt && $dt->format('Y-m-d') === $v) ? $v : '';
}

function dateListInclusive(string $startYmd, string $endYmd): array {
  $out = [];
  $s = DateTime::createFromFormat('Y-m-d', $startYmd);
  $e = DateTime::createFromFormat('Y-m-d', $endYmd);
  if (!$s || !$e) return $out;

  if ($e < $s) { $tmp = $s; $s = $e; $e = $tmp; }

  $cur = clone $s;
  while ($cur <= $e) {
    $out[] = $cur->format('Y-m-d');
    $cur->modify('+1 day');
  }
  return $out;
}

function isSunday(string $ymd): bool {
  $ts = strtotime($ymd);
  return $ts ? (date('w', $ts) == '0') : false;
}

function isHoliday(string $ymd): bool {
  // You can add holiday checking logic here
  return false;
}

function safeDate($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateTime($v, $dash='—'){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00 00:00:00') return $dash;
  $ts = strtotime($v);
  return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function statusMeta($status){
  $s = strtolower(trim((string)$status));
  if ($s === 'pending')  return ['Pending',  'status-yellow', 'bi-hourglass-split'];
  if ($s === 'approved') return ['Approved', 'status-green',  'bi-check2-circle'];
  if ($s === 'rejected') return ['Rejected', 'status-red',    'bi-x-circle'];
  return [($status ?: '—'), 'status-gray', 'bi-info-circle'];
}

// ---------------- EMPLOYEE INFO ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, designation, department FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}
$preparedBy = $empRow['full_name'] ?? $employeeName;
$department = $empRow['department'] ?? '';

// ---------------- GET LEAVE BALANCE ----------------
$leaveBalance = ['CL' => 12, 'SL' => 12, 'EL' => 15, 'LOP' => 0, 'OD' => 0, 'WFH' => 5];
$st = mysqli_prepare($conn, "SELECT leave_type, SUM(total_days) as used 
                               FROM leave_requests 
                               WHERE employee_id = ? AND status = 'Approved' 
                               AND YEAR(created_at) = YEAR(CURDATE())
                               GROUP BY leave_type");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  while ($row = mysqli_fetch_assoc($res)) {
    $type = $row['leave_type'];
    if (isset($leaveBalance[$type])) {
      $leaveBalance[$type] = max(0, $leaveBalance[$type] - (float)$row['used']);
    }
  }
  mysqli_stmt_close($st);
}

// ---------------- STATE ----------------
$success = '';
$error = '';

$leaveType = 'CL';
$reason = '';
$contactDuringLeave = '';
$handoverTo = '';
$fromDate = '';
$toDate = '';
$selectedDates = [];
$halfDayMap = [];

// Calendar month view
$today = new DateTime();
$viewMonth = (int)($_GET['m'] ?? (int)$today->format('n'));
$viewYear  = (int)($_GET['y'] ?? (int)$today->format('Y'));
if ($viewMonth < 1 || $viewMonth > 12) $viewMonth = (int)$today->format('n');
if ($viewYear < 2020 || $viewYear > 2100) $viewYear = (int)$today->format('Y');

// ---------------- HANDLE SUBMIT ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {

  $leaveType = trim((string)($_POST['leave_type'] ?? 'CL'));
  $reason = trim((string)($_POST['reason'] ?? ''));
  $contactDuringLeave = trim((string)($_POST['contact_during_leave'] ?? ''));
  $handoverTo = trim((string)($_POST['handover_to'] ?? ''));
  $fromDate = ymd($_POST['from_date'] ?? '');
  $toDate   = ymd($_POST['to_date'] ?? '');

  $selectedDatesRaw = $_POST['selected_dates'] ?? '[]';
  $decoded = json_decode((string)$selectedDatesRaw, true);
  if (!is_array($decoded)) $decoded = [];

  $selectedDates = [];
  foreach ($decoded as $d) {
    $d2 = ymd($d);
    if ($d2 !== '') $selectedDates[] = $d2;
  }
  $selectedDates = array_values(array_unique($selectedDates));
  sort($selectedDates);

  // Half day map
  $halfDayMapRaw = $_POST['half_day_map'] ?? '{}';
  $decodedHalf = json_decode((string)$halfDayMapRaw, true);
  if (!is_array($decodedHalf)) $decodedHalf = [];
  $halfDayMap = [];
  foreach ($decodedHalf as $k => $v) {
    $k2 = ymd($k);
    $v2 = strtoupper(trim((string)$v));
    if ($k2 !== '' && in_array($v2, ['FH','SH'], true)) {
      $halfDayMap[$k2] = $v2;
    }
  }

  // Validation
  $allowedLeaveTypes = ['CL','SL','EL','LOP','OD','WFH'];
  if (!in_array($leaveType, $allowedLeaveTypes, true)) {
    $error = "Invalid leave type selected.";
  }

  if ($error === '' && $reason === '') {
    $error = "Please enter reason for leave.";
  }

  if ($error === '' && empty($selectedDates)) {
    $error = "Please select at least one leave date on the calendar.";
  }

  // Check leave balance
  if ($error === '') {
    $totalRequested = 0;
    foreach ($selectedDates as $d) {
      $totalRequested += isset($halfDayMap[$d]) ? 0.5 : 1.0;
    }
    
    if ($leaveType !== 'LOP' && $leaveType !== 'OD' && $leaveType !== 'WFH') {
      $available = $leaveBalance[$leaveType] ?? 0;
      if ($totalRequested > $available) {
        $error = "Insufficient leave balance. Available: {$available} days, Requested: {$totalRequested} days";
      }
    }
  }

  // Derive from/to from selected dates
  if ($error === '') {
    $fromDate = $selectedDates[0];
    $toDate   = $selectedDates[count($selectedDates)-1];
    
    // Check maximum consecutive days
    $consecutiveDays = count($selectedDates);
    if ($consecutiveDays > $MAX_LEAVE_DAYS) {
      $error = "Maximum {$MAX_LEAVE_DAYS} consecutive leave days allowed.";
    }
  }

  // Exclude Sundays (if enabled)
  if ($error === '' && $EXCLUDE_SUNDAYS) {
    $filtered = [];
    foreach ($selectedDates as $d) {
      if (!isSunday($d)) $filtered[] = $d;
      else unset($halfDayMap[$d]);
    }
    $selectedDates = array_values($filtered);

    if (empty($selectedDates)) {
      $error = "Selected dates contain only Sundays. Please select working days.";
    } else {
      $fromDate = $selectedDates[0];
      $toDate   = $selectedDates[count($selectedDates)-1];
    }
  }

  // Prevent overlap with pending/approved
  if ($error === '') {
    $st = mysqli_prepare($conn, "
      SELECT id, from_date, to_date, status
      FROM leave_requests
      WHERE employee_id = ?
        AND status IN ('Pending','Approved')
        AND NOT (to_date < ? OR from_date > ?)
      LIMIT 1
    ");
    if ($st) {
      mysqli_stmt_bind_param($st, "iss", $employeeId, $fromDate, $toDate);
      mysqli_stmt_execute($st);
      $res = mysqli_stmt_get_result($st);
      $dup = mysqli_fetch_assoc($res);
      mysqli_stmt_close($st);
      if ($dup) {
        $error = "You already have a {$dup['status']} leave request from {$dup['from_date']} to {$dup['to_date']} that overlaps with the selected date(s).";
      }
    }
  }

  // Total days calculation
  $totalDays = 0.0;
  if ($error === '') {
    foreach ($selectedDates as $d) {
      $totalDays += isset($halfDayMap[$d]) ? 0.5 : 1.0;
    }
    if ($totalDays <= 0) $error = "Invalid total leave days.";
  }

  // Save
  if ($error === '') {
    $payload = [];
    foreach ($selectedDates as $d) {
      $payload[] = [
        'date' => $d,
        'half_day' => $halfDayMap[$d] ?? null,
        'day_name' => date('l', strtotime($d))
      ];
    }
    $selectedDatesJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $status = 'Pending';
    $appliedAt = date('Y-m-d H:i:s');

    $ins = mysqli_prepare($conn, "
      INSERT INTO leave_requests
      (employee_id, leave_type, from_date, to_date, total_days, reason, contact_during_leave, handover_to, selected_dates_json, status, applied_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$ins) {
      $error = "DB Error: " . mysqli_error($conn);
    } else {
      mysqli_stmt_bind_param(
        $ins,
        "isssdssssss",
        $employeeId,
        $leaveType,
        $fromDate,
        $toDate,
        $totalDays,
        $reason,
        $contactDuringLeave,
        $handoverTo,
        $selectedDatesJson,
        $status,
        $appliedAt
      );

      if (!mysqli_stmt_execute($ins)) {
        $error = "Failed to apply leave: " . mysqli_stmt_error($ins);
      } else {
        mysqli_stmt_close($ins);
        header("Location: apply-leave.php?saved=1");
        exit;
      }
      mysqli_stmt_close($ins);
    }
  }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "✓ Leave applied successfully. Waiting for approval.";
}

// ---------------- RECENT LEAVE REQUESTS ----------------
$recentLeaves = [];
$st = mysqli_prepare($conn, "
  SELECT id, leave_type, from_date, to_date, total_days, status, applied_at, reason, created_at
  FROM leave_requests
  WHERE employee_id = ?
  ORDER BY id DESC
  LIMIT 5
");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $recentLeaves = mysqli_fetch_all($res, MYSQLI_ASSOC);
  mysqli_stmt_close($st);
}

// ---------------- CALENDAR PREP ----------------
$firstDay = DateTime::createFromFormat('Y-n-j', $viewYear . '-' . $viewMonth . '-1');
$daysInMonth = (int)$firstDay->format('t');
$startWeekday = (int)$firstDay->format('w');

$prev = clone $firstDay; $prev->modify('-1 month');
$next = clone $firstDay; $next->modify('+1 month');

$todayYmd = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Apply Leave - TEK-C</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:16px 12px 14px; background: #f9fafb; }
    .panel{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      box-shadow:0 10px 30px rgba(17,24,39,.05);
      padding:20px;
      margin-bottom:20px;
    }

    .title-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
    .h-title{ margin:0; font-weight:1000; color:#111827; line-height:1.1; font-size:28px; }
    .h-sub{ margin:4px 0 0; color:#6b7280; font-weight:800; font-size:13px; }

    .sec-head{
      display:flex; align-items:center; gap:10px;
      padding:12px 16px;
      border-radius:14px;
      background:#f9fafb;
      border:1px solid #eef2f7;
      margin-bottom:20px;
    }
    .sec-ic{
      width:38px; height:38px; border-radius:12px;
      display:grid; place-items:center;
      background: rgba(45,156,219,.12);
      color: var(--blue, #2d9cdb);
      flex:0 0 auto;
    }
    .sec-title{ margin:0; font-weight:1000; color:#111827; font-size:15px; }
    .sec-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:12px; }

    /* Stats cards matching history page */
    .stat-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:16px;
      box-shadow:0 10px 30px rgba(17,24,39,.05);
      padding:14px 16px;
      height:90px;
      display:flex;
      align-items:center;
      gap:12px;
      transition: all 0.2s;
    }
    .stat-card:hover{
      transform: translateY(-2px);
      box-shadow:0 15px 35px rgba(17,24,39,.1);
    }
    .stat-ic{
      width:48px; height:48px;
      border-radius:14px;
      display:grid; place-items:center;
      color:#fff; font-size:20px;
      flex:0 0 auto;
    }
    .stat-ic.blue{ background: #2d9cdb; }
    .stat-ic.green{ background: #10b981; }
    .stat-ic.yellow{ background: #f59e0b; }
    .stat-ic.red{ background: #ef4444; }
    .stat-ic.gray{ background: #64748b; }
    .stat-label{ color:#4b5563; font-weight:850; font-size:12px; letter-spacing:0.3px; }
    .stat-value{ font-size:24px; font-weight:1000; line-height:1; margin-top:4px; }

    /* Form Elements */
    .form-label {
      font-weight: 800;
      color: #4b5563;
      font-size: 12px;
      margin-bottom: 6px;
      letter-spacing: 0.3px;
      text-transform: uppercase;
    }
    .form-control, .form-select {
      border: 2px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 14px;
      font-size: 13px;
      font-weight: 800;
      transition: all 0.2s;
    }
    .form-control:focus, .form-select:focus {
      border-color: #2d9cdb;
      box-shadow: 0 0 0 3px rgba(45,156,219,0.1);
    }

    /* Calendar */
    .calendar-container {
      background: #fff;
      border-radius: 16px;
      overflow: hidden;
      border: 1px solid #e5e7eb;
    }
    .calendar-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 18px;
      background: #f9fafb;
      border-bottom: 1px solid #e5e7eb;
    }
    .calendar-title {
      font-weight: 1000;
      font-size: 15px;
      color: #111827;
      margin: 0;
    }
    .calendar-nav-btn {
      background: #fff;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      padding: 6px 14px;
      font-size: 12px;
      font-weight: 800;
      transition: all 0.2s;
    }
    .calendar-nav-btn:hover {
      background: #2d9cdb;
      color: #fff;
      border-color: #2d9cdb;
    }
    .calendar-grid {
      display: grid;
      grid-template-columns: repeat(7, 1fr);
    }
    .calendar-weekday {
      background: #f9fafb;
      padding: 12px 8px;
      text-align: center;
      font-weight: 900;
      font-size: 11px;
      color: #6b7280;
      border-bottom: 1px solid #e5e7eb;
    }
    .calendar-day {
      min-height: 80px;
      border-right: 1px solid #e5e7eb;
      border-bottom: 1px solid #e5e7eb;
      position: relative;
      transition: all 0.2s;
      cursor: pointer;
    }
    .calendar-day:hover {
      background: #f9fafb;
    }
    .calendar-day.disabled {
      background: #fafafc;
      opacity: 0.6;
      cursor: not-allowed;
    }
    .calendar-day.disabled .day-number {
      color: #cbd5e1;
    }
    .calendar-day.selected {
      background: rgba(45,156,219,0.08);
    }
    .calendar-day.range-start,
    .calendar-day.range-end {
      background: rgba(45,156,219,0.15);
      position: relative;
    }
    .calendar-day.range-start::before,
    .calendar-day.range-end::before {
      content: '';
      position: absolute;
      top: 0;
      bottom: 0;
      width: 3px;
      background: #2d9cdb;
    }
    .calendar-day.range-start::before {
      left: 0;
    }
    .calendar-day.range-end::before {
      right: 0;
    }
    .day-number {
      display: inline-block;
      padding: 6px 10px;
      font-weight: 800;
      font-size: 13px;
      color: #111827;
    }
    .calendar-day.today .day-number {
      background: #2d9cdb;
      color: #fff;
      border-radius: 8px;
    }
    .calendar-day.sunday .day-number {
      color: #ef4444;
    }
    .day-badge {
      position: absolute;
      bottom: 6px;
      right: 6px;
      font-size: 9px;
      padding: 2px 6px;
      border-radius: 12px;
      background: #f0f2f5;
      color: #6b7280;
      font-weight: 800;
    }

    /* Status Badge matching history page */
    .status-badge{
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 1000;
      letter-spacing: .3px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      white-space: nowrap;
      text-transform: uppercase;
      border:1px solid transparent;
    }
    .status-green{ background: rgba(16,185,129,.12); color:#10b981; border-color: rgba(16,185,129,.22); }
    .status-yellow{ background: rgba(245,158,11,.12); color:#f59e0b; border-color: rgba(245,158,11,.22); }
    .status-red{ background: rgba(239,68,68,.12); color:#ef4444; border-color: rgba(239,68,68,.22); }
    .status-gray{ background: rgba(100,116,139,.12); color:#64748b; border-color: rgba(100,116,139,.22); }

    /* Selected Dates List */
    .selected-dates-list {
      max-height: 350px;
      overflow-y: auto;
      border: 2px solid #e5e7eb;
      border-radius: 12px;
      background: #fff;
    }
    .selected-date-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 16px;
      border-bottom: 1px solid #e5e7eb;
      transition: all 0.2s;
    }
    .selected-date-item:last-child {
      border-bottom: none;
    }
    .selected-date-item:hover {
      background: #f9fafb;
    }
    .date-info {
      flex: 1;
    }
    .date {
      font-weight: 1000;
      color: #111827;
      margin-bottom: 4px;
      font-size: 13px;
    }
    .day-name {
      font-size: 11px;
      color: #6b7280;
      font-weight: 800;
    }
    .half-day-select {
      width: 130px;
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      padding: 6px 10px;
      font-size: 12px;
      font-weight: 800;
    }

    /* Buttons matching history page */
    .btn-primary-custom {
      background: #2d9cdb;
      border: none;
      border-radius: 12px;
      padding: 12px 24px;
      font-weight: 900;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
      color: #fff;
    }
    .btn-primary-custom:hover {
      background: #2a8bc9;
      transform: translateY(-2px);
    }
    .btn-outline-secondary-custom {
      border: 2px solid #e5e7eb;
      border-radius: 10px;
      padding: 8px 16px;
      font-weight: 800;
      font-size: 12px;
      transition: all 0.2s;
      background: #fff;
    }
    .btn-outline-secondary-custom:hover {
      background: #f9fafb;
      border-color: #2d9cdb;
    }

    /* Badge pill */
    .badge-pill {
      background: #f3f4f6;
      border: 1px solid #e5e7eb;
      border-radius: 999px;
      padding: 6px 12px;
      font-size: 12px;
      font-weight: 800;
      color: #4b5563;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .content-scroll { padding: 12px 10px !important; }
      .panel { padding: 16px !important; }
      .calendar-day { min-height: 60px; }
      .day-number { font-size: 11px; padding: 4px 8px; }
      .selected-date-item { flex-direction: column; gap: 8px; }
      .half-day-select { width: 100%; }
      .stat-card { height: auto; padding: 12px; }
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

        <!-- Page Header matching history page -->
        <div class="title-row">
          <div>
            <h1 class="h-title">Apply Leave</h1>
            <p class="h-sub">Submit a new leave request</p>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge-pill"><i class="bi bi-person"></i> <?php echo e($employeeName); ?></span>
            <a href="my-leave-history.php" class="badge-pill text-decoration-none" style="background: #f3f4f6;">
              <i class="bi bi-clock-history"></i> View History
            </a>
          </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
          <div class="alert alert-danger border-0 rounded-3 shadow-sm mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success border-0 rounded-3 shadow-sm mb-4" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Stats Cards matching history page style -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-calendar-check"></i></div>
              <div>
                <div class="stat-label">Available CL</div>
                <div class="stat-value"><?php echo e($leaveBalance['CL']); ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-heart-pulse"></i></div>
              <div>
                <div class="stat-label">Available SL</div>
                <div class="stat-value"><?php echo e($leaveBalance['SL']); ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic yellow"><i class="bi bi-star"></i></div>
              <div>
                <div class="stat-label">Available EL</div>
                <div class="stat-value"><?php echo e($leaveBalance['EL']); ?></div>
              </div>
            </div>
          </div>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic gray"><i class="bi bi-laptop"></i></div>
              <div>
                <div class="stat-label">Available WFH</div>
                <div class="stat-value"><?php echo e($leaveBalance['WFH']); ?></div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-4">
          <!-- Leave Form Column -->
          <div class="col-lg-8">
            <form method="POST" id="leaveForm" autocomplete="off">
              <input type="hidden" name="submit_leave" value="1">
              <input type="hidden" name="selected_dates" id="selected_dates_input" value="">
              <input type="hidden" name="half_day_map" id="half_day_map_input" value="">
              <input type="hidden" name="from_date" id="from_date_input" value="">
              <input type="hidden" name="to_date" id="to_date_input" value="">

              <!-- Leave Details Panel -->
              <div class="panel">
                <div class="sec-head">
                  <div class="sec-ic"><i class="bi bi-calendar-plus"></i></div>
                  <div>
                    <p class="sec-title mb-0">Leave Details</p>
                    <p class="sec-sub mb-0">Fill in the leave information</p>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                    <select class="form-select" name="leave_type" id="leave_type" required>
                      <option value="CL" <?php echo ($leaveType==='CL'?'selected':''); ?>>Casual Leave (CL) - <?php echo $leaveBalance['CL']; ?> days left</option>
                      <option value="SL" <?php echo ($leaveType==='SL'?'selected':''); ?>>Sick Leave (SL) - <?php echo $leaveBalance['SL']; ?> days left</option>
                      <option value="EL" <?php echo ($leaveType==='EL'?'selected':''); ?>>Earned Leave (EL) - <?php echo $leaveBalance['EL']; ?> days left</option>
                      <option value="LOP" <?php echo ($leaveType==='LOP'?'selected':''); ?>>Loss of Pay (LOP)</option>
                      <option value="OD" <?php echo ($leaveType==='OD'?'selected':''); ?>>On Duty (OD)</option>
                      <option value="WFH" <?php echo ($leaveType==='WFH'?'selected':''); ?>>Work From Home (WFH) - <?php echo $leaveBalance['WFH']; ?> days left</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Date Range</label>
                    <div class="row g-2">
                      <div class="col-6">
                        <input class="form-control" id="from_date_view" readonly placeholder="From Date" style="background:#f9fafb;">
                      </div>
                      <div class="col-6">
                        <input class="form-control" id="to_date_view" readonly placeholder="To Date" style="background:#f9fafb;">
                      </div>
                    </div>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="reason" rows="3" required placeholder="Enter detailed reason for leave"><?php echo e($reason); ?></textarea>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Contact During Leave</label>
                    <input class="form-control" name="contact_during_leave" value="<?php echo e($contactDuringLeave); ?>" placeholder="Mobile number / email">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Handover To</label>
                    <input class="form-control" name="handover_to" value="<?php echo e($handoverTo); ?>" placeholder="Employee name">
                  </div>
                </div>
              </div>

              <!-- Calendar Panel -->
              <div class="panel">
                <div class="sec-head">
                  <div class="sec-ic"><i class="bi bi-calendar3"></i></div>
                  <div>
                    <p class="sec-title mb-0">Select Dates</p>
                    <p class="sec-sub mb-0">Click on dates to select leave days</p>
                  </div>
                </div>

                <div class="calendar-container">
                  <div class="calendar-header">
                    <button type="button" class="calendar-nav-btn" onclick="changeMonth(-1)">
                      <i class="bi bi-chevron-left"></i> Prev
                    </button>
                    <h6 class="calendar-title" id="calendarTitle"><?php echo e($firstDay->format('F Y')); ?></h6>
                    <button type="button" class="calendar-nav-btn" onclick="changeMonth(1)">
                      Next <i class="bi bi-chevron-right"></i>
                    </button>
                  </div>
                  <div class="calendar-grid" id="calendarGrid">
                    <!-- Calendar will be populated by JavaScript -->
                  </div>
                </div>

                <div class="d-flex gap-3 mt-3 flex-wrap">
                  <div class="d-flex align-items-center gap-2">
                    <span style="width: 20px; height: 20px; background: rgba(45,156,219,0.08); border-radius: 4px;"></span>
                    <span class="small fw-bold">Selected</span>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span style="width: 20px; height: 20px; background: rgba(45,156,219,0.15); border-radius: 4px;"></span>
                    <span class="small fw-bold">Range Start/End</span>
                  </div>
                  <?php if ($EXCLUDE_SUNDAYS): ?>
                  <div class="d-flex align-items-center gap-2">
                    <span style="width: 20px; height: 20px; background: #fafafc; border: 1px solid #e5e7eb;"></span>
                    <span class="small text-danger fw-bold">Sunday (Disabled)</span>
                  </div>
                  <?php endif; ?>
                  <?php if ($BLOCK_PAST_DAYS): ?>
                  <div class="d-flex align-items-center gap-2">
                    <span style="width: 20px; height: 20px; background: #fafafc;"></span>
                    <span class="small text-muted fw-bold">Past Dates (Disabled)</span>
                  </div>
                  <?php endif; ?>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                  <div class="d-flex gap-3">
                    <span class="badge-pill">
                      <i class="bi bi-calendar-range me-1"></i> Selected: <strong id="selected_count">0</strong>
                    </span>
                    <span class="badge-pill">
                      <i class="bi bi-calculator me-1"></i> Total Days: <strong id="total_leave_days">0</strong>
                    </span>
                  </div>
                  <button type="button" class="btn-outline-secondary-custom" id="clearSelection">
                    <i class="bi bi-eraser"></i> Clear All
                  </button>
                </div>
              </div>

              <!-- Selected Dates Panel -->
              <div class="panel">
                <div class="sec-head">
                  <div class="sec-ic"><i class="bi bi-list-check"></i></div>
                  <div>
                    <p class="sec-title mb-0">Selected Dates & Half-Day Options</p>
                    <p class="sec-sub mb-0">Configure half-day for specific dates if needed</p>
                  </div>
                </div>

                <div id="selectedList" class="selected-dates-list">
                  <div class="text-center text-muted py-4">No dates selected. Click on calendar dates to select.</div>
                </div>

                <div class="mt-4">
                  <button type="submit" class="btn-primary-custom w-100">
                    <i class="bi bi-send-check"></i> Submit Leave Request
                  </button>
                </div>
              </div>
            </form>
          </div>

          <!-- Sidebar Column -->
          <div class="col-lg-4">
            <!-- Recent Requests Panel matching history page style -->
            <div class="panel">
              <div class="sec-head">
                <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                <div>
                  <p class="sec-title mb-0">Recent Requests</p>
                  <p class="sec-sub mb-0">Your latest leave applications</p>
                </div>
              </div>

              <?php if (empty($recentLeaves)): ?>
                <div class="text-center text-muted py-4" style="font-weight:800;">
                  <i class="bi bi-inbox" style="font-size: 32px; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                  No leave requests found.
                </div>
              <?php else: ?>
                <div class="d-grid gap-2">
                  <?php foreach ($recentLeaves as $r): ?>
                    <?php
                      [$stLabel, $stClass, $stIcon] = statusMeta($r['status'] ?? '');
                      $from = safeDate($r['from_date'] ?? '');
                      $to   = safeDate($r['to_date'] ?? '');
                    ?>
                    <div class="p-3 border rounded-3" style="background:#f9fafb;">
                      <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="fw-bold" style="font-size:13px;"><?php echo e($r['leave_type']); ?></span>
                        <span class="status-badge <?php echo $stClass; ?>">
                          <i class="bi <?php echo $stIcon; ?>"></i> <?php echo e($stLabel); ?>
                        </span>
                      </div>
                      <div class="small text-muted mb-1">
                        <i class="bi bi-calendar-range"></i> <?php echo e($from); ?> → <?php echo e($to); ?>
                      </div>
                      <div class="small text-muted">
                        <i class="bi bi-calculator"></i> <?php echo e($r['total_days']); ?> day(s)
                      </div>
                      <div class="small text-muted mt-1">
                        <i class="bi bi-clock"></i> <?php echo e(safeDate($r['applied_at'])); ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
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
// Calendar configuration
const EXCLUDE_SUNDAYS = <?php echo $EXCLUDE_SUNDAYS ? 'true' : 'false'; ?>;
const BLOCK_PAST_DAYS = <?php echo $BLOCK_PAST_DAYS ? 'true' : 'false'; ?>;
const TODAY = '<?php echo $todayYmd; ?>';

let currentMonth = <?php echo $viewMonth; ?>;
let currentYear = <?php echo $viewYear; ?>;
let rangeStart = null;
let rangeEnd = null;
let selectedDates = [];
let halfDayMap = {};

// DOM Elements
const calendarGrid = document.getElementById('calendarGrid');
const calendarTitle = document.getElementById('calendarTitle');
const selectedDatesInput = document.getElementById('selected_dates_input');
const halfDayMapInput = document.getElementById('half_day_map_input');
const fromDateInput = document.getElementById('from_date_input');
const toDateInput = document.getElementById('to_date_input');
const fromDateView = document.getElementById('from_date_view');
const toDateView = document.getElementById('to_date_view');
const selectedCountEl = document.getElementById('selected_count');
const totalLeaveDaysEl = document.getElementById('total_leave_days');
const selectedList = document.getElementById('selectedList');
const clearBtn = document.getElementById('clearSelection');

// Helper Functions
function formatYmd(date) {
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  const dd = String(date.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

function parseYmd(ymd) {
  const parts = ymd.split('-');
  if (parts.length !== 3) return null;
  return new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
}

function isSunday(ymd) {
  const date = parseYmd(ymd);
  return date ? date.getDay() === 0 : false;
}

function isPast(ymd) {
  return ymd < TODAY;
}

function isDisabled(ymd) {
  if (BLOCK_PAST_DAYS && isPast(ymd)) return true;
  if (EXCLUDE_SUNDAYS && isSunday(ymd)) return true;
  return false;
}

function getMonthDays(year, month) {
  const firstDay = new Date(year, month - 1, 1);
  const lastDay = new Date(year, month, 0);
  const daysInMonth = lastDay.getDate();
  const startWeekday = firstDay.getDay();
  
  return { daysInMonth, startWeekday };
}

function calcTotalLeaveDays() {
  let total = 0;
  selectedDates.forEach(d => {
    total += halfDayMap[d] ? 0.5 : 1;
  });
  return total;
}

function updateCounters() {
  selectedCountEl.textContent = selectedDates.length;
  totalLeaveDaysEl.textContent = calcTotalLeaveDays().toFixed(1);
}

function updateHiddenInputs() {
  selectedDatesInput.value = JSON.stringify(selectedDates);
  halfDayMapInput.value = JSON.stringify(halfDayMap);
  
  const from = selectedDates.length ? selectedDates[0] : '';
  const to = selectedDates.length ? selectedDates[selectedDates.length - 1] : '';
  
  fromDateInput.value = from;
  toDateInput.value = to;
  if (fromDateView) fromDateView.value = from ? formatDisplayDate(from) : '';
  if (toDateView) toDateView.value = to ? formatDisplayDate(to) : '';
}

function formatDisplayDate(ymd) {
  const date = parseYmd(ymd);
  return date ? date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
}

function renderSelectedList() {
  if (!selectedList) return;
  
  if (selectedDates.length === 0) {
    selectedList.innerHTML = '<div class="text-center text-muted py-4">No dates selected. Click on calendar dates to select.</div>';
    return;
  }
  
  let html = '';
  selectedDates.forEach(date => {
    const half = halfDayMap[date] || '';
    const dayName = new Date(date).toLocaleDateString('en-US', { weekday: 'long' });
    
    html += `
      <div class="selected-date-item">
        <div class="date-info">
          <div class="date">${formatDisplayDate(date)}</div>
          <div class="day-name">${dayName}</div>
        </div>
        <select class="half-day-select" data-date="${date}">
          <option value="" ${half === '' ? 'selected' : ''}>Full Day</option>
          <option value="FH" ${half === 'FH' ? 'selected' : ''}>First Half (AM)</option>
          <option value="SH" ${half === 'SH' ? 'selected' : ''}>Second Half (PM)</option>
        </select>
      </div>
    `;
  });
  
  selectedList.innerHTML = html;
  
  // Add event listeners to half-day selects
  document.querySelectorAll('.half-day-select').forEach(select => {
    select.addEventListener('change', function() {
      const date = this.dataset.date;
      const value = this.value;
      if (value) {
        halfDayMap[date] = value;
      } else {
        delete halfDayMap[date];
      }
      updateHiddenInputs();
      updateCounters();
      renderSelectedList();
      renderCalendar();
    });
  });
}

function applyRange(start, end) {
  if (!start || !end) return;
  
  const startDate = parseYmd(start);
  const endDate = parseYmd(end);
  if (!startDate || !endDate) return;
  
  const dates = [];
  const current = new Date(startDate);
  while (current <= endDate) {
    const ymd = formatYmd(current);
    if (!isDisabled(ymd)) {
      dates.push(ymd);
    }
    current.setDate(current.getDate() + 1);
  }
  
  selectedDates = dates.sort();
  
  // Clean up half-day map
  Object.keys(halfDayMap).forEach(key => {
    if (!selectedDates.includes(key)) delete halfDayMap[key];
  });
  
  rangeStart = selectedDates[0] || null;
  rangeEnd = selectedDates[selectedDates.length - 1] || null;
  
  updateHiddenInputs();
  updateCounters();
  renderSelectedList();
  renderCalendar();
}

function resetSelection() {
  rangeStart = null;
  rangeEnd = null;
  selectedDates = [];
  halfDayMap = {};
  updateHiddenInputs();
  updateCounters();
  renderSelectedList();
  renderCalendar();
}

function renderCalendar() {
  const { daysInMonth, startWeekday } = getMonthDays(currentYear, currentMonth);
  const firstDay = new Date(currentYear, currentMonth - 1, 1);
  calendarTitle.textContent = firstDay.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  
  const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  let html = weekdays.map(day => `<div class="calendar-weekday">${day}</div>`).join('');
  
  // Empty cells for days before month start
  for (let i = 0; i < startWeekday; i++) {
    html += `<div class="calendar-day disabled"></div>`;
  }
  
  // Days of the month
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(currentYear, currentMonth - 1, day);
    const ymd = formatYmd(date);
    const isToday = ymd === TODAY;
    const disabled = isDisabled(ymd);
    const isSelected = selectedDates.includes(ymd);
    const isStart = ymd === rangeStart;
    const isEnd = ymd === rangeEnd;
    const isSundayCheck = date.getDay() === 0;
    const halfDay = halfDayMap[ymd];
    
    let classes = ['calendar-day'];
    if (disabled) classes.push('disabled');
    if (isSelected) classes.push('selected');
    if (isStart) classes.push('range-start');
    if (isEnd) classes.push('range-end');
    if (isToday) classes.push('today');
    if (isSundayCheck) classes.push('sunday');
    
    let badge = '';
    if (halfDay === 'FH') badge = '<span class="day-badge">FH</span>';
    if (halfDay === 'SH') badge = '<span class="day-badge">SH</span>';
    if (disabled && EXCLUDE_SUNDAYS && isSundayCheck) badge = '<span class="day-badge">Sun</span>';
    if (disabled && BLOCK_PAST_DAYS && isPast(ymd)) badge = '<span class="day-badge">Past</span>';
    
    html += `
      <div class="${classes.join(' ')}" data-date="${ymd}" data-disabled="${disabled}">
        <div class="day-number">${day}</div>
        ${badge}
      </div>
    `;
  }
  
  calendarGrid.innerHTML = html;
  
  // Add click handlers
  document.querySelectorAll('.calendar-day:not(.disabled)').forEach(day => {
    day.addEventListener('click', function(e) {
      const date = this.dataset.date;
      if (!date || this.dataset.disabled === 'true') return;
      
      if (!rangeStart || (rangeStart && rangeEnd)) {
        // Start new selection
        rangeStart = date;
        rangeEnd = null;
        selectedDates = [date];
        if (EXCLUDE_SUNDAYS && isSunday(date)) selectedDates = [];
        updateHiddenInputs();
        updateCounters();
        renderSelectedList();
        renderCalendar();
      } else if (rangeStart && !rangeEnd) {
        // Complete the range
        rangeEnd = date;
        applyRange(rangeStart, rangeEnd);
      }
    });
  });
}

function changeMonth(delta) {
  let newMonth = currentMonth + delta;
  let newYear = currentYear;
  
  if (newMonth < 1) {
    newMonth = 12;
    newYear--;
  } else if (newMonth > 12) {
    newMonth = 1;
    newYear++;
  }
  
  currentMonth = newMonth;
  currentYear = newYear;
  
  // Update URL without reload
  const url = new URL(window.location.href);
  url.searchParams.set('m', currentMonth);
  url.searchParams.set('y', currentYear);
  window.history.pushState({}, '', url);
  
  renderCalendar();
}

// Add animation to stat cards on load
document.addEventListener('DOMContentLoaded', function() {
  renderCalendar();
  
  const statCards = document.querySelectorAll('.stat-card');
  statCards.forEach((card, index) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    setTimeout(() => {
      card.style.transition = 'all 0.3s ease';
      card.style.opacity = '1';
      card.style.transform = 'translateY(0)';
    }, index * 100);
  });
  
  clearBtn?.addEventListener('click', resetSelection);
  
  // Form validation
  document.getElementById('leaveForm')?.addEventListener('submit', function(e) {
    if (selectedDates.length === 0) {
      e.preventDefault();
      alert('Please select at least one date from the calendar.');
      return false;
    }
    
    const reason = document.querySelector('textarea[name="reason"]').value.trim();
    if (!reason) {
      e.preventDefault();
      alert('Please enter a reason for leave.');
      return false;
    }
  });
});
</script>
</body>
</html>

<?php
try {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) { }
?>