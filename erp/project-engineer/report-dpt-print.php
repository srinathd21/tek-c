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

// ===== FETCH DATA =====
$stmt = $conn->prepare("SELECT * FROM dpt_main WHERE id=?");
$stmt->bind_param("i", $viewId);
$stmt->execute();
$dpt = $stmt->get_result()->fetch_assoc();


$stmt->close();

$stmt = $conn->prepare("SELECT * FROM dpt_details WHERE dpt_main_id=? ORDER BY sl_no");
$stmt->bind_param("i", $viewId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

class DPTPDF extends FPDF {

    private $dpt;

    function setData($dpt){
        $this->dpt = $dpt;
    }
    function VCell($w, $h, $txt, $border=1, $align='C'){
        $lineH = 4;

        $nb = $this->NbLines($w, $txt);
        $textH = $nb * $lineH;

        $y = $this->GetY();
        $x = $this->GetX();

        // center vertically
        $this->SetXY($x, $y + ($h - $textH)/2);
        $this->MultiCell($w, $lineH, $txt, 0, $align);

        // restore position
        $this->SetXY($x + $w, $y);

        // draw border
        $this->Rect($x, $y, $w, $h);
    }
    function Header(){

    $this->SetDrawColor(0,0,0);
    $this->SetLineWidth(0.15);
    $this->Rect(10, 10, 277, 190);

    $X = 10;
    $Y = 10;

    $logoW = 30;
    $rightW = 110;
    $gap = 0;
    $headerH = 32;

    $titleW = 277 - ($logoW + $rightW + $gap);
    $titleX = $X + $logoW + $gap;
    $tableX = $titleX + $titleW;

    // ===== LOGO BOX =====
    $this->Rect($X, $Y, $logoW, $headerH);

    $logoCandidates = [
        __DIR__ . '/assets/logo.png',
        __DIR__ . '/assets/ukb.png'
    ];

    
foreach ($logoCandidates as $path) { if (file_exists($path)) { $gap = 1; $imgW = $logoW - (2 * $gap); $imgH = $headerH - (2 * $gap); $this->Image( $path, $X + $gap, $Y + $gap, $imgW, $imgH ); break; } }


    // ===== TITLE =====
    $this->SetFillColor(220,220,220);
    $this->Rect($titleX, $Y, $titleW, $headerH, 'F');
    $this->Rect($titleX, $Y, $titleW, $headerH);

    $this->SetFont('Arial','B',16);
    $this->SetXY($titleX, $Y + ($headerH/2) - 5);
    $this->Cell($titleW, 10, 'DAILY PROGRESS TRACKER (DPT)', 0, 0, 'C');

    // ===== RIGHT TABLE =====
    $fields = [
        'Project' => $this->dpt['project_name'] ?? '',
        'Client'  => $this->dpt['client_name'] ?? '',
        'PMC'     => $this->dpt['pmc'] ?? '',
        'Dated'   => $this->dpt['dated'] ?? date('Y-m-d')
    ];

    $rowH = $headerH / count($fields);
    $rowY = $Y;

    foreach($fields as $label => $value){

        $this->SetXY($tableX, $rowY);

        $this->SetFont('Arial','B',9);
        $this->Cell(40, $rowH, $label, 1);

        $this->SetFont('Arial','',9);
        $this->Cell($rightW - 40, $rowH, $value, 1);

        $rowY += $rowH;
    }

    // move cursor safely below header
    $this->SetY($Y + $headerH + 5);
}

    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }

    function NbLines($w,$txt){
        $cw=&$this->CurrentFont['cw'];
        $wmax=($w-2*$this->cMargin)*1000/$this->FontSize;
        $s=str_replace("\r",'',$txt);
        $nb=strlen($s);
        $sep=-1;$i=0;$j=0;$l=0;$nl=1;

        while($i<$nb){
            $c=$s[$i];
            if($c=="\n"){ $i++;$sep=-1;$j=$i;$l=0;$nl++;continue;}
            if($c==' ') $sep=$i;
            $l += $cw[$c] ?? 0;

            if($l>$wmax){
                $i = ($sep==-1)?$i+1:$sep+1;
                $sep=-1;$j=$i;$l=0;$nl++;
            } else $i++;
        }
        return $nl;
    }

    function TableHeader($w){
        $this->SetFont('Arial','B',9);
        $this->SetTextColor(255,255,255);
       $this->SetFillColor(130,150,180);

        $x=10; $y=$this->GetY();

        $this->SetXY($x,$y);
        $this->Cell($w[0],12,'SL.NO',1,0,'C',true);
        $this->Cell($w[1],12,'LIST OF PENDING WORKS',1,0,'C',true);
        $this->Cell($w[2]+$w[3],6,'DATE',1,0,'C',true);
        $this->Cell($w[4]+$w[5],6,'STATUS',1,0,'C',true);
        $this->Cell($w[6],12,'REMARKS',1,0,'C',true);

        $this->SetXY($x+$w[0]+$w[1],$y+6);
        $this->Cell($w[2],6,'SCHEDULED FINISH',1,0,'C',true);
        $this->Cell($w[3],6,'ACTUAL / TARGETED FINISH',1,0,'C',true);

        $this->SetFillColor(0,200,0);
        $this->Cell($w[4],6,'ONTRACK',1,0,'C',true);

        $this->SetFillColor(220,0,0);
        $this->Cell($w[5],6,'DELAY',1,0,'C',true);

        $this->SetY($y+12);
        $this->SetTextColor(0,0,0);
    }
}

// ===== INIT =====
$pdf = new DPTPDF('L','mm','A4');
$pdf->SetMargins(10,10,10);
$pdf->AliasNbPages();
$pdf->setData($dpt);
$pdf->AddPage();


// widths (7 columns)
$w = [20, 90, 45, 45, 18, 18, 41];

$pdf->TableHeader($w);

$pdf->SetFont('Arial','',8); // Reduced font size from 9 to 8

foreach($rows as $r){

    $lineH = 4; // Reduced from 5 to 4
    
    // Calculate required height based on work description only
    $nb = $pdf->NbLines($w[1], $r['list_of_work'] ?? '');
    $h = $nb * $lineH;
    
    // Use fixed minimum height (reduced from 16 to 10)
   $h = max($h, 7);

    if($pdf->GetY() + $h > 185){
        $pdf->AddPage();
        $pdf->setData($dpt);
        $pdf->TableHeader($w);
    }

    $x = $pdf->GetX();
    $y = $pdf->GetY();

    // SL.NO
    $pdf->Rect($x, $y, $w[0], $h);
    $pdf->VCell($w[0], $h, $r['sl_no'], 1, 'C');
    $pdf->SetXY($x + $w[0], $y);

    // LIST OF PENDING WORKS
    $pdf->VCell($w[1], $h, $r['list_of_work'] ?? '', 1, 'L');
    $pdf->SetXY($x + $w[0] + $w[1], $y);

    // SCHEDULED FINISH
    $pdf->Rect($pdf->GetX(), $y, $w[2], $h);
    $pdf->VCell($w[2], $h, $r['scheduled_finish'], 1, 'C');
    $pdf->SetXY($x + $w[0] + $w[1] + $w[2], $y);

    // ACTUAL / TARGETED FINISH
    $pdf->Rect($pdf->GetX(), $y, $w[3], $h);
    $pdf->VCell($w[3], $h, $r['actual_targeted_finish'], 1, 'C');
    $pdf->SetXY($x + $w[0] + $w[1] + $w[2] + $w[3], $y);

    $status = strtoupper($r['status'] ?? '');

    // ONTRACK
// ONTRACK
$x1 = $pdf->GetX();
$pdf->Rect($x1, $y, $w[4], $h);

if($status === 'ONTRACK'){
    $pdf->SetFillColor(0,255,0);
    $pdf->Rect($x1, $y, $w[4], $h, 'F');

    $pdf->SetTextColor(0,0,0);
    $pdf->SetXY($x1, $y + ($h - $lineH)/2);
    $pdf->Cell($w[4], $lineH, 'ONTRACK', 0, 0, 'C');
}

$pdf->SetXY($x1 + $w[4], $y);

// DELAY
$x2 = $pdf->GetX();
$pdf->Rect($x2, $y, $w[5], $h);

if($status === 'DELAY'){
    $pdf->SetFillColor(255,0,0);
    $pdf->Rect($x2, $y, $w[5], $h, 'F');

    $pdf->SetTextColor(255,255,255);
    $pdf->SetXY($x2, $y + ($h - $lineH)/2);
    $pdf->Cell($w[5], $lineH, 'DELAY', 0, 0, 'C');
}

$pdf->SetTextColor(0,0,0);
    
    $remarksX = $x + array_sum(array_slice($w, 0, 6));
    $pdf->SetXY($remarksX, $y);
    // REMARKS - SINGLE COLUMN
    $pdf->SetFont('Arial', '', 8); // Reduced font size
    $pdf->Rect($remarksX, $y, $w[6], $h);
    
    // Vertically center the remarks text
    $remarks_text = $r['remark'] ?? '';

$nbR = $pdf->NbLines($w[6] - 4, $remarks_text);
$textH = $nbR * $lineH;

$text_y = $y + ($h - $textH) / 2;

$pdf->SetXY($remarksX + 2, $text_y);
$pdf->MultiCell($w[6] - 4, $lineH, $remarks_text, 0, 'L');

    $pdf->SetY($y + $h);
}

// footer notes
// $pdf->Ln(4);
// $pdf->SetFont('Arial','I',8);
// $pdf->Cell(0,4,'Status Legend: ON TRACK | DELAY',0,1);
// $pdf->Cell(0,4,'Generated by: '.$_SESSION['employee_name'].' on '.date('d-m-Y H:i:s'),0,1);

while (ob_get_level()) ob_end_clean();
$pdf->Output();
exit;