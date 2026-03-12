<?php
// report-ma-print.php — Meeting Agenda (MA) PDF (FPDF) — A3 PORTRAIT + FIXED ROW HEIGHT (6mm)
// ✅ A3 paper
// ✅ Attendees: ONLY Name + Firm
// ✅ Action Items: Responsible/Due/Status mapping fixed (supports person/due/status etc.)
// ✅ Closure: extra blank row removed
// ✅ Fonts: Calibri 11 (fallback Arial) + Title 14
// ✅ FIX: Start Time / End Time mapping made robust (supports multiple possible keys)

ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
  header("Location: ../login.php");
  exit;
}

$employeeId  = (int)$_SESSION['employee_id'];
$designation = strtolower(trim((string)($_SESSION['designation'] ?? '')));

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
function scope_level($designationLower){
  if (in_array($designationLower, ['director','vice president','general manager'], true)) return 'all';
  if ($designationLower === 'manager') return 'manager';
  return 'self';
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

// ✅ robust get helper (multiple keys)
function get_any($arr, $keys, $default=''){
  foreach ($keys as $k){
    if (isset($arr[$k]) && trim((string)$arr[$k]) !== '') return $arr[$k];
  }
  return $default;
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

// ✅ FIX: Start/End time robust mapping
$data['start_time']       = clean_text(get_any($row, ['meeting_start_time','start_time','meeting_start','startTime'], ''));
$data['end_time']         = clean_text(get_any($row, ['meeting_end_time','end_time','meeting_end','endTime'], ''));

$data['next_date']        = dmy_dash($row['next_meeting_date'] ?? '');
$data['next_start']       = clean_text(get_any($row, ['next_meeting_start_time','next_start_time','next_start'], ''));
$data['next_end']         = clean_text(get_any($row, ['next_meeting_end_time','next_end_time','next_end'], ''));

$data['prepared_by']      = $preparedName;
$data['designation']      = $preparedDesignation !== '' ? $preparedDesignation : clean_text($_SESSION['designation'] ?? '');

// JSON blocks
$data['agenda']    = decode_rows($row['objectives_json'] ?? '');
$data['attendees'] = decode_rows($row['attendees_json'] ?? '');
$data['notes']     = decode_rows($row['discussions_json'] ?? '');
$data['actions']   = decode_rows($row['actions_json'] ?? '');

// Minimum rows
if (count($data['agenda']) < 2) {
  while (count($data['agenda']) < 2) $data['agenda'][] = ['text'=>''];
}
if (count($data['attendees']) < 1) $data['attendees'] = [['name'=>'','firm'=>'']];
if (count($data['notes']) < 1)     $data['notes']     = [['topic'=>'']];
if (count($data['actions']) < 1)   $data['actions']   = [['description'=>'','person'=>'','due'=>'','status'=>'']];

// ---------------- PDF class ----------------
class PDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 6;
  public $outerY = 6;
  public $outerW = 0;

  // Calibri if installed for FPDF, else Arial
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

  function Header(){
    $this->SetLineWidth(0.35);

    // Outer border
    $this->outerW = round($this->GetPageWidth() - 12, 1);
    $outerH = round($this->GetPageHeight() - 12, 1);
    $this->Rect($this->outerX, $this->outerY, $this->outerW, $outerH);

    $X0 = $this->outerX;
    $Y0 = $this->outerY;
    $W  = $this->outerW;

    $headerH = 20;

    $logoW  = 20.0;
    $rightW = 70.0;
    $titleW = round($W - $logoW - $rightW, 1);

    if ($titleW < 60) {
      $titleW = 60.0;
      $rightW = round($W - $logoW - $titleW, 1);
    }

    // Logo cell
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

    // Title (14)
    $this->SetFillColor(220,220,220);
    $this->SetFont($this->ff, 'B', 14);
    $this->Cell($titleW, $headerH, 'MEETING AGENDA (MA)', 1, 0, 'C', true);

    // Right meta (4 rows)
    $rx = $X0 + $logoW + $titleW;
    $ry = $Y0;
    $rH = $headerH / 4;

    $labW = 22;
    $valW = max(10, $rightW - $labW);

    $rows = [
      ['Project', $this->meta['project_name'] ?? ''],
      ['Client',  $this->meta['client_name'] ?? ''],
      ['PMC',     $this->meta['pmc_name'] ?? ''],
      ['MA',      ($this->meta['ma_no'] ?? '').' / '.($this->meta['ma_date'] ?? '')],
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
    $this->SetY(-15);
    $this->SetFont($this->ff, 'I', 9);
    $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
    $this->Cell(0, 10, 'Generated: ' . date('d-m-Y H:i'), 0, 0, 'R');
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
}

// ---------------- setup PDF ----------------
$pdf = new PDF('P', 'mm', 'A3');
$pdf->InitFonts();
$pdf->SetMargins(6, 6, 6);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);

$h   = 6;
$gap = 6;

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
$pdf->AddPage();

// Geometry
$X0 = 6;
$W  = $pdf->GetPageWidth() - 12;

$wL  = 12;
$wS  = 32;
$avail = $W - ($wL + $wS);
$xR = $X0 + $wL + $wS;

// ========================= A. Meeting (6 rows) =========================
$pdf->SetFont($pdf->ff, 'B', 11);

$segH = $h * 6;
$pdf->EnsureSpace($segH + $gap);
$yA = $pdf->GetY();

draw_left_merged_fixed($pdf, $X0, $yA, $wL, $wS, 'A.', 'Meeting', $segH);

list($wLeft, $wRight) = split_widths($avail, [0.50, 0.50]);

$pdf->SetXY($xR, $yA);
$pdf->Cell($wLeft,  $h, 'Facilitator', 1, 0, 'L');
$pdf->Cell($wRight, $h, 'Meeting Taken By', 1, 1, 'L');

$pdf->SetFont($pdf->ff, '', 11);
$pdf->SetX($xR);
$pdf->Cell($wLeft,  $h, $pdf->FitText($wLeft,  $data['facilitator']), 1, 0, 'L');
$pdf->Cell($wRight, $h, $pdf->FitText($wRight, $data['meeting_taken_by']), 1, 1, 'L');

$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetX($xR);
$pdf->Cell($wLeft,  $h, 'Meeting Date / Place', 1, 0, 'L');
$pdf->Cell($wRight, $h, 'Meeting Number', 1, 1, 'L');

$pdf->SetFont($pdf->ff, '', 11);
$pdf->SetX($xR);
$pdf->Cell($wLeft,  $h, $pdf->FitText($wLeft,  $data['meeting_place']), 1, 0, 'L');
$pdf->Cell($wRight, $h, $pdf->FitText($wRight, $data['meeting_number']), 1, 1, 'L');

$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetX($xR);
$pdf->Cell($wLeft,  $h, 'Start Time', 1, 0, 'L');
$pdf->Cell($wRight, $h, 'End Time', 1, 1, 'L');

$pdf->SetFont($pdf->ff, '', 11);
$pdf->SetX($xR);
// ✅ This is where start/end time prints
$pdf->Cell($wLeft,  $h, $pdf->FitText($wLeft,  $data['start_time']), 1, 0, 'L');
$pdf->Cell($wRight, $h, $pdf->FitText($wRight, $data['end_time']), 1, 1, 'L');

end_section($pdf, $yA, $segH, $gap);

// ========================= B. Agenda =========================
$agenda = $data['agenda'];
$wSl = 12;
$wAg = $avail - $wSl;

$idx = 0;
while ($idx < count($agenda)) {
  $headH = $h;
  $remaining = ($pdf->GetPageHeight()-20) - $pdf->GetY();
  if ($remaining < ($headH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-20) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($agenda) - $idx);
  $segH = $headH + ($rowsThis * $h);

  $pdf->EnsureSpace($segH + $gap);
  $yB = $pdf->GetY();

  draw_left_merged_fixed($pdf, $X0, $yB, $wL, $wS, 'B.', 'Agenda', $segH);

  $pdf->SetFont($pdf->ff, 'B', 11);
  $pdf->SetXY($xR, $yB);
  $pdf->Cell($wSl, $h, 'SL', 1, 0, 'C');
  $pdf->Cell($wAg, $h, 'Agenda Item', 1, 1, 'L');

  $pdf->SetFont($pdf->ff, '', 11);
  for ($i=0; $i<$rowsThis; $i++) {
    $r = $agenda[$idx+$i];
    $txt = clean_text($r['item'] ?? ($r['text'] ?? ($r['objective'] ?? '')));
    $pdf->DrawRowFixed($xR, [$wSl,$wAg], [(string)($idx+$i+1), $txt], $h, ['C','L']);
  }

  end_section($pdf, $yB, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= C. Attendees (ONLY Name + Firm) =========================
$att = $data['attendees'];
$wName = round($avail * 0.55, 1);
$wFirm = round($avail - $wName, 1);

$idx = 0;
while ($idx < count($att)) {
  $headH = $h;
  $remaining = ($pdf->GetPageHeight()-20) - $pdf->GetY();
  if ($remaining < ($headH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-20) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($att) - $idx);
  $segH = $headH + ($rowsThis * $h);

  $pdf->EnsureSpace($segH + $gap);
  $yC = $pdf->GetY();

  draw_left_merged_fixed($pdf, $X0, $yC, $wL, $wS, 'C.', 'Attendees', $segH);

  $pdf->SetFont($pdf->ff, 'B', 11);
  $pdf->SetXY($xR, $yC);
  $pdf->Cell($wName, $h, 'Name', 1, 0, 'L');
  $pdf->Cell($wFirm, $h, 'Firm', 1, 1, 'L');

  $pdf->SetFont($pdf->ff, '', 11);
  for ($i=0; $i<$rowsThis; $i++) {
    $r = $att[$idx+$i];
    $name = clean_text($r['name'] ?? '');
    $firm = clean_text($r['firm'] ?? '');
    $pdf->DrawRowFixed($xR, [$wName,$wFirm], [$name,$firm], $h, ['L','L']);
  }

  end_section($pdf, $yC, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= D. Notes =========================
$notes = $data['notes'];
$wSlD = 12;
$wTxt = $avail - $wSlD;

$idx = 0;
while ($idx < count($notes)) {
  $headH = $h;
  $remaining = ($pdf->GetPageHeight()-20) - $pdf->GetY();
  if ($remaining < ($headH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-20) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($notes) - $idx);
  $segH = $headH + ($rowsThis * $h);

  $pdf->EnsureSpace($segH + $gap);
  $yD = $pdf->GetY();

  draw_left_merged_fixed($pdf, $X0, $yD, $wL, $wS, 'D.', 'Notes', $segH);

  $pdf->SetFont($pdf->ff, 'B', 11);
  $pdf->SetXY($xR, $yD);
  $pdf->Cell($wSlD, $h, 'SL', 1, 0, 'C');
  $pdf->Cell($wTxt, $h, 'Discussion / Notes', 1, 1, 'L');

  $pdf->SetFont($pdf->ff, '', 11);
  for ($i=0; $i<$rowsThis; $i++) {
    $r = $notes[$idx+$i];
    $topic = clean_text($r['topic'] ?? ($r['note'] ?? ($r['discussion'] ?? '')));
    $more  = clean_text($r['notes'] ?? '');
    $txt = $topic;
    if ($more !== '' && $more !== $topic) $txt .= ' - ' . $more;

    $pdf->DrawRowFixed($xR, [$wSlD,$wTxt], [(string)($idx+$i+1), $txt], $h, ['C','L']);
  }

  end_section($pdf, $yD, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= E. Action Items =========================
$act = $data['actions'];

$wDesc = 90;
$wResp = 50;
$wDue  = 32;
$wSt   = $avail - ($wDesc+$wResp+$wDue);

$idx = 0;
while ($idx < count($act)) {
  $headH = $h;
  $remaining = ($pdf->GetPageHeight()-20) - $pdf->GetY();
  if ($remaining < ($headH + $h + 2)) $pdf->AddPage();

  $remaining = ($pdf->GetPageHeight()-20) - $pdf->GetY();
  $maxFit = (int)floor(($remaining - $headH) / $h);
  if ($maxFit < 1) $maxFit = 1;

  $rowsThis = min($maxFit, count($act) - $idx);
  $segH = $headH + ($rowsThis * $h);

  $pdf->EnsureSpace($segH + $gap);
  $yE = $pdf->GetY();

  draw_left_merged_fixed($pdf, $X0, $yE, $wL, $wS, 'E.', 'Action Items', $segH);

  $pdf->SetFont($pdf->ff, 'B', 11);
  $pdf->SetXY($xR, $yE);
  $pdf->Cell($wDesc, $h, 'Description', 1, 0, 'L');
  $pdf->Cell($wResp, $h, 'Responsible', 1, 0, 'L');
  $pdf->Cell($wDue,  $h, 'Due Date', 1, 0, 'C');
  $pdf->Cell($wSt,   $h, 'Status', 1, 1, 'L');

  $pdf->SetFont($pdf->ff, '', 11);
  for ($i=0; $i<$rowsThis; $i++) {
    $r = $act[$idx+$i];

    $desc = clean_text($r['description'] ?? '');
    $resp = clean_text($r['responsible'] ?? ($r['person_responsible'] ?? ($r['person'] ?? '')));
    $due  = fmt_due_date(clean_text($r['due_date'] ?? ($r['due'] ?? '')));
    $stat = clean_text($r['status'] ?? ($r['state'] ?? ''));

    $pdf->DrawRowFixed($xR, [$wDesc,$wResp,$wDue,$wSt], [$desc,$resp,$due,$stat], $h, ['L','L','C','L']);
  }

  end_section($pdf, $yE, $segH, $gap);
  $idx += $rowsThis;
}

// ========================= F. Closure (no extra row) =========================
$pdf->SetFont($pdf->ff, 'B', 11);

// 4 rows only
$segH = $h * 4;
$pdf->EnsureSpace($segH);
$yF = $pdf->GetY();

draw_left_merged_fixed($pdf, $X0, $yF, $wL, $wS, 'F.', 'Closure', $segH);

list($wNext, $wPrep) = split_widths($avail, [0.60, 0.40]);

$pdf->SetXY($xR, $yF);
$pdf->Cell($wNext, $h, 'Next Meeting', 1, 0, 'L');
$pdf->Cell($wPrep, $h, 'Prepared By', 1, 1, 'L');

$pdf->SetFont($pdf->ff, '', 11);
$next1 = $data['next_date'];
$pdf->SetX($xR);
$pdf->Cell($wNext, $h, $pdf->FitText($wNext, $next1), 1, 0, 'L');
$pdf->Cell($wPrep, $h, $pdf->FitText($wPrep, $data['prepared_by']), 1, 1, 'L');

$next2 = trim($data['next_start']) !== '' ? ($data['next_start'] . (trim($data['next_end']) !== '' ? ' - '.$data['next_end'] : '')) : '';
$pdf->SetX($xR);
$pdf->Cell($wNext, $h, $pdf->FitText($wNext, $next2), 1, 0, 'L');
$pdf->Cell($wPrep, $h, $pdf->FitText($wPrep, $data['designation']), 1, 1, 'L');

$pdf->SetFont($pdf->ff, 'B', 11);
$pdf->SetX($xR);
$pdf->Cell($wNext, $h, '', 1, 0, 'L');
$pdf->Cell($wPrep, $h, 'Signature', 1, 1, 'L');

// ---------------- output ----------------
ob_end_clean();

$filename = 'MA_' . preg_replace('/[^A-Za-z0-9\-_]/','_', $data['ma_no']) . '.pdf';

if ($MODE_STRING) {
  $GLOBALS['__MA_PDF_RESULT__'] = [
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
?>
