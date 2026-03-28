<?php
// report-dar-print.php — DAR PDF (FPDF) — DPR STYLE HEADER (A3, Title 14, Content 11) + DB LOGO + FOOTER
//
// Supports:
//   ?view=123              => inline view/print
//   ?view=123&dl=1         => force download
//   ?view=123&mode=string  => returns bytes in $GLOBALS['__DAR_PDF_RESULT__']

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

$employeeId     = (int)($_SESSION['employee_id'] ?? 0);
$designationRaw = (string)($_SESSION['designation'] ?? '');
$designation    = strtolower(trim($designationRaw));

$MODE_STRING    = (isset($_GET['mode']) && $_GET['mode'] === 'string');
$forceDownload  = (isset($_GET['dl']) && $_GET['dl'] == '1');

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

function decode_rows($json){
    $json = (string)$json;
    if (trim($json) === '') return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function fmt_dmy_dash($ymd){
    $ymd = trim((string)$ymd);
    if ($ymd === '' || $ymd === '0000-00-00') return '';
    $t = strtotime($ymd);
    return $t ? date('d-m-Y', $t) : $ymd;
}

function roleScope(string $designationLower): string {
    if (in_array($designationLower, ['director','vice president','general manager'], true)) return 'all';
    if ($designationLower === 'manager') return 'manager';
    return 'self';
}

// Safe filename helpers
function safe_filename_site($s){
    $s = clean_text($s);
    $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $s);
    $s = preg_replace('/[^A-Za-z0-9 \-\_\.]/', '_', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s, " ._-");
    return $s;
}
function safe_filename_basic($s){
    return safe_filename_site($s);
}
function rfc5987_encode($str){
    return "UTF-8''" . rawurlencode($str);
}

// ---------------- COMPANY (name + logo from DB) ----------------
$companyName = 'UKB Construction Management Pvt Ltd';
$companyLogoDb = '';
$companySql = "SELECT company_name, logo_path FROM company_details WHERE id = 1 LIMIT 1";
$companyResult = mysqli_query($conn, $companySql);
if ($companyResult) {
    $companyData = mysqli_fetch_assoc($companyResult);
    if (!empty($companyData['company_name'])) $companyName = $companyData['company_name'];
    if (!empty($companyData['logo_path']))   $companyLogoDb = $companyData['logo_path'];
}

// ---------------- load DAR ----------------
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid DAR id");

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
    s.project_location,
    c.client_name
  FROM dar_reports r
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

if (!$row) die("DAR not found or not allowed");

// Header fields
$division     = clean_text($row['division'] ?? '');
$incharge     = clean_text($row['incharge'] ?? '');
$darNo        = clean_text($row['dar_no'] ?? '');
$darDateYmd   = (string)($row['dar_date'] ?? '');
$darDateDMY   = fmt_dmy_dash($darDateYmd);

$projectName  = clean_text($row['project_name'] ?? '');
$clientName   = clean_text($row['client_name'] ?? '');

$activities   = decode_rows($row['activities_json'] ?? '');

// ---------------- PDF class (DPR-style header) ----------------
class DARPDF extends FPDF {
    public $meta = [];
    public $logoPath = '';

    public $outerX = 8;
    public $outerY = 8;
    public $outerW = 0;

    public $GREY = [220,220,220];

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

    function Header(){
        $this->SetLineWidth(0.35);

        $this->outerW = $this->GetPageWidth() - 16;
        $outerH = $this->GetPageHeight() - 16;
        $this->Rect($this->outerX, $this->outerY, $this->outerW, $outerH);

        $X0 = $this->outerX;
        $Y0 = $this->outerY;

        // Same proportions as DPR header
        $headerH = 32;
        $logoW   = 32;
        $rightW  = 115;
        $titleW  = $this->outerW - $logoW - $rightW;

        // Logo cell
        $this->SetXY($X0, $Y0);
        $this->Cell($logoW, $headerH, '', 1, 0, 'C');

        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, $X0+2, $Y0+2, $logoW-4, $headerH-4);
        }

        // Title cell (grey)
        $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
        $this->SetFont($this->ff, 'B', $this->TITLE_SIZE);
        $this->Cell($titleW, $headerH, 'DAILY ACTIVITY REPORT (DAR)', 1, 0, 'C', true);

        // Right meta table (5 rows)
        $rx = $X0 + $logoW + $titleW;
        $ry = $Y0;
        $rH = $headerH / 5;
        $labW = 32;
        $valW = $rightW - $labW;

        $rows = [
            ['Project',  "Mr." . ($this->meta['project_name'] ?? '')],
            ['Client',   "Mr." . ($this->meta['client_name'] ?? '')],
            ['Division', ($this->meta['division'] ?? '')],
            ['DAR',      ($this->meta['dar_no'] ?? '')],
            ['Date',     ($this->meta['date'] ?? '')],
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

        $this->SetY($Y0 + $headerH + 8);
    }

    function Footer(){
        $this->SetY(-20);
        $this->SetFont($this->ff, 'I', 10);

        $company = (string)($this->meta['company'] ?? '');
        $pageText = $this->PageNo() . ' / {nb}';
        $pageTextWidth = $this->GetStringWidth($pageText);

        $this->Cell(0, 12, clean_text($company), 0, 0, 'L');

        $this->SetX(($this->GetPageWidth() - $pageTextWidth) / 2);
        $this->Cell($pageTextWidth, 12, $pageText, 0, 0, 'C');
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

    function EnsureSpace($needH){
        if ($this->GetY() + $needH > ($this->GetPageHeight() - 24)) {
            $this->AddPage();
        }
    }

    function RowDynamic($x,$widths,$cells,$lineH=6,$aligns=[]){
        $maxLines = 1;
        for($i=0; $i<count($cells); $i++){
            $maxLines = max($maxLines, $this->NbLines($widths[$i], (string)($cells[$i] ?? '')));
        }
        $h = $maxLines * $lineH;

        $this->EnsureSpace($h);

        $y = $this->GetY();
        $curX = $x;

        for($i=0; $i<count($cells); $i++){
            $w = $widths[$i];
            $a = $aligns[$i] ?? 'L';
            $txt = (string)($cells[$i] ?? '');

            $this->Rect($curX, $y, $w, $h);

            if (trim($txt) !== '') {
                $lines = $this->NbLines($w-2, $txt);
                $textH = $lines * $lineH;
                $startY = $y + max(0, ($h - $textH)/2);

                $this->SetXY($curX+1, $startY);
                $this->MultiCell($w-2, $lineH, $txt, 0, $a);
            }
            $curX += $w;
        }

        $this->SetXY($x, $y + $h);
        return $h;
    }
}

// ---------------- setup PDF (A3) ----------------
$meta = [
    'company'      => $companyName,
    'project_name' => $projectName,
    'client_name'  => $clientName,
    'division'     => $division,
    'incharge'     => $incharge,
    'dar_no'       => $darNo,
    'date'         => $darDateDMY,
];

$pdf = new DARPDF('P','mm','A3');
$pdf->InitFonts();
$pdf->SetMargins(8,8,8);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.35);
$pdf->AliasNbPages('{nb}');
$pdf->SetMeta($meta);

// Logo: prefer DB logo_path, else fallback list
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

$pdf->AddPage();

$X0 = 8;
$W  = $pdf->GetPageWidth() - 16;

// ---------------- TABLE HEADER (same logic as your original) ----------------
$pdf->SetFillColor(141,180,226);

$wSL  = 18;
$wRem = 60;
$wAct = $W - $wSL - $wRem;
$subColW = $wAct / 3;

$hHeadTop   = 8;
$hHeadSub   = 10;
$hHeadTotal = $hHeadTop + $hHeadSub;

$yH = $pdf->GetY();

$pdf->Rect($X0, $yH, $wSL, $hHeadTotal, 'F');
$pdf->Rect($X0, $yH, $wSL, $hHeadTotal);

$pdf->SetFont($pdf->ff,'B',11);
$pdf->SetXY($X0, $yH + ($hHeadTotal/2) - 4);
$pdf->Cell($wSL, 8, 'SL NO', 0, 0, 'C');

$pdf->Rect($X0+$wSL, $yH, $wAct, $hHeadTop, 'F');
$pdf->Rect($X0+$wSL, $yH, $wAct, $hHeadTop);
$pdf->SetXY($X0+$wSL, $yH + 2);
$pdf->SetFont($pdf->ff,'B',11);
$pdf->Cell($wAct, 5, 'ACTIVITY', 0, 0, 'C');

$pdf->Rect($X0+$wSL+$wAct, $yH, $wRem, $hHeadTotal, 'F');
$pdf->Rect($X0+$wSL+$wAct, $yH, $wRem, $hHeadTotal);
$pdf->SetXY($X0+$wSL+$wAct, $yH + ($hHeadTotal/2) - 4);
$pdf->SetFont($pdf->ff,'B',11);
$pdf->Cell($wRem, 8, 'REMARKS', 0, 0, 'C');

$yH2 = $yH + $hHeadTop;

$pdf->Rect($X0+$wSL, $yH2, $subColW, $hHeadSub, 'F');
$pdf->Rect($X0+$wSL, $yH2, $subColW, $hHeadSub);
$pdf->SetXY($X0+$wSL, $yH2 + 3);
$pdf->SetFont($pdf->ff,'B',11);
$pdf->Cell($subColW, 5, 'PLANNED', 0, 0, 'C');

$pdf->Rect($X0+$wSL+$subColW, $yH2, $subColW, $hHeadSub, 'F');
$pdf->Rect($X0+$wSL+$subColW, $yH2, $subColW, $hHeadSub);
$pdf->SetXY($X0+$wSL+$subColW, $yH2 + 3);
$pdf->SetFont($pdf->ff,'B',11);
$pdf->Cell($subColW, 5, 'ACHIEVED', 0, 0, 'C');

$pdf->Rect($X0+$wSL+($subColW*2), $yH2, $subColW, $hHeadSub, 'F');
$pdf->Rect($X0+$wSL+($subColW*2), $yH2, $subColW, $hHeadSub);
$pdf->SetFont($pdf->ff,'B',11);
$pdf->SetXY($X0+$wSL+($subColW*2), $yH2 + 1);
$pdf->Cell($subColW, 4, 'PLANNED FOR', 0, 0, 'C');
$pdf->SetXY($X0+$wSL+($subColW*2), $yH2 + 6);
$pdf->Cell($subColW, 4, 'TOMORROW', 0, 0, 'C');

$pdf->SetY($yH + $hHeadTotal);

// ---------------- TBODY ----------------
$pdf->SetFont($pdf->ff,'',11);

$lineH_data = 6;
$gapRowH    = 6;

$widths = [$wSL, $subColW, $subColW, $subColW, $wRem];

// gap row BEFORE first content line
$pdf->RowDynamic($X0, $widths, ['', '', '', '', ''], $gapRowH, ['C','C','C','C','C']);

if (empty($activities)) {
    $pdf->RowDynamic($X0, $widths, ['1', '', '', '', 'No activities'], $lineH_data, ['C','L','C','L','L']);
    $pdf->RowDynamic($X0, $widths, ['', '', '', '', ''], $gapRowH, ['C','C','C','C','C']);
} else {
    $sl = 1;
    foreach ($activities as $act) {
        if (!is_array($act)) continue;

        $planned  = clean_text($act['planned'] ?? '');
        $achieved = clean_text($act['achieved'] ?? '');
        $tomorrow = clean_text($act['tomorrow'] ?? '');
        $remark   = clean_text($act['remarks'] ?? '');

        $pdf->RowDynamic(
            $X0,
            $widths,
            [(string)$sl, $planned, $achieved, $tomorrow, $remark],
            $lineH_data,
            ['C','L','C','L','L']
        );

        $pdf->RowDynamic($X0, $widths, ['', '', '', '', ''], $gapRowH, ['C','C','C','C','C']);
        $sl++;
    }
}

// ---------------- output (FILENAME WITH SITENAME) ----------------
// Example: Mr.Anandhamayam_DAR_DAR-1-20260207-01_Dated_07-02-2026.pdf
$sitePart = safe_filename_site($projectName);
$noPart   = safe_filename_basic($darNo);
$datePart = safe_filename_site($darDateDMY);

if ($sitePart === '') $sitePart = 'SITE';
if ($noPart === '')   $noPart   = 'ID_' . $viewId;
if ($datePart === '') $datePart = date('d-m-Y');

$filename = 'Mr.' . $sitePart . '_DAR_' . $noPart . '_Dated_' . $datePart . '.pdf';

// MODE_STRING path (for mail attachment or internal usage)
if ($MODE_STRING) {
    $pdfBytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__DAR_PDF_RESULT__'] = [
        'filename' => $filename,
        'bytes'    => $pdfBytes,
    ];

    try {
        if (isset($conn) && $conn instanceof mysqli) $conn->close();
    } catch (Throwable $e) {}

    return;
}

// Normal browser response path (inline or download)
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