<?php
// report-mpt-print.php — MPT PDF (FPDF) — SAME LOGO HEADER STYLE AS YOUR DAR PRINT
// ✅ Header (logo + title + right meta table) is SAME STYLE as report-dar-print.php
// ✅ Layout matches uploaded MPT sheet (legend + project hand over + main table)
// ✅ Dynamic row height (wrap) for Planned Task + Remarks
// ✅ Status cell filled (ON TRACK = Yellow, DONE = Green, DELAY = Red)
// ✅ Role scope safe:
//    - Director / VP / GM => all
//    - Manager            => managed sites
//    - Others             => self
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

$conn = get_db_connection();
if (!$conn) die("DB connection failed");

$employeeId = (int)$_SESSION['employee_id'];
$designationRaw = (string)($_SESSION['designation'] ?? '');
$designation = strtolower(trim($designationRaw));

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

function decode_rows($json){
  $json = (string)$json;
  if (trim($json) === '') return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}

function fmt_dmy_dot($ymd){
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('d.m.Y', $t) : $ymd;
}

function fmt_mon_yr($ymd){ // Jul-26
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('M-y', $t) : $ymd;
}

function month_name_from_any($m){
  $m = trim((string)$m);
  if ($m === '') return '';
  if (is_numeric($m)) {
    $n = (int)$m;
    if ($n >= 1 && $n <= 12) return date('F', mktime(0,0,0,$n,1,2000));
  }
  $t = strtotime("1 ".$m." 2000");
  if ($t) return date('F', $t);
  return $m;
}

function status_norm($s){
  $s = strtoupper(trim((string)$s));
  if ($s === 'ONTRACK') $s = 'ON TRACK';
  if ($s === 'IN PROGRESS') $s = 'ON TRACK';
  if ($s === 'COMPLETED') $s = 'DONE';
  if ($s === 'COMPLETE') $s = 'DONE';
  return $s;
}

function status_rgb($s){
  $s = status_norm($s);
  if ($s === 'DONE')  return [0,176,80];
  if ($s === 'DELAY') return [255,0,0];
  return [255,255,0]; // ON TRACK
}

function roleScope(string $designationLower): string {
  if (in_array($designationLower, ['director','vice president','general manager'], true)) return 'all';
  if ($designationLower === 'manager') return 'manager';
  return 'self';
}

// ---------------- load MPT ----------------
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid MPT id");

// IMPORTANT: this code expects table `mpt_reports` with JSON column `items_json`.
// If your column/table names differ, update ONLY the SQL + field mappings below.

$scope = roleScope($designation);

$scopeCond = "";
$types = "i";
$params = [$viewId];

if ($scope === 'self') {
  $scopeCond = " AND r.employee_id = ? ";
  $types .= "i";
  $params[] = $employeeId;
} elseif ($scope === 'manager') {
  $scopeCond = " AND r.site_id IN (SELECT id FROM sites WHERE manager_employee_id = ?) ";
  $types .= "i";
  $params[] = $employeeId;
}

$sql = "
  SELECT
    r.*,
    s.project_name,
    s.expected_completion_date,
    c.client_name
  FROM mpt_reports r
  INNER JOIN sites s ON s.id = r.site_id
  INNER JOIN clients c ON c.id = s.client_id
  WHERE r.id = ?
  $scopeCond
  LIMIT 1
";
$st = mysqli_prepare($conn, $sql);
if (!$st) die("SQL Error: " . mysqli_error($conn));
mysqli_stmt_bind_param($st, $types, ...$params);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($st);

if (!$row) die("MPT not found or not allowed");

// ---------------- map fields (flexible) ----------------
$projectName = clean_text($row['project_name'] ?? '');
$clientName  = clean_text($row['client_name'] ?? '');

$pmcName = clean_text($row['pmc_name'] ?? ($row['pmc'] ?? 'UKB Construction & Management Pvt Ltd'));

$mptNo = clean_text($row['mpt_no'] ?? ($row['mpt_number'] ?? ''));
$mptDateYmd = (string)($row['mpt_date'] ?? ($row['date'] ?? ''));
$mptDateDot = fmt_dmy_dot($mptDateYmd);

$monthRaw = $row['mpt_month'] ?? ($row['month'] ?? '');
$monthName = month_name_from_any($monthRaw);
if ($monthName === '' && $mptDateYmd !== '') $monthName = date('F', strtotime($mptDateYmd));

$mptNoDated = '';
if ($mptNo !== '' && $mptDateDot !== '') $mptNoDated = '# ' . $mptNo . ' / ' . $mptDateDot;
elseif ($mptNo !== '') $mptNoDated = '# ' . $mptNo;
elseif ($mptDateDot !== '') $mptNoDated = '# ' . $mptDateDot;

$handoverYmd = (string)($row['handover_date'] ?? ($row['project_handover'] ?? ($row['hand_over_date'] ?? '')));
if ($handoverYmd === '' || $handoverYmd === '0000-00-00') {
  $handoverYmd = (string)($row['expected_completion_date'] ?? '');
}
$handoverMY = fmt_mon_yr($handoverYmd);

// items JSON
$items = decode_rows($row['items_json'] ?? ($row['mpt_items_json'] ?? ''));
if (!is_array($items)) $items = [];

// group by section letter A/B/C/D
$grouped = [];
foreach ($items as $it) {
  if (!is_array($it)) continue;
  $sec = strtoupper(trim((string)($it['section'] ?? $it['group'] ?? $it['category'] ?? 'A')));
  if (!in_array($sec, ['A','B','C','D'], true)) $sec = 'A';
  if (!isset($grouped[$sec])) $grouped[$sec] = [];
  $grouped[$sec][] = $it;
}

$defaultSectionTitles = [
  'A' => 'DESIGN DELIVERABLE',
  'B' => 'VENDOR FINALIZATION',
  'C' => 'SITE WORKS',
  'D' => 'CLIENT DECISIONS',
];

// ---------------- PDF class (logo header same as DAR) ----------------
class PDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 6;
  public $outerY = 6;
  public $outerW = 0;
  public $GREY  = [220,220,220];
  public $gapAfterHeader = 0;

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

    // logo cell
    $this->SetXY($X0, $Y0);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C');
    if ($this->logoPath && file_exists($this->logoPath)) {
      $this->Image($this->logoPath, $X0+2, $Y0+2, $logoW-4, $headerH-4);
    }

    // title cell
    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->SetFont('Arial','B',12);
    $this->Cell($titleW, $headerH, 'MONTHLY PLANNED TRACKER (MPT)', 1, 0, 'C', true);

    // right meta table (4 rows)
    $rx = $X0 + $logoW + $titleW;
    $ry = $Y0;
    $rH = $headerH / 4;
    $labW = 26;
    $valW = $rightW - $labW;

    $rows = [
      ['Project', $this->meta['project'] ?? ''],
      ['Client',  $this->meta['client'] ?? ''],
      ['PMC',     $this->meta['pmc'] ?? ''],
      ['MPT No / Dated', $this->meta['mpt_no_dated'] ?? ''],
    ];

    for($i=0;$i<4;$i++){
      $this->SetXY($rx, $ry + $i*$rH);
      $this->SetFont('Arial','B',8.5);
      $this->Cell($labW, $rH, (string)$rows[$i][0], 1, 0, 'L');

      $txt = (string)$rows[$i][1];
      $fs = 8.5;
      $this->SetFont('Arial','B', $fs);
      while ($fs > 6.5 && $this->GetStringWidth($txt) > ($valW - 2)) {
        $fs -= 0.3;
        $this->SetFont('Arial','B', $fs);
      }
      $this->Cell($valW, $rH, $txt, 1, 0, 'L');
    }

    $this->SetY($Y0 + $headerH + $this->gapAfterHeader);
  }

  function EnsureSpace($needH){
    if ($this->GetY() + $needH > ($this->GetPageHeight() - 6)) $this->AddPage();
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

  function RectFill($x,$y,$w,$h,$rgb=null){
    if (is_array($rgb)) {
      $this->SetFillColor($rgb[0],$rgb[1],$rgb[2]);
      $this->Rect($x,$y,$w,$h,'F');
    }
    $this->Rect($x,$y,$w,$h);
  }

  function CellWrapVCenter($x,$y,$w,$h,$txt,$align='C',$lineH=4.8,$fillRgb=null){
    $this->RectFill($x,$y,$w,$h,$fillRgb);
    $txt = (string)$txt;
    if (trim($txt) === '') return;

    $lines = $this->NbLines($w, $txt);
    $textH = $lines * $lineH;
    $startY = $y + max(0, ($h - $textH)/2);

    $this->SetXY($x, $startY);
    $this->MultiCell($w, $lineH, $txt, 0, $align);
  }

  function RowDynamic($x,$widths,$cells,$lineH=4.8,$aligns=[],$fills=[]){
    $maxLines = 1;
    for($i=0;$i<count($cells);$i++){
      $maxLines = max($maxLines, $this->NbLines($widths[$i], (string)($cells[$i] ?? '')));
    }
    $h = $maxLines * $lineH;

    $this->EnsureSpace($h);

    $y = $this->GetY();
    $curX = $x;

    for($i=0;$i<count($cells);$i++){
      $w = $widths[$i];
      $a = $aligns[$i] ?? 'C';
      $txt = (string)($cells[$i] ?? '');
      $fill = $fills[$i] ?? null;
      $this->CellWrapVCenter($curX, $y, $w, $h, $txt, $a, $lineH, $fill);
      $curX += $w;
    }

    $this->SetXY($x, $y + $h);
    return $h;
  }
}

// ---------------- setup PDF ----------------
$meta = [
  'project' => $projectName,
  'client'  => $clientName,
  'pmc'     => $pmcName,
  'mpt_no_dated' => $mptNoDated,
];

$pdf = new PDF('P','mm','A4');
$pdf->SetMargins(6,6,6);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);

// same logo candidates as your DAR
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

// Geometry
$X0 = 6;
$W  = $pdf->GetPageWidth() - 12;

// After header, your DAR adds a bordered gap row. Do same.
$GAP_ROW_H = 6;
$pdf->EnsureSpace($GAP_ROW_H);
$pdf->Rect($X0, $pdf->GetY(), $W, $GAP_ROW_H);
$pdf->Ln($GAP_ROW_H);

// ---------------- Month strip row (like uploaded sheet) ----------------
$monthRowH = 10;
$pdf->EnsureSpace($monthRowH);

$pdf->SetFont('Arial','B',10);
// left side blank cell area (same width as sheet uses)
$pdf->Rect($X0, $pdf->GetY(), $W, $monthRowH);
$pdf->SetXY($X0, $pdf->GetY()+2.5);
$pdf->Cell($W, 5, 'MONTH : ' . clean_text($monthName), 0, 0, 'C');
$pdf->Ln($monthRowH);

// ---------------- Legend + Project handover row ----------------
$legendH = 18;
$rowH = 6;

$legendW = 90; // close to sheet left legend width
$rightW  = $W - $legendW;

$yL = $pdf->GetY();
$pdf->EnsureSpace($legendH);

// legend outer
$pdf->Rect($X0, $yL, $legendW, $legendH);

// each legend row
$colorBoxW = 14;
$labelW = $legendW - $colorBoxW;

$legend = [
  ['ON TRACK', [255,255,0]],
  ['DONE',     [0,176,80]],
  ['DELAY',    [255,0,0]],
];

for($i=0;$i<3;$i++){
  $yy = $yL + $i*$rowH;
  $pdf->RectFill($X0, $yy, $colorBoxW, $rowH, $legend[$i][1]);
  $pdf->Rect($X0, $yy, $colorBoxW, $rowH);

  $pdf->Rect($X0+$colorBoxW, $yy, $labelW, $rowH);
  $pdf->SetFont('Arial','B',8.5);
  $pdf->SetXY($X0+$colorBoxW+1, $yy+1.5);
  $pdf->Cell($labelW-2, $rowH-3, $legend[$i][0], 0, 0, 'L');
}

// project handover block (right)
$handX = $X0 + $legendW;
$handY = $yL;
$handLabelW = 60;
$handValW   = $rightW - $handLabelW;

$pdf->Rect($handX, $handY, $handLabelW, $legendH);
$pdf->Rect($handX+$handLabelW, $handY, $handValW, $legendH);

$pdf->SetFont('Arial','B',11);
$pdf->SetXY($handX, $handY + 6);
$pdf->Cell($handLabelW, 6, 'PROJECT HAND OVER', 0, 0, 'C');

$pdf->SetXY($handX+$handLabelW, $handY + 6);
$pdf->Cell($handValW, 6, clean_text($handoverMY), 0, 0, 'C');

$pdf->SetY($yL + $legendH);

// ---------------- Main table header (blue) ----------------
$BLUE = [153,187,227];
$pdf->SetFillColor($BLUE[0], $BLUE[1], $BLUE[2]);

// widths (like sheet)
$wSL   = 16;
$wTask = 66;
$wResp = 18;
$wDate = 18;
$wPP   = 16;
$wPA   = 16;
$wStat = 20;
$wRem  = $W - ($wSL+$wTask+$wResp+$wDate+$wPP+$wPA+$wStat);

$hTop = 8;
$hSub = 8;

$yH = $pdf->GetY();
$pdf->EnsureSpace($hTop+$hSub+2);

// header merged cells
$pdf->SetFont('Arial','B',8.3);

// SL.NO
$pdf->RectFill($X0, $yH, $wSL, $hTop+$hSub, $BLUE);
$pdf->SetXY($X0, $yH+5);
$pdf->Cell($wSL, 3, 'SL.NO', 0, 0, 'C');

// PLANNED TASK
$x = $X0+$wSL;
$pdf->RectFill($x, $yH, $wTask, $hTop+$hSub, $BLUE);
$pdf->SetXY($x, $yH+5);
$pdf->Cell($wTask, 3, 'PLANNED TASK', 0, 0, 'C');

// RESPONSIBLE BY
$x += $wTask;
$pdf->RectFill($x, $yH, $wResp, $hTop+$hSub, $BLUE);
$pdf->SetFont('Arial','B',7.6);
$pdf->SetXY($x, $yH+3.4);
$pdf->MultiCell($wResp, 4, "RESPONSIBLE\nBY", 0, 'C');

// PLANNED COMPLETION DATE
$x += $wResp;
$pdf->RectFill($x, $yH, $wDate, $hTop+$hSub, $BLUE);
$pdf->SetFont('Arial','B',7.2);
$pdf->SetXY($x, $yH+1.2);
$pdf->MultiCell($wDate, 4, "PLANNED\nCOMPLETION\nDATE", 0, 'C');

// % OF WORK DONE (top merged over planned/actual)
$x += $wDate;
$workW = $wPP+$wPA;
$pdf->SetFont('Arial','B',8.0);
$pdf->RectFill($x, $yH, $workW, $hTop, $BLUE);
$pdf->SetXY($x, $yH+5);
$pdf->Cell($workW, 3, '% OF WORK DONE', 0, 0, 'C');

// STATUS
$x2 = $x + $workW;
$pdf->SetFont('Arial','B',8.3);
$pdf->RectFill($x2, $yH, $wStat, $hTop+$hSub, $BLUE);
$pdf->SetXY($x2, $yH+5);
$pdf->Cell($wStat, 3, 'STATUS', 0, 0, 'C');

// REMARKS
$x2 += $wStat;
$pdf->RectFill($x2, $yH, $wRem, $hTop+$hSub, $BLUE);
$pdf->SetXY($x2, $yH+5);
$pdf->Cell($wRem, 3, 'REMARKS', 0, 0, 'C');

// Sub headers: planned / actual
$y2 = $yH + $hTop;
$pdf->RectFill($x, $y2, $wPP, $hSub, $BLUE);
$pdf->RectFill($x+$wPP, $y2, $wPA, $hSub, $BLUE);
$pdf->SetFont('Arial','B',8.0);
$pdf->SetXY($x, $y2+3);
$pdf->Cell($wPP, 3, 'PLANNED', 0, 0, 'C');
$pdf->SetXY($x+$wPP, $y2+3);
$pdf->Cell($wPA, 3, 'ACTUAL', 0, 0, 'C');

// move below header
$pdf->SetXY($X0, $yH + $hTop + $hSub);

// gap row like sheet
$pdf->EnsureSpace($GAP_ROW_H);
$pdf->Rect($X0, $pdf->GetY(), $W, $GAP_ROW_H);
$pdf->Ln($GAP_ROW_H);

// ---------------- Body ----------------
$pdf->SetFont('Arial','B',8.0);
$lineH = 4.8;

$sectionOrder = ['A','B','C','D'];
$greyRow = [217,217,217];

if (empty($grouped)) {
  $pdf->RowDynamic(
    $X0,
    [$wSL,$wTask,$wResp,$wDate,$wPP,$wPA,$wStat,$wRem],
    ['','No MPT tasks found','','','','','',''],
    $lineH,
    ['C','L','C','C','C','C','C','L'],
    [null,null,null,null,null,null,null,null]
  );
} else {

  foreach ($sectionOrder as $sec) {
    if (empty($grouped[$sec])) continue;

    // section title (allow override from JSON)
    $secTitle = '';
    foreach ($grouped[$sec] as $it) {
      if (is_array($it) && isset($it['section_title']) && trim((string)$it['section_title']) !== '') {
        $secTitle = clean_text($it['section_title']);
        break;
      }
    }
    if ($secTitle === '') $secTitle = $defaultSectionTitles[$sec] ?? ('SECTION ' . $sec);

    // section header row (grey)
    $pdf->SetFont('Arial','B',8.6);
    $pdf->RowDynamic(
      $X0,
      [$wSL,$wTask,$wResp,$wDate,$wPP,$wPA,$wStat,$wRem],
      [$sec, $secTitle, '', '', '', '', '', ''],
      $lineH,
      ['C','L','C','C','C','C','C','L'],
      [$greyRow,$greyRow,$greyRow,$greyRow,$greyRow,$greyRow,$greyRow,$greyRow]
    );

    // rows in section
    $pdf->SetFont('Arial','B',8.0);
    $sl = 1;

    foreach ($grouped[$sec] as $it) {
      if (!is_array($it)) continue;

      $task = clean_text($it['planned_task'] ?? ($it['task'] ?? ''));
      $resp = clean_text($it['responsible_by'] ?? ($it['responsible'] ?? ''));
      $date = clean_text($it['planned_completion_date'] ?? ($it['completion_date'] ?? ''));

      $pp = clean_text($it['planned_percent'] ?? ($it['pct_planned'] ?? ($it['planned'] ?? '')));
      $pa = clean_text($it['actual_percent'] ?? ($it['pct_actual'] ?? ($it['actual'] ?? '')));

      $stt = status_norm($it['status'] ?? '');
      if ($stt === '') $stt = 'ON TRACK';
      $rem = clean_text($it['remarks'] ?? '');

      // format date if Y-m-d
      if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $date)) $date = fmt_dmy_dot($date);

      // format % to 2 decimals if numeric
      $ppNum = str_replace('%','',trim((string)$pp));
      if ($ppNum !== '' && is_numeric($ppNum)) $pp = number_format((float)$ppNum, 2) . '%';
      $paNum = str_replace('%','',trim((string)$pa));
      if ($paNum !== '' && is_numeric($paNum)) $pa = number_format((float)$paNum, 2) . '%';

      $fillStatus = status_rgb($stt);

      $pdf->RowDynamic(
        $X0,
        [$wSL,$wTask,$wResp,$wDate,$wPP,$wPA,$wStat,$wRem],
        [(string)$sl, $task, $resp, $date, $pp, $pa, $stt, $rem],
        $lineH,
        ['C','L','C','C','C','C','C','L'],
        [null,null,null,null,null,null,$fillStatus,null]
      );

      $sl++;
    }

    // section gap row
    $pdf->EnsureSpace($GAP_ROW_H);
    $pdf->Rect($X0, $pdf->GetY(), $W, $GAP_ROW_H);
    $pdf->Ln($GAP_ROW_H);
  }
}

// ---------------- output ----------------
ob_end_clean();

$filename = 'MPT_' . preg_replace('/[^A-Za-z0-9\-_]/','_', ($mptNo !== '' ? $mptNo : ('ID_'.$viewId))) . '.pdf';

if ($MODE_STRING) {
  $GLOBALS['__MPT_PDF_RESULT__'] = [
    'filename' => $filename,
    'bytes' => $pdf->Output('S'),
  ];
} else {
  $pdf->Output('I', $filename);
}

try { if (isset($conn) && $conn instanceof mysqli) $conn->close(); } catch (Throwable $e) {}
if (!$MODE_STRING) exit;
