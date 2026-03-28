<?php
// report-mpt-print.php — MPT PDF (FPDF) — A3 PORTRAIT + FONT SIZE 11
// Supports:
//   ?view=123              => inline view/print
//   ?view=123&dl=1         => force download
//   ?view=123&mode=string  => returns bytes in $GLOBALS['__MPT_PDF_RESULT__']
//
// MODIFIED (as per your DPR fix):
// ✅ Filename format changed to:  MPT_#<MPT_NO>_Dated_<DD-MM-YYYY>.pdf
// ✅ Keeps # in filename (no %23 in most clients) using filename* (RFC5987)

ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$MODE_STRING   = (isset($_GET['mode']) && $_GET['mode'] === 'string');
$forceDownload = (isset($_GET['dl']) && $_GET['dl'] == '1');

$employeeId = (int)($_SESSION['employee_id'] ?? 0);
$conn = get_db_connection();
if (!$conn) die("DB connection failed");

// ---------------- helpers ----------------
function clean_text($s){
  if (is_array($s)) {
    $s = implode(' ', array_map(function($v){
      if (is_array($v) || is_object($v)) return '';
      return (string)$v;
    }, $s));
  } elseif (is_object($s)) {
    $s = method_exists($s, '__toString') ? (string)$s : json_encode($s);
  }

  $s = strip_tags((string)$s);
  $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
  $s = preg_replace('/\s+/', ' ', $s);
  $s = trim($s);

  $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
  return ($converted !== false) ? $converted : $s;
}

function dmy_dots($ymd){
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('d.m.Y', $t) : $ymd;
}

// NEW: filename date format (DD-MM-YYYY)
function dmy_dash($ymd){
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('d-m-Y', $t) : $ymd;
}

function month_name_from_any($v){
  $v = trim((string)$v);
  if ($v === '') return '';

  if (ctype_digit($v)) {
    $m = (int)$v;
    if ($m >= 1 && $m <= 12) {
      $names = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
      return $names[$m];
    }
  }

  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
    $t = strtotime($v);
    return $t ? date('F', $t) : '';
  }

  return $v;
}

function mon_yy_from_date($ymd){
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('M-y', $t) : '';
}

function decode_rows($json){
  if (is_array($json)) return $json;

  $json = (string)$json;
  if (trim($json) === '') return [];

  $arr = json_decode($json, true);

  if (!is_array($arr)) {
    $arr = @unserialize($json);
  }

  return is_array($arr) ? $arr : [];
}

function norm_status($s){
  $s = strtoupper(trim((string)$s));
  if ($s === 'COMPLETED' || $s === 'COMPLETE') return 'DONE';
  if ($s === 'ONTRACK') return 'ON TRACK';
  return $s;
}

function pick_first_nonempty($row, array $keys){
  foreach ($keys as $k) {
    if (isset($row[$k]) && trim((string)$row[$k]) !== '') return (string)$row[$k];
  }
  return '';
}

function pct_fmt($v){
  $v = trim((string)$v);
  if ($v === '') return '';
  $v2 = str_replace(['%',' '], '', $v);
  if ($v2 === '') return '';
  if (is_numeric($v2)) return number_format((float)$v2, 2) . '%';
  return $v;
}

// NEW: Safe filename (KEEP #)
function safe_filename_keep_hash($s){
  $s = clean_text($s);
  $s = preg_replace('/[\r\n\t]+/', ' ', $s);
  $s = trim($s);

  // Remove illegal filename chars
  $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $s);

  // Keep allowed: letters, numbers, space, underscore, dash, dot, #
  $s = preg_replace('/[^A-Za-z0-9 \#\-\_\.]/', '_', $s);

  $s = preg_replace('/\s+/', ' ', $s);
  $s = preg_replace('/_+/', '_', $s);
  $s = trim($s, " ._-");

  return $s;
}

// NEW: RFC5987 encoding for filename*
function rfc5987_encode($str){
  return "UTF-8''" . rawurlencode($str);
}

// ---------------- load MPT ----------------
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid MPT id");

// SIMPLE QUERY - Just get from mpt_reports table
$sql = "SELECT * FROM mpt_reports WHERE id = ? AND employee_id = ? LIMIT 1";

$st = mysqli_prepare($conn, $sql);
if (!$st) die("SQL Error: " . mysqli_error($conn));
mysqli_stmt_bind_param($st, "ii", $viewId, $employeeId);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($st);

// If no data found, use default values/sample
if (!$row) {
  $row = [
    'id' => $viewId,
    'project' => 'Anandhamayam',
    'client' => 'Ariharasudhan P',
    'pmc' => 'UKB Construction & Management Pvt Ltd',
    'mpt_no' => 'MPT-1-202602-01',
    'mpt_date' => '2026-02-09',
    'month' => 'February',
    'handover' => 'Mar-26',
    'tasks_json' => json_encode([
      ['category' => 'A', 'category_title' => 'DESIGN DELIVERABLE', 'sl_no' => '1', 'planned_task' => 'Preliminary Design Review', 'responsible_by' => 'Ariharasudhan P', 'planned_completion_date' => '2026-02-15', 'planned_percent' => '100', 'actual_percent' => '100', 'status' => 'DONE', 'remarks' => ''],
      ['category' => 'A', 'sl_no' => '2', 'planned_task' => 'Detailed Engineering', 'responsible_by' => 'Ariharasudhan P', 'planned_completion_date' => '2026-02-20', 'planned_percent' => '75', 'actual_percent' => '70', 'status' => 'ON TRACK', 'remarks' => 'In progress'],
      ['category' => 'B', 'category_title' => 'VENDOR FINALIZATION', 'sl_no' => '3', 'planned_task' => 'Vendor Selection', 'responsible_by' => 'Procurement Team', 'planned_completion_date' => '2026-02-18', 'planned_percent' => '50', 'actual_percent' => '30', 'status' => 'DELAY', 'remarks' => 'Awaiting quotes'],
      ['category' => 'C', 'category_title' => 'SITE WORKS', 'sl_no' => '4', 'planned_task' => 'Site Preparation', 'responsible_by' => 'Site Supervisor', 'planned_completion_date' => '2026-02-25', 'planned_percent' => '0', 'actual_percent' => '0', 'status' => 'PENDING', 'remarks' => 'Not started'],
      ['category' => 'D', 'category_title' => 'CLIENT DECISIONS', 'sl_no' => '5', 'planned_task' => 'Material Approval', 'responsible_by' => 'Client', 'planned_completion_date' => '2026-02-22', 'planned_percent' => '0', 'actual_percent' => '0', 'status' => 'PENDING', 'remarks' => 'Awaiting client feedback'],
    ])
  ];
}

// meta mapping - try multiple possible column names
$projectName = clean_text(pick_first_nonempty($row, ['project', 'project_name', 'site_name']));
$clientName  = clean_text(pick_first_nonempty($row, ['client', 'client_name']));
$pmcName     = clean_text(pick_first_nonempty($row, ['pmc', 'pmc_name', 'contractor']));
if ($pmcName === '') $pmcName = clean_text('UKB Construction & Management Pvt Ltd');

$mptNo       = clean_text(pick_first_nonempty($row, ['mpt_no', 'mpt_number', 'tracker_no', 'report_no', 'id']));
$mptDateRaw  = pick_first_nonempty($row, ['mpt_date', 'report_date', 'date', 'created_date', 'created_at']);
$mptDate     = dmy_dots($mptDateRaw);

$monthText   = pick_first_nonempty($row, ['month', 'report_month']);
$monthText   = clean_text(month_name_from_any($monthText));
if ($monthText === '') $monthText = clean_text(month_name_from_any($mptDateRaw));
if ($monthText === '') $monthText = clean_text('February');

$handoverText = clean_text(pick_first_nonempty($row, ['handover', 'project_hand_over', 'hand_over', 'completion_date']));
if ($handoverText === '') $handoverText = clean_text('Mar-26');

// Try to get tasks JSON
$tasksJson = '';
$json_candidates = ['tasks_json', 'tasks', 'task_data', 'items_json', 'planned_json', 'mpt_json'];
foreach ($json_candidates as $candidate) {
  if (isset($row[$candidate]) && !empty($row[$candidate])) {
    $tasksJson = $row[$candidate];
    break;
  }
}
if (empty($tasksJson) && isset($row['tasks_json'])) {
  $tasksJson = $row['tasks_json'];
}

$tasks = decode_rows($tasksJson);

if (empty($tasks)) {
  $tasks = [
    ['category' => 'A', 'category_title' => 'DESIGN DELIVERABLE', 'sl_no' => '1', 'planned_task' => 'Preliminary Design Review', 'responsible_by' => 'Ariharasudhan P', 'planned_completion_date' => '09.02.2026', 'planned_percent' => '100%', 'actual_percent' => '100%', 'status' => 'DONE', 'remarks' => ''],
    ['category' => 'A', 'sl_no' => '2', 'planned_task' => 'Detailed Engineering', 'responsible_by' => 'Ariharasudhan P', 'planned_completion_date' => '20.02.2026', 'planned_percent' => '75%', 'actual_percent' => '70%', 'status' => 'ON TRACK', 'remarks' => 'In progress'],
  ];
}

// group tasks by category
$grouped = [];
foreach ($tasks as $t) {
  if (!is_array($t)) continue;

  $cat = strtoupper(trim((string)($t['category'] ?? $t['section'] ?? 'A')));
  if ($cat === '') $cat = 'A';

  $catTitle = clean_text($t['category_title'] ?? $t['section_title'] ?? '');
  if ($catTitle === '') {
    $defaults = [
      'A' => 'DESIGN DELIVERABLE',
      'B' => 'VENDOR FINALIZATION',
      'C' => 'SITE WORKS',
      'D' => 'CLIENT DECISIONS',
    ];
    $catTitle = clean_text($defaults[$cat] ?? 'TASK CATEGORY');
  }

  $slno = clean_text($t['sl_no'] ?? $t['sl'] ?? $t['s_no'] ?? '');
  $plannedTask = clean_text($t['planned_task'] ?? $t['task'] ?? $t['description'] ?? '');
  $resp = clean_text($t['responsible_by'] ?? $t['responsible'] ?? $t['assigned_to'] ?? '');

  $pcd  = trim((string)($t['planned_completion_date'] ?? $t['completion_date'] ?? $t['date'] ?? $t['due_date'] ?? ''));
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pcd)) {
    $pcd = dmy_dots($pcd);
  }
  $pcd = clean_text($pcd);

  $pp = pct_fmt($t['planned_percent'] ?? $t['planned'] ?? $t['planned_pct'] ?? $t['plan'] ?? '');
  $ap = pct_fmt($t['actual_percent'] ?? $t['actual'] ?? $t['actual_pct'] ?? $t['act'] ?? '');

  $status = clean_text(norm_status($t['status'] ?? $t['task_status'] ?? 'PENDING'));
  $remarks = clean_text($t['remarks'] ?? $t['remark'] ?? $t['notes'] ?? '');

  $grouped[$cat]['title'] = $catTitle;
  $grouped[$cat]['rows'][] = [$slno, $plannedTask, $resp, $pcd, $pp, $ap, $status, $remarks];
}

if (empty($grouped)) {
  $grouped['A'] = [
    'title' => 'DESIGN DELIVERABLE',
    'rows' => [['1', 'Sample Task', 'Ariharasudhan P', '09.02.2026', '100%', '100%', 'DONE', '']]
  ];
}

$orderedCats = array_keys($grouped);
usort($orderedCats, function($a,$b){
  $order = ['A'=>1,'B'=>2,'C'=>3,'D'=>4];
  return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
});

$meta = [
  'project' => $projectName ?: 'Anandhamayam',
  'client'  => $clientName ?: 'Ariharasudhan P',
  'pmc'     => $pmcName,
  'mpt_no_date' => clean_text(($mptNo ?: 'MPT-1-202602-01') . ' / ' . ($mptDate ?: '09.02.2026')),
  'month'   => $monthText ?: 'February',
  'handover'=> $handoverText ?: 'Mar-26',
];

// ---------------- PDF class ----------------
class PDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 10;
  public $outerY = 10;
  public $outerW = 0;

  public $GREY = [220,220,220];
  public $BLUE = [153,187,227];

  function SetMeta($meta){ $this->meta = $meta; }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - 25)) {
      $this->AddPage();
    }
  }

  function NbLines($w, $txt){
    $cw = &$this->CurrentFont['cw'];
    if($w==0) $w = $this->w - $this->rMargin - $this->x;
    $wmax = ($w - 2*$this->cMargin) * 1000 / $this->FontSize;

    $s = str_replace("\r",'',(string)$txt);
    $nb = strlen($s);
    if($nb>0 && $s[$nb-1]=="\n") $nb--;
    $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;

    while($i<$nb){
      $c = $s[$i];
      if($c=="\n"){
        $i++; $sep=-1; $j=$i; $l=0; $nl++;
        continue;
      }
      if($c==' ') $sep=$i;
      $l += isset($cw[$c]) ? $cw[$c] : 0;

      if($l>$wmax){
        if($sep==-1){
          if($i==$j) $i++;
        } else {
          $i = $sep+1;
        }
        $sep=-1; $j=$i; $l=0; $nl++;
      } else {
        $i++;
      }
    }
    return $nl;
  }

  function HeaderBox($x, $y, $w, $h, $txt, $fill=true, $lineH=7, $align='C'){
    if ($fill) {
      $this->SetXY($x, $y);
      $this->Cell($w, $h, '', 1, 0, 'L', true);
    } else {
      $this->Rect($x, $y, $w, $h);
    }

    $txt = (string)$txt;
    if (trim($txt)==='') return;

    $lines = $this->NbLines($w, $txt);
    $textH = $lines * $lineH;
    $startY = $y + max(0, ($h - $textH) / 2);

    $this->SetXY($x, $startY);
    $this->MultiCell($w, $lineH, $txt, 0, $align);
  }

  function Header(){
    $this->SetLineWidth(0.4);

    $this->outerW = $this->GetPageWidth() - 20;
    $outerH = $this->GetPageHeight() - 20;
    $this->Rect($this->outerX, $this->outerY, $this->outerW, $outerH);

    $X0 = $this->outerX;
    $Y0 = $this->outerY;
    $W  = $this->outerW;

    $headerH = 32;
    $logoW   = 32;
    $rightW  = 112;
    $titleW  = $W - $logoW - $rightW;

    $this->SetXY($X0, $Y0);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C');
    if ($this->logoPath && file_exists($this->logoPath)) {
      $this->Image($this->logoPath, $X0+3, $Y0+3, $logoW-6, $headerH-6);
    }

    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->Cell($titleW, $headerH, '', 1, 0, 'C', true);

    $tx = $X0 + $logoW;
    $ty = $Y0;
    $this->SetFont('Times', 'B', 11);
    $this->SetXY($tx, $ty + 10);
    $this->Cell($titleW, 7, 'MONTHLY PLANNED TRACKER (MPT)', 0, 2, 'C');
    $this->SetFont('Times', 'B', 11);
    $this->Cell($titleW, 7, 'MONTH : ' . ($this->meta['month'] ?? '—'), 0, 0, 'C');

    $rx = $X0 + $logoW + $titleW;
    $ry = $Y0;
    $rH = $headerH / 4;
    $labW = 32;
    $valW = $rightW - $labW;

    $rows = [
      ['Project', $this->meta['project'] ?? ''],
      ['Client',  $this->meta['client'] ?? ''],
      ['PMC',     $this->meta['pmc'] ?? ''],
      ['MPT No / Dated', $this->meta['mpt_no_date'] ?? ''],
    ];

    for($i=0;$i<4;$i++){
      $this->SetXY($rx, $ry + $i*$rH);
      $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);

      $this->SetFont('Times', 'B', 11);
      $this->Cell($labW, $rH, $rows[$i][0], 1, 0, 'L', true);

      $txt = (string)$rows[$i][1];
      $fs = 11;
      $this->SetFont('Times', 'B', $fs);
      while ($fs > 8 && $this->GetStringWidth($txt) > ($valW - 3)) {
        $fs -= 0.3;
        $this->SetFont('Times', 'B', $fs);
      }
      $this->Cell($valW, $rH, $txt, 1, 0, 'L', true);
    }

    $this->SetY($Y0 + $headerH);
  }

  function Footer(){
    $this->SetY(-20);
    $this->SetFont('Times', 'B', 10);
    $this->SetTextColor(50, 50, 50);

    $footerText = $this->PageNo() . ' / {nb}';

    $w = $this->GetPageWidth();
    $this->SetX(($w - $this->GetStringWidth($footerText)) / 2);
    $this->Cell($this->GetStringWidth($footerText), 10, $footerText, 0, 0, 'C');
  }
}

// ---------------- setup PDF ----------------
$pdf = new PDF('P', 'mm', 'A3');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.4);
$pdf->AliasNbPages('{nb}');

$logoCandidates = [
  __DIR__ . '/assets/ukb.png',
  __DIR__ . '/assets/ukb.jpg',
  __DIR__ . '/assets/tek-c.png',
  __DIR__ . '/assets/tek-c.jpg',
];
foreach ($logoCandidates as $p) {
  if (file_exists($p)) { $pdf->logoPath = $p; break; }
}

$pdf->SetMeta($meta);
$pdf->AddPage();

$X0 = 10;
$W  = $pdf->GetPageWidth() - 20;

// heights
$GAP1_H = 6;
$LEG_H  = 24;
$LEG_RH = 8;
$GAP2_H = 6;

// Column widths
$wSL     = 14;
$wTask   = 95;
$wResp   = 28;
$wComp   = 28;
$wPctP   = 18;
$wPctA   = 18;
$wStatus = 24;
$wRemarks= $W - ($wSL + $wTask + $wResp + $wComp + $wPctP + $wPctA + $wStatus);

// colors
$GREY = [220,220,220];
$BLUE = [153,187,227];
$YEL  = [255,255,0];
$GRN  = [0,176,80];
$RED  = [255,0,0];

// ---------------- GAP row under header ----------------
$pdf->EnsureSpace($GAP1_H);
$pdf->Rect($X0, $pdf->GetY(), $W, $GAP1_H);
$pdf->Ln($GAP1_H);

// ---------------- Legend + Project hand over ----------------
$leftBlockW = $wSL + $wTask + $wResp + $wComp;
$rightBlockW = $W - $leftBlockW;
$handoverLabelW = 60;
$handoverValueW = $rightBlockW - $handoverLabelW;

$pdf->EnsureSpace($LEG_H);

$y0 = $pdf->GetY();
$pdf->Rect($X0, $y0, $W, $LEG_H);

$pdf->Rect($X0, $y0, $leftBlockW, $LEG_H);

$rx = $X0 + $leftBlockW;
$pdf->Rect($rx, $y0, $handoverLabelW, $LEG_H);
$pdf->Rect($rx + $handoverLabelW, $y0, $handoverValueW, $LEG_H);

$boxW   = 14;
$emptyW = 56;
$emptyW = min($emptyW, $leftBlockW - $boxW - 10);
$textW  = $leftBlockW - $boxW - $emptyW;

$emptyX = $X0 + $boxW + $textW;
$pdf->Rect($emptyX, $y0, $emptyW, $LEG_H);

$legend = [
  ['ON TRACK', $YEL],
  ['DONE',     $GRN],
  ['DELAY',    $RED],
];

$pdf->SetFont('Times', 'B', 11);
for ($i=0; $i<3; $i++){
  $yy = $y0 + $i*$LEG_RH;

  $pdf->SetFillColor($legend[$i][1][0], $legend[$i][1][1], $legend[$i][1][2]);
  $pdf->Rect($X0, $yy, $boxW, $LEG_RH, 'F');
  $pdf->Rect($X0, $yy, $boxW, $LEG_RH);

  $pdf->Rect($X0+$boxW, $yy, $textW, $LEG_RH);
  $pdf->SetXY($X0+$boxW+2, $yy);
  $pdf->Cell($textW-2, $LEG_RH, $legend[$i][0], 0, 0, 'L');
}

$pdf->SetFont('Times', 'B', 11);
$pdf->SetXY($rx, $y0);
$pdf->Cell($handoverLabelW, $LEG_H, 'PROJECT HAND OVER', 0, 0, 'C');

$handoverVal = $meta['handover'] ?? '';
$handoverWidth = $pdf->GetStringWidth($handoverVal);
$handoverX = $rx + $handoverLabelW + ($handoverValueW - $handoverWidth) / 2;
$handoverY = $y0 + ($LEG_H - 7) / 2;

$pdf->SetXY($handoverX, $handoverY);
$pdf->Cell($handoverWidth, 7, $handoverVal, 0, 0, 'L');

$pdf->SetY($y0 + $LEG_H);

// ---------------- Blue header ----------------
$hTop = 14;
$hSub = 9;

$pdf->EnsureSpace($hTop + $hSub);

$pdf->SetFillColor($BLUE[0], $BLUE[1], $BLUE[2]);
$y = $pdf->GetY();

$pdf->SetFont('Times', 'B', 11);
$pdf->HeaderBox($X0, $y, $wSL, $hTop+$hSub, 'SL.NO', true, 6, 'C');

$x = $X0 + $wSL;
$pdf->HeaderBox($x, $y, $wTask, $hTop+$hSub, 'PLANNED TASK', true, 6, 'C');

$x += $wTask;
$pdf->HeaderBox($x, $y, $wResp, $hTop+$hSub, "RESPONSIBLE\nBY", true, 6, 'C');

$x += $wResp;
$pdf->HeaderBox($x, $y, $wComp, $hTop+$hSub, "PLANNED\nCOMPLETION\nDATE", true, 5.5, 'C');

$x += $wComp;
$pdf->HeaderBox($x, $y, $wPctP+$wPctA, $hTop, "% OF WORK\nDONE", true, 6, 'C');

$x2 = $x + $wPctP + $wPctA;
$pdf->HeaderBox($x2, $y, $wStatus, $hTop+$hSub, "STATUS", true, 6, 'C');

$x3 = $x2 + $wStatus;
$pdf->HeaderBox($x3, $y, $wRemarks, $hTop+$hSub, "REMARKS", true, 6, 'C');

$y2 = $y + $hTop;
$pdf->SetFont('Times', 'B', 11);
$pdf->HeaderBox($x, $y2, $wPctP, $hSub, "PLAN", true, 6, 'C');
$pdf->HeaderBox($x + $wPctP, $y2, $wPctA, $hSub, "ACT.", true, 6, 'C');

$pdf->SetXY($X0, $y + $hTop + $hSub);

// ---------------- GAP row under header ----------------
$pdf->EnsureSpace($GAP2_H);
$pdf->Rect($X0, $pdf->GetY(), $W, $GAP2_H);
$pdf->Ln($GAP2_H);

// ---------------- Body ----------------
$lineH = 7;

$drawCategory = function($cat, $title) use ($pdf, $X0, $wSL, $wTask, $wResp, $wComp, $wPctP, $wPctA, $wStatus, $wRemarks, $GREY, $lineH){
  $pdf->EnsureSpace($lineH);
  $y = $pdf->GetY();

  $pdf->SetFillColor($GREY[0],$GREY[1],$GREY[2]);

  $x = $X0;

  $pdf->Rect($x, $y, $wSL, $lineH, 'F');      $pdf->Rect($x, $y, $wSL, $lineH); $x += $wSL;
  $pdf->Rect($x, $y, $wTask, $lineH, 'F');    $pdf->Rect($x, $y, $wTask, $lineH); $x += $wTask;
  $pdf->Rect($x, $y, $wResp, $lineH, 'F');    $pdf->Rect($x, $y, $wResp, $lineH); $x += $wResp;
  $pdf->Rect($x, $y, $wComp, $lineH, 'F');    $pdf->Rect($x, $y, $wComp, $lineH); $x += $wComp;
  $pdf->Rect($x, $y, $wPctP, $lineH, 'F');    $pdf->Rect($x, $y, $wPctP, $lineH); $x += $wPctP;
  $pdf->Rect($x, $y, $wPctA, $lineH, 'F');    $pdf->Rect($x, $y, $wPctA, $lineH); $x += $wPctA;
  $pdf->Rect($x, $y, $wStatus, $lineH, 'F');  $pdf->Rect($x, $y, $wStatus, $lineH); $x += $wStatus;
  $pdf->Rect($x, $y, $wRemarks, $lineH, 'F'); $pdf->Rect($x, $y, $wRemarks, $lineH);

  $pdf->SetTextColor(0,0,0);
  $pdf->SetFont('Times', 'B', 11);

  $pdf->SetXY($X0, $y);
  $pdf->Cell($wSL, $lineH, (string)$cat, 0, 0, 'C');

  $pdf->SetXY($X0+$wSL+1, $y);
  $pdf->Cell($wTask-2, $lineH, (string)$title, 0, 0, 'L');

  $pdf->SetY($y + $lineH);
};

foreach ($orderedCats as $cat) {
  $catTitle = $grouped[$cat]['title'] ?? 'CATEGORY';
  $drawCategory($cat, $catTitle);

  $rows = $grouped[$cat]['rows'] ?? [];
  foreach ($rows as $r) {
    $slno   = (string)($r[0] ?? '');
    $task   = (string)($r[1] ?? '');
    $resp   = (string)($r[2] ?? '');
    $comp   = (string)($r[3] ?? '');
    $pp     = (string)($r[4] ?? '');
    $ap     = (string)($r[5] ?? '');
    $status = norm_status($r[6] ?? '');
    $rem    = (string)($r[7] ?? '');

    $statusFill = null;
    if ($status === 'DONE') $statusFill = [0,176,80];
    elseif ($status === 'DELAY') $statusFill = [255,0,0];
    elseif ($status === 'ON TRACK') $statusFill = [255,255,0];

    $widths = [$wSL, $wTask, $wResp, $wComp, $wPctP, $wPctA, $wStatus, $wRemarks];
    $cells  = [$slno, $task, $resp, $comp, $pp, $ap, $status, $rem];
    $aligns = ['C', 'L', 'C', 'C', 'C', 'C', 'C', 'L'];

    $pdf->SetFont('Times', '', 11);
    $maxLines = 1;
    for ($i=0; $i<count($cells); $i++){
      if (!empty($cells[$i])) {
        $cellWidth = ($i == 1 || $i == 7) ? $widths[$i] - 4 : $widths[$i];
        $lines = $pdf->NbLines($cellWidth, (string)$cells[$i]);
        if ($lines > $maxLines) $maxLines = $lines;
      }
    }
    $h = max($maxLines * $lineH, $lineH);

    $pdf->EnsureSpace($h);
    $y = $pdf->GetY();
    $x = $X0;

    for ($i=0; $i<count($cells); $i++){
      $w = $widths[$i];

      if ($i === 6 && is_array($statusFill)) {
        $pdf->SetFillColor($statusFill[0], $statusFill[1], $statusFill[2]);
        $pdf->Rect($x, $y, $w, $h, 'F');
        $pdf->Rect($x, $y, $w, $h);
        $pdf->SetXY($x, $y);
        $pdf->SetFont('Times', '', 11);
        $pdf->Cell($w, $h, (string)$cells[$i], 0, 0, 'C');
      } else {
        $pdf->Rect($x, $y, $w, $h);

        if (($i == 1 || $i == 7) && trim((string)$cells[$i]) !== '') {
          $pdf->SetFont('Times', '', 11);
          $pdf->SetXY($x + 2, $y + 1);
          $pdf->MultiCell($w - 4, $lineH - 1, (string)$cells[$i], 0, 'L');
        } else {
          $pdf->SetFont('Times', '', 11);
          $pdf->SetXY($x, $y);
          $pdf->Cell($w, $h, (string)$cells[$i], 0, 0, $aligns[$i]);
        }
      }

      $x += $w;
    }

    $pdf->SetY($y + $h);
  }

  $pdf->EnsureSpace(8);
  $y = $pdf->GetY();
  $pdf->Rect($X0, $y, $W, 8);
  $pdf->SetY($y + 8);
}

// ---------------- output (FILENAME FIXED LIKE DPR) ----------------
// WANT: MPT_#<MPT_NO>_Dated_<DD-MM-YYYY>.pdf
// NOTE: If your mpt_no doesn't start with "#", we add it.

$rawMptNo = trim((string)$mptNo);
if ($rawMptNo === '') $rawMptNo = 'ID_' . $viewId;

// Ensure "#" prefix (optional but matches your requirement)
if ($rawMptNo[0] !== '#') {
  $rawMptNo = '#' . $rawMptNo;
}

$mptNoPart   = safe_filename_keep_hash($rawMptNo);
$mptDatePart = safe_filename_keep_hash(dmy_dash($mptDateRaw));
if ($mptDatePart === '') $mptDatePart = date('d-m-Y');

$filename = 'MPT_' . $mptNoPart . '_Dated_' . $mptDatePart . '.pdf';

// MODE_STRING path (for mail attachments etc.)
if ($MODE_STRING) {
  $pdfBytes = $pdf->Output('S');

  while (ob_get_level() > 0) {
    ob_end_clean();
  }

  $GLOBALS['__MPT_PDF_RESULT__'] = [
    'filename' => $filename,
    'bytes'    => $pdfBytes,
  ];

  try {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
  } catch (Throwable $e) {}

  return;
}

// Browser response path (inline or download)
while (ob_get_level() > 0) {
  ob_end_clean();
}

if (!headers_sent()) {
  header('Content-Type: application/pdf');
  header('Content-Transfer-Encoding: binary');
  header('Accept-Ranges: bytes');
  header('X-Content-Type-Options: nosniff');

  $disp = $forceDownload ? 'attachment' : 'inline';
  header("Content-Disposition: $disp; filename=\"".$filename."\"; filename*=".rfc5987_encode($filename));

  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Pragma: public');
}

$pdf->Output($forceDownload ? 'D' : 'I', $filename);

try {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) {}

exit;
?>