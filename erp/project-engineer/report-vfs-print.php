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
$stmt = $conn->prepare("SELECT * FROM vfs_main WHERE id=?");
$stmt->bind_param("i", $viewId);
$stmt->execute();
$vfs = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM vfs_details WHERE vfs_main_id=? ORDER BY sl_no");
$stmt->bind_param("i", $viewId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

// ===== PDF CLASS =====
class VFSPDF extends FPDF {

    private $vfs;

    function setData($vfs){
        $this->vfs = $vfs;
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
        $this->SetFillColor(220,220,220);
        $this->Rect($X+$logoW,$Y,$titleW,$headerH,'FD');
        $this->SetFont('Arial','B',13);
        $this->SetXY($X+$logoW,$Y+10);
        $this->Cell($titleW,6,'VENDOR FINALIZATION SCHEDULE (VFS)',0,0,'C');

        // RIGHT GRID
        $rx = $X+$logoW+$titleW;
        $rows = [
            ['Project',$this->vfs['project_name'] ?? ''],
            ['Client',$this->vfs['client_name'] ?? ''],
            ['Architect',$this->vfs['architects'] ?? ''],
            ['PMC',$this->vfs['pmc'] ?? ''],
            ['Date/Version',($this->vfs['vfs_date'] ?? '').' / '.($this->vfs['version'] ?? 'R0')]
        ];

        $rowH=$headerH/count($rows);
        $labelW=30; $valueW=$rightW-$labelW;

        foreach($rows as $i=>$r){
            $this->SetXY($rx,$Y+$i*$rowH);

            $this->SetFillColor(230,230,230);
            $this->SetFont('Arial','B',9);
            $this->Cell($labelW,$rowH,$r[0],1,0,'L',true);

            $this->SetFont('Arial','',9);
            $this->Cell($valueW,$rowH,$r[1],1,0,'L');
        }

        $this->SetY($Y+$headerH+5);
    }

    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
}

// ===== INIT =====
$pdf = new VFSPDF('L','mm','A4');
$pdf->SetMargins(10,10,10);
$pdf->AliasNbPages();
$pdf->setData($vfs);
$pdf->AddPage();

$rowCount = 0;

// ===== COLUMN WIDTHS =====
$w = [15,110,40,35,35,42];

// ===== HEADER DRAW FUNCTION =====
function drawHeader($pdf,$w){

    $pdf->SetFont('Arial','B',10);
    $pdf->SetFillColor(132,161,196);

    $y=$pdf->GetY();
    $x=$pdf->GetX();

    // SL NO
    $pdf->MultiCell($w[0],14,'SL NO',1,'C',true);
    $pdf->SetXY($x+$w[0],$y);

    // PACKAGES
    $pdf->MultiCell($w[1],14,'PACKAGES',1,'C',true);
    $pdf->SetXY($x+$w[0]+$w[1],$y);

    // ===== DURATION (WITH LINE) =====
    $dx=$pdf->GetX();
    $dy=$pdf->GetY();
    $h=14; $half=$h/2;

    $pdf->Rect($dx,$dy,$w[2],$h,'DF');
    $pdf->Line($dx,$dy+$half,$dx+$w[2],$dy+$half);

    $pdf->SetXY($dx,$dy+1.5);
    $pdf->Cell($w[2],5,'DURATION',0,0,'C');

    $pdf->SetXY($dx,$dy+$half+1.5);
    $pdf->Cell($w[2],5,'(DAYS)',0,0,'C');

    $pdf->SetXY($dx+$w[2],$dy);

    // DATE
    $pdf->Cell($w[3]+$w[4],7,'DATE',1,0,'C',true);

    // REMARK
    $pdf->MultiCell($w[5],14,'REMARK',1,'C',true);

    // row 2
    $pdf->SetXY($x+$w[0]+$w[1]+$w[2],$y+7);
    $pdf->Cell($w[3],7,'START',1,0,'C',true);
    $pdf->Cell($w[4],7,'END',1,0,'C',true);

    $pdf->Ln(7);
}

// draw first header
drawHeader($pdf,$w);

// ===== BODY =====
$pdf->SetFont('Arial','',9);

foreach($rows as $r){

    if($pdf->GetY()>180){
        $pdf->AddPage();
        drawHeader($pdf,$w);
    }

    $fill = ($rowCount++ % 2 == 0);
    $c = $fill ? 245 : 255;
    $pdf->SetFillColor($c,$c,$c);

    // SAFE MAPPING (won’t fail silently)
    $duration = $r['duration_days'] ?? $r['duration'] ?? '-';
    $start    = $r['start_date'] ?? $r['start'] ?? '-';
    $end      = $r['end_date'] ?? $r['end'] ?? '-';

    $pdf->Cell($w[0],8,$r['sl_no'] ?? '',1,0,'C',$fill);
    $pdf->Cell($w[1],8,substr($r['package_name'] ?? '-',0,40),1,0,'L',$fill);
    $pdf->Cell($w[2],8,$duration,1,0,'C',$fill);
    $pdf->Cell($w[3],8,$start,1,0,'C',$fill);
    $pdf->Cell($w[4],8,$end,1,0,'C',$fill);
    $pdf->Cell($w[5],8,substr($r['remarks'] ?? '-',0,35),1,0,'L',$fill);

    $pdf->Ln();
}

// ===== OUTPUT =====
while (ob_get_level()) ob_end_clean();
$pdf->Output('I','VFS_Report.pdf');
exit;