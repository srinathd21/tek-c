<?php
// report-mpt-print.php — MPT PDF (FPDF) — FIXED ALIGNMENT + FONT WRAP (MATCH SAMPLE)
// ✅ Same logo logic as your DAR (ukb/tek-c)
// ✅ Fix header alignment (no broken words like RESPON / SIBLE)
// ✅ Fix date wrapping (09.02.2026 stays in one line)
// ✅ Fix weird â€” symbols (remove UTF-8 em dash; use safe ASCII)
// ✅ Dynamic row height + centered text
// ✅ Legend + PROJECT HAND OVER block like sample
// ✅ Supports: ?view=ID
// ✅ Supports: ?view=ID&mode=string (returns bytes in $GLOBALS['__MPT_PDF_RESULT__'])

ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId = (int)$_SESSION['employee_id'];
$conn = get_db_connection();
if (!$conn) die("DB connection failed");

$MODE_STRING = (isset($_GET['mode']) && $_GET['mode'] === 'string');

// ---------------- helpers ----------------
function clean_text($s){
  $s = strip_tags((string)$s);
  $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
  $s = preg_replace('/\s+/', ' ', $s);
  $s = trim($s);

  // FPDF built-in fonts expect Windows-1252 (or ISO-8859-1)
  $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
  return ($converted !== false) ? $converted : $s;
}

function dmy_dots($ymd){
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('d.m.Y', $t) : $ymd;
}

function month_name_from_any($v){
  $v = trim((string)$v);
  if ($v === '') return '';

  // if numeric month (e.g., "2")
  if (ctype_digit($v)) {
    $m = (int)$v;
    if ($m >= 1 && $m <= 12) {
      $names = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
      return $names[$m];
    }
  }

  // if date
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
    $t = strtotime($v);
    return $t ? date('F', $t) : '';
  }

  return $v; // already like "February"
}

function mon_yy_from_date($ymd){
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('M-y', $t) : '';
}

function decode_rows($json){
  $json = (string)$json;
  if (trim($json) === '') return [];
  $arr = json_decode($json, true);
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
  return $v; // already formatted text
}

// ---------------- load MPT ----------------
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid MPT id");

/*
  NOTE:
  If your table or json column name differs, only change these:
  - table: mpt_reports
  - json column: tasks_json
*/
$sql = "
  SELECT
    r.*,
    s.project_name,
    s.expected_completion_date,
    c.client_name
  FROM mpt_reports r
  LEFT JOIN sites s ON s.id = r.site_id
  LEFT JOIN clients c ON c.id = s.client_id
  WHERE r.id = ? AND r.employee_id = ?
  LIMIT 1
";
$st = mysqli_prepare($conn, $sql);
if (!$st) die(mysqli_error($conn));
mysqli_stmt_bind_param($st, "ii", $viewId, $employeeId);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($st);
if (!$row) die("MPT not found or not allowed");

// meta mapping
$projectName = clean_text(pick_first_nonempty($row, ['project','project_name']));
if ($projectName === '' && isset($row['project_name'])) $projectName = clean_text($row['project_name']);

$clientName  = clean_text(pick_first_nonempty($row, ['client','client_name']));
if ($clientName === '' && isset($row['client_name'])) $clientName = clean_text($row['client_name']);

$pmcName     = clean_text(pick_first_nonempty($row, ['pmc','pmc_name']));
if ($pmcName === '') $pmcName = clean_text('UKB Construction & Management Pvt Ltd');

$mptNo       = clean_text(pick_first_nonempty($row, ['mpt_no','mpt_number','tracker_no']));
$mptDateRaw  = pick_first_nonempty($row, ['mpt_date','date','created_date']);
$mptDate     = dmy_dots($mptDateRaw);

$monthText   = pick_first_nonempty($row, ['month','mpt_month']);
$monthText   = clean_text(month_name_from_any($monthText));
if ($monthText === '') $monthText = clean_text(month_name_from_any($mptDateRaw));
if ($monthText === '') $monthText = clean_text('—');

$handoverText = clean_text(pick_first_nonempty($row, ['project_hand_over','handover','hand_over']));
if ($handoverText === '') $handoverText = clean_text(mon_yy_from_date(pick_first_nonempty($row, ['handover_date','hand_over_date'])));
if ($handoverText === '' && !empty($row['expected_completion_date'])) $handoverText = clean_text(mon_yy_from_date($row['expected_completion_date']));
if ($handoverText === '') $handoverText = clean_text('—');

$tasksJson = pick_first_nonempty($row, ['tasks_json','mpt_tasks_json','mpt_json','planned_json','items_json']);
$tasks = decode_rows($tasksJson);

// group tasks
$grouped = [];
foreach ($tasks as $t) {
  if (!is_array($t)) continue;

  $cat = strtoupper(trim((string)($t['category'] ?? $t['section'] ?? '')));
  if ($cat === '') $cat = 'A';

  $catTitle = clean_text($t['category_title'] ?? $t['section_title'] ?? '');
  if ($catTitle === '') {
    $defaults = [
      'A' => 'DESIGN DELIVERABLE',
      'B' => 'VENDOR FINALIZATION',
      'C' => 'SITE WORKS',
      'D' => 'CLIENT DECISIONS',
    ];
    $catTitle = clean_text($defaults[$cat] ?? 'CATEGORY');
  }

  $slno = clean_text($t['sl_no'] ?? $t['sl'] ?? '');
  $plannedTask = clean_text($t['planned_task'] ?? $t['task'] ?? '');
  $resp = clean_text($t['responsible_by'] ?? $t['responsible'] ?? '');
  $pcd  = trim((string)($t['planned_completion_date'] ?? $t['completion_date'] ?? $t['date'] ?? ''));
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $pcd)) $pcd = dmy_dots($pcd);
  $pcd = clean_text($pcd);

  $pp = pct_fmt($t['planned_percent'] ?? $t['planned'] ?? $t['planned_pct'] ?? '');
  $ap = pct_fmt($t['actual_percent'] ?? $t['actual'] ?? $t['actual_pct'] ?? '');
  $pp = clean_text($pp);
  $ap = clean_text($ap);

  $status = clean_text(norm_status($t['status'] ?? ''));
  $remarks = clean_text($t['remarks'] ?? $t['remark'] ?? '');

  $grouped[$cat]['title'] = $catTitle;
  $grouped[$cat]['rows'][] = [$slno, $plannedTask, $resp, $pcd, $pp, $ap, $status, $remarks];
}

$orderedCats = array_keys($grouped);
usort($orderedCats, function($a,$b){
  $order = ['A'=>1,'B'=>2,'C'=>3,'D'=>4];
  return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
});

$meta = [
  'project' => $projectName,
  'client'  => $clientName,
  'pmc'     => $pmcName,
  'mpt_no_date' => clean_text('# ' . $mptNo . ' / ' . $mptDate),
  'month'   => $monthText,
  'handover'=> $handoverText,
];

// ---------------- PDF class ----------------
class PDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 6;
  public $outerY = 6;
  public $outerW = 0;

  public $GREY = [220,220,220];
  public $BLUE = [153,187,227];

  function SetMeta($meta){ $this->meta = $meta; }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - 6)) {
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
      $l += $cw[$c] ?? 0;

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

  function CellWrapCenter($x, $y, $w, $h, $txt, $align='C', $lineH=5.5){
    $this->Rect($x, $y, $w, $h);
    $txt = (string)$txt;
    if (trim($txt) === '') return;

    $lines = $this->NbLines($w, $txt);
    $textH = $lines * $lineH;
    $startY = $y + max(0, ($h - $textH) / 2);

    $this->SetXY($x, $startY);
    $this->MultiCell($w, $lineH, $txt, 0, $align);
  }

  function HeaderBox($x, $y, $w, $h, $txt, $fill=true, $lineH=5.0, $align='C'){
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
    $this->SetLineWidth(0.35);

    // Outer border
    $this->outerW = $this->GetPageWidth() - 12;
    $outerH = $this->GetPageHeight() - 12;
    $this->Rect($this->outerX, $this->outerY, $this->outerW, $outerH);

    $X0 = $this->outerX;
    $Y0 = $this->outerY;
    $W  = $this->outerW;

    // Header block
    $headerH = 24;
    $logoW   = 22;
    $rightW  = 84;
    $titleW  = $W - $logoW - $rightW;

    // logo
    $this->SetXY($X0, $Y0);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C');
    if ($this->logoPath && file_exists($this->logoPath)) {
      $this->Image($this->logoPath, $X0+2, $Y0+2, $logoW-4, $headerH-4);
    }

    // title background
    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->Cell($titleW, $headerH, '', 1, 0, 'C', true);

    // title text
    $tx = $X0 + $logoW;
    $ty = $Y0;
    $this->SetFont('Arial','B',12);
    $this->SetXY($tx, $ty + 7);
    $this->Cell($titleW, 6, 'MONTHLY PLANNED TRACKER (MPT)', 0, 2, 'C');
    $this->SetFont('Arial','B',11);
    $this->Cell($titleW, 6, 'MONTH : ' . ($this->meta['month'] ?? '—'), 0, 0, 'C');

    // right meta table
    $rx = $X0 + $logoW + $titleW;
    $ry = $Y0;
    $rH = $headerH / 4;
    $labW = 30;
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

      $this->SetFont('Arial','B',9.5);
      $this->Cell($labW, $rH, $rows[$i][0], 1, 0, 'L', true);

      $txt = (string)$rows[$i][1];
      $fs = 9.5;
      $this->SetFont('Arial','B', $fs);
      while ($fs > 7 && $this->GetStringWidth($txt) > ($valW - 2)) {
        $fs -= 0.5;
        $this->SetFont('Arial','B', $fs);
      }
      $this->Cell($valW, $rH, $txt, 1, 0, 'L', true);
    }

    $this->SetY($Y0 + $headerH);
  }
}

// ---------------- setup PDF ----------------
$pdf = new PDF('P', 'mm', 'A4');
$pdf->SetMargins(6, 6, 6);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);

// same logo selection as DAR
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

$X0 = 6;
$W  = $pdf->GetPageWidth() - 12;

// heights
$GAP1_H = 10;
$LEG_H  = 18;
$LEG_RH = 6;
$GAP2_H = 6;

// ✅ WIDTHS UPDATED to FIX WRAP/ALIGNMENT (NO BROKEN WORDS / NO DATE SPLIT)
$wSL     = 16;
$wResp   = 22;   // wider so "RESPONSIBLE" doesn't split
$wComp   = 24;   // wider so date doesn't split
$wPctP   = 16;
$wPctA   = 16;
$wStatus = 22;
$wTask   = 58;   // adjust to keep total width
$wRemarks= $W - ($wSL+$wTask+$wResp+$wComp+$wPctP+$wPctA+$wStatus);

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

// left legend area
$pdf->Rect($X0, $y0, $leftBlockW, $LEG_H);

// right handover
$rx = $X0 + $leftBlockW;
$pdf->Rect($rx, $y0, $handoverLabelW, $LEG_H);
$pdf->Rect($rx + $handoverLabelW, $y0, $handoverValueW, $LEG_H);

// legend rows
$boxW = 14;
$textW = $leftBlockW - $boxW;

$legend = [
  ['ON TRACK', $YEL],
  ['DONE',     $GRN],
  ['DELAY',    $RED],
];

$pdf->SetFont('Arial','B',11);
for ($i=0; $i<3; $i++){
  $yy = $y0 + $i*$LEG_RH;

  $pdf->SetFillColor($legend[$i][1][0], $legend[$i][1][1], $legend[$i][1][2]);
  $pdf->Rect($X0, $yy, $boxW, $LEG_RH, 'F');
  $pdf->Rect($X0, $yy, $boxW, $LEG_RH);

  $pdf->Rect($X0+$boxW, $yy, $textW, $LEG_RH);
  $pdf->SetXY($X0+$boxW+2, $yy);
  $pdf->Cell($textW-2, $LEG_RH, $legend[$i][0], 0, 0, 'L');
}

// handover text
$pdf->SetFont('Arial','B',16);
$pdf->SetXY($rx, $y0);
$pdf->Cell($handoverLabelW, $LEG_H, 'PROJECT HAND OVER', 0, 0, 'C');
$pdf->SetFont('Arial','B',14);
$pdf->SetXY($rx + $handoverLabelW, $y0);
$pdf->Cell($handoverValueW, $LEG_H, ($meta['handover'] ?? ''), 0, 0, 'C');

$pdf->SetY($y0 + $LEG_H);

// ---------------- Blue header (two-level) ----------------
$hTop = 10;
$hSub = 8;

$pdf->EnsureSpace($hTop + $hSub);

$pdf->SetFillColor($BLUE[0], $BLUE[1], $BLUE[2]);
$y = $pdf->GetY();

$pdf->SetFont('Arial','B',10);
$pdf->HeaderBox($X0, $y, $wSL, $hTop+$hSub, 'SL.NO', true, 5.0, 'C');

$x = $X0 + $wSL;
$pdf->HeaderBox($x, $y, $wTask, $hTop+$hSub, 'PLANNED TASK', true, 5.0, 'C');

$x += $wTask;
// IMPORTANT: Manual line break so FPDF doesn't split word at letters
$pdf->SetFont('Arial','B',9);
$pdf->HeaderBox($x, $y, $wResp, $hTop+$hSub, "RESPONSIBLE\nBY", true, 4.8, 'C');

$x += $wResp;
$pdf->SetFont('Arial','B',8.6);
$pdf->HeaderBox($x, $y, $wComp, $hTop+$hSub, "PLANNED\nCOMPLETION\nDATE", true, 4.3, 'C');

$x += $wComp;
$pdf->SetFont('Arial','B',10);
$pdf->HeaderBox($x, $y, $wPctP+$wPctA, $hTop, "% OF WORK\nDONE", true, 5.0, 'C');

$x2 = $x + $wPctP + $wPctA;
$pdf->SetFont('Arial','B',10);
$pdf->HeaderBox($x2, $y, $wStatus, $hTop+$hSub, "STATUS", true, 5.0, 'C');

$x3 = $x2 + $wStatus;
$pdf->HeaderBox($x3, $y, $wRemarks, $hTop+$hSub, "REMARKS", true, 5.0, 'C');

// sub headers
$y2 = $y + $hTop;
$pdf->SetFont('Arial','B',10);
$pdf->HeaderBox($x, $y2, $wPctP, $hSub, "PLANNED", true, 5.0, 'C');
$pdf->HeaderBox($x + $wPctP, $y2, $wPctA, $hSub, "ACTUAL", true, 5.0, 'C');

$pdf->SetXY($X0, $y + $hTop + $hSub);

// ---------------- GAP row under header ----------------
$pdf->EnsureSpace($GAP2_H);
$pdf->Rect($X0, $pdf->GetY(), $W, $GAP2_H);
$pdf->Ln($GAP2_H);

// ---------------- Body ----------------
$lineH = 6.5;

// category row drawer
$drawCategory = function($cat, $title) use ($pdf, $X0, $W, $wSL, $wTask, $wResp, $wComp, $wPctP, $wPctA, $wStatus, $wRemarks, $GREY, $lineH){
  $pdf->EnsureSpace($lineH);
  $y = $pdf->GetY();

  $pdf->SetFillColor($GREY[0],$GREY[1],$GREY[2]);

  // draw each cell filled (so grid aligns perfectly)
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
  $pdf->SetFont('Arial','B',11);

  $pdf->SetXY($X0, $y);
  $pdf->Cell($wSL, $lineH, (string)$cat, 0, 0, 'C');

  $pdf->SetXY($X0+$wSL+2, $y);
  $pdf->Cell($wTask-2, $lineH, (string)$title, 0, 0, 'L');

  $pdf->SetY($y + $lineH);
};

// draw body rows
foreach ($orderedCats as $cat) {
  $catTitle = $grouped[$cat]['title'] ?? 'CATEGORY';
  $drawCategory($cat, $catTitle);

  $rows = $grouped[$cat]['rows'] ?? [];
  foreach ($rows as $r) {
    $slno   = $r[0];
    $task   = $r[1];
    $resp   = $r[2];
    $comp   = $r[3];
    $pp     = $r[4]; // keep empty if empty
    $ap     = $r[5]; // keep empty if empty
    $status = norm_status($r[6]);
    $rem    = $r[7];

    $statusFill = null;
    if ($status === 'DONE') $statusFill = [0,176,80];
    elseif ($status === 'DELAY') $statusFill = [255,0,0];
    elseif ($status === 'ON TRACK') $statusFill = [255,255,0];

    $widths = [$wSL,$wTask,$wResp,$wComp,$wPctP,$wPctA,$wStatus,$wRemarks];
    $cells  = [$slno,$task,$resp,$comp,$pp,$ap,$status,$rem];
    $aligns = ['C','L','C','C','C','C','C','L'];

    // dynamic height (based on wrapping)
    $pdf->SetFont('Arial','B',10);
    $maxLines = 1;
    for ($i=0;$i<count($cells);$i++){
      $maxLines = max($maxLines, $pdf->NbLines($widths[$i], (string)$cells[$i]));
    }
    $h = $maxLines * $lineH;

    $pdf->EnsureSpace($h);
    $y = $pdf->GetY();
    $x = $X0;

    for ($i=0;$i<count($cells);$i++){
      $w = $widths[$i];

      // body font (slightly smaller to avoid split)
      $pdf->SetFont('Arial','B',10);

      if ($i === 6 && is_array($statusFill)) {
        $pdf->SetFillColor($statusFill[0],$statusFill[1],$statusFill[2]);
        $pdf->Rect($x, $y, $w, $h, 'F');
        $pdf->Rect($x, $y, $w, $h);
        $pdf->CellWrapCenter($x, $y, $w, $h, (string)$cells[$i], 'C', $lineH);
      } else {
        $pdf->Rect($x, $y, $w, $h);
        $pdf->CellWrapCenter($x, $y, $w, $h, (string)$cells[$i], $aligns[$i], $lineH);
      }

      $x += $w;
    }

    $pdf->SetY($y + $h);
  }

  // small blank bordered row between categories (as in sample)
  $pdf->EnsureSpace(4);
  $pdf->Rect($X0, $pdf->GetY(), $W, 4);
  $pdf->Ln(4);
}

// ---------------- output ----------------
ob_end_clean();
$filename = 'MPT_' . preg_replace('/[^A-Za-z0-9\-_]/','_', (string)$mptNo) . '.pdf';

if ($MODE_STRING) {
  $GLOBALS['__MPT_PDF_RESULT__'] = [
    'filename' => $filename,
    'bytes' => $pdf->Output('S'),
  ];
} else {
  $pdf->Output('I', $filename);
}

try {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) {}

if (!$MODE_STRING) exit;
