<?php
require('fpdf.php');

class PDF extends FPDF
{
    function Header()
    {
        // Title
        $this->SetFont('Arial','B',16);
        $this->Cell(0,10,'Employee Details Form',0,1,'C');
        $this->Ln(5);

        // Photo Box (Top Right)
        $this->SetXY(160,20);
        $this->Cell(35,8,'Photo',1,2,'C');
        $this->Cell(35,30,'',1,1);
        $this->Ln(5);
    }

    function SectionTitle($title)
    {
        $this->SetFont('Arial','B',12);
        $this->Ln(8);
        $this->Cell(0,8,$title,0,1,'L');
    }

    function Row($label)
    {
        $this->SetFont('Arial','',11);
        $this->Cell(60,10,$label,1);
        $this->Cell(130,10,'',1);
        $this->Ln();
    }
}

$pdf = new PDF();
$pdf->AddPage();

$pdf->SetFont('Arial','',12);

/* ---------------- Identity Details ---------------- */
$pdf->SectionTitle('Identity Details');

$pdf->Row('Employee Full Name (as per ID)');
$pdf->Row('Employee ID / Employee Code');
$pdf->Row('Date of Birth');
$pdf->Row('Gender');
$pdf->Row('Blood Group');

/* ---------------- Contact Details ---------------- */
$pdf->SectionTitle('Contact Details');

$pdf->Row('Mobile Number');
$pdf->Row('Email Address');
$pdf->Row('Emergency Contact Name');
$pdf->Row('Emergency Contact Phone');
$pdf->Row('Current Address');

/* ---------------- Employment Details ---------------- */
$pdf->SectionTitle('Employment Details');

$pdf->Row('Date of Joining');
$pdf->Row('Department');
$pdf->Row('Designation');
$pdf->Row('Reporting Manager');
$pdf->Row('Work Location');
$pdf->Row('Site Name');
$pdf->Row('Employee Status');

/* ---------------- Compliance ---------------- */
$pdf->SectionTitle('Compliance / Verification');

$pdf->Row('Aadhaar Card Number');
$pdf->Row('PAN Card Number');

/* ---------------- Payroll ---------------- */
$pdf->SectionTitle('Payroll / Banking');

$pdf->Row('Bank Account Number');
$pdf->Row('IFSC Code');

/* ---------------- Signature ---------------- */
$pdf->Ln(15);
$pdf->SetFont('Arial','',11);
$pdf->Cell(50,8,'Signature of Employee:');
$pdf->Cell(100,8,'__________________________',0,1);

$pdf->Ln(5);
$pdf->Cell(20,8,'Date:');
$pdf->Cell(80,8,'________________',0,1);

$pdf->Output();
?>