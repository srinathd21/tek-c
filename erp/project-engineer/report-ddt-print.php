<?php
ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
    header("Location: ../login.php");
    exit;
}
// 9345904421
$conn = get_db_connection();
if (!$conn) die("DB failed");
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId <= 0) die("Invalid ID");

// ===== FETCH MAIN =====
$stmt = mysqli_prepare($conn, "SELECT * FROM ddt_main WHERE id=?");
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$ddt = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$ddt) die("No data");

// ===== FETCH DETAILS =====
$stmt = mysqli_prepare($conn, "SELECT * FROM ddt_details WHERE ddt_main_id=? ORDER BY sl_no");
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

mysqli_close($conn);

// ================= PDF =================
class DDTPDF extends FPDF {

    private $ddt;

    function setData($ddt){
        $this->ddt = $ddt;
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
        $this->Cell($titleW,8,'DESIGN DELIVERABLE TRACKER (DDT)',0,0,'C');

        // RIGHT GRID
        $fields = [
            'Project'        => $this->ddt['project_name'] ?? '',
            'Client'         => $this->ddt['client_name'] ?? '',
            'Architect'      => $this->ddt['architects'] ?? '',
            'PMC'            => $this->ddt['pmc'] ?? '',
            'Revision/Date'  => $this->ddt['revisions'] ?? ''
        ];

        $rowH = $headerH / count($fields);

        foreach($fields as $label => $value){

            $this->SetXY($X+$logoW+$titleW, $Y);

            $this->SetFont('Arial','B',8);
            $labelW = 35;
            $this->Cell($labelW, $rowH, $label, 1);

            $this->SetFont('Arial','',8);
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
        $this->SetFillColor(141,180,226);

        $x = $this->GetX();
        $y = $this->GetY();

        $h1 = 6; // top header row
        $h2 = 6; // sub header row

        // ===== ROW 1 (GROUP HEADERS) =====
        // SL NO (rowspan 2)
        $this->Rect($x, $y, $w[0], $h1 + $h2, 'DF');

        // LIST OF DRAWINGS (rowspan 2)
        $this->Rect($x+$w[0], $y, $w[1], $h1 + $h2, 'DF');

        // SITE SCHEDULE (colspan 1 -> START)
        $this->Rect($x+$w[0]+$w[1], $y, $w[2], $h1, 'DF');

        // DRAWING DELIVERABLE (colspan 2)
        $this->Rect($x+$w[0]+$w[1]+$w[2], $y, $w[3]+$w[4], $h1, 'DF');

        // REMARK (rowspan 2)
        $this->Rect($x+$w[0]+$w[1]+$w[2]+$w[3]+$w[4], $y, $w[5], $h1 + $h2, 'DF');


        // ===== ROW 1 TEXT =====
        $this->SetXY($x, $y + 3);
        $this->Cell($w[0], 5, 'SL NO', 0, 0, 'C');

        $this->SetXY($x+$w[0], $y + 3);
        $this->Cell($w[1], 5, 'LIST OF DRAWINGS', 0, 0, 'C');

        $this->SetXY($x+$w[0]+$w[1], $y + 1);
        $this->Cell($w[2], 5, 'SITE SCHEDULE', 0, 0, 'C');

        $this->SetXY($x+$w[0]+$w[1]+$w[2], $y + 1);
        $this->Cell($w[3]+$w[4], 5, 'DRAWING DELIVERABLE (DATES)', 0, 0, 'C');

        $this->SetXY($x+$w[0]+$w[1]+$w[2]+$w[3]+$w[4], $y + 3);
        $this->Cell($w[5], 5, 'REMARK', 0, 0, 'C');


        // ===== ROW 2 (SUB HEADERS) =====
        $y2 = $y + $h1;

        // START
        $this->Rect($x+$w[0]+$w[1], $y2, $w[2], $h2, 'DF');

        // PLANNED
        $this->Rect($x+$w[0]+$w[1]+$w[2], $y2, $w[3], $h2, 'DF');

        // ACTUAL / EXPECTED
        $this->Rect($x+$w[0]+$w[1]+$w[2]+$w[3], $y2, $w[4], $h2, 'DF');


        // ===== ROW 2 TEXT =====
        $this->SetFont('Arial','B',8);

        $this->SetXY($x+$w[0]+$w[1], $y2 + 2);
        $this->Cell($w[2], 4, 'START', 0, 0, 'C');

        $this->SetXY($x+$w[0]+$w[1]+$w[2], $y2 + 2);
        $this->Cell($w[3], 4, 'PLANNED', 0, 0, 'C');

        $this->SetXY($x+$w[0]+$w[1]+$w[2]+$w[3], $y2 + 2);
        $this->Cell($w[4], 4, 'ACTUAL / EXPECTED', 0, 0, 'C');


        // Move cursor after full header
        $this->SetY($y + $h1 + $h2);
    }

    function sectionRow($code, $title, $w){
        $this->SetFont('Arial','B',9);
        $this->SetFillColor(230,230,230);

        $this->Cell($w[0], 8, $code, 1, 0, 'C', true);
        $this->Cell(array_sum($w) - $w[0], 8, $title, 1, 1, 'L', true);

        $this->SetFont('Arial','',9);
    }

    function drawTable($rows){

        // Column widths: SL NO, LIST OF DRAWINGS, SITE SCHEDULE(START), DRAWING DELIVERABLE, ACTUAL/EXPECTED, REMARK
        $w = [15, 100, 35, 40, 35, 52];

        $this->tableHeader($w);

        $currentSection = '';
        $currentSlNo = 1;

        foreach($rows as $r){
            
            // Determine section based on sl_no ranges or explicit section field
            $sl_no = (int)($r['sl_no'] ?? 0);
            $rowSection = '';
            
            if(isset($r['section']) && !empty($r['section'])) {
                $rowSection = $r['section'];
            } else {
                // Fallback: detect by sl_no (1-3 Architectural, 4-6 Structural, 7-9 MEP)
                if($sl_no >= 1 && $sl_no <= 3) $rowSection = 'A';
                elseif($sl_no >= 4 && $sl_no <= 6) $rowSection = 'B';
                elseif($sl_no >= 7 && $sl_no <= 9) $rowSection = 'C';
                else $rowSection = 'A';
            }
            
            // SECTION HEADER
            if($currentSection != $rowSection){
                $currentSection = $rowSection;
                
                // Check if we need a new page for section header
                if($this->GetY() > 170){
                    $this->AddPage();
                    $this->tableHeader($w);
                }

                if($rowSection == 'A')
                    $this->sectionRow('A', 'Architectural & Interior Drawings', $w);
                elseif($rowSection == 'B')
                    $this->sectionRow('B', 'Structural Drawings', $w);
                elseif($rowSection == 'C')
                    $this->sectionRow('C', 'MEP Drawings', $w);
            }

            // PAGE BREAK FOR DATA ROWS
            if($this->GetY() > 180){
                $this->AddPage();
                $this->tableHeader($w);
                
                // Re-print section header on new page if needed
                if($rowSection == 'A')
                    $this->sectionRow('A', 'Architectural & Interior Drawings', $w);
                elseif($rowSection == 'B')
                    $this->sectionRow('B', 'Structural Drawings', $w);
                elseif($rowSection == 'C')
                    $this->sectionRow('C', 'MEP Drawings', $w);
            }

            // ROW DATA
            $this->Cell($w[0], 8, $r['sl_no'] ?? '', 1);
            $this->Cell($w[1], 8, $r['list_of_drawings'] ?? '', 1);
            $this->Cell($w[2], 8, $r['site_schedule_start'] ?? '', 1);
            $this->Cell($w[3], 8, $r['drawing_deliverable_date'] ?? '', 1);
            $this->Cell($w[4], 8, $r['actual_expected'] ?? '', 1);
            $this->Cell($w[5], 8, $r['remarks'] ?? '', 1);
            $this->Ln();
        }
    }
}

// ===== INIT =====
$pdf = new DDTPDF('L','mm','A4');
$pdf->SetMargins(10,10,10);
$pdf->SetAutoPageBreak(true,15);
$pdf->AliasNbPages();
$pdf->setData($ddt);
$pdf->AddPage();

$pdf->drawTable($rows);

// ===== OUTPUT =====
while (ob_get_level()) ob_end_clean();

$filename = "DDT_" . preg_replace('/[^A-Za-z0-9_-]/','_', $ddt['ddt_no'] ?? 'report') . ".pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="'.$filename.'"');

$pdf->Output('I',$filename);
exit;
?>