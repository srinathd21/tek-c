<?php
// apply-leave.php
// Updated UI using manager-projects/manage-page template
// Includes project TL notification and activity_logs entry for leave creation.

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
  die("Database connection failed.");
}

// ---------------- AUTH ----------------
if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId   = (int)$_SESSION['employee_id'];
$employeeName = (string)($_SESSION['employee_name'] ?? '');
$username     = (string)($_SESSION['username'] ?? '');

// ---------------- CONFIG ----------------
$EXCLUDE_SUNDAYS = true;
$BLOCK_PAST_DAYS = true;
$MAX_LEAVE_DAYS = 30;

// ---------------- HELPERS ----------------
function e($v){
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tableExists($conn, string $table): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $res = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
  if (!$res) return false;
  $ok = mysqli_num_rows($res) > 0;
  mysqli_free_result($res);
  return $ok;
}

function columnExists($conn, string $table, string $column): bool {
  $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
  $columnEsc = mysqli_real_escape_string($conn, $column);
  $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$columnEsc'");
  if (!$res) return false;
  $ok = mysqli_num_rows($res) > 0;
  mysqli_free_result($res);
  return $ok;
}

function logActivity($conn, $activity_type, $module, $description, $reference_id = null){
  $employee_id   = $_SESSION['employee_id'] ?? null;
  $employee_name = $_SESSION['employee_name'] ?? '';
  $username      = $_SESSION['username'] ?? '';
  $designation   = $_SESSION['designation'] ?? '';
  $department    = $_SESSION['department'] ?? '';
  $ip            = $_SERVER['REMOTE_ADDR'] ?? '';

  $stmt = mysqli_prepare(
    $conn,
    "INSERT INTO activity_logs
    (
      employee_id,
      employee_name,
      username,
      designation,
      department,
      activity_type,
      module,
      description,
      reference_id,
      ip_address
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
  );

  if ($stmt) {
    mysqli_stmt_bind_param(
      $stmt,
      "isssssssis",
      $employee_id,
      $employee_name,
      $username,
      $designation,
      $department,
      $activity_type,
      $module,
      $description,
      $reference_id,
      $ip
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
  }
}

function sendNotification(
  $conn,
  int $toEmployeeId,
  string $title,
  string $message,
  string $module = 'leave_requests',
  ?int $referenceId = null,
  string $link = ''
): bool {
  if ($toEmployeeId <= 0) return false;

  // Run the separate SQL file first. This function will not auto-create tables.
  if (!tableExists($conn, 'notifications')) return false;

  $cols = [];
  $vals = [];
  $types = '';

  $map = [
    'employee_id'  => ['i', $toEmployeeId],
    'title'        => ['s', $title],
    'message'      => ['s', $message],
    'type'         => ['s', 'leave'],
    'module'       => ['s', $module],
    'reference_id' => ['i', $referenceId],
    'link'         => ['s', $link],
    'is_read'      => ['i', 0]
  ];

  foreach ($map as $col => $pair) {
    if (columnExists($conn, 'notifications', $col)) {
      $cols[] = "`$col`";
      $types .= $pair[0];
      $vals[] = $pair[1];
    }
  }

  if (empty($cols)) return false;

  $placeholders = implode(',', array_fill(0, count($cols), '?'));
  $sql = "INSERT INTO notifications (" . implode(',', $cols) . ") VALUES ($placeholders)";
  $stmt = mysqli_prepare($conn, $sql);
  if (!$stmt) return false;

  mysqli_stmt_bind_param($stmt, $types, ...$vals);
  $ok = mysqli_stmt_execute($stmt);
  mysqli_stmt_close($stmt);

  return $ok;
}

function employeeRoleKey(array $empRow): string {
  $designation = strtolower(trim((string)($empRow['designation'] ?? '')));
  $department  = strtolower(trim((string)($empRow['department'] ?? '')));

  if (
    str_contains($designation, 'admin') ||
    str_contains($designation, 'administrator') ||
    str_contains($department, 'admin')
  ) {
    return 'admin';
  }

  if (
    str_contains($designation, 'hr') ||
    str_contains($designation, 'human resource') ||
    str_contains($department, 'hr') ||
    str_contains($department, 'human resource')
  ) {
    return 'hr';
  }

  if (
    str_contains($designation, 'manager') ||
    str_contains($designation, 'project manager')
  ) {
    return 'manager';
  }

  if (
    str_contains($designation, 'team lead') ||
    str_contains($designation, 'tl') ||
    str_contains($designation, 'lead')
  ) {
    return 'tl';
  }

  if (
    str_contains($designation, 'project engineer') ||
    str_contains($designation, 'engineer')
  ) {
    return 'project_engineer';
  }

  return 'employee';
}

function findAdminApprover($conn, int $excludeEmployeeId = 0): array {
  $sql = "
    SELECT id, full_name
    FROM employees
    WHERE id <> ?
      AND (
        LOWER(COALESCE(designation,'')) LIKE '%admin%'
        OR LOWER(COALESCE(department,'')) LIKE '%admin%'
        OR LOWER(COALESCE(username,'')) = 'admin'
      )
      AND (
        employee_status IS NULL
        OR LOWER(employee_status) = 'active'
      )
    ORDER BY
      CASE
        WHEN LOWER(COALESCE(username,'')) = 'admin' THEN 1
        WHEN LOWER(COALESCE(designation,'')) LIKE '%admin%' THEN 2
        ELSE 3
      END,
      id ASC
    LIMIT 1
  ";

  $stmt = mysqli_prepare($conn, $sql);
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $excludeEmployeeId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $admin = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if ($admin) {
      return [
        'id' => (int)$admin['id'],
        'name' => (string)($admin['full_name'] ?? ''),
        'source' => 'Admin'
      ];
    }
  }

  return ['id' => 0, 'name' => '', 'source' => ''];
}

function getProjectApprover($conn, int $siteId, int $employeeId, array $empRow): array {
  $roleKey = employeeRoleKey($empRow);

  // Manager / HR leave requests should go to Admin, regardless of selected project.
  if (in_array($roleKey, ['manager', 'hr'], true)) {
    $adminApprover = findAdminApprover($conn, $employeeId);
    if ((int)$adminApprover['id'] > 0) {
      return $adminApprover;
    }
  }

  if ($siteId <= 0) {
    if ($roleKey === 'tl') {
      $reportingTo = (int)($empRow['reporting_to'] ?? 0);
      if ($reportingTo > 0 && $reportingTo !== $employeeId) {
        return [
          'id' => $reportingTo,
          'name' => (string)($empRow['reporting_manager'] ?? ''),
          'source' => 'Reporting Manager'
        ];
      }
    }

    return findAdminApprover($conn, $employeeId);
  }

  $hasTeamLeadCol = columnExists($conn, 'sites', 'team_lead_employee_id');

  $teamLeadSelect = $hasTeamLeadCol
    ? "s.team_lead_employee_id"
    : "NULL AS team_lead_employee_id";

  $teamLeadJoin = $hasTeamLeadCol
    ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id"
    : "LEFT JOIN employees tl ON 1=0";

  $stmt = mysqli_prepare($conn, "
    SELECT
      s.id,
      s.project_name,
      s.manager_employee_id,
      $teamLeadSelect,
      tl.full_name AS team_lead_name,
      mgr.full_name AS manager_name,
      ptl.id AS fallback_team_lead_id,
      ptl.full_name AS fallback_team_lead_name
    FROM sites s
    $teamLeadJoin
    LEFT JOIN employees mgr ON mgr.id = s.manager_employee_id
    LEFT JOIN site_project_engineers spe_tl ON spe_tl.site_id = s.id
    LEFT JOIN employees ptl
      ON ptl.id = spe_tl.employee_id
     AND (
       LOWER(COALESCE(ptl.designation,'')) LIKE '%team lead%'
       OR LOWER(COALESCE(ptl.designation,'')) LIKE '%tl%'
       OR LOWER(COALESCE(ptl.designation,'')) LIKE '%lead%'
     )
    WHERE s.id = ?
    LIMIT 1
  ");

  if (!$stmt) return ['id' => 0, 'name' => '', 'source' => ''];

  mysqli_stmt_bind_param($stmt, "i", $siteId);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  $site = $res ? mysqli_fetch_assoc($res) : null;
  mysqli_stmt_close($stmt);

  if (!$site) return ['id' => 0, 'name' => '', 'source' => ''];

  $tlId = (int)($site['team_lead_employee_id'] ?? 0);
  $fallbackTlId = (int)($site['fallback_team_lead_id'] ?? 0);
  $managerId = (int)($site['manager_employee_id'] ?? 0);

  // Project Engineer / normal employee: notify Project TL first, then Manager, then Reporting Manager/Admin.
  if (in_array($roleKey, ['project_engineer', 'employee'], true)) {
    if ($tlId > 0 && $tlId !== $employeeId) {
      return [
        'id' => $tlId,
        'name' => (string)($site['team_lead_name'] ?? ''),
        'source' => 'Project TL'
      ];
    }

    if ($fallbackTlId > 0 && $fallbackTlId !== $employeeId) {
      return [
        'id' => $fallbackTlId,
        'name' => (string)($site['fallback_team_lead_name'] ?? ''),
        'source' => 'Project TL'
      ];
    }

    if ($managerId > 0 && $managerId !== $employeeId) {
      return [
        'id' => $managerId,
        'name' => (string)($site['manager_name'] ?? ''),
        'source' => 'Project Manager'
      ];
    }
  }

  // Team Lead: notify Project Manager first, then Reporting Manager, then Admin.
  if ($roleKey === 'tl') {
    if ($managerId > 0 && $managerId !== $employeeId) {
      return [
        'id' => $managerId,
        'name' => (string)($site['manager_name'] ?? ''),
        'source' => 'Project Manager'
      ];
    }

    $reportingTo = (int)($empRow['reporting_to'] ?? 0);
    if ($reportingTo > 0 && $reportingTo !== $employeeId) {
      return [
        'id' => $reportingTo,
        'name' => (string)($empRow['reporting_manager'] ?? ''),
        'source' => 'Reporting Manager'
      ];
    }
  }

  // Final fallback for any role.
  $reportingTo = (int)($empRow['reporting_to'] ?? 0);
  if ($reportingTo > 0 && $reportingTo !== $employeeId) {
    $name = (string)($empRow['reporting_manager'] ?? '');

    if ($name === '') {
      $q = mysqli_prepare($conn, "SELECT full_name FROM employees WHERE id=? LIMIT 1");
      if ($q) {
        mysqli_stmt_bind_param($q, "i", $reportingTo);
        mysqli_stmt_execute($q);
        $rr = mysqli_stmt_get_result($q);
        $r = $rr ? mysqli_fetch_assoc($rr) : null;
        mysqli_stmt_close($q);
        $name = (string)($r['full_name'] ?? '');
      }
    }

    return [
      'id' => $reportingTo,
      'name' => $name,
      'source' => 'Reporting Manager'
    ];
  }

  return findAdminApprover($conn, $employeeId);
}

function ymd($v){
  $v = trim((string)$v);
  if ($v === '') return '';
  $dt = DateTime::createFromFormat('Y-m-d', $v);
  return ($dt && $dt->format('Y-m-d') === $v) ? $v : '';
}

function isSunday(string $ymd): bool {
  $ts = strtotime($ymd);
  return $ts ? (date('w', $ts) == '0') : false;
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
  if ($s === 'pending')  return ['Pending',  'pending',     'bi-hourglass-split'];
  if ($s === 'approved') return ['Approved', 'ontrack',     'bi-check2-circle'];
  if ($s === 'rejected') return ['Rejected', 'atrisk',      'bi-x-circle'];
  return [($status ?: '—'), 'progressing', 'bi-info-circle'];
}

function projectStatusBadge($start, $end){
  $today = date('Y-m-d');

  $start = trim((string)$start);
  $end   = trim((string)$end);

  if ($end !== '' && $end !== '0000-00-00' && $end < $today) {
    return ['Completed', 'ontrack'];
  }

  if ($start !== '' && $start !== '0000-00-00' && $start > $today) {
    return ['Upcoming', 'pending'];
  }

  return ['Ongoing', 'progressing'];
}

// ---------------- EMPLOYEE INFO ----------------
$empRow = null;
$st = mysqli_prepare($conn, "SELECT id, full_name, email, designation, department, reporting_to, reporting_manager FROM employees WHERE id=? LIMIT 1");
if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);
  $empRow = mysqli_fetch_assoc($res);
  mysqli_stmt_close($st);
}

$preparedBy = $empRow['full_name'] ?? $employeeName;
$department = $empRow['department'] ?? '';
$currentRoleKey = employeeRoleKey($empRow ?: []);

// ---------------- PROJECTS UNDER THIS EMPLOYEE ----------------
$myProjects = [];
$selectedSiteId = 0;

$hasTeamLeadCol = columnExists($conn, 'sites', 'team_lead_employee_id');
$hasDeletedAtCol = columnExists($conn, 'sites', 'deleted_at');

$teamLeadSelectForMyProjects = $hasTeamLeadCol ? "s.team_lead_employee_id," : "NULL AS team_lead_employee_id,";
$teamLeadJoinForMyProjects = $hasTeamLeadCol ? "LEFT JOIN employees tl ON tl.id = s.team_lead_employee_id" : "LEFT JOIN employees tl ON 1=0";
$employeeProjectFilter = $hasTeamLeadCol
  ? "(s.manager_employee_id = ? OR s.team_lead_employee_id = ? OR spe.employee_id = ?)"
  : "(s.manager_employee_id = ? OR spe.employee_id = ?)";

$deletedFilter = $hasDeletedAtCol ? "s.deleted_at IS NULL AND" : "";

$projectSql = "
  SELECT DISTINCT
    s.id,
    s.project_name,
    s.project_location,
    s.project_type,
    s.start_date,
    s.expected_completion_date,
    s.manager_employee_id,
    $teamLeadSelectForMyProjects
    tl.full_name AS team_lead_name,
    ptl.full_name AS fallback_team_lead_name,
    mgr.full_name AS manager_name
  FROM sites s
  LEFT JOIN site_project_engineers spe ON spe.site_id = s.id
  LEFT JOIN employees mgr ON mgr.id = s.manager_employee_id
  $teamLeadJoinForMyProjects
  LEFT JOIN site_project_engineers spe_tl ON spe_tl.site_id = s.id
  LEFT JOIN employees ptl
    ON ptl.id = spe_tl.employee_id
   AND (
     LOWER(COALESCE(ptl.designation,'')) LIKE '%team lead%'
     OR LOWER(COALESCE(ptl.designation,'')) LIKE '%tl%'
     OR LOWER(COALESCE(ptl.designation,'')) LIKE '%lead%'
   )
  WHERE $deletedFilter
    $employeeProjectFilter
  ORDER BY s.project_name ASC
";

$projectStmt = mysqli_prepare($conn, $projectSql);
if ($projectStmt) {
  if ($hasTeamLeadCol) {
    mysqli_stmt_bind_param($projectStmt, "iii", $employeeId, $employeeId, $employeeId);
  } else {
    mysqli_stmt_bind_param($projectStmt, "ii", $employeeId, $employeeId);
  }
  mysqli_stmt_execute($projectStmt);
  $projectRes = mysqli_stmt_get_result($projectStmt);
  $myProjects = $projectRes ? mysqli_fetch_all($projectRes, MYSQLI_ASSOC) : [];
  mysqli_stmt_close($projectStmt);
}

// ---------------- LEAVE BALANCE ----------------
$leaveBalance = ['CL' => 12, 'SL' => 12, 'EL' => 15, 'LOP' => 0, 'OD' => 0, 'WFH' => 5];

$st = mysqli_prepare($conn, "
  SELECT leave_type, SUM(total_days) AS used
  FROM leave_requests
  WHERE employee_id = ?
    AND status = 'Approved'
    AND YEAR(created_at) = YEAR(CURDATE())
  GROUP BY leave_type
");

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

if ($viewMonth < 1 || $viewMonth > 12) {
  $viewMonth = (int)$today->format('n');
}

if ($viewYear < 2020 || $viewYear > 2100) {
  $viewYear = (int)$today->format('Y');
}

// ---------------- HANDLE SUBMIT ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {

  $leaveType = trim((string)($_POST['leave_type'] ?? 'CL'));
  $selectedSiteId = (int)($_POST['site_id'] ?? 0);
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

  $allowedLeaveTypes = ['CL','SL','EL','LOP','OD','WFH'];

  if (!in_array($leaveType, $allowedLeaveTypes, true)) {
    $error = "Invalid leave type selected.";
  }

  if ($error === '' && $selectedSiteId <= 0) {
    $error = "Please select the project/site for this leave request.";
  }

  if ($error === '') {
    $projectAllowed = false;
    foreach ($myProjects as $projectOption) {
      if ((int)$projectOption['id'] === $selectedSiteId) {
        $projectAllowed = true;
        break;
      }
    }

    if (!$projectAllowed) {
      $error = "Selected project is not assigned to you.";
    }
  }

  if ($error === '' && $reason === '') {
    $error = "Please enter reason for leave.";
  }

  if ($error === '' && empty($selectedDates)) {
    $error = "Please select at least one leave date on the calendar.";
  }

  if ($error === '') {
    $totalRequested = 0.0;
    foreach ($selectedDates as $d) {
      $totalRequested += isset($halfDayMap[$d]) ? 0.5 : 1.0;
    }

    if (!in_array($leaveType, ['LOP','OD','WFH'], true)) {
      $available = $leaveBalance[$leaveType] ?? 0;
      if ($totalRequested > $available) {
        $error = "Insufficient leave balance. Available: {$available} days, Requested: {$totalRequested} days.";
      }
    }
  }

  if ($error === '') {
    $fromDate = $selectedDates[0];
    $toDate = $selectedDates[count($selectedDates) - 1];

    if (count($selectedDates) > $MAX_LEAVE_DAYS) {
      $error = "Maximum {$MAX_LEAVE_DAYS} consecutive leave days allowed.";
    }
  }

  if ($error === '' && $EXCLUDE_SUNDAYS) {
    $filtered = [];

    foreach ($selectedDates as $d) {
      if (!isSunday($d)) {
        $filtered[] = $d;
      } else {
        unset($halfDayMap[$d]);
      }
    }

    $selectedDates = array_values($filtered);

    if (empty($selectedDates)) {
      $error = "Selected dates contain only Sundays. Please select working days.";
    } else {
      $fromDate = $selectedDates[0];
      $toDate = $selectedDates[count($selectedDates) - 1];
    }
  }

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

  $totalDays = 0.0;

  if ($error === '') {
    foreach ($selectedDates as $d) {
      $totalDays += isset($halfDayMap[$d]) ? 0.5 : 1.0;
    }

    if ($totalDays <= 0) {
      $error = "Invalid total leave days.";
    }
  }

  if ($error === '') {
    $payload = [];
    foreach ($selectedDates as $d) {
      $payload[] = [
        'date' => $d,
        'half_day' => $halfDayMap[$d] ?? null,
        'day_name' => date('l', strtotime($d))
      ];
    }

    $selectedDatesJson = json_encode([
      'site_id' => $selectedSiteId,
      'dates' => $payload
    ], JSON_UNESCAPED_UNICODE);

    $status = 'Pending';
    $appliedAt = date('Y-m-d H:i:s');

    $projectNameForLog = '';
    foreach ($myProjects as $projectOption) {
      if ((int)$projectOption['id'] === $selectedSiteId) {
        $projectNameForLog = (string)$projectOption['project_name'];
        break;
      }
    }

    $approver = getProjectApprover($conn, $selectedSiteId, $employeeId, $empRow ?: []);
    $approverId = (int)($approver['id'] ?? 0);

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
        $leaveRequestId = mysqli_insert_id($conn);

        if (columnExists($conn, 'leave_requests', 'site_id') || columnExists($conn, 'leave_requests', 'approver_id')) {
          $sets = [];
          if (columnExists($conn, 'leave_requests', 'site_id')) {
            $sets[] = "site_id = " . (int)$selectedSiteId;
          }
          if (columnExists($conn, 'leave_requests', 'approver_id')) {
            $sets[] = "approver_id = " . (int)$approverId;
          }

          if (!empty($sets)) {
            mysqli_query($conn, "UPDATE leave_requests SET " . implode(', ', $sets) . " WHERE id = " . (int)$leaveRequestId . " LIMIT 1");
          }
        }

        $activityDescription =
          "Applied leave request: {$leaveType} from {$fromDate} to {$toDate} ({$totalDays} day(s))";

        if ($projectNameForLog !== '') {
          $activityDescription .= " for project: " . $projectNameForLog;
        }

        logActivity($conn, 'CREATE', 'LEAVE', $activityDescription, $leaveRequestId);

        if ($approverId > 0) {
          $notificationTitle = "New leave request";
          $notificationMessage =
            "{$preparedBy} applied {$leaveType} leave from {$fromDate} to {$toDate} ({$totalDays} day(s))";

          if ($projectNameForLog !== '') {
            $notificationMessage .= " for project {$projectNameForLog}";
          }

          if (!empty($approver['source'])) {
            $notificationMessage .= " - sent to hierarchy approver: " . $approver['source'];
          }

          sendNotification(
            $conn,
            $approverId,
            $notificationTitle,
            $notificationMessage,
            'leave_requests',
            $leaveRequestId,
            'leave-approval.php?id=' . $leaveRequestId
          );
        }

        mysqli_stmt_close($ins);
        header("Location: apply-leave.php?saved=1");
        exit;
      }

      mysqli_stmt_close($ins);
    }
  }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
  $success = "Leave applied successfully. Notification sent to the hierarchy-based approver and activity log stored.";
}

// ---------------- RECENT LEAVE REQUESTS ----------------
$recentLeaves = [];
$st = mysqli_prepare($conn, "
  SELECT id, leave_type, from_date, to_date, total_days, status, applied_at, reason, selected_dates_json, created_at
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

// ---------------- STATS ----------------
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;

$st = mysqli_prepare($conn, "
  SELECT status, COUNT(*) AS cnt
  FROM leave_requests
  WHERE employee_id = ?
  GROUP BY status
");

if ($st) {
  mysqli_stmt_bind_param($st, "i", $employeeId);
  mysqli_stmt_execute($st);
  $res = mysqli_stmt_get_result($st);

  while ($row = mysqli_fetch_assoc($res)) {
    $s = strtolower((string)$row['status']);
    if ($s === 'pending') $pendingCount = (int)$row['cnt'];
    if ($s === 'approved') $approvedCount = (int)$row['cnt'];
    if ($s === 'rejected') $rejectedCount = (int)$row['cnt'];
  }

  mysqli_stmt_close($st);
}

// ---------------- CALENDAR PREP ----------------
$firstDay = DateTime::createFromFormat('Y-n-j', $viewYear . '-' . $viewMonth . '-1');
$todayYmd = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Apply Leave - TEK-C</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    :root{
      --page-bg:#f5f7fb;
      --card-bg:#ffffff;
      --border:#e5e7eb;
      --text:#111827;
      --muted:#6b7280;
      --soft:#f8fafc;
      --shadow:0 10px 26px rgba(15,23,42,.055);
      --radius:15px;
    }

    body{ background:var(--page-bg); }

    .content-scroll{
      flex:1 1 auto;
      overflow:auto;
      padding:16px;
    }

    .projects-wrapper{ width:100%; }

    .page-heading{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }

    .page-heading h1{
      font-size:19px;
      font-weight:900;
      color:var(--text);
      margin:0;
    }

    .page-heading p{
      margin:3px 0 0;
      color:var(--muted);
      font-size:12px;
      font-weight:600;
    }

    .primary-btn{
      border:0;
      background:#111827;
      color:#fff;
      height:36px;
      padding:0 14px;
      border-radius:11px;
      font-size:12px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      gap:7px;
      text-decoration:none;
      white-space:nowrap;
    }

    .primary-btn:hover{
      background:#020617;
      color:#fff;
    }

    .secondary-btn{
      border:1px solid var(--border);
      background:#fff;
      color:#334155;
      height:36px;
      padding:0 14px;
      border-radius:11px;
      font-size:12px;
      font-weight:900;
      display:inline-flex;
      align-items:center;
      gap:7px;
      text-decoration:none;
      white-space:nowrap;
    }

    .secondary-btn:hover{
      border-color:#cbd5e1;
      background:#f8fafc;
      color:#111827;
    }

    .stat-card{
      background:var(--card-bg);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:12px 13px;
      min-height:78px;
      display:flex;
      align-items:center;
      gap:11px;
    }

    .stat-ic{
      width:38px;
      height:38px;
      border-radius:12px;
      display:grid;
      place-items:center;
      color:#fff;
      font-size:17px;
      flex:0 0 auto;
    }

    .blue{ background:#2f80ed; }
    .orange{ background:#f2994a; }
    .green{ background:#27ae60; }
    .red{ background:#eb5757; }
    .gray{ background:#64748b; }

    .stat-label{
      color:var(--muted);
      font-weight:800;
      font-size:10.5px;
      text-transform:uppercase;
    }

    .stat-value{
      font-size:24px;
      font-weight:950;
      color:var(--text);
      line-height:1;
    }

    .panel{
      background:var(--card-bg);
      border:1px solid var(--border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:13px;
      margin-bottom:14px;
    }

    .panel-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:12px;
    }

    .panel-title{
      font-weight:900;
      font-size:14px;
      margin:0;
      color:var(--text);
    }

    .panel-subtitle{
      color:var(--muted);
      font-size:11px;
      font-weight:700;
      margin-top:2px;
    }

    .form-label{
      font-size:11px;
      font-weight:900;
      color:#475569;
      text-transform:uppercase;
      margin-bottom:6px;
    }

    .form-control,
    .form-select{
      min-height:38px;
      border:1px solid var(--border);
      border-radius:11px;
      font-size:12px;
      font-weight:800;
      color:#111827;
      padding:8px 11px;
    }

    .form-control:focus,
    .form-select:focus{
      border-color:#bfdbfe;
      box-shadow:0 0 0 3px rgba(59,130,246,.10);
    }

    textarea.form-control{
      min-height:86px;
    }

    .form-helper{
      color:#64748b;
      font-size:10.5px;
      font-weight:700;
      margin-top:5px;
      display:flex;
      gap:6px;
      align-items:center;
    }

    .badge-pill{
      border-radius:999px;
      padding:5px 8px;
      font-weight:900;
      font-size:10px;
      display:inline-flex;
      align-items:center;
      gap:6px;
      border:1px solid transparent;
      text-decoration:none;
    }

    .mini-dot{
      width:6px;
      height:6px;
      border-radius:50%;
      background:currentColor;
    }

    .ontrack{
      color:#15803d;
      background:#dcfce7;
      border-color:#bbf7d0;
    }

    .progressing{
      color:#2563eb;
      background:#dbeafe;
      border-color:#bfdbfe;
    }

    .pending{
      color:#6d28d9;
      background:#ede9fe;
      border-color:#ddd6fe;
    }

    .atrisk{
      color:#b91c1c;
      background:#fee2e2;
      border-color:#fecaca;
    }

    .calendar-container{
      border:1px solid var(--border);
      border-radius:13px;
      overflow:hidden;
      background:#fff;
    }

    .calendar-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding:10px 12px;
      background:var(--soft);
      border-bottom:1px solid var(--border);
    }

    .calendar-title{
      font-size:13px;
      font-weight:950;
      margin:0;
      color:var(--text);
    }

    .calendar-nav-btn{
      height:32px;
      border:1px solid var(--border);
      background:#fff;
      border-radius:10px;
      padding:0 10px;
      font-size:11px;
      font-weight:900;
      color:#334155;
    }

    .calendar-nav-btn:hover{
      background:#111827;
      color:#fff;
      border-color:#111827;
    }

    .calendar-grid{
      display:grid;
      grid-template-columns:repeat(7, 1fr);
    }

    .calendar-weekday{
      background:var(--soft);
      color:#64748b;
      font-size:10px;
      text-transform:uppercase;
      font-weight:900;
      text-align:center;
      padding:8px 5px;
      border-bottom:1px solid var(--border);
    }

    .calendar-day{
      min-height:72px;
      border-right:1px solid #eef2f7;
      border-bottom:1px solid #eef2f7;
      position:relative;
      cursor:pointer;
      transition:.15s ease;
      background:#fff;
    }

    .calendar-day:hover{
      background:#fbfdff;
    }

    .calendar-day.disabled{
      background:#f8fafc;
      cursor:not-allowed;
      opacity:.65;
    }

    .calendar-day.selected{
      background:#eff6ff;
    }

    .calendar-day.range-start,
    .calendar-day.range-end{
      box-shadow:inset 0 0 0 2px #93c5fd;
    }

    .day-number{
      display:inline-grid;
      place-items:center;
      min-width:25px;
      height:25px;
      margin:6px;
      border-radius:8px;
      font-size:12px;
      font-weight:900;
      color:#111827;
    }

    .calendar-day.today .day-number{
      background:#111827;
      color:#fff;
    }

    .calendar-day.sunday .day-number{
      color:#dc2626;
    }

    .day-badge{
      position:absolute;
      right:6px;
      bottom:6px;
      border-radius:999px;
      background:#f1f5f9;
      color:#64748b;
      font-size:9px;
      font-weight:900;
      padding:2px 6px;
    }

    .selected-dates-list{
      border:1px solid var(--border);
      border-radius:13px;
      background:#fff;
      overflow:hidden;
      max-height:330px;
      overflow-y:auto;
    }

    .selected-date-item{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding:9px 10px;
      border-bottom:1px solid #eef2f7;
    }

    .selected-date-item:last-child{
      border-bottom:0;
    }

    .date{
      color:#111827;
      font-size:12px;
      font-weight:950;
      line-height:1.2;
    }

    .day-name{
      color:#64748b;
      font-size:10.5px;
      font-weight:800;
      margin-top:2px;
    }

    .half-day-select{
      width:135px;
      height:34px;
      border:1px solid var(--border);
      border-radius:10px;
      background:#fff;
      font-size:11px;
      font-weight:850;
      padding:0 8px;
    }

    .compact-table-wrap{
      width:100%;
      border:1px solid var(--border);
      border-radius:13px;
      overflow:hidden;
      background:#fff;
    }

    .compact-table{
      width:100%;
      margin:0;
      table-layout:auto;
    }

    .compact-table thead th{
      background:var(--soft);
      color:#64748b;
      font-size:10px;
      text-transform:uppercase;
      font-weight:900;
      border-bottom:1px solid var(--border)!important;
      padding:8px 9px;
    }

    .compact-table tbody td{
      padding:8px 9px;
      vertical-align:middle;
      border-color:#eef2f7;
      color:#334155;
      font-weight:700;
      font-size:11.5px;
    }

    .table-title-cell{
      display:flex;
      align-items:center;
      gap:8px;
    }

    .table-icon{
      width:26px;
      height:26px;
      border-radius:8px;
      display:grid;
      place-items:center;
      background:#eff6ff;
      color:#2563eb;
      font-size:13px;
      flex:0 0 auto;
    }

    .table-primary-text{
      color:#111827;
      font-size:11.5px;
      font-weight:900;
    }

    .table-secondary-text{
      color:#64748b;
      font-size:10px;
      font-weight:700;
      margin-top:1px;
    }

    .summary-card{
      border:1px solid var(--border);
      background:#fff;
      border-radius:13px;
      padding:10px;
    }

    .summary-row{
      display:flex;
      justify-content:space-between;
      gap:12px;
      padding:7px 0;
      border-bottom:1px solid #eef2f7;
      color:#334155;
      font-size:11.5px;
      font-weight:800;
    }

    .summary-row:last-child{
      border-bottom:0;
    }

    .summary-row span:first-child{
      color:#64748b;
    }

    .submit-row{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding-top:12px;
      border-top:1px solid #eef2f7;
      margin-top:12px;
    }


    @media(max-width:991.98px){
      .main{ margin-left:0!important; width:100%!important; max-width:100%!important; }
      .sidebar{ position:fixed!important; transform:translateX(-100%); z-index:1040!important; }
      .sidebar.open, .sidebar.active, .sidebar.show{ transform:translateX(0)!important; }
    }

    @media(max-width:1199px){
      .compact-table thead{ display:none; }

      .compact-table,
      .compact-table tbody,
      .compact-table tr,
      .compact-table td{
        display:block;
        width:100%;
      }

      .compact-table tbody tr{
        border-bottom:1px solid var(--border);
        padding:10px;
      }

      .compact-table tbody td{
        border:0;
        display:flex;
        justify-content:space-between;
        gap:12px;
      }

      .compact-table tbody td::before{
        content:attr(data-label);
        font-size:10px;
        font-weight:900;
        color:#64748b;
        text-transform:uppercase;
        flex:0 0 95px;
      }

      .compact-table tbody td:first-child{
        display:block;
      }

      .compact-table tbody td:first-child::before{
        display:none;
      }
    }

    @media(max-width:768px){
      .content-scroll{ padding:12px 10px 12px!important; }
      .page-heading{ align-items:flex-start; flex-direction:column; }
      .panel{ padding:12px; }
      .calendar-day{ min-height:56px; }
      .selected-date-item{ align-items:stretch; flex-direction:column; }
      .half-day-select{ width:100%; }
      .submit-row{ align-items:stretch; flex-direction:column; }
      .primary-btn, .secondary-btn{ width:100%; justify-content:center; }
    }
  </style>
</head>

<body>
<div class="app">
  <?php include 'includes/sidebar.php'; ?>

  <main class="main" aria-label="Main">
    <?php include 'includes/topbar.php'; ?>

    <div id="contentScroll" class="content-scroll">
      <div class="container-fluid projects-wrapper px-0">

        <div class="page-heading">
          <div>
            <h1>Apply Leave</h1>
            <p>Submit leave request and route approval by hierarchy: Project Engineer → TL, TL → Manager, Manager/HR → Admin.</p>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <a href="my-leave-history.php" class="secondary-btn">
              <i class="bi bi-clock-history"></i>
              View History
            </a>

            <a href="my-sites.php" class="primary-btn">
              <i class="bi bi-folder2-open"></i>
              My Projects
            </a>
          </div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
          <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic blue"><i class="bi bi-calendar-check"></i></div>
              <div>
                <div class="stat-label">Available CL</div>
                <div class="stat-value"><?php echo e($leaveBalance['CL']); ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic green"><i class="bi bi-heart-pulse"></i></div>
              <div>
                <div class="stat-label">Available SL</div>
                <div class="stat-value"><?php echo e($leaveBalance['SL']); ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
              <div>
                <div class="stat-label">Pending Requests</div>
                <div class="stat-value"><?php echo (int)$pendingCount; ?></div>
              </div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card">
              <div class="stat-ic gray"><i class="bi bi-folder2"></i></div>
              <div>
                <div class="stat-label">My Projects</div>
                <div class="stat-value"><?php echo count($myProjects); ?></div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12 col-xl-8">
            <form method="POST" id="leaveForm" autocomplete="off">
              <input type="hidden" name="submit_leave" value="1">
              <input type="hidden" name="selected_dates" id="selected_dates_input" value="">
              <input type="hidden" name="half_day_map" id="half_day_map_input" value="">
              <input type="hidden" name="from_date" id="from_date_input" value="">
              <input type="hidden" name="to_date" id="to_date_input" value="">

              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Leave Request Details</h3>
                    <div class="panel-subtitle">Choose project, leave type and handover details</div>
                  </div>
                  <span class="badge-pill progressing">
                    <span class="mini-dot"></span>
                    <?php echo e($preparedBy); ?>
                  </span>
                </div>

                <div class="row g-3">
                  <div class="col-md-7">
                    <label class="form-label">Project / Site <span class="text-danger">*</span></label>
                    <select class="form-select" name="site_id" id="site_id" required>
                      <option value="">Select assigned project</option>
                      <?php foreach ($myProjects as $projectOption): ?>
                        <?php
                          $projectId = (int)$projectOption['id'];
                          $projectLabel = trim((string)$projectOption['project_name']);
                          if (!empty($projectOption['project_location'])) {
                            $projectLabel .= ' - ' . $projectOption['project_location'];
                          }

                          $projectTlName = !empty($projectOption['team_lead_name'])
                            ? $projectOption['team_lead_name']
                            : ($projectOption['fallback_team_lead_name'] ?? '');

                          $tlText = !empty($projectTlName)
                            ? 'TL: ' . $projectTlName
                            : (!empty($projectOption['manager_name']) ? 'Manager: ' . $projectOption['manager_name'] : 'No TL assigned');

                          if ($currentRoleKey === 'tl') {
                            $tlText = !empty($projectOption['manager_name'])
                              ? 'Manager: ' . $projectOption['manager_name']
                              : 'No Manager assigned';
                          }
                        ?>
                        <option value="<?php echo $projectId; ?>" <?php echo ($selectedSiteId === $projectId ? 'selected' : ''); ?>>
                          <?php echo e($projectLabel . ' (' . $tlText . ')'); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>

                    <?php if (empty($myProjects)): ?>
                      <div class="form-helper text-danger">
                        <i class="bi bi-exclamation-circle"></i>
                        No assigned project found for your employee account.
                      </div>
                    <?php else: ?>
                      <div class="form-helper">
                        <i class="bi bi-bell"></i>
                        Approval route is hierarchy based: Project Engineer → TL, TL → Manager, Manager/HR → Admin. TL users will see the project manager name beside each project.
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="col-md-5">
                    <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                    <select class="form-select" name="leave_type" id="leave_type" required>
                      <option value="CL" <?php echo ($leaveType==='CL'?'selected':''); ?>>Casual Leave (CL) - <?php echo e($leaveBalance['CL']); ?> left</option>
                      <option value="SL" <?php echo ($leaveType==='SL'?'selected':''); ?>>Sick Leave (SL) - <?php echo e($leaveBalance['SL']); ?> left</option>
                      <option value="EL" <?php echo ($leaveType==='EL'?'selected':''); ?>>Earned Leave (EL) - <?php echo e($leaveBalance['EL']); ?> left</option>
                      <option value="LOP" <?php echo ($leaveType==='LOP'?'selected':''); ?>>Loss of Pay (LOP)</option>
                      <option value="OD" <?php echo ($leaveType==='OD'?'selected':''); ?>>On Duty (OD)</option>
                      <option value="WFH" <?php echo ($leaveType==='WFH'?'selected':''); ?>>Work From Home (WFH) - <?php echo e($leaveBalance['WFH']); ?> left</option>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label">From / To</label>
                    <div class="row g-2">
                      <div class="col-6">
                        <input class="form-control" id="from_date_view" readonly placeholder="From Date" style="background:#f8fafc;">
                      </div>
                      <div class="col-6">
                        <input class="form-control" id="to_date_view" readonly placeholder="To Date" style="background:#f8fafc;">
                      </div>
                    </div>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Contact During Leave</label>
                    <input class="form-control" name="contact_during_leave" value="<?php echo e($contactDuringLeave); ?>" placeholder="Mobile / email">
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Handover To</label>
                    <input class="form-control" name="handover_to" value="<?php echo e($handoverTo); ?>" placeholder="Employee name">
                  </div>

                  <div class="col-12">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="reason" required placeholder="Enter detailed reason for leave"><?php echo e($reason); ?></textarea>
                  </div>
                </div>
              </div>

              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Select Leave Dates</h3>
                    <div class="panel-subtitle">Click start date and end date to select a range</div>
                  </div>

                  <button type="button" class="secondary-btn" id="clearSelection">
                    <i class="bi bi-eraser"></i>
                    Clear
                  </button>
                </div>

                <div class="calendar-container">
                  <div class="calendar-header">
                    <button type="button" class="calendar-nav-btn" onclick="changeMonth(-1)">
                      <i class="bi bi-chevron-left"></i>
                      Prev
                    </button>

                    <h6 class="calendar-title" id="calendarTitle"><?php echo e($firstDay->format('F Y')); ?></h6>

                    <button type="button" class="calendar-nav-btn" onclick="changeMonth(1)">
                      Next
                      <i class="bi bi-chevron-right"></i>
                    </button>
                  </div>

                  <div class="calendar-grid" id="calendarGrid"></div>
                </div>

                <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mt-3">
                  <div class="d-flex gap-2 flex-wrap">
                    <span class="badge-pill progressing">
                      <i class="bi bi-calendar-range"></i>
                      Selected:
                      <strong id="selected_count">0</strong>
                    </span>

                    <span class="badge-pill ontrack">
                      <i class="bi bi-calculator"></i>
                      Total Days:
                      <strong id="total_leave_days">0</strong>
                    </span>

                    <?php if ($EXCLUDE_SUNDAYS): ?>
                      <span class="badge-pill atrisk">
                        <i class="bi bi-slash-circle"></i>
                        Sundays disabled
                      </span>
                    <?php endif; ?>
                  </div>

                  <span class="table-secondary-text">
                    Past dates are blocked. Half-day can be selected below.
                  </span>
                </div>
              </div>

              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Selected Dates & Half-Day Options</h3>
                    <div class="panel-subtitle">Mark specific days as first half or second half if needed</div>
                  </div>
                </div>

                <div id="selectedList" class="selected-dates-list">
                  <div class="text-center text-muted py-4">No dates selected. Click on calendar dates to select.</div>
                </div>

                <div class="submit-row">
                  <div class="table-secondary-text">
                    Create action will be stored in <b>activity_logs</b> and a notification will be sent to the approver.
                  </div>

                  <button type="submit" class="primary-btn" <?php echo empty($myProjects) ? 'disabled' : ''; ?>>
                    <i class="bi bi-send-check"></i>
                    Submit Leave Request
                  </button>
                </div>
              </div>
            </form>
          </div>

          <div class="col-12 col-xl-4">
            <div class="panel">
              <div class="panel-header">
                <div>
                  <h3 class="panel-title">Approval Route</h3>
                  <div class="panel-subtitle">How the selected project notification is routed</div>
                </div>
              </div>

              <div class="summary-card">
                <div class="summary-row">
                  <span>Employee</span>
                  <strong><?php echo e($preparedBy); ?></strong>
                </div>
                <div class="summary-row">
                  <span>Department</span>
                  <strong><?php echo e($department ?: '—'); ?></strong>
                </div>
                <div class="summary-row">
                  <span>Detected Role</span>
                  <strong><?php echo e(strtoupper(str_replace('_', ' ', $currentRoleKey))); ?></strong>
                </div>
                <div class="summary-row">
                  <span>Route Priority</span>
                  <strong>PE → TL, TL → Manager, Manager/HR → Admin</strong>
                </div>
                <div class="summary-row">
                  <span>Activity Module</span>
                  <strong>LEAVE</strong>
                </div>
              </div>
            </div>

            <div class="panel">
              <div class="panel-header">
                <div>
                  <h3 class="panel-title">My Assigned Projects</h3>
                  <div class="panel-subtitle">Only projects under this employee are available</div>
                </div>
              </div>

              <?php if (empty($myProjects)): ?>
                <div class="text-center text-muted py-4" style="font-weight:800;">
                  <i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:8px;opacity:.5;"></i>
                  No assigned project found.
                </div>
              <?php else: ?>
                <div class="compact-table-wrap">
                  <table class="table compact-table align-middle">
                    <thead>
                      <tr>
                        <th>Project</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach (array_slice($myProjects, 0, 6) as $p): ?>
                        <?php [$pStatus, $pClass] = projectStatusBadge($p['start_date'] ?? '', $p['expected_completion_date'] ?? ''); ?>
                        <tr>
                          <td data-label="Project">
                            <div class="table-title-cell">
                              <div class="table-icon"><i class="bi bi-building"></i></div>
                              <div>
                                <div class="table-primary-text"><?php echo e($p['project_name'] ?? ''); ?></div>
                                <div class="table-secondary-text"><?php echo e($p['project_location'] ?? ''); ?></div>
                              </div>
                            </div>
                          </td>
                          <td data-label="Status">
                            <span class="badge-pill <?php echo e($pClass); ?>">
                              <span class="mini-dot"></span>
                              <?php echo e($pStatus); ?>
                            </span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>

            <div class="panel">
              <div class="panel-header">
                <div>
                  <h3 class="panel-title">Recent Requests</h3>
                  <div class="panel-subtitle">Latest leave applications</div>
                </div>
                <a href="my-leave-history.php" class="badge-pill progressing">
                  View All
                </a>
              </div>

              <?php if (empty($recentLeaves)): ?>
                <div class="text-center text-muted py-4" style="font-weight:800;">
                  <i class="bi bi-inbox" style="font-size:32px;display:block;margin-bottom:8px;opacity:.5;"></i>
                  No leave requests found.
                </div>
              <?php else: ?>
                <div class="d-grid gap-2">
                  <?php foreach ($recentLeaves as $r): ?>
                    <?php
                      [$stLabel, $stClass, $stIcon] = statusMeta($r['status'] ?? '');
                      $from = safeDate($r['from_date'] ?? '');
                      $to = safeDate($r['to_date'] ?? '');
                    ?>

                    <div class="summary-card">
                      <div class="d-flex justify-content-between gap-2 align-items-start">
                        <div>
                          <div class="table-primary-text"><?php echo e($r['leave_type']); ?></div>
                          <div class="table-secondary-text">
                            <?php echo e($from); ?> → <?php echo e($to); ?>
                          </div>
                          <div class="table-secondary-text mt-1">
                            <?php echo e($r['total_days']); ?> day(s) • <?php echo e(safeDate($r['applied_at'])); ?>
                          </div>
                        </div>

                        <span class="badge-pill <?php echo e($stClass); ?>">
                          <i class="bi <?php echo e($stIcon); ?>"></i>
                          <?php echo e($stLabel); ?>
                        </span>
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
let selectedDates = <?php echo json_encode(array_values($selectedDates)); ?>;
let halfDayMap = <?php echo json_encode((object)$halfDayMap); ?>;

if (selectedDates.length > 0) {
  rangeStart = selectedDates[0];
  rangeEnd = selectedDates[selectedDates.length - 1];
}

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
    const parsedDate = parseYmd(date);
    const dayName = parsedDate ? parsedDate.toLocaleDateString('en-US', { weekday: 'long' }) : '';
    
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
  updateHiddenInputs();
  updateCounters();
  renderSelectedList();
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
  if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
  }
} catch (Throwable $e) { }
?>
