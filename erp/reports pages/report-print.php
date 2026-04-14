<?php
ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId    = (int)($_SESSION['employee_id'] ?? 0);
$MODE_STRING   = (isset($_GET['mode']) && $_GET['mode'] === 'string');
$forceDownload = (isset($_GET['dl']) && $_GET['dl'] == '1');

$conn = get_db_connection();
if (!$conn) die("DB connection failed");

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid DPR id");

// ---------------- COMPANY (for footer / header branding) ----------------
$companyName = 'TEK-C Construction Pvt. Ltd.';
$companyLogoDb = '';
$companySql = "SELECT company_name, logo_path FROM company_details WHERE id = 1 LIMIT 1";
$companyResult = mysqli_query($conn, $companySql);
if ($companyResult) {
  $companyData = mysqli_fetch_assoc($companyResult);
  if (!empty($companyData['company_name'])) $companyName = $companyData['company_name'];
  if (!empty($companyData['logo_path'])) $companyLogoDb = $companyData['logo_path'];
}

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

  // Convert to Windows-1252 for PDF compatibility
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
  if (is_array($json)) return $json;
  if ($json === null) return [];

  $json = trim((string)$json);
  if ($json === '') return [];

  $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

  $tryDecode = function($str){
    $arr = json_decode($str, true);
    if (json_last_error() === JSON_ERROR_NONE) return $arr;
    return null;
  };

  $arr = $tryDecode($json);
  if ($arr === null) $arr = $tryDecode(stripslashes($json));

  if (is_string($arr)) {
    $arr2 = $tryDecode($arr);
    if (is_array($arr2)) $arr = $arr2;
  }

  if (is_array($arr)) {
    if (isset($arr['rows']) && is_array($arr['rows'])) return $arr['rows'];
    if (isset($arr['data']) && is_array($arr['data'])) return $arr['data'];

    $isAssoc = array_keys($arr) !== range(0, count($arr) - 1);
    if ($isAssoc) return [$arr];

    return $arr;
  }

  $s = @unserialize($json);
  return is_array($s) ? $s : [];
}

function split_widths($total, $parts){
  $out = [];
  $sum = 0.0;
  for ($i=0; $i<count($parts); $i++){
    $w = round($total * (float)$parts[$i], 1);
    $out[] = $w;
    $sum += $w;
  }
  if (!empty($out)) {
    $diff = round($total - $sum, 1);
    $out[count($out)-1] = round($out[count($out)-1] + $diff, 1);
  }
  return $out;
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

function get_first($arr, $keys, $default=''){
  if (!is_array($arr)) return $default;
  foreach ((array)$keys as $k) {
    if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') return $arr[$k];
  }
  return $default;
}

function safe_filename_site($s){
  $s = clean_text($s);
  $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $s);
  $s = preg_replace('/[^A-Za-z0-9 \-\_\.]/', '_', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  $s = preg_replace('/_+/', '_', $s);
  $s = trim($s, " ._-");
  return $s;
}

function safe_filename_keep_hash($s){
  $s = clean_text($s);
  $s = preg_replace('/[\r\n\t]+/', ' ', $s);
  $s = trim($s);

  $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $s);
  $s = preg_replace('/[^A-Za-z0-9 \#\-\_\.]/', '_', $s);

  $s = preg_replace('/\s+/', ' ', $s);
  $s = preg_replace('/_+/', '_', $s);
  $s = trim($s, " ._-");

  return $s;
}

function rfc5987_encode($str){
  return "UTF-8''" . rawurlencode($str);
}

// ---------------- load DPR ----------------
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
$pmcName = 'UKB Construction Management Pvt Ltd';
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
$data['company_name']   = clean_text($companyName);
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

// Normalize Manpower
$tmp = [];
foreach ($data['manpower'] as $r) {
  $tmp[] = [
    'agency'   => (string)get_first($r, ['agency', 'vendor', 'firm', 'contractor', 'company', 'name'], ''),
    'category' => (string)get_first($r, ['category', 'type', 'trade', 'designation'], ''),
    'unit'     => (string)get_first($r, ['unit', 'uom'], ''),
    'qty'      => (string)get_first($r, ['qty', 'quantity', 'count', 'nos'], ''),
    'remark'   => (string)get_first($r, ['remark', 'remarks', 'note', 'notes'], ''),
  ];
}
$data['manpower'] = $tmp;

// Calculate total manpower
$totalManpower = 0;
foreach ($data['manpower'] as $m) {
  $totalManpower += (float)$m['qty'];
}

// Normalize Machinery
$tmp = [];
foreach ($data['machinery'] as $r) {
  $tmp[] = [
    'equipment' => (string)get_first($r, ['equipment', 'machinery', 'item', 'description', 'type'], ''),
    'unit'      => (string)get_first($r, ['unit', 'uom'], ''),
    'qty'       => (string)get_first($r, ['qty', 'quantity', 'count', 'nos'], ''),
    'remark'    => (string)get_first($r, ['remark', 'remarks', 'note', 'notes'], ''),
  ];
}
$data['machinery'] = $tmp;

// Normalize Material
$tmp = [];
foreach ($data['material'] as $r) {
  $tmp[] = [
    'vendor'   => (string)get_first($r, ['vendor', 'supplier', 'firm', 'company', 'name'], ''),
    'material' => (string)get_first($r, ['material', 'item', 'material_name', 'description'], ''),
    'unit'     => (string)get_first($r, ['unit', 'uom'], ''),
    'qty'      => (string)get_first($r, ['qty', 'quantity'], ''),
    'remark'   => (string)get_first($r, ['remark', 'remarks', 'note', 'notes'], ''),
  ];
}
$data['material'] = $tmp;

// Normalize Work Progress
$tmp = [];
foreach ($data['workprog'] as $r) {
  $tmp[] = [
    'task'     => (string)get_first($r, ['task', 'description', 'item', 'work', 'activity'], ''),
    'duration' => (string)get_first($r, ['duration', 'planned_days'], ''),
    'start'    => dmy_dash(get_first($r, ['start', 'start_date'], '')),
    'end'      => dmy_dash(get_first($r, ['end', 'end_date'], '')),
    'status'   => (string)get_first($r, ['status', 'progress_status'], ''),
    'reasons'  => (string)get_first($r, ['reasons', 'remarks', 'note', 'notes'], ''),
  ];
}
$data['workprog'] = $tmp;

// Normalize Constraints
$tmp = [];
foreach ($data['constraints'] as $r) {
  $tmp[] = [
    'issue'  => (string)get_first($r, ['issue', 'constraint', 'description'], ''),
    'status' => (string)get_first($r, ['status', 'constraint_status'], ''),
    'date'   => dmy_dash(get_first($r, ['date', 'constraint_date'], '')),
    'remark' => (string)get_first($r, ['remark', 'remarks', 'note', 'notes'], ''),
  ];
}
$data['constraints'] = $tmp;

// ---------------- PDF class ----------------
class DPRPDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 5;
  public $outerY = 5;
  public $outerW = 0;

  public $GREY  = [220,220,220];
  public $gapAfterHeader = 5;

  public $ff = 'Arial';
  public $TITLE_SIZE = 14;
  public $CONTENT_SIZE = 10;

  function InitFonts(){
    $fontDir = __DIR__ . '/libs/fpdf/font/';
    $reg    = $fontDir . 'calibri.php';
    $bold   = $fontDir . 'calibrib.php';
    $italic = $fontDir . 'calibrii.php';
    $bi     = $fontDir . 'calibriz.php';

    if (file_exists($reg) && file_exists($bold)) {
      $this->AddFont('Calibri', '', 'calibri.php');
      $this->AddFont('Calibri', 'B', 'calibrib.php');
      if (file_exists($italic)) $this->AddFont('Calibri', 'I', 'calibrii.php');
      if (file_exists($bi))     $this->AddFont('Calibri', 'BI', 'calibriz.php');
      $this->ff = 'Calibri';
    } else {
      $this->ff = 'Arial';
    }
  }

  function SetMeta($meta){ $this->meta = $meta; }


  function Header(){
    $this->SetLineWidth(0.3);
    
    $this->outerW = $this->GetPageWidth() - 10;
    $outerH = $this->GetPageHeight() - 10;
    $this->Rect($this->outerX, $this->outerY, $this->outerW, $outerH);

    $X0 = $this->outerX;
    $Y0 = $this->outerY;

    $headerH = 30;
    $logoW   = 30;
    $rightW  = 82;
    $titleW  = $this->outerW - $logoW - $rightW;

    $this->SetXY($X0, $Y0);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C');

    if ($this->logoPath && file_exists($this->logoPath)) {
      $this->Image($this->logoPath, $X0+2, $Y0+2, $logoW-4, $headerH-4);
    }

    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->SetFont($this->ff, 'B', $this->TITLE_SIZE);
    $this->Cell($titleW, $headerH, 'DAILY PROGRESS REPORT (DPR)', 1, 0, 'C', true);

    $rx = $X0 + $logoW + $titleW;
    $ry = $Y0;
    $rH = $headerH / 4;
    $labW = 28;
    $valW = $rightW - $labW;

    $rows = [
      ['Project', $this->meta['project_name'] ?? ''],
      ['Client',  $this->meta['client_name'] ?? ''],
      ['PMC',     $this->meta['pmc_name'] ?? ''],
      ['DPR No / Date', $this->meta['dpr_no'] . ' / ' . trim((string)($this->meta['dpr_date'] ?? ''))],
      
    ];

    for($i=0;$i<4;$i++){
      $this->SetXY($rx, $ry + $i*$rH);
      $this->SetFont($this->ff,'B',9);
      $this->Cell($labW, $rH, $rows[$i][0], 1, 0, 'L');

      $txt = (string)$rows[$i][1];
      $fs = 9;
      $this->SetFont($this->ff,'', $fs);
      while ($fs > 7 && $this->GetStringWidth($txt) > ($valW - 2)) {
        $fs -= 0.5;
        $this->SetFont($this->ff,'', $fs);
      }
      $this->Cell($valW, $rH, $txt, 1, 0, 'L');
    }

    $this->SetY($Y0 + $headerH + $this->gapAfterHeader);
  }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - 15)) {
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
  
  function MultiCellFit($w, $h, $txt, $border=1, $align='L'){
    $txt = trim((string)$txt);
    if ($txt === '') {
      $this->Cell($w, $h, '', $border, 0, $align);
      return;
    }
    $this->MultiCell($w, $h, $txt, $border, $align);
  }
}

// ---------------- setup PDF with Portrait A4 ----------------
$pdf = new DPRPDF('P', 'mm', 'A4');
$pdf->InitFonts();
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.3);
$pdf->gapAfterHeader = 5;
$pdf->AliasNbPages('{nb}');

// Logo candidates
$logoCandidates = [];

if (!empty($companyLogoDb)) {
  $p1 = __DIR__ . '/' . ltrim($companyLogoDb, '/');
  $p2 = __DIR__ . '/../' . ltrim($companyLogoDb, '/');
  $logoCandidates[] = $p1;
  $logoCandidates[] = $p2;
}

$logoCandidates = array_merge($logoCandidates, [
  __DIR__ . '/public/logo.png',
  __DIR__ . '/assets/logo.png',
  __DIR__ . '/images/logo.png',
  __DIR__ . '/logo.png',
  __DIR__ . '/assets/ukb.png',
  __DIR__ . '/assets/ukb.jpg',
]);

foreach ($logoCandidates as $p) {
  if ($p && file_exists($p)) { $pdf->logoPath = $p; break; }
}

$pdf->SetMeta($data);
$pdf->AddPage();

// Geometry for Portrait A4 (210mm width)
$X0 = 5;
$W  = $pdf->GetPageWidth() - 10; // 200mm

$wL  = 12;
$wS  = 35;
$h   = 6;
$gap = 6;

$avail = $W - ($wL + $wS);
$xR = $X0 + $wL + $wS;

// ========================= A. Schedule =========================
$pdf->SetFont($pdf->ff,'B',10);

$segH = $h*3;
$pdf->EnsureSpace($segH + $gap);
$yA = $pdf->GetY();

// Draw left label for A
$pdf->SetFillColor(220,220,220);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetXY($X0, $yA);
$pdf->Cell($wL, $segH, 'A.', 1, 0, 'C', true);
$pdf->Cell($wS, $segH, 'Schedule', 1, 0, 'C', true);

list($wDateBlock, $wDurBlock) = split_widths($avail, [0.62, 0.38]);
list($wStart, $wEnd, $wProj) = split_widths($wDateBlock, [0.30, 0.34, 0.36]);
list($wTotal, $wElap, $wBal) = split_widths($wDurBlock, [0.3333, 0.3333, 0.3334]);

$pdf->SetXY($xR, $yA);
$pdf->SetFont($pdf->ff, 'B', 10);
$pdf->Cell($wDateBlock, $h, 'Date', 1, 0, 'C');
$pdf->Cell($wDurBlock,  $h, 'Duration', 1, 1, 'C');

$pdf->SetFont($pdf->ff, 'B', 9);
$pdf->SetX($xR);
$pdf->Cell($wStart, $h, 'Start', 1, 0, 'L');
$pdf->Cell($wEnd,   $h, 'End', 1, 0, 'L');
$pdf->Cell($wProj,  $h, 'Projected', 1, 0, 'L');
$pdf->Cell($wTotal, $h, 'Total', 1, 0, 'L');
$pdf->Cell($wElap,  $h, 'Elapsed', 1, 0, 'L');
$pdf->Cell($wBal,   $h, 'Balance', 1, 1, 'L');

$pdf->SetFont($pdf->ff,'',9);
$pdf->SetX($xR);
$pdf->Cell($wStart, $h, $pdf->FitText($wStart, $data['schedule_start']), 1, 0, 'L');
$pdf->Cell($wEnd,   $h, $pdf->FitText($wEnd,   $data['schedule_end']),   1, 0, 'L');
$pdf->Cell($wProj,  $h, $pdf->FitText($wProj,  $data['projected']),      1, 0, 'L');
$pdf->Cell($wTotal, $h, $pdf->FitText($wTotal, $data['dur_total']),      1, 0, 'L');
$pdf->Cell($wElap,  $h, $pdf->FitText($wElap,  $data['dur_elapsed']),    1, 0, 'L');
$pdf->Cell($wBal,   $h, $pdf->FitText($wBal,   $data['dur_balance']),    1, 1, 'L');

$pdf->SetY($yA + $segH + $gap);

// ========================= B. Site =========================
$pdf->SetFont($pdf->ff,'B',10);
$segH = $h*2;
$pdf->EnsureSpace($segH + $gap);
$yB = $pdf->GetY();

// Draw left label for B
$pdf->SetFillColor(220,220,220);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetXY($X0, $yB);
$pdf->Cell($wL, $segH, 'B.', 1, 0, 'C', true);
$pdf->Cell($wS, $segH, 'Site', 1, 0, 'C', true);

$half = $avail / 2;

$opt  = 43;
$opt1 = 71.5;
$opt2 = 56.5;
$opt3 = $avail - ($opt + $opt1 + $opt2);

$pdf->SetXY($xR, $yB);
$pdf->SetFont($pdf->ff, 'B', 10);
$pdf->Cell($half, $h, 'Weather', 1, 0, 'C');
$pdf->Cell($half, $h, 'Site Conditions', 1, 1, 'C');

$pdf->SetFont($pdf->ff,'',9);

$wVal = strtolower(trim((string)$data['weather']));
$sVal = strtolower(trim((string)$data['site_condition']));

$wNorm = ($wVal === 'normal');
$wRain = ($wVal === 'rainy');
$cNorm = ($sVal === 'normal');
$cSl   = ($sVal === 'slushy');

// total available width
$colWidth = $avail / 4;

$pdf->SetX($xR);
$pdf->SetFillColor(255,255,102);

$pdf->Cell($colWidth, $h, 'Normal', 1, 0, 'C', $wNorm);
$pdf->Cell($colWidth, $h, 'Rainy',  1, 0, 'C', $wRain);
$pdf->Cell($colWidth, $h, 'Normal', 1, 0, 'C', $cNorm);
$pdf->Cell($colWidth, $h, 'Slushy', 1, 1, 'C', $cSl);

$pdf->SetY($yB + $segH + $gap);

// ========================= C. Manpower =========================
$pdf->SetFont($pdf->ff,'B',10);
$rowCount = count($data['manpower']);
$totalRows = $rowCount + 2; // +2 for header row and total row
$segH = $h * $totalRows;
$pdf->EnsureSpace($segH + $gap);
$yC = $pdf->GetY();

// Draw left label for C (spanning full height)
$pdf->SetFillColor(220,220,220);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetXY($X0, $yC);
$pdf->Cell($wL, $segH, 'C.', 1, 0, 'C', true);
$pdf->Cell($wS, $segH, 'Manpower', 1, 0, 'C', true);

$widths = split_widths($avail, [0.25, 0.25, 0.10, 0.10, 0.30]);
list($wAgency, $wCategory, $wUnit, $wQty, $wRemark) = $widths;

$pdf->SetXY($xR, $yC);
$pdf->SetFont($pdf->ff, 'B', 9);
$pdf->Cell($wAgency,   $h, 'Agency', 1, 0, 'C');
$pdf->Cell($wCategory, $h, 'Category', 1, 0, 'C');
$pdf->Cell($wUnit,     $h, 'Unit', 1, 0, 'C');
$pdf->Cell($wQty,      $h, 'Qty', 1, 0, 'C');
$pdf->Cell($wRemark,   $h, 'Remark', 1, 1, 'C');

$pdf->SetFont($pdf->ff, '', 9);
if (empty($data['manpower'])) {
  $pdf->SetX($xR);
  $pdf->Cell($avail, $h, 'No manpower data available', 1, 1, 'L');
} else {
  foreach ($data['manpower'] as $row) {
    $pdf->SetX($xR);
    $pdf->Cell($wAgency,   $h, $pdf->FitText($wAgency,   $row['agency']),   1, 0, 'L');
    $pdf->Cell($wCategory, $h, $pdf->FitText($wCategory, $row['category']), 1, 0, 'L');
    $pdf->Cell($wUnit,     $h, $pdf->FitText($wUnit,     $row['unit']),     1, 0, 'C');
    $pdf->Cell($wQty,      $h, $pdf->FitText($wQty,      $row['qty']),      1, 0, 'C');
    $pdf->Cell($wRemark,   $h, $pdf->FitText($wRemark,   $row['remark']),   1, 1, 'L');
  }
  
  // Total Manpower row
  $pdf->SetFont($pdf->ff, 'B', 9);
  $pdf->SetX($xR);
  $pdf->Cell($wAgency + $wCategory, $h, 'Total Manpower', 1, 0, 'R');
  $pdf->Cell($wUnit, $h, 'Nos', 1, 0, 'C');
  $pdf->Cell($wQty, $h, $totalManpower, 1, 0, 'C');
  $pdf->Cell($wRemark, $h, '', 1, 1, 'L');
  $pdf->SetFont($pdf->ff, '', 9);
}

$pdf->SetY($yC + $segH + $gap);

// ========================= D. Machinery =========================
$pdf->SetFont($pdf->ff,'B',10);
$rowCount = count($data['machinery']);
$totalRows = $rowCount + 1; // +1 for header row
$segH = $h * $totalRows;
$pdf->EnsureSpace($segH + $gap);
$yD = $pdf->GetY();

// Draw left label for D (spanning full height)
$pdf->SetFillColor(220,220,220);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetXY($X0, $yD);
$pdf->Cell($wL, $segH, 'D.', 1, 0, 'C', true);
$pdf->Cell($wS, $segH, 'Machinery', 1, 0, 'C', true);

$widths = split_widths($avail, [0.50, 0.15, 0.15, 0.20]);
list($wEquipment, $wUnit, $wQty, $wRemark) = $widths;

$pdf->SetXY($xR, $yD);
$pdf->SetFont($pdf->ff, 'B', 9);
$pdf->Cell($wEquipment, $h, 'Type of Equipment', 1, 0, 'C');
$pdf->Cell($wUnit,      $h, 'Unit', 1, 0, 'C');
$pdf->Cell($wQty,       $h, 'Qty', 1, 0, 'C');
$pdf->Cell($wRemark,    $h, 'Remark', 1, 1, 'C');

$pdf->SetFont($pdf->ff, '', 9);
if (empty($data['machinery'])) {
  $pdf->SetX($xR);
  $pdf->Cell($avail, $h, 'No machinery data available', 1, 1, 'L');
} else {
  foreach ($data['machinery'] as $row) {
    $pdf->SetX($xR);
    $pdf->Cell($wEquipment, $h, $pdf->FitText($wEquipment, $row['equipment']), 1, 0, 'L');
    $pdf->Cell($wUnit,      $h, $pdf->FitText($wUnit,      $row['unit']),      1, 0, 'C');
    $pdf->Cell($wQty,       $h, $pdf->FitText($wQty,       $row['qty']),       1, 0, 'C');
    $pdf->Cell($wRemark,    $h, $pdf->FitText($wRemark,    $row['remark']),    1, 1, 'L');
  }
}

$pdf->SetY($yD + $segH + $gap);

// ========================= E. Material =========================
$pdf->SetFont($pdf->ff,'B',10);
$rowCount = count($data['material']);
$totalRows = $rowCount + 1; // +1 for header row
$segH = $h * $totalRows;
$pdf->EnsureSpace($segH + $gap);
$yE = $pdf->GetY();

// Draw left label for E (spanning full height)
$pdf->SetFillColor(220,220,220);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetXY($X0, $yE);
$pdf->Cell($wL, $segH, 'E.', 1, 0, 'C', true);
$pdf->Cell($wS, $segH, 'Material', 1, 0, 'C', true);

$widths = split_widths($avail, [0.25, 0.35, 0.10, 0.10, 0.20]);
list($wVendor, $wMaterial, $wUnit, $wQty, $wRemark) = $widths;

$pdf->SetXY($xR, $yE);
$pdf->SetFont($pdf->ff, 'B', 9);
$pdf->Cell($wVendor,   $h, 'Vendor', 1, 0, 'C');
$pdf->Cell($wMaterial, $h, 'Material', 1, 0, 'C');
$pdf->Cell($wUnit,     $h, 'Unit', 1, 0, 'C');
$pdf->Cell($wQty,      $h, 'Qty', 1, 0, 'C');
$pdf->Cell($wRemark,   $h, 'Remark', 1, 1, 'C');

$pdf->SetFont($pdf->ff, '', 9);
if (empty($data['material'])) {
  $pdf->SetX($xR);
  $pdf->Cell($avail, $h, 'No material data available', 1, 1, 'L');
} else {
  foreach ($data['material'] as $row) {
    $pdf->SetX($xR);
    $pdf->Cell($wVendor,   $h, $pdf->FitText($wVendor,   $row['vendor']),   1, 0, 'L');
    $pdf->Cell($wMaterial, $h, $pdf->FitText($wMaterial, $row['material']), 1, 0, 'L');
    $pdf->Cell($wUnit,     $h, $pdf->FitText($wUnit,     $row['unit']),     1, 0, 'C');
    $pdf->Cell($wQty,      $h, $pdf->FitText($wQty,      $row['qty']),      1, 0, 'C');
    $pdf->Cell($wRemark,   $h, $pdf->FitText($wRemark,   $row['remark']),   1, 1, 'L');
  }
}

$pdf->SetY($yE + $segH + $gap);

// ========================= F. Work Progress =========================
$pdf->SetFont($pdf->ff,'B',10);

$yF = $pdf->GetY(); // start position

// WIDTHS
list($wTask, $wDuration, $wStart, $wEnd, $wIn, $wDelay, $wReasons)
    = split_widths($avail, [0.28, 0.10, 0.12, 0.12, 0.08, 0.08, 0.22]);

$pdf->SetXY($xR, $yF);
$pdf->SetFont($pdf->ff, 'B', 9);

// ================= HEADER ROW 1 =================
$pdf->Cell($wTask, $h*2, 'Task', 1, 0, 'C');
$pdf->Cell($wDuration + $wStart + $wEnd, $h, 'Weekly Schedule', 1, 0, 'C');
$pdf->Cell($wIn + $wDelay, $h, 'Status', 1, 0, 'C');
$pdf->Cell($wReasons, $h*2, 'Reasons', 1, 1, 'C');

// ================= HEADER ROW 2 =================
$pdf->SetX($xR + $wTask);
$pdf->Cell($wDuration, $h, 'Duration', 1, 0, 'C');
$pdf->Cell($wStart,    $h, 'Start', 1, 0, 'C');
$pdf->Cell($wEnd,      $h, 'End', 1, 0, 'C');
$pdf->Cell($wIn,       $h, 'In', 1, 0, 'C');
$pdf->Cell($wDelay,    $h, 'Delay', 1, 1, 'C');

// ================= DATA =================
$pdf->SetFont($pdf->ff, '', 9);

if (empty($data['workprog'])) {
    $pdf->SetX($xR);
    $pdf->Cell($avail, $h, 'No work progress data available', 1, 1, 'L');
} else {
    foreach ($data['workprog'] as $row) {

        $pdf->SetX($xR);

        $pdf->Cell($wTask, $h, $pdf->FitText($wTask, $row['task']), 1);
        $pdf->Cell($wDuration, $h, $row['duration'], 1, 0, 'C');
        $pdf->Cell($wStart, $h, $row['start'], 1, 0, 'C');
        $pdf->Cell($wEnd, $h, $row['end'], 1, 0, 'C');

        $status = strtolower(trim($row['status'] ?? ''));
        $isDelay = in_array($status, ['delay','delayed']);

        // IN
        if (!$isDelay) {
            $pdf->SetFillColor(200,255,200);
            $pdf->Cell($wIn, $h, 'X', 1, 0, 'C', true);
        } else {
            $pdf->Cell($wIn, $h, '', 1);
        }

        // DELAY
        if ($isDelay) {
            $pdf->SetFillColor(255,200,200);
            $pdf->Cell($wDelay, $h, 'X', 1, 0, 'C', true);
        } else {
            $pdf->Cell($wDelay, $h, '', 1);
        }

        $pdf->Cell($wReasons, $h, $pdf->FitText($wReasons, $row['reasons']), 1, 1);
    }
}

// ================= GET ACTUAL HEIGHT =================
$yEnd = $pdf->GetY();
$actualHeight = $yEnd - $yF;

// ================= DRAW LEFT LABEL AFTER =================
$pdf->SetXY($X0, $yF);
$pdf->SetFillColor(220,220,220);
$pdf->SetFont($pdf->ff, 'B', 11);

$pdf->Cell($wL, $actualHeight, 'F.', 1, 0, 'C', true);
$pdf->Cell($wS, $actualHeight, 'Work Progress', 1, 0, 'C', true);

// move cursor
$pdf->SetY($yEnd + $gap);
// ========================= G. Constraints =========================
$pdf->SetFont($pdf->ff,'B',10);
$rowCount = count($data['constraints']);
$totalRows = $rowCount + 2; // +2 for header rows
$segH = $h * $totalRows;
$pdf->EnsureSpace($segH + $gap);
$yG = $pdf->GetY();

// Draw left label for G (spanning full height)
$pdf->SetFillColor(220,220,220);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetXY($X0, $yG);
$pdf->Cell($wL, $segH, 'G.', 1, 0, 'C', true);
$pdf->Cell($wS, $segH, 'Constraints', 1, 0, 'C', true);

// Define column widths for Constraints in Portrait
$wIssue = 70;
$wStatusOpen = 18;
$wStatusClosed = 18;
$wDate = 30;
$wRemark = $avail - ($wIssue + $wStatusOpen + $wStatusClosed + $wDate);

$pdf->SetXY($xR, $yG);
$pdf->SetFont($pdf->ff, 'B', 9);

// First header row
$pdf->Cell($wIssue, $h, 'Issues', 1, 0, 'C');
$pdf->Cell($wStatusOpen + $wStatusClosed, $h, 'Status', 1, 0, 'C');
$pdf->Cell($wDate, $h, 'Date', 1, 0, 'C');
$pdf->Cell($wRemark, $h, 'Remark', 1, 1, 'C');

// Second header row
$pdf->SetX($xR + $wIssue);
$pdf->Cell($wStatusOpen, $h, 'Open', 1, 0, 'C');
$pdf->Cell($wStatusClosed, $h, 'Closed', 1, 0, 'C');
$pdf->Cell($wDate, $h, '', 1, 0, 'C');
$pdf->Cell($wRemark, $h, '', 1, 1, 'C');

$pdf->SetFont($pdf->ff, '', 9);
if (empty($data['constraints'])) {
  $pdf->SetX($xR);
  $pdf->Cell($avail, $h, 'No constraints or issues reported', 1, 1, 'L');
} else {
  foreach ($data['constraints'] as $row) {
    $pdf->SetX($xR);
    $pdf->Cell($wIssue,  $h, $pdf->FitText($wIssue,  $row['issue']),  1, 0, 'L');
    
    $status = strtolower($row['status']);
    $isOpen = ($status === 'open');
    $isClosed = ($status === 'closed');
    
    // Open column - using 'V' for check (ASCII compatible)
    if ($isOpen) {
      $pdf->SetFillColor(255, 200, 200);
      $pdf->Cell($wStatusOpen, $h, 'X', 1, 0, 'C', true);
    } else {
      $pdf->Cell($wStatusOpen, $h, '', 1, 0, 'C', false);
    }
    
    // Closed column - using 'V' for check (ASCII compatible)
    if ($isClosed) {
      $pdf->SetFillColor(200, 255, 200);
      $pdf->Cell($wStatusClosed, $h, 'X', 1, 0, 'C', true);
    } else {
      $pdf->Cell($wStatusClosed, $h, '', 1, 0, 'C', false);
    }
    
    $pdf->Cell($wDate,   $h, $pdf->FitText($wDate,   $row['date']),   1, 0, 'C');
    $pdf->Cell($wRemark, $h, $pdf->FitText($wRemark, $row['remark']), 1, 1, 'L');
  }
}

$pdf->SetY($yG + $segH + $gap);

// ========================= H. Signatures =========================
$pdf->SetFont($pdf->ff,'B',10);
$segH = $h * 3;
$pdf->EnsureSpace($segH + $gap);
$yH = $pdf->GetY();

// Draw left label for H (spanning full height)
$pdf->SetFillColor(220,220,220);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetXY($X0, $yH);
$pdf->Cell($wL, $segH, 'H.', 1, 0, 'C', true);
$pdf->Cell($wS, $segH, 'Signatures', 1, 0, 'C', true);

$pdf->SetXY($xR, $yH);
$pdf->SetFont($pdf->ff, 'B', 9);
$pdf->Cell($avail/2, $h, 'Report Distribute To', 1, 0, 'C');
$pdf->Cell($avail/2, $h, 'Prepared By', 1, 1, 'C');

$pdf->SetFont($pdf->ff, '', 9);
$pdf->SetX($xR);
$pdf->Cell($avail/2, $h*2, $pdf->FitText($avail/2, $data['report_to_raw']), 1, 0, 'L');
$pdf->Cell($avail/2, $h*2, $pdf->FitText($avail/2, $data['prepared_by'] . "\n(" . $data['designation'] . ")"), 1, 1, 'L');

$pdf->SetY($yH + $segH + $gap);

// ---------------- output ----------------
$sitePart = safe_filename_site($data['project_name'] ?? '');
$dprPart  = safe_filename_keep_hash($data['dpr_no'] ?? '');
$datePart = safe_filename_site($data['dpr_date'] ?? '');

if ($sitePart === '') $sitePart = 'SITE';
if ($dprPart === '')  $dprPart  = 'ID_' . $viewId;
if ($datePart === '') $datePart = date('d-m-Y');

$filename = $sitePart . '_DPR_' . $dprPart . '_Dated_' . $datePart . '.pdf';

if ($MODE_STRING) {
  $pdfBytes = $pdf->Output('S');

  while (ob_get_level() > 0) {
    ob_end_clean();
  }

  $GLOBALS['__DPR_PDF_RESULT__'] = [
    'filename' => $filename,
    'bytes'    => $pdfBytes,
  ];

  try {
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
  } catch (Throwable $e) {}

  return;
}

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