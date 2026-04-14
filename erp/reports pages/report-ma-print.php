<?php
// report-ma-print.php — Meeting Agenda (MA) PDF (FPDF) — A3 PORTRAIT + FIXED ROW HEIGHT (6mm)
// Supports:
//   ?view=123        => inline view/print
//   ?view=123&dl=1   => force download
// MODIFIED: Filename output format changed to:
//   Mr.(Site name)_DPR #(DPR NO)_Dated (report date).pdf

ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId  = (int)($_SESSION['employee_id'] ?? 0);
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));
$MODE_STRING = (isset($_GET['mode']) && $_GET['mode'] === 'string');
$forceDownload = (isset($_GET['dl']) && $_GET['dl'] == '1');

$conn = get_db_connection();
if (!$conn) die("DB connection failed");

// Company name
$companySql = "SELECT company_name FROM company_details WHERE id = 1 LIMIT 1";
$companyResult = mysqli_query($conn, $companySql);
$companyData = $companyResult ? mysqli_fetch_assoc($companyResult) : null;
$companyName = $companyData['company_name'] ?? 'TEK-C Construction Pvt. Ltd.';

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
function fmt_due_date($v){
  $v = trim((string)$v);
  if ($v === '' || $v === '0000-00-00') return '';
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return dmy_dash($v);
  return $v;
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
function scope_level($designationLower){
  if (in_array($designationLower, ['director','vice president','general manager'], true)) return 'all';
  if ($designationLower === 'manager') return 'manager';
  return 'self';
}
function get_any($arr, $keys, $default=''){
  foreach ($keys as $k){
    if (isset($arr[$k]) && trim((string)$arr[$k]) !== '') return $arr[$k];
  }
  return $default;
}

// Safe filename helper
function safe_filename_part($s){
  $s = clean_text($s);
  // Replace spaces with underscore
  $s = preg_replace('/\s+/', '_', $s);
  // Remove characters unsafe for filenames
  $s = preg_replace('/[^A-Za-z0-9\-_\.]/', '_', $s);
  // Collapse multiple underscores
  $s = preg_replace('/_+/', '_', $s);
  $s = trim($s, "._-");
  return $s;
}

// ---------------- load MA ----------------
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid MA id");

$scope = scope_level($designation);

if ($scope === 'all') {
  $sql = "
    SELECT
      r.*,
      s.project_name, s.project_location, s.project_type,
      s.manager_employee_id,
      c.client_name
    FROM ma_reports r
    INNER JOIN sites s ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE r.id = ?
    LIMIT 1
  ";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) die(mysqli_error($conn));
  mysqli_stmt_bind_param($st, "i", $viewId);

} elseif ($scope === 'manager') {
  $sql = "
    SELECT
      r.*,
      s.project_name, s.project_location, s.project_type,
      s.manager_employee_id,
      c.client_name
    FROM ma_reports r
    INNER JOIN sites s ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE r.id = ? AND s.manager_employee_id = ?
    LIMIT 1
  ";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) die(mysqli_error($conn));
  mysqli_stmt_bind_param($st, "ii", $viewId, $employeeId);

} else {
  $sql = "
    SELECT
      r.*,
      s.project_name, s.project_location, s.project_type,
      s.manager_employee_id,
      c.client_name
    FROM ma_reports r
    INNER JOIN sites s ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE r.id = ? AND r.employee_id = ?
    LIMIT 1
  ";
  $st = mysqli_prepare($conn, $sql);
  if (!$st) die(mysqli_error($conn));
  mysqli_stmt_bind_param($st, "ii", $viewId, $employeeId);
}

mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($st);

if (!$row) die("MA not found or not allowed");

// Prepared by (from employees table)
$preparedName = '';
$preparedDesignation = '';
$empIdForMA = (int)($row['employee_id'] ?? 0);

if ($empIdForMA > 0) {
  $stE = mysqli_prepare($conn, "SELECT full_name, designation FROM employees WHERE id=? LIMIT 1");
  if ($stE) {
    mysqli_stmt_bind_param($stE, "i", $empIdForMA);
    mysqli_stmt_execute($stE);
    $rE = mysqli_stmt_get_result($stE);
    $erow = mysqli_fetch_assoc($rE);
    mysqli_stmt_close($stE);
    $preparedName = clean_text($erow['full_name'] ?? '');
    $preparedDesignation = clean_text($erow['designation'] ?? '');
  }
}
if ($preparedName === '') $preparedName = clean_text($_SESSION['employee_name'] ?? '');

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
$data['project_name']     = clean_text($row['project_name'] ?? '');
$data['client_name']      = clean_text($row['client_name'] ?? '');
$data['pmc_name']         = clean_text($pmcName);

$data['ma_no']            = clean_text($row['ma_no'] ?? '');
$data['ma_date']          = dmy_dash($row['ma_date'] ?? '');

$data['facilitator']      = clean_text($row['facilitator'] ?? '');
$data['meeting_place']    = clean_text($row['meeting_date_place'] ?? '');
$data['meeting_taken_by'] = clean_text($row['meeting_taken_by'] ?? '');
$data['meeting_number']   = clean_text($row['meeting_number'] ?? '');

$data['start_time']       = clean_text(get_any($row, ['meeting_start_time','start_time','meeting_start','startTime'], ''));
$data['end_time']         = clean_text(get_any($row, ['meeting_end_time','end_time','meeting_end','endTime'], ''));

$data['next_date']        = dmy_dash($row['next_meeting_date'] ?? '');
$data['next_start']       = clean_text(get_any($row, ['next_meeting_start_time','next_start_time','next_start'], ''));
$data['next_end']         = clean_text(get_any($row, ['next_meeting_end_time','next_end_time','next_end'], ''));

$data['prepared_by']      = $preparedName;
$data['designation']      = $preparedDesignation !== '' ? $preparedDesignation : clean_text($_SESSION['designation'] ?? '');

// JSON blocks
$data['objectives'] = decode_rows($row['objectives_json'] ?? '');
$data['attendees']  = decode_rows($row['attendees_json'] ?? '');
$data['notes']      = decode_rows($row['discussions_json'] ?? '');
$data['actions']    = decode_rows($row['actions_json'] ?? '');

// Minimum rows (keep at least 1 row so tables show)
if (count($data['objectives']) < 1) $data['objectives'] = [['text'=>'']];
if (count($data['attendees']) < 1)  $data['attendees']  = [['name'=>'','firm'=>'']];
if (count($data['notes']) < 1)      $data['notes']      = [['topic'=>'']];
if (count($data['actions']) < 1)    $data['actions']    = [['description'=>'','person'=>'','due'=>'']];

// ---------------- PDF class ----------------
class PDF extends FPDF {
  public $meta = [];
  public $companyName = '';
  public $logoPath = '';
  public $outerX = 6;
  public $outerY = 6;
  public $outerW = 0;
  public $sectionGap = 3;

  public $ff = 'Arial';

  function SetMeta($meta){ $this->meta = $meta; }

  function InitFonts(){
    $fontDir = __DIR__ . '/libs/fpdf/font/';
    $reg  = $fontDir . 'calibri.php';
    $bold = $fontDir . 'calibrib.php';

    if (file_exists($reg) && file_exists($bold)) {
      $this->AddFont('Calibri', '', 'calibri.php');
      $this->AddFont('Calibri', 'B', 'calibrib.php');
      $this->ff = 'Calibri';
    } else {
      $this->ff = 'Arial';
    }
  }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - 20)) {
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

  function SectionCell($x, $y, $w, $h, $text, $align='L'){
    $this->SetFillColor(220,220,220);
    $this->Rect($x, $y, $w, $h, 'DF');

    $fontSize = 11;
    $lineH = 5.0;

    while ($fontSize >= 8) {
      $this->SetFont($this->ff, 'B', $fontSize);
      $lines = $this->WrapTextByWidth($w - 2, $text);
      $needH = count($lines) * $lineH;

      if ($needH <= ($h - 2)) break;
      $fontSize -= 0.5;
    }

    $needH = count($lines) * $lineH;
    $startY = $y + (($h - $needH) / 2);

    $this->SetXY($x + 1, $startY);
    foreach ($lines as $ln) {
      $this->MultiCell($w - 2, $lineH, $ln, 0, $align, false);
      $this->SetX($x + 1);
    }

    $this->Rect($x, $y, $w, $h, 'D');
  }

  function WrapTextByWidth($w, $txt){
    $txt = trim((string)$txt);
    if ($txt === '') return [''];

    $words = preg_split('/\s+/', $txt);
    $lines = [];
    $line = '';

    foreach ($words as $word) {
      $test = ($line === '') ? $word : ($line . ' ' . $word);
      if ($this->GetStringWidth($test) <= ($w - 2)) {
        $line = $test;
      } else {
        if ($line !== '') $lines[] = $line;
        $line = $word;
      }
    }
    if ($line !== '') $lines[] = $line;

    return $lines;
  }

  function Header(){
    $this->SetLineWidth(0.35);

    $this->outerW = round($this->GetPageWidth() - 12, 1);
    $outerH = round($this->GetPageHeight() - 12, 1);
    $this->Rect($this->outerX, $this->outerY, $this->outerW, $outerH);

    $X0 = $this->outerX;
    $Y0 = $this->outerY;
    $W  = $this->outerW;

    $headerH = 20;

    $logoW  = 20.0;
    $rightW = 110.0;
    $titleW = round($W - $logoW - $rightW, 1);

    if ($titleW < 60) {
      $titleW = 60.0;
      $rightW = round($W - $logoW - $titleW, 1);
    }

    $this->SetXY($X0, $Y0);
    $this->SetFillColor(255,255,255);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C', true);

    if ($this->logoPath && file_exists($this->logoPath)) {
      $logoX = $X0 + 2;
      $logoY = $Y0 + 2;
      $logoWidth  = $logoW - 4;
      $logoHeight = $headerH - 4;
      $this->Image($this->logoPath, $logoX, $logoY, $logoWidth, $logoHeight);
    }

    $this->SetFillColor(220,220,220);
    $this->SetFont($this->ff, 'B', 14);
    $this->Cell($titleW, $headerH, 'MEETING AGENDA (MA)', 1, 0, 'C', true);

    $rx = $X0 + $logoW + $titleW;
    $ry = $Y0;
    $rH = $headerH / 4;

    $labW = 40;
    $valW = max(10, $rightW - $labW);

    $rows = [
      ['Project', $this->meta['project_name'] ?? ''],
      ['Client',  $this->meta['client_name'] ?? ''],
      ['PMC',     $this->meta['pmc_name'] ?? ''],
      ['Date',    $this->meta['ma_date'] ?? ''],
    ];

    for($i=0;$i<4;$i++){
      $this->SetXY($rx, $ry + $i*$rH);
      $this->SetFont($this->ff, 'B', 11);
      $this->Cell($labW, $rH, $rows[$i][0], 1, 0, 'L');

      $txt = (string)$rows[$i][1];
      $fs = 11;
      $this->SetFont($this->ff, '', $fs);

      while ($fs > 7 && $this->GetStringWidth($txt) > ($valW - 2)) {
        $fs -= 0.5;
        $this->SetFont($this->ff, '', $fs);
      }
      if ($this->GetStringWidth($txt) > ($valW - 2)) {
        while (strlen($txt) > 0 && $this->GetStringWidth($txt.'...') > ($valW - 2)) {
          $txt = substr($txt, 0, -1);
        }
        $txt = rtrim($txt) . '...';
      }

      $this->Cell($valW, $rH, $txt, 1, 0, 'L');
    }

    $this->SetY($Y0 + $headerH + 6);
  }

  function Footer(){
    $this->SetY(-16);
    $this->SetFont($this->ff, '', 10);

    $company = (string)$this->companyName;

    $this->Cell(0, 10, $company, 0, 0, 'L');

    $this->SetXY($this->outerX, $this->GetY());
    $this->Cell($this->outerW, 10, $this->PageNo().' / {nb}', 0, 0, 'C');
  }
}

// ---------------- setup PDF ----------------
$pdf = new PDF('P', 'mm', 'A3');
$pdf->InitFonts();
$pdf->companyName = $companyName;
$pdf->SetMargins(6, 6, 6);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);
$pdf->sectionGap = 6;

$h = 6;

// logo
$logoCandidates = [
  __DIR__ . '/assets/ukb.png',
  __DIR__ . '/assets/ukb.jpg',
  __DIR__ . '/assets/ukb.jpeg',
  __DIR__ . '/public/logo.png',
  __DIR__ . '/assets/logo.png',
];
foreach ($logoCandidates as $p) {
  if (file_exists($p)) { $pdf->logoPath = $p; break; }
}

$pdf->SetMeta($data);
$pdf->AliasNbPages();
$pdf->AddPage();

// Geometry
$X0 = 6;
$W  = $pdf->GetPageWidth() - 12;

$wSection = 44;
$avail = $W - $wSection;
$xR = $X0 + $wSection;

// ========================= I. Meeting Schedule =========================
$pdf->SetFont($pdf->ff, 'B', 11);

$segH = $h * 3;
$pdf->EnsureSpace($segH + $pdf->sectionGap);
$yI = $pdf->GetY();

$pdf->SectionCell($X0, $yI, $wSection, $segH, 'I. Meeting Schedule', 'L');

list($half1, $half2) = split_widths($avail, [0.50, 0.50]);
$labW = 40;
$valW1 = round($half1 - $labW, 1);
$valW2 = round($half2 - $labW, 1);

// Row 1
$pdf->SetXY($xR, $yI);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labW, $h, 'Facilitator', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valW1, $h, $pdf->FitText($valW1, $data['facilitator']), 1, 0, 'L');

$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labW, $h, 'Meeting Number', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valW2, $h, $pdf->FitText($valW2, $data['meeting_number']), 1, 1, 'L');

// Row 2
$pdf->SetX($xR);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labW, $h, 'Meeting Date/Place', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valW1, $h, $pdf->FitText($valW1, $data['meeting_place']), 1, 0, 'L');

$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labW, $h, 'Meeting Start Time', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valW2, $h, $pdf->FitText($valW2, $data['start_time']), 1, 1, 'L');

// Row 3
$pdf->SetX($xR);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labW, $h, 'Meeting Taken By', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valW1, $h, $pdf->FitText($valW1, $data['meeting_taken_by']), 1, 0, 'L');

$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labW, $h, 'Meeting End Time', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valW2, $h, $pdf->FitText($valW2, $data['end_time']), 1, 1, 'L');

$pdf->Ln($pdf->sectionGap);

// ========================= II. Meeting Objectives =========================
$objectives = $data['objectives'];

$wSl = 40;
$wObj = $avail - $wSl;

$rowsThis = count($objectives);
$segH = $h * (1 + $rowsThis);
$pdf->EnsureSpace($segH + $pdf->sectionGap);
$yII = $pdf->GetY();

$pdf->SectionCell($X0, $yII, $wSection, $segH, 'II. Meeting Objectives', 'L');

$pdf->SetXY($xR, $yII);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($wSl, $h, 'Sl.No.', 1, 0, 'L');
$pdf->Cell($wObj, $h, 'Objectives', 1, 1, 'L');

$pdf->SetFont($pdf->ff, '', 11);
for ($i=0; $i<$rowsThis; $i++) {
  $r = $objectives[$i];
  $txt = clean_text($r['item'] ?? ($r['text'] ?? ($r['objective'] ?? '')));
  $pdf->DrawRowFixed($xR, [$wSl,$wObj], [(string)($i+1), $txt], $h, ['L','L']);
}

$pdf->Ln($pdf->sectionGap);

// ========================= III. Requested Attendees =========================
$att = $data['attendees'];

$wSlA  = 40;
$wName = round(($avail - $wSlA) * 0.45, 1);
$wFirm = $avail - ($wSlA + $wName);

$rowsThis = count($att);
$segH = $h * (1 + $rowsThis);
$pdf->EnsureSpace($segH + $pdf->sectionGap);
$yIII = $pdf->GetY();

$pdf->SectionCell($X0, $yIII, $wSection, $segH, 'III. Requested Attendees', 'L');

$pdf->SetXY($xR, $yIII);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($wSlA,  $h, 'Sl.No.', 1, 0, 'L');
$pdf->Cell($wName, $h, 'Name', 1, 0, 'L');
$pdf->Cell($wFirm, $h, 'Firm', 1, 1, 'L');

$pdf->SetFont($pdf->ff, '', 11);
for ($i=0; $i<$rowsThis; $i++) {
  $r = $att[$i];
  $name = clean_text($r['name'] ?? '');
  $firm = clean_text($r['firm'] ?? '');
  $pdf->DrawRowFixed($xR, [$wSlA,$wName,$wFirm], [(string)($i+1), $name, $firm], $h, ['L','L','L']);
}

$pdf->Ln($pdf->sectionGap);

// ========================= IV. Discussions / Decisions Items =========================
$notes = $data['notes'];

$wSlN = 40;
$wTop = $avail - $wSlN;

$rowsThis = count($notes);
$segH = $h * (1 + $rowsThis);
$pdf->EnsureSpace($segH + $pdf->sectionGap);
$yIV = $pdf->GetY();

$pdf->SectionCell($X0, $yIV, $wSection, $segH, 'IV. Discussions / Decisions Items', 'L');

$pdf->SetXY($xR, $yIV);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($wSlN, $h, 'Sl.No.', 1, 0, 'L');
$pdf->Cell($wTop, $h, 'Topics', 1, 1, 'L');

$pdf->SetFont($pdf->ff, '', 11);
for ($i=0; $i<$rowsThis; $i++) {
  $r = $notes[$i];
  $topic = clean_text($r['topic'] ?? ($r['note'] ?? ($r['discussion'] ?? '')));
  $more  = clean_text($r['notes'] ?? '');
  $txt = $topic;
  if ($more !== '' && $more !== $topic) $txt .= ' - ' . $more;

  $pdf->DrawRowFixed($xR, [$wSlN,$wTop], [(string)($i+1), $txt], $h, ['L','L']);
}

$pdf->Ln($pdf->sectionGap);

// ========================= V. Action Assignments =========================
$act = $data['actions'];

$wSlV  = 40;
$wDue  = 70;
$wResp = 40;
$wDesc = $avail - ($wSlV + $wResp + $wDue);

$rowsThis = count($act);
$segH = $h * (1 + $rowsThis);
$pdf->EnsureSpace($segH + $pdf->sectionGap);
$yV = $pdf->GetY();

$pdf->SectionCell($X0, $yV, $wSection, $segH, 'V. Action Assignments', 'L');

$pdf->SetXY($xR, $yV);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($wSlV,  $h, 'Sl.No.', 1, 0, 'L');
$pdf->Cell($wDesc, $h, 'Descriptions', 1, 0, 'L');
$pdf->Cell($wResp, $h, 'Person Responsible', 1, 0, 'L');
$pdf->Cell($wDue,  $h, 'Due Date', 1, 1, 'L');

$pdf->SetFont($pdf->ff, '', 11);
for ($i=0; $i<$rowsThis; $i++) {
  $r = $act[$i];
  $desc = clean_text($r['description'] ?? '');
  $resp = clean_text($r['responsible'] ?? ($r['person_responsible'] ?? ($r['person'] ?? '')));
  $due  = fmt_due_date(clean_text($r['due_date'] ?? ($r['due'] ?? '')));

  $pdf->DrawRowFixed($xR, [$wSlV,$wDesc,$wResp,$wDue], [(string)($i+1), $desc, $resp, $due], $h, ['L','L','L','L']);
}

$pdf->Ln($pdf->sectionGap);

// ========================= VI. Next Meeting =========================
$segH = $h * 3;
$pdf->EnsureSpace($segH + $pdf->sectionGap);
$yVI = $pdf->GetY();

$pdf->SectionCell($X0, $yVI, $wSection, $segH, 'VI. Next Meeting', 'L');

$labWn = 40;
$valWn = $avail - $labWn;

$pdf->SetXY($xR, $yVI);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labWn, $h, 'Meeting Date', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valWn, $h, $pdf->FitText($valWn, $data['next_date']), 1, 1, 'L');

$pdf->SetX($xR);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labWn, $h, 'Meeting Start Time', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valWn, $h, $pdf->FitText($valWn, $data['next_start']), 1, 1, 'L');

$pdf->SetX($xR);
$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->Cell($labWn, $h, 'Meeting End Time', 1, 0, 'L');
$pdf->SetFont($pdf->ff, '', 11);
$pdf->Cell($valWn, $h, $pdf->FitText($valWn, $data['next_end']), 1, 1, 'L');

// ---------------- output ----------------
// REQUIRED FORMAT:
// Mr.(Site name)_DPR #(DPR NO)_Dated (report date).pdf
//
// NOTE: This is MA file, so "DPR NO" uses ma_no.
// If you want true DPR naming, replace $data['ma_no'] with $data['dpr_no'] in your DPR print file.

$sitePart = safe_filename_part($data['project_name'] ?? '');
$noPart   = safe_filename_part($data['ma_no'] ?? '');      // change to dpr_no in DPR print
$datePart = safe_filename_part($data['ma_date'] ?? '');    // change to dpr_date in DPR print

if ($sitePart === '') $sitePart = 'SITE';
if ($noPart === '')   $noPart   = 'ID_' . $viewId;
if ($datePart === '') $datePart = date('d-m-Y');

// Keep # symbol requested, but safe for most systems.
// If you want maximum safety, change "#".$noPart to $noPart.
$filename = 'Mr.' . $sitePart . '_DPR_#' . $noPart . '_Dated_' . $datePart . '.pdf';

// MODE_STRING path (for mail attachment or internal use)
if ($MODE_STRING) {
  $pdfBytes = $pdf->Output('S');

  while (ob_get_level() > 0) {
    ob_end_clean();
  }

  $GLOBALS['__MA_PDF_RESULT__'] = [
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

  if ($forceDownload) {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
  } else {
    header('Content-Disposition: inline; filename="' . $filename . '"');
  }

  header('Cache-Control: private, max-age=0, must-revalidate');
  header('Pragma: public');
}

$pdf->Output($forceDownload ? 'D' : 'I', $filename);

try {
  if (isset($conn) && $conn instanceof mysqli) $conn->close();
} catch (Throwable $e) {}

exit;