<?php
// report-pd-print.php - PD PDF Report

ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}

$conn = get_db_connection();
if (!$conn) die("DB failed");

$viewId = (int)($_GET['view'] ?? 0);
if ($viewId <= 0) die("Invalid ID");

// ===== FETCH DATA =====
$stmt = $conn->prepare("SELECT * FROM pd_main WHERE id=?");
$stmt->bind_param("i", $viewId);
$stmt->execute();
$pd = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM pd_details WHERE pd_main_id=? ORDER BY sl_no");
$stmt->bind_param("i", $viewId);
$stmt->execute();
$details = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

// ===== PDF CLASS =====
class PDPDF extends FPDF {

    private $pd;

    function setData($pd){
        $this->pd = $pd;
    }

    function Header(){
        $X=10; $Y=10;
        $totalW=277; 
        $this->SetLineWidth(0.2);
        $this->Rect($X,$Y,$totalW,190);
        $logoW  = 28;
        $rightW = 90;
        $titleW = 277 - ($logoW + $rightW);
        $headerH = 28;

        // LOGO
        $this->Rect($X,$Y,$logoW,$headerH);

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

        // TITLE
        $this->SetFillColor(220,220,220);
        $this->Rect($X+$logoW,$Y,$titleW,$headerH,'FD');
        $this->SetFont('Arial','B',13);
        $this->SetXY($X+$logoW,$Y+10);
        $this->Cell($titleW,6,'PROJECT DIRECTORY (PD)',0,0,'C');

        // RIGHT GRID
        $rx = $X+$logoW+$titleW;
        $rows = [
            ['Project', $this->pd['project_name'] ?? ''],
            ['Client', $this->pd['client_name'] ?? ''],
            ['Architect', $this->pd['architect'] ?? ''],
            ['PMC', $this->pd['pmc'] ?? ''],
            ['Date/Version', ($this->pd['pd_date'] ?? '').' / '.($this->pd['version'] ?? 'R0')]
        ];

        $rowH = $headerH / count($rows);
        $labelW = 30;
        $valueW = $rightW - $labelW;

        foreach($rows as $i => $r){
            $this->SetXY($rx, $Y + $i * $rowH);
            $this->SetFillColor(230,230,230);
            $this->SetFont('Arial','B',9);
            $this->Cell($labelW, $rowH, $r[0], 1, 0, 'L', true);
            $this->SetFont('Arial','',9);
            $this->Cell($valueW, $rowH, $r[1], 1, 0, 'L');
        }

        $this->SetY($Y + $headerH + 5);
    }

    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
}

// ===== INIT =====
$pdf = new PDPDF('L','mm','A4');
$pdf->SetMargins(10,10,10);
$pdf->AliasNbPages();
$pdf->setData($pd);
$pdf->AddPage();



// Column widths
$sw = [12, 50, 45, 40, 40, 45, 45];

// Header
$pdf->SetFillColor(141, 169, 196);
$pdf->SetTextColor(0, 0, 0);

$pdf->Cell($sw[0], 8, 'SL NO', 1, 0, 'C', true);
$pdf->Cell($sw[1], 8, 'STAKEHOLDERS', 1, 0, 'C', true);
$pdf->Cell($sw[2], 8, 'COMPANY', 1, 0, 'C', true);
$pdf->Cell($sw[3], 8, 'CONTACT PERSON', 1, 0, 'C', true);
$pdf->Cell($sw[4], 8, 'DESIGNATION', 1, 0, 'C', true);
$pdf->Cell($sw[5], 8, 'MOBILE NO', 1, 0, 'C', true);
$pdf->Cell($sw[6], 8, 'EMAIL ID', 1, 1, 'C', true);

// Body
$pdf->SetFont('Arial','',8);
foreach($details as $i => $d){
    $fill = ($i % 2 == 0);
    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
    $pdf->Cell($sw[0], 7, $d['sl_no'], 1, 0, 'C', $fill);
    $pdf->Cell($sw[1], 7, substr($d['stakeholder_type'] ?? '-', 0, 30), 1, 0, 'L', $fill);
    $pdf->Cell($sw[2], 7, substr($d['company_name'] ?? '-', 0, 25), 1, 0, 'L', $fill);
    $pdf->Cell($sw[3], 7, substr($d['contact_person'] ?? '-', 0, 22), 1, 0, 'L', $fill);
    $pdf->Cell($sw[4], 7, substr($d['designation'] ?? '-', 0, 22), 1, 0, 'L', $fill);
    $pdf->Cell($sw[5], 7, $d['mobile_number'] ?? '-', 1, 0, 'L', $fill);
    $pdf->Cell($sw[6], 7, substr($d['email_id'] ?? '-', 0, 28), 1, 1, 'L', $fill);
}

// ===== OUTPUT =====
while (ob_get_level()) ob_end_clean();
$pdf->Output('I', 'PD_Report.pdf');
exit;
?>  