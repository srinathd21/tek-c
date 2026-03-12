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

function draw_left_merged_fixed($pdf, $x, $y, $wL, $wS, $label, $title, $height){
  $pdf->SetFillColor(220,220,220);
  $pdf->SetFont($pdf->ff, 'B', 11);
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

function get_first($arr, $keys, $default=''){
  if (!is_array($arr)) return $default;
  foreach ((array)$keys as $k) {
    if (array_key_exists($k, $arr) && $arr[$k] !== null) return $arr[$k];
  }
  return $default;
}

function to_number($v){
  if ($v === null) return 0;
  $v = trim((string)$v);
  if ($v === '') return 0;
  $v = preg_replace('/[^0-9\.\-]/', '', $v);
  if ($v === '' || $v === '-' || $v === '.' || $v === '-.') return 0;
  return (float)$v;
}

function build_numbered_remarks($rows, $key, $startIndex = 0){
  $lines = [];
  $n = (int)$startIndex + 1;
  foreach ($rows as $r) {
    $txt = '';
    if (is_array($r) && array_key_exists($key, $r)) $txt = clean_text($r[$key]);
    $lines[] = $n . '.' . ($txt !== '' ? ' ' . $txt : '');
    $n++;
  }
  return implode("\n", $lines);
}

/**
 * Safe filename for SITE (no # needed)
 * Keep letters, numbers, space, underscore, dash, dot.
 */
function safe_filename_site($s){
  $s = clean_text($s);
  $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $s);
  $s = preg_replace('/[^A-Za-z0-9 \-\_\.]/', '_', $s);
  $s = preg_replace('/\s+/', ' ', $s);
  $s = preg_replace('/_+/', '_', $s);
  $s = trim($s, " ._-");
  return $s;
}

/**
 * Safe filename (KEEP #) — do NOT URL-encode.
 * Keep letters, numbers, space, underscore, dash, dot, and #
 */
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

// RFC5987 filename* encoding (UTF-8)
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
    'agency'   => (string)get_first($r, ['agency','vendor','firm','contractor','company','name'], ''),
    'category' => (string)get_first($r, ['category','type','trade','designation'], ''),
    'unit'     => (string)get_first($r, ['unit','uom'], ''),
    'qty'      => (string)get_first($r, ['qty','quantity','count','nos'], ''),
    'remark'   => (string)get_first($r, ['remark','remarks','note','notes'], ''),
  ];
}
$data['manpower'] = $tmp;

// Normalize Material
$tmp = [];
foreach ($data['material'] as $r) {
  $tmp[] = [
    'vendor'   => (string)get_first($r, ['vendor','supplier','firm','company','name'], ''),
    'material' => (string)get_first($r, ['material','item','material_name','description'], ''),
    'unit'     => (string)get_first($r, ['unit','uom'], ''),
    'qty'      => (string)get_first($r, ['qty','quantity'], ''),
    'remark'   => (string)get_first($r, ['remark','remarks','note','notes'], ''),
  ];
}
$data['material'] = $tmp;

// ---------------- PDF class ----------------
class DPRPDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 8;
  public $outerY = 8;
  public $outerW = 0;

  public $GREY  = [220,220,220];
  public $gapAfterHeader = 8;

  public $ff = 'Arial';
  public $TITLE_SIZE = 14;
  public $CONTENT_SIZE = 11;

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

  function Footer(){
    $this->SetY(-20);
    $this->SetFont($this->ff, 'I', $this->CONTENT_SIZE);

    $company = (string)($this->meta['company_name'] ?? '');
    $this->Cell(0, 12, $company, 0, 0, 'L');

    $pageText = $this->PageNo() . ' / {nb}';
    $pageTextWidth = $this->GetStringWidth($pageText);
    $this->SetX(($this->GetPageWidth() - $pageTextWidth) / 2);
    $this->Cell($pageTextWidth, 12, $pageText, 0, 0, 'C');
  }

  function Header(){
    $this->SetLineWidth(0.35);

    $this->outerW = $this->GetPageWidth() - 16;
    $outerH = $this->GetPageHeight() - 16;
    $this->Rect($this->outerX, $this->outerY, $this->outerW, $outerH);

    $X0 = $this->outerX;
    $Y0 = $this->outerY;

    $headerH = 32;
    $logoW   = 32;
    $rightW  = 115;
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
    $rH = $headerH / 5;
    $labW = 28;
    $valW = $rightW - $labW;

    $rows = [
      ['Project', "Mr." . ($this->meta['project_name'] ?? '')],
      ['Client',  "Mr." . ($this->meta['client_name'] ?? '')],
      ['PMC',     "Mr." . ($this->meta['pmc_name'] ?? '')],
      ['DPR',     $this->meta['dpr_no'] ?? ''],
      ['DPR Date', trim((string)($this->meta['dpr_date'] ?? ''))],
    ];

    for($i=0;$i<5;$i++){
      $this->SetXY($rx, $ry + $i*$rH);

      $this->SetFont($this->ff,'B',$this->CONTENT_SIZE);
      $this->Cell($labW, $rH, $rows[$i][0], 1, 0, 'L');

      $txt = (string)$rows[$i][1];
      $fs = $this->CONTENT_SIZE;
      $this->SetFont($this->ff,'', $fs);
      while ($fs > 8 && $this->GetStringWidth($txt) > ($valW - 2)) {
        $fs -= 0.5;
        $this->SetFont($this->ff,'', $fs);
      }
      $this->Cell($valW, $rH, $txt, 1, 0, 'L');
    }

    $this->SetY($Y0 + $headerH + $this->gapAfterHeader);
  }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - 16)) {
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

  function DrawRowFixedFill($x, $widths, $cells, $h, $aligns, $fills){
    $this->EnsureSpace($h);
    $this->SetX($x);

    for($i=0;$i<count($cells);$i++){
      $w   = $widths[$i];
      $txt = $this->FitText($w, $cells[$i] ?? '');
      $al  = $aligns[$i] ?? 'L';

      $doFill = !empty($fills[$i]) && is_array($fills[$i]);
      if ($doFill) {
        $rgb = $fills[$i];
        $this->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
      }

      $this->Cell($w, $h, $txt, 1, 0, $al, $doFill);
    }

    $this->Ln($h);
  }
}

// ---------------- setup PDF with A3 ----------------
$pdf = new DPRPDF('P', 'mm', 'A3');
$pdf->InitFonts();
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);
$pdf->gapAfterHeader = 8;
$pdf->AliasNbPages('{nb}');

// Prefer logo from DB if it resolves to a real file, else fallback candidates
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

// Geometry
$X0 = 8;
$W  = $pdf->GetPageWidth() - 16;

$wL  = 14;
$wS  = 38;
$h   = 7;
$gap = 8;

$avail = $W - ($wL + $wS);
$xR = $X0 + $wL + $wS;

// ========================= A. Schedule =========================
$pdf->SetFont($pdf->ff,'B',11);

$segH = $h*3;
$pdf->EnsureSpace($segH + $gap);
$yA = $pdf->GetY();

draw_left_merged_fixed($pdf, $X0, $yA, $wL, $wS, 'A.', 'Schedule', $segH);

list($wDateBlock, $wDurBlock) = split_widths($avail, [0.62, 0.38]);
list($wStart, $wEnd, $wProj) = split_widths($wDateBlock, [0.30, 0.34, 0.36]);
list($wTotal, $wElap, $wBal) = split_widths($wDurBlock, [0.3333, 0.3333, 0.3334]);

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

$pdf->SetFont($pdf->ff,'',11);
$pdf->SetX($xR);
$pdf->Cell($wStart, $h, $pdf->FitText($wStart, $data['schedule_start']), 1, 0, 'L');
$pdf->Cell($wEnd,   $h, $pdf->FitText($wEnd,   $data['schedule_end']),   1, 0, 'L');
$pdf->Cell($wProj,  $h, $pdf->FitText($wProj,  $data['projected']),      1, 0, 'L');
$pdf->Cell($wTotal, $h, $pdf->FitText($wTotal, $data['dur_total']),      1, 0, 'L');
$pdf->Cell($wElap,  $h, $pdf->FitText($wElap,  $data['dur_elapsed']),    1, 0, 'L');
$pdf->Cell($wBal,   $h, $pdf->FitText($wBal,   $data['dur_balance']),    1, 1, 'L');

end_section($pdf, $yA, $segH, $gap);

// ========================= B. Site =========================
$pdf->SetFont($pdf->ff,'B',11);
$segH = $h*2;
$pdf->EnsureSpace($segH + $gap);
$yB = $pdf->GetY();

draw_left_merged_fixed($pdf, $X0, $yB, $wL, $wS, 'B.', 'Site', $segH);

$half = $avail / 2;

$opt  = 43;
$opt1 = 71.5;
$opt2 = 56.5;
$opt3 = $avail - ($opt + $opt1 + $opt2);

$pdf->SetXY($xR, $yB);
$pdf->Cell($half, $h, 'Weather', 1, 0, 'C');
$pdf->Cell($half, $h, 'Site Conditions', 1, 1, 'C');

$pdf->SetFont($pdf->ff,'',11);

$wVal = strtolower(trim((string)$data['weather']));
$sVal = strtolower(trim((string)$data['site_condition']));

$wNorm = ($wVal === 'normal');
$wRain = ($wVal === 'rainy');
$cNorm = ($sVal === 'normal');
$cSl   = ($sVal === 'slushy');

$pdf->SetX($xR);
$pdf->SetFillColor(255,255,102);
$pdf->Cell($opt,  $h, 'Normal', 1, 0, 'L', $wNorm);
$pdf->Cell($opt1, $h, 'Rainy',  1, 0, 'L', $wRain);
$pdf->Cell($opt2, $h, 'Normal', 1, 0, 'L', $cNorm);
$pdf->Cell($opt3, $h, 'Slushy', 1, 1, 'L', $cSl);

end_section($pdf, $yB, $segH, $gap);

// ========================= (Your remaining sections C..H are unchanged) =========================
// IMPORTANT: keep your existing code for C..H exactly as you already have.
// For brevity, I’m not repeating your entire C..H blocks here because nothing changes in them.
// If you want, paste your full file and I’ll return it fully expanded again.


// ---------------- output (FILENAME FIXED + SITE NAME KEPT + NO %23) ----------------
// Desired example: Mr.Anandhamayam_DPR_#01_Dated_24-02-2026.pdf
// %23 appears when client URL-encodes '#'. We mitigate by sending filename* also.

$sitePart = safe_filename_site($data['project_name'] ?? '');
$dprPart  = safe_filename_keep_hash($data['dpr_no'] ?? '');
$datePart = safe_filename_site($data['dpr_date'] ?? '');

if ($sitePart === '') $sitePart = 'SITE';
if ($dprPart === '')  $dprPart  = 'ID_' . $viewId;
if ($datePart === '') $datePart = date('d-m-Y');

$filename = 'Mr.' . $sitePart . '_DPR_' . $dprPart . '_Dated_' . $datePart . '.pdf';

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