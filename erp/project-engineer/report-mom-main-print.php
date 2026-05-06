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
if (!$conn) {
    die("DB failed");
}

$viewId = (int)($_GET['view'] ?? 0);
if ($viewId <= 0) {
    die("Invalid ID");
}

function pdf_text($v): string
{
    $s = trim((string)$v);
    $s = preg_replace('/\s+/', ' ', $s);

    if ($s === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
        if ($converted !== false) {
            $s = $converted;
        }
    }

    return $s;
}

function safe_date($v): string
{
    if (empty($v)) return '';
    $ts = strtotime($v);
    return $ts ? date('d-m-Y', $ts) : (string)$v;
}

function mom_mom_no_short($momNo): string
{
    if (preg_match('/\/(\d{3})$/', (string)$momNo, $m)) {
        return $m[1];
    }
    return (string)$momNo;
}

function mom_page1_attendee_group_label(array $group): string
{
    $firm = trim((string)($group['attendee_firm'] ?? ''));
    $type = trim((string)($group['attendee_type'] ?? ''));

    if ($type === 'Client') {
        return 'Client';
    }

    if ($firm !== '') {
        return $firm;
    }

    return $type !== '' ? $type : 'Others';
}

function mom_page1_attendee_group_value(array $items): string
{
    $names = [];
    foreach ($items as $r) {
        $name = trim((string)($r['attendee_name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return implode(', ', $names);
}

function short_code_from_firm(string $firm): string
{
    $firm = trim($firm);
    if ($firm === '') return '';

    $map = [
        'M/s. UKB Construction Management Pvt Ltd' => 'UKB',
        'M/s. MYVN Architecture' => 'MYVN',
        'M/s. Miracle Marbles' => 'MM',
        'M/s. Madurai Air Systems' => 'MAS',
        'M/s. Profx' => 'PROFX',
        'M/s. Capital Interiors & Constructors' => 'CIC',
        'M/s. Capital Interiors & Constructors (Civil Work)' => 'CIC',
        'M/s. Sankar Electricals' => 'SE',
        'M/s. Pr Plumbing Works' => 'PR',
        'M/s. Crescent Enterprises Works' => 'CE',
        'M/s. JP Interior' => 'JP',
    ];

    foreach ($map as $k => $v) {
        if (strcasecmp($k, $firm) === 0) {
            return $v;
        }
    }

    $clean = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $firm);
    $parts = preg_split('/\s+/', trim((string)$clean));
    $abbr = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        if (preg_match('/^[A-Za-z0-9]/', $p)) {
            $abbr .= strtoupper($p[0]);
        }
    }
    return $abbr !== '' ? substr($abbr, 0, 8) : '';
}

// ===== FETCH MAIN =====
$stmt = mysqli_prepare($conn, "SELECT * FROM mom_main WHERE id=?");
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$mom = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$mom) {
    die("No data");
}

// ===== FETCH DETAILS =====
$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM mom_details
     WHERE mom_main_id=?
     ORDER BY FIELD(section_type,'ATTENDEE','SECTION_I','SECTION_II','SECTION_III','SECTION_IV'),
              section_code, subsection_code, sl_no, id"
);
mysqli_stmt_bind_param($stmt, "i", $viewId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

mysqli_close($conn);

// ===== SPLIT DATA =====
$attendeeRows = [];
$sectionRows = [
    'SECTION_I' => [],
    'SECTION_II' => [],
    'SECTION_III' => [],
    'SECTION_IV' => [],
];

foreach ($rows as $r) {
    if (($r['section_type'] ?? '') === 'ATTENDEE') {
        $attendeeRows[] = $r;
    } elseif (isset($sectionRows[$r['section_type']])) {
        $sectionRows[$r['section_type']][] = $r;
    }
}

// Group attendees by firm/type
$attendeeGroups = [];
foreach ($attendeeRows as $r) {
    $key = trim((string)($r['attendee_firm'] ?? ''));
    if ($key === '') {
        $key = trim((string)($r['attendee_type'] ?? 'Others'));
    }
    if ($key === '') {
        $key = 'Others';
    }

    if (!isset($attendeeGroups[$key])) {
        $attendeeGroups[$key] = [
            'attendee_firm' => $r['attendee_firm'] ?? '',
            'attendee_type' => $r['attendee_type'] ?? '',
            'items' => [],
        ];
    }
    $attendeeGroups[$key]['items'][] = $r;
}

// Build distribution by mail
$distributionCodes = [];
foreach ($attendeeGroups as $group) {
    $code = short_code_from_firm((string)($group['attendee_firm'] ?? ''));
    if ($code !== '') {
        $distributionCodes[] = $code;
    }
}
$distributionCodes = array_values(array_unique($distributionCodes));
if (!in_array('CLIENT', $distributionCodes, true)) {
    array_unshift($distributionCodes, 'CLIENT');
}
$distributionLine = implode(', ', $distributionCodes);

// ===== PDF CLASS =====
class MOMPDF extends FPDF
{
    public array $mom = [];

    public function setData(array $mom): void
    {
        $this->mom = $mom;
    }

    function Header()
    {
        $project = strtoupper(pdf_text((string)($this->mom['project_name'] ?? '')));
        $code = 'UKB_TM10_MOM';

        $this->SetFont('Arial', 'B', 10);
        $this->SetY(8);
        $this->Cell(0, 5, $code, 0, 1, 'R');

        $this->Ln(6);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(0, 6, $project, 0, 1, 'C');
        $this->Cell(0, 6, 'MINUTES OF MEETING', 0, 1, 'C');
        $this->Ln(10);
    }

    function Footer()
    {
        $this->SetY(-16);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(120, 120, 120);

        $this->Cell(0, 5, 'U K B Construction Management Pvt Ltd', 0, 1, 'C');
        $this->Cell(0, 5, '# 697/75, 3rd Floor, 30th Cross, Jayanagar 4th T Block, Bangalore-560041', 0, 1, 'C');
        $this->Cell(0, 5, 'Ph. +91 80 26630203, 26644644 Email: ukb.bangalore@gmail.com', 0, 1, 'C');
        $this->Cell(0, 5, $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function fitText(string $text, float $width): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if ($this->GetStringWidth($text) <= $width) {
            return $text;
        }

        $ellipsis = '...';
        $ellipsisWidth = $this->GetStringWidth($ellipsis);
        $maxWidth = max(0, $width - $ellipsisWidth);

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            while ($text !== '' && $this->GetStringWidth($text) > $maxWidth) {
                $text = mb_substr($text, 0, mb_strlen($text) - 1);
            }
        } else {
            while ($text !== '' && $this->GetStringWidth($text) > $maxWidth) {
                $text = substr($text, 0, -1);
            }
        }

        return rtrim($text) . $ellipsis;
    }

    function NbLines($w, $txt): int
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }

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
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
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

    function drawFixedRow(array $cells, array $widths, int $h = 10, string $fontStyle = 'B', int $fontSize = 10): void
    {
        $x = $this->GetX();
        $y = $this->GetY();

        for ($i = 0; $i < count($widths); $i++) {
            $w = (float)$widths[$i];
            $this->Rect($x, $y, $w, $h);
            $this->SetXY($x + 1, $y + 2);
            $this->SetFont('Arial', $fontStyle, $fontSize);
            $txt = $this->fitText((string)($cells[$i] ?? ''), $w - 2);
            $this->Cell($w - 2, $h - 4, $txt, 0, 0, 'L');
            $x += $w;
        }

        $this->SetXY($this->lMargin, $y + $h);
    }

    function drawMergedRow(string $title, string $text, int $leftWidth = 25, int $rightWidth = 163, int $lineHeight = 6): void
    {
        $x = $this->GetX();
        $y = $this->GetY();

        $text = pdf_text($text);
        $lines = max(1, $this->NbLines($rightWidth - 2, $text));
        $rowHeight = max($lineHeight * $lines, 10);

        $this->Rect($x, $y, $leftWidth, $rowHeight);
        $this->Rect($x + $leftWidth, $y, $rightWidth, $rowHeight);

        $this->SetFont('Arial', 'B', 10);
        $this->SetXY($x + $leftWidth + 1, $y + 2);
        $this->MultiCell($rightWidth - 2, $lineHeight, $text, 0, 'L');

        $this->SetY($y + $rowHeight);
    }

    function drawTwoValueRow(string $leftText, string $rightText, int $leftWidth = 25, int $midWidth = 72, int $rightWidth = 91, bool $boldLeft = true, bool $boldRight = false): void
    {
        $x = $this->GetX();
        $y = $this->GetY();
        $lineHeight = 6;

        $leftText = pdf_text($leftText);
        $rightText = pdf_text($rightText);

        $leftLines = max(1, $this->NbLines($midWidth - 2, $leftText));
        $rightLines = max(1, $this->NbLines($rightWidth - 2, $rightText));
        $rowHeight = max($lineHeight * max($leftLines, $rightLines), 10);

        $this->Rect($x, $y, $leftWidth, $rowHeight);

        $this->Rect($x + $leftWidth, $y, $midWidth, $rowHeight);
        $this->SetXY($x + $leftWidth + 1, $y + 2);
        $this->SetFont('Arial', $boldLeft ? 'B' : '', 10);
        $this->MultiCell($midWidth - 2, $lineHeight, $leftText, 0, 'L');

        $this->Rect($x + $leftWidth + $midWidth, $y, $rightWidth, $rowHeight);
        $this->SetXY($x + $leftWidth + $midWidth + 1, $y + 2);
        $this->SetFont('Arial', $boldRight ? 'B' : '', 10);
        $this->MultiCell($rightWidth - 2, $lineHeight, $rightText, 0, 'L');

        $this->SetY($y + $rowHeight);
    }

    function blueTitleRow(string $title): void
    {
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(0, 0, 220);
        $this->Cell(25, 10, '', 1, 0);
        $this->Cell(163, 10, pdf_text($title), 1, 1, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    function blankRow(int $h = 10): void
    {
        $this->Cell(188, $h, '', 1, 1);
    }

    function tableHeader(): void
    {
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(12, 8, 'SL.NO', 1, 0, 'C');
        $this->Cell(84, 8, 'DISCUSSIONS/DECISIONS', 1, 0, 'C');
        $this->Cell(47, 8, 'ACTION BY', 1, 0, 'C');
        $this->Cell(47, 8, 'DEADLINE', 1, 1, 'C');
    }

    function detailRow($sl, $desc, $by, $deadline): void
    {
        $desc = trim((string)$desc);
        $by = trim((string)$by);
        $deadline = trim((string)$deadline);

        $x = $this->GetX();
        $y = $this->GetY();

        $w1 = 12;
        $w2 = 84;
        $w3 = 47;
        $w4 = 47;
        $lineH = 8;

        $descLines = max(1, $this->NbLines($w2 - 2, $desc));
        $byLines = max(1, $this->NbLines($w3 - 2, $by));
        $deadlineLines = max(1, $this->NbLines($w4 - 2, $deadline));
        $rowH = $lineH * max($descLines, $byLines, $deadlineLines, 1);

        if ($this->GetY() + $rowH > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->tableHeader();
            $x = $this->GetX();
            $y = $this->GetY();
        }

        $this->Rect($x, $y, $w1, $rowH);
        $this->Rect($x + $w1, $y, $w2, $rowH);
        $this->Rect($x + $w1 + $w2, $y, $w3, $rowH);
        $this->Rect($x + $w1 + $w2 + $w3, $y, $w4, $rowH);

        $this->SetFont('Arial', '', 9);

        $this->SetXY($x, $y + 1);
        $this->MultiCell($w1, $lineH, (string)$sl, 0, 'C');

        $this->SetXY($x + $w1 + 1, $y + 1);
        $this->MultiCell($w2 - 2, $lineH, pdf_text($desc), 0, 'L');

        $this->SetXY($x + $w1 + $w2 + 1, $y + 1);
        $this->MultiCell($w3 - 2, $lineH, pdf_text($by), 0, 'C');

        $this->SetXY($x + $w1 + $w2 + $w3 + 1, $y + 1);
        $this->MultiCell($w4 - 2, $lineH, pdf_text($deadline), 0, 'C');

        $this->SetY($y + $rowH);
    }
}

// ===== INIT =====
$pdf = new MOMPDF('P', 'mm', 'A4');
$pdf->SetMargins(8, 10, 8);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AliasNbPages();
$pdf->setData($mom);

// ================= PAGE 1 =================
$pdf->AddPage();

// top rows
$pdf->drawFixedRow(
    ['Project', pdf_text($mom['project_name']), 'MOM No', mom_mom_no_short($mom['mom_no'])],
    [25, 72, 22, 69],
    10
);

$pdf->drawFixedRow(
    ['Date/Place', pdf_text($mom['meeting_date_place']), 'Time', pdf_text($mom['meeting_time'])],
    [25, 72, 22, 69],
    10
);

// blank row
$pdf->blankRow(8);

// Agenda
$pdf->blueTitleRow('AGENDA');
$pdf->drawMergedRow('AGENDA', pdf_text($mom['agenda']), 25, 163, 6);

// spacer
$pdf->blankRow(8);

// ================= ATTENDEES =================
$pdf->blueTitleRow('ATTENDEES');

foreach ($attendeeGroups as $group) {
    $label = pdf_text(mom_page1_attendee_group_label($group));
    $value = pdf_text(mom_page1_attendee_group_value($group['items']));

    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $w1 = 25;
    $w2 = 72;
    $w3 = 91;
    $lineHeight = 6;

    $labelLines = max(1, $pdf->NbLines($w2 - 2, $label));
    $valueLines = max(1, $pdf->NbLines($w3 - 2, $value));
    $rowHeight = max($lineHeight * max($labelLines, $valueLines), 10);

    $pdf->Rect($x, $y, $w1, $rowHeight);

    $pdf->Rect($x + $w1, $y, $w2, $rowHeight);
    $pdf->SetXY($x + $w1 + 1, $y + 2);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->MultiCell($w2 - 2, $lineHeight, $label, 0, 'L');

    $pdf->Rect($x + $w1 + $w2, $y, $w3, $rowHeight);
    $pdf->SetXY($x + $w1 + $w2 + 1, $y + 2);
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell($w3 - 2, $lineHeight, $value, 0, 'L');

    $pdf->SetY($y + $rowHeight);
}

$pdf->blankRow(8);

// ================= DISTRIBUTION =================
$pdf->blueTitleRow('DISTRIBUTION BY MAIL');

function drawRow($pdf, $left, $right, $boldLeft = true, $boldRight = false, $blue = false)
{
    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $w1 = 25;
    $w2 = 72;
    $w3 = 91;
    $lineHeight = 6;

    $left = pdf_text($left);
    $right = pdf_text($right);
    if ($blue) {
    $pdf->SetTextColor(0, 0, 220);
} else {
    $pdf->SetTextColor(0, 0, 0);
}

    $leftLines = max(1, $pdf->NbLines($w2 - 2, $left));
    $rightLines = max(1, $pdf->NbLines($w3 - 2, $right));
    $rowHeight = max($lineHeight * max($leftLines, $rightLines), 10);

    $pdf->Rect($x, $y, $w1, $rowHeight);

    $pdf->Rect($x + $w1, $y, $w2, $rowHeight);
    $pdf->SetXY($x + $w1 + 1, $y + 2);
    $pdf->SetFont('Arial', $boldLeft ? 'B' : '', 10);
    $pdf->MultiCell($w2 - 2, $lineHeight, $left, 0, 'L');

    $pdf->Rect($x + $w1 + $w2, $y, $w3, $rowHeight);
    $pdf->SetXY($x + $w1 + $w2 + 1, $y + 2);
    $pdf->SetFont('Arial', $boldRight ? 'B' : '', 10);
    $pdf->MultiCell($w3 - 2, $lineHeight, $right, 0, 'L');
$pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($y + $rowHeight);
}

drawRow($pdf, 'All Attendees', '');
drawRow($pdf, pdf_text($distributionLine), '');
// $pdf->blueTitleRow('ISSUED BY / DATE');


drawRow($pdf, 'ISSUED BY', 'DATE', true, true, true);
drawRow(
    $pdf,
    pdf_text($mom['issued_by']),
    pdf_text(safe_date($mom['issued_date'])),
    false,
    false
);

// ================= PAGE 2+ =================
if (
    !empty($sectionRows['SECTION_I']) ||
    !empty($sectionRows['SECTION_II']) ||
    !empty($sectionRows['SECTION_III']) ||
    !empty($sectionRows['SECTION_IV'])
) {
    $pdf->AddPage();

    $sectionMap = [
        'SECTION_I' => ['I', 'ARCHITECTS / CONSULTANTS DELIVERABLES'],
        'SECTION_II' => ['II', 'PMC DELIVERABLES'],
        'SECTION_III' => ['III', 'CONTRACTORS / VENDORS DELIVERABLES'],
        'SECTION_IV' => ['IV', 'OTHERS'],
    ];

    foreach ($sectionMap as $sectionKey => [$code, $title]) {
        $items = $sectionRows[$sectionKey] ?? [];
        if (empty($items)) {
            continue;
        }

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(12, 8, $code, 1, 0, 'C');
        $pdf->SetTextColor(0, 0, 160);
        $pdf->Cell(178, 8, '  ' . strtoupper($title), 1, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $currentSub = '';
        $headerPrinted = false;

        foreach ($items as $r) {
            $subCode = trim((string)($r['subsection_code'] ?? ''));
            $subTitle = trim((string)($r['subsection_title'] ?? ''));

            if ($sectionKey === 'SECTION_III') {
                $subKey = $subCode . '|' . $subTitle;
                if ($subKey !== $currentSub && $subTitle !== '') {
                    $currentSub = $subKey;
                    $pdf->SetFont('Arial', 'B', 10);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Cell(12, 8, $subCode, 1, 0, 'C');
                    $pdf->SetTextColor(0, 0, 160);
                    $pdf->Cell(178, 8, '  ' . strtoupper($subTitle), 1, 1, 'L');
                    $pdf->SetTextColor(0, 0, 0);

                    $pdf->tableHeader();
                    $headerPrinted = true;
                }
            } else {
                if (!$headerPrinted) {
                    $pdf->tableHeader();
                    $headerPrinted = true;
                }
            }

            $desc = trim((string)($r['description'] ?? ''));
            $actionBy = trim((string)($r['responsible_party'] ?? ''));
            $deadline = safe_date($r['deadline'] ?? '');

            $pdf->detailRow(
                (int)($r['sl_no'] ?? 0),
                $desc,
                $actionBy,
                $deadline
            );
        }

        $pdf->Ln(4);
    }
}

// ===== OUTPUT =====
while (ob_get_level()) {
    ob_end_clean();
}

$filename = "MOM_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $mom['mom_no'] ?? 'report') . ".pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');

$pdf->Output('I', $filename);
exit;