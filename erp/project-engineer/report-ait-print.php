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

$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die("Invalid AIT ID");

/* ---------------- HELPERS ---------------- */

function clean($s){
    return trim(preg_replace('/\s+/', ' ', strip_tags((string)$s)));
}

function dmy($d){
    if (!$d || $d == '0000-00-00') return '';
    return date('d-m-Y', strtotime($d));
}

// Get company logo from database (like DAR code)
$companyLogoDb = '';
$companySql = "SELECT logo_path FROM company_details WHERE id = 1 LIMIT 1";
$companyResult = mysqli_query($conn, $companySql);
if ($companyResult) {
    $companyData = mysqli_fetch_assoc($companyResult);
    if (!empty($companyData['logo_path'])) $companyLogoDb = $companyData['logo_path'];
}

/* ---------------- FETCH DATA ---------------- */

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

/* ---------------- PDF CLASS ---------------- */

class PDF extends FPDF {
    
    public $logoPath = '';
    public $logoWidth = 0;
    public $logoHeight = 0;
    
    protected $bottomMargin = 30;
    protected $headerHeight = 35;
    protected $colWidths = [12, 28, 60, 25, 35, 28, 38, 36, 25];
    
    function Header(){
    // Page usable width
    $pageWidth = 287; // 297 - 10 margin

    // Outer border
    $this->Rect(5,5,$pageWidth,200);

    $logoBoxW = 30;
    $metaW = 90;
    $titleW = $pageWidth - $logoBoxW - $metaW;

    // Logo box
    $this->Rect(5,5,$logoBoxW,25);

    // --- LOGO ---
    if ($this->logoPath && file_exists($this->logoPath)) {
        list($imgW, $imgH) = getimagesize($this->logoPath);

        $maxW = $logoBoxW - 6;
        $maxH = 19;

        $ratio = min($maxW / $imgW, $maxH / $imgH);

        $w = $imgW * $ratio;
        $h = $imgH * $ratio;

        $x = 5 + ($logoBoxW - $w)/2;
        $y = 5 + (25 - $h)/2;

        $this->Image($this->logoPath, $x, $y, $w, $h);
    } else {
        $this->SetFont('Arial','I',9);
        $this->SetXY(5,15);
        $this->Cell($logoBoxW,5,'No Logo',0,0,'C');
    }

    // --- TITLE ---
    $this->SetXY(5 + $logoBoxW, 5);
    $this->SetFont('Arial','B',14);
    $this->Cell($titleW,25,'ACTION ITEM TRACKER (AIT)',1,0,'C');

    // --- META BOX ---
    $this->SetFont('Arial','',8);

    $x = 5 + $logoBoxW + $titleW; // ✅ FIXED
    $y = 5;
    $h = 5;

    $data = [
        ['Project', $GLOBALS['main']['project_name']],
        ['Client', $GLOBALS['main']['client_name']],
        ['Architects', $GLOBALS['main']['architects']],
        ['PMC', $GLOBALS['main']['pmc']],
        ['AIT No', $GLOBALS['main']['ait_no']]
    ];

    foreach ($data as $row){
        $this->SetXY($x,$y);
        $this->Cell(30,$h,$row[0],1,0,'L');
        $this->Cell($metaW - 30,$h,clean($row[1]),1,0,'L');
        $y += $h;
    }

    $this->headerHeight = 30;
    $this->SetY($this->headerHeight);
    $this->SetX(5);
    $this->headerHeight = 30;

// add gap (e.g. 10mm)
$gap = 8;

$this->SetY($this->headerHeight + $gap);
$this->SetX(5);
}

    
    function GetRowHeight($data, $isHeader = false) {
        $lineHeight = $isHeader ? 8 : 12;
        $maxLines = 1;
        
        if (!$isHeader) {
            foreach ($data as $i => $txt) {
                $lines = $this->NbLines($this->colWidths[$i], $txt);
                if ($lines > $maxLines) $maxLines = $lines;
            }
        }
        
        return $lineHeight * $maxLines;
    }
    
    function DrawHeaderRow() {
        $headers = [
            'SL NO', 'DATED', 'DESCRIPTION', 'PRIORITY',
            'RESPONSIBLE BY', 'DUE DATE', 'COMPLETION DATE',
            'PROGRESS NOTES', 'STATUS'
        ];
        
        $height = $this->GetRowHeight($headers, true);
        
        // Check page break
        $usableBottom = 200 - $this->bottomMargin;
        if ($this->GetY() + $height > $usableBottom) {
            $this->AddPage();
            $this->SetY($this->headerHeight);
            $this->SetX(5);
        }
        
        $yStart = $this->GetY();
        $xStart = $this->GetX();
        
        // Draw header background and borders
        $this->SetFillColor(141,180,226);
        $this->SetFont('Arial','B',9);
        
        $x = $xStart;
        foreach ($headers as $i => $text) {
            $w = $this->colWidths[$i];
            $this->Rect($x, $yStart, $w, $height, 'DF');
            $this->SetXY($x + 1, $yStart + (($height - 6) / 2));
            $this->Cell($w - 2, 6, $text, 0, 0, 'C');
            $x += $w;
        }
        
        $this->SetY($yStart + $height);
        $this->SetX($xStart);
    }
    
    function DrawDataRow($data) {
        $height = $this->GetRowHeight($data, false);
        
        // Check page break
        $usableBottom = 200 - $this->bottomMargin;
        if ($this->GetY() + $height > $usableBottom) {
            $this->AddPage();
            $this->SetY($this->headerHeight);
            $this->SetX(5);
            $this->DrawHeaderRow(); // Redraw header on new page
        }
        
        $yStart = $this->GetY();
        $xStart = $this->GetX();
        
        // Draw row borders and content
        $x = $xStart;
        foreach ($data as $i => $text) {
            $w = $this->colWidths[$i];
            
            // Priority color for column index 3
            if ($i == 3) {
                $p = strtoupper(trim($text));
                if ($p == 'HIGH') $this->SetFillColor(255,0,0);
                elseif ($p == 'MEDIUM') $this->SetFillColor(255,192,0);
                elseif ($p == 'LOW') $this->SetFillColor(0,176,80);
                else $this->SetFillColor(255,255,255);
                
                $this->Rect($x, $yStart, $w, $height, 'DF');
                $this->SetFillColor(255,255,255);
            } else {
                $this->Rect($x, $yStart, $w, $height);
            }
            
            // Draw text
            $this->SetFont('Arial','',9);
            $textHeight = $this->NbLines($w-2, $text) * 5;
            $startY = $yStart + (($height - $textHeight) / 2);
            
            $this->SetXY($x + 1, $startY);
            
            // Set alignment
            $align = 'L';
            if ($i == 0 || $i == 1 || $i == 3 || $i == 5 || $i == 6) $align = 'C';
            if ($i == 4) $align = 'L';
            if ($i == 7) $align = 'L';
            if ($i == 8) $align = 'C';
            
            $this->MultiCell($w - 2, 5, $text, 0, $align);
            
            $x += $w;
        }
        
        $this->SetY($yStart + $height);
        $this->SetX($xStart);
    }
    
    function NbLines($w, $txt) {
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $cw = &$this->CurrentFont['cw'];
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb-1] == "\n") $nb--;
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) $i++;
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
    
    function Footer(){
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo(),0,0,'C');
    }
}

/* ---------------- BUILD PDF ---------------- */

$pdf = new PDF('L','mm','A4');

// Find logo with prioritized search
$logoCandidates = [];

// First priority: Database path
if (!empty($companyLogoDb)) {
    $p1 = __DIR__ . '/' . ltrim($companyLogoDb, '/');
    $p2 = __DIR__ . '/../' . ltrim($companyLogoDb, '/');
    $logoCandidates[] = $p1;
    $logoCandidates[] = $p2;
}

// Second priority: Common logo locations
$logoCandidates = array_merge($logoCandidates, [
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
]);

// Find first valid logo
foreach ($logoCandidates as $path) {
    if ($path && file_exists($path)) {
        // Validate it's an image file
        $mime = mime_content_type($path);
        if (strpos($mime, 'image/') === 0) {
            $pdf->logoPath = $path;
            break;
        }
    }
}

$pdf->AddPage();
$pdf->SetX(5);

// Draw header
$pdf->DrawHeaderRow();

// Draw data rows
if (!$details) {
    $pdf->DrawDataRow(['1', '', 'No Data', '', '', '', '', '', '']);
} else {
    foreach ($details as $r) {
        $pdf->DrawDataRow([
            $r['sl_no'],
            dmy($r['dated']),
            clean($r['description']),
            clean($r['priority']),
            clean($r['responsible_by']),
            dmy($r['due_date']),
            dmy($r['completion_date']),
            clean($r['progress_notes']),
            clean($r['status'])
        ]);
    }
}

$pdf->Output();
exit;
?>