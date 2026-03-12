<?php
// report-mom-print-final-v3.php
// Final adjustments: corrected column width math so right-table fits the outer box,
// Deadline column reduced (and won't overflow), and left section titles center-aligned.

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

/* ----------------- helpers ----------------- */

function clean_text($s){
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
$data['mom_no']         = clean_text($row['mom_no'] ?? 'MOM-'.date('Ymd'));
$data['mom_date']       = dmy_dash($row['mom_date'] ?? date('Y-m-d'));
$data['meeting_conducted'] = clean_text($row['meeting_conducted_by'] ?? '');
$data['meeting_held_at']   = clean_text($row['meeting_held_at'] ?? '');
$data['meeting_time']    = time_format($row['meeting_time'] ?? '');
$data['agenda']          = decode_rows($row['meeting_agenda_json'] ?? '');
$data['attendees']       = decode_rows($row['attendees_json'] ?? []);
$data['discussions']     = decode_rows($row['discussions_json'] ?? []);
$data['mom_shared_to']   = decode_rows($row['mom_shared_to_json'] ?? []);
$data['shared_by']       = clean_text($row['shared_by'] ?? $row['prepared_name'] ?? '');
$data['shared_on']       = dmy_dash($row['shared_on'] ?? '');
$data['short_forms']     = [
  ['INFO','Information'],
  ['IMM','Immediately'],
  ['ASAP','As Soon As Possible'],
  ['TBF','To be Followed'],
];
$data['amended_points']  = decode_rows($row['amended_points_json'] ?? []);
$data['next_meeting_date']  = clean_text($row['next_meeting_date'] ? dmy_dash($row['next_meeting_date']) : '');
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

/* ----------------- PDF class (adjusted) ----------------- */

class DPRMOM_PDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 6;
  public $outerY = 6;
  public $leftLabelW = 12;
  public $leftTitleW = 42;
  public $lineH = 6;
  public $rowGap = 1.8;
  public $GREY = [220,220,220];
  public $defaultFontSize = 9;

  function SetMeta($m){ $this->meta = $m; }

  function Header(){
    $W = $this->GetPageWidth() - ($this->outerX*2);
    $this->SetLineWidth(0.35);
    $this->Rect($this->outerX, $this->outerY, $W, $this->GetPageHeight() - ($this->outerY*2));

    $logoW = 28; $rightW = 62; $titleW = $W - $logoW - $rightW;
    $headerH = 22;

    $this->SetXY($this->outerX, $this->outerY);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C');
    if ($this->logoPath && file_exists($this->logoPath)) {
      $maxW = $logoW - 4;
      $maxH = $headerH - 4;
      $this->Image($this->logoPath, $this->outerX+2, $this->outerY+2, $maxW, $maxH);
    }

    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->SetFont('Arial','B',12);
    $this->Cell($titleW, $headerH, 'MINUTES OF MEETING', 1, 0, 'C', true);

    $rx = $this->outerX + $logoW + $titleW;
    $rH = $headerH / 2;
    $labW = 28;
    $valW = $rightW - $labW;

    $this->SetXY($rx, $this->outerY);
    $this->SetFont('Arial','B',$this->defaultFontSize);
    $this->Cell($labW, $rH, 'MOM No', 1, 0, 'L');
    $this->SetFont('Arial','',$this->defaultFontSize);
    $this->Cell($valW, $rH, $this->meta['mom_no'] ?? '', 1, 1, 'L');

    $this->SetX($rx);
    $this->SetFont('Arial','B',$this->defaultFontSize);
    $this->Cell($labW, $rH, 'Date', 1, 0, 'L');
    $this->SetFont('Arial','',$this->defaultFontSize);
    $this->Cell($valW, $rH, $this->meta['mom_date'] ?? '', 1, 1, 'L');

    $this->SetY($this->outerY + $headerH + 6);
    $this->SetFont('Arial','',$this->defaultFontSize);
  }

  function NbLinesForCell($w, $txt){
    $cw = &$this->CurrentFont['cw'];
    $wmax = ($w - 2*$this->cMargin) * 1000 / $this->FontSize;
    $s = str_replace("\r",'', (string)$txt);
    $nb = strlen($s);
    if ($nb === 0) return 1;
    $lines = 1;
    $accum = 0;
    for ($i=0;$i<$nb;$i++){
      $c = $s[$i];
      if ($c == "\n") { $lines++; $accum = 0; continue; }
      $accum += $cw[$c] ?? 0;
      if ($accum > $wmax) { $lines++; $accum = 0; }
    }
    return $lines;
  }

  function CalcRowHeight($widths, $cells, $lineH = null, $fontsizes = null){
    if ($lineH === null) $lineH = $this->lineH;
    if ($fontsizes === null) $fontsizes = array_fill(0, count($cells), $this->defaultFontSize);

    $maxLines = 1;
    for ($i=0;$i<count($cells);$i++){
      $fs = $fontsizes[$i] ?? $this->defaultFontSize;
      $this->SetFont('Arial','',$fs);
      $lines = $this->NbLinesForCell($widths[$i], (string)$cells[$i]);
      if ($lines > $maxLines) $maxLines = $lines;
    }
    $this->SetFont('Arial','',$this->defaultFontSize);
    return ($lineH * $maxLines) + $this->rowGap;
  }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - $this->outerY)) {
      $this->AddPage();
    }
  }

  function RowWrapped($x, $widths, $cells, $lineH = null, $aligns = null, $fontsizes = null, $ensure = true){
    if ($lineH === null) $lineH = $this->lineH;
    if ($aligns === null) $aligns = array_fill(0, count($cells), 'L');
    if ($fontsizes === null) $fontsizes = array_fill(0, count($cells), $this->defaultFontSize);

    $h = $this->CalcRowHeight($widths, $cells, $lineH, $fontsizes);
    if ($ensure) $this->EnsureSpace($h);

    $this->SetXY($x, $this->GetY());
    for ($i=0;$i<count($cells);$i++){
      $w = $widths[$i];
      $xcur = $this->GetX();
      $ycur = $this->GetY();
      $this->Rect($xcur, $ycur, $w, $h);
      $fs = $fontsizes[$i] ?? $this->defaultFontSize;
      $this->SetFont('Arial','',$fs);
      $this->MultiCell($w, $lineH, (string)$cells[$i], 0, $aligns[$i]);
      $this->SetXY($xcur + $w, $ycur);
    }
    $this->Ln($h);
    $this->SetFont('Arial','',$this->defaultFontSize);
  }

  // LeftMerged now centers the title text inside the title box
  function LeftMerged($x, $y, $label, $title, $height){
    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->SetXY($x, $y);
    $this->SetFont('Arial','B',$this->defaultFontSize);
    $this->Cell($this->leftLabelW, $height, $label, 1, 0, 'C', true);

    // draw and fill title box
    $this->SetXY($x + $this->leftLabelW, $y);
    $this->Rect($x + $this->leftLabelW, $y, $this->leftTitleW, $height, 'DF');

    // write title centered inside the box
    $this->SetXY($x + $this->leftLabelW + 2, $y + 1);
    $this->SetFont('Arial','B',$this->defaultFontSize);
    $titleSafe = break_long_words((string)$title, 22);
    $this->MultiCell($this->leftTitleW - 4, $this->lineH, $titleSafe, 0, 'C');
    $this->SetXY($x + $this->leftLabelW + $this->leftTitleW, $y);
  }

  function DrawSection($X0, $xR, $label, $title, $rows, $lineH = null){
    if ($lineH === null) $lineH = $this->lineH;

    $totalRowsH = 0;
    foreach ($rows as $r) {
      $widths = $r[0];
      $cells = $r[1];
      $fonts = $r[3] ?? null;
      $h = $this->CalcRowHeight($widths, $cells, $lineH, $fonts);
      $totalRowsH += $h;
    }

    // compute title height (centered)
    $this->SetFont('Arial','B',$this->defaultFontSize);
    $titleSafe = break_long_words((string)$title, 22);
    $titleLines = $this->NbLinesForCell($this->leftTitleW - 4, $titleSafe);
    $titleH = ($this->lineH * $titleLines) + 4;
    $this->SetFont('Arial','',$this->defaultFontSize);

    $mergedH = max($totalRowsH, $titleH, $this->lineH + $this->rowGap);
    $this->EnsureSpace($mergedH);

    $this->LeftMerged($X0, $this->GetY(), $label, $title, $mergedH);

    foreach ($rows as $r) {
      $widths = $r[0];
      $cells  = $r[1];
      $aligns = $r[2] ?? null;
      $fonts  = $r[3] ?? null;
      $this->RowWrapped($xR, $widths, $cells, $lineH, $aligns, $fonts, false);
    }
    $this->Ln(2);
  }
}

/* ----------------- setup & render ----------------- */

$pdf = new DPRMOM_PDF('P','mm','A4');
$pdf->SetMargins(6,6,6);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);

// optional logo
$logoCandidates = [ __DIR__.'/assets/ukb.png', __DIR__.'/assets/ukb.jpg' ];
foreach ($logoCandidates as $p) { if (file_exists($p)) { $pdf->logoPath = $p; break; } }

$pdf->SetMeta(['mom_no'=>$data['mom_no'],'mom_date'=>$data['mom_date']]);
$pdf->AddPage();

$X0 = $pdf->outerX;
$W  = $pdf->GetPageWidth() - ($X0*2);
$wL = $pdf->leftLabelW;
$wS = $pdf->leftTitleW;
$h  = $pdf->lineH;
$avail = $W - ($wL + $wS);
$xR = $X0 + $wL + $wS;

/* ---------- A: Project Information (centered title) ---------- */
$colsA = [$avail*0.25, $avail*0.25, $avail*0.25, $avail*0.25];
$rowsA = [
  [$colsA, ['Project','','PMC','']],
  [$colsA, [$data['project_name'],'',$data['pmc_name'],'']],
  [$colsA, ['Client','','Architects','']],
  [$colsA, [$data['client_name'],'',$data['architects'],'']]
];
$pdf->DrawSection($X0, $xR, 'A.', 'PROJECT INFORMATION', $rowsA, $h);

/* ---------- B: Meeting Information (centered title) ---------- */
$half = $avail/2;
$rowsB = [
  [[$half,$half], ['Meeting Conducted by','Date']],
  [[$half,$half], [$data['meeting_conducted'],$data['mom_date']]],
  [[$half,$half], ['Meeting Held at','Time']],
  [[$half,$half], [$data['meeting_held_at'],$data['meeting_time']]],
];
$pdf->DrawSection($X0, $xR, 'B.', 'MEETING INFORMATION', $rowsB, $h);

/* ---------- C: Agenda (centered title) ---------- */
$rowsC = [];
$rowsC[] = [[18, $avail-18], ['No.','Agenda Items']];
foreach($data['agenda'] as $i=>$ag){
  $rowsC[] = [[18, $avail-18], [ (string)($i+1), clean_text($ag['item'] ?? $ag) ]];
}
$pdf->DrawSection($X0, $xR, 'C.', 'MEETING AGENDA', $rowsC, $h);

/* ---------- D: Attendees (centered title) ---------- */
$wa = $avail*0.23; $wb = $avail*0.28; $wc = $avail*0.23; $wd = $avail*0.26;
$rowsD = [];
$rowsD[] = [[$wa,$wb,$wc,$wd], ['Stakeholders','Name','Designation','Firm']];
foreach($data['attendees'] as $a){
  $rowsD[] = [[$wa,$wb,$wc,$wd], [
    clean_text($a['stakeholder'] ?? ''),
    clean_text($a['name'] ?? ''),
    clean_text($a['designation'] ?? ''),
    clean_text($a['firm'] ?? '')
  ]];
}
$pdf->DrawSection($X0, $xR, 'D.', 'MEETING ATTENDEES', $rowsD, $h);

/* ---------- E: Minutes of Discussions (centered title) ---------- */
$discs = $data['discussions'];
$totalDiscs = count($discs);
$globalIdx = 0;

while ($globalIdx < $totalDiscs) {
    $remainingSpace = $pdf->GetPageHeight() - $pdf->GetY() - $pdf->outerY - 18;

    // fixed left small column (sl no) measured inside available width
    $col1 = 12; // keep slno visually ~12mm
    $remaining = $avail - $col1; // ensure total widths = avail
    if ($remaining < 60) $remaining = $avail * 0.9; // defensive

    // distribute remaining into discussion / responsible / deadline
    $col2 = $remaining * 0.70;   // discussion (major)
    $col3 = $remaining * 0.12;   // responsible
    $col4 = $remaining * 0.18;   // deadline (reduced but adequate)

    $rowsChunk = [];
    $rowsChunk[] = [[$col1,$col2,$col3,$col4], ['Sl.No.','Discussions/Decisions','Responsible by','Deadline'], ['C','L','L','C'], [9,9,9,9]];
    $estH = $pdf->CalcRowHeight([$col1,$col2,$col3,$col4], $rowsChunk[0][1], $h, $rowsChunk[0][3]);

    $i = 0;
    while (($globalIdx + $i) < $totalDiscs) {
        $r = $discs[$globalIdx + $i];
        $serial = (string)($globalIdx + $i + 1);
        $discussionText = break_long_words(clean_text($r['discussion'] ?? ''), 24);
        $responsibleText = break_long_words(clean_text($r['responsible'] ?? ''), 18);
        $deadlineText = break_long_words(dmy_dash($r['deadline'] ?? ''), 12);

        $fonts = [9,9,9,9];
        if (strlen(strip_tags($deadlineText)) > 16) $fonts[3] = 8;

        $rowSpec = [[$col1,$col2,$col3,$col4], [$serial, $discussionText, $responsibleText, $deadlineText], ['C','L','L','C'], $fonts];
        $rowH = $pdf->CalcRowHeight($rowSpec[0], $rowSpec[1], $h, $fonts);

        if ($estH + $rowH > $remainingSpace && $i>0) break;
        $rowsChunk[] = $rowSpec;
        $estH += $rowH;
        $i++;
    }

    if (count($rowsChunk) == 1 && ($globalIdx < $totalDiscs)) {
        $r = $discs[$globalIdx];
        $serial = (string)($globalIdx + 1);
        $discussionText = break_long_words(clean_text($r['discussion'] ?? ''), 24);
        $responsibleText = break_long_words(clean_text($r['responsible'] ?? ''), 18);
        $deadlineText = break_long_words(dmy_dash($r['deadline'] ?? ''), 12);
        $fonts = [9,9,9,9];
        if (strlen($deadlineText) > 16) $fonts[3] = 8;
        $rowsChunk[] = [[$col1,$col2,$col3,$col4], [$serial,$discussionText,$responsibleText,$deadlineText], ['C','L','L','C'], $fonts];
        $i = 1;
    }

    $pdf->DrawSection($X0, $xR, 'E.', 'MINUTES OF DISCUSSIONS', $rowsChunk, $h);
    $globalIdx += $i;
}

/* ---------- F: MOM SHARED TO (centered title) ---------- */
$rowsF = [];
$colA = $avail*0.5; $colB = $avail*0.5;
$rowsF[] = [[$colA,$colB], ['Attendees','Copy To'], ['L','L'], [9,9]];
if (count($data['mom_shared_to'])>0) {
  foreach ($data['mom_shared_to'] as $s) {
    $rowsF[] = [[$colA,$colB], [ clean_text($s['attendees'] ?? ''), clean_text($s['copy_to'] ?? '') ], ['L','L'], [9,9]];
  }
} else {
  $rowsF[] = [[$colA,$colB], ['All Attendees',''], ['L','L'], [9,9]];
}
$pdf->DrawSection($X0, $xR, 'F.', 'MOM SHARED TO', $rowsF, $h);

/* ---------- G: MOM SHARED BY (centered title) ---------- */
$rowsG = [];
$rowsG[] = [[$avail*0.5,$avail*0.5], ['Shared by','Shared on'], ['L','C'], [9,9]];
$rowsG[] = [[$avail*0.5,$avail*0.5], [$data['shared_by'],$data['shared_on']], ['L','C'], [9,9]];
$pdf->DrawSection($X0, $xR, 'G.', 'MOM SHARED BY', $rowsG, $h);

/* ---------- H: MOM SHORT-FORMS (centered title) ---------- */
$rowsH = [];
$col1 = $avail*0.18; $col2 = $avail*0.82;
$rowsH[] = [[$col1,$col2], ['Short Form','Description'], ['C','L'], [9,9]];
foreach ($data['short_forms'] as $sf) $rowsH[] = [[$col1,$col2], [$sf[0], $sf[1]], ['C','L'], [9,9]];
$pdf->DrawSection($X0, $xR, 'H.', 'MOM SHORT-FORMS', $rowsH, $h);

/* ---------- I: Amended Points (centered title) ---------- */
$am = $data['amended_points'];
$hasAm = false;
foreach ($am as $a) { if (!empty($a['discussion']) || !empty($a['slno'])) { $hasAm = true; break; } }
if ($hasAm) {
  // compute same remaining pattern as E to keep visual consistency
  $col1 = 12;
  $remaining = max(60, $avail - $col1);
  $c2 = $remaining * 0.60;
  $c3 = $remaining * 0.20;
  $c4 = $remaining * 0.20;
  $rowsI = [];
  $rowsI[] = [[$col1,$c2,$c3,$c4], ['Sl.No','Discussions/Decisions','Responsible by','Deadline'], ['C','L','L','C'], [9,9,9,9]];
  foreach ($am as $a) {
    $rowsI[] = [[$col1,$c2,$c3,$c4], [
      $a['slno'] ?? '',
      break_long_words(clean_text($a['discussion'] ?? ''), 24),
      break_long_words(clean_text($a['responsible'] ?? ''), 18),
      break_long_words(dmy_dash($a['deadline'] ?? ''), 12)
    ], ['C','L','L','C'], [9,9,9,9]];
  }
  $pdf->DrawSection($X0, $xR, 'I.', 'AMENDED POINTS (INCASE OF MISSED POINTS)', $rowsI, $h);
}

/* ---------- J: NEXT MEETING DATE & PLACE (centered title) ---------- */
$nextDate = break_long_words($data['next_meeting_date'] ?: '', 18);
$nextPlace = break_long_words($data['next_meeting_place'] ?: '', 18);

$rowsJ = [];
$rowsJ[] = [[$avail*0.5,$avail*0.5], ['Date','Place'], ['C','C'], [9,9]];
$rowsJ[] = [[$avail*0.5,$avail*0.5], [$nextDate, $nextPlace], ['C','C'], [9,9]];
$pdf->DrawSection($X0, $xR, 'J.', 'NEXT MEETING DATE & PLACE', $rowsJ, $h);

/* ---------- OUTPUT ---------- */
ob_end_clean();
$filename = 'MOM_' . preg_replace('/[^A-Za-z0-9\-_]/','_', $data['mom_no']) . '.pdf';
$pdf->Output('I', $filename);

try { if (isset($conn) && $conn instanceof mysqli) $conn->close(); } catch (Throwable $e) {}
exit;
