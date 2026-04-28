<?php
// Start session if needed
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'u209621005_tekc';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get module statistics from database
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sites WHERE deleted_at IS NULL");
    $siteCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients");
    $clientCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM dpr_reports");
    $dprCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM dar_reports");
    $darCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM mom_reports");
    $momCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM ma_reports");
    $maCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM rfi_reports");
    $rfiCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM ait_main");
    $aitCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM quotation_requests");
    $rfqCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM quotations");
    $quotationCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE employee_status = 'active'");
    $employeeCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM attendance");
    $attendanceCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch(PDOException $e) {
    $siteCount = 8; $clientCount = 3; $dprCount = 34; $darCount = 7;
    $momCount = 8; $maCount = 13; $rfiCount = 3; $aitCount = 1;
    $rfqCount = 7; $quotationCount = 2; $employeeCount = 18; $attendanceCount = 23;
}

// Construction images array
$constructionImages = [
    'hero' => 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1800&q=80',
    'site1' => 'https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=800&q=80',
    'site2' => 'https://images.unsplash.com/photo-1541976590-713941681591?auto=format&fit=crop&w=800&q=80',
    'reports' => 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?auto=format&fit=crop&w=800&q=80',
    'quotation' => 'https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?auto=format&fit=crop&w=800&q=80',
    'hr' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=800&q=80',
    'documents' => 'https://images.unsplash.com/photo-1554224154-26032ffc8079?auto=format&fit=crop&w=800&q=80',
    'security' => 'https://images.unsplash.com/photo-1555949963-ff9fe0c870eb?auto=format&fit=crop&w=800&q=80',
    'analytics' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=800&q=80',
    'mobile' => 'https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?auto=format&fit=crop&w=800&q=80',
    'team' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=800&q=80',
    'blueprint' => 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?auto=format&fit=crop&w=800&q=80',
    'meeting' => 'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=800&q=80',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modules | TEK-C Global Construction ERP</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --dark: #101820;
            --dark2: #151f28;
            --yellow: #ffc329;
            --yellow2: #ffb000;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e9edf3;
            --soft: #f7f9fc;
        }

        * { font-family: "Inter", sans-serif; }
        html { scroll-behavior: smooth; }
        body { background: #fff; color: var(--text); overflow-x: hidden; }

        .navbar {
            background: rgba(16, 24, 32, 0.96);
            backdrop-filter: blur(14px);
            padding: 15px 0;
            box-shadow: 0 8px 35px rgba(0,0,0,.25);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff !important;
            font-weight: 900;
        }

        .logo-box {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 8px 22px rgba(255, 195, 41, .4);
        }

        .logo-text span {
            display: block;
            font-size: 11px;
            letter-spacing: 4px;
            color: #cfd6df;
            font-weight: 600;
            margin-top: -3px;
        }

        .nav-link {
            color: #dbe3ec !important;
            font-weight: 600;
            margin: 0 8px;
            position: relative;
        }

        .nav-link:hover, .nav-link.active { color: var(--yellow) !important; }
        .nav-link.active::after {
            content: "";
            position: absolute;
            left: 10px;
            bottom: -8px;
            height: 3px;
            width: 35px;
            background: var(--yellow);
            border-radius: 20px;
        }

        .search-box {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 12px;
            color: #fff;
            padding: 11px 15px;
            min-width: 260px;
        }

        .btn-yellow {
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            color: #111;
            font-weight: 800;
            border: 0;
            border-radius: 12px;
            padding: 12px 24px;
            transition: .35s;
        }

        .btn-yellow:hover { transform: translateY(-3px); box-shadow: 0 18px 40px rgba(255, 179, 0, .45); }
        .btn-light-custom { background: #fff; color: #111; font-weight: 800; border-radius: 12px; padding: 12px 24px; border: 0; transition: .35s; }
        .btn-light-custom:hover { transform: translateY(-3px); }

        .hero-modules {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 100px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-modules::before {
            content: "";
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            background: url('<?php echo $constructionImages['hero']; ?>') center/cover no-repeat;
            opacity: 0.15;
            z-index: 0;
        }

        .hero-modules .container { position: relative; z-index: 1; }
        .hero-modules h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-modules .yellow-text { color: var(--yellow); }

        section { padding: 85px 0; }
        .section-title { text-align: center; margin-bottom: 50px; }
        .section-title h2 { font-size: clamp(32px, 4vw, 48px); font-weight: 900; color: #111827; letter-spacing: -1px; }
        .section-title p { color: var(--muted); font-size: 17px; }

        .module-card-large {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 30px;
            transition: .35s;
            box-shadow: 0 10px 32px rgba(17,24,39,.05);
            overflow: hidden;
            position: relative;
        }

        .module-card-large:hover { transform: translateY(-8px); border-color: var(--yellow); }
        
        .module-bg-img {
            position: absolute;
            right: -50px;
            bottom: -50px;
            width: 250px;
            height: 250px;
            opacity: 0.08;
            transition: .5s;
            pointer-events: none;
        }
        
        .module-card-large:hover .module-bg-img {
            opacity: 0.15;
            transform: scale(1.1);
        }

        .module-header { display: flex; gap: 20px; align-items: flex-start; margin-bottom: 25px; position: relative; z-index: 1; }
        .module-icon-large {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #fff;
            flex-shrink: 0;
        }
        .module-title h3 { font-weight: 900; margin-bottom: 8px; color: #111827; }
        .module-title p { color: var(--muted); margin-bottom: 0; }

        .feature-list { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 25px; position: relative; z-index: 1; }
        .feature-tag {
            background: #f4f6f9;
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #4b5563;
            transition: .3s;
        }
        .feature-tag:hover { background: var(--yellow); color: #111; transform: translateY(-2px); }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 15px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
            position: relative;
            z-index: 1;
        }
        .stat-value { font-size: 28px; font-weight: 900; color: var(--yellow); }
        .stat-label { font-size: 12px; color: var(--muted); font-weight: 600; }

        .category-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin-bottom: 40px;
        }
        .filter-btn {
            background: #fff;
            border: 1px solid var(--border);
            padding: 10px 24px;
            border-radius: 30px;
            font-weight: 600;
            transition: .3s;
            cursor: pointer;
        }
        .filter-btn:hover, .filter-btn.active { background: var(--yellow); border-color: var(--yellow); color: #111; }

        .image-showcase {
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            border-radius: 20px;
            min-height: 300px;
            position: relative;
            overflow: hidden;
        }
        
        .image-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.8));
            padding: 30px 20px 20px;
            color: white;
        }

        .value-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 30px;
            text-align: center;
            height: 100%;
            transition: .35s;
            position: relative;
            overflow: hidden;
        }
        
        .value-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('<?php echo $constructionImages['blueprint']; ?>') center/cover no-repeat;
            opacity: 0;
            transition: .5s;
        }
        
        .value-card:hover::before { opacity: 0.05; }
        .value-card:hover { transform: translateY(-8px); border-color: var(--yellow); }
        
        .value-icon {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
            color: #fff;
        }

        .final-cta {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            color: #fff;
            padding: 70px 0;
            position: relative;
            overflow: hidden;
        }
        
        .final-cta::before {
            content: "";
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            background: url('<?php echo $constructionImages['site1']; ?>') center/cover no-repeat;
            opacity: 0.1;
        }

        footer {
            background: #101820;
            color: #d8dee8;
            padding: 55px 0 25px;
        }
        footer h6 { color: #fff; font-weight: 900; margin-bottom: 18px; }
        footer a {
            display: block;
            color: #aeb8c5;
            text-decoration: none;
            margin-bottom: 11px;
            transition: .3s;
        }
        footer a:hover { color: var(--yellow); transform: translateX(5px); }
        .social-icon {
            width: 42px;
            height: 42px;
            background: #fff;
            color: #101820;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin-right: 8px;
            transition: .3s;
        }
        .social-icon:hover { background: var(--yellow); transform: translateY(-5px); }

        @media (max-width: 991px) {
            .search-box { min-width: 100%; margin: 12px 0; }
            .module-header { flex-direction: column; text-align: center; }
            .module-icon-large { margin: 0 auto; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .module-item { animation: fadeIn 0.5s ease; }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-modules">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>TEK-C <span class="yellow-text">Modules</span></h1>
                <p class="lead mt-4">Complete suite of integrated modules for end-to-end construction management</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2">15+ Modules</span>
                    <span class="badge bg-warning text-dark me-2 p-2">100+ Features</span>
                    <span class="badge bg-warning text-dark p-2">Fully Integrated</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Image Showcase Row -->
<section style="padding: 0 0 50px 0;">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="image-showcase" style="background-image: url('<?php echo $constructionImages['site1']; ?>'); min-height: 250px;">
                    <div class="image-overlay">
                        <h5 class="fw-bold mb-0">Active Construction Sites</h5>
                        <small><?php echo $siteCount; ?>+ Sites Managed</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="image-showcase" style="background-image: url('<?php echo $constructionImages['meeting']; ?>'); min-height: 250px;">
                    <div class="image-overlay">
                        <h5 class="fw-bold mb-0">Daily Site Meetings</h5>
                        <small><?php echo $momCount + $maCount; ?>+ Meetings Documented</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="image-showcase" style="background-image: url('<?php echo $constructionImages['team']; ?>'); min-height: 250px;">
                    <div class="image-overlay">
                        <h5 class="fw-bold mb-0">Dedicated Team</h5>
                        <small><?php echo $employeeCount; ?>+ Construction Experts</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Category Filter -->
<section class="bg-light" style="padding: 40px 0;">
    <div class="container">
        <div class="category-filter" data-aos="fade-up">
            <button class="filter-btn active" data-filter="all">All Modules</button>
            <button class="filter-btn" data-filter="sites">Site Management</button>
            <button class="filter-btn" data-filter="reports">Reports</button>
            <button class="filter-btn" data-filter="quotations">Quotations</button>
            <button class="filter-btn" data-filter="hr">HR & Payroll</button>
            <button class="filter-btn" data-filter="documents">Documents</button>
            <button class="filter-btn" data-filter="security">Security</button>
            <button class="filter-btn" data-filter="analytics">Analytics</button>
            <button class="filter-btn" data-filter="mobile">Mobile</button>
        </div>
    </div>
</section>

<!-- Modules Display -->
<section style="padding-top: 0;">
    <div class="container">
        <!-- Module 1: Site Management -->
        <div class="module-card-large module-item" data-category="sites" data-aos="fade-up">
            <img src="<?php echo $constructionImages['site2']; ?>" class="module-bg-img" alt="">
            <div class="module-header">
                <div class="module-icon-large"><i class="bi bi-building"></i></div>
                <div class="module-title">
                    <h3>Site & Project Management</h3>
                    <p>Complete management of construction sites, contracts, and project lifecycles.</p>
                </div>
            </div>
            <div class="feature-list">
                <span class="feature-tag"><i class="bi bi-check-circle-fill me-1"></i> Site Registration</span>
                <span class="feature-tag"><i class="bi bi-check-circle-fill me-1"></i> Contract Management</span>
                <span class="feature-tag"><i class="bi bi-check-circle-fill me-1"></i> Timeline Tracking</span>
                <span class="feature-tag"><i class="bi bi-check-circle-fill me-1"></i> BOQ Management</span>
                <span class="feature-tag"><i class="bi bi-check-circle-fill me-1"></i> Site Dashboard</span>
                <span class="feature-tag"><i class="bi bi-check-circle-fill me-1"></i> Multi-location Support</span>
            </div>
            <div class="stats-grid">
                <div class="stat-item"><div class="stat-value"><?php echo $siteCount; ?></div><div class="stat-label">Active Sites</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $clientCount; ?></div><div class="stat-label">Clients</div></div>
                <div class="stat-item"><div class="stat-value">₹2.5Cr+</div><div class="stat-label">Contract Value</div></div>
            </div>
        </div>

        <!-- Module 2: Reports -->
        <div class="module-card-large module-item" data-category="reports" data-aos="fade-up">
            <img src="<?php echo $constructionImages['reports']; ?>" class="module-bg-img" alt="">
            <div class="module-header">
                <div class="module-icon-large"><i class="bi bi-journal-text"></i></div>
                <div class="module-title">
                    <h3>Daily Reports & Documentation</h3>
                    <p>Comprehensive reporting system for all construction activities.</p>
                </div>
            </div>
            <div class="feature-list">
                <span class="feature-tag">DPR</span><span class="feature-tag">DAR</span><span class="feature-tag">MOM</span>
                <span class="feature-tag">MAS</span><span class="feature-tag">RFI</span><span class="feature-tag">AIT</span>
                <span class="feature-tag">DLAR</span><span class="feature-tag">MPT</span>
            </div>
            <div class="stats-grid">
                <div class="stat-item"><div class="stat-value"><?php echo $dprCount; ?></div><div class="stat-label">DPR Reports</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $darCount; ?></div><div class="stat-label">DAR Reports</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $momCount; ?></div><div class="stat-label">MOM Reports</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $maCount; ?></div><div class="stat-label">MA Reports</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $rfiCount; ?></div><div class="stat-label">RFIs</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $aitCount; ?></div><div class="stat-label">AIT Items</div></div>
            </div>
        </div>

        <!-- Module 3: Quotations -->
        <div class="module-card-large module-item" data-category="quotations" data-aos="fade-up">
            <img src="<?php echo $constructionImages['quotation']; ?>" class="module-bg-img" alt="">
            <div class="module-header">
                <div class="module-icon-large"><i class="bi bi-calculator"></i></div>
                <div class="module-title">
                    <h3>Quotation & Tendering</h3>
                    <p>Streamlined quotation management and vendor comparison system.</p>
                </div>
            </div>
            <div class="feature-list">
                <span class="feature-tag">RFQ Generation</span><span class="feature-tag">Vendor Management</span>
                <span class="feature-tag">Quotation Comparison</span><span class="feature-tag">Negotiation Tracking</span>
                <span class="feature-tag">QS Approval</span><span class="feature-tag">Purchase Order</span>
            </div>
            <div class="stats-grid">
                <div class="stat-item"><div class="stat-value"><?php echo $rfqCount; ?></div><div class="stat-label">RFQs Created</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $quotationCount; ?></div><div class="stat-label">Quotations</div></div>
            </div>
        </div>

        <!-- Module 4: HR & Payroll -->
        <div class="module-card-large module-item" data-category="hr" data-aos="fade-up">
            <img src="<?php echo $constructionImages['hr']; ?>" class="module-bg-img" alt="">
            <div class="module-header">
                <div class="module-icon-large"><i class="bi bi-people"></i></div>
                <div class="module-title">
                    <h3>HR & Payroll Management</h3>
                    <p>Complete workforce management from hiring to payroll.</p>
                </div>
            </div>
            <div class="feature-list">
                <span class="feature-tag">Employee Database</span><span class="feature-tag">Attendance</span>
                <span class="feature-tag">Leave Management</span><span class="feature-tag">Hiring</span>
                <span class="feature-tag">Onboarding</span><span class="feature-tag">Payroll</span>
            </div>
            <div class="stats-grid">
                <div class="stat-item"><div class="stat-value"><?php echo $employeeCount; ?></div><div class="stat-label">Employees</div></div>
                <div class="stat-item"><div class="stat-value"><?php echo $attendanceCount; ?></div><div class="stat-label">Attendance Records</div></div>
            </div>
        </div>

        <!-- Module 5: Document Control -->
        <div class="module-card-large module-item" data-category="documents" data-aos="fade-up">
            <img src="<?php echo $constructionImages['documents']; ?>" class="module-bg-img" alt="">
            <div class="module-header">
                <div class="module-icon-large"><i class="bi bi-folder2-open"></i></div>
                <div class="module-title">
                    <h3>Document Control System</h3>
                    <p>Centralized document management with version control.</p>
                </div>
            </div>
            <div class="feature-list">
                <span class="feature-tag">Checklist Reports</span><span class="feature-tag">DDT</span>
                <span class="feature-tag">DPT</span><span class="feature-tag">PMS</span>
                <span class="feature-tag">VFS</span><span class="feature-tag">WPT</span>
            </div>
        </div>

        <!-- Module 6: Security -->
        <div class="module-card-large module-item" data-category="security" data-aos="fade-up">
            <img src="<?php echo $constructionImages['security']; ?>" class="module-bg-img" alt="">
            <div class="module-header">
                <div class="module-icon-large"><i class="bi bi-shield-lock"></i></div>
                <div class="module-title">
                    <h3>Security & Access Control</h3>
                    <p>Role-based access control with complete audit trails.</p>
                </div>
            </div>
            <div class="feature-list">
                <span class="feature-tag">Role-based Permissions</span><span class="feature-tag">User Authentication</span>
                <span class="feature-tag">Activity Logging</span><span class="feature-tag">Data Encryption</span>
                <span class="feature-tag">2FA</span><span class="feature-tag">Session Management</span>
            </div>
        </div>

        <!-- Module 7: Analytics -->
        <div class="module-card-large module-item" data-category="analytics" data-aos="fade-up">
            <img src="<?php echo $constructionImages['analytics']; ?>" class="module-bg-img" alt="">
            <div class="module-header">
                <div class="module-icon-large"><i class="bi bi-graph-up"></i></div>
                <div class="module-title">
                    <h3>Analytics & Dashboard</h3>
                    <p>Real-time insights and comprehensive analytics.</p>
                </div>
            </div>
            <div class="feature-list">
                <span class="feature-tag">Real-time Dashboards</span><span class="feature-tag">Project Analytics</span>
                <span class="feature-tag">Financial Reports</span><span class="feature-tag">HR Analytics</span>
                <span class="feature-tag">Performance Metrics</span><span class="feature-tag">Data Visualization</span>
            </div>
        </div>

        <!-- Module 8: Mobile -->
        <div class="module-card-large module-item" data-category="mobile" data-aos="fade-up">
            <img src="<?php echo $constructionImages['mobile']; ?>" class="module-bg-img" alt="">
            <div class="module-header">
                <div class="module-icon-large"><i class="bi bi-phone"></i></div>
                <div class="module-title">
                    <h3>Mobile Application</h3>
                    <p>Access TEK-C on the go with our mobile app.</p>
                </div>
            </div>
            <div class="feature-list">
                <span class="feature-tag">Mobile Attendance</span><span class="feature-tag">Photo Upload</span>
                <span class="feature-tag">Notifications</span><span class="feature-tag">Leave Application</span>
                <span class="feature-tag">QR Scanning</span><span class="feature-tag">Offline Support</span>
            </div>
        </div>
    </div>
</section>

<!-- Construction in Action Gallery -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Construction <span class="yellow-text">in Action</span></h2>
            <p>Real projects managed with TEK-C</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4" data-aos="zoom-in">
                <div class="image-showcase" style="background-image: url('https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=800&q=80'); min-height: 280px;"></div>
                <div class="text-center mt-3"><h6 class="fw-bold">High-rise Construction</h6><small class="text-muted">Chennai, Tamil Nadu</small></div>
            </div>
            <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
                <div class="image-showcase" style="background-image: url('https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=800&q=80'); min-height: 280px;"></div>
                <div class="text-center mt-3"><h6 class="fw-bold">Commercial Complex</h6><small class="text-muted">Bangalore, Karnataka</small></div>
            </div>
            <div class="col-md-4" data-aos="zoom-in" data-aos-delay="200">
                <div class="image-showcase" style="background-image: url('https://images.unsplash.com/photo-1541976590-713941681591?auto=format&fit=crop&w=800&q=80'); min-height: 280px;"></div>
                <div class="text-center mt-3"><h6 class="fw-bold">Residential Township</h6><small class="text-muted">Coimbatore, Tamil Nadu</small></div>
            </div>
        </div>
    </div>
</section>

<!-- Module Comparison Table -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Module <span class="yellow-text">Comparison</span></h2>
            <p>Compare features across different modules</p>
        </div>
        <div class="table-responsive" data-aos="fade-up">
            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr><th>Feature</th><th>Site</th><th>Reports</th><th>Quotations</th><th>HR</th><th>Documents</th></tr>
                </thead>
                <tbody>
                    <tr><td class="fw-bold">Real-time Tracking</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="fw-bold">Mobile Access</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="fw-bold">Approval Workflow</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="fw-bold">Report Generation</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="fw-bold">Analytics Dashboard</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="fw-bold">Role-based Access</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td><td>✅</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Final CTA -->
<section class="final-cta">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8 mx-auto text-center" data-aos="fade-up">
                <h2 class="display-5 fw-black">Ready to Explore <span class="yellow-text">TEK-C Modules</span>?</h2>
                <p class="mt-3 fs-5">Get a personalized demo of the modules that matter to your business</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="#" class="btn btn-yellow"><i class="bi bi-calendar2-check me-2"></i> Request a Demo</a>
                    <a href="#" class="btn btn-light-custom"><i class="bi bi-download me-2"></i> Download Module Guide</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
    AOS.init({ duration: 1000, once: true, offset: 80 });
    
    // Filter functionality
    const filterBtns = document.querySelectorAll('.filter-btn');
    const moduleItems = document.querySelectorAll('.module-item');
    
    filterBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            filterBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const filterValue = btn.getAttribute('data-filter');
            moduleItems.forEach(item => {
                if (filterValue === 'all' || item.getAttribute('data-category') === filterValue) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    });
    
    // Search functionality
    const searchBox = document.querySelector(".search-box");
    if (searchBox) {
        searchBox.addEventListener("keyup", function() {
            const value = this.value.toLowerCase();
            document.querySelectorAll(".module-card-large").forEach(card => {
                const text = card.innerText.toLowerCase();
                card.style.display = (value === "" || text.includes(value)) ? "block" : "none";
            });
        });
    }
</script>

</body>
</html>