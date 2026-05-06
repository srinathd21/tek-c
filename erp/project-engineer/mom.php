<?php
// mom.php — Minutes of Meeting (MOM) Submission Form
// Keeps the previous mom.php structure/theme, with hierarchical header and section add buttons inside headers.

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
$designation  = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$employeeName = (string)($_SESSION['employee_name'] ?? '');

$allowed = [
    'project engineer grade 1',
    'project engineer grade 2',
    'sr. engineer',
    'team lead',
    'manager',
    'hr',
    'director',
    'qs manager',
    'qs engineer'
];

if (!in_array($designation, $allowed, true)) {
    header("Location: index.php");
    exit;
}

// ---------------- HELPERS ----------------
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function pv(string $key, $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

function saveMomDetail(
    mysqli_stmt $stmt,
    int $mainId,
    string $sectionType,
    ?string $sectionCode,
    ?string $subsectionCode,
    ?string $subsectionTitle,
    int $slNo,
    ?string $attendeeName,
    ?string $attendeeFirm,
    ?string $attendeeType,
    ?string $description,
    ?string $responsibleParty,
    ?string $deadline,
    ?string $remarks
): void {
    mysqli_stmt_bind_param(
        $stmt,
        "issssisssssss",
        $mainId,
        $sectionType,
        $sectionCode,
        $subsectionCode,
        $subsectionTitle,
        $slNo,
        $attendeeName,
        $attendeeFirm,
        $attendeeType,
        $description,
        $responsibleParty,
        $deadline,
        $remarks
    );

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Failed to save MOM detail: " . mysqli_stmt_error($stmt));
    }
}

// ---------------- Get Assigned Sites ----------------
$sites = [];

if ($designation === 'manager') {
    $q = "
        SELECT s.id, s.project_name, s.project_location, c.client_name, c.id AS client_id
        FROM sites s
        INNER JOIN clients c ON c.id = s.client_id
        WHERE s.manager_employee_id = ?
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
    $q = "
        SELECT s.id, s.project_name, s.project_location, c.client_name, c.id AS client_id
        FROM site_project_engineers spe
        INNER JOIN sites s ON s.id = spe.site_id
        INNER JOIN clients c ON c.id = s.client_id
        WHERE spe.employee_id = ?
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
$requestSiteId = isset($_POST['site_id']) ? (int)$_POST['site_id'] : (int)($_GET['site_id'] ?? 0);
$siteId = $requestSiteId;

$site = null;
$clientId = 0;

if ($siteId > 0) {
    $isAllowedSite = false;
    foreach ($sites as $s) {
        if ((int)$s['id'] === $siteId) {
            $isAllowedSite = true;
            break;
        }
    }

    if ($isAllowedSite) {
        $sql = "
            SELECT s.id, s.project_name, s.client_id, c.client_name, s.project_location
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

            if ($site) {
                $clientId = (int)$site['client_id'];
            }
        }
    }
}

// ---------------- Generate MOM Number ----------------
function generateMOMNo(mysqli $conn, int $siteId): string
{
    $year = date('Y');
    $month = date('m');
    $prefix = "MOM/{$siteId}/{$year}{$month}/";

    $st = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM mom_main WHERE mom_no LIKE ?");
    $likePattern = $prefix . '%';

    if ($st) {
        mysqli_stmt_bind_param($st, "s", $likePattern);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        $row = mysqli_fetch_assoc($res);
        mysqli_stmt_close($st);

        $nextNum = ((int)($row['cnt'] ?? 0)) + 1;
        return $prefix . str_pad((string)$nextNum, 3, '0', STR_PAD_LEFT);
    }

    return $prefix . '001';
}

// ---------------- Last Inserted ID ----------------
$lastInsertedId = null;
if (isset($_GET['saved']) && $_GET['saved'] === '1' && isset($_GET['mid'])) {
    $lastInsertedId = (int)$_GET['mid'];
}

// ---------------- Submit MOM ----------------
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_mom'])) {
    $site_id = (int)($_POST['site_id'] ?? 0);

    $okSite = false;
    foreach ($sites as $s) {
        if ((int)$s['id'] === $site_id) {
            $okSite = true;
            break;
        }
    }
    if (!$okSite) {
        $error = "Invalid site selection.";
    }

    $mom_no   = trim((string)($_POST['mom_no'] ?? ''));
    $project_name = trim((string)($_POST['project_name'] ?? ''));
    $client_name   = trim((string)($_POST['client_name'] ?? ''));
    $architects = trim((string)($_POST['architects'] ?? ''));
    $pmc = trim((string)($_POST['pmc'] ?? ''));
    $revisions = trim((string)($_POST['revisions'] ?? ''));
    $mom_date = trim((string)($_POST['mom_date'] ?? date('Y-m-d')));
    $meeting_date_place = trim((string)($_POST['meeting_date_place'] ?? ''));
    $meeting_time = trim((string)($_POST['meeting_time'] ?? ''));
    $agenda = trim((string)($_POST['agenda'] ?? ''));
    $issued_by = trim((string)($_POST['issued_by'] ?? $employeeName));
    $issued_date = trim((string)($_POST['issued_date'] ?? date('Y-m-d')));

    if ($error === '' && $site_id <= 0) $error = "Please select a site.";
    if ($error === '' && $mom_no === '') $error = "MOM No is required.";
    if ($error === '' && $mom_date === '') $error = "MOM Date is required.";
    if ($error === '' && $meeting_date_place === '') $error = "Date / Place is required.";
    if ($error === '' && $meeting_time === '') $error = "Meeting Time is required.";
    if ($error === '' && $issued_by === '') $error = "Issued By is required.";
    if ($error === '' && $issued_date === '') $error = "Issued Date is required.";
    if ($error === '' && $project_name === '') $error = "Project Name is required.";
    if ($error === '' && $client_name === '') $error = "Client Name is required.";

    if ($error === '') {
        $mom_no = $mom_no !== '' ? $mom_no : generateMOMNo($conn, $site_id);

        mysqli_begin_transaction($conn);

        try {
            $insMain = mysqli_prepare(
                $conn,
                "
                INSERT INTO mom_main
                (mom_no, site_id, client_id, project_name, client_name, architects, pmc, revisions,
                 mom_date, meeting_date_place, meeting_time, agenda, issued_by, issued_date,
                 created_by, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                "
            );

            if (!$insMain) {
                throw new Exception("Failed to prepare main insert");
            }

            mysqli_stmt_bind_param(
                $insMain,
                "siisssssssssssis",
                $mom_no,
                $site_id,
                $clientId,
                $project_name,
                $client_name,
                $architects,
                $pmc,
                $revisions,
                $mom_date,
                $meeting_date_place,
                $meeting_time,
                $agenda,
                $issued_by,
                $issued_date,
                $employeeId,
                $employeeName
            );

            if (!mysqli_stmt_execute($insMain)) {
                throw new Exception("Failed to save main record: " . mysqli_stmt_error($insMain));
            }

            $mainId = mysqli_insert_id($conn);
            mysqli_stmt_close($insMain);

            $insDetail = mysqli_prepare(
                $conn,
                "
                INSERT INTO mom_details
                (mom_main_id, section_type, section_code, subsection_code, subsection_title, sl_no,
                 attendee_name, attendee_firm, attendee_type, description, responsible_party, deadline, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                "
            );

            if (!$insDetail) {
                throw new Exception("Failed to prepare detail insert");
            }

            // Save Attendees
            $attendee_names = $_POST['attendee_name'] ?? [];
            $attendee_firms = $_POST['attendee_firm'] ?? [];
            $attendee_types = $_POST['attendee_type'] ?? [];
            $sl_no = 1;

            foreach ($attendee_names as $idx => $name) {
                $attendeeName = trim((string)$name);
                if ($attendeeName === '') {
                    continue;
                }

                $attendeeFirm = trim((string)($attendee_firms[$idx] ?? ''));
                $attendeeType = trim((string)($attendee_types[$idx] ?? 'Others'));

                saveMomDetail(
                    $insDetail,
                    $mainId,
                    'ATTENDEE',
                    '',
                    '',
                    '',
                    $sl_no,
                    $attendeeName,
                    $attendeeFirm,
                    $attendeeType,
                    null,
                    null,
                    null,
                    null
                );

                $sl_no++;
            }

            // Save Section I
            $secI_descs = $_POST['secI_desc'] ?? [];
            $secI_responsible = $_POST['secI_responsible'] ?? [];
            $secI_deadlines = $_POST['secI_deadline'] ?? [];
            $secI_remarks = $_POST['secI_remarks'] ?? [];
            $sl_no = 1;

            foreach ($secI_descs as $idx => $desc) {
                $description = trim((string)$desc);
                if ($description === '') {
                    continue;
                }

                saveMomDetail(
                    $insDetail,
                    $mainId,
                    'SECTION_I',
                    'I',
                    '',
                    '',
                    $sl_no,
                    null,
                    null,
                    null,
                    $description,
                    trim((string)($secI_responsible[$idx] ?? '')),
                    !empty($secI_deadlines[$idx]) ? $secI_deadlines[$idx] : null,
                    trim((string)($secI_remarks[$idx] ?? ''))
                );

                $sl_no++;
            }

            // Save Section II
            $secII_descs = $_POST['secII_desc'] ?? [];
            $secII_responsible = $_POST['secII_responsible'] ?? [];
            $secII_deadlines = $_POST['secII_deadline'] ?? [];
            $secII_remarks = $_POST['secII_remarks'] ?? [];
            $sl_no = 1;

            foreach ($secII_descs as $idx => $desc) {
                $description = trim((string)$desc);
                if ($description === '') {
                    continue;
                }

                saveMomDetail(
                    $insDetail,
                    $mainId,
                    'SECTION_II',
                    'II',
                    '',
                    '',
                    $sl_no,
                    null,
                    null,
                    null,
                    $description,
                    trim((string)($secII_responsible[$idx] ?? '')),
                    !empty($secII_deadlines[$idx]) ? $secII_deadlines[$idx] : null,
                    trim((string)($secII_remarks[$idx] ?? ''))
                );

                $sl_no++;
            }

            // Save Section III
            $secIII_subsections = $_POST['secIII_subsection'] ?? [];
            $secIII_subsection_titles = $_POST['secIII_subsection_title'] ?? [];
            $secIII_descs = $_POST['secIII_desc'] ?? [];
            $secIII_responsible = $_POST['secIII_responsible'] ?? [];
            $secIII_deadlines = $_POST['secIII_deadline'] ?? [];
            $secIII_remarks = $_POST['secIII_remarks'] ?? [];
            $sl_no = 1;

            foreach ($secIII_descs as $idx => $desc) {
                $description = trim((string)$desc);
                if ($description === '') {
                    continue;
                }

                saveMomDetail(
                    $insDetail,
                    $mainId,
                    'SECTION_III',
                    'III',
                    trim((string)($secIII_subsections[$idx] ?? '')),
                    trim((string)($secIII_subsection_titles[$idx] ?? '')),
                    $sl_no,
                    null,
                    null,
                    null,
                    $description,
                    trim((string)($secIII_responsible[$idx] ?? '')),
                    !empty($secIII_deadlines[$idx]) ? $secIII_deadlines[$idx] : null,
                    trim((string)($secIII_remarks[$idx] ?? ''))
                );

                $sl_no++;
            }

            // Save Section IV
            $secIV_descs = $_POST['secIV_desc'] ?? [];
            $secIV_responsible = $_POST['secIV_responsible'] ?? [];
            $secIV_deadlines = $_POST['secIV_deadline'] ?? [];
            $secIV_remarks = $_POST['secIV_remarks'] ?? [];
            $sl_no = 1;

            foreach ($secIV_descs as $idx => $desc) {
                $description = trim((string)$desc);
                if ($description === '') {
                    continue;
                }

                saveMomDetail(
                    $insDetail,
                    $mainId,
                    'SECTION_IV',
                    'IV',
                    '',
                    '',
                    $sl_no,
                    null,
                    null,
                    null,
                    $description,
                    trim((string)($secIV_responsible[$idx] ?? '')),
                    !empty($secIV_deadlines[$idx]) ? $secIV_deadlines[$idx] : null,
                    trim((string)($secIV_remarks[$idx] ?? ''))
                );

                $sl_no++;
            }

            mysqli_stmt_close($insDetail);
            mysqli_commit($conn);

            header("Location: mom.php?site_id=" . $site_id . "&saved=1&mid=" . $mainId);
            exit;
        } catch (Exception $ex) {
            mysqli_rollback($conn);
            $error = $ex->getMessage();
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success = "MOM submitted successfully.";
}

// ---------------- Get Recent MOM Records ----------------
$recent = [];
$st = mysqli_prepare(
    $conn,
    "
    SELECT m.id, m.mom_no, m.mom_date, m.project_name, m.client_name, COALESCE(dc.detail_count, 0) AS detail_count
    FROM mom_main m
    LEFT JOIN (
        SELECT mom_main_id, COUNT(*) AS detail_count
        FROM mom_details
        GROUP BY mom_main_id
    ) dc ON dc.mom_main_id = m.id
    WHERE m.created_by = ?
    ORDER BY m.created_at DESC
    LIMIT 10
    "
);
if ($st) {
    mysqli_stmt_bind_param($st, "i", $employeeId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    $recent = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($st);
}

// ---------------- Form Defaults ----------------
$formSiteId = $siteId;
$formClientId = $clientId;

$formProjectName = $site ? (string)$site['project_name'] : '';
$formClientName = $site ? (string)$site['client_name'] : '';

$formMOMDate = $_POST['mom_date'] ?? date('Y-m-d');
$formIssuedDate = $_POST['issued_date'] ?? date('Y-m-d');
$formMomNo = $_POST['mom_no'] ?? ($siteId > 0 ? generateMOMNo($conn, $siteId) : '');
$formArchitects = $_POST['architects'] ?? '';
$formPmc = $_POST['pmc'] ?? '';
$formRevisions = $_POST['revisions'] ?? '';
$formMeetingDatePlace = $_POST['meeting_date_place'] ?? '';
$formMeetingTime = $_POST['meeting_time'] ?? '';
$formAgenda = $_POST['agenda'] ?? '';
$formIssuedBy = $_POST['issued_by'] ?? $employeeName;
$formMomSharedTo = $_POST['mom_shared_to'] ?? 'All Attendees';
$formMomCopyTo = $_POST['mom_copy_to'] ?? '';
$formMomSharedBy = $_POST['mom_shared_by'] ?? $employeeName;
$formMomSharedOn = $_POST['mom_shared_on'] ?? date('Y-m-d');
$formNextMeetingDate = $_POST['next_meeting_date'] ?? '';
$formNextMeetingPlace = $_POST['next_meeting_place'] ?? '';

$defaultPmc = "M/s. UKB Construction Management Pvt Ltd";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>MOM - Minutes of Meeting | TEK-C</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

    <link href="assets/css/layout-styles.css" rel="stylesheet" />
    <link href="assets/css/topbar.css" rel="stylesheet" />
    <link href="assets/css/footer.css" rel="stylesheet" />

    <style>
        .content-scroll{
            flex:1 1 auto;
            overflow:auto;
            padding:22px 22px 14px;
        }

        .panel{
            background:#fff;
            border:1px solid #e5e7eb;
            border-radius:16px;
            box-shadow:0 10px 30px rgba(17,24,39,.05);
            padding:16px;
            margin-bottom:14px;
        }

        .title-row{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
        }

        .h-title{
            margin:0;
            font-weight:1000;
            color:#111827;
        }

        .h-sub{
            margin:4px 0 0;
            color:#6b7280;
            font-weight:800;
            font-size:13px;
        }

        .form-label{
            font-weight:900;
            color:#374151;
            font-size:13px;
            margin-bottom:6px;
        }

        .form-control,
        .form-select{
            border:2px solid #e5e7eb;
            border-radius:12px;
            padding:10px 12px;
            font-weight:750;
            font-size:14px;
        }

        .sec-head{
            display:flex;
            align-items:center;
            gap:10px;
            padding:10px 12px;
            border-radius:14px;
            background:#f9fafb;
            border:1px solid #eef2f7;
            margin-bottom:10px;
        }

        .sec-ic{
            width:34px;
            height:34px;
            border-radius:12px;
            display:grid;
            place-items:center;
            background:rgba(45,156,219,.12);
            color:var(--blue);
            flex:0 0 auto;
        }

        .sec-title{
            margin:0;
            font-weight:1000;
            color:#111827;
            font-size:14px;
        }

        .sec-sub{
            margin:2px 0 0;
            color:#6b7280;
            font-weight:800;
            font-size:12px;
        }

        .grid-2{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:12px;
        }

        .grid-3{
            display:grid;
            grid-template-columns:1fr 1fr 1fr;
            gap:12px;
        }

        @media (max-width: 992px){
            .grid-2, .grid-3{
                grid-template-columns:1fr;
            }
        }

        .table thead th{
            font-size:12px;
            color:#6b7280;
            font-weight:900;
            border-bottom:1px solid #e5e7eb !important;
            background:#f9fafb;
        }

        .btn-primary-tek{
            background:var(--blue);
            border:none;
            border-radius:12px;
            padding:10px 16px;
            font-weight:1000;
            display:inline-flex;
            align-items:center;
            gap:8px;
            box-shadow:0 12px 26px rgba(45,156,219,.18);
            color:#fff;
        }

        .btn-primary-tek:hover{
            background:#2a8bc9;
            color:#fff;
        }

        .btn-addrow{
            border-radius:12px;
            font-weight:900;
            height:42px;
            padding:0 16px;
            display:inline-flex;
            align-items:center;
            gap:8px;
            white-space:nowrap;
        }

        .badge-pill{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:6px 10px;
            border-radius:999px;
            border:1px solid #e5e7eb;
            background:#fff;
            font-weight:900;
            font-size:12px;
        }

        .small-muted{
            color:#6b7280;
            font-weight:800;
            font-size:12px;
        }

        .section-toolbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
        }

        .section-left{
            display:flex;
            align-items:center;
            gap:10px;
            min-width:0;
        }

        .subsection-toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;

    margin-top:14px;
    margin-bottom:10px;

    padding:12px 14px;

    border-radius:14px;
    border:1px solid #e5e7eb;

    background:#f9fafb;

    box-shadow:0 4px 14px rgba(17,24,39,.04);
}

.subsection-left{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
}

.subsection-ic{
    width:36px;
    height:36px;
    border-radius:12px;

    display:grid;
    place-items:center;

    background:rgba(45,156,219,.12);
    color:var(--blue);

    flex:0 0 auto;

    font-size:15px;
}

.subsection-title{
    margin:0;
    font-size:13px;
    font-weight:1000;
    color:#111827;
    line-height:1.3;
}

.subsection-sub{
    margin:2px 0 0;
    font-size:11px;
    font-weight:800;
    color:#6b7280;
}

.subsection-btn{
    height:40px;
    padding:0 15px;

    border-radius:12px;

    font-weight:900;

    display:inline-flex;
    align-items:center;
    gap:8px;
}

        .site-selector{
            background:#fff;
            border-radius:12px;
            padding:10px 12px;
            font-weight:750;
            font-size:14px;
            border:2px solid #e5e7eb;
            width:100%;
        }

        .delete-row-btn{
    width:34px;
    height:34px;

    display:inline-flex;
    align-items:center;
    justify-content:center;

    border-radius:10px;

    background:#fef2f2;
    border:1px solid #fecaca;

    color:#dc2626;

    cursor:pointer;

    transition:all .18s ease;

    font-size:15px;
}

.delete-row-btn:hover{
    background:#dc2626;
    border-color:#dc2626;
    color:#ffffff;

    transform:scale(1.05);
}

.delete-row-btn:active{
    transform:scale(.96);
}

        @media (max-width: 991.98px){
            .main{
                margin-left:0 !important;
                width:100% !important;
                max-width:100% !important;
            }
            .sidebar{
                position:fixed !important;
                transform:translateX(-100%);
                z-index:1040 !important;
            }
            .sidebar.open, .sidebar.active, .sidebar.show{
                transform:translateX(0) !important;
            }
        }

        @media (max-width: 768px){
            .content-scroll{
                padding:12px 10px 12px !important;
            }

            .container-fluid{
                padding-left:6px !important;
                padding-right:6px !important;
            }

            .panel{
                padding:12px !important;
                margin-bottom:12px;
                border-radius:14px;
            }

            .sec-head{
                padding:10px !important;
                border-radius:12px;
            }

            .section-toolbar{
                align-items:flex-start;
            }

            .btn-addrow{
                width:100%;
                justify-content:center;
            }

            .subsection-toolbar .subsection-btn{
                width:100%;
                justify-content:center;
            }
        }
    </style>
</head>

<body>
<div class="app">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main">
        <?php include 'includes/topbar.php'; ?>

        <div id="contentScroll" class="content-scroll">
            <div class="container-fluid">

                <!-- PAGE HEADER -->
                <div class="panel mb-3 header-panel">
                    <div class="section-toolbar">
                        <div class="section-left">
                            <div class="sec-ic" style="width:58px;height:58px;border-radius:16px;font-size:24px;">
                                <i class="bi bi-journal-text"></i>
                            </div>
                            <div>
                                <h1 class="h-title mb-1">MINUTES OF MEETING (MOM)</h1>
                                <p class="h-sub mb-0">Record meeting discussions, decisions, and action items</p>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap align-items-center">
                            <span class="badge-pill"><i class="bi bi-person-circle"></i> <?php echo e($employeeName); ?></span>
                            <span class="badge-pill"><i class="bi bi-award-fill"></i> <?php echo e($_SESSION['designation'] ?? ''); ?></span>
                            <a href="mom.php" class="btn btn-outline-secondary btn-addrow">
                                <i class="bi bi-arrow-clockwise"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo e($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($lastInsertedId): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius:14px;">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        MOM submitted successfully!
                        <a href="report-mom-main-print.php?view=<?php echo (int)$lastInsertedId; ?>" target="_blank" class="ms-2">Print PDF</a>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <input type="hidden" name="submit_mom" value="1">
                    <input type="hidden" name="site_id" id="site_id" value="<?php echo (int)$formSiteId; ?>">
                    <input type="hidden" name="client_id" id="client_id" value="<?php echo (int)$formClientId; ?>">
                    <input type="hidden" name="project_name" id="project_name" value="<?php echo e($formProjectName); ?>">
                    <input type="hidden" name="client_name" id="client_name" value="<?php echo e($formClientName); ?>">

                    <!-- SITE PICKER -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-geo-alt"></i></div>
                            <div>
                                <p class="sec-title mb-0">Project Selection</p>
                                <p class="sec-sub mb-0">Choose the site to prepare MOM</p>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div>
                                <label class="form-label">My Assigned Sites <span class="text-danger">*</span></label>
                                <select class="site-selector" id="sitePicker">
                                    <option value="">-- Select Site --</option>
                                    <?php foreach ($sites as $s): ?>
                                        <?php $sid = (int)$s['id']; ?>
                                        <option value="<?php echo $sid; ?>" <?php echo ($sid === $formSiteId ? 'selected' : ''); ?>>
                                            <?php echo e($s['project_name']); ?> — <?php echo e($s['project_location']); ?> (<?php echo e($s['client_name']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="small-muted mt-1">Changing the site reloads the page with that project loaded.</div>
                            </div>

                        </div>
                    </div>

                    <!-- PROJECT INFORMATION -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-building"></i></div>
                            <div>
                                <p class="sec-title mb-0">Project Information</p>
                                <p class="sec-sub mb-0">Auto-filled from selected site</p>
                            </div>
                        </div>

                        <?php if (!$site): ?>
                            <div class="text-muted" style="font-weight:800;">Please select a site above to load project information.</div>
                        <?php else: ?>
                            <div class="grid-2">
                                <div>
                                    <div class="small-muted">Project</div>
                                    <div style="font-weight:1000;"><?php echo e($site['project_name']); ?></div>
                                </div>
                                <div>
                                    <div class="small-muted">Client</div>
                                    <div style="font-weight:1000;"><?php echo e($site['client_name']); ?></div>
                                </div>
                            </div>

                            <hr style="border-color:#eef2f7;">

                            <div class="grid-2">
                                <div>
                                    <label class="form-label">Architects / Consultants</label>
                                    <input type="text" class="form-control" name="architects" value="<?php echo pv('architects', $formArchitects); ?>" placeholder="Enter architects / consultants">
                                </div>
                                <div>
                                    <label class="form-label">PMC</label>
                                    <input type="text" class="form-control" name="pmc" value="<?php echo pv('pmc', $defaultPmc); ?>" placeholder="Enter PMC">
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- MOM HEADER -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-file-earmark-text"></i></div>
                            <div>
                                <p class="sec-title mb-0">MOM Header</p>
                                <p class="sec-sub mb-0">MOM No / Revision + Meeting Date</p>
                            </div>
                        </div>

                        <div class="grid-3">
                            <div>
                                <label class="form-label">MOM No / Revision</label>
                                <input type="text" class="form-control" name="revisions" placeholder="e.g., MOM #22" value="<?php echo pv('revisions', $formRevisions); ?>">
                            </div>
                            <div>
                                <label class="form-label">MOM Date</label>
                                <input type="date" class="form-control" name="mom_date" value="<?php echo pv('mom_date', $formMOMDate); ?>">
                            </div>
                            <div>
                                <label class="form-label">Issued Date</label>
                                <input type="date" class="form-control" name="issued_date" value="<?php echo pv('issued_date', $formIssuedDate); ?>">
                            </div>
                        </div>

                        <div class="grid-2 mt-3">
                            <div>
                                <label class="form-label">Date / Place</label>
                                <input type="text" class="form-control" name="meeting_date_place" placeholder="e.g., 03-01-2026 & 04-01-2026 / Site" value="<?php echo pv('meeting_date_place', $formMeetingDatePlace); ?>">
                            </div>
                            <div>
                                <label class="form-label">Meeting Time</label>
                                <input type="text" class="form-control" name="meeting_time" placeholder="e.g., 12.00 PM to 6.30 PM" value="<?php echo pv('meeting_time', $formMeetingTime); ?>">
                            </div>
                        </div>

                        <div class="grid-2 mt-3">
                            <div>
                                <label class="form-label">Issued By</label>
                                <input type="text" class="form-control" name="issued_by" value="<?php echo pv('issued_by', $formIssuedBy); ?>">
                            </div>
                            <div>
                                <label class="form-label">Prepared By</label>
                                <input type="text" class="form-control" value="<?php echo e($employeeName); ?>" readonly>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Agenda</label>
                            <textarea class="form-control" name="agenda" rows="2"><?php echo pv('agenda', $formAgenda); ?></textarea>
                        </div>
                    </div>

                    <!-- ATTENDEES -->
                    <div class="panel">
                        <div class="sec-head section-toolbar">
                            <div class="section-left">
                                <div class="sec-ic"><i class="bi bi-people"></i></div>
                                <div>
                                    <p class="sec-title mb-0">Attendees</p>
                                    <p class="sec-sub mb-0">Meeting participants list</p>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-addrow" onclick="addAttendeeRow()">
                                <i class="bi bi-plus-circle"></i> Add Attendee
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">SL NO</th>
                                        <th>ATTENDEE NAME</th>
                                        <th>FIRM</th>
                                        <th>TYPE</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="attendeesBody">
                                    <tr class="attendee-row">
                                        <td><input type="number" class="form-control sl-no" value="1" readonly style="width:70px;"></td>
                                        <td><input type="text" class="form-control" name="attendee_name[]" value=""></td>
                                        <td><input type="text" class="form-control" name="attendee_firm[]" value="" placeholder="Firm"></td>
                                        <td>
                                            <select class="form-select" name="attendee_type[]">
                                                <option>Client</option>
                                                <option>Architect</option>
                                                <option>Contractor</option>
                                                <option>PMC</option>
                                                <option>Vendor</option>
                                                <option>Others</option>
                                            </select>
                                        </td>
                                        <td class="text-center"><i class="bi bi-trash delete-row-btn delete-row-btn"></i></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                    </div>

                    <!-- SECTION I -->
                    <div class="panel">
                        <div class="sec-head section-toolbar">
                            <div class="section-left">
                                <div class="sec-ic"><i class="bi bi-building"></i></div>
                                <div>
                                    <p class="sec-title mb-0">I. Architects / Consultants Deliverables</p>
                                    <p class="sec-sub mb-0">Discussion and action items</p>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-addrow" onclick="addRow('secIBody', 'secI-row')">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">SL NO</th>
                                        <th>DESCRIPTION</th>
                                        <th style="width:150px;">ACTION BY</th>
                                        <th style="width:120px;">DEADLINE</th>
                                        <th style="width:150px;">REMARKS</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="secIBody">
                                    <tr class="secI-row">
                                        <td><input type="number" class="form-control sl-no" value="1" readonly style="width:70px;"></td>
                                        <td><textarea class="form-control" name="secI_desc[]" rows="2"></textarea></td>
                                        <td><input type="text" class="form-control" name="secI_responsible[]"></td>
                                        <td><input type="date" class="form-control" name="secI_deadline[]"></td>
                                        <td><input type="text" class="form-control" name="secI_remarks[]"></td>
                                        <td class="text-center"><i class="bi bi-trash delete-row-btn"></i></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- SECTION II -->
                    <div class="panel">
                        <div class="sec-head section-toolbar">
                            <div class="section-left">
                                <div class="sec-ic"><i class="bi bi-diagram-3"></i></div>
                                <div>
                                    <p class="sec-title mb-0">II. PMC Deliverables</p>
                                    <p class="sec-sub mb-0">PMC related discussion points</p>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-addrow" onclick="addRow('secIIBody', 'secII-row')">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">SL NO</th>
                                        <th>DESCRIPTION</th>
                                        <th style="width:150px;">ACTION BY</th>
                                        <th style="width:120px;">DEADLINE</th>
                                        <th style="width:150px;">REMARKS</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="secIIBody">
                                    <tr class="secII-row">
                                        <td><input type="number" class="form-control sl-no" value="1" readonly style="width:70px;"></td>
                                        <td><textarea class="form-control" name="secII_desc[]" rows="2"></textarea></td>
                                        <td><input type="text" class="form-control" name="secII_responsible[]"></td>
                                        <td><input type="date" class="form-control" name="secII_deadline[]"></td>
                                        <td><input type="text" class="form-control" name="secII_remarks[]"></td>
                                        <td class="text-center"><i class="bi bi-trash delete-row-btn"></i></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- SECTION III -->
                    <div class="panel">
                        <div class="sec-head">
                            <div class="sec-ic"><i class="bi bi-tools"></i></div>
                            <div>
                                <p class="sec-title mb-0">III. Contractors / Vendors Deliverables</p>
                                <p class="sec-sub mb-0">Vendor and contractor activities</p>
                            </div>
                        </div>

                        <?php
                        $subsections = [
                            'A' => 'CAPITAL INTERIORS & CONSTRUCTORS (CIVIL WORK)',
                            'B' => 'MADURAI AIR SYSTEM (HVAC WORK)',
                            'C' => 'MIRACLE MARBLES (FLOORING WORK)',
                            'D' => 'SANKAR ELECTRICALS (ELECTRICAL WORK)',
                            'E' => 'PR PLUMBING WORKS (PLUMBING WORK)',
                            'F' => 'CRESCENT ENTERPRISES WORKS (FABRICATION WORK)',
                            'G' => 'JP INTERIOR (PLUNNING & FALSE CEILING WORK)'
                        ];
                        foreach ($subsections as $code => $title):
                        ?>
                            <div class="subsection-toolbar">

    <div class="subsection-left">

        <div class="subsection-ic">
            <i class="bi bi-folder2-open"></i>
        </div>

        <div>
            <p class="subsection-title mb-0">
                <?php echo e($code); ?>. <?php echo e($title); ?>
            </p>

            <p class="subsection-sub mb-0">
                Contractor / Vendor Discussion Items
            </p>
        </div>

    </div>

    <button type="button"
            class="btn btn-outline-primary subsection-btn"
            onclick="addRow('secIII<?php echo e($code); ?>', 'secIII-row')">

        <i class="bi bi-plus-circle"></i> Add Item

    </button>

</div>

                            <div class="table-responsive">
                                <table class="table table-bordered align-middle mt-2 mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width:60px;">SL NO</th>
                                            <th>DESCRIPTION</th>
                                            <th style="width:150px;">ACTION BY</th>
                                            <th style="width:120px;">DEADLINE</th>
                                            <th style="width:150px;">REMARKS</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="secIII<?php echo e($code); ?>">
                                        <tr class="secIII-row">
                                            <td><input type="number" class="form-control sl-no" value="1" readonly style="width:70px;"></td>
                                            <td><textarea class="form-control" name="secIII_desc[]" rows="2"></textarea></td>
                                            <td><input type="text" class="form-control" name="secIII_responsible[]"></td>
                                            <td><input type="date" class="form-control" name="secIII_deadline[]"></td>
                                            <td><input type="text" class="form-control" name="secIII_remarks[]"></td>
                                            <td class="text-center"><i class="bi bi-trash delete-row-btn"></i></td>
                                            <input type="hidden" name="secIII_subsection[]" value="<?php echo e($code); ?>">
                                            <input type="hidden" name="secIII_subsection_title[]" value="<?php echo e($title); ?>">
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- SECTION IV -->
                    <div class="panel">
                        <div class="sec-head section-toolbar">
                            <div class="section-left">
                                <div class="sec-ic"><i class="bi bi-grid"></i></div>
                                <div>
                                    <p class="sec-title mb-0">IV. Others</p>
                                    <p class="sec-sub mb-0">Additional discussion points</p>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-addrow" onclick="addRow('secIVBody', 'secIV-row')">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </button>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:60px;">SL NO</th>
                                        <th>DESCRIPTION</th>
                                        <th style="width:150px;">ACTION BY</th>
                                        <th style="width:120px;">DEADLINE</th>
                                        <th style="width:150px;">REMARKS</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="secIVBody">
                                    <tr class="secIV-row">
                                        <td><input type="number" class="form-control sl-no" value="1" readonly style="width:70px;"></td>
                                        <td><textarea class="form-control" name="secIV_desc[]" rows="2"></textarea></td>
                                        <td><input type="text" class="form-control" name="secIV_responsible[]"></td>
                                        <td><input type="date" class="form-control" name="secIV_deadline[]"></td>
                                        <td><input type="text" class="form-control" name="secIV_remarks[]"></td>
                                        <td class="text-center"><i class="bi bi-trash delete-row-btn"></i></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn-primary-tek" <?php echo ($formSiteId <= 0 ? 'disabled' : ''); ?>>
                                <i class="bi bi-save"></i> Submit MOM
                            </button>
                        </div>

                        <?php if ($formSiteId <= 0): ?>
                            <div class="small-muted mt-2"><i class="bi bi-info-circle"></i> Select a site above to enable submit.</div>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- RECENT MOM -->
                <div class="panel">
                    <div class="sec-head">
                        <div class="sec-ic"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <p class="sec-title mb-0">Recent MOM Submissions</p>
                            <p class="sec-sub mb-0">Your last submissions</p>
                        </div>
                    </div>

                    <?php if (empty($recent)): ?>
                        <div class="text-muted" style="font-weight:800;">No MOM submitted yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>MOM No</th>
                                        <th>Date</th>
                                        <th>Project</th>
                                        <th>Client</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r): ?>
                                        <tr>
                                            <td style="font-weight:1000;"><?php echo e($r['mom_no']); ?></td>
                                            <td><?php echo e(date('d-m-Y', strtotime($r['mom_date']))); ?></td>
                                            <td><?php echo e($r['project_name']); ?></td>
                                            <td><?php echo e($r['client_name']); ?></td>
                                            <td>
                                                <a href="report-mom-main-print.php?view=<?php echo (int)$r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    Print
                                                </a>
                                            </td>
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
document.addEventListener('DOMContentLoaded', function () {
    var picker = document.getElementById('sitePicker');
    if (picker) {
        picker.addEventListener('change', function () {
            var v = picker.value || '';
            window.location.href = v ? ('mom.php?site_id=' + encodeURIComponent(v)) : 'mom.php';
        });
    }

    function renumberRows(tbodyId) {
        const tb = document.getElementById(tbodyId);
        if (!tb) return;

        const rows = tb.querySelectorAll('tr');
        rows.forEach((tr, idx) => {
            const sl = tr.querySelector('.sl-no');
            if (sl) sl.value = idx + 1;
        });
    }

    function clearRow(tr) {
        tr.querySelectorAll('input,select,textarea').forEach(el => {
            if (el.type === 'hidden') return;
            if (el.tagName === 'SELECT') {
                el.selectedIndex = 0;
            } else {
                el.value = '';
            }
        });
    }

    function addRow(tbodyId, rowClass) {
        const tb = document.getElementById(tbodyId);
        if (!tb) return;

        const template = tb.querySelector('.' + rowClass);
        if (!template) return;

        const tr = template.cloneNode(true);
        clearRow(tr);
        tb.appendChild(tr);
        renumberRows(tbodyId);
    }

    window.addAttendeeRow = function () {
        addRow('attendeesBody', 'attendee-row');
    };

    window.addRow = function (tbodyId, rowClass) {
        addRow(tbodyId, rowClass);
    };

    document.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.delete-row-btn');
        if (!btn) return;

        const tr = btn.closest('tr');
        if (!tr) return;

        const tb = tr.parentNode;
        if (!tb) return;

        const rowCount = tb.querySelectorAll('tr').length;
        if (rowCount <= 1) {
            clearRow(tr);
            return;
        }

        tr.remove();

        if (tb.id === 'attendeesBody') renumberRows('attendeesBody');
        if (tb.id === 'secIBody') renumberRows('secIBody');
        if (tb.id === 'secIIBody') renumberRows('secIIBody');
        if (tb.id === 'secIVBody') renumberRows('secIVBody');

        if (tb.id && tb.id.startsWith('secIII')) {
            renumberRows(tb.id);
        }
    });
});
</script>
</body>
</html>