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

// ===== FETCH =====
$stmt = mysqli_prepare($conn, "SELECT * FROM vft_main WHERE id=?");
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$vft = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));


// print_r($vft); exit;
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT * FROM vft_details WHERE vft_main_id=? ORDER BY sl_no");
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

mysqli_close($conn);

// ===== PDF CLASS =====
class VFTPDF extends FPDF {
    private $vft;

    

    function setData($vft){
        $this->vft = $vft;
    }

    function Header(){

        $margin = 5;
        $pageW  = $this->GetPageWidth();
        $pageH  = $this->GetPageHeight();

        // BORDER
        $this->Rect($margin, $margin, $pageW - 2*$margin, $pageH - 2*$margin);

        $X = $margin;
        $Y = $margin;

        $totalW  = $pageW - (2 * $margin);
        $headerH = 30;

        $logoW  = 30;
        $rightW = 90;
        $titleW = $totalW - ($logoW + $rightW);

        // LOGO
        $this->Rect($X, $Y, $logoW, $headerH);

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

        foreach ($logoCandidates as $path) { if (file_exists($path)) { $gap = 1; $imgW = $logoW - (2 * $gap); $imgH = $headerH - (2 * $gap); $this->Image( $path, $X + $gap, $Y + $gap, $imgW, $imgH ); break; } }

        // TITLE
        $this->SetFillColor(220,220,220);
        $this->Rect($X+$logoW, $Y, $titleW, $headerH, 'FD');

        $this->SetFont('Arial','B',14);
        $this->SetXY($X+$logoW, $Y+10);
        $this->Cell($titleW,8,'VENDOR FINALIZATION TRACKER (VFT)',0,0,'C');

        // RIGHT INFO GRID
        $rx = $X + $logoW + $titleW;
        $ry = $Y;

        $fields = [
            'Project' => $this->vft['project_name'] ?? '',
            'Client'  => $this->vft['client_name'] ?? '',
            'PMC'     => $this->vft['pmc'] ?? '',
            'Date'    => $this->vft['vft_date'] ?? ''
        ];

        $rowH = $headerH / count($fields);

        foreach($fields as $k=>$v){
            $this->SetXY($rx,$ry);
            $this->SetFont('Arial','B',7);
            $this->Cell(30,$rowH,$k,1);

            $this->SetFont('Arial','',7);
            $this->Cell($rightW-30,$rowH,substr($v,0,30),1);

            $ry += $rowH;
        }

        $this->SetY($Y + $headerH + 3);
    }

    function HeaderCell($w, $h, $txt, $fillColor = [149,179,215]) {

    $x = $this->GetX();
    $y = $this->GetY();

    // SET BG COLOR
    $this->SetFillColor(...$fillColor);

    // DRAW FILLED RECT
    $this->Rect($x, $y, $w, $h, 'DF'); // D=border, F=fill

    // TEXT HANDLING
    $lines = explode("\n", $txt);
    $lineCount = count($lines);

    $lineHeight = $this->FontSize * 1.8;
    $totalTextHeight = $lineCount * $lineHeight;

    $startY = $y + ($h - $totalTextHeight) / 2;

    foreach ($lines as $i => $line) {
        $this->SetXY($x, $startY + ($i * $lineHeight));
        $this->Cell($w, $lineHeight, $line, 0, 0, 'C');
    }

    $this->SetXY($x + $w, $y);
}

    function TableHeader($col){

    $this->SetFont('Arial','B',8);
    $this->SetLineWidth(0.2);

    $done = [198,239,206];
    $wip  = [255,235,156];
    $nip  = [255,199,206];
    $head = [149,179,215];

  // ===================== ROW 1 (ALIGNED WITH TABLE WIDTH) =====================

// Done (SL + PACKAGE + STATUS)
// ===================== ROW 1 (EXACT COLUMN ALIGNMENT) =====================

// Done → SL NO
$this->SetFillColor(146,208,80);
$this->Cell($col[0], 6, 'Done', 1, 0, 'C', true);

// Approved → PACKAGE
$this->SetFillColor(255,255,255);
$this->Cell($col[1], 6, 'Approved', 1, 0, 'L', true);

// WIP → STATUS
$this->SetFillColor(255,255,255);
$this->Cell($col[2], 6, '', 1, 0, 'C', true);

$wipWidth = $col[3] + $col[4] + $col[5] + $col[6];

// Split into label + description
$this->SetFillColor(255,255,0);
$this->Cell($wipWidth * 0.25, 6, 'WIP', 1, 0, 'C', true);

$this->SetFillColor(255,255,255);
$this->Cell($wipWidth * 0.75, 6, 'Work In Progress', 1, 0, 'L', true);

// NIP → DESIGN
$this->SetFillColor(255,0,0);
$this->SetTextColor(255,255,255);
$this->Cell($col[7], 6, 'NIP', 1, 0, 'C', true);

// Not In Progress → BUDGET → FINALIZATION
$this->SetFillColor(255,255,255);
$this->SetTextColor(0,0,0);
$this->Cell(
    $col[8] + $col[9] + $col[10] + $col[11],
    6,
    'Not In Progress',
    1, 0, 'L', true
);

// ✓ → PO/WO
$this->Cell($col[12], 6, 'Correct', 1, 0, 'C', true);

// Approved → REMARKS
$this->Cell($col[13] + $col[14], 6, 'Approved', 1, 0, 'L', true);

$this->Ln();
    // ===================== ROW 2 + ROW 3 (MERGED PROPERLY) =====================
    $this->SetFillColor(...$head);

    $h1 = 8;
    $h2 = 8;

    $xStart = $this->GetX();
    $yStart = $this->GetY();

    // LEFT FIXED (ROWSPAN)
    $this->Cell($col[0], $h1+$h2, 'SL NO', 1, 0, 'C', true);
    $this->Cell($col[1], $h1+$h2, 'PACKAGE', 1, 0, 'C', true);
    $this->Cell($col[2], $h1+$h2, 'STATUS', 1, 0, 'C', true);

    // ---- PLANNED ----
    $x = $this->GetX();
    $y = $this->GetY();

    $this->Cell($col[3]+$col[4], $h1, 'PLANNED SCHEDULE', 1, 0, 'C', true);

    // draw subcells WITHOUT moving to new row globally
    $this->SetXY($x, $y + $h1);
    $this->Cell($col[3], $h2, 'START', 1, 0, 'C', true);
    $this->Cell($col[4], $h2, 'FINISH', 1, 0, 'C', true);

    $this->SetXY($x + $col[3] + $col[4], $y);

    // ---- ACTUAL ----
    $x = $this->GetX();

    $this->Cell($col[5]+$col[6], $h1, 'ACTUAL / EXPECTED', 1, 0, 'C', true);

    $this->SetXY($x, $y + $h1);
    $this->Cell($col[5], $h2, 'START', 1, 0, 'C', true);
    $this->Cell($col[6], $h2, 'FINISH', 1, 0, 'C', true);

    $this->SetXY($x + $col[5] + $col[6], $y);

    // ---- RIGHT SIDE (ROWSPAN) ----
   $this->HeaderCell($col[7], $h1+$h2, "DESIGN\nAPPROVAL", $head);
$this->HeaderCell($col[8], $h1+$h2, "BUDGET\nAPPROVAL", $head);
$this->HeaderCell($col[9], $h1+$h2, "VENDOR\nIDENTIFICATION", $head);
$this->HeaderCell($col[10], $h1+$h2, "RFQ /\nTENDER", $head);
$this->HeaderCell($col[11], $h1+$h2, "FINALIZATION", $head);
$this->HeaderCell($col[12], $h1+$h2, "APPROVED", $head);
$this->HeaderCell($col[13], $h1+$h2, "PO/WO", $head);
$this->HeaderCell($col[14], $h1+$h2, "REMARKS", $head);

    // FINAL POSITION
    $this->SetY($yStart + $h1 + $h2);
}

    function NbLines($w,$txt){
        $cw=&$this->CurrentFont['cw'];
        $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;

        $s=str_replace("\r",'',$txt);
        $nb=strlen($s);

        $sep=-1;$i=0;$j=0;$l=0;$nl=1;

        while($i<$nb){
            $c=$s[$i];
            if($c=="\n"){ $i++; $sep=-1;$j=$i;$l=0;$nl++; continue;}
            if($c==' ')$sep=$i;
            $l+=$cw[$c];

            if($l>$wmax){
                if($sep==-1){ if($i==$j)$i++; }
                else $i=$sep+1;
                $sep=-1;$j=$i;$l=0;$nl++;
            } else $i++;
        }
        return $nl;
    }
}

$pdf = new VFTPDF('L','mm','A4');
$pdf->SetMargins(5,5,5);
$pdf->AliasNbPages();
$pdf->setData($vft);

// ✅ correct total width (match margins)
$totalWidth = $pdf->GetPageWidth() - 10; // 5 + 5

// ✅ balanced column ratios (FIXED)
$base = [
    10,   // SL
    38,  // PACKAGE
    16,  // STATUS

    16,16,   // PLANNED
    16,16,   // ACTUAL

    18,  // DESIGN
    18,  // BUDGET
    24,  // VENDOR IDENTIFICATION
    18,  // RFQ
    20,  // FINALIZATION

    20,  // APPROVED
    16,  // PO/WO

    40   // REMARKS (important)
];

$sum  = array_sum($base);

$col = array_map(function($w) use ($totalWidth, $sum) {
    return ($w / $sum) * $totalWidth;
}, $base);

$pdf->AddPage();

// ✅ align with margin (not 10)
$pdf->SetX(5);
$pdf->TableHeader($col);


// ===== BODY =====
$lineH = 6;

foreach ($rows as $r){

    $rowH = max(
        $pdf->NbLines($col[1],$r['package']),
        $pdf->NbLines($col[14],$r['remarks']),
        1
    ) * $lineH;

    if ($pdf->GetY() + $rowH > ($pdf->GetPageHeight() - 15)){
        $pdf->AddPage();
        $pdf->TableHeader($col);
        $pdf->SetFont('Arial','',8);    
    }

    $x=$pdf->GetX();
    $y=$pdf->GetY();

    $pdf->Cell($col[0],$rowH,$r['sl_no'],1,0,'C');

    $pdf->SetXY($x+$col[0],$y);
    $pdf->MultiCell($col[1],$lineH,$r['package'],1);

    $pdf->SetXY($x+$col[0]+$col[1],$y);
    $pdf->Cell($col[2],$rowH,$r['status'],1,0,'C');

    $fields=[
        'planned_schedule_start','planned_schedule_finish',
        'actual_expected_start','actual_expected_finish',
        'design_approval','budget_approval','vendor_identification',
        'rfq_tender','finalization','approved','po_wo'
    ];

    $i=3;
    foreach($fields as $f){
        $pdf->SetXY($x+array_sum(array_slice($col,0,$i)),$y);
        $pdf->Cell($col[$i],$rowH,$r[$f] ?? '',1,0,'C');
        $i++;
    }

    $pdf->SetXY($x+array_sum(array_slice($col,0,14)),$y);
    $pdf->MultiCell($col[14],$lineH,$r['remarks'],1);
    if ($pdf->GetY() + $rowH > ($pdf->GetPageHeight() - 15)) {
        $pdf->AddPage();
        $pdf->SetX(5);
        $pdf->TableHeader($col);
    }
    $pdf->SetY($y+$rowH);
}

// OUTPUT
while (ob_get_level()) ob_end_clean();

$pdf->Output('I','VFT.pdf');
exit;
?>