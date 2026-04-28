<?php
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

// ===== FETCH MAIN =====
$stmt = mysqli_prepare($conn, "SELECT * FROM dds_main WHERE id=?");
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$dds = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$dds) die("No data");

// ===== FETCH DETAILS =====
$stmt = mysqli_prepare($conn, "SELECT * FROM dds_details WHERE dds_main_id=? ORDER BY section, sl_no");
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

mysqli_close($conn);

// ================= PDF =================
class DDSPDF extends FPDF {

    private $dds;

    function setData($dds){
        $this->dds = $dds;
    }

    function Header(){

        $this->SetLineWidth(0.15);
        $this->Rect(10, 10, 277, 190);

        $X = 10;
        $Y = 10;

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

$this->Image(
    $path,
    $X + $gap,
    $Y + $gap,
    $imgW,
    $imgH
);
                break;
            }
        }

        // TITLE
        $this->SetFillColor(200,200,200);
        $this->Rect($X+$logoW, $Y, $titleW, $headerH, 'FD');

        $this->SetFont('Arial','B',14);
        $this->SetXY($X+$logoW, $Y+9);
        $this->Cell($titleW,8,'DESIGN DELIVERABLE SCHEDULE (DDS)',0,0,'C');

        // RIGHT GRID
        $fields = [
            'Project'              => $this->dds['project_name'] ?? '',
            'Client'               => $this->dds['client_name'] ?? '',
            'Architect'            => $this->dds['architect'] ?? '',
            'Structural Consultant'=> $this->dds['structural_consultant'] ?? '',
            'PMC'                  => $this->dds['pmc'] ?? '',
            'Date / Version'       => $this->dds['date_version'] ?? '' . '' . $this->dds['version'] ?? ''
        ];

        $rowH = $headerH / 6;

        foreach($fields as $label => $value){

            $this->SetXY($X+$logoW+$titleW, $Y);

            $this->SetFont('Arial','B',7);
            $labelW = 38;
            $this->Cell($labelW, $rowH, $label, 1);

            $this->SetFont('Arial','',7);
            $this->Cell($rightW-$labelW, $rowH, $value, 1);

            $Y += $rowH;
        }

        $this->SetY(10 + $headerH + 5);
    }

    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }

    function tableHeader($w){

    $this->SetFont('Arial','B',9);
   $this->SetFillColor(141, 180, 226); // Excel-style light blue
$this->SetTextColor(0,0,0);         // black text (matches that shade better)

    $x = $this->GetX();
    $y = $this->GetY();

    $h1 = 6; // top row height
    $h2 = 6; // second row height

    // ===== FIRST ROW =====
    // SL NO (rowspan 2)
    $this->Cell($w[0], $h1 + $h2, 'SL NO', 1, 0, 'C', true);

    // LIST OF DRAWINGS (rowspan 2)
    $this->Cell($w[1], $h1 + $h2, 'LIST OF DRAWINGS', 1, 0, 'C', true);

    // DURATION (rowspan 2)
    // Save current position
$xCurrent = $this->GetX();
$yCurrent = $this->GetY();

// Top cell: DURATION
$this->Cell($w[2], $h1, 'DURATION', 1, 2, 'C', true);

// Bottom cell: (DAYS)
$this->SetX($xCurrent);
$this->Cell($w[2], $h2, '(DAYS)', 1, 0, 'C', true);

// Move cursor back to correct row height alignment
$this->SetXY($xCurrent + $w[2], $yCurrent);

    // DATE (colspan 2)
    $this->Cell($w[3] + $w[4], $h1, 'DATE', 1, 0, 'C', true);

    // REMARK (rowspan 2)
    $this->Cell($w[5], $h1 + $h2, 'REMARK', 1, 0, 'C', true);

    $this->Ln($h1);

    // ===== SECOND ROW =====
    // Move cursor to start of DATE columns
    $this->SetX($x + $w[0] + $w[1] + $w[2]);

    $this->Cell($w[3], $h2, 'START', 1, 0, 'C', true);
    $this->Cell($w[4], $h2, 'END', 1, 0, 'C', true);

    $this->Ln();

    // Reset colors
    $this->SetTextColor(0,0,0);
    $this->SetFillColor(255,255,255);
}

    function sectionRow($code, $title, $w){
        $this->SetFont('Arial','B',9);
        $this->SetFillColor(230, 230, 230);
        $this->SetTextColor(0, 0, 0);

        $this->Cell($w[0], 8, $code, 1, 0, 'C', true);
        $this->Cell(array_sum($w) - $w[0], 8, $title, 1, 1, 'L', true);
    }

    function drawTable($rows){

        // Column widths: SL NO, LIST OF DRAWINGS, DURATION, START DATE, END DATE, REMARK
        $w = [18, 110, 30, 30, 30, 59];

        $this->tableHeader($w);

        $currentSection = '';
        
        // Section mapping
        $sectionTitles = [
            'A' => 'ARCHITECTURAL DRAWINGS',
            'B' => 'STRUCTURAL DRAWINGS',
            'C' => 'ELECTRICAL DRAWINGS',
            'D' => 'PLUMBING DRAWINGS',
            'E' => 'HVAC DRAWINGS'
        ];

        foreach($rows as $r){
            
            $rowSection = isset($r['section']) && !empty($r['section']) ? $r['section'] : 'A';
            $sectionTitle = $sectionTitles[$rowSection] ?? 'DRAWINGS';
            
            // SECTION HEADER
            if($currentSection != $rowSection){
                $currentSection = $rowSection;
                
                // Check if we need a new page for section header
                if($this->GetY() > 170){
                    $this->AddPage();
                    $this->tableHeader($w);
                }

                $this->sectionRow($rowSection, $sectionTitle, $w);
            }

            // PAGE BREAK FOR DATA ROWS
            if($this->GetY() > 180){
                $this->AddPage();
                $this->tableHeader($w);
                
                // Re-print section header on new page if needed
                $this->sectionRow($rowSection, $sectionTitle, $w);
            }

            // Format dates for display
            $startDate = !empty($r['start_date']) ? date('d-m-Y', strtotime($r['start_date'])) : '';
            $endDate = !empty($r['end_date']) ? date('d-m-Y', strtotime($r['end_date'])) : '';
            $duration = $r['duration_days'] ?? '';
            $this->SetFont('Arial','',9);
            // ROW DATA
            $this->Cell($w[0], 8, $r['sl_no'] ?? '', 1);
            $this->Cell($w[1], 8, $r['list_of_drawings'] ?? '', 1);
            $this->Cell($w[2], 8, $duration, 1, 0, 'C');
            $this->Cell($w[3], 8, $startDate, 1, 0, 'C');
            $this->Cell($w[4], 8, $endDate, 1, 0, 'C');
            $this->Cell($w[5], 8, $r['remarks'] ?? '', 1);
            $this->Ln();
        }
    }
}

// ===== INIT =====
$pdf = new DDSPDF('L','mm','A4');
$pdf->SetMargins(10,10,10);
$pdf->SetAutoPageBreak(true,15);
$pdf->AliasNbPages();
$pdf->setData($dds);
$pdf->AddPage();

$pdf->drawTable($rows);

// ===== OUTPUT =====
while (ob_get_level()) ob_end_clean();

$filename = "DDS_" . preg_replace('/[^A-Za-z0-9_-]/','_', $dds['dds_no'] ?? 'report') . ".pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.$filename.'"');

$pdf->Output('I',$filename);
exit;
?>