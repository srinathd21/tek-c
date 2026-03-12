<?php
// report-dar-print.php — DAR PDF (FPDF) — DPR STYLE + DYNAMIC ROW HEIGHT
// ✅ REALTIME DB DAR: dar_reports.activities_json (planned, achieved, tomorrow, remarks)
// ✅ Planned column format: "Project Name : Planned Activity"
// ✅ Dynamic row height based on wrapped lines
// ✅ Center align (horizontal + vertical) inside every cell
// ✅ Proper header borders like sample
// ✅ Column spacing like uploaded image
// ✅ FIX: BOTH GAP ROWS set to EXACT height = 6 (lined gap you marked)
// ✅ Supports: ?view=ID
// ✅ Supports: ?view=ID&mode=string (returns bytes in $GLOBALS['__DAR_PDF_RESULT__'])

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

function dmy_long($ymd){
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $t = strtotime($ymd);
  return $t ? date('d F Y', $t) : $ymd;
}

function decode_rows($json){
  $json = (string)$json;
  if (trim($json) === '') return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}

function status_norm($s){
  $s = strtoupper(trim((string)$s));
  if ($s === 'COMPLETED') return 'COMPLETE';
  return $s;
}

// planned becomes: "ProjectName : planned"
function normalize_activity_row(array $r, string $projectName): array {
  $plannedRaw  = clean_text($r['planned'] ?? '');
  $planned     = trim($projectName) !== '' ? (clean_text($projectName) . ' : ' . $plannedRaw) : $plannedRaw;

  $achieved = status_norm($r['achieved'] ?? '');
  $tomorrow = clean_text($r['tomorrow'] ?? '');
  $remarks  = clean_text($r['remarks'] ?? '');

  return [$planned, $achieved, $tomorrow, $remarks];
}

// ---------------- load DAR ----------------
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid DAR id");

$sql = "
  SELECT
    r.*,
    s.project_name,
    s.project_type
  FROM dar_reports r
  INNER JOIN sites s ON s.id = r.site_id
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
if (!$row) die("DAR not found or not allowed");

$projectName = clean_text($row['project_name'] ?? '');

// meta
$division = clean_text($row['division'] ?? '');
if ($division === '') $division = clean_text($row['project_type'] ?? 'QS Division');

$incharge = clean_text($row['incharge'] ?? '');
if ($incharge === '') $incharge = clean_text($row['prepared_by'] ?? ($_SESSION['employee_name'] ?? '—'));

$darNo   = clean_text($row['dar_no'] ?? '');
$darDate = dmy_long($row['dar_date'] ?? '');

$activities = decode_rows($row['activities_json'] ?? '');
if (!is_array($activities)) $activities = [];

$meta = [
  'division' => $division,
  'incharge' => $incharge,
  'dar_no'   => $darNo,
  'dar_date' => $darDate,
];

// ---------------- PDF class ----------------
class PDF extends FPDF {
  public $meta = [];
  public $logoPath = '';
  public $outerX = 6;
  public $outerY = 6;
  public $outerW = 0;
  public $GREY  = [220,220,220];
  public $gapAfterHeader = 0; // we draw bordered gap row manually

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

    // logo
    $this->SetXY($X0, $Y0);
    $this->Cell($logoW, $headerH, '', 1, 0, 'C');
    if ($this->logoPath && file_exists($this->logoPath)) {
      $this->Image($this->logoPath, $X0+2, $Y0+2, $logoW-4, $headerH-4);
    }

    // title
    $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
    $this->SetFont('Arial','B',12);
    $this->Cell($titleW, $headerH, 'DAILY ACTIVITY REPORT (DAR)', 1, 0, 'C', true);

    // right meta table
    $rx = $X0 + $logoW + $titleW;
    $ry = $Y0;
    $rH = $headerH / 4;
    $labW = 22;
    $valW = $rightW - $labW;

    $rows = [
      ['Division', $this->meta['division'] ?? ''],
      ['Incharge', $this->meta['incharge'] ?? ''],
      ['DAR No.',  $this->meta['dar_no'] ?? ''],
      ['Date',     $this->meta['dar_date'] ?? ''],
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

  function CellWrapCenter($x, $y, $w, $h, $txt, $align='C', $lineH=7){
    $this->Rect($x, $y, $w, $h);
    $txt = (string)$txt;
    if (trim($txt) === '') return;

    $lines = $this->NbLines($w, $txt);
    $textH = $lines * $lineH;
    $startY = $y + max(0, ($h - $textH) / 2);

    $this->SetXY($x, $startY);
    $this->MultiCell($w, $lineH, $txt, 0, $align);
  }

  function RowDynamicCentered($x, $widths, $cells, $lineH=7, $aligns=[]){
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
      $this->CellWrapCenter($curX, $y, $w, $h, $txt, $a, $lineH);
      $curX += $w;
    }
    $this->SetXY($x, $y + $h);
  }

  function HeaderBox($x, $y, $w, $h, $txt, $fill=true, $lineH=6, $align='C'){
    if ($fill) {
      $this->SetXY($x, $y);
      $this->Cell($w, $h, '', 1, 0, 'L', true);
    } else {
      $this->Rect($x, $y, $w, $h);
    }

    $txt = (string)$txt;
    $lines = $this->NbLines($w, $txt);
    $textH = $lines * $lineH;
    $startY = $y + max(0, ($h - $textH) / 2);

    $this->SetXY($x, $startY);
    $this->MultiCell($w, $lineH, $txt, 0, $align);
  }
}

// ---------------- setup PDF ----------------
$pdf = new PDF('P', 'mm', 'A4');
$pdf->SetMargins(6, 6, 6);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);

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

// widths
$wSL   = 16;
$wPlan = 78;
$wAch  = 32;
$wTom  = 34;
$wRem  = $W - ($wSL + $wPlan + $wAch + $wTom);

// ✅ FIX: gap row exactly 6mm (like DPR cell height)
$GAP_ROW_H = 6;

// ---------------- GAP ROW under title (bordered full width) ----------------
$pdf->EnsureSpace($GAP_ROW_H);
$pdf->Rect($X0, $pdf->GetY(), $W, $GAP_ROW_H);
$pdf->Ln($GAP_ROW_H);

// ---------------- header ----------------
$BLUE = [153,187,227];
$pdf->SetFillColor($BLUE[0], $BLUE[1], $BLUE[2]);

$hTop = 10;
$hSub = 14;

$pdf->SetFont('Arial','B',12);

$y = $pdf->GetY();
$pdf->EnsureSpace($hTop + $hSub + 2);

// SL NO merged
$pdf->HeaderBox($X0, $y, $wSL, $hTop+$hSub, 'SL NO', true, 6, 'C');

// ACTIVITY merged top
$actX = $X0 + $wSL;
$actW = $wPlan + $wAch + $wTom;
$pdf->HeaderBox($actX, $y, $actW, $hTop, 'ACTIVITY', true, 6, 'C');

// REMARKS merged
$remX = $actX + $actW;
$pdf->HeaderBox($remX, $y, $wRem, $hTop+$hSub, 'REMARKS', true, 6, 'C');

// second header row
$y2 = $y + $hTop;

$pdf->SetFont('Arial','B',12);
$pdf->HeaderBox($actX, $y2, $wPlan, $hSub, 'PLANNED', true, 6, 'C');
$pdf->HeaderBox($actX+$wPlan, $y2, $wAch, $hSub, 'ACHIEVED', true, 6, 'C');

$pdf->SetFont('Arial','B',11);
$pdf->HeaderBox($actX+$wPlan+$wAch, $y2, $wTom, $hSub, "PLANNED FOR\nTOMORROW", true, 5.5, 'C');

// vertical border between activity and remarks
$pdf->Line($remX, $y, $remX, $y + $hTop + $hSub);

// move below header
$pdf->SetXY($X0, $y + $hTop + $hSub);

// ---------------- GAP ROW under column header (same 6mm) ----------------
$pdf->EnsureSpace($GAP_ROW_H);
$pdf->Rect($X0, $pdf->GetY(), $W, $GAP_ROW_H);
$pdf->Ln($GAP_ROW_H);

// ---------------- body ----------------
$pdf->SetFont('Arial','',11);
$lineH = 8;

$rows = [];
foreach ($activities as $r) {
  if (!is_array($r)) continue;
  $rows[] = normalize_activity_row($r, $projectName);
}

$sl = 1;
foreach ($rows as $r) {
  [$planned, $achieved, $tomorrow, $remarks] = $r;

  $pdf->RowDynamicCentered(
    $X0,
    [$wSL, $wPlan, $wAch, $wTom, $wRem],
    [(string)$sl, $planned, $achieved, $tomorrow, $remarks],
    $lineH,
    ['C','C','C','C','C']
  );
  $sl++;
}

// ---------------- output ----------------
ob_end_clean();
$filename = 'DAR_' . preg_replace('/[^A-Za-z0-9\-_]/','_', $darNo) . '.pdf';

if ($MODE_STRING) {
  $GLOBALS['__DAR_PDF_RESULT__'] = [
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
