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

    $viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
    if ($viewId <= 0) die("Invalid ID");

    // ===== FETCH MAIN =====
    $sql = "SELECT * FROM pms_main WHERE id=?";
    $stmt = mysqli_prepare($conn,$sql);
    mysqli_stmt_bind_param($stmt,"i",$viewId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pms = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$pms) die("PMS record not found");

    // ===== FETCH DETAILS =====
    $sql = "SELECT * FROM pms_details WHERE pms_main_id=? ORDER BY sl_no";
    $stmt = mysqli_prepare($conn,$sql);
    mysqli_stmt_bind_param($stmt,"i",$viewId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    mysqli_close($conn);

    // ===== PDF CLASS =====
    class PMSPDF extends FPDF {
        private $pms;

        function setData($pms){
            $this->pms = $pms;
        }

        // function Header(){
        //     // ===== OUTER BORDER (THIN & CLEAN) =====
        //     $this->SetDrawColor(0,0,0);
        //     $this->SetLineWidth(0.15);
        //     $this->Rect(10, 10, 277, 190);

        //     $X = 10;
        //     $Y = 10;

        //     $logoW = 30;
        //     $rightW = 110;
        //     $titleW = 277 - ($logoW + $rightW);
        //     $headerH = 30;

        //     // ===== LOGO =====
        //     $this->Rect($X,$Y,$logoW,$headerH);
        //     $logoCandidates = [
        //         __DIR__ . '/assets/logo.png',
        //         __DIR__ . '/assets/logo.jpg',
        //         __DIR__ . '/assets/ukb.png',
        //         __DIR__ . '/assets/ukb.jpg',
        //         __DIR__ . '/public/logo.png',
        //         __DIR__ . '/public/logo.jpg',
        //         __DIR__ . '/images/logo.png',
        //         __DIR__ . '/images/logo.jpg',
        //         __DIR__ . '/logo.png',
        //         __DIR__ . '/logo.jpg',
        //         __DIR__ . '/../assets/logo.png',
        //         __DIR__ . '/../assets/ukb.png',
        //     ];

        //     foreach ($logoCandidates as $path) {
        //         if (file_exists($path)) {
        //             $this->Image($path, $X+3, $Y+3, $logoW-6);
        //             break;
        //         }
        //     }

        //     // ===== TITLE =====
        //     $this->SetFillColor(141, 180, 226);
        //     $this->Rect($X+$logoW,$Y,$titleW,$headerH,'F');

        //     $this->SetFont('Arial','B',16);
        //     $this->SetTextColor(255,255,255);
        //     $this->SetXY($X+$logoW,$Y+9);
        //     $this->Cell($titleW,8,'PROJECT MASTER SCHEDULE',0,0,'C');
        //     $this->SetTextColor(0,0,0);

        //     // ===== RIGHT SIDE DATA =====
        //     $fields = [
        //         'Project'      => $this->pms['project_name'] ?? '',
        //         'Client'       => $this->pms['client_name'] ?? '',
        //         'Architect'    => $this->pms['architect'] ?? '',
        //         'PMC'          => $this->pms['pmc'] ?? '',
        //         'Revision'     => $this->pms['revision'] ?? '',
        //         'PMS Date'     => $this->pms['pms_date'] ?? '',
        //     ];

        //     $rowH = $headerH / count($fields);

        //     foreach($fields as $label => $value){
        //         $this->SetXY($X+$logoW+$titleW, $Y);
        //         $this->SetFillColor(240, 240, 240);
                
        //         $this->SetFont('Arial','B',8);
        //         $labelW = 40;
        //         $this->Cell($labelW, $rowH, $label, 1, 0, 'L', true);

        //         $this->SetFont('Arial','',9);
        //         $this->Cell($rightW-$labelW, $rowH, $value, 1, 0, 'L');
                
        //         $Y += $rowH;
        //     }

        //     $this->SetY(10 + $headerH + 5);
            
        //     // Add document number and prepared by
        //     $this->SetFont('Arial','I',9);
        //     $this->SetTextColor(100,100,100);
        //     $this->Cell(0,5,'Document No: ' . ($this->pms['pms_no'] ?? ''), 0, 1, 'R');
        //     $this->SetX(10);
        //     $this->Cell(0,5,'Prepared By: ' . ($this->pms['prepared_by_name'] ?? ''), 0, 1, 'L');
        //     $this->SetTextColor(0,0,0);
            
        //     $this->Ln(5);
        // }
function NbLines($w, $txt){
    $cw = &$this->CurrentFont['cw'];
    
    if($w==0)
        $w = $this->w - $this->rMargin - $this->x;
    $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;

    $s = str_replace("\r",'',$txt);
    $nb = strlen($s);
    if($nb>0 && $s[$nb-1]=="\n")
        $nb--;

    $sep = -1;
    $i = 0;
    $j = 0;
    $l = 0;
    $nl = 1;

    while($i < $nb){
        $c = $s[$i];
        if($c=="\n"){
            $i++;
            $sep = -1;
            $j = $i;
            $l = 0;
            $nl++;
            continue;
        }
        if($c==' ')
            $sep = $i;

        $l += $cw[$c];

        if($l > $wmax){
            if($sep == -1){
                if($i == $j)
                    $i++;
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

    // ===== OUTER PAGE BORDER =====
    $this->SetDrawColor(0,0,0);
    $this->SetLineWidth(0.2);
    $this->Rect(10, 10, 277, 190);

    $X = 10;
    $Y = 10;

    $totalW = 277;
    $headerH = 28;

    $logoW  = 30;
    $rightW = 100;
    $titleW = $totalW - ($logoW + $rightW);

    // ===== LOGO BOX =====
    $this->Rect($X,$Y,$logoW,$headerH);

    // 🔥 Proper centered logo (not random positioning)
    $logoCandidates = [
        __DIR__ . '/assets/logo.png',
        __DIR__ . '/assets/logo.jpg',
        __DIR__ . '/assets/ukb.png',
        __DIR__ . '/assets/ukb.jpg',
        __DIR__ . '/public/logo.png',
        __DIR__ . '/images/logo.png',
        __DIR__ . '/logo.png',
    ];

    foreach ($logoCandidates as $path) { if (file_exists($path)) { $gap = 1; $imgW = $logoW - (2 * $gap); $imgH = $headerH - (2 * $gap); $this->Image( $path, $X + $gap, $Y + $gap, $imgW, $imgH ); break; } }


    // ===== TITLE BACKGROUND =====
    $this->SetFillColor(200,200,200); // closer to your image tone
    $this->Rect($X+$logoW, $Y, $titleW, $headerH, 'F');

    // Border
    $this->Rect($X+$logoW, $Y, $titleW, $headerH);

    // ===== TITLE TEXT =====
    $this->SetFont('Arial','B',14);
    $this->SetXY($X+$logoW, $Y + 9);
    $this->Cell($titleW, 8, 'PROJECT MASTER SCHEDULE (PMS)', 0, 0, 'C');

    // ===== RIGHT TABLE =====
    $fields = [
        'Project'   => $this->pms['project_name'] ?? '',
        'Client'    => $this->pms['client_name'] ?? '',
        'Architect' => $this->pms['architect'] ?? '',
        'PMC'       => $this->pms['pmc'] ?? '',
        'Dated / Version ' => $this->pms['pms_date'] ?? '' . ' / ' . ($this->pms['revision'] ?? ''),
    ];

    $rowH = $headerH / count($fields);
    $labelW = 40;
    $valueW = $rightW - $labelW;

    $currentY = $Y;

    foreach($fields as $label => $value){

        $this->SetXY($X + $logoW + $titleW, $currentY);

        // label background
        $this->SetFillColor(235,235,235);

        $this->SetFont('Arial','B',8);
        $this->Cell($labelW, $rowH, $label, 1, 0, 'L', true);

        $this->SetFont('Arial','',8);
        $this->Cell($valueW, $rowH, $value, 1, 0, 'L');

        $currentY += $rowH;
    }

    // ===== GAP AFTER HEADER =====
    $this->SetY($Y + $headerH + 4);
}

        function Footer(){
            $this->SetY(-12);
            $this->SetFont('Arial','I',8);
            $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
        }
    }

    // ===== INIT =====
    $pdf = new PMSPDF('L','mm','A4');
    $pdf->SetMargins(10,10,10);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AliasNbPages();
    $pdf->setData($pms);
    $pdf->AddPage();

    // ===== TABLE WIDTHS =====
    $w = [15, 95, 30, 40, 40, 57]; // total = 277

    // ===== TABLE HEADER =====
    $pdf->SetFont('Arial','B',10);
$pdf->SetFillColor(141,180,226);
$pdf->SetTextColor(0,0,0);
$pdf->SetLineWidth(0.2);

$h1 = 6;
$h2 = 6;

// ===== FIRST ROW =====

// SL NO
$pdf->Cell($w[0], $h1 + $h2, 'SL NO', 1, 0, 'C', true);

// TASK
$pdf->Cell($w[1], $h1 + $h2, 'TASK / ACTIVITY / MILESTONE', 1, 0, 'C', true);

$x = $pdf->GetX();
$y = $pdf->GetY();
$cellH = $h1 + $h2;

// Outer box
$pdf->Cell($w[2], $cellH, '', 1, 0, 'C', true);

// 🔥 Draw middle divider line
$pdf->Line($x, $y + $h1, $x + $w[2], $y + $h1);

// Top text
$pdf->SetXY($x, $y + 1.5);
$pdf->Cell($w[2], 5, 'DURATION', 0, 2, 'C');

// Bottom text
$pdf->SetX($x);
$pdf->Cell($w[2], 5, '(DAYS)', 0, 0, 'C');

// DATE (colspan 2)
$pdf->SetXY($x + $w[2], $y);
$pdf->Cell($w[3] + $w[4], $h1, 'DATE', 1, 0, 'C', true);

// REMARK
$pdf->Cell($w[5], $h1 + $h2, 'REMARK', 1, 0, 'C', true);

$pdf->Ln($h1);

// ===== SECOND ROW =====

// move EXACTLY under DATE (this is where you were messing up)
$pdf->SetX(10 + $w[0] + $w[1] + $w[2]);

$pdf->Cell($w[3], $h2, 'START', 1, 0, 'C', true);
$pdf->Cell($w[4], $h2, 'END', 1, 0, 'C', true);

$pdf->Ln();

// Reset text color
$pdf->SetTextColor(0,0,0);

    // ===== BODY =====
    $pdf->SetFont('Arial','',10);

foreach ($rows as $r){

    // ===== CALCULATE DYNAMIC HEIGHT =====
    $lineH = 7;

    $taskLines = $pdf->NbLines($w[1], $r['task_activity'] ?? '');
    $remarkLines = $pdf->NbLines($w[5], $r['remark'] ?? '');

    $maxLines = max($taskLines, $remarkLines, 1);
    $rowH = $maxLines * $lineH;

    // ===== PAGE BREAK =====
    if($pdf->GetY() + $rowH > 190){
        $pdf->AddPage();

        // redraw header (IMPORTANT)
        $this->TableHeader($pdf, $w);
    }

    $xStart = $pdf->GetX();
    $yStart = $pdf->GetY();

    // SL NO
    $pdf->MultiCell($w[0], $rowH, $r['sl_no'] ?? '', 1, 'C');
    $pdf->SetXY($xStart + $w[0], $yStart);

    // TASK
    $pdf->MultiCell($w[1], $lineH, $r['task_activity'] ?? '', 1, 'L');
    $pdf->SetXY($xStart + $w[0] + $w[1], $yStart);

    // DURATION
    $pdf->MultiCell($w[2], $rowH, $r['duration_days'] ?? '0', 1, 'C');
    $pdf->SetXY($xStart + $w[0] + $w[1] + $w[2], $yStart);

    // START DATE
    $pdf->MultiCell($w[3], $rowH, $r['date_start'] ?? '', 1, 'C');
    $pdf->SetXY($xStart + $w[0] + $w[1] + $w[2] + $w[3], $yStart);

    // END DATE
    $pdf->MultiCell($w[4], $rowH, $r['date_end'] ?? '', 1, 'C');
    $pdf->SetXY($xStart + $w[0] + $w[1] + $w[2] + $w[3] + $w[4], $yStart);

    // REMARK
    $pdf->MultiCell($w[5], $lineH, $r['remark'] ?? '', 1, 'L');

}

   
    // ===== OUTPUT =====
    while (ob_get_level()) ob_end_clean();

    $filename = "PMS_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $pms['pms_no']) . ".pdf";

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');

    $pdf->Output('I', $filename);
    exit;
    ?>