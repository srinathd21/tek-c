<?php
ob_start();
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/libs/fpdf.php';

// ✅ Login check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin');

/* -------------------- HELPER FUNCTIONS -------------------- */
function esc_db($v) {
    global $conn;
    return mysqli_real_escape_string($conn, trim((string)$v));
}

function clean($s) {
    $s = strip_tags((string)$s);
    $s = preg_replace('/\s+/', ' ', $s);
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = str_replace(["\xE2\x80\x93", "\xE2\x80\x94", "–", "—"], '-', $s);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($converted !== false) $s = $converted;
    return trim($s);
}

/* -------------------- READ FILTERS FROM REPORTS PAGE -------------------- */
$filter = [
    'type' => $_GET['type'] ?? 'both',
    'date_mode' => $_GET['date_mode'] ?? 'all',
    'day' => $_GET['day'] ?? '',
    'week' => $_GET['week'] ?? '',
    'month' => $_GET['month'] ?? '',
    'start' => $_GET['start'] ?? '',
    'end' => $_GET['end'] ?? '',
    'vendor' => $_GET['vendor'] ?? '',
    'payment' => $_GET['payment'] ?? '',
    'cost_type' => $_GET['cost_type'] ?? '',
    'amount_min' => $_GET['amount_min'] ?? '',
    'amount_max' => $_GET['amount_max'] ?? '',
    'search' => $_GET['search'] ?? '',
    'sort' => $_GET['sort'] ?? 'date_asc',
    'business_id' => isset($_GET['business_id']) ? intval($_GET['business_id']) : 0,
];

/* -------------------- BUSINESS DETAILS -------------------- */
$project = 'Not Specified';
$client  = 'Not Specified';
$pmc     = 'Not Specified';

if ($filter['business_id'] > 0) {
    $bq = mysqli_query($conn, "SELECT project_name, client, pmc FROM businesses WHERE id = {$filter['business_id']} LIMIT 1");
} else {
    $bq = mysqli_query($conn, "SELECT project_name, client, pmc FROM businesses ORDER BY id DESC LIMIT 1");
}
if ($bq && mysqli_num_rows($bq) > 0) {
    $biz = mysqli_fetch_assoc($bq);
    $project = clean($biz['project_name'] ?? 'Not Specified');
    $client  = clean($biz['client'] ?? 'Not Specified');
    $pmc     = clean($biz['pmc'] ?? 'Not Specified');
}

// ✅ As requested: keep header text exactly like this (same label text)
$scope_of_work = 'Cash flow report';
if ($filter['type'] === 'income')  $scope_of_work = 'Income cash flow';
if ($filter['type'] === 'expense') $scope_of_work = 'Expense cash flow';

/* -------------------- BUILD DATE RANGE -------------------- */
$from = null; $to = null;
$date_label = 'All Dates';

if ($filter['date_mode'] === 'day' && $filter['day']) {
    $from = $to = $filter['day'];
    $date_label = date('d-m-Y', strtotime($filter['day']));
} elseif ($filter['date_mode'] === 'week' && $filter['week']) {
    $ts = strtotime($filter['week']);
    $dow = (int)date('N', $ts);
    $monday = strtotime("-" . ($dow - 1) . " days", $ts);
    $sunday = strtotime("+" . (7 - $dow) . " days", $monday);
    $from = date('Y-m-d', $monday);
    $to   = date('Y-m-d', $sunday);
    $date_label = date('d-m-Y', $monday) . ' to ' . date('d-m-Y', $sunday);
} elseif ($filter['date_mode'] === 'month' && $filter['month']) {
    $from = date('Y-m-01', strtotime($filter['month'] . '-01'));
    $to   = date('Y-m-t', strtotime($filter['month'] . '-01'));
    $date_label = date('F Y', strtotime($filter['month'] . '-01'));
} elseif ($filter['date_mode'] === 'range' && $filter['start'] && $filter['end']) {
    $from = $filter['start'];
    $to = $filter['end'];
    $date_label = date('d-m-Y', strtotime($filter['start'])) . ' to ' . date('d-m-Y', strtotime($filter['end']));
}

/* -------------------- BUILD WHERE FRAGMENTS -------------------- */
$incomeWhere = [];
$expenseWhere = [];

if (!$is_admin) {
    $incomeWhere[]  = "user_id = $user_id";
    $expenseWhere[] = "user_id = $user_id";
}

if ($from && $to) {
    $f = esc_db($from); $t = esc_db($to);
    $incomeWhere[] = "income_date BETWEEN '$f' AND '$t'";
    $expenseWhere[] = "expense_date BETWEEN '$f' AND '$t'";
}

if ($filter['vendor'])  $expenseWhere[] = "vendor_name = '" . esc_db($filter['vendor']) . "'";
if ($filter['payment']) $expenseWhere[] = "payment_type = '" . esc_db($filter['payment']) . "'";

if ($filter['cost_type']) {
    $sct = esc_db($filter['cost_type']);
    $incomeWhere[]  = "cost_type LIKE '%$sct%'";
    $expenseWhere[] = "description LIKE '%$sct%'";
}

if ($filter['amount_min'] !== '') {
    $min = floatval($filter['amount_min']);
    $incomeWhere[]  = "amount >= $min";
    $expenseWhere[] = "amount >= $min";
}
if ($filter['amount_max'] !== '') {
    $max = floatval($filter['amount_max']);
    $incomeWhere[]  = "amount <= $max";
    $expenseWhere[] = "amount <= $max";
}
if ($filter['search']) {
    $s = esc_db($filter['search']);
    $incomeWhere[]  = "(cost_type LIKE '%$s%')";
    $expenseWhere[] = "(description LIKE '%$s%' OR vendor_name LIKE '%$s%')";
}

/* -------------------- QUERY DATA (store with source + month key) -------------------- */
$rows = [];
$incomeTotal = 0.0;
$expenseTotal = 0.0;

if ($filter['type'] === 'income' || $filter['type'] === 'both') {
    $sql = "SELECT income_date AS dt, cost_type AS descr, amount FROM income";
    if (count($incomeWhere)) $sql .= " WHERE " . implode(" AND ", $incomeWhere);
    $sql .= " ORDER BY income_date ASC, id ASC";
    $iq = mysqli_query($conn, $sql);
    while ($r = mysqli_fetch_assoc($iq)) {
        $amount = (float)$r['amount'];
        $rows[] = [
            'date' => $r['dt'],
            'month_key' => date('Y-m', strtotime($r['dt'])),
            'desc' => clean($r['descr']),
            'vendor' => '-',
            'account' => '-',
            'in' => $amount,
            'out' => 0.0,
        ];
        $incomeTotal += $amount;
    }
}

if ($filter['type'] === 'expense' || $filter['type'] === 'both') {
    $sql = "SELECT expense_date AS dt, description, vendor_name, payment_type, amount FROM expenses";
    if (count($expenseWhere)) $sql .= " WHERE " . implode(" AND ", $expenseWhere);
    $sql .= " ORDER BY expense_date ASC, id ASC";
    $eq = mysqli_query($conn, $sql);
    while ($r = mysqli_fetch_assoc($eq)) {
        $amount = (float)$r['amount'];
        $rows[] = [
            'date' => $r['dt'],
            'month_key' => date('Y-m', strtotime($r['dt'])),
            'desc' => clean($r['description']),
            'vendor' => clean($r['vendor_name'] ?: '-'),
            'account' => clean($r['payment_type'] ?: '-'),
            'in' => 0.0,
            'out' => $amount,
        ];
        $expenseTotal += $amount;
    }
}

// sort rows
if ($filter['sort'] === 'date_desc') {
    usort($rows, function($a,$b){ return strtotime($b['date']) <=> strtotime($a['date']); });
} else {
    usort($rows, function($a,$b){ return strtotime($a['date']) <=> strtotime($b['date']); });
}

/* -------------------- PDF CLASS -------------------- */
class PDF extends FPDF {
    public $project_name = '';

    function setProjectName($name) { $this->project_name = $name; }

    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial','I',8);
        $this->Cell(60, 6, $this->project_name, 0, 0, 'L');
        $this->Cell(60, 6, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        $this->Cell(60, 6, date('d-m-Y'), 0, 0, 'R');
    }

    // ✅ smaller font + keep inside cell (for header right side)
    function FitTextCell($w, $h, $txt, $border=1, $ln=0, $align='L', $fill=false, $maxSize=10, $minSize=7) {
        $txt = (string)$txt;
        $size = $maxSize;
        $this->SetFont('Arial','B',$size);
        while ($size > $minSize && $this->GetStringWidth($txt) > ($w - 2)) {
            $size--;
            $this->SetFont('Arial','B',$size);
        }
        $this->Cell($w, $h, $txt, $border, $ln, $align, $fill);
    }

    function WrapTextToLines($text, $maxWidth) {
        if ($maxWidth <= 0) return [$text];
        $words = explode(' ', (string)$text);
        $lines = [];
        $currentLine = '';
        foreach ($words as $word) {
            $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
            if ($this->GetStringWidth($testLine) > $maxWidth && $currentLine !== '') {
                $lines[] = $currentLine;
                $currentLine = $word;
            } else {
                $currentLine = $testLine;
            }
        }
        if ($currentLine !== '') $lines[] = $currentLine;
        return $lines;
    }
}

/* -------------------- CREATE PDF -------------------- */
$pdf = new PDF('P');
$pdf->setProjectName($project);
$pdf->AliasNbPages();
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

/* =========================================================
   HEADER (design)
   ✅ reduced font & reduced row height so text doesn't overflow
   ========================================================= */
$startX = 8;
$startY = 8;
$totalW = 194;

$rightW = 80;
$leftW  = $totalW - $rightW;

// ✅ reduced height
$rowH = 6;                 // was 7
$headerRows = 5;
$headerH = $headerRows * $rowH;

$pdf->Rect($startX, $startY, $totalW, $headerH);

// left title grey
$pdf->SetFillColor(220,220,220);
$pdf->Rect($startX, $startY, $leftW, $headerH, 'F');
$pdf->Rect($startX, $startY, $leftW, $headerH);

// title
$titleText = 'CASH FLOW';
if ($filter['type'] === 'expense') $titleText = 'EXPENSE CASH FLOW';
if ($filter['type'] === 'income')  $titleText = 'INCOME CASH FLOW';

$pdf->SetFont('Arial','B',16); // slightly smaller than before to match design and avoid tight fit
$pdf->SetXY($startX, $startY);
$pdf->Cell($leftW, $headerH, $titleText, 0, 0, 'C');

// right details table
$rx = $startX + $leftW;
$ry = $startY;

$labelW = 35;
$valueW = $rightW - $labelW;

$details = [
    ['Project', $project],
    ['Client', $client],
    ['PMC', $pmc],
    ['Scope of work', $scope_of_work],
    ['Date', $date_label],
];

for ($i=0; $i<count($details); $i++) {
    $pdf->SetXY($rx, $ry + ($i*$rowH));
    // ✅ reduced label font
    $pdf->SetFont('Arial','B',10); // was 11
    $pdf->Cell($labelW, $rowH, $details[$i][0], 1, 0, 'L');
    $val = clean($details[$i][1]);
    // ✅ reduced maxSize so it never overflows
    $pdf->FitTextCell($valueW, $rowH, $val, 1, 0, 'L', false, 10, 7);
}

// move below header block
$pdf->SetY($startY + $headerH + 8);

/* =========================================================
   TABLE (stable layout) + MONTH TOTAL row in Yellow
   ========================================================= */
$pdf->SetFont('Arial','B',8);
$pdf->SetFillColor(210,230,250);

$w = [18, 10, 52, 30, 20, 20, 22, 22];
$headers = ['DATE', 'SL', 'DESCRIPTION', 'VENDOR', 'ACCOUNT', 'INCOME', 'EXPENSE', 'BALANCE'];

foreach ($headers as $i=>$h) {
    $pdf->Cell($w[$i], 6, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont('Arial','',8);

$balance = 0.0;
$currentDate = '';
$sl = 1;
$lastDisplayedDate = '';
$rowHeightContent = 5;

// month tracking
$monthIncome = 0.0;
$monthExpense = 0.0;
$currentMonthKey = null;

// page break helper for month total row
$printTableHeader = function() use ($pdf, $headers, $w) {
    $pdf->SetFont('Arial','B',8);
    $pdf->SetFillColor(210,230,250);
    foreach ($headers as $i=>$h) {
        $pdf->Cell($w[$i], 6, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetFont('Arial','',8);
};

$printMonthTotalRow = function($monthKey, $mi, $me, $bal) use ($pdf, $w) {
    if (!$monthKey) return;

    // page break safety for this row (fixed height 6)
    if ($pdf->GetY() + 6 > 270) {
        $pdf->AddPage();
    }

    $label = 'MONTH TOTAL - ' . date('F Y', strtotime($monthKey . '-01'));

    $pdf->SetFont('Arial','B',8);
    $pdf->SetFillColor(255,255,153); // ✅ yellow highlight

    // DATE + SL empty
    $pdf->Cell($w[0], 6, '', 1, 0, 'C', true);
    $pdf->Cell($w[1], 6, '', 1, 0, 'C', true);

    // DESCRIPTION (put label)
    $pdf->Cell($w[2], 6, $label, 1, 0, 'L', true);

    // vendor / account empty
    $pdf->Cell($w[3], 6, '', 1, 0, 'C', true);
    $pdf->Cell($w[4], 6, '', 1, 0, 'C', true);

    // totals
    $pdf->Cell($w[5], 6, number_format($mi, 2, '.', ''), 1, 0, 'R', true);
    $pdf->Cell($w[6], 6, number_format($me, 2, '.', ''), 1, 0, 'R', true);
    $pdf->Cell($w[7], 6, number_format($bal, 2, '.', ''), 1, 1, 'R', true);

    $pdf->SetFont('Arial','',8);
};

foreach ($rows as $r) {

    // init month key
    if ($currentMonthKey === null) {
        $currentMonthKey = $r['month_key'];
        $monthIncome = 0.0;
        $monthExpense = 0.0;
    }

    // if month changed -> print previous month total row
    if ($r['month_key'] !== $currentMonthKey) {
        $printMonthTotalRow($currentMonthKey, $monthIncome, $monthExpense, $balance);
        $currentMonthKey = $r['month_key'];
        $monthIncome = 0.0;
        $monthExpense = 0.0;

        // if month total caused page break, reprint headers
        if ($pdf->GetY() < 20) {
            $printTableHeader();
        }
    }

    // update running + month totals
    $balance += $r['in'] - $r['out'];
    $monthIncome += $r['in'];
    $monthExpense += $r['out'];

    // reset SL when date changes
    if ($currentDate != $r['date']) {
        $sl = 1;
        $currentDate = $r['date'];
    }

    $desc = $r['desc'] ?: '';
    $vendor = $r['vendor'] ?: '-';
    $account = $r['account'] ?: '-';

    if (strlen($vendor) > 25) $vendor = substr($vendor, 0, 22) . '...';
    if (strlen($account) > 15) $account = substr($account, 0, 12) . '...';

    // height calc
    $descLines = $pdf->WrapTextToLines($desc, $w[2] - 2);
    $vendorLines = $pdf->WrapTextToLines($vendor, $w[3] - 2);
    $accountLines = $pdf->WrapTextToLines($account, $w[4] - 2);

    $maxLines = max(count($descLines), count($vendorLines), count($accountLines), 1);
    $maxH = $rowHeightContent * $maxLines;

    // page break safety
    if ($pdf->GetY() + $maxH > 270) {
        $pdf->AddPage();
        $printTableHeader();
        $sl = 1;
        $lastDisplayedDate = '';
        $currentDate = '';
    }

    // show date only once per date group
    $dateToDisplay = '';
    if ($lastDisplayedDate != $r['date']) {
        $dateToDisplay = date('d-m-Y', strtotime($r['date']));
        $lastDisplayedDate = $r['date'];
    }

    // DATE
    $pdf->Cell($w[0], $maxH, $dateToDisplay, 1, 0, 'C');
    // SL
    $pdf->Cell($w[1], $maxH, $sl, 1, 0, 'C');
    $sl++;

    // DESCRIPTION (MultiCell)
    $descXPos = $pdf->GetX();
    $descYPos = $pdf->GetY();
    $pdf->MultiCell($w[2], $rowHeightContent, $desc, 1, 'L');
    $newY = $descYPos + $maxH;

    // continue row
    $pdf->SetXY($descXPos + $w[2], $descYPos);
    $pdf->Cell($w[3], $maxH, $vendor, 1, 0, 'L');
    $pdf->Cell($w[4], $maxH, $account, 1, 0, 'L');

    $incomeText  = $r['in']  ? number_format($r['in'], 2, '.', '') : '';
    $expenseText = $r['out'] ? number_format($r['out'], 2, '.', '') : '';
    $balanceText = number_format($balance, 2, '.', '');

    $pdf->Cell($w[5], $maxH, $incomeText, 1, 0, 'R');
    $pdf->Cell($w[6], $maxH, $expenseText, 1, 0, 'R');
    $pdf->Cell($w[7], $maxH, $balanceText, 1, 1, 'R');

    $pdf->SetY($newY);
}

// print last month total row
$printMonthTotalRow($currentMonthKey, $monthIncome, $monthExpense, $balance);

/* -------------------- GRAND TOTALS ROW -------------------- */
if ($pdf->GetY() + 6 > 270) {
    $pdf->AddPage();
    $printTableHeader();
}

$pdf->SetFont('Arial','B',8);
$pdf->SetFillColor(240,240,240);

$pdf->Cell($w[0], 6, '', 1, 0, 'C', true);
$pdf->Cell($w[1], 6, '', 1, 0, 'C', true);
$pdf->Cell($w[2], 6, 'TOTALS', 1, 0, 'R', true);
$pdf->Cell($w[3], 6, '', 1, 0, 'C', true);
$pdf->Cell($w[4], 6, '', 1, 0, 'C', true);

$pdf->Cell($w[5], 6, number_format($incomeTotal, 2, '.', ''), 1, 0, 'R', true);
$pdf->Cell($w[6], 6, number_format($expenseTotal, 2, '.', ''), 1, 0, 'R', true);

$finalBalance = $incomeTotal - $expenseTotal;
$pdf->Cell($w[7], 6, number_format($finalBalance, 2, '.', ''), 1, 1, 'R', true);

/* -------------------- OUTPUT -------------------- */
$filename = 'report_' . date('Ymd_His') . '.pdf';
$pdf->Output('D', $filename);
exit;
