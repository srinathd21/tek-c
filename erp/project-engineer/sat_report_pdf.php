    <?php
    session_start();
    require_once 'includes/db-config.php';
    require_once 'libs/fpdf.php';

    $conn = get_db_connection();

    $batchId = $_GET['batch_id'] ?? '';
    if (!$batchId) die("No batch ID");

    $stmt = mysqli_prepare($conn, "SELECT * FROM sat_samples WHERE batch_id=? ORDER BY sl_no ASC");
    mysqli_stmt_bind_param($stmt, "s", $batchId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);

    if (!$rows) die("No data");

    $meta = [
        'project' => $rows[0]['project_name'],
        'client' => $rows[0]['client_name'],
        'architects' => $rows[0]['architects'] ?? '',
        'pmc' => $rows[0]['pmc'] ?? '',
        'revisionsdated' => $rows[0]['revisions'] ?? ''
    ];

    class PDF extends FPDF {

        public $meta;
        public $headerBottom = 0;
        public $logoPath = '';

        function Header() {

            $margin = 10;
            $pageW = $this->GetPageWidth();
            $totalWidth = $pageW - ($margin * 2);

            $logoW = 30;
            $rightW = 110;
            $titleW = $totalWidth - $logoW - $rightW;

            $this->SetXY($margin, 10);

            // ---- LOGO BOX ----
            $this->Cell($logoW, 30, '', 1);

            // 🔥 RENDER LOGO
            if (!empty($this->logoPath) && file_exists($this->logoPath)) {
                $this->Image(
                    $this->logoPath,
                    $margin + 2,
                    12,
                    $logoW - 4
                );
            }

            // ---- TITLE ----
            $this->SetXY($margin + $logoW, 10);
            $this->SetFillColor(220,220,220);
            $this->SetFont('Arial','B',14);
            $this->Cell($titleW, 30, 'SAMPLES APPROVAL TRACKER (SAT)', 1, 0, 'C', true);

            // ---- RIGHT META ----
            $x = $margin + $logoW + $titleW;
            $y = 10;
            $h = 30 / 5;

            $labels = ['Project','Client','Architects','PMC','Revisions/Dated'];

            for ($i=0;$i<5;$i++) {
                $this->SetXY($x, $y + ($i*$h));
                $this->SetFont('Arial','B',9);
                $this->Cell(35,$h,$labels[$i],1);

                $key = strtolower(str_replace(['/',' '],'',$labels[$i]));

                $this->SetFont('Arial','',9);
                $this->Cell($rightW-35,$h,$this->meta[$key] ?? '',1);
            }

            // OUTER BORDER
            $this->Rect($margin, 10, $totalWidth, 30);

            // HEADER BOTTOM
            $this->headerBottom = 40;
            $this->SetY($this->headerBottom);

            // ✅ BORDERED EMPTY ROW
            $this->SetX($margin);
            $this->Cell($totalWidth, 8, '', 1);
        }
    }

    // ---------------- PDF INIT ----------------
    $pdf = new PDF('L','mm','A3');
    $pdf->meta = $meta;

    // 🔥 DB LOGO (FIXED PATH)
$companySql = "SELECT logo_path FROM company_details WHERE id = 1 LIMIT 1";
$res = mysqli_query($conn, $companySql);
$rowLogo = mysqli_fetch_assoc($res);
$companyLogoDb = $rowLogo['logo_path'] ?? '';

$logoCandidates = [];
if (!empty($companyLogoDb)) {
    $p1 = __DIR__ . '/' . ltrim($companyLogoDb, '/');
    $p2 = __DIR__ . '/../' . ltrim($companyLogoDb, '/');
    $logoCandidates[] = $p1;
    $logoCandidates[] = $p2;
}

// Add fallback paths
$logoCandidates = array_merge($logoCandidates, [
    __DIR__ . '/public/logo.png',
    __DIR__ . '/assets/logo.png',
    __DIR__ . '/images/logo.png',
    __DIR__ . '/logo.png',
    __DIR__ . '/assets/ukb.png',
    __DIR__ . '/assets/ukb.jpg',
]);

foreach ($logoCandidates as $p) {
    if ($p && file_exists($p)) { 
        $pdf->logoPath = $p; 
        break; 
    }
}
    $pdf->AddPage();

    // START AFTER HEADER
    $pdf->SetY($pdf->headerBottom + 8);

    $pageW = $pdf->GetPageWidth();
    $margin = 10;
    $totalWidth = $pageW - ($margin * 2);

    $X0 = $margin;
    $pdf->SetX($X0);

    // ---- PORTRAIT WIDTHS ----
    $w = [12,55,45,20,20,20,20,20,20,20,55];

    // SCALE
    $sum = array_sum($w);
    $scale = $totalWidth / $sum;
    foreach ($w as &$col) $col *= $scale;

    // ---- HEADER ----
    $pdf->SetFillColor(141,180,226);
    $pdf->SetFont('Arial','B',9);

    $X = $pdf->GetX();
    $Y = $pdf->GetY();

    $pdf->Cell($w[0],12,'SL NO',1,0,'C',true);
    $pdf->Cell($w[1],12,'SAMPLES',1,0,'C',true);
    $pdf->Cell($w[2],12,'VENDORS',1,0,'C',true);

    $pdf->Cell($w[3]+$w[4],6,'SAMPLE STATUS',1,0,'C',true);
    $pdf->Cell($w[5]+$w[6],6,'QUOTE STATUS',1,0,'C',true);
    $pdf->Cell($w[7]+$w[8]+$w[9],6,'APPROVAL STATUS (Y)',1,0,'C',true);

    $pdf->Cell($w[10],12,'COMMENTS / FURTHER ACTION',1,0,'C',true);

    // sub header
    $pdf->SetXY($X + $w[0] + $w[1] + $w[2], $Y + 6);

    $pdf->Cell($w[3],6,'DELIVERED',1,0,'C',true);
    $pdf->Cell($w[4],6,'DATE',1,0,'C',true);

    $pdf->Cell($w[5],6,'RECEIVED',1,0,'C',true);
    $pdf->Cell($w[6],6,'DATE',1,0,'C',true);

    $pdf->Cell($w[7],6,'APPROVED',1,0,'C',true);
    $pdf->Cell($w[8],6,'REJECTED',1,0,'C',true);
    $pdf->Cell($w[9],6,'DATE',1,0,'C',true);

    $pdf->SetXY($X0, $Y + 12);

    // ---- DATA ----
    $pdf->SetFont('Arial','',9);
    $startY = $pdf->GetY();

    foreach ($rows as $r) {

        $pdf->Cell($w[0],8,$r['sl_no'],1);
        $pdf->Cell($w[1],8,$r['sample_name'],1);
        $pdf->Cell($w[2],8,$r['vendor_name'],1);

        $pdf->Cell($w[3],8,$r['sample_delivered'] ? 'Y' : '-',1);
        $pdf->Cell($w[4],8,$r['sample_delivered_date'] ?? '-',1);

        $pdf->Cell($w[5],8,$r['quote_received'] ? 'Y' : '-',1);
        $pdf->Cell($w[6],8,$r['quote_received_date'] ?? '-',1);

        $pdf->Cell($w[7],8,$r['approved'] ? 'Y' : '-',1);
        $pdf->Cell($w[8],8,$r['rejected'] ? 'N' : '-',1);
        $pdf->Cell($w[9],8,$r['approval_date'] ?? '-',1);

        $pdf->Cell($w[10],8,$r['comments'] ?? '-',1);

        $pdf->Ln();
    }

    // OUTER BORDER
    $tableHeight = $pdf->GetY() - $startY;
    $pdf->Rect($X0, $startY, $totalWidth, $tableHeight);

    $pdf->Output(); 