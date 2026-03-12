<?php
// attendance.php (TEK-C style)
// ✅ Updated:
// 1) Default filter shows TODAY attendance
// 2) User can still filter any date range
// 3) Added two views:
//    - Table/List view
//    - Calendar view
// 4) Mobile cards kept
// 5) Update attendance status + remarks
// 6) Works with your current DB structure

session_start();
require_once 'includes/db-config.php';

date_default_timezone_set('Asia/Kolkata');

$conn = get_db_connection();
if (!$conn) {
    die("Database connection failed.");
}

/* =========================
   HELPERS
========================= */
function e($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function safeDate($v, $fallback = '—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $fallback;
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : e($v);
}

function safeDateShort($v, $fallback = '—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return $fallback;
    $ts = strtotime($v);
    return $ts ? date('d M', $ts) : e($v);
}

function safeDateTime($v, $fallback = '—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return $fallback;
    $ts = strtotime($v);
    return $ts ? date('d M Y, h:i A', $ts) : e($v);
}

function safeTime($v, $fallback = '—'){
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return $fallback;
    $ts = strtotime($v);
    return $ts ? date('h:i A', $ts) : e($v);
}

function safeNum($v, $fallback = '0'){
    return is_numeric($v) ? (string)$v : $fallback;
}

function fileUrl($path){
    $p = trim((string)$path);
    if ($p === '') return '';

    $p = str_replace('\\', '/', $p);
    $p = preg_replace('~/+~', '/', $p);

    if (preg_match('~^https?://~i', $p)) return $p;

    if (stripos($p, '..admin/') === 0) {
        $p = '../admin/' . substr($p, 8);
    }

    if (stripos($p, '../admin/uploads/') === 0) return $p;
    if (stripos($p, 'admin/uploads/') === 0) return '../' . $p;
    if (stripos($p, '/admin/uploads/') === 0) return '..' . $p;

    if (stripos($p, 'uploads/') === 0) return '../admin/' . $p;
    if (stripos($p, '/uploads/') === 0) return '../admin' . $p;

    if (stripos($p, 'employees/') === 0) return '../admin/uploads/' . $p;
    if (stripos($p, '/employees/') === 0) return '../admin/uploads' . $p;

    return '../admin/uploads/' . ltrim($p, '/');
}

function attendanceBadgeClass($status){
    $status = strtolower(trim((string)$status));
    switch ($status) {
        case 'present':  return 'status-present';
        case 'absent':   return 'status-absent';
        case 'half-day': return 'status-halfday';
        case 'late':     return 'status-late';
        case 'holiday':  return 'status-holiday';
        case 'leave':    return 'status-leave';
        case 'vacation': return 'status-vacation';
        default:         return 'status-default';
    }
}

function attendanceText($status){
    return ucwords(str_replace('-', ' ', (string)$status));
}

/* =========================
   FLASH
========================= */
$success = (string)($_SESSION['flash_success'] ?? '');
$error   = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* =========================
   UPDATE ATTENDANCE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_attendance'])) {
    $attendance_id = (int)($_POST['attendance_id'] ?? 0);
    $status        = trim((string)($_POST['status'] ?? 'present'));
    $remarks       = trim((string)($_POST['remarks'] ?? ''));

    $allowedStatuses = ['present','absent','half-day','late','holiday','leave','vacation'];

    if ($attendance_id <= 0 || !in_array($status, $allowedStatuses, true)) {
        $_SESSION['flash_error'] = 'Invalid attendance update request.';
    } else {
        $sql = "UPDATE attendance SET status = ?, remarks = ? WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            $_SESSION['flash_error'] = 'Database error: ' . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($stmt, "ssi", $status, $remarks, $attendance_id);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['flash_success'] = 'Attendance updated successfully.';
            } else {
                $_SESSION['flash_error'] = 'Update failed: ' . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header("Location: attendance.php" . ($qs ? '?' . $qs : ''));
    exit;
}

/* =========================
   FILTERS
   ✅ Default = TODAY
========================= */
$today = date('Y-m-d');

$date_from   = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : $today;
$date_to     = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : $today;
$employee_id = (int)($_GET['employee_id'] ?? 0);
$status      = trim((string)($_GET['status'] ?? ''));
$site_id     = (int)($_GET['site_id'] ?? 0);
$view        = trim((string)($_GET['view'] ?? 'table'));
if (!in_array($view, ['table','calendar'], true)) {
    $view = 'table';
}

/* =========================
   CALENDAR MONTH FILTER
========================= */
$calendar_month = trim((string)($_GET['calendar_month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}\-\d{2}$/', $calendar_month)) {
    $calendar_month = date('Y-m');
}
$calendarMonthStart = $calendar_month . '-01';
$calendarMonthEnd   = date('Y-m-t', strtotime($calendarMonthStart));

$employees = [];
$sites = [];
$attendanceRows = [];
$calendarRows = [];

$stats = [
    'total'    => 0,
    'present'  => 0,
    'absent'   => 0,
    'late'     => 0,
    'leave'    => 0,
    'holiday'  => 0,
    'half_day' => 0
];

/* =========================
   FETCH FILTER DROPDOWNS
========================= */
$resEmp = mysqli_query($conn, "SELECT id, full_name, employee_code FROM employees ORDER BY full_name ASC");
if ($resEmp) {
    $employees = mysqli_fetch_all($resEmp, MYSQLI_ASSOC);
    mysqli_free_result($resEmp);
}

$resSite = mysqli_query($conn, "SELECT id, project_name, project_code FROM sites WHERE deleted_at IS NULL ORDER BY project_name ASC");
if ($resSite) {
    $sites = mysqli_fetch_all($resSite, MYSQLI_ASSOC);
    mysqli_free_result($resSite);
}

/* =========================
   BUILD WHERE FOR TABLE VIEW
========================= */
$where = [];
$params = [];
$types = '';

if ($date_from !== '') {
    $where[] = "a.attendance_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $where[] = "a.attendance_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($employee_id > 0) {
    $where[] = "a.employee_id = ?";
    $params[] = $employee_id;
    $types .= 'i';
}

if ($status !== '') {
    $where[] = "a.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($site_id > 0) {
    $where[] = "(a.punch_in_site_id = ? OR a.punch_out_site_id = ?)";
    $params[] = $site_id;
    $params[] = $site_id;
    $types .= 'ii';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* =========================
   FETCH STATS
========================= */
$sqlStats = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late,
        SUM(CASE WHEN a.status = 'leave' THEN 1 ELSE 0 END) AS leave_count,
        SUM(CASE WHEN a.status = 'holiday' THEN 1 ELSE 0 END) AS holiday_count,
        SUM(CASE WHEN a.status = 'half-day' THEN 1 ELSE 0 END) AS half_day
    FROM attendance a
    $whereSql
";
$stmtStats = mysqli_prepare($conn, $sqlStats);
if ($stmtStats) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmtStats, $types, ...$params);
    }
    mysqli_stmt_execute($stmtStats);
    $resStats = mysqli_stmt_get_result($stmtStats);
    if ($resStats && $row = mysqli_fetch_assoc($resStats)) {
        $stats['total']    = (int)($row['total'] ?? 0);
        $stats['present']  = (int)($row['present'] ?? 0);
        $stats['absent']   = (int)($row['absent'] ?? 0);
        $stats['late']     = (int)($row['late'] ?? 0);
        $stats['leave']    = (int)($row['leave_count'] ?? 0);
        $stats['holiday']  = (int)($row['holiday_count'] ?? 0);
        $stats['half_day'] = (int)($row['half_day'] ?? 0);
    }
    mysqli_stmt_close($stmtStats);
}

/* =========================
   FETCH TABLE / LIST RECORDS
========================= */
$sqlList = "
    SELECT
        a.*,
        e.full_name,
        e.employee_code,
        e.designation,
        e.department,
        e.photo,
        s1.project_name AS punch_in_site_name,
        s2.project_name AS punch_out_site_name
    FROM attendance a
    INNER JOIN employees e ON a.employee_id = e.id
    LEFT JOIN sites s1 ON a.punch_in_site_id = s1.id
    LEFT JOIN sites s2 ON a.punch_out_site_id = s2.id
    $whereSql
    ORDER BY a.attendance_date DESC, a.id DESC
";
$stmtList = mysqli_prepare($conn, $sqlList);
if ($stmtList) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmtList, $types, ...$params);
    }
    mysqli_stmt_execute($stmtList);
    $resList = mysqli_stmt_get_result($stmtList);
    if ($resList) {
        $attendanceRows = mysqli_fetch_all($resList, MYSQLI_ASSOC);
    }
    mysqli_stmt_close($stmtList);
}

/* =========================
   FETCH CALENDAR DATA
========================= */
$calWhere = [];
$calParams = [];
$calTypes = '';

$calWhere[] = "a.attendance_date >= ?";
$calParams[] = $calendarMonthStart;
$calTypes .= 's';

$calWhere[] = "a.attendance_date <= ?";
$calParams[] = $calendarMonthEnd;
$calTypes .= 's';

if ($employee_id > 0) {
    $calWhere[] = "a.employee_id = ?";
    $calParams[] = $employee_id;
    $calTypes .= 'i';
}

if ($status !== '') {
    $calWhere[] = "a.status = ?";
    $calParams[] = $status;
    $calTypes .= 's';
}

if ($site_id > 0) {
    $calWhere[] = "(a.punch_in_site_id = ? OR a.punch_out_site_id = ?)";
    $calParams[] = $site_id;
    $calParams[] = $site_id;
    $calTypes .= 'ii';
}

$calWhereSql = 'WHERE ' . implode(' AND ', $calWhere);

$sqlCalendar = "
    SELECT
        a.id,
        a.employee_id,
        a.attendance_date,
        a.punch_in_time,
        a.punch_out_time,
        a.total_hours,
        a.status,
        a.remarks,
        e.full_name,
        e.employee_code,
        e.photo
    FROM attendance a
    INNER JOIN employees e ON a.employee_id = e.id
    $calWhereSql
    ORDER BY a.attendance_date ASC, e.full_name ASC
";
$stmtCal = mysqli_prepare($conn, $sqlCalendar);
if ($stmtCal) {
    mysqli_stmt_bind_param($stmtCal, $calTypes, ...$calParams);
    mysqli_stmt_execute($stmtCal);
    $resCal = mysqli_stmt_get_result($stmtCal);
    if ($resCal) {
        $calendarRows = mysqli_fetch_all($resCal, MYSQLI_ASSOC);
    }
    mysqli_stmt_close($stmtCal);
}

/* =========================
   PREPARE CALENDAR GRID
========================= */
$calendarByDate = [];
foreach ($calendarRows as $row) {
    $d = (string)$row['attendance_date'];
    if (!isset($calendarByDate[$d])) {
        $calendarByDate[$d] = [];
    }
    $calendarByDate[$d][] = $row;
}

$monthTs = strtotime($calendarMonthStart);
$calendarTitle = date('F Y', $monthTs);
$daysInMonth = (int)date('t', $monthTs);
$firstDayWeekIndex = (int)date('N', $monthTs); // 1=Mon ... 7=Sun
$calendarCells = [];

for ($i = 1; $i < $firstDayWeekIndex; $i++) {
    $calendarCells[] = null;
}
for ($d = 1; $d <= $daysInMonth; $d++) {
    $calendarCells[] = sprintf('%s-%02d', $calendar_month, $d);
}
while (count($calendarCells) % 7 !== 0) {
    $calendarCells[] = null;
}

$prevCalendarMonth = date('Y-m', strtotime($calendarMonthStart . ' -1 month'));
$nextCalendarMonth = date('Y-m', strtotime($calendarMonthStart . ' +1 month'));

/* preserve filters for calendar nav */
function buildAttendanceUrl($overrides = []) {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $k => $v) {
        if ($v === null) unset($query[$k]);
    }
    return 'attendance.php?' . http_build_query($query);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Attendance - TEK-C</title>

    <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
    <link rel="manifest" href="assets/fav/site.webmanifest">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }
        .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px; height:100%; }
        .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
        .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:12px; flex-wrap:wrap; }

        .stat-card{
            background: var(--surface);
            border:1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding:14px 16px;
            height:92px;
            display:flex;
            align-items:center;
            gap:14px;
        }

        .stat-ic{
            width:46px; height:46px; border-radius:14px;
            display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto;
        }

        .stat-blue{ background:#2d9cdb; }
        .stat-green{ background:#10b981; }
        .stat-red{ background:#ef4444; }
        .stat-yellow{ background:#f59e0b; }
        .stat-purple{ background:#8b5cf6; }
        .stat-orange{ background:#fb923c; }

        .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
        .stat-value{ font-size:28px; font-weight:900; line-height:1; margin-top:3px; }

        .filter-grid{
            display:grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap:12px;
        }

        .form-label{
            font-size:12px;
            font-weight:900;
            color:#4b5563;
            margin-bottom:6px;
        }

        .form-control, .form-select{
            border-radius:12px;
            min-height:44px;
            border:1px solid var(--border);
            font-weight:700;
        }

        .btn-main{
            background: var(--blue);
            color:#fff;
            border:none;
            padding:10px 16px;
            border-radius:12px;
            font-weight:900;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }
        .btn-main:hover{ background:#2589c5; color:#fff; }

        .btn-lite{
            background:#fff;
            color:#374151;
            border:1px solid var(--border);
            padding:10px 16px;
            border-radius:12px;
            font-weight:900;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }
        .btn-lite:hover{ color:var(--blue); background:#f9fafb; }

        .view-switch{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }

        .view-pill{
            background:#fff;
            color:#374151;
            border:1px solid var(--border);
            padding:9px 14px;
            border-radius:12px;
            font-weight:900;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:8px;
        }

        .view-pill.active{
            background: var(--blue);
            color:#fff;
            border-color: var(--blue);
        }

        .employee-box{ display:flex; align-items:center; gap:10px; }
        .employee-photo{
            width:40px; height:40px; border-radius:12px; overflow:hidden;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display:flex; align-items:center; justify-content:center;
            color:#fff; font-weight:900; font-size:16px; flex:0 0 auto;
        }
        .employee-photo img{ width:100%; height:100%; object-fit:cover; }

        .employee-name{ font-weight:1000; font-size:13px; color:#111827; margin-bottom:2px; line-height:1.2; }
        .employee-sub{ font-size:11px; color:#6b7280; font-weight:800; line-height:1.2; }

        .status-badge{
            padding:4px 10px;
            border-radius:999px;
            font-size:11px;
            font-weight:1000;
            text-transform:uppercase;
            letter-spacing:.3px;
            display:inline-flex;
            align-items:center;
            gap:6px;
            white-space:nowrap;
        }

        .status-present{ background:rgba(16,185,129,.12); color:#10b981; border:1px solid rgba(16,185,129,.22); }
        .status-absent{ background:rgba(239,68,68,.12); color:#ef4444; border:1px solid rgba(239,68,68,.22); }
        .status-halfday{ background:rgba(251,146,60,.12); color:#ea580c; border:1px solid rgba(251,146,60,.22); }
        .status-late{ background:rgba(245,158,11,.12); color:#d97706; border:1px solid rgba(245,158,11,.22); }
        .status-holiday{ background:rgba(99,102,241,.12); color:#4f46e5; border:1px solid rgba(99,102,241,.22); }
        .status-leave{ background:rgba(139,92,246,.12); color:#7c3aed; border:1px solid rgba(139,92,246,.22); }
        .status-vacation{ background:rgba(6,182,212,.12); color:#0891b2; border:1px solid rgba(6,182,212,.22); }
        .status-default{ background:rgba(107,114,128,.12); color:#4b5563; border:1px solid rgba(107,114,128,.22); }

        .loc-text{ font-size:12px; font-weight:700; color:#374151; line-height:1.35; }
        .mini-text{ font-size:11px; color:#6b7280; font-weight:800; }

        .table thead th{
            font-size:11px;
            color:#6b7280;
            font-weight:800;
            border-bottom:1px solid var(--border)!important;
            padding:10px !important;
            white-space:normal !important;
        }

        .table td{
            vertical-align:top;
            border-color:var(--border);
            font-weight:700;
            color:#374151;
            padding:10px !important;
            font-size:13px;
            white-space:normal !important;
        }

        .btn-action{
            background:#fff;
            border:1px solid var(--border);
            border-radius:10px;
            padding:7px 10px;
            color:#374151;
            font-size:12px;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            font-weight:900;
        }
        .btn-action:hover{ color:var(--blue); background:#f9fafb; }

        .att-card{
            border:1px solid var(--border);
            border-radius:16px;
            background:#fff;
            box-shadow: var(--shadow);
            padding:12px;
        }

        .att-grid{
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap:10px;
            margin-top:12px;
        }

        .att-item{
            border:1px solid var(--border);
            border-radius:12px;
            padding:10px;
            background:#fafafa;
        }

        .att-key{
            font-size:11px;
            font-weight:1000;
            color:#6b7280;
            margin-bottom:4px;
            text-transform:uppercase;
        }

        .att-val{
            font-size:13px;
            font-weight:900;
            color:#111827;
            line-height:1.3;
            word-break:break-word;
        }

        .alert{ border:none; border-radius:16px; box-shadow: var(--shadow); }

        .calendar-toolbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:14px;
        }

        .calendar-title{
            font-size:20px;
            font-weight:1000;
            color:#111827;
            margin:0;
        }

        .calendar-grid{
            display:grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap:10px;
        }

        .calendar-day-head{
            text-align:center;
            font-size:11px;
            font-weight:1000;
            color:#6b7280;
            text-transform:uppercase;
            padding:8px 6px;
        }

        .calendar-cell{
            min-height:150px;
            border:1px solid var(--border);
            border-radius:16px;
            background:#fff;
            padding:10px;
            display:flex;
            flex-direction:column;
            gap:8px;
        }

        .calendar-cell.empty{
            background:#f9fafb;
            border-style:dashed;
        }

        .calendar-date{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
        }

        .calendar-date-num{
            width:32px;
            height:32px;
            border-radius:10px;
            display:grid;
            place-items:center;
            font-size:13px;
            font-weight:1000;
            color:#111827;
            background:#f3f4f6;
        }

        .calendar-date-num.today{
            background:var(--blue);
            color:#fff;
        }

        .calendar-count{
            font-size:10px;
            font-weight:1000;
            color:#6b7280;
            text-transform:uppercase;
        }

        .calendar-events{
            display:flex;
            flex-direction:column;
            gap:6px;
            overflow:hidden;
        }

        .calendar-event{
            border:1px solid var(--border);
            border-left:4px solid #d1d5db;
            border-radius:12px;
            background:#fafafa;
            padding:7px 8px;
            font-size:11px;
            line-height:1.3;
        }

        .calendar-event.status-present{ border-left-color:#10b981; }
        .calendar-event.status-absent{ border-left-color:#ef4444; }
        .calendar-event.status-halfday{ border-left-color:#ea580c; }
        .calendar-event.status-late{ border-left-color:#d97706; }
        .calendar-event.status-holiday{ border-left-color:#4f46e5; }
        .calendar-event.status-leave{ border-left-color:#7c3aed; }
        .calendar-event.status-vacation{ border-left-color:#0891b2; }

        .calendar-event-name{
            font-weight:1000;
            color:#111827;
            margin-bottom:2px;
            word-break:break-word;
        }

        .calendar-event-meta{
            color:#6b7280;
            font-weight:800;
            font-size:10px;
        }

        .calendar-mobile-list{
            display:none;
        }

        .calendar-day-card{
            border:1px solid var(--border);
            border-radius:16px;
            background:#fff;
            box-shadow: var(--shadow);
            padding:12px;
        }

        .calendar-day-title{
            font-weight:1000;
            color:#111827;
            font-size:15px;
            margin-bottom:10px;
        }

        .calendar-day-list{
            display:flex;
            flex-direction:column;
            gap:8px;
        }

        @media (max-width: 1399.98px){
            .filter-grid{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 1199.98px){
            .calendar-grid{
                grid-template-columns: repeat(7, minmax(150px, 1fr));
                overflow-x:auto;
            }
        }

        @media (max-width: 767.98px){
            .content-scroll{ padding:12px 10px 12px !important; }
            .container-fluid.maxw{ padding-left:6px !important; padding-right:6px !important; }
            .panel{ padding:12px !important; margin-bottom:12px; border-radius:14px; }
            .filter-grid{ grid-template-columns: 1fr; }
            .att-grid{ grid-template-columns: 1fr; }
            .main{ margin-left:0 !important; width:100% !important; max-width:100% !important; }
            .sidebar{ position:fixed !important; transform:translateX(-100%); z-index:1040 !important; }
            .sidebar.open,.sidebar.active,.sidebar.show{ transform:translateX(0) !important; }

            .calendar-grid{ display:none; }
            .calendar-mobile-list{ display:block; }
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

                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div>
                        <h1 class="h3 fw-bold text-dark mb-1">Attendance</h1>
                        <p class="text-muted mb-0">Today attendance by default, with table and calendar view</p>
                    </div>

                    <div class="view-switch">
                        <a href="<?php echo e(buildAttendanceUrl(['view' => 'table'])); ?>" class="view-pill <?php echo $view === 'table' ? 'active' : ''; ?>">
                            <i class="bi bi-table"></i> Table View
                        </a>
                        <a href="<?php echo e(buildAttendanceUrl(['view' => 'calendar'])); ?>" class="view-pill <?php echo $view === 'calendar' ? 'active' : ''; ?>">
                            <i class="bi bi-calendar3"></i> Calendar View
                        </a>
                    </div>
                </div>

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

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6 col-xl">
                        <div class="stat-card">
                            <div class="stat-ic stat-blue"><i class="bi bi-calendar3"></i></div>
                            <div><div class="stat-label">Total Records</div><div class="stat-value"><?php echo (int)$stats['total']; ?></div></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl">
                        <div class="stat-card">
                            <div class="stat-ic stat-green"><i class="bi bi-person-check"></i></div>
                            <div><div class="stat-label">Present</div><div class="stat-value"><?php echo (int)$stats['present']; ?></div></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl">
                        <div class="stat-card">
                            <div class="stat-ic stat-red"><i class="bi bi-person-x"></i></div>
                            <div><div class="stat-label">Absent</div><div class="stat-value"><?php echo (int)$stats['absent']; ?></div></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl">
                        <div class="stat-card">
                            <div class="stat-ic stat-yellow"><i class="bi bi-clock-history"></i></div>
                            <div><div class="stat-label">Late</div><div class="stat-value"><?php echo (int)$stats['late']; ?></div></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl">
                        <div class="stat-card">
                            <div class="stat-ic stat-purple"><i class="bi bi-calendar-minus"></i></div>
                            <div><div class="stat-label">Leave</div><div class="stat-value"><?php echo (int)$stats['leave']; ?></div></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl">
                        <div class="stat-card">
                            <div class="stat-ic stat-orange"><i class="bi bi-calendar2-day"></i></div>
                            <div><div class="stat-label">Half Day</div><div class="stat-value"><?php echo (int)$stats['half_day']; ?></div></div>
                        </div>
                    </div>
                </div>

                <div class="panel mb-3">
                    <div class="panel-header">
                        <h3 class="panel-title">Filter Attendance</h3>
                    </div>

                    <form method="GET">
                        <input type="hidden" name="view" value="<?php echo e($view); ?>">

                        <div class="filter-grid">
                            <div>
                                <label class="form-label">From Date</label>
                                <input type="date" class="form-control" name="date_from" value="<?php echo e($date_from); ?>">
                            </div>

                            <div>
                                <label class="form-label">To Date</label>
                                <input type="date" class="form-control" name="date_to" value="<?php echo e($date_to); ?>">
                            </div>

                            <div>
                                <label class="form-label">Employee</label>
                                <select class="form-select" name="employee_id">
                                    <option value="0">All Employees</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo (int)$emp['id']; ?>" <?php echo ($employee_id === (int)$emp['id']) ? 'selected' : ''; ?>>
                                            <?php echo e($emp['full_name'] . ' (' . $emp['employee_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <?php
                                    $statusOptions = ['present','absent','half-day','late','holiday','leave','vacation'];
                                    foreach ($statusOptions as $st):
                                    ?>
                                        <option value="<?php echo e($st); ?>" <?php echo ($status === $st) ? 'selected' : ''; ?>>
                                            <?php echo e(ucwords(str_replace('-', ' ', $st))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Site</label>
                                <select class="form-select" name="site_id">
                                    <option value="0">All Sites</option>
                                    <?php foreach ($sites as $site): ?>
                                        <option value="<?php echo (int)$site['id']; ?>" <?php echo ($site_id === (int)$site['id']) ? 'selected' : ''; ?>>
                                            <?php echo e($site['project_name'] . (!empty($site['project_code']) ? ' (' . $site['project_code'] . ')' : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Calendar Month</label>
                                <input type="month" class="form-control" name="calendar_month" value="<?php echo e($calendar_month); ?>">
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap mt-3">
                            <button type="submit" class="btn-main">
                                <i class="bi bi-search"></i> Apply Filters
                            </button>
                            <a href="attendance.php" class="btn-lite">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset to Today
                            </a>
                            <a href="<?php echo e(buildAttendanceUrl(['date_from' => $today, 'date_to' => $today])); ?>" class="btn-lite">
                                <i class="bi bi-calendar-day"></i> Today
                            </a>
                        </div>
                    </form>
                </div>

                <?php if ($view === 'calendar'): ?>
                    <div class="panel">
                        <div class="calendar-toolbar">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <a href="<?php echo e(buildAttendanceUrl(['view' => 'calendar', 'calendar_month' => $prevCalendarMonth])); ?>" class="btn-lite">
                                    <i class="bi bi-chevron-left"></i> Prev
                                </a>
                                <h3 class="calendar-title"><?php echo e($calendarTitle); ?></h3>
                                <a href="<?php echo e(buildAttendanceUrl(['view' => 'calendar', 'calendar_month' => $nextCalendarMonth])); ?>" class="btn-lite">
                                    Next <i class="bi bi-chevron-right"></i>
                                </a>
                            </div>

                            <a href="<?php echo e(buildAttendanceUrl(['view' => 'calendar', 'calendar_month' => date('Y-m')])); ?>" class="btn-main">
                                <i class="bi bi-calendar-check"></i> Current Month
                            </a>
                        </div>

                        <div class="calendar-grid">
                            <div class="calendar-day-head">Mon</div>
                            <div class="calendar-day-head">Tue</div>
                            <div class="calendar-day-head">Wed</div>
                            <div class="calendar-day-head">Thu</div>
                            <div class="calendar-day-head">Fri</div>
                            <div class="calendar-day-head">Sat</div>
                            <div class="calendar-day-head">Sun</div>

                            <?php foreach ($calendarCells as $cellDate): ?>
                                <?php if ($cellDate === null): ?>
                                    <div class="calendar-cell empty"></div>
                                <?php else: ?>
                                    <?php
                                        $items = $calendarByDate[$cellDate] ?? [];
                                        $isToday = ($cellDate === $today);
                                    ?>
                                    <div class="calendar-cell">
                                        <div class="calendar-date">
                                            <div class="calendar-date-num <?php echo $isToday ? 'today' : ''; ?>">
                                                <?php echo (int)date('d', strtotime($cellDate)); ?>
                                            </div>
                                            <div class="calendar-count"><?php echo count($items); ?> record<?php echo count($items) === 1 ? '' : 's'; ?></div>
                                        </div>

                                        <div class="calendar-events">
                                            <?php if (empty($items)): ?>
                                                <div class="mini-text">No attendance</div>
                                            <?php else: ?>
                                                <?php foreach ($items as $item): ?>
                                                    <?php $badgeClass = attendanceBadgeClass($item['status'] ?? ''); ?>
                                                    <div class="calendar-event <?php echo e($badgeClass); ?>">
                                                        <div class="calendar-event-name"><?php echo e($item['full_name']); ?></div>
                                                        <div class="calendar-event-meta">
                                                            <?php echo e(attendanceText($item['status'] ?? '')); ?>
                                                            • In: <?php echo e(safeTime($item['punch_in_time'] ?? '')); ?>
                                                            • Hrs: <?php echo e(safeNum($item['total_hours'] ?? '0')); ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="calendar-mobile-list">
                            <?php
                            $mobileDaysShown = false;
                            foreach ($calendarCells as $cellDate):
                                if ($cellDate === null) continue;
                                $items = $calendarByDate[$cellDate] ?? [];
                                if (empty($items)) continue;
                                $mobileDaysShown = true;
                            ?>
                                <div class="calendar-day-card mb-3">
                                    <div class="calendar-day-title">
                                        <?php echo e(date('d M Y, l', strtotime($cellDate))); ?>
                                    </div>

                                    <div class="calendar-day-list">
                                        <?php foreach ($items as $item): ?>
                                            <?php $badgeClass = attendanceBadgeClass($item['status'] ?? ''); ?>
                                            <div class="calendar-event <?php echo e($badgeClass); ?>">
                                                <div class="calendar-event-name"><?php echo e($item['full_name']); ?> (<?php echo e($item['employee_code']); ?>)</div>
                                                <div class="calendar-event-meta">
                                                    <?php echo e(attendanceText($item['status'] ?? '')); ?>
                                                    • In: <?php echo e(safeTime($item['punch_in_time'] ?? '')); ?>
                                                    • Out: <?php echo e(safeTime($item['punch_out_time'] ?? '')); ?>
                                                    • Hrs: <?php echo e(safeNum($item['total_hours'] ?? '0')); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (!$mobileDaysShown): ?>
                                <div class="panel text-muted fw-bold">No attendance records found in this month.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="d-block d-md-none mb-4">
                        <?php if (empty($attendanceRows)): ?>
                            <div class="panel text-muted fw-bold">No attendance records found.</div>
                        <?php else: ?>
                            <div class="d-grid gap-3">
                                <?php foreach ($attendanceRows as $row): ?>
                                    <?php
                                        $photoSrc = fileUrl($row['photo'] ?? '');
                                        $badgeClass = attendanceBadgeClass($row['status'] ?? '');
                                    ?>
                                    <div class="att-card">
                                        <div class="d-flex justify-content-between gap-2 align-items-start">
                                            <div class="employee-box">
                                                <div class="employee-photo">
                                                    <?php if (!empty($photoSrc)): ?>
                                                        <img src="<?php echo e($photoSrc); ?>" alt="<?php echo e($row['full_name'] ?? ''); ?>">
                                                    <?php else: ?>
                                                        <?php echo strtoupper(substr((string)($row['full_name'] ?? ''), 0, 1)); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="employee-name"><?php echo e($row['full_name'] ?? ''); ?></div>
                                                    <div class="employee-sub"><?php echo e(($row['employee_code'] ?? '') . ' • ' . ($row['designation'] ?? '')); ?></div>
                                                </div>
                                            </div>

                                            <span class="status-badge <?php echo e($badgeClass); ?>">
                                                <i class="bi bi-circle-fill" style="font-size:8px;"></i>
                                                <?php echo e(attendanceText($row['status'] ?? '')); ?>
                                            </span>
                                        </div>

                                        <div class="att-grid">
                                            <div class="att-item">
                                                <div class="att-key">Date</div>
                                                <div class="att-val"><?php echo safeDate($row['attendance_date'] ?? ''); ?></div>
                                            </div>
                                            <div class="att-item">
                                                <div class="att-key">Hours</div>
                                                <div class="att-val"><?php echo e(safeNum($row['total_hours'] ?? '0')); ?></div>
                                            </div>
                                            <div class="att-item">
                                                <div class="att-key">Punch In</div>
                                                <div class="att-val"><?php echo safeDateTime($row['punch_in_time'] ?? ''); ?></div>
                                                <div class="mini-text mt-1"><?php echo e($row['punch_in_type'] ?? ''); ?> <?php echo !empty($row['punch_in_site_name']) ? '• ' . e($row['punch_in_site_name']) : ''; ?></div>
                                            </div>
                                            <div class="att-item">
                                                <div class="att-key">Punch Out</div>
                                                <div class="att-val"><?php echo safeDateTime($row['punch_out_time'] ?? ''); ?></div>
                                                <div class="mini-text mt-1"><?php echo e($row['punch_out_type'] ?? ''); ?> <?php echo !empty($row['punch_out_site_name']) ? '• ' . e($row['punch_out_site_name']) : ''; ?></div>
                                            </div>
                                            <div class="att-item" style="grid-column:1/-1;">
                                                <div class="att-key">Remarks</div>
                                                <div class="att-val"><?php echo e(trim((string)($row['remarks'] ?? '')) !== '' ? $row['remarks'] : 'No remarks'); ?></div>
                                            </div>
                                        </div>

                                        <div class="mt-3">
                                            <button
                                                type="button"
                                                class="btn-action w-100"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editAttendanceModal"
                                                data-id="<?php echo (int)$row['id']; ?>"
                                                data-status="<?php echo e($row['status'] ?? 'present'); ?>"
                                                data-remarks="<?php echo e($row['remarks'] ?? ''); ?>"
                                                data-employee="<?php echo e($row['full_name'] ?? ''); ?>"
                                                data-date="<?php echo e(safeDate($row['attendance_date'] ?? '')); ?>"
                                            >
                                                <i class="bi bi-pencil-square"></i> Update Attendance
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="panel d-none d-md-block">
                        <div class="panel-header">
                            <h3 class="panel-title">Attendance Records</h3>
                        </div>

                        <div class="table-responsive">
                            <table id="attendanceTable" class="table align-middle mb-0 dt-responsive" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Date</th>
                                        <th>Punch In</th>
                                        <th>Punch Out</th>
                                        <th>Hours</th>
                                        <th>Status</th>
                                        <th>Location</th>
                                        <th>Remarks</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendanceRows as $row): ?>
                                        <?php
                                            $photoSrc = fileUrl($row['photo'] ?? '');
                                            $badgeClass = attendanceBadgeClass($row['status'] ?? '');
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="employee-box">
                                                    <div class="employee-photo">
                                                        <?php if (!empty($photoSrc)): ?>
                                                            <img src="<?php echo e($photoSrc); ?>" alt="<?php echo e($row['full_name'] ?? ''); ?>">
                                                        <?php else: ?>
                                                            <?php echo strtoupper(substr((string)($row['full_name'] ?? ''), 0, 1)); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="employee-name"><?php echo e($row['full_name'] ?? ''); ?></div>
                                                        <div class="employee-sub"><?php echo e(($row['employee_code'] ?? '') . ' • ' . ($row['department'] ?? '')); ?></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <td data-order="<?php echo e($row['attendance_date'] ?? ''); ?>">
                                                <?php echo safeDate($row['attendance_date'] ?? ''); ?>
                                            </td>

                                            <td>
                                                <div><?php echo safeDateTime($row['punch_in_time'] ?? ''); ?></div>
                                                <div class="mini-text">
                                                    <?php echo e($row['punch_in_type'] ?? ''); ?>
                                                    <?php echo !empty($row['punch_in_site_name']) ? ' • ' . e($row['punch_in_site_name']) : ''; ?>
                                                </div>
                                            </td>

                                            <td>
                                                <div><?php echo safeDateTime($row['punch_out_time'] ?? ''); ?></div>
                                                <div class="mini-text">
                                                    <?php echo e($row['punch_out_type'] ?? ''); ?>
                                                    <?php echo !empty($row['punch_out_site_name']) ? ' • ' . e($row['punch_out_site_name']) : ''; ?>
                                                </div>
                                            </td>

                                            <td><?php echo e(safeNum($row['total_hours'] ?? '0')); ?></td>

                                            <td>
                                                <span class="status-badge <?php echo e($badgeClass); ?>">
                                                    <i class="bi bi-circle-fill" style="font-size:8px;"></i>
                                                    <?php echo e(attendanceText($row['status'] ?? '')); ?>
                                                </span>
                                            </td>

                                            <td>
                                                <div class="loc-text"><?php echo e(trim((string)($row['punch_in_location'] ?? '')) !== '' ? $row['punch_in_location'] : '—'); ?></div>
                                            </td>

                                            <td><?php echo e(trim((string)($row['remarks'] ?? '')) !== '' ? $row['remarks'] : '—'); ?></td>

                                            <td class="text-end">
                                                <button
                                                    type="button"
                                                    class="btn-action"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editAttendanceModal"
                                                    data-id="<?php echo (int)$row['id']; ?>"
                                                    data-status="<?php echo e($row['status'] ?? 'present'); ?>"
                                                    data-remarks="<?php echo e($row['remarks'] ?? ''); ?>"
                                                    data-employee="<?php echo e($row['full_name'] ?? ''); ?>"
                                                    data-date="<?php echo e(safeDate($row['attendance_date'] ?? '')); ?>"
                                                >
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if (empty($attendanceRows)): ?>
                                <div class="py-4 text-center text-muted fw-bold">No attendance records found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <?php include 'includes/footer.php'; ?>
    </main>
</div>

<div class="modal fade" id="editAttendanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" style="border:none; border-radius:18px;">
            <form method="POST">
                <input type="hidden" name="update_attendance" value="1">
                <input type="hidden" name="attendance_id" id="modal_attendance_id">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Update Attendance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <div class="small text-muted fw-bold">Employee</div>
                        <div id="modal_employee_name" class="fw-bold text-dark"></div>
                    </div>

                    <div class="mb-3">
                        <div class="small text-muted fw-bold">Date</div>
                        <div id="modal_attendance_date" class="fw-bold text-dark"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="modal_status" required>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="half-day">Half Day</option>
                            <option value="late">Late</option>
                            <option value="holiday">Holiday</option>
                            <option value="leave">Leave</option>
                            <option value="vacation">Vacation</option>
                        </select>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" id="modal_remarks" rows="4" placeholder="Enter remarks"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-lite" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-main">
                        <i class="bi bi-check2-circle"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script src="assets/js/sidebar-toggle.js"></script>

<script>
$(function () {
    if ($('#attendanceTable').length) {
        $('#attendanceTable').DataTable({
            responsive: true,
            autoWidth: false,
            pageLength: 10,
            lengthMenu: [[10,25,50,100,-1],[10,25,50,100,'All']],
            order: [[1, 'desc']],
            columnDefs: [
                { targets: [8], orderable: false, searchable: false }
            ],
            language: {
                zeroRecords: "No matching attendance records found",
                info: "Showing _START_ to _END_ of _TOTAL_ records",
                infoEmpty: "No attendance records to show",
                lengthMenu: "Show _MENU_",
                search: "Search:"
            }
        });
    }

    const editModal = document.getElementById('editAttendanceModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('modal_attendance_id').value = button.getAttribute('data-id') || '';
            document.getElementById('modal_status').value = button.getAttribute('data-status') || 'present';
            document.getElementById('modal_remarks').value = button.getAttribute('data-remarks') || '';
            document.getElementById('modal_employee_name').textContent = button.getAttribute('data-employee') || '';
            document.getElementById('modal_attendance_date').textContent = button.getAttribute('data-date') || '';
        });
    }
});
</script>
</body>
</html>
<?php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>