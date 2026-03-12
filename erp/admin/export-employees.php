<?php
session_start();
require('libs/fpdf.php');
require_once 'includes/db-config.php';

$conn = get_db_connection();
if (!$conn) die("Database connection failed.");

$format = $_POST['export_format'] ?? '';

if ($format !== 'pdf') {
    die("Only PDF export implemented.");
}

// Fetch employees
$result = mysqli_query($conn, "
    SELECT full_name, employee_code, designation, department,
           mobile_number, email, work_location,
           employee_status, date_of_joining
    FROM employees
    ORDER BY date_of_joining DESC
");

if (!$result) {
    die("Error fetching employees: " . mysqli_error($conn));
}

$employees = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_free_result($result);

// Format date helper
function formatDate($date){
    if(empty($date) || $date == '0000-00-00') return '-';
    return date('d-m-Y', strtotime($date));
}

// ---------------- PDF CLASS ----------------
class PDF extends FPDF
{
    private $colWidths = [
        'name' => 35,
        'code' => 22,
        'designation' => 45,   // Increased width
        'dept' => 18,
        'mobile' => 25,
        'email' => 40,
        'status' => 20,
        'joining' => 25
    ];

    function Header()
    {
        $this->SetFont('Arial','B',14);
        $this->Cell(0,10,'Employee Directory - TEK-C',0,1,'C');
        $this->Ln(3);

        $this->SetFont('Arial','B',9);
        $this->SetFillColor(230,230,230);

        $this->centerTable();

        $w = $this->colWidths;

        $this->Cell($w['name'],8,'Name',1,0,'L',true);
        $this->Cell($w['code'],8,'Code',1,0,'L',true);
        $this->Cell($w['designation'],8,'Designation',1,0,'L',true);
        $this->Cell($w['dept'],8,'Dept',1,0,'L',true);
        $this->Cell($w['mobile'],8,'Mobile',1,0,'L',true);
        $this->Cell($w['email'],8,'Email',1,0,'L',true);
        $this->Cell($w['status'],8,'Status',1,0,'L',true);
        $this->Cell($w['joining'],8,'Joining',1,1,'L',true);
    }

    function Row($data)
    {
        $this->SetFont('Arial','',8);

        $this->centerTable();

        $w = $this->colWidths;

        $this->Cell($w['name'],7,$data['full_name'] ?? '-',1);
        $this->Cell($w['code'],7,$data['employee_code'] ?? '-',1);
        $this->Cell($w['designation'],7,$data['designation'] ?? '-',1);
        $this->Cell($w['dept'],7,$data['department'] ?? '-',1);
        $this->Cell($w['mobile'],7,$data['mobile_number'] ?? '-',1);
        $this->Cell($w['email'],7,$data['email'] ?? '-',1);
        $this->Cell($w['status'],7,ucfirst($data['employee_status'] ?? '-'),1);
        $this->Cell($w['joining'],7,formatDate($data['date_of_joining'] ?? ''),1);
        $this->Ln();
    }

    function centerTable()
    {
        $totalWidth = array_sum($this->colWidths);
        $pageWidth = $this->GetPageWidth();
        $marginLeft = ($pageWidth - $totalWidth) / 2;
        $this->SetX($marginLeft);
    }

    function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,5,'Generated on: '.date('d-m-Y H:i').' | Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
}

// ---------------- CREATE PDF ----------------
$pdf = new PDF('L','mm','A4'); // Landscape
$pdf->AliasNbPages();
$pdf->SetMargins(10,10,10);
$pdf->AddPage();
$pdf->SetAutoPageBreak(true,15);

// Table rows
foreach ($employees as $emp) {
    $pdf->Row($emp);
}

// Force download
$pdf->Output('D', 'Employee_Directory.pdf');
exit;