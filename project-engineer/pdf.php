<?php
require('libs/fpdf.php');

class DPR extends FPDF {
    function Header() {
        // Top right doc number
        $this->SetFont('Arial', '', 8);
        $this->SetXY(160, 5);
        $this->Cell(45, 5, 'UKB_TM06_DPR', 0, 1, 'R');

        // Logo area
        $this->SetXY(5, 10);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(255, 255, 255);
        $this->Cell(30, 18, 'u  k  b', 1, 0, 'C', true);

        // Title
        $this->SetXY(35, 10);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(100, 18, 'DAILY PROGRESS REPORT (DPR)', 1, 0, 'C');

        // Project Info
        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(135, 10);
        $this->Cell(25, 4.5, 'Project', 1, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(45, 4.5, 'Mr. Binny Bansal Residence', 1, 1);

        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(135, 14.5);
        $this->Cell(25, 4.5, 'Client', 1, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(45, 4.5, 'Mr. Binny Bansal', 1, 1);

        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(135, 19);
        $this->Cell(25, 4.5, 'PMC', 1, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(45, 4.5, 'M/s. UKB Construction Mgmt Pvt Ltd', 1, 1);

        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(135, 23.5);
        $this->Cell(25, 4.5, 'DPR No//Date', 1, 0);
        $this->SetFont('Arial', '', 8);
        $this->Cell(45, 4.5, '#1835/  02-02-2026', 1, 1);

        $this->Ln(3);
    }

    function Footer() {
        $this->SetY(-10);
        $this->SetFont('Arial', '', 8);
        $this->Cell(70, 5, 'UKB Construction Management pvt ltd', 0, 0, 'L');
        $this->Cell(65, 5, '1/1', 0, 0, 'C');
        $this->Cell(60, 5, 'BBR', 0, 1, 'R');
    }

    function SectionLabel($letter, $label, $height) {
        $x = $this->GetX();
        $y = $this->GetY();
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(220, 220, 220);
        $this->MultiCell(8, $height / 2, $letter, 1, 'C', true);
        $this->SetXY($x + 8, $y);
        $this->MultiCell(22, $height / 2, $label, 1, 'C', true);
        $this->SetXY($x + 30, $y);
    }
}

$pdf = new DPR('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetMargins(5, 5, 5);
$pdf->SetFont('Arial', '', 8);
$pdf->SetAutoPageBreak(true, 10);

// =====================
// SECTION A - Schedule
// =====================
$y = $pdf->GetY();
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(8, 12, 'A.', 1, 0, 'C', true);
$pdf->Cell(22, 12, 'Schedule', 1, 0, 'C', true);

// Date / Duration headers
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(100, 5, 'Date', 1, 0, 'C', true);
$pdf->Cell(65, 5, 'Duration', 1, 1, 'C', true);

$pdf->SetX(35);
$pdf->Cell(33, 5, 'Start', 1, 0, 'L', true);
$pdf->Cell(33, 5, 'End', 1, 0, 'L', true);
$pdf->Cell(34, 5, 'Projected', 1, 0, 'L', true);
$pdf->Cell(21, 5, 'Total', 1, 0, 'L', true);
$pdf->Cell(22, 5, 'Elapsed', 1, 0, 'L', true);
$pdf->Cell(22, 5, 'Balance', 1, 1, 'L', true);

$pdf->SetX(35);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(33, 5, '08-10-2018', 1, 0);
$pdf->Cell(33, 5, '31-05-2025', 1, 0);
$pdf->Cell(34, 5, '28-02-2026', 1, 0);
$pdf->Cell(21, 5, '2700', 1, 0);
$pdf->Cell(22, 5, '2674', 1, 0);
$pdf->Cell(22, 5, '26', 1, 1);

$pdf->Ln(2);

// =====================
// SECTION B - Site
// =====================
$y = $pdf->GetY();
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(8, 10, 'B.', 1, 0, 'C', true);
$pdf->Cell(22, 10, 'Site', 1, 0, 'C', true);

$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(55, 5, 'Weather', 1, 0, 'C', true);
$pdf->Cell(80, 5, 'Site Conditions', 1, 1, 'C', true);

$pdf->SetX(30);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(27, 5, 'Normal', 1, 0);
$pdf->SetFillColor(255, 220, 0);
$pdf->Cell(28, 5, 'Rainy', 1, 0, 'L', true);
$pdf->SetFillColor(255, 255, 255);
$pdf->Cell(40, 5, 'Normal', 1, 0);
$pdf->SetFillColor(255, 220, 0);
$pdf->Cell(40, 5, 'Slushy', 1, 1, 'L', true);

$pdf->Ln(2);

// =====================
// SECTION C - Manpower
// =====================
$y = $pdf->GetY();
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
// Section label cells drawn manually with fixed height
$startY = $pdf->GetY();
$pdf->Cell(8, 5, '', 1, 0, 'C', true); // placeholder top
$pdf->Cell(22, 5, '', 1, 0, 'C', true);

$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(40, 5, 'Agency', 1, 0, 'L', true);
$pdf->Cell(30, 5, 'Category', 1, 0, 'L', true);
$pdf->Cell(20, 5, 'Unit', 1, 0, 'L', true);
$pdf->Cell(15, 5, 'Qty', 1, 0, 'L', true);
$pdf->Cell(70, 5, 'Remark', 1, 1, 'L', true);

$manpower = [
    ['MAS Constructions', 'Mason',       'Nos', '0',  '1. Second Floor - Pool cover - Wooden Flooring work in progress.'],
    ['',                  'M/C',          'Nos', '1',  '2. Internal Staircase - Wall - Primer Applying work progress.'],
    ['',                  'Painter',      'Nos', '7',  '3. Second Floor - Screen wall - Texture Painting work progress.'],
    ['',                  'Carpenter',    'Nos', '0',  '4. Terrace Floor- OHD - Railing painting work progress.'],
    ['',                  'Granite team', 'Nos', '0',  '5. Swimming pool - Decking - Polishing base preparation.'],
    ['SKK Dhiya Shades',  'Painter',      'Nos', '2',  '6. Second Floor - Lobby - Base preparation work progress.'],
    ['Trysqure',          'Technician',   'Nos', '4',  '7. Ground Floor - Counter top hole making for tab fixing work.'],
    ['Paliath interiors', 'Painter',      'Nos', '0',  '8. Housekeeping and curing work.'],
    ['Amber',             'Carpenter',    'Nos', '0',  ''],
];
$pdf->SetFont('Arial', '', 8);
foreach ($manpower as $row) {
    $pdf->SetX(30);
    $pdf->Cell(40, 5, $row[0], 1, 0);
    $pdf->Cell(30, 5, $row[1], 1, 0);
    $pdf->Cell(20, 5, $row[2], 1, 0);
    $pdf->Cell(15, 5, $row[3], 1, 0);
    $pdf->Cell(70, 5, $row[4], 1, 1);
}
// Total row
$pdf->SetX(30);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(70, 5, 'Total Manpower', 1, 0);
$pdf->Cell(20, 5, 'Nos', 1, 0);
$pdf->Cell(15, 5, '14', 1, 0);
$pdf->Cell(70, 5, '', 1, 1);

$pdf->Ln(2);

// =====================
// SECTION D - Machineries
// =====================
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(8, 5, 'D.', 1, 0, 'C', true);
$pdf->Cell(22, 5, 'Machineries', 1, 0, 'C', true);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(70, 5, 'Type of Equipment', 1, 0, 'L', true);
$pdf->Cell(20, 5, 'Unit', 1, 0, 'L', true);
$pdf->Cell(15, 5, 'Qty', 1, 0, 'L', true);
$pdf->Cell(70, 5, 'Remark', 1, 1, 'L', true);

$machines = [
    ['Bosch GDC 141 wood Cutting Machine', 'Nos', '3', '1. All Equipments are in good condition.'],
    ['Drill machine',                       'Nos', '1', ''],
    ['Air Compressor Spray Machine',        'Nos', '1', ''],
];
$pdf->SetFont('Arial', '', 8);
foreach ($machines as $row) {
    $pdf->SetX(30);
    $pdf->Cell(70, 5, $row[0], 1, 0);
    $pdf->Cell(20, 5, $row[1], 1, 0);
    $pdf->Cell(15, 5, $row[2], 1, 0);
    $pdf->Cell(70, 5, $row[3], 1, 1);
}

$pdf->Ln(2);

// =====================
// SECTION E - Material
// =====================
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(8, 5, 'E.', 1, 0, 'C', true);
$pdf->Cell(22, 5, 'Material', 1, 0, 'C', true);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(40, 5, 'Vendor', 1, 0, 'L', true);
$pdf->Cell(30, 5, 'Material', 1, 0, 'L', true);
$pdf->Cell(20, 5, 'Unit', 1, 0, 'L', true);
$pdf->Cell(15, 5, 'Qty', 1, 0, 'L', true);
$pdf->Cell(70, 5, 'Remark', 1, 1, 'L', true);

$materials = [
    ['MAS Constructions', '', '', '', ''],
    ['', '', '', '', ''],
    ['', '', '', '', ''],
    ['', '', '', '', ''],
];
$pdf->SetFont('Arial', '', 8);
foreach ($materials as $row) {
    $pdf->SetX(30);
    $pdf->Cell(40, 5, $row[0], 1, 0);
    $pdf->Cell(30, 5, $row[1], 1, 0);
    $pdf->Cell(20, 5, $row[2], 1, 0);
    $pdf->Cell(15, 5, $row[3], 1, 0);
    $pdf->Cell(70, 5, $row[4], 1, 1);
}

$pdf->Ln(2);

// =====================
// SECTION F - Work Progress
// =====================
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(8, 10, 'F.', 1, 0, 'C', true);
$pdf->Cell(22, 10, 'Work Progress', 1, 0, 'C', true);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(50, 5, 'Task', 1, 0, 'C', true);
$pdf->Cell(75, 5, 'Weekly Schedule', 1, 0, 'C', true);
$pdf->Cell(40, 5, 'Status', 1, 0, 'C', true);
$pdf->Cell(10, 5, '', 1, 1, 'C', true);

$pdf->SetX(30);
$pdf->Cell(50, 5, '', 1, 0, 'C', true);
$pdf->Cell(25, 5, 'Duration', 1, 0, 'C', true);
$pdf->Cell(25, 5, 'Start', 1, 0, 'C', true);
$pdf->Cell(25, 5, 'End', 1, 0, 'C', true);
$pdf->SetFillColor(144, 238, 144);
$pdf->Cell(20, 5, 'In Control', 1, 0, 'C', true);
$pdf->SetFillColor(255, 220, 0);
$pdf->Cell(20, 5, 'Delay', 1, 0, 'C', true);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(30, 5, 'Reasons', 1, 1, 'C', true);

$works = [
    ['Swimming Pool cover - wooden Flooring Polishing work.', '6', '02-02-2026', '07-02-2026'],
    ['Sculpture ceiling wooden supports work.',               '2', '02-02-2026', '03-02-2026'],
    ['Internal Main staircase - Wall Base Preparation work.', '6', '02-02-2026', '07-02-2026'],
    ['External texture Painting work.',                       '4', '02-02-2026', '05-02-2026'],
    ['Bar counter light installation work.',                  '6', '02-02-2026', '07-02-2026'],
    ['Bar counter Painting work.',                            '3', '02-02-2026', '04-02-2026'],
];
$pdf->SetFont('Arial', '', 8);
foreach ($works as $row) {
    $pdf->SetX(30);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell(50, 8, $row[0], 1, 0, 'L');
    $pdf->Cell(25, 8, $row[1], 1, 0, 'C');
    $pdf->Cell(25, 8, $row[2], 1, 0, 'C');
    $pdf->Cell(25, 8, $row[3], 1, 0, 'C');
    $pdf->Cell(20, 8, '', 1, 0);
    $pdf->Cell(20, 8, '', 1, 0);
    $pdf->Cell(30, 8, '', 1, 1);
}

$pdf->Ln(2);

// =====================
// SECTION G - Constraints
// =====================
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(8, 5, 'G.', 1, 0, 'C', true);
$pdf->Cell(22, 5, 'Constraints', 1, 0, 'C', true);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(50, 5, 'Issues', 1, 0, 'L', true);
$pdf->Cell(75, 5, 'Status', 1, 0, 'C', true);
$pdf->Cell(50, 5, 'Remark', 1, 1, 'C', true);

$pdf->SetX(30);
$pdf->Cell(50, 5, '', 1, 0);
$pdf->SetFillColor(255, 220, 0);
$pdf->Cell(25, 5, 'Open', 1, 0, 'C', true);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(25, 5, 'Closed', 1, 0, 'C', true);
$pdf->SetFillColor(255, 255, 255);
$pdf->Cell(25, 5, 'Date', 1, 0, 'C');
$pdf->Cell(50, 5, '', 1, 1);

$pdf->SetX(30);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(50, 5, 'Nil', 1, 0);
$pdf->Cell(25, 5, '', 1, 0);
$pdf->Cell(25, 5, '', 1, 0);
$pdf->Cell(25, 5, '', 1, 0);
$pdf->Cell(50, 5, '', 1, 1);

$pdf->Ln(2);

// =====================
// SECTION H - Report by
// =====================
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(8, 5, 'H.', 1, 0, 'C', true);
$pdf->Cell(22, 5, 'Report by', 1, 0, 'C', true);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(85, 5, 'Report Distribute to', 1, 0, 'L', true);
$pdf->Cell(90, 5, 'Prepared By', 1, 1, 'L', true);

$pdf->SetX(30);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(85, 5, 'Mr. & Mrs. Bansal', 1, 0);
$pdf->Cell(90, 5, 'Musamil.A', 1, 1);

$pdf->SetX(30);
$pdf->Cell(85, 5, 'Client', 1, 0);
$pdf->Cell(90, 5, 'Sr.Project Engineer', 1, 1);

$pdf->Output('DPR_Report.pdf', 'I');
?>