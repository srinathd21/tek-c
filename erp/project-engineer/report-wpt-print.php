<?php
// report-wpt-print.php - Work Progress Tracker (WPT) PDF Generation

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

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid ID");

// ===== FETCH MAIN =====
$sql = "SELECT * FROM wpt_main WHERE id=?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$wpt = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$wpt) die("Record not found");

// ===== FETCH DETAILS =====
$sql = "SELECT * FROM wpt_details WHERE wpt_main_id=? ORDER BY sl_no";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

mysqli_close($conn);

// ===== PDF CLASS =====
class WPTPDF extends FPDF {

    private $wptData;

    function setData($wptData){
        $this->wptData = $wptData;
    }
    
function getLineCount($text, $width){
    $cw = &$this->CurrentFont['cw'];
    $wmax = ($width - 2) * 1000 / $this->FontSize;

    $text = str_replace("\r",'',$text);
    $nb = strlen($text);

    $sep = -1;
    $i = 0;
    $j = 0;
    $l = 0;
    $nl = 1;

    while($i < $nb){
        $c = $text[$i];

        if($c == "\n"){
            $i++;
            $sep = -1;
            $j = $i;
            $l = 0;
            $nl++;
            continue;
        }

        if($c == ' ') $sep = $i;

        $l += $cw[$c];

        if($l > $wmax){
            if($sep == -1){
                if($i == $j) $i++;
            } else {
                $i = $sep + 1;
            }
            $sep = -1;
            $j = $i;
            $l = 0;
            $nl++;
        } else {
            $i++;
        }
    }

    return $nl;
}
    function Header(){

        // ===== OUTER BORDER =====
        $this->SetDrawColor(0,0,0);
        $this->SetLineWidth(0.15);
        $this->Rect(10, 10, 277, 190);

        $X = 10;
        $Y = 10;

        // Column widths for 4 columns
        $col1W = 30;  // Logo column
        $col2W = 110; // Title column  
        $col3W = 68;  // Left info column
        $col4W = 69;  // Right info column
        // Total: 32+108+68+69 = 277

        $headerH = 28; // Header height

        // ===== COLUMN 1: LOGO =====
        $this->Rect($X, $Y, $col1W, $headerH);
        
        // Find and place logo - properly centered
        $logoPath = null;
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

        foreach ($logoCandidates as $path) { if (file_exists($path)) { $gap = 1; $imgW = $col1W - (2 * $gap); $imgH = $headerH - (2 * $gap); $this->Image( $path, $X + $gap, $Y + $gap, $imgW, $imgH ); break; } }

        
        if ($logoPath) {
            // Calculate logo dimensions to fit within column width and height
            list($imgWidth, $imgHeight) = getimagesize($logoPath);
            $maxWidth = $col1W - 4;
            $maxHeight = $headerH - 4;
            
            $ratio = min($maxWidth / $imgWidth, $maxHeight / $imgHeight);
            $newWidth = $imgWidth * $ratio;
            $newHeight = $imgHeight * $ratio;
            
            $logoX = $X + ($col1W - $newWidth) / 2;
            $logoY = $Y + ($headerH - $newHeight) / 2;
            
            $this->Image($logoPath, $logoX, $logoY, $newWidth, $newHeight);
        }

        // ===== COLUMN 2: TITLE =====
        $this->SetFillColor(220, 220, 220);
        $this->Rect($X+$col1W, $Y, $col2W, $headerH, 'FD');

        $this->SetFont('Arial', 'B', 13);
        $this->SetXY($X+$col1W, $Y+6);
        $this->Cell($col2W, 6, 'WORK PROGRESS TRACKER', 0, 0, 'C');
        $this->SetXY($X+$col1W, $Y+14);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell($col2W, 6, '(WPT)', 0, 0, 'C');

        // ===== COLUMN 3 & 4: INFO FIELDS =====
        $rowH = 7; // 4 rows of 7mm = 28mm total
        
        // Define fields for left column (Column 3)
        $leftFields = [
            'Project'   => $this->wptData['project_name'] ?? '',
            'Client'    => $this->wptData['client_name'] ?? '',
            'Architect' => $this->wptData['architect'] ?? '',
            'Contractor' => $this->wptData['contractor'] ?? ''
        ];
        
        // Define fields for right column (Column 4)
        $rightFields = [
            'PMC'           => $this->wptData['pmc'] ?? '',
            'Scope of Work' => $this->wptData['scope_of_work'] ?? '',
            'Weeks Ends on' => $this->wptData['week_ends_on'] ?? '',
            'WPT No./Dated' => ($this->wptData['wpt_no'] ?? '') . ' / ' . ($this->wptData['created_at'] ? date('d-m-Y', strtotime($this->wptData['created_at'])) : '')
        ];
        
        $labelW = 27; // Width for label in each column
        $valueW = $col3W - $labelW; // Value width in left column
        $valueW4 = $col4W - $labelW; // Value width in right column
        
        // Draw vertical divider between column 3 and 4
        $this->Line($X+$col1W+$col2W, $Y, $X+$col1W+$col2W, $Y+$headerH);
        
        $currentY = $Y;
        
        // Row 1
        $this->SetXY($X+$col1W+$col2W, $currentY);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelW, $rowH, 'Project', 1);
        $this->SetFont('Arial', '', 9);
        $this->Cell($valueW, $rowH, $this->truncateText($leftFields['Project'], $valueW), 1);
        
        $this->SetXY($X+$col1W+$col2W+$col3W, $currentY);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelW, $rowH, 'PMC', 1);
        $this->SetFont('Arial', '', 9);
        $this->Cell($valueW4, $rowH, $this->truncateText($rightFields['PMC'], $valueW4), 1);
        
        // Row 2
        $currentY += $rowH;
        
        $this->SetXY($X+$col1W+$col2W, $currentY);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelW, $rowH, 'Client', 1);
        $this->SetFont('Arial', '', 9);
        $this->Cell($valueW, $rowH, $this->truncateText($leftFields['Client'], $valueW), 1);
        
        $this->SetXY($X+$col1W+$col2W+$col3W, $currentY);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelW, $rowH, 'Scope of Work', 1);
        $this->SetFont('Arial', '', 9);
        $this->Cell($valueW4, $rowH, $this->truncateText($rightFields['Scope of Work'], $valueW4), 1);
        
        // Row 3
        $currentY += $rowH;
        
        $this->SetXY($X+$col1W+$col2W, $currentY);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelW, $rowH, 'Architect', 1);
        $this->SetFont('Arial', '', 9);
        $this->Cell($valueW, $rowH, $this->truncateText($leftFields['Architect'], $valueW), 1);
        
        $this->SetXY($X+$col1W+$col2W+$col3W, $currentY);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelW, $rowH, 'Weeks Ends on', 1);
        $this->SetFont('Arial', '', 9);
        $this->Cell($valueW4, $rowH, $rightFields['Weeks Ends on'], 1);
        
        // Row 4
        $currentY += $rowH;
        
        $this->SetXY($X+$col1W+$col2W, $currentY);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell($labelW, $rowH, 'Contractor', 1);
        $this->SetFont('Arial', '', 9);
        $this->Cell($valueW, $rowH, $this->truncateText($leftFields['Contractor'], $valueW), 1);
        
        $this->SetXY($X+$col1W+$col2W+$col3W, $currentY);
        $this->SetFont('Arial', 'B', 9 );
        $this->Cell($labelW, $rowH, 'WPT No./Dated', 1);
        $this->SetFont('Arial', '', 9);
        $this->Cell($valueW4, $rowH, $this->truncateText($rightFields['WPT No./Dated'], $valueW4), 1);
        
        $this->SetY(10 + $headerH + 8);
    }
    
    function truncateText($text, $maxWidth) {
        if (empty($text)) return '';
        $currentWidth = $this->GetStringWidth($text);
        if ($currentWidth <= $maxWidth) return $text;
        
        while ($this->GetStringWidth($text . '...') > $maxWidth && strlen($text) > 3) {
            $text = substr($text, 0, -1);
        }
        return $text . '...';
    }

    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }
}

// ===== INIT =====
$pdf = new WPTPDF('L', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AliasNbPages();
$pdf->setData($wpt);

$pdf->AddPage();



$w = [10, 55, 18, 18, 18, 25, 18, 18, 25, 18, 17, 37];
// SUM = 277 PERFECT

// ===== HEADER =====
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(141, 180, 226);

$x = $pdf->GetX();
$y = $pdf->GetY();

// ---- LEFT FIXED (ROWSPAN) ----
$pdf->Cell($w[0], 14, 'SL NO', 1, 0, 'C', true);
$pdf->Cell($w[1], 14, 'TASK AS PER SCHEDULE', 1, 0, 'C', true);
$pdf->Cell($w[2], 14, 'DURATION', 1, 0, 'C', true);

// ---- GROUP HEADERS (TOP ROW ONLY) ----
$pdf->Cell($w[3]+$w[4]+$w[5], 7, 'AS PER SCHEDULE', 1, 0, 'C', true);
$pdf->Cell($w[6]+$w[7]+$w[8], 7, 'ACTUAL', 1, 0, 'C', true);
$pdf->Cell($w[9]+$w[10], 7, 'DELAY(DAYS)', 1, 0, 'C', true);

// ---- RIGHT FIXED (ROWSPAN) ----
$pdf->Cell($w[11], 14, 'REMARKS', 1, 0, 'C', true);

// ⚠️ IMPORTANT: go UP, not next line
$pdf->SetXY($x + $w[0] + $w[1] + $w[2], $y + 7);

// ---- SECOND ROW ----
$pdf->Cell($w[3], 7, 'START', 1, 0, 'C', true);
$pdf->Cell($w[4], 7, 'FINISH', 1, 0, 'C', true);
$pdf->Cell($w[5], 7, '% WORK DONE', 1, 0, 'C', true);

$pdf->Cell($w[6], 7, 'START', 1, 0, 'C', true);
$pdf->Cell($w[7], 7, 'FINISH', 1, 0, 'C', true);
$pdf->Cell($w[8], 7, '% WORK DONE', 1, 0, 'C', true);

$pdf->Cell($w[9], 7, 'PREVIOUS', 1, 0, 'C', true);
$pdf->Cell($w[10], 7, 'PRESENT', 1, 0, 'C', true);

// Move cursor to next full row
$pdf->SetY($y + 14);
// ===== BODY =====
$pdf->SetFont('Arial', '', 8);

foreach ($rows as $r){

    // Page break check
    if($pdf->GetY() > 175){
        $pdf->AddPage();
        
        // Redraw 2-row header
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(141, 180, 226);
        
        // Row 1
        $pdf->Cell($w[0], 8, 'SL NO', 1, 0, 'C', true);
        $pdf->Cell($w[1], 8, 'TASK AS PER SCHEDULE', 1, 0, 'C', true);
        $pdf->Cell($w[2], 8, 'DURATION', 1, 0, 'C', true);
        $pdf->Cell($w[3]+$w[4], 8, 'AS PER SCHEDULE', 1, 0, 'C', true);
        $pdf->Cell($w[5], 8, '% WORK DONE', 1, 0, 'C', true);
        $pdf->Cell($w[6]+$w[7], 8, 'ACTUAL', 1, 0, 'C', true);
        $pdf->Cell($w[8], 8, '% WORK DONE', 1, 0, 'C', true);
        $pdf->Cell($w[9]+$w[10], 8, 'DELAY(DAYS)', 1, 0, 'C', true);
        $pdf->Cell($w[11], 8, 'REMARKS', 1, 0, 'C', true);
        $pdf->Ln();
        
        // Row 2
        $pdf->Cell($w[0], 7, '', 1, 0, 'C', true);
        $pdf->Cell($w[1], 7, '', 1, 0, 'C', true);
        $pdf->Cell($w[2], 7, '', 1, 0, 'C', true);
        $pdf->Cell($w[3], 7, 'START', 1, 0, 'C', true);
        $pdf->Cell($w[4], 7, 'FINISH', 1, 0, 'C', true);
        $pdf->Cell($w[5], 7, '', 1, 0, 'C', true);
        $pdf->Cell($w[6], 7, 'START', 1, 0, 'C', true);
        $pdf->Cell($w[7], 7, 'FINISH', 1, 0, 'C', true);
        $pdf->Cell($w[8], 7, '', 1, 0, 'C', true);
        $pdf->Cell($w[9], 7, 'PREVIOUS', 1, 0, 'C', true);
        $pdf->Cell($w[10], 7, 'PRESENT', 1, 0, 'C', true);
        $pdf->Cell($w[11], 7, '', 1, 0, 'C', true);
        $pdf->Ln();
        
        $pdf->SetFont('Arial', '', 9);
    }

    $taskText = $r['task_name'] ?? '';
$remarksText = $r['remarks'] ?? '';

$taskLines = $pdf->getLineCount($taskText, $w[1]);
$remarksLines = $pdf->getLineCount($remarksText, $w[11]);

$lineHeight = 5;
$rowHeight = max($lineHeight, $taskLines * $lineHeight, $remarksLines * $lineHeight);

$startY = $pdf->GetY();

// SL NO (ONLY ONCE)
$pdf->Cell($w[0], $rowHeight, $r['sl_no'], 1, 0, 'C');

// TASK
$x = $pdf->GetX();
$y = $pdf->GetY();
$pdf->MultiCell($w[1], $lineHeight, $taskText, 1);
$pdf->SetXY($x + $w[1], $y);

// DURATION
$pdf->Cell($w[2], $rowHeight, $r['duration'], 1, 0, 'C');

// AS PER SCHEDULE
$pdf->Cell($w[3], $rowHeight, $r['start_date'], 1, 0, 'C');
$pdf->Cell($w[4], $rowHeight, $r['finish_date'], 1, 0, 'C');
$pdf->Cell($w[5], $rowHeight, $r['schedule_work_done'], 1, 0, 'C');

// ACTUAL
$pdf->Cell($w[6], $rowHeight, $r['actual_start'], 1, 0, 'C');
$pdf->Cell($w[7], $rowHeight, $r['actual_finish'], 1, 0, 'C');
$pdf->Cell($w[8], $rowHeight, $r['actual_work_done'], 1, 0, 'C');

// DELAY
$pdf->Cell($w[9], $rowHeight, $r['prev_delay'], 1, 0, 'C');
$pdf->Cell($w[10], $rowHeight, $r['present_delay'], 1, 0, 'C');

// REMARKS
$x = $pdf->GetX();
$y = $pdf->GetY();
$pdf->MultiCell($w[11], $lineHeight, $remarksText, 1);

// move to next row
$pdf->SetY($startY + $rowHeight);
    
    // $pdf->Ln($rowHeight);
}

// ===== OUTPUT =====
while (ob_get_level()) ob_end_clean();

$filename = "WPT_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $wpt['wpt_no']) . ".pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');

$pdf->Output('I', $filename);
exit;