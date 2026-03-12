<?php
// checklist-report-print.php — Checklist PDF (FPDF) — A3 PORTRAIT
// Filename format changed to:
//   Mr.(SiteName)_CHECKLIST_(DocNo)_Dated_(dd-mm-YYYY).pdf
//
// Supports:
//   ?view=123              => inline view/print
//   ?view=123&dl=1         => force download
//   ?view=123&mode=string  => returns bytes in $GLOBALS['__CHECKLIST_PDF_RESULT__']

ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}

$employeeId    = (int)($_SESSION['employee_id'] ?? 0);
$viewId        = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$MODE_STRING   = (isset($_GET['mode']) && $_GET['mode'] === 'string');
$forceDownload = (isset($_GET['dl']) && $_GET['dl'] == '1');

if ($viewId <= 0) die('Invalid checklist id');

$conn = get_db_connection();
if (!$conn) die("DB connection failed");

// FETCH COMPANY NAME
$companySql    = "SELECT company_name FROM company_details WHERE id = 1 LIMIT 1";
$companyResult = mysqli_query($conn, $companySql);
$companyData   = $companyResult ? mysqli_fetch_assoc($companyResult) : [];
$companyName   = $companyData['company_name'] ?? 'TEK-C Construction Pvt. Ltd.';

/* ================= FETCH CHECKLIST DATA ================= */
$sql = "
SELECT 
    c.*, s.project_name, cl.client_name
FROM checklist_reports c
JOIN sites s ON s.id = c.site_id
JOIN clients cl ON cl.id = s.client_id
WHERE c.id = ? AND c.employee_id = ?
LIMIT 1
";
$st = mysqli_prepare($conn, $sql);
if (!$st) die(mysqli_error($conn));

mysqli_stmt_bind_param($st, "ii", $viewId, $employeeId);
mysqli_stmt_execute($st);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
mysqli_stmt_close($st);

if (!$row) die('Checklist not found');

/* ================= HELPERS ================= */
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
    if (is_array($json)) return $json;
    if ($json === null) return [];

    $json = trim((string)$json);
    if ($json === '') return [];

    $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

    $a = json_decode($json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($a)) {
        if (isset($a['rows']) && is_array($a['rows'])) return $a['rows'];
        if (isset($a['data']) && is_array($a['data'])) return $a['data'];
        return $a;
    }

    $a = json_decode(stripslashes($json), true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($a)) {
        if (isset($a['rows']) && is_array($a['rows'])) return $a['rows'];
        if (isset($a['data']) && is_array($a['data'])) return $a['data'];
        return $a;
    }

    $u = @unserialize($json);
    return is_array($u) ? $u : [];
}

function group_by_section($items){
    $o = [];
    if (!is_array($items)) return $o;
    foreach ($items as $i) {
        if (!is_array($i)) continue;
        $section = trim((string)($i['section'] ?? 'Others'));
        if ($section === '') $section = 'Others';
        $o[$section][] = $i;
    }
    return $o;
}

function safe_date_dmy($dateStr){
    $dateStr = trim((string)$dateStr);
    if ($dateStr === '' || $dateStr === '0000-00-00') return '';
    $t = strtotime($dateStr);
    return $t ? date('d-m-Y', $t) : clean_text($dateStr);
}

// Filename safety helpers
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
    $s = clean_text($s);
    $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $s);
    $s = preg_replace('/[^A-Za-z0-9 \-\_\.]/', '_', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s, " ._-");
    return $s;
}
// RFC5987 filename* encoding (UTF-8)
function rfc5987_encode($str){
    return "UTF-8''" . rawurlencode($str);
}

/* ================= PDF CLASS ================= */
class ChecklistPDF extends FPDF
{
    protected $firstPage = true;
    protected $totalPages = 0;

    public $companyName = '';
    public $ff = 'Arial';

    public $TITLE_SIZE = 14;
    public $CONTENT_SIZE = 11;

    function InitFonts()
    {
        $fontDir = __DIR__ . '/libs/fpdf/font/';
        $reg     = $fontDir . 'calibri.php';
        $bold    = $fontDir . 'calibrib.php';
        $italic  = $fontDir . 'calibrii.php';
        $bi      = $fontDir . 'calibriz.php';

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

    function Header()
    {
        if ($this->firstPage) {
            $logoPaths = [
                'public/logo.png',
                'assets/logo.png',
                'images/logo.png',
                'logo.png',
                'assets/ukb.png',
                'assets/ukb.jpg'
            ];

            $logoFound = false;
            foreach ($logoPaths as $path) {
                $full = __DIR__ . '/' . $path;
                if (file_exists($full)) {
                    $pageWidth   = $this->GetPageWidth();
                    $leftMargin  = $this->lMargin;
                    $rightMargin = $this->rMargin;
                    $usableWidth = $pageWidth - $leftMargin - $rightMargin;

                    $logoWidth = 30;
                    $x = $leftMargin + ($usableWidth - $logoWidth) / 2;
                    $this->Image($full, $x, 10, $logoWidth);
                    $logoFound = true;
                    break;
                }
            }

            if (!$logoFound) {
                $this->SetY(10);
            }

            $this->Ln(25);

            $this->SetFont($this->ff, 'B', $this->TITLE_SIZE);
            $this->Cell(0, 10, 'PROJECT ENGINEER DAILY ACTIVITY CHECKLIST', 0, 1, 'C');
            $this->Ln(10);

            $this->firstPage = false;
        }
    }

    function Footer()
    {
        $this->SetY(-20);
        $this->SetFont($this->ff, 'I', 10);

        $pageText = $this->PageNo() . ' / {nb}';
        $pageTextWidth = $this->GetStringWidth($pageText);

        $this->Cell(0, 12, clean_text($this->companyName), 0, 0, 'L');

        $this->SetX(($this->GetPageWidth() - $pageTextWidth) / 2);
        $this->Cell($pageTextWidth, 12, $pageText, 0, 0, 'C');
    }

    function Close()
    {
        $this->totalPages = $this->page;
        parent::Close();
    }

    function CheckboxItem($text, $checked = false)
    {
        $x = $this->GetX();
        $y = $this->GetY();
        $checkboxSize = 5;
        $checkboxX = $x;
        $checkboxY = $y + 1;

        $this->SetFillColor(210, 220, 255);
        $this->Rect($checkboxX, $checkboxY, $checkboxSize, $checkboxSize, 'F');
        $this->Rect($checkboxX, $checkboxY, $checkboxSize, $checkboxSize, 'D');

        if ($checked) {
            $this->SetDrawColor(0, 0, 0);
            $this->SetLineWidth(0.8);
            $centerX = $checkboxX + ($checkboxSize / 2);
            $centerY = $checkboxY + ($checkboxSize / 2);
            $this->Line($centerX - 2, $centerY, $centerX - 0.8, $centerY + 1.5);
            $this->Line($centerX - 0.8, $centerY + 1.5, $centerX + 2, $centerY - 2);
            $this->SetLineWidth(0.2);
        }

        $this->SetXY($x + 10, $y);
        $this->SetFont($this->ff, '', $this->CONTENT_SIZE);

        $pageWidth = $this->GetPageWidth();
        $rightMargin = $this->rMargin;
        $textWidth = $pageWidth - ($x + 10) - $rightMargin;

        $this->MultiCell($textWidth, 7, clean_text($text), 0, 'L');

        $finalY = $this->GetY();
        $this->SetY($finalY + 2);
    }

    function SectionTitle($text)
    {
        $this->Ln(6);
        $this->SetFont($this->ff, 'B', $this->TITLE_SIZE);
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 9, '  ' . clean_text($text), 0, 1, 'L', true);
        $this->Ln(3);
    }

    function DataField($label, $value, $labelW = 40)
    {
        $this->SetFont($this->ff, 'B', $this->CONTENT_SIZE);
        $this->Cell($labelW, 8, clean_text($label), 0, 0);

        $this->SetFont($this->ff, '', $this->CONTENT_SIZE);
        $this->SetFillColor(210, 220, 255);

        $w = $this->GetPageWidth() - $this->lMargin - $this->rMargin - $labelW;
        if ($w < 30) $w = 30;

        $this->Cell($w, 8, '  ' . clean_text($value), 1, 1, 'L', true);
        $this->Ln(3);
    }

    function SignatureField($label, $value, $labelW = 50)
    {
        $this->SetFont($this->ff, 'B', $this->CONTENT_SIZE);
        $this->Cell($labelW, 8, clean_text($label), 0, 0);

        $this->SetFont($this->ff, '', $this->CONTENT_SIZE);
        $this->SetFillColor(210, 220, 255);

        $w = $this->GetPageWidth() - $this->lMargin - $this->rMargin - $labelW;
        if ($w < 30) $w = 30;

        $this->Cell($w, 8, '  ' . clean_text($value), 1, 1, 'L', true);
        $this->Ln(5);
    }
}

/* ================= INIT PDF ================= */
$pdf = new ChecklistPDF('P', 'mm', 'A3');
$pdf->InitFonts();
$pdf->companyName = $companyName;
$pdf->AliasNbPages('{nb}');
$pdf->SetAutoPageBreak(true, 25);
$pdf->SetMargins(25, 20, 25);
$pdf->AddPage();

/* ================= HEADER DATA ================= */
$pdf->DataField('Project:', clean_text($row['project_name'] ?? ''));
$pdf->DataField('Client:', clean_text($row['client_name'] ?? ''));
$pdf->DataField('PMC:', clean_text($row['pmc_lead'] ?? ''));
$pdf->DataField('Date:', safe_date_dmy($row['checklist_date'] ?? ''));
$pdf->DataField('Doc No.:', clean_text($row['doc_no'] ?? ''));

$pdf->Ln(8);

/* ================= CHECKLIST ITEMS ================= */
$sections = group_by_section(decode_rows($row['checklist_json'] ?? ''));

$section_order = [
    'Daily Responsibilities',
    'Quality Control',
    'Coordination',
    'Safety & Compliance',
    'Documentation',
    'Escalation & Communication',
    'Weekly/Monthly Duties',
    'Professional Conduct'
];

foreach ($section_order as $section_name) {
    if (!isset($sections[$section_name])) continue;

    $items = $sections[$section_name];
    $pdf->SectionTitle($section_name);

    foreach ($items as $item) {
        $label   = clean_text($item['label'] ?? '');
        $checked = (int)($item['checked'] ?? 0) === 1;

        $pdf->CheckboxItem($label, $checked);

        if ($pdf->GetY() > 370) {
            $pdf->AddPage();
        }
    }
    $pdf->Ln(3);

    unset($sections[$section_name]);
}

foreach ($sections as $section_name => $items) {
    $pdf->SectionTitle($section_name);

    foreach ($items as $item) {
        $label   = clean_text($item['label'] ?? '');
        $checked = (int)($item['checked'] ?? 0) === 1;

        $pdf->CheckboxItem($label, $checked);

        if ($pdf->GetY() > 370) {
            $pdf->AddPage();
        }
    }
    $pdf->Ln(3);
}

/* ================= SIGNATURES ================= */
$pdf->Ln(15);
$pdf->SignatureField('Project Engineer:', clean_text($row['project_engineer'] ?? ''));
$pdf->SignatureField('PMC Lead:', clean_text($row['pmc_lead'] ?? ''));

/* ================= OUTPUT ================= */
// Filename: Mr.(SiteName)_CHECKLIST_(DocNo)_Dated_(dd-mm-YYYY).pdf
$sitePart = safe_filename_site($row['project_name'] ?? '');
$docPart  = safe_filename_basic($row['doc_no'] ?? '');
$datePart = safe_filename_site(safe_date_dmy($row['checklist_date'] ?? ''));

if ($sitePart === '') $sitePart = 'SITE';
if ($docPart === '')  $docPart  = 'ID_' . $viewId;
if ($datePart === '') $datePart = date('d-m-Y');

$filename = 'Mr.' . $sitePart . '_CHECKLIST_' . $docPart . '_Dated_' . $datePart . '.pdf';

// mode=string output for embedding/other scripts
if ($MODE_STRING) {
    $pdfBytes = $pdf->Output('S');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $GLOBALS['__CHECKLIST_PDF_RESULT__'] = [
        'filename' => $filename,
        'bytes'    => $pdfBytes,
    ];

    try {
        if (isset($conn) && $conn instanceof mysqli) $conn->close();
    } catch (Throwable $e) {}

    return;
}

// browser output (inline / download)
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