<?php
// report-dlar-print.php — DLAR PDF A4 landscape fit template
//
// Supports:
//   ?view=123              => inline view/print
//   ?view=123&dl=1         => force download
//   ?view=123&mode=string  => returns bytes in $GLOBALS['__DLAR_PDF_RESULT__']

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
$sessionRole    = strtolower(trim((string)($_SESSION['role'] ?? '')));

$MODE_STRING   = (isset($_GET['mode']) && $_GET['mode'] === 'string');
$forceDownload = (isset($_GET['dl']) && $_GET['dl'] == '1');

function normalizeAccessRole(string $designation, string $sessionRole = ''): string {
    $d = strtolower(trim($designation));
    $r = strtolower(trim($sessionRole));

    if (in_array($r, ['admin', 'administrator', 'super admin'], true)) return 'admin';
    if (in_array($d, ['admin', 'administrator', 'director', 'vice president', 'general manager'], true)) return 'admin';
    if ($d === 'manager') return 'manager';
    if ($d === 'team lead') return 'tl';
    if (in_array($d, ['project engineer grade 1', 'project engineer grade 2', 'sr. engineer', 'engineer', 'project engineer'], true)) return 'engineer';

    return 'employee';
}

$accessRole = normalizeAccessRole($designationRaw, $sessionRole);

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

$companyName   = 'UKB Construction Management Pvt Ltd';
$companyLogoDb = '';

$companySql = "SELECT company_name, logo_path FROM company_details WHERE id = 1 LIMIT 1";
$companyResult = mysqli_query($conn, $companySql);
if ($companyResult) {
    $companyData = mysqli_fetch_assoc($companyResult);
    if (!empty($companyData['company_name'])) $companyName = $companyData['company_name'];
    if (!empty($companyData['logo_path']))   $companyLogoDb = $companyData['logo_path'];
}

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid DLAR id");

$sql = "
    SELECT
        r.*,
        s.project_name AS site_project_name,
        s.project_location,
        s.manager_employee_id,
        s.team_lead_employee_id,
        c.client_name AS site_client_name
    FROM dlar_reports r
    INNER JOIN sites s ON s.id = r.site_id
    INNER JOIN clients c ON c.id = s.client_id
    WHERE r.id = ?
    LIMIT 1
";

$st = mysqli_prepare($conn, $sql);
if (!$st) die("SQL Error: " . mysqli_error($conn));

mysqli_stmt_bind_param($st, "i", $viewId);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($st);

if (!$row) die("DLAR not found");

$canAccess = false;
if ($accessRole === 'admin') {
    $canAccess = true;
} elseif ($accessRole === 'manager') {
    $canAccess = ((int)($row['manager_employee_id'] ?? 0) === $employeeId);
} elseif ($accessRole === 'tl') {
    $canAccess = ((int)($row['team_lead_employee_id'] ?? 0) === $employeeId);
} else {
    $canAccess = ((int)($row['employee_id'] ?? 0) === $employeeId);
}

if (!$canAccess) {
    die("DLAR not found or not allowed");
}

$dlarNo         = clean_text($row['dlar_no'] ?? '');
$reportDateY    = (string)($row['report_date'] ?? '');
$reportDateDMY  = fmt_dmy_dash($reportDateY);

$projectName    = clean_text($row['project_name'] ?? $row['site_project_name'] ?? '');
$clientName     = clean_text($row['client_name'] ?? $row['site_client_name'] ?? '');
$architectName  = clean_text($row['architect_name'] ?? '');
$pmcName        = clean_text($row['pmc_name'] ?? '');
$dateVersion    = clean_text($row['date_version'] ?? '');
$items          = decode_rows($row['items_json'] ?? '');

class DLARPDF extends FPDF {
    public $meta = [];
    public $logoPath = '';
    public $outerX = 5;
    public $outerY = 5;
    public $outerW = 0;
    public $outerH = 0;

    public $GREY = [220,220,220];

    public $ff = 'Arial';
    public $TITLE_SIZE = 14;

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
        $this->SetLineWidth(0.25);

        $this->outerW = $this->GetPageWidth() - 10;
        $this->outerH = $this->GetPageHeight() - 10;
        $this->Rect($this->outerX, $this->outerY, $this->outerW, $this->outerH);

        $X0 = $this->outerX;
        $Y0 = $this->outerY;

        $headerH = 28;
        $logoW   = 28;
        $rightW  = 72;
        $titleW  = $this->outerW - $logoW - $rightW;

        $this->Rect($X0, $Y0, $logoW, $headerH);
        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, $X0+3, $Y0+3, $logoW-6, $headerH-6);
        }

        $this->SetFillColor($this->GREY[0], $this->GREY[1], $this->GREY[2]);
        $this->Rect($X0 + $logoW, $Y0, $titleW, $headerH, 'FD');
        $this->SetFont($this->ff, 'B', $this->TITLE_SIZE);
        $this->SetXY($X0 + $logoW, $Y0 + 8);
        $this->Cell($titleW, 8, 'DELAY ANALYSIS REPORT(DLAR)', 0, 0, 'C');

        $rx = $X0 + $logoW + $titleW;
        $ry = $Y0;
        $rH = $headerH / 5;
        $labW = 22;
        $valW = $rightW - $labW;

        $rows = [
            ['Project',      $this->meta['project_name'] ?? ''],
            ['Client',       $this->meta['client_name'] ?? ''],
            ['Architect',    $this->meta['architect_name'] ?? ''],
            ['PMC',          $this->meta['pmc_name'] ?? ''],
            ['Date/Version', $this->meta['date_version'] ?? ''],
        ];

        for($i=0;$i<5;$i++){
            $y = $ry + $i*$rH;
            $this->SetXY($rx, $y);

            $this->SetFont($this->ff,'B',8.5);
            $this->Cell($labW, $rH, clean_text($rows[$i][0]), 1, 0, 'L');

            $txt = clean_text((string)$rows[$i][1]);
            $fs = 8.5;
            $this->SetFont($this->ff,'', $fs);
            while ($fs > 6 && $this->GetStringWidth($txt) > ($valW - 1.5)) {
                $fs -= 0.3;
                $this->SetFont($this->ff,'', $fs);
            }
            $this->Cell($valW, $rH, $txt, 1, 0, 'L');
        }

        $this->SetY($Y0 + $headerH + 6);
    }

    function Footer(){
        $this->SetY(-12);
        $this->SetFont($this->ff, 'I', 8);

        $company = (string)($this->meta['company'] ?? '');
        $pageText = $this->PageNo() . ' / {nb}';
        $pageTextWidth = $this->GetStringWidth($pageText);

        $this->Cell(0, 6, clean_text($company), 0, 0, 'L');
        $this->SetX(($this->GetPageWidth() - $pageTextWidth) / 2);
        $this->Cell($pageTextWidth, 6, $pageText, 0, 0, 'C');
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
        if ($this->GetY() + $needH > ($this->GetPageHeight() - 15)) {
            $this->AddPage();
        }
    }

    function FitCellText($x, $y, $w, $h, $txt, $fill=false, $style='B', $baseSize=7.5){
        if ($fill) {
            $this->Rect($x, $y, $w, $h, 'FD');
        } else {
            $this->Rect($x, $y, $w, $h);
        }

        $txt = clean_text($txt);
        if ($txt === '') return;

        $fontSize = $baseSize;
        $this->SetFont($this->ff, $style, $fontSize);
        while ($fontSize > 5.2 && $this->GetStringWidth($txt) > ($w - 1.2)) {
            $fontSize -= 0.25;
            $this->SetFont($this->ff, $style, $fontSize);
        }

        $this->SetXY($x, $y + (($h - 4) / 2));
        $this->Cell($w, 4, $txt, 0, 0, 'C');
    }

    function MultiRow($x, $widths, $cells, $lineH = 5, $aligns = []){
        $maxLines = 1;
        for($i=0; $i<count($cells); $i++){
            $maxLines = max($maxLines, $this->NbLines($widths[$i], (string)($cells[$i] ?? '')));
        }
        $h = max(8, $maxLines * $lineH);

        $this->EnsureSpace($h);

        $y = $this->GetY();
        $curX = $x;

        for($i=0; $i<count($cells); $i++){
            $w = $widths[$i];
            $a = $aligns[$i] ?? 'L';
            $txt = clean_text((string)($cells[$i] ?? ''));

            $this->Rect($curX, $y, $w, $h);

            if ($txt !== '') {
                $lines = $this->NbLines($w-2, $txt);
                $textH = $lines * $lineH;
                $startY = $y + max(0, ($h - $textH) / 2);

                $this->SetXY($curX + 1, $startY);
                $this->MultiCell($w - 2, $lineH, $txt, 0, $a);
            }

            $curX += $w;
        }

        $this->SetXY($x, $y + $h);
        return $h;
    }

    function EmptyRow($x, $widths, $h = 8){
        $this->EnsureSpace($h);
        $y = $this->GetY();
        $curX = $x;
        foreach ($widths as $w) {
            $this->Rect($curX, $y, $w, $h);
            $curX += $w;
        }
        $this->SetXY($x, $y + $h);
    }
}

$meta = [
    'company'        => $companyName,
    'project_name'   => $projectName,
    'client_name'    => $clientName,
    'architect_name' => $architectName,
    'pmc_name'       => $pmcName,
    'date_version'   => $dateVersion !== '' ? $dateVersion : $reportDateDMY,
    'dlar_no'        => $dlarNo,
    'date'           => $reportDateDMY,
];

$pdf = new DLARPDF('L', 'mm', 'A4');
$pdf->InitFonts();
$pdf->SetMargins(5,5,5);
$pdf->SetAutoPageBreak(false);
$pdf->SetLineWidth(0.25);
$pdf->AliasNbPages('{nb}');
$pdf->SetMeta($meta);

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
    if ($p && file_exists($p)) {
        $pdf->logoPath = $p;
        break;
    }
}

$pdf->AddPage();

$X0 = 5;

// A4 landscape safe widths
$wSL        = 12;
$wTask      = 66;
$wPlanned   = 30;
$wActual    = 30;
$wDelay     = 18;
$wResp      = 26;
$wOpened    = 36;
$wReminders = 43;
$wClosed    = 26;

$widths = [$wSL, $wTask, $wPlanned, $wActual, $wDelay, $wResp, $wOpened, $wReminders, $wClosed];

$pdf->SetFillColor(141,180,226);
$pdf->SetFont($pdf->ff,'B',7);

$hTop   = 9;
$hSub   = 10;
$hTotal = $hTop + $hSub;

$yH = $pdf->GetY();

$pdf->FitCellText($X0, $yH, $wSL, $hTotal, 'SL NO', true, 'B', 7.2);
$pdf->FitCellText($X0 + $wSL, $yH, $wTask, $hTotal, 'DELAYED TASK', true, 'B', 7.2);

$xComp = $X0 + $wSL + $wTask;
$pdf->Rect($xComp, $yH, $wPlanned + $wActual, $hTop, 'FD');
$pdf->SetFont($pdf->ff,'B',7);
$pdf->SetXY($xComp, $yH + 2.2);
$pdf->Cell($wPlanned + $wActual, 4, 'COMPLETION DATE', 0, 0, 'C');

$xDelay = $xComp + $wPlanned + $wActual;
$pdf->FitCellText($xDelay, $yH, $wDelay, $hTotal, 'DELAY (DAYS)', true, 'B', 6.0);

$xResp = $xDelay + $wDelay;
$pdf->FitCellText($xResp, $yH, $wResp, $hTotal, 'DELAY RESPONSE BY', true, 'B', 5.8);

$xEng = $xResp + $wResp;
$pdf->Rect($xEng, $yH, $wOpened + $wReminders + $wClosed, $hTop, 'FD');
$pdf->SetFont($pdf->ff,'B',6.2);
$pdf->SetXY($xEng, $yH + 2.2);
$pdf->Cell($wOpened + $wReminders + $wClosed, 4, 'ENGINEER IN CHARGE/PMC ACTION', 0, 0, 'C');

$yH2 = $yH + $hTop;
$pdf->FitCellText($xComp, $yH2, $wPlanned, $hSub, 'PLANNED', true, 'B', 6.5);
$pdf->FitCellText($xComp + $wPlanned, $yH2, $wActual, $hSub, 'ACTUAL', true, 'B', 6.5);

$pdf->FitCellText($xEng, $yH2, $wOpened, $hSub, 'ISSUES OPENED ON', true, 'B', 5.2);
$pdf->FitCellText($xEng + $wOpened, $yH2, $wReminders, $hSub, 'NO. OF REMINDERS / FOLLOW UPS DATED', true, 'B', 4.9);
$pdf->FitCellText($xEng + $wOpened + $wReminders, $yH2, $wClosed, $hSub, 'ISSUES CLOSED ON', true, 'B', 5.2);

$pdf->SetY($yH + $hTotal);
$pdf->SetFont($pdf->ff,'',8);

$filledRows = 0;
if (!empty($items)) {
    foreach ($items as $idx => $item) {
        if (!is_array($item)) continue;

        $rowData = [
            (string)($item['sl_no'] ?? ($idx + 1)),
            clean_text($item['delayed_task'] ?? ''),
            fmt_dmy_dash($item['planned_date'] ?? ''),
            fmt_dmy_dash($item['actual_date'] ?? ''),
            clean_text($item['delay_days'] ?? ''),
            clean_text($item['delay_response_by'] ?? ''),
            fmt_dmy_dash($item['issues_opened_on'] ?? ''),
            clean_text($item['reminders_dated'] ?? ''),
            fmt_dmy_dash($item['issues_closed_on'] ?? ''),
        ];

        $pdf->MultiRow(
            $X0,
            $widths,
            $rowData,
            4.5,
            ['C','L','C','C','C','C','C','L','C']
        );

        $filledRows++;
    }
}

$minRows = 10;
if ($filledRows < $minRows) {
    for ($i = $filledRows; $i < $minRows; $i++) {
        $pdf->EmptyRow($X0, $widths, 7.5);
    }
}

$sitePart = safe_filename_site($projectName);
$noPart   = safe_filename_basic($dlarNo);
$datePart = safe_filename_site($reportDateDMY);

if ($sitePart === '') $sitePart = 'SITE';
if ($noPart === '')   $noPart   = 'ID_' . $viewId;
if ($datePart === '') $datePart = date('d-m-Y');

$filename = 'Mr.' . $sitePart . '_DLAR_' . $noPart . '_Dated_' . $datePart . '.pdf';

if ($MODE_STRING) {
    $pdfBytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__DLAR_PDF_RESULT__'] = [
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