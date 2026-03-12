<?php
// admin/download-sample-payslip-pdf.php
session_start();
require('libs/fpdf.php');
require_once __DIR__ . '/includes/db-config.php';

if (empty($_SESSION['employee_id'])) {
    die("Unauthorized");
}

$employeeId = (int)$_SESSION['employee_id'];

$conn = get_db_connection();
if (!$conn) die("DB Error");

// Fetch employee basic details (you can replace/extend later with payroll tables)
$stmt = mysqli_prepare($conn, "
    SELECT e.*, r.full_name AS reporting_person
    FROM employees e
    LEFT JOIN employees r ON e.reporting_to = r.id
    WHERE e.id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $employeeId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$emp = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$emp) die("Employee not found");

function v($val){
    return (!empty($val) && $val !== '0000-00-00') ? (string)$val : '-';
}
function fmtMoney($n){
    return number_format((float)$n, 2);
}
function safeDate($date){
    if(empty($date) || $date == '0000-00-00') return '-';
    return date('d-m-Y', strtotime($date));
}

// ------------------------------------------------------------
// SAMPLE PAYSLIP DATA (Replace later from DB)
// ------------------------------------------------------------
$company = [
    'name'    => 'TEK-C ENGINEERING PRIVATE LIMITED',
    'address' => 'No. 24, Sample Street, Chennai, Tamil Nadu - 600001',
    'phone'   => '+91 98765 43210',
    'email'   => 'hr@tekc.example.com'
];

$payslip = [
    'month_label'      => 'January 2026',
    'salary_month'     => '2026-01',
    'pay_date'         => '2026-02-05',
    'employee_name'    => v($emp['full_name']),
    'employee_code'    => v($emp['employee_code']),
    'designation'      => v($emp['designation']),
    'department'       => v($emp['department']),
    'date_of_joining'  => safeDate($emp['date_of_joining']),
    'pan'              => v($emp['pancard_number'] ?? ''),
    'uan'              => '100123456789',      // sample
    'pf_no'            => 'PF/TN/12345/000123',// sample
    'esi_no'           => 'ESI1234567890',     // sample
    'bank_name'        => 'State Bank of India', // sample
    'bank_account'     => v($emp['bank_account_number'] ?? '123456789012'),
    'ifsc'             => v($emp['ifsc_code'] ?? 'SBIN0001234'),
    'worked_days'      => 26.0,
    'paid_days'        => 26.0,
    'lop_days'         => 0.0
];

// Earnings (sample)
$earnings = [
    ['name' => 'Basic Pay',           'amount' => 18000.00],
    ['name' => 'House Rent Allowance','amount' => 9000.00],
    ['name' => 'Conveyance Allowance','amount' => 1600.00],
    ['name' => 'Medical Allowance',   'amount' => 1250.00],
    ['name' => 'Special Allowance',   'amount' => 6150.00],
    ['name' => 'Other Allowance',     'amount' => 1000.00],
];

// Deductions (sample)
$deductions = [
    ['name' => 'Provident Fund (PF)', 'amount' => 2160.00],
    ['name' => 'ESI',                 'amount' => 0.00],
    ['name' => 'Professional Tax',    'amount' => 200.00],
    ['name' => 'TDS',                 'amount' => 500.00],
    ['name' => 'LOP Deduction',       'amount' => 0.00],
    ['name' => 'Other Deduction',     'amount' => 100.00],
];

$totalEarnings   = array_sum(array_column($earnings, 'amount'));
$totalDeductions = array_sum(array_column($deductions, 'amount'));
$netPay          = $totalEarnings - $totalDeductions;

// ------------------------------------------------------------
// PDF
// ------------------------------------------------------------
class PDF extends FPDF
{
    public $company = [];
    public $payslip = [];

    function Header()
    {
        // Company header
        $this->SetFont('Arial','B',14);
        $this->Cell(0,7, $this->company['name'] ?? 'Company Name', 0, 1, 'C');

        $this->SetFont('Arial','',9);
        $this->Cell(0,5, $this->company['address'] ?? '', 0, 1, 'C');
        $contact = trim(($this->company['phone'] ?? '') . '   |   ' . ($this->company['email'] ?? ''));
        $this->Cell(0,5, $contact, 0, 1, 'C');

        $this->Ln(2);

        // Title
        $this->SetFont('Arial','B',12);
        $title = 'PAYSLIP FOR ' . strtoupper($this->payslip['month_label'] ?? '');
        $this->Cell(0,8, $title, 1, 1, 'C');
        $this->Ln(2);
    }

    function Footer()
    {
        $this->SetY(-18);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,5,'This is a system generated sample payslip. Signature not required.',0,1,'C');
        $this->Cell(0,5,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }

    function SectionTitle($title)
    {
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial','B',10);
        $this->Cell(0,7,$title,1,1,'L',true);
    }

    function InfoRow2Col($l1, $v1, $l2, $v2)
    {
        $this->SetFont('Arial','B',9);
        $this->Cell(35,7,$l1,1,0);
        $this->SetFont('Arial','',9);
        $this->Cell(60,7,$v1,1,0);

        $this->SetFont('Arial','B',9);
        $this->Cell(35,7,$l2,1,0);
        $this->SetFont('Arial','',9);
        $this->Cell(60,7,$v2,1,1);
    }

    function SalaryTableHeader()
    {
        $this->SetFont('Arial','B',9);
        $this->SetFillColor(245,245,245);

        // Left side (Earnings)
        $this->Cell(70,7,'EARNINGS',1,0,'C',true);
        $this->Cell(25,7,'AMOUNT',1,0,'C',true);

        // Right side (Deductions)
        $this->Cell(70,7,'DEDUCTIONS',1,0,'C',true);
        $this->Cell(25,7,'AMOUNT',1,1,'C',true);
    }

    function SalaryRow($earnName, $earnAmt, $dedName, $dedAmt)
    {
        $this->SetFont('Arial','',9);

        $this->Cell(70,7,$earnName,1,0);
        $this->Cell(25,7,$earnAmt,1,0,'R');

        $this->Cell(70,7,$dedName,1,0);
        $this->Cell(25,7,$dedAmt,1,1,'R');
    }

    function AmountInWordsRow($text)
    {
        $this->SetFont('Arial','B',9);
        $this->Cell(40,8,'Net Pay (in words)',1,0);

        $this->SetFont('Arial','',9);
        $this->Cell(150,8,$text,1,1);
    }
}

// simple number to words (Indian-style basic fallback)
// You can replace with a better function later if needed
function amountInWords($amount)
{
    $amount = round((float)$amount, 2);
    $rupees = floor($amount);
    $paise  = (int)round(($amount - $rupees) * 100);

    // Simple English converter for demo
    $fmt = number_format($rupees, 0);
    $words = $fmt . ' Rupees'; // sample placeholder text

    if ($paise > 0) {
        $words .= ' and ' . $paise . ' Paise';
    }

    return $words . ' Only';
}

$pdf = new PDF('P','mm','A4');
$pdf->AliasNbPages();
$pdf->SetMargins(10,10,10);
$pdf->SetAutoPageBreak(true, 20);
$pdf->company = $company;
$pdf->payslip = $payslip;
$pdf->AddPage();

// ---------------- Employee & Payslip Information ----------------
$pdf->SectionTitle('Employee Details');
$pdf->InfoRow2Col('Employee Name', $payslip['employee_name'], 'Employee Code', $payslip['employee_code']);
$pdf->InfoRow2Col('Department', $payslip['department'], 'Designation', $payslip['designation']);
$pdf->InfoRow2Col('Date of Joining', $payslip['date_of_joining'], 'Pay Date', safeDate($payslip['pay_date']));
$pdf->InfoRow2Col('Bank A/c No.', $payslip['bank_account'], 'IFSC Code', $payslip['ifsc']);
$pdf->InfoRow2Col('PAN', $payslip['pan'], 'UAN', $payslip['uan']);
$pdf->InfoRow2Col('PF No.', $payslip['pf_no'], 'ESI No.', $payslip['esi_no']);

$pdf->Ln(2);

$pdf->SectionTitle('Attendance / Pay Summary');
$pdf->InfoRow2Col('Salary Month', $payslip['month_label'], 'Worked Days', (string)$payslip['worked_days']);
$pdf->InfoRow2Col('Paid Days', (string)$payslip['paid_days'], 'LOP Days', (string)$payslip['lop_days']);

$pdf->Ln(3);

// ---------------- Earnings / Deductions Table ----------------
$pdf->SalaryTableHeader();

$maxRows = max(count($earnings), count($deductions));
for ($i = 0; $i < $maxRows; $i++) {
    $eName = $earnings[$i]['name'] ?? '';
    $eAmt  = isset($earnings[$i]['amount']) ? fmtMoney($earnings[$i]['amount']) : '';

    $dName = $deductions[$i]['name'] ?? '';
    $dAmt  = isset($deductions[$i]['amount']) ? fmtMoney($deductions[$i]['amount']) : '';

    $pdf->SalaryRow($eName, $eAmt, $dName, $dAmt);
}

// Totals row
$pdf->SetFont('Arial','B',9);
$pdf->Cell(70,8,'Total Earnings',1,0,'R');
$pdf->Cell(25,8,fmtMoney($totalEarnings),1,0,'R');
$pdf->Cell(70,8,'Total Deductions',1,0,'R');
$pdf->Cell(25,8,fmtMoney($totalDeductions),1,1,'R');

$pdf->Ln(2);

// Net Pay Highlight
$pdf->SetFont('Arial','B',11);
$pdf->SetFillColor(230, 255, 230);
$pdf->Cell(165,9,'NET PAY',1,0,'R',true);
$pdf->Cell(25,9,fmtMoney($netPay),1,1,'R',true);

$pdf->Ln(2);

// Amount in words
$pdf->AmountInWordsRow(amountInWords($netPay));

// Notes
$pdf->Ln(3);
$pdf->SetFont('Arial','',8);
$pdf->MultiCell(0,5,
    "Note: This is a SAMPLE payslip generated for layout/demo purpose. "
  . "Replace the sample earnings, deductions, attendance and statutory details with database values later."
);

// Output download
$code = !empty($emp['employee_code']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $emp['employee_code']) : 'EMP';
$filename = 'Sample_Payslip_' . $code . '_' . str_replace(' ', '_', $payslip['month_label']) . '.pdf';

$pdf->Output('D', $filename);
exit;
?>