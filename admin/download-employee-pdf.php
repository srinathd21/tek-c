<?php
require('libs/fpdf.php');
require_once __DIR__ . '/includes/db-config.php';

$conn = get_db_connection();
if (!$conn) die("DB Connection Failed");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die("Invalid ID");

// Fetch employee with reporting manager name
$stmt = mysqli_prepare($conn, "
    SELECT e.*, r.full_name AS reporting_person
    FROM employees e
    LEFT JOIN employees r ON e.reporting_to = r.id
    WHERE e.id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$emp = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$emp) die("Employee not found");

// Helper function
function v($val){
    return (!empty($val) && $val !== '0000-00-00') ? $val : '-';
}

// Format date
function formatDate($date){
    if(empty($date) || $date == '0000-00-00') return '-';
    return date('d-m-Y', strtotime($date));
}

// Photo path
$photoPath = '';
if (!empty($emp['photo'])) {
    $photoPath = __DIR__ . '/' . $emp['photo'];
}

// ---------------- PDF CLASS ----------------
class PDF extends FPDF
{
   function Header()
{
    // Title
    $this->SetY(8);
    $this->SetFont('Arial','B',14);
    $this->Cell(0,8,'Employee Details Form',0,1,'C');

    // Photo Box
    $this->SetXY(165,20);
    $this->Cell(30,30,'',1);

    // IMPORTANT: Move cursor below photo
    $this->SetY(45);   // 👈 this prevents overlap
}

    function SectionTitle($title)
    {
        $this->SetFont('Arial','B',11);
        $this->Ln(4);
        $this->Cell(0,6,$title,0,1,'L');
    }

    function RowData($label, $value)
    {
        $this->SetFont('Arial','',10);
        $this->Cell(65,7,$label,1);
        $this->Cell(125,7,$value,1);
        $this->Ln();
    }

    function RowMulti($label, $value)
    {
        $this->SetFont('Arial','',10);
        $this->Cell(65,7,$label,1);
        $x = $this->GetX();
        $y = $this->GetY();
        $this->MultiCell(125,7,$value,1);
    }
}

$pdf = new PDF('P','mm','A4');
$pdf->SetMargins(10,10,10);
$pdf->AddPage();
$pdf->SetAutoPageBreak(false);

$pdf->SetY(48);  // start content below photo

if (!empty($photoPath) && file_exists($photoPath)) {
    $pdf->Image($photoPath, 167, 21, 26, 26);
}

/* ================= IDENTITY ================= */
$pdf->SectionTitle('Identity Details');
$pdf->RowData('Employee Full Name', v($emp['full_name']));
$pdf->RowData('Employee Code', v($emp['employee_code']));
$pdf->RowData('Date of Birth', formatDate($emp['date_of_birth']));
$pdf->RowData('Gender', v($emp['gender']));
$pdf->RowData('Blood Group', v($emp['blood_group']));

/* ================= CONTACT ================= */
$pdf->SectionTitle('Contact Details');
$pdf->RowData('Mobile Number', v($emp['mobile_number']));
$pdf->RowData('Email Address', v($emp['email']));
$pdf->RowData('Emergency Contact Name', v($emp['emergency_contact_name']));
$pdf->RowData('Emergency Contact Phone', v($emp['emergency_contact_phone']));
$pdf->RowMulti('Current Address', v($emp['current_address']));

/* ================= EMPLOYMENT ================= */
$pdf->SectionTitle('Employment Details');
$pdf->RowData('Date of Joining', formatDate($emp['date_of_joining']));
$pdf->RowData('Department', v($emp['department']));
$pdf->RowData('Designation', v($emp['designation']));
$pdf->RowData('Reporting Manager', v($emp['reporting_person']));
$pdf->RowData('Work Location', v($emp['work_location']));
$pdf->RowData('Site Name', v($emp['site_name']));
$pdf->RowData('Employee Status', strtoupper(v($emp['employee_status'])));

/* ================= COMPLIANCE ================= */
$pdf->SectionTitle('Compliance / Verification');
$pdf->RowData('Aadhaar Card Number', v($emp['aadhar_card_number']));
$pdf->RowData('PAN Card Number', v($emp['pancard_number']));

/* ================= PAYROLL ================= */
$pdf->SectionTitle('Payroll / Banking');
$pdf->RowData('Bank Account Number', v($emp['bank_account_number']));
$pdf->RowData('IFSC Code', v($emp['ifsc_code']));

/* ================= SIGNATURE ================= */
$pdf->SetY(250);
$pdf->SetFont('Arial','',10);
$pdf->Ln(15);
$pdf->SetFont('Arial','',10);
$pdf->Cell(50,6,'Signature of Employee:');
$pdf->SetX($pdf->GetX() - 10);
$pdf->Cell(80,6,'__________________________',0,1);

$pdf->Ln(4);
$pdf->Cell(15,6,'Date:');
$pdf->SetX($pdf->GetX() - 4);
$pdf->Cell(60,6,'________________',0,1);

// Force download
$pdf->Output('D', 'Employee_'.$emp['employee_code'].'.pdf');
exit;
?>