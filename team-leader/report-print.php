<?php
// report-dpr-print.php — DPR PDF (FPDF) — PORTRAIT + GAP=6 + FIXED ROW HEIGHT (6mm)
// ✅ Fix: Work Progress Start/End shows FULL date (no 05-02-20...)
// ✅ Supports: ?view=ID (normal print)
// ✅ Supports: ?view=ID&mode=string (returns PDF bytes in $GLOBALS['__DPR_PDF_RESULT__'])

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
  $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
  return ($converted !== false) ? $converted : $s;
}
function dmy_dash($ymd){
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('d-m-Y', $t) : $ymd;
}
function decode_rows($json){
  $json = (string)$json;
  if (trim($json) === '') return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}
function split_widths($total, $parts){
  $out = [];
  $sum = 0.0;
  for ($i=0; $i<count($parts); $i++){
    $w = round($total * $parts[$i], 1);
    $out[] = $w;
    $sum += $w;
  }
  $diff = round($total - $sum, 1);
  $out[count($out)-1] = round($out[count($out)-1] + $diff, 1);
  return $out;
}
function draw_left_merged_fixed($pdf, $x, $y, $wL, $wS, $label, $title, $height){
  $pdf->SetFillColor(220,220,220);
  $pdf->SetFont('Arial','B',9);
  $pdf->SetXY($x, $y);
  $pdf->Cell($wL, $height, $label, 1, 0, 'C', true);
  $pdf->Cell($wS, $height, $title, 1, 0, 'C', true);
}
function end_section($pdf, $yStart, $segH, $gap){
  $endY = $yStart + $segH;
  if ($pdf->GetY() < $endY) $pdf->SetY($endY);
  if ($gap > 0) $pdf->Ln($gap);
}
function client_name_only($s){
  $s = clean_text($s);
  if ($s === '') return '';

  $first = preg_split('/[,;]/', $s);
  $first = trim($first[0] ?? $s);

  if (strpos($first, '-') !== false) {
    $parts = explode('-', $first, 2);
    $right = trim($parts[1] ?? '');
    $first = ($right !== '') ? $right : trim($parts[0]);
  } elseif (strpos($first, ':') !== false) {
    $parts = explode(':', $first, 2);
    $right = trim($parts[1] ?? '');
    $first = ($right !== '') ? $right : trim($parts[0]);
  }

  $first = preg_replace('/\S+@\S+\.\S+/', '', $first);
  return trim($first);
}

// ---------------- load DPR ----------------
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid DPR id");

$sql = "
  SELECT
    r.*,
    s.project_name, s.project_location, s.project_type,
    s.manager_employee_id,
    c.client_name
  FROM dpr_reports r
  INNER JOIN sites s ON s.id = r.site_id
  INNER JOIN clients c ON c.id = s.client_id
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
if (!$row) die("DPR not found or not allowed");

// PMC name (manager name if exists)
$pmcName = 'UKB Construction Management pvt ltd';
if (!empty($row['manager_employee_id'])) {
  $mid = (int)$row['manager_employee_id'];
  $st2 = mysqli_prepare($conn, "SELECT full_name FROM employees WHERE id=? LIMIT 1");
  if ($st2) {
    mysqli_stmt_bind_param($st2, "i", $mid);
    mysqli_stmt_execute($st2);
    $r2 = mysqli_stmt_get_result($st2);
    $mrow = mysqli_fetch_assoc($r2);
    if (!empty($mrow['full_name'])) $pmcName = $mrow['full_name'];
    mysqli_stmt_close($st2);
  }
}

// Map data
$data = [];
$data['project_name']   = clean_text($row['project_name'] ?? '');
$data['client_name']    = clean_text($row['client_name'] ?? '');
$data['pmc_name']       = clean_text($pmcName);
$data['dpr_no']         = clean_text($row['dpr_no'] ?? '');
$data['dpr_date']       = dmy_dash($row['dpr_date'] ?? '');

$data['schedule_start'] = dmy_dash($row['schedule_start'] ?? '');
$data['schedule_end']   = dmy_dash($row['schedule_end'] ?? '');
$data['projected']      = dmy_dash($row['schedule_projected'] ?? '');

$data['dur_total']      = clean_text((string)($row['duration_total'] ?? ''));
$data['dur_elapsed']    = clean_text((string)($row['duration_elapsed'] ?? ''));
$data['dur_balance']    = clean_text((string)($row['duration_balance'] ?? ''));

$data['weather']        = clean_text($row['weather'] ?? '');
$data['site_condition'] = clean_text($row['site_condition'] ?? '');

$data['report_to_raw']  = clean_text($row['report_distribute_to'] ?? '');
$data['report_to_name'] = client_name_only($data['report_to_raw']);
$data['prepared_by']    = clean_text($row['prepared_by'] ?? '');
$data['designation']    = clean_text($_SESSION['designation'] ?? 'Project Engineer');

// JSON blocks
$data['manpower']    = decode_rows($row['manpower_json'] ?? '');
$data['machinery']   = decode_rows($row['machinery_json'] ?? '');
$data['material']    = decode_rows($row['material_json'] ?? '');
$data['workprog']    = decode_rows($row['work_progress_json'] ?? '');
$data['constraints'] = decode_rows($row['constraints_json'] ?? '');

// ---------------- PDF class ----------------
class PDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 6;
  public $outerY = 6;
  public $outerW = 0;
  public $GREY  = [220,220,220];
  public $gapAfterHeader = 6;

  function SetMeta($meta){ $this->meta = $meta; }

  function Header(){
    $this->SetLineWidth(0.35);

    $this->outerW = $this->GetPageWidth() - 12;
    $outerH = $this->GetPageHeight() - 12;
    $this->Rect($this->outerX, $this->outerY, $this->outerW, $outerH);

    $X0 = $this->outerX;
    $Y0 = $this->outerY;
    $W  = $this->outerW;

    $headerH = 20;
    $logoW   = 21;
    $rightW  = 70;
    $titleW  = $W - $logoW - $rightW;

    $this->SetXY($X0, $Y0);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C');
    if ($this->logoPath && file_exists($this->logoPath)) {
      $this->Image($this->logoPath, $X0+2, $Y0+2, $logoW-4, $headerH-4);
    }

    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->SetFont('Arial','B',12);
    $this->Cell($titleW, $headerH, 'DAILY PROGRESS REPORT (DPR)', 1, 0, 'C', true);

    $rx = $X0 + $logoW + $titleW;
    $ry = $Y0;
    $rH = $headerH / 4;
    $labW = 22;
    $valW = $rightW - $labW;

    $rows = [
      ['Project', $this->meta['project_name'] ?? ''],
      ['Client',  $this->meta['client_name'] ?? ''],
      ['PMC',     $this->meta['pmc_name'] ?? ''],
      ['DPR', ($this->meta['dpr_no'] ?? '').' / '.($this->meta['dpr_date'] ?? '')],
    ];

    for($i=0;$i<4;$i++){
      $this->SetXY($rx, $ry + $i*$rH);
      $this->SetFont('Arial','B',8.5);
      $this->Cell($labW, $rH, $rows[$i][0], 1, 0, 'L');

      $txt = (string)$rows[$i][1];
      $fs = 8.5;
      $this->SetFont('Arial','', $fs);
      while ($fs > 6 && $this->GetStringWidth($txt) > ($valW - 2)) {
        $fs -= 0.5;
        $this->SetFont('Arial','', $fs);
      }
      $this->Cell($valW, $rH, $txt, 1, 0, 'L');
    }

    $this->SetY($Y0 + $headerH + $this->gapAfterHeader);
  }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - 6)) {
      $this->AddPage();
    }
  }

  function FitText($w, $txt, $ellipsis='...'){
    $txt = trim((string)$txt);
    if ($txt === '') return '';
    if ($this->GetStringWidth($txt) <= ($w - 2)) return $txt;

    $max = strlen($txt);
    for ($i=$max; $i>0; $i--){
      $t = rtrim(substr($txt, 0, $i)) . $ellipsis;
      if ($this->GetStringWidth($t) <= ($w - 2)) return $t;
    }
    return $ellipsis;
  }

  function DrawRowFixed($x, $widths, $cells, $h, $aligns){
    $this->EnsureSpace($h);
    $this->SetX($x);
    for($i=0;$i<count($cells);$i++){
      $w = $widths[$i];
      $txt = $this->FitText($w, $cells[$i] ?? '');
      $al = $aligns[$i] ?? 'L';
      $this->Cell($w, $h, $txt, 1, 0, $al);
    }
    $this->Ln($h);
  }
}

// ---------------- setup PDF ----------------
$pdf = new PDF('P', 'mm', 'A4');
$pdf->SetMargins(6, 6, 6);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);

$SECTION_GAP = 6;
$pdf->gapAfterHeader = 6;

$logoCandidates = [

  __DIR__ . '/assets/ukb.png',
  __DIR__ . '/assets/ukb.jpg',
];
foreach ($logoCandidates as $p) {
  if (file_exists($p)) { $pdf->logoPath = $p; break; }
}

$pdf->SetMeta($data);
$pdf->AddPage();

// Geometry
$X0 = 6;
$W  = $pdf->GetPageWidth() - 12;

$wL  = 12;
$wS  = 32;
$h   = 6;
$gap = 6;

$avail = $W - ($wL + $wS);
$xR = $X0 + $wL + $wS;

// ========================= A. Schedule =========================
$pdf->SetFont('Arial','B',9);

$segH = $h*3;
$pdf->EnsureSpace($segH + $gap);
$yA = $pdf->GetY();

draw_left_merged_fixed($pdf, $X0, $yA, $wL, $wS, 'A.', 'Schedule', $segH);

list($wDateBlock, $wDurBlock) = split_widths($avail, [0.66, 0.34]);
list($wStart, $wEnd, $wProj) = split_widths($wDateBlock, [0.35, 0.35, 0.30]);
list($wTotal, $wElap, $wBal) = split_widths($wDurBlock, [0.40, 0.30, 0.30]);

$pdf->SetXY($xR, $yA);
$pdf->Cell($wDateBlock, $h, 'Date', 1, 0, 'C');
$pdf->Cell($wDurBlock,  $h, 'Duration', 1, 1, 'C');

$pdf->SetX($xR);
$pdf->Cell($wStart, $h, 'Start', 1, 0, 'L');
$pdf->Cell($wEnd,   $h, 'End', 1, 0, 'L');
$pdf->Cell($wProj,  $h, 'Projected', 1, 0, 'L');
$pdf->Cell($wTotal, $h, 'Total', 1, 0, 'L');
$pdf->Cell($wElap,  $h, 'Elapsed', 1, 0, 'L');
$pdf->Cell($wBal,   $h, 'Balance', 1, 1, 'L');

$pdf->SetFont('Arial','',9);
$pdf->SetX($xR);
$pdf->Cell($wStart, $h, $pdf->FitText($wStart, $data['schedule_start']), 1, 0, 'L');
$pdf->Cell($wEnd,   $h, $pdf->FitText($wEnd,   $data['schedule_end']),   1, 0, 'L');
$pdf->Cell($wProj,  $h, $pdf->FitText($wProj,  $data['projected']),      1, 0, 'L');
$pdf->Cell($wTotal, $h, $pdf->FitText($wTotal, $data['dur_total']),      1, 0, 'L');
$pdf->Cell($wElap,  $h, $pdf->FitText($wElap,  $data['dur_elapsed']),    1, 0, 'L');
$pdf->Cell($wBal,   $h, $pdf->FitText($wBal,   $data['dur_balance']),    1, 1, 'L');

end_section($pdf, $yA, $segH, $gap);

// ========================= B. Site =========================
$pdf->SetFont('Arial','B',9);
$segH = $h*2;
$pdf->EnsureSpace($segH + $gap);
$yB = $pdf->GetY();

draw_left_merged_fixed($pdf, $X0, $yB, $wL, $wS, 'B.', 'Site', $segH);

$half = $avail / 2;
$opt  = $half / 2;

$pdf->SetXY($xR, $yB);
$pdf->Cell($half, $h, 'Weather', 1, 0, 'C');
$pdf->Cell($half, $h, 'Site Conditions', 1, 1, 'C');

$pdf->SetFont('Arial','',9);

$wNorm = (strtolower(trim($data['weather'])) === 'normal');
$wRain = (strtolower(trim($data['weather'])) === 'rainy');
$cNorm = (strtolower(trim($data['site_condition'])) === 'normal');
$cSl   = (strtolower(trim($data['site_condition'])) === 'slushy');

$pdf->SetX($xR);
$pdf->SetFillColor(255,255,102);
$pdf->Cell($opt, $h, 'Normal', 1, 0, 'L', $wNorm);
$pdf->Cell($opt, $h, 'Rainy',  1, 0, 'L', $wRain);
$pdf->Cell($opt, $h, 'Normal', 1, 0, 'L', $cNorm);
$pdf->Cell($opt, $h, 'Slushy', 1, 1, 'L', $cSl);

end_section($pdf, $yB, $segH, $gap);

// ========================= C. Manpower =========================
$mp = $data['manpower'];
if (count($mp) === 0) $mp = [['agency'=>'','category'=>'','unit'=>'','qty'=>'','remark'=>'']];

$totalMP = 0;
foreach($mp as $r){ $totalMP += (int)($r['qty'] ?? 0); }

$wAgency = 52; $wCat=34; $wUnit=18; $wQty=14;
$wRemark = $avail - ($wAgency+$wCat+$wUnit+$wQty);

$idx = 0;
while ($idx < count($mp)) {
  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  $headH = $h;
  if ($remaining < ($headH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($mp) - $idx);
  $isLast = ($idx + $rowsThis >= count($mp));

  $segH = $headH + ($rowsThis * $h) + ($isLast ? $h : 0);

  $pdf->EnsureSpace($segH + $gap);
  $yC = $pdf->GetY();
  draw_left_merged_fixed($pdf, $X0, $yC, $wL, $wS, 'C.', 'Manpower', $segH);

  $pdf->SetFont('Arial','B',9);
  $pdf->SetXY($xR, $yC);
  $pdf->Cell($wAgency, $h, 'Agency', 1, 0, 'L');
  $pdf->Cell($wCat,    $h, 'Category', 1, 0, 'L');
  $pdf->Cell($wUnit,   $h, 'Unit', 1, 0, 'C');
  $pdf->Cell($wQty,    $h, 'Qty', 1, 0, 'C');
  $pdf->Cell($wRemark, $h, 'Remark', 1, 1, 'L');

  $pdf->SetFont('Arial','',9);
  for($i=0;$i<$rowsThis;$i++){
    $r = $mp[$idx+$i];
    $pdf->DrawRowFixed($xR, [$wAgency,$wCat,$wUnit,$wQty,$wRemark], [
      clean_text($r['agency'] ?? ''),
      clean_text($r['category'] ?? ''),
      clean_text($r['unit'] ?? ''),
      clean_text($r['qty'] ?? ''),
      clean_text($r['remark'] ?? ''),
    ], $h, ['L','L','C','C','L']);
  }

  if ($isLast) {
    $pdf->SetFont('Arial','B',9);
    $pdf->SetX($xR);
    $pdf->Cell($wAgency + $wCat, $h, 'Total Manpower', 1, 0, 'L');
    $pdf->Cell($wUnit, $h, 'Nos', 1, 0, 'C');
    $pdf->Cell($wQty,  $h, (string)$totalMP, 1, 0, 'C');
    $pdf->Cell($wRemark, $h, '', 1, 1, 'L');
  }

  end_section($pdf, $yC, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= D. Machineries =========================
$mc = $data['machinery'];
if (count($mc) === 0) $mc = [['equipment'=>'','unit'=>'','qty'=>'','remark'=>'']];

$wEquip = 70; $wUnitD = 18; $wQtyD = 14;
$wRemD  = $avail - ($wEquip+$wUnitD+$wQtyD);

$idx = 0;
while ($idx < count($mc)) {
  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  $headH = $h;
  if ($remaining < ($headH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($mc) - $idx);
  $segH = $headH + ($rowsThis * $h);

  $pdf->EnsureSpace($segH + $gap);
  $yD = $pdf->GetY();
  draw_left_merged_fixed($pdf, $X0, $yD, $wL, $wS, 'D.', 'Machineries', $segH);

  $pdf->SetFont('Arial','B',9);
  $pdf->SetXY($xR, $yD);
  $pdf->Cell($wEquip, $h, 'Type of Equipment', 1, 0, 'L');
  $pdf->Cell($wUnitD, $h, 'Unit', 1, 0, 'C');
  $pdf->Cell($wQtyD,  $h, 'Qty', 1, 0, 'C');
  $pdf->Cell($wRemD,  $h, 'Remark', 1, 1, 'L');

  $pdf->SetFont('Arial','',9);
  for($i=0;$i<$rowsThis;$i++){
    $r = $mc[$idx+$i];
    $pdf->DrawRowFixed($xR, [$wEquip,$wUnitD,$wQtyD,$wRemD], [
      clean_text($r['equipment'] ?? ''),
      clean_text($r['unit'] ?? ''),
      clean_text($r['qty'] ?? ''),
      clean_text($r['remark'] ?? ''),
    ], $h, ['L','C','C','L']);
  }

  end_section($pdf, $yD, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= E. Material =========================
$mt = $data['material'];
if (count($mt) === 0) $mt = [['vendor'=>'','material'=>'','unit'=>'','qty'=>'','remark'=>'']];

$wVendor=40; $wMat=55; $wUnitE=18; $wQtyE=14;
$wRemE = $avail - ($wVendor+$wMat+$wUnitE+$wQtyE);

$idx = 0;
while ($idx < count($mt)) {
  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  $headH = $h;
  if ($remaining < ($headH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($mt) - $idx);
  $segH = $headH + ($rowsThis * $h);

  $pdf->EnsureSpace($segH + $gap);
  $yE = $pdf->GetY();
  draw_left_merged_fixed($pdf, $X0, $yE, $wL, $wS, 'E.', 'Material', $segH);

  $pdf->SetFont('Arial','B',9);
  $pdf->SetXY($xR, $yE);
  $pdf->Cell($wVendor, $h, 'Vendor', 1, 0, 'L');
  $pdf->Cell($wMat,    $h, 'Material', 1, 0, 'L');
  $pdf->Cell($wUnitE,  $h, 'Unit', 1, 0, 'C');
  $pdf->Cell($wQtyE,   $h, 'Qty', 1, 0, 'C');
  $pdf->Cell($wRemE,   $h, 'Remark', 1, 1, 'L');

  $pdf->SetFont('Arial','',9);
  for($i=0;$i<$rowsThis;$i++){
    $r = $mt[$idx+$i];
    $pdf->DrawRowFixed($xR, [$wVendor,$wMat,$wUnitE,$wQtyE,$wRemE], [
      clean_text($r['vendor'] ?? ''),
      clean_text($r['material'] ?? ''),
      clean_text($r['unit'] ?? ''),
      clean_text($r['qty'] ?? ''),
      clean_text($r['remark'] ?? ''),
    ], $h, ['L','L','C','C','L']);
  }

  end_section($pdf, $yE, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= F. Work Progress (2-row header) =========================
$wp = $data['workprog'];
if (count($wp) === 0) $wp = [['task'=>'','duration'=>'','start'=>'','end'=>'','status'=>'','reasons'=>'']];

// ✅ FIXED WIDTHS (Start/End wider so full date fits)
$wTask   = 47;   // was 55
$wDurF   = 18;
$wStartF = 22;   // was 18 ✅
$wEndF   = 22;   // was 18 ✅
$wIn     = 12;
$wDelay  = 12;
$wReasons = $avail - ($wTask+$wDurF+$wStartF+$wEndF+$wIn+$wDelay); // auto balance

$idx = 0;
while ($idx < count($wp)) {
  $headerH = $h*2;

  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  if ($remaining < ($headerH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headerH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($wp) - $idx);
  $segH = $headerH + ($rowsThis * $h);

  $pdf->EnsureSpace($segH + $gap);
  $yF = $pdf->GetY();

  draw_left_merged_fixed($pdf, $X0, $yF, $wL, $wS, 'F.', 'Work Progress', $segH);

  $pdf->SetFont('Arial','B',9);

  // row 1
  $pdf->SetXY($xR, $yF);
  $pdf->Cell($wTask, $headerH, 'Task', 1, 0, 'C');
  $pdf->Cell($wDurF+$wStartF+$wEndF, $h, 'Weekly Schedule', 1, 0, 'C');
  $pdf->Cell($wIn+$wDelay, $h, 'Status', 1, 0, 'C');
  $pdf->Cell($wReasons, $headerH, 'Reasons', 1, 0, 'C');

  // row 2
  $pdf->SetXY($xR + $wTask, $yF + $h);
  $pdf->Cell($wDurF,   $h, 'Duration', 1, 0, 'C');
  $pdf->Cell($wStartF, $h, 'Start', 1, 0, 'C');
  $pdf->Cell($wEndF,   $h, 'End', 1, 0, 'C');

  $pdf->SetFillColor(0,255,0);
  $pdf->Cell($wIn, $h, 'In', 1, 0, 'C', true);

  $pdf->SetFillColor(255,0,0);
  $pdf->SetTextColor(255,255,255);
  $pdf->Cell($wDelay, $h, 'Delay', 1, 0, 'C', true);
  $pdf->SetTextColor(0,0,0);

  // data start
  $pdf->SetXY($xR, $yF + $headerH);

  $pdf->SetFont('Arial','',9);
  for($i=0;$i<$rowsThis;$i++){
    $r = $wp[$idx+$i];

    $status = strtolower(trim((string)($r['status'] ?? '')));
    $inControl = ($status === 'in control' || $status === 'incontrol' || $status === 'in');
    $markIn  = $inControl ? 'X' : '';
    $markDel = (!$inControl && $status !== '') ? 'X' : '';

    // ✅ now Start/End will fit full dd-mm-yyyy
    $pdf->DrawRowFixed($xR, [$wTask,$wDurF,$wStartF,$wEndF,$wIn,$wDelay,$wReasons], [
      clean_text($r['task'] ?? ''),
      clean_text($r['duration'] ?? ''),
      dmy_dash($r['start'] ?? ''),
      dmy_dash($r['end'] ?? ''),
      $markIn,
      $markDel,
      clean_text($r['reasons'] ?? ''),
    ], $h, ['L','C','C','C','C','C','L']);
  }

  end_section($pdf, $yF, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= G. Constraints =========================
$cs = $data['constraints'];
if (count($cs) === 0) $cs = [['issue'=>'Nil','status'=>'','date'=>'','remark'=>'']];

$wIssues=70; $wOpen=12; $wClosed=12; $wDateG=18;
$wRemarkG = $avail - ($wIssues+$wOpen+$wClosed+$wDateG);

$idx = 0;
while ($idx < count($cs)) {
  $headerH = $h*2;

  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  if ($remaining < ($headerH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-6) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headerH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($cs) - $idx);
  $segH = $headerH + ($rowsThis * $h);

  $pdf->EnsureSpace($segH + $gap);
  $yG = $pdf->GetY();

  draw_left_merged_fixed($pdf, $X0, $yG, $wL, $wS, 'G.', 'Constraints', $segH);

  $pdf->SetFont('Arial','B',9);

  $pdf->SetXY($xR, $yG);
  $pdf->Cell($wIssues, $h, 'Issues', 1, 0, 'L');
  $pdf->Cell($wOpen+$wClosed+$wDateG, $h, 'Status', 1, 0, 'C');
  $pdf->Cell($wRemarkG, $h, 'Remark', 1, 1, 'L');

  $pdf->SetX($xR + $wIssues);

  $pdf->SetFillColor(255,0,0);
  $pdf->SetTextColor(255,255,255);
  $pdf->Cell($wOpen, $h, 'Open', 1, 0, 'C', true);

  $pdf->SetFillColor(0,255,0);
  $pdf->SetTextColor(0,0,0);
  $pdf->Cell($wClosed, $h, 'Closed', 1, 0, 'C', true);

  $pdf->SetFillColor(255,255,255);
  $pdf->Cell($wDateG, $h, 'Date', 1, 1, 'C');

  $pdf->SetFont('Arial','',9);

  for($i=0;$i<$rowsThis;$i++){
    $r = $cs[$idx+$i];
    $issue = clean_text($r['issue'] ?? '');
    $stt   = strtolower(trim((string)($r['status'] ?? '')));
    $dt    = dmy_dash($r['date'] ?? '');
    $rmk   = clean_text($r['remark'] ?? '');

    $openX = ($stt === 'open') ? 'X' : '';
    $clsX  = ($stt === 'closed') ? 'X' : '';

    $pdf->DrawRowFixed($xR, [$wIssues,$wOpen,$wClosed,$wDateG,$wRemarkG], [
      $issue, $openX, $clsX, $dt, $rmk
    ], $h, ['L','C','C','C','L']);
  }

  end_section($pdf, $yG, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= H. Report by (ONLY CLIENT NAME + "Client") =========================
list($wDist, $wPrep) = split_widths($avail, [0.55, 0.45]);
$segH = $h*3;

$pdf->EnsureSpace($segH);
$yH = $pdf->GetY();

draw_left_merged_fixed($pdf, $X0, $yH, $wL, $wS, 'H.', 'Report by', $segH);

$pdf->SetFont('Arial','B',9);
$pdf->SetXY($xR, $yH);
$pdf->Cell($wDist, $h, 'Report Distribute to', 1, 0, 'L');
$pdf->Cell($wPrep, $h, 'Prepared By', 1, 1, 'L');

$pdf->SetFont('Arial','',9);

$distName = $data['report_to_name'] !== '' ? $data['report_to_name'] : $data['client_name'];

$pdf->SetX($xR);
$pdf->Cell($wDist, $h, $pdf->FitText($wDist, $distName), 1, 0, 'L');
$pdf->Cell($wPrep, $h, $pdf->FitText($wPrep, $data['prepared_by']), 1, 1, 'L');

$pdf->SetX($xR);
$pdf->Cell($wDist, $h, $pdf->FitText($wDist, 'Client'), 1, 0, 'L');
$pdf->Cell($wPrep, $h, $pdf->FitText($wPrep, $data['designation']), 1, 1, 'L');

// ---------------- output ----------------
ob_end_clean();
$filename = 'DPR_' . preg_replace('/[^A-Za-z0-9\-_]/','_', $data['dpr_no']) . '.pdf';

if ($MODE_STRING) {
  $GLOBALS['__DPR_PDF_RESULT__'] = [
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
