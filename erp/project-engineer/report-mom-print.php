<?php
// report-mom-print-final-v3-A3-PORTRAIT-CONTROLLED-FONTSAFE-FOOTER-NOGAP.php
// FULL CODE (A3 PORTRAIT "sheet style" with FULL ALIGNMENT CONTROL)
// - Supports:
//    ?view=123        => inline view/print
//    ?view=123&dl=1   => force download
//    ?view=123&mode=string => returns bytes in $GLOBALS['__MOM_PDF_RESULT__']
// - Page size: A3 Portrait
// - Outer border on every page
// - Big header prints ONLY on first page
// - Page 2+ starts exactly at top border area (NO extra top gap)
// - Title font size 14, content font size 11
// - Footer shows page X/Y like 1/3, 2/3 (FPDF AliasNbPages)
// - FIX: Array to string conversion safe clean_text()
// - FIX: CalcRowHeight uses same inner width as MultiCell (no overlap)
// - Font: tries Calibri if installed, otherwise falls back to Arial automatically

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

/* ----------------- helpers ----------------- */

function clean_text($s){
    // handle arrays/objects safely (prevents "Array to string conversion")
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
    $c = @iconv('UTF-8','windows-1252//TRANSLIT//IGNORE',$s);
    return $c !== false ? $c : $s;
}
function dmy_dash($ymd){
    $ymd = trim((string)$ymd);
    if ($ymd === '' || $ymd === '0000-00-00') return '';
    $t = strtotime($ymd);
    return $t ? date('d-m-Y', $t) : $ymd;
}
function time_format($time){
    $time = trim((string)$time);
    if ($time === '') return '';
    $t = strtotime($time);
    return $t ? date('h:i A', $t) : $time;
}
function decode_rows($json){
    if (is_array($json)) return $json; // already decoded
    $json = (string)$json;
    if (trim($json) === '') return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

/**
 * Insert breaks into very long contiguous sequences so MultiCell can wrap them.
 * Inserts a space every $max characters on long non-space runs.
 */
function break_long_words(string $s, int $max = 18): string {
    return preg_replace_callback('/(\S{' . ($max+1) . ',})/u', function($m) use ($max){
        return trim(chunk_split($m[1], $max, ' '));
    }, $s);
}

/* ----------------- load MOM ----------------- */

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid MOM id");

$sql = "
 SELECT m.*, s.project_name, c.client_name, e.full_name as prepared_name
 FROM mom_reports m
 JOIN sites s ON s.id = m.site_id
 JOIN clients c ON c.id = s.client_id
 LEFT JOIN employees e ON e.id = m.prepared_by
 WHERE m.id = ? AND m.employee_id = ?
 LIMIT 1
";
$st = mysqli_prepare($conn, $sql);
if (!$st) die(mysqli_error($conn));
mysqli_stmt_bind_param($st, "ii", $viewId, $employeeId);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($st);
if (!$row) die("MOM not found or not allowed");

// Map data
$data = [];
$data['project_name']   = clean_text($row['project_name'] ?? 'Project');
$data['client_name']    = clean_text($row['client_name'] ?? 'Client');
$data['pmc_name']       = clean_text($row['pmc_name'] ?? 'M/s. UKB Construction Management Pvt Ltd');
$data['architects']     = clean_text($row['architects'] ?? '');
$data['mom_no']         = clean_text($row['mom_no'] ?? ('MOM-'.date('Ymd')));
$data['mom_date']       = dmy_dash($row['mom_date'] ?? date('Y-m-d'));
$data['meeting_conducted'] = clean_text($row['meeting_conducted_by'] ?? '');
$data['meeting_held_at']   = clean_text($row['meeting_held_at'] ?? '');
$data['meeting_time']    = time_format($row['meeting_time'] ?? '');
$data['agenda']          = decode_rows($row['meeting_agenda_json'] ?? '');
$data['attendees']       = decode_rows($row['attendees_json'] ?? []);
$data['discussions']     = decode_rows($row['discussions_json'] ?? []);
$data['mom_shared_to']   = decode_rows($row['mom_shared_to_json'] ?? []);
$data['shared_by']       = clean_text($row['shared_by'] ?? ($row['prepared_name'] ?? ''));
$data['shared_on']       = dmy_dash($row['shared_on'] ?? '');
$data['short_forms']     = [
  ['INFO','Information'],
  ['IMM','Immediately'],
  ['ASAP','As Soon As Possible'],
  ['TBF','To be Followed'],
];
$data['amended_points']  = decode_rows($row['amended_points_json'] ?? []);
$data['next_meeting_date']  = clean_text(!empty($row['next_meeting_date']) ? dmy_dash($row['next_meeting_date']) : '');
$data['next_meeting_place'] = clean_text($row['next_meeting_place'] ?? '');

// sensible defaults
if (count($data['agenda']) === 0) {
  $data['agenda'] = [['item' => 'Review previous MOM'], ['item' => 'Project update']];
}
if (count($data['attendees']) === 0) {
  $data['attendees'] = [
    ['stakeholder'=>'Client','name'=>'','designation'=>'','firm'=>''],
    ['stakeholder'=>'PMC','name'=>'','designation'=>'','firm'=>$data['pmc_name']]
  ];
}
if (count($data['discussions']) === 0) {
  for ($i=1;$i<=12;$i++){
    $data['discussions'][] = ['slno'=>$i,'discussion'=>'','responsible'=>'','deadline'=>''];
  }
}
if (count($data['mom_shared_to']) === 0) {
  $data['mom_shared_to'] = [['attendees'=>'All Attendees','copy_to'=>'']];
}

/* ----------------- PDF class ----------------- */

class DPRMOM_PDF extends FPDF {
  public $meta = [];
  public $logoPath = '';

  // sheet border inset
  public $outerX = 8;
  public $outerY = 8;

  // left merged widths
  public $leftLabelW = 13;
  public $leftTitleW = 52;

  // row metrics (right side tables)
  public $lineH = 6.5;
  public $rowGap = 2.0;
  public $sectionGap = 10;

  // title box metrics (left merged title)
  public $titleLineH = 6.0;
  public $titlePadTop = 2;
  public $titlePadBottom = 2;
  public $minLeftTitleH = 22;

  public $minSectionH = 0;

  public $GREY = [220,220,220];

  // FONT CONTROL
  public $fontFamily = 'Arial'; // will be set to Calibri if available
  public $titleFontSize = 14;
  public $defaultFontSize = 11;

  function SetMeta($m){ $this->meta = $m; }

  function Header(){
    $W = $this->GetPageWidth() - ($this->outerX*2);

    // outer border on every page
    $this->SetLineWidth(0.35);
    $this->Rect($this->outerX, $this->outerY, $W, $this->GetPageHeight() - ($this->outerY*2));

    // only print big header on page 1
    if ($this->PageNo() != 1) {
      // NO GAP on pages 2+ (start exactly at top border area)
      $this->SetY($this->outerY);
      $this->SetFont($this->fontFamily,'',$this->defaultFontSize);
      return;
    }

    $logoW = 25; $rightW = 107; $titleW = $W - $logoW - $rightW;
    $headerH = 24;

    $this->SetXY($this->outerX, $this->outerY);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C');
    if ($this->logoPath && file_exists($this->logoPath)) {
      $maxW = $logoW - 4;
      $maxH = $headerH - 4;
      $this->Image($this->logoPath, $this->outerX+2, $this->outerY+2, $maxW, $maxH);
    }

    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->SetFont($this->fontFamily,'B',$this->titleFontSize);
    $this->Cell($titleW, $headerH, 'MINUTES OF MEETING', 1, 0, 'C', true);

    $rx = $this->outerX + $logoW + $titleW;
    $rH = $headerH / 2;
    $labW = 45;
    $valW = $rightW - $labW;

    $this->SetXY($rx, $this->outerY);
    $this->SetFont($this->fontFamily,'B',$this->defaultFontSize);
    $this->Cell($labW, $rH, 'MOM No', 1, 0, 'L');
    $this->SetFont($this->fontFamily,'',$this->defaultFontSize);
    $this->Cell($valW, $rH, $this->meta['mom_no'] ?? '', 1, 1, 'L');

    $this->SetX($rx);
    $this->SetFont($this->fontFamily,'B',$this->defaultFontSize);
    $this->Cell($labW, $rH, 'Date', 1, 0, 'L');
    $this->SetFont($this->fontFamily,'',$this->defaultFontSize);
    $this->Cell($valW, $rH, $this->meta['mom_date'] ?? '', 1, 1, 'L');

    $this->SetY($this->outerY + $headerH + 7);
    $this->SetFont($this->fontFamily,'',$this->defaultFontSize);
  }

  function Footer(){
    $this->SetY(-16); // move page no. up a little
    $this->SetFont($this->fontFamily,'',$this->defaultFontSize);
    $this->Cell(0, 8, $this->PageNo().'/{nb}', 0, 0, 'C');
  }

  function NbLinesForCell($w, $txt){
    $cw = &$this->CurrentFont['cw'];
    if ($w == 0) $w = $this->w - $this->rMargin - $this->x;

    $wmax = ($w - 2*$this->cMargin) * 1000 / $this->FontSize;
    $s = str_replace("\r", '', (string)$txt);
    $nb = strlen($s);
    if ($nb > 0 && $s[$nb-1] == "\n") $nb--;

    $sep = -1;
    $i = 0;
    $j = 0;
    $l = 0;
    $nl = 1;

    while ($i < $nb) {
      $c = $s[$i];
      if ($c == "\n") {
        $i++;
        $sep = -1;
        $j = $i;
        $l = 0;
        $nl++;
        continue;
      }
      if ($c == ' ') $sep = $i;

      $l += $cw[$c] ?? 0;
      if ($l > $wmax) {
        if ($sep == -1) {
          if ($i == $j) $i++;
        } else {
          $i = $sep + 1;
        }
        $sep = -1;
        $j = $i;
        $l = 0;
        $nl++;
      } else {
        $i++;
      }
    }
    return $nl;
  }

  function CalcRowHeight($widths, $cells, $lineH = null, $fontsizes = null, $styles = null){
    if ($lineH === null) $lineH = $this->lineH;
    if ($fontsizes === null) $fontsizes = array_fill(0, count($cells), $this->defaultFontSize);
    if ($styles === null) $styles = array_fill(0, count($cells), '');

    $maxLines = 1;
    for ($i=0; $i<count($cells); $i++){
      $fs = $fontsizes[$i] ?? $this->defaultFontSize;
      $st = $styles[$i] ?? '';
      $this->SetFont($this->fontFamily, $st, $fs);

      $innerW = $widths[$i] - 2*$this->cMargin;
      if ($innerW <= 0) $innerW = $widths[$i];

      $lines = $this->NbLinesForCell($innerW, (string)$cells[$i]);
      if ($lines > $maxLines) $maxLines = $lines;
    }
    $this->SetFont($this->fontFamily,'',$this->defaultFontSize);
    return ($lineH * $maxLines) + $this->rowGap;
  }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - $this->outerY)) {
      $this->AddPage();
    }
  }

  function RowWrapped($x, $widths, $cells, $lineH = null, $aligns = null, $fontsizes = null, $styles = null, $ensure = true){
    if ($lineH === null) $lineH = $this->lineH;
    if ($aligns === null) $aligns = array_fill(0, count($cells), 'L');
    if ($fontsizes === null) $fontsizes = array_fill(0, count($cells), $this->defaultFontSize);
    if ($styles === null) $styles = array_fill(0, count($cells), '');

    $h = $this->CalcRowHeight($widths, $cells, $lineH, $fontsizes, $styles);
    if ($ensure) $this->EnsureSpace($h);

    $yStartRow = $this->GetY();
    $this->SetXY($x, $yStartRow);

    for ($i=0; $i<count($cells); $i++){
      $w = $widths[$i];
      $xcur = $this->GetX();
      $ycur = $this->GetY();

      $this->Rect($xcur, $ycur, $w, $h);

      $fs = $fontsizes[$i] ?? $this->defaultFontSize;
      $st = $styles[$i] ?? '';
      $this->SetFont($this->fontFamily, $st, $fs);

      $txt = (string)$cells[$i];
      $innerW = $w - 2*$this->cMargin;

      $lines = $this->NbLinesForCell($innerW, $txt);
      $textH = $lines * $lineH;
      $yText = $ycur + max(0, ($h - $textH) / 2);

      $this->SetXY($xcur + $this->cMargin, $yText);
      $this->MultiCell($innerW, $lineH, $txt, 0, $aligns[$i] ?? 'L');

      $this->SetXY($xcur + $w, $ycur);
    }

    $this->SetY($yStartRow + $h);
    $this->Ln(0);
    $this->SetFont($this->fontFamily,'',$this->defaultFontSize);
  }

  function LeftMerged($x, $y, $label, $title, $height){
    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);

    $height = max($height, $this->minLeftTitleH);

    $this->SetXY($x, $y);
    $this->SetFont($this->fontFamily,'B',$this->defaultFontSize);
    $this->Cell($this->leftLabelW, $height, $label, 1, 0, 'C', true);

    $this->SetXY($x + $this->leftLabelW, $y);
    $this->Rect($x + $this->leftLabelW, $y, $this->leftTitleW, $height, 'DF');

    $this->SetFont($this->fontFamily,'B',$this->defaultFontSize);
    $titleSafe = break_long_words((string)$title, 24);

    $textW = $this->leftTitleW - 2*$this->cMargin;
    $titleLines = $this->NbLinesForCell($textW, $titleSafe);
    $textH = $titleLines * $this->titleLineH;

    $usableH = $height - ($this->titlePadTop + $this->titlePadBottom);
    $yStart = $y + $this->titlePadTop + max(0, ($usableH - $textH) / 2);

    $this->SetXY($x + $this->leftLabelW + $this->cMargin, $yStart);
    $this->MultiCell($textW, $this->titleLineH, $titleSafe, 0, 'C');

    $this->SetXY($x + $this->leftLabelW + $this->leftTitleW, $y);
  }

  function DrawSection($X0, $xR, $label, $title, $rows, $lineH = null){
    if ($lineH === null) $lineH = $this->lineH;

    $totalRowsH = 0;
    foreach ($rows as $r) {
      $widths = $r[0];
      $cells  = $r[1];
      $fonts  = $r[3] ?? null;
      $styles = $r[4] ?? null;
      $hh = $this->CalcRowHeight($widths, $cells, $lineH, $fonts, $styles);
      $totalRowsH += $hh;
    }

    $this->SetFont($this->fontFamily,'B',$this->defaultFontSize);
    $titleSafe = break_long_words((string)$title, 24);

    $textW = $this->leftTitleW - 2*$this->cMargin;
    $titleLines = $this->NbLinesForCell($textW, $titleSafe);
    $titleNeedH = ($titleLines * $this->titleLineH) + $this->titlePadTop + $this->titlePadBottom;

    $minTitleH = max($this->minLeftTitleH, $titleNeedH);

    $this->SetFont($this->fontFamily,'',$this->defaultFontSize);

    $mergedH = max($totalRowsH, $titleNeedH, $minTitleH, $this->lineH + $this->rowGap);
    if ($this->minSectionH > 0) $mergedH = max($mergedH, $this->minSectionH);

    $this->EnsureSpace($mergedH);

    $yTop = $this->GetY();

    $this->LeftMerged($X0, $yTop, $label, $title, $mergedH);

    foreach ($rows as $r) {
      $widths = $r[0];
      $cells  = $r[1];
      $aligns = $r[2] ?? null;
      $fonts  = $r[3] ?? null;
      $styles = $r[4] ?? null;
      $this->RowWrapped($xR, $widths, $cells, $lineH, $aligns, $fonts, $styles, false);
    }

    $yEnd = $this->GetY();
    $minEnd = $yTop + $mergedH;
    if ($yEnd < $minEnd) $this->SetY($minEnd);

    $this->Ln($this->sectionGap);
  }
}

/* ----------------- setup & render ----------------- */

// A3 PORTRAIT
$pdf = new DPRMOM_PDF('P','mm','A3');
$pdf->SetMargins(8,8,8);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);

// Footer total pages
$pdf->AliasNbPages();

// optional logo
$logoCandidates = [ __DIR__.'/assets/ukb.png', __DIR__.'/assets/ukb.jpg' ];
foreach ($logoCandidates as $p) { if (file_exists($p)) { $pdf->logoPath = $p; break; } }

// -------------------- ALIGNMENT CONTROL KNOBS --------------------
$pdf->leftLabelW = 13;
$pdf->leftTitleW = 54;

// font + sizes
$pdf->titleFontSize = 14;
$pdf->defaultFontSize = 11;

// table spacing
$pdf->lineH = 9.5;
$pdf->rowGap = 2.0;
$pdf->sectionGap = 10;

// left title formatting
$pdf->titleLineH = 6.0;
$pdf->titlePadTop = 2;
$pdf->titlePadBottom = 2;
$pdf->minLeftTitleH = 22;

// OPTIONAL: force minimum height for every section
// $pdf->minSectionH = 40;
// ---------------------------------------------------------------

// TRY CALIBRI IF INSTALLED (no fatal error)
$fontDir = __DIR__ . '/libs/font/';
$calibriOk = file_exists($fontDir.'calibri.php') && file_exists($fontDir.'calibrib.php');
if ($calibriOk) {
  $pdf->AddFont('Calibri','','calibri.php');
  $pdf->AddFont('Calibri','B','calibrib.php');
  if (file_exists($fontDir.'calibrii.php')) $pdf->AddFont('Calibri','I','calibrii.php');
  if (file_exists($fontDir.'calibriz.php')) $pdf->AddFont('Calibri','BI','calibriz.php');
  $pdf->fontFamily = 'Calibri';
} else {
  $pdf->fontFamily = 'Arial';
}

$pdf->SetMeta(['mom_no'=>$data['mom_no'],'mom_date'=>$data['mom_date']]);
$pdf->AddPage();

$X0 = $pdf->outerX;
$W  = $pdf->GetPageWidth() - ($X0*2);
$wL = $pdf->leftLabelW;
$wS = $pdf->leftTitleW;
$h  = $pdf->lineH;
$avail = $W - ($wL + $wS);
$xR = $X0 + $wL + $wS;

/* ---------- A: Project Information ---------- */
$colsA = [$avail*0.25, $avail*0.25, $avail*0.21, $avail*0.29];
$rowsA = [
  [$colsA, ['Project','','PMC','']],
  [$colsA, [$data['project_name'],'',$data['pmc_name'],'']],
  [$colsA, ['Client','','Architects','']],
  [$colsA, [$data['client_name'],'',$data['architects'],'']]
];
$pdf->DrawSection($X0, $xR, 'A.', 'PROJECT INFORMATION', $rowsA, $h);

/* ---------- B: Meeting Information ---------- */
$half = $avail/2;
$rowsB = [
  [[$half,$half], ['Meeting Conducted by','Date']],
  [[$half,$half], [$data['meeting_conducted'],$data['mom_date']]],
  [[$half,$half], ['Meeting Held at','Time']],
  [[$half,$half], [$data['meeting_held_at'],$data['meeting_time']]],
];
$pdf->DrawSection($X0, $xR, 'B.', 'MEETING INFORMATION', $rowsB, $h);

/* ---------- C: Agenda ---------- */
$rowsC = [];
$noW = $avail * 0.08;
$rowsC[] = [[$noW, $avail-$noW], ['No.','Agenda Items']];
foreach($data['agenda'] as $i=>$ag){
  $rowsC[] = [[$noW, $avail-$noW], [ (string)($i+1), clean_text($ag['item'] ?? $ag) ]];
}
$pdf->DrawSection($X0, $xR, 'C.', 'MEETING AGENDA', $rowsC, $h);

/* ---------- D: Attendees ---------- */
$wa = $avail*0.25; $wb = $avail*0.25; $wc = $avail*0.22; $wd = $avail*0.28;
$rowsD = [];
$rowsD[] = [[$wa,$wb,$wc,$wd], ['Stakeholders','Name','Designation','Firm'], ['L','L','L','L'], [11,11,11,11], ['B','B','B','B']];
foreach($data['attendees'] as $a){
  $rowsD[] = [[$wa,$wb,$wc,$wd], [
    clean_text($a['stakeholder'] ?? ''),
    clean_text($a['name'] ?? ''),
    clean_text($a['designation'] ?? ''),
    clean_text($a['firm'] ?? '')
  ], ['L','L','L','L'], [11,11,11,11], ['', '', '', '']];
}
$pdf->DrawSection($X0, $xR, 'D.', 'MEETING ATTENDEES', $rowsD, $h);

/* ---------- E: Minutes of Discussions ---------- */
$discs = $data['discussions'];
$totalDiscs = count($discs);
$globalIdx = 0;

while ($globalIdx < $totalDiscs) {
    $remainingSpace = $pdf->GetPageHeight() - $pdf->GetY() - $pdf->outerY - 20;

    $col1 = $avail * 0.08;
    $remaining = $avail - $col1;

    $col2 = $remaining * 0.70;
    $col3 = $remaining * 0.14;
    $col4 = $remaining * 0.16;

    $rowsChunk = [];
    $rowsChunk[] = [[$col1,$col2,$col3,$col4], ['Sl.No.','Discussions/Decisions',"Responsible\nby",'Deadline'], ['L','L','C','C'], [11,11,11,11], ['B','B','B','B']];
    $estH = $pdf->CalcRowHeight([$col1,$col2,$col3,$col4], $rowsChunk[0][1], $h, $rowsChunk[0][3], $rowsChunk[0][4]);

    $i = 0;
    while (($globalIdx + $i) < $totalDiscs) {
        $r = $discs[$globalIdx + $i];
        $serial = (string)($globalIdx + $i + 1);
        $discussionText = break_long_words(clean_text($r['discussion'] ?? ''), 28);
        $responsibleText = break_long_words(clean_text($r['responsible'] ?? ''), 20);
        $deadlineText = break_long_words(dmy_dash($r['deadline'] ?? ''), 12);

        $fonts = [11,11,11,11];
        if (strlen(strip_tags($deadlineText)) > 16) $fonts[3] = 10;

        $rowSpec = [[$col1,$col2,$col3,$col4], [$serial, $discussionText, $responsibleText, $deadlineText], ['C','L','L','C'], $fonts, ['', '', '', '']];
        $rowH = $pdf->CalcRowHeight($rowSpec[0], $rowSpec[1], $h, $fonts, $rowSpec[4]);

        if ($estH + $rowH > $remainingSpace && $i>0) break;
        $rowsChunk[] = $rowSpec;
        $estH += $rowH;
        $i++;
    }

    if (count($rowsChunk) == 1 && ($globalIdx < $totalDiscs)) {
        $r = $discs[$globalIdx];
        $serial = (string)($globalIdx + 1);
        $discussionText = break_long_words(clean_text($r['discussion'] ?? ''), 28);
        $responsibleText = break_long_words(clean_text($r['responsible'] ?? ''), 20);
        $deadlineText = break_long_words(dmy_dash($r['deadline'] ?? ''), 12);
        $fonts = [11,11,11,11];
        if (strlen($deadlineText) > 16) $fonts[3] = 10;
        $rowsChunk[] = [[$col1,$col2,$col3,$col4], [$serial,$discussionText,$responsibleText,$deadlineText], ['C','L','L','C'], $fonts, ['', '', '', '']];
        $i = 1;
    }

    $pdf->DrawSection($X0, $xR, 'E.', 'MINUTES OF DISCUSSIONS', $rowsChunk, $h);
    $globalIdx += $i;
}

/* ---------- F: MOM SHARED TO ---------- */
$rowsF = [];
$colA = $avail*0.5; $colB = $avail*0.5;
$rowsF[] = [[$colA,$colB], ['Attendees','Copy To'], ['L','L'], [11,11], ['B','B']];
if (count($data['mom_shared_to'])>0) {
  foreach ($data['mom_shared_to'] as $s) {
    $rowsF[] = [[$colA,$colB], [ clean_text($s['attendees'] ?? ''), clean_text($s['copy_to'] ?? '') ], ['L','L'], [11,11], ['', '']];
  }
} else {
  $rowsF[] = [[$colA,$colB], ['All Attendees',''], ['L','L'], [11,11], ['', '']];
}
$pdf->DrawSection($X0, $xR, 'F.', 'MOM SHARED TO', $rowsF, $h);

/* ---------- G: MOM SHARED BY ---------- */
$rowsG = [];
$rowsG[] = [[$avail*0.5,$avail*0.5], ['Shared by','Shared on'], ['L','L'], [11,11], ['B','B']];
$rowsG[] = [[$avail*0.5,$avail*0.5], [$data['shared_by'],$data['shared_on']], ['L','L'], [11,11], ['', '']];
$pdf->DrawSection($X0, $xR, 'G.', 'MOM SHARED BY', $rowsG, $h);

/* ---------- H: MOM SHORT-FORMS (no header row) ---------- */
$rowsH = [];
$sfCol1 = $avail*0.25;
$sfCol2 = $avail*0.75;
foreach ($data['short_forms'] as $sf) {
  $rowsH[] = [[$sfCol1,$sfCol2], [$sf[0], $sf[1]], ['L','L'], [11,11], ['B','']];
}
$pdf->DrawSection($X0, $xR, 'H.', 'MOM SHORT-FORMS', $rowsH, $h);

/* ---------- I: AMENDED POINTS ---------- */
$wSl   = $avail * 0.08;
$wResp = $avail * 0.14;
$wDead = $avail * 0.16;
$wDisc = $avail - ($wSl + $wResp + $wDead);

$rowsI = [];
$rowsI[] = [
  [$wSl, $wDisc, $wResp, $wDead],
  ['Sl.No.', 'Discussions/Decisions', "Responsible\nby", 'Deadline'],
  ['L','L','C','C'],
  [11,11,11,11],
  ['B','B','B','B']
];

$am = $data['amended_points'] ?? [];
$hasData = false;
foreach ($am as $a) {
  if (!empty($a['discussion']) || !empty($a['slno']) || !empty($a['responsible']) || !empty($a['deadline'])) {
    $hasData = true; break;
  }
}

if ($hasData) {
  foreach ($am as $a) {
    $rowsI[] = [
      [$wSl, $wDisc, $wResp, $wDead],
      [
        (string)($a['slno'] ?? ''),
        break_long_words(clean_text($a['discussion'] ?? ''), 28),
        break_long_words(clean_text($a['responsible'] ?? ''), 20),
        break_long_words(dmy_dash($a['deadline'] ?? ''), 12),
      ],
      ['C','L','L','C'],
      [11,11,11,11],
      ['','','','']
    ];
  }
} else {
  $rowsI[] = [
    [$wSl, $wDisc, $wResp, $wDead],
    ['', '', '', ''],
    ['C','L','L','C'],
    [11,11,11,11],
    ['','','','']
  ];
}

$pdf->DrawSection(
  $X0, $xR,
  'I.',
  "AMENDED POINTS\n(INCASE OF MISSED\nPOINTS)",
  $rowsI,
  $h
);

$pdf->Ln(6);

/* ---------- J: NEXT MEETING DATE & PLACE ---------- */
$nextDate  = break_long_words($data['next_meeting_date'] ?: '', 18);
$nextPlace = break_long_words($data['next_meeting_place'] ?: '', 22);

$rowsJ = [];
$rowsJ[] = [[$avail*0.5,$avail*0.5], ['Date','Place'], ['L','L'], [11,11], ['B','B']];
$rowsJ[] = [[$avail*0.5,$avail*0.5], [$nextDate, $nextPlace], ['L','L'], [11,11], ['', '']];
$pdf->DrawSection($X0, $xR, 'J.', 'NEXT MEETING DATE & PLACE', $rowsJ, $h);

/* ---------- OUTPUT ---------- */

// Safe filename
$rawMomNo = trim((string)($data['mom_no'] ?? ''));
$safeMomNo = preg_replace('/[^A-Za-z0-9\-_]/', '_', $rawMomNo);
if ($safeMomNo === '') $safeMomNo = 'ID_' . $viewId;
$filename = 'MOM_' . $safeMomNo . '_A3.pdf';

// MODE_STRING path (for mail attachment / internal use)
if ($MODE_STRING) {
    $pdfBytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__MOM_PDF_RESULT__'] = [
        'filename' => $filename,
        'bytes'    => $pdfBytes,
    ];

    try {
        if (isset($conn) && $conn instanceof mysqli) $conn->close();
    } catch (Throwable $e) {}

    return;
}

// Browser response path (inline view/print OR download)
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