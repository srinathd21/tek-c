<?php
ob_start();
session_start();

require_once __DIR__ . '/includes/db-config.php';
require_once __DIR__ . '/libs/fpdf.php';

if (empty($_SESSION['employee_id'])) {
    header("Location: login.php");
    exit;
}

$employeeId = (int)$_SESSION['employee_id'];
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId <= 0) die('Invalid checklist id');

$conn = get_db_connection();

// FETCH COMPANY NAME
$companySql = "SELECT company_name FROM company_details WHERE id = 1 LIMIT 1";
$companyResult = mysqli_query($conn, $companySql);
$companyData = mysqli_fetch_assoc($companyResult);
$companyName = $companyData['company_name'] ?? 'TEK-C Construction Pvt. Ltd.';

/* ================= FETCH CHECKLIST DATA ================= */
$sql = "
SELECT 
    c.*, s.project_name, cl.client_name
FROM checklist_reports c
JOIN sites s ON s.id = c.site_id
JOIN clients cl ON cl.id = s.client_id
WHERE c.id = ? AND c.employee_id = ?
LIMIT 1
";
$st = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($st, "ii", $viewId, $employeeId);
mysqli_stmt_execute($st);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
mysqli_stmt_close($st);

if (!$row) die('Checklist not found');

/* ================= HELPERS ================= */
function clean_text($s){
    return trim(html_entity_decode(strip_tags((string)$s)));
}

function decode_rows($json){
    $a = json_decode($json, true);
    return is_array($a) ? $a : [];
}

function group_by_section($items){
    $o = [];
    foreach ($items as $i) $o[$i['section']][] = $i;
    return $o;
}

/* ================= PDF CLASS ================= */
class ChecklistPDF extends FPDF
{
    protected $firstPage = true;
    protected $totalPages = 0;
    public $companyName = '';
    public $pmcLead = '';
    
    function Header()
    {
        if ($this->firstPage) {
            $logoPaths = [
                'public/logo.png',
                'assets/logo.png',
                'images/logo.png',
                'logo.png',
                'assets/ukb.png',
                'assets/ukb.jpg'
            ];
            
            $logoFound = false;
            foreach ($logoPaths as $path) {
                if (file_exists(__DIR__ . '/' . $path)) {
                    $pageWidth = $this->GetPageWidth();
                    $leftMargin = $this->lMargin;
                    $rightMargin = $this->rMargin;
                    $usableWidth = $pageWidth - $leftMargin - $rightMargin;
                    // Increased logo size slightly for A3
                    $logoWidth = 30;
                    $x = $leftMargin + ($usableWidth - $logoWidth) / 2;
                    $this->Image(__DIR__ . '/' . $path, $x, 10, $logoWidth);
                    $logoFound = true;
                    break;
                }
            }
            
            if (!$logoFound) {
                $this->SetY(10);
            }
            
            $this->Ln(25);
            $this->SetFont('Arial', 'B', 18); // Larger font for A3
            $this->Cell(0, 10, 'PROJECT ENGINEER DAILY ACTIVITY CHECKLIST', 0, 1, 'C');
            $this->Ln(10);
            
            $this->firstPage = false;
        }
    }

    function Footer()
    {
        $this->SetY(-20); // Moved up slightly for A3
        $this->SetFont('Arial', 'I', 10); // Slightly larger font
        
        // Get total pages
        $totalPages = $this->getTotalPages();
        $pageText = $this->PageNo() . ' / ' . $totalPages;
        
        // Calculate width of page text for centering
        $pageTextWidth = $this->GetStringWidth($pageText);
        
        // Company name on left
        $this->Cell(0, 12, $this->companyName, 0, 0, 'L');
        
        // Page number centered
        $this->SetX(($this->GetPageWidth() - $pageTextWidth) / 2);
        $this->Cell($pageTextWidth, 12, $pageText, 0, 0, 'C');
        
        // PMC Lead on right
        $pmcText = $this->pmcLead;
        $this->SetXY($this->GetPageWidth() - $this->rMargin - $this->GetStringWidth($pmcText), $this->GetY());
        $this->Cell(0, 12, $pmcText, 0, 0, 'R');
    }
    
    function getTotalPages()
    {
        if ($this->totalPages == 0) {
            $this->totalPages = $this->page;
        }
        return $this->totalPages;
    }
    
    function Close()
    {
        $this->totalPages = $this->page;
        parent::Close();
    }

    function Checkbox($x, $y, $size = 5, $checked = false) // Larger checkbox for A3
    {
        $this->Rect($x, $y, $size, $size);
        
        if ($checked) {
            $this->SetLineWidth(0.8);
            $centerX = $x + ($size / 2);
            $centerY = $y + ($size / 2);
            $this->Line($centerX - 2, $centerY, $centerX - 0.8, $centerY + 1.5);
            $this->Line($centerX - 0.8, $centerY + 1.5, $centerX + 2, $centerY - 2);
            $this->SetLineWidth(0.2);
        }
    }

    function CheckboxItem($text, $checked = false)
    {
        $x = $this->GetX();
        $y = $this->GetY();
        
        // Adjusted positions for A3
        $this->Checkbox($x + 3, $y + 2, 5, $checked);
        $this->SetXY($x + 12, $y);
        $this->SetFont('Arial', '', 11); // Larger font for A3
        $this->MultiCell(0, 6, $text);
        $this->Ln(2);
    }

    function SectionTitle($text)
    {
        $this->Ln(6);
        $this->SetFont('Arial', 'B', 13); // Larger font for A3
        $this->SetFillColor(240, 240, 240);
        $this->Cell(0, 9, '  ' . $text, 0, 1, 'L', true);
        $this->Ln(3);
    }

    function DataField($label, $value, $labelW = 40) // Wider label for A3
    {
        $this->SetFont('Arial', 'B', 11);
        $this->Cell($labelW, 8, $label, 0, 0);
        $this->SetFont('Arial', '', 11);
        $this->SetFillColor(245, 245, 250);
        $this->Cell(200, 8, '  ' . $value, 1, 1, 'L', true); // Wider cell for A3
        $this->Ln(3);
    }

    function SignatureField($label, $value, $labelW = 50) // Wider label for A3
    {
        $this->SetFont('Arial', 'B', 11);
        $this->Cell($labelW, 8, $label, 0, 0);
        $this->SetFont('Arial', '', 11);
        $this->SetFillColor(245, 245, 250);
        $this->Cell(180, 8, '  ' . $value, 1, 1, 'L', true); // Wider cell for A3
        $this->Ln(5);
    }
}

/* ================= INIT PDF ================= */
// Changed to A3 format
$pdf = new ChecklistPDF('P', 'mm', 'A3');
$pdf->companyName = $companyName;
$pdf->pmcLead = clean_text($row['pmc_lead']); // Set PMC Lead
$pdf->SetAutoPageBreak(true, 25); // Increased bottom margin for A3
$pdf->SetMargins(25, 20, 25); // Wider margins for A3
$pdf->AddPage();

/* ================= HEADER DATA ================= */
$pdf->DataField('Project:', clean_text($row['project_name']));
$pdf->DataField('Client:', clean_text($row['client_name']));
$pdf->DataField('PMC:', clean_text($row['pmc_lead']));
$pdf->DataField('Date:', date('d-m-Y', strtotime($row['checklist_date'])));
$pdf->DataField('Doc No.:', clean_text($row['doc_no']));

$pdf->Ln(8);

/* ================= CHECKLIST ITEMS ================= */
$sections = group_by_section(decode_rows($row['checklist_json']));

$section_order = [
    'Daily Responsibilities',
    'Quality Control',
    'Coordination',
    'Safety & Compliance',
    'Documentation',
    'Escalation & Communication',
    'Weekly/Monthly Duties',
    'Professional Conduct'
];

$itemCount = 0;
foreach ($section_order as $section_name) {
    if (isset($sections[$section_name])) {
        $items = $sections[$section_name];
        
        $pdf->SectionTitle($section_name);
        
        foreach ($items as $item) {
            $pdf->CheckboxItem(clean_text($item['label']), (int)$item['checked'] === 1);
            $itemCount++;
            
            // Adjusted page break threshold for A3 (A3 height is 420mm)
            if ($pdf->GetY() > 370) {
                $pdf->AddPage();
            }
        }
        $pdf->Ln(3);
    }
}

/* ================= SIGNATURES ================= */
$pdf->Ln(15);
$pdf->SignatureField('Project Engineer:', clean_text($row['project_engineer']));
$pdf->SignatureField('PMC Lead:', clean_text($row['pmc_lead']));

/* ================= OUTPUT ================= */
ob_end_clean();
$pdf->Output('I', 'CHECKLIST_' . $row['doc_no'] . '.pdf');

$conn->close();
exit;
?>