<?php
require('libs/fpdf.php');

class PDF extends FPDF
{
    function Header()
    {
        // Reduce top margin space
        $this->SetY(8);

        // Title
        $this->SetFont('Arial','B',14);
        $this->Cell(0,8,'Employee Details Form',0,1,'C');

        // Photo Box (Top Right)
        $this->SetXY(165,12);
        $this->SetFont('Arial','',9);
        $this->Cell(30,6,'Photo',1,2,'C');
        $this->Cell(30,25,'',1,1);

        $this->Ln(2);
    }

    function SectionTitle($title)
    {
        $this->SetFont('Arial','B',11);
        $this->Ln(4);
        $this->Cell(0,6,$title,0,1,'L');
    }

    function Row($label)
    {
        $this->SetFont('Arial','',10);
        $this->Cell(65,7,$label,1);
        $this->Cell(125,7,'',1);
        $this->Ln();
    }
}

$pdf = new PDF('P','mm','A4');
$pdf->SetMargins(10,10,10);
$pdf->AddPage();
$pdf->SetAutoPageBreak(false); // VERY IMPORTANT

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
$pdf->SetFont('Arial','',10);
$pdf->Cell(50,6,'Signature of Employee:');
$pdf->SetX($pdf->GetX() - 10);
$pdf->Cell(80,6,'__________________________',0,1);

$pdf->Ln(4);
$pdf->Cell(15,6,'Date:');
$pdf->SetX($pdf->GetX() - 4);
$pdf->Cell(60,6,'________________',0,1);

$pdf->Output();
?>