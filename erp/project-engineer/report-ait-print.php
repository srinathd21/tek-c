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
if (!$conn) die("DB connection failed");

$viewId = (int)($_GET['view'] ?? 0);
if ($viewId <= 0) die("Invalid ID");

/* ===== FETCH DATA ===== */

$mainQ = mysqli_query($conn, "
    SELECT m.*, s.project_location 
    FROM ait_main m
    JOIN sites s ON s.id = m.site_id
    WHERE m.id = $viewId
");
$main = mysqli_fetch_assoc($mainQ);
if (!$main) die("No data");

$detailsQ = mysqli_query($conn, "
    SELECT * FROM ait_details 
    WHERE ait_main_id = $viewId 
    ORDER BY sl_no ASC
");
$details = mysqli_fetch_all($detailsQ, MYSQLI_ASSOC);

/* ===== HELPERS ===== */

function clean($s){
    return trim(preg_replace('/\s+/', ' ', strip_tags((string)$s)));
}

function dmy($d){
    if (!$d || $d == '0000-00-00') return '';
    return date('d-m-Y', strtotime($d));
}

/* ===== PDF CLASS ===== */

class AITPDF extends FPDF {

    public $main;

    function setData($main){
        $this->main = $main;
    }

    function Header(){

        $X = 10; $Y = 10;
        $totalW = 277;

        $this->Rect($X, $Y, $totalW, 190);

        $logoW = 30;
        $headerH = 25;
        $metaW = 90;
        $titleW = $totalW - ($logoW + $metaW);

        /* ===== LOGO BOX ===== */
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

        $imgInfo = @getimagesize($path);

        if ($imgInfo) {

            list($imgW, $imgH) = $imgInfo;

           $gap = 1; // small padding

$boxW = $logoW - (2 * $gap);
$boxH = $headerH - (2 * $gap);

// 🔥 FORCE WIDTH-FIT (reduces left/right gap)
$ratio = $boxW / $imgW;

$w = $boxW;
$h = $imgH * $ratio;

// If height overflows, fallback to normal fit
if ($h > $boxH) {
    $ratio = $boxH / $imgH;
    $h = $boxH;
    $w = $imgW * $ratio;
}

// center
$imgX = $X + ($logoW - $w) / 2;
$imgY = $Y + ($headerH - $h) / 2;

$this->Image($path, $imgX, $imgY, $w, $h);
        }

        break;
    }
}

        /* ===== TITLE ===== */
        $this->SetFillColor(220,220,220);
        $this->Rect($X + $logoW, $Y, $titleW, $headerH, 'FD');

        $this->SetFont('Arial','B',14);
        $this->SetXY($X + $logoW, $Y + 8);
        $this->Cell($titleW, 8, 'ACTION ITEM TRACKER (AIT)', 0, 0, 'C');

        /* ===== META ===== */
        $metaX = $X + $logoW + $titleW;

        $data = [
            ['Project', $this->main['project_name']],
            ['Client', $this->main['client_name']],
            ['Architect', $this->main['architects']],
            ['PMC', $this->main['pmc']],
            ['AIT No', $this->main['ait_no']]
        ];

        $rowH = $headerH / count($data);
        $labelW = 30;

        foreach ($data as $i => $row){
            $this->SetXY($metaX, $Y + ($i * $rowH));

            $this->SetFont('Arial','B',9);
            $this->SetFillColor(230,230,230);
            $this->Cell($labelW, $rowH, $row[0], 1, 0, 'L', true);

            $this->SetFont('Arial','',9);
            $this->Cell($metaW - $labelW, $rowH, clean($row[1]), 1, 0, 'L');
        }

        $this->SetY($Y + $headerH + 6);
    }

    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
}

/* ===== INIT ===== */

$pdf = new AITPDF('L','mm','A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10,10,10);
$pdf->setData($main);
$pdf->AddPage();

/* ===== TABLE HEADER ===== */

$w = [12,28,60,25,35,28,28,40,21];

$pdf->SetFont('Arial','B',9);
$pdf->SetFillColor(141,180,226);

$headers = [
    'SL','DATE','DESCRIPTION','PRIORITY',
    'RESPONSIBLE','DUE','COMPLETE','PROGRESS','STATUS'
];

foreach ($headers as $i => $h) {
    $pdf->Cell($w[$i],10,$h,1,0,'C',true);
}
$pdf->Ln();

/* ===== TABLE BODY ===== */

$pdf->SetFont('Arial','',9);

$rowCount = 0;

foreach ($details as $r){

    if ($pdf->GetY() > 180){
        $pdf->AddPage();

        foreach ($headers as $i => $h) {
            $pdf->Cell($w[$i],10,$h,1,0,'C',true);
        }
        $pdf->Ln();
    }

    $fill = ($rowCount++ % 2 == 0);
    $c = $fill ? 245 : 255;
    $pdf->SetFillColor($c,$c,$c);

    $pdf->Cell($w[0],8,$r['sl_no'],1,0,'C',$fill);
    $pdf->Cell($w[1],8,dmy($r['dated']),1,0,'C',$fill);
    $pdf->Cell($w[2],8,substr(clean($r['description']),0,40),1,0,'L',$fill);
    $pdf->Cell($w[3],8,$r['priority'],1,0,'C',$fill);
    $pdf->Cell($w[4],8,$r['responsible_by'],1,0,'L',$fill);
    $pdf->Cell($w[5],8,dmy($r['due_date']),1,0,'C',$fill);
    $pdf->Cell($w[6],8,dmy($r['completion_date']),1,0,'C',$fill);
    $pdf->Cell($w[7],8,substr(clean($r['progress_notes']),0,35),1,0,'L',$fill);
    $pdf->Cell($w[8],8,$r['status'],1,0,'C',$fill);

    $pdf->Ln();
}

/* ===== OUTPUT ===== */

while (ob_get_level()) ob_end_clean();
$pdf->Output('I','AIT_Report.pdf');
exit;