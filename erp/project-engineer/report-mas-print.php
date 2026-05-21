<?php
ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

date_default_timezone_set('Asia/Kolkata');

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$conn = get_db_connection();
if (!$conn) {
    die("DB failed");
}

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) {
    die("Invalid ID");
}

// ===== FETCH MAIN =====
$sql = "SELECT * FROM mas_main WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$mas = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$mas) {
    mysqli_close($conn);
    die("MAS record not found");
}

// ===== FETCH DETAILS =====
$sql = "SELECT * FROM mas_details WHERE mas_main_id = ? ORDER BY sl_no ASC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

mysqli_close($conn);

// ===== HELPERS =====
function pdfText($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '-';
    }

    // FPDF default font supports ISO-8859-1 better than UTF-8.
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);
}

function pdfDate($date) {
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        return '-';
    }

    $ts = strtotime($date);
    if (!$ts) {
        return $date;
    }

    return date('d-m-Y', $ts);
}

// ===== PDF CLASS =====
class MASPDF extends FPDF {

    private $mas = [];

    function setData($mas) {
        $this->mas = is_array($mas) ? $mas : [];
    }

    function Header() {

        // ===== OUTER BORDER =====
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.15);
        $this->Rect(10, 10, 277, 190);

        $X = 10;
        $Y = 10;

        $logoW   = 30;
        $rightW  = 110;
        $titleW  = 277 - ($logoW + $rightW);
        $headerH = 30;

        // ===== LOGO BOX =====
        $this->Rect($X, $Y, $logoW, $headerH);

        $logoCandidates = [
            __DIR__ . '/assets/logo.png',
            __DIR__ . '/assets/logo.jpg',
            __DIR__ . '/assets/ukb.png',
            __DIR__ . '/assets/ukb.jpg',
            __DIR__ . '/public/logo.png',
            __DIR__ . '/public/logo.jpg',
            __DIR__ . '/images/logo.png',
            __DIR__ . '/images/logo.jpg',
            __DIR__ . '/logo.png',
            __DIR__ . '/logo.jpg',
            __DIR__ . '/../assets/logo.png',
            __DIR__ . '/../assets/ukb.png',
        ];

        foreach ($logoCandidates as $path) {
            if (file_exists($path)) {
                $gap = 1;
                $imgW = $logoW - (2 * $gap);
                $imgH = $headerH - (2 * $gap);
                $this->Image($path, $X + $gap, $Y + $gap, $imgW, $imgH);
                break;
            }
        }

        // ===== TITLE =====
        $this->SetFillColor(220, 220, 220);
        $this->Rect($X + $logoW, $Y, $titleW, $headerH, 'FD');

        $this->SetFont('Arial', 'B', 16);
        $this->SetXY($X + $logoW, $Y + 9);
        $this->Cell($titleW, 8, 'MEETING ATTENDANCE SHEET', 0, 0, 'C');

        // ===== RIGHT SIDE VALUES =====
        $meetingHeld = pdfText($this->mas['meeting_venue'] ?? '') . ' / ' . pdfDate($this->mas['meeting_date'] ?? '');

        $fields = [
            'Project'                 => pdfText($this->mas['project_name'] ?? ''),
            'Client'                  => pdfText($this->mas['client_name'] ?? ''),
            'Architect'               => pdfText($this->mas['architect'] ?? ''),
            'PMC'                     => pdfText($this->mas['pmc'] ?? ''),
            'Meeting Held at/ Dated'  => $meetingHeld,
        ];

        $rowH   = $headerH / count($fields);
        $labelW = 45;
        $valueW = $rightW - $labelW;

        foreach ($fields as $label => $value) {
            $this->SetXY($X + $logoW + $titleW, $Y);

            $this->SetFont('Arial', 'B', 8);
            $this->Cell($labelW, $rowH, pdfText($label), 1, 0, 'L');

            $this->SetFont('Arial', '', 8);
            $this->Cell($valueW, $rowH, $value, 1, 0, 'L');

            $Y += $rowH;
        }

        $this->SetY(10 + $headerH + 5);
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

// ===== INIT PDF =====
$pdf = new MASPDF('L', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AliasNbPages();

// IMPORTANT FIX:
// Pass MAS data before AddPage(), because Header() runs inside AddPage().
$pdf->setData($mas);

$pdf->AddPage();

// ===== TABLE WIDTHS =====
$w = [15, 80, 60, 40, 55, 27]; // total = 277

// ===== TABLE HEADER =====
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(141, 180, 226);
$pdf->SetLineWidth(0.1);

$headers = ['SL.NO.', 'ATTENDEE NAME', 'ORGANIZATION', 'MOBILE NO.', 'EMAIL ID', 'SIGNATURE'];

foreach ($headers as $i => $h) {
    $pdf->Cell($w[$i], 10, pdfText($h), 1, 0, 'C', true);
}
$pdf->Ln();

// ===== BODY =====
$pdf->SetFont('Arial', '', 10);

foreach ($rows as $r) {

    if ($pdf->GetY() > 185) {
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(141, 180, 226);

        foreach ($headers as $i => $h) {
            $pdf->Cell($w[$i], 10, pdfText($h), 1, 0, 'C', true);
        }

        $pdf->Ln();
        $pdf->SetFont('Arial', '', 10);
    }

    $pdf->Cell($w[0], 8, pdfText($r['sl_no'] ?? ''), 1, 0, 'C');
    $pdf->Cell($w[1], 8, pdfText($r['attendee_name'] ?? ''), 1);
    $pdf->Cell($w[2], 8, pdfText($r['organization'] ?? ''), 1);
    $pdf->Cell($w[3], 8, pdfText($r['mobile_no'] ?? ''), 1);
    $pdf->Cell($w[4], 8, pdfText($r['email_id'] ?? ''), 1);
    $pdf->Cell($w[5], 8, '', 1);
    $pdf->Ln();
}

// ===== OUTPUT =====
while (ob_get_level()) {
    ob_end_clean();
}

$filename = "MAS_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $mas['mas_no'] ?? 'report') . ".pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');

$pdf->Output('I', $filename);
exit;