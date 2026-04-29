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
    
    // Fetch company details
    $stmt = $pdo->query("SELECT * FROM company_details ORDER BY id DESC LIMIT 1");
    $companyDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get total active sites
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sites WHERE deleted_at IS NULL");
    $siteCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total completed projects (projects with end date passed)
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sites WHERE expected_completion_date < CURDATE() AND deleted_at IS NULL");
    $completedProjects = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total active employees
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE employee_status = 'active'");
    $employeeCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total clients
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients");
    $clientCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total reports generated
    $stmt = $pdo->query("SELECT 
                        (SELECT COUNT(*) FROM dpr_reports) + 
                        (SELECT COUNT(*) FROM dar_reports) + 
                        (SELECT COUNT(*) FROM mom_reports) + 
                        (SELECT COUNT(*) FROM ma_reports) as total");
    $reportCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get department distribution
    $stmt = $pdo->query("SELECT department, COUNT(*) as count FROM employees WHERE employee_status = 'active' GROUP BY department");
    $departmentStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent clients
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC LIMIT 6");
    $recentClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employee roles distribution
    $stmt = $pdo->query("SELECT designation, COUNT(*) as count FROM employees WHERE employee_status = 'active' GROUP BY designation ORDER BY count DESC LIMIT 6");
    $roleDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get testimonials/staff feedback (using activity logs as proxy)
    $stmt = $pdo->query("SELECT DISTINCT user_name, user_role, action_type FROM activity_logs WHERE user_name IS NOT NULL AND user_name != '' ORDER BY created_at DESC LIMIT 6");
    $teamMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate years of operation
    $stmt = $pdo->query("SELECT MIN(YEAR(created_at)) as earliest_year FROM sites WHERE created_at IS NOT NULL");
    $earliest = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentYear = date('Y');
    $yearsOfOperation = $earliest['earliest_year'] ? ($currentYear - $earliest['earliest_year']) : 5;
    
} catch(PDOException $e) {
    // Fallback to static data if database connection fails
    $companyDetails = [
        'company_name' => 'TEK-C Global',
        'company_address' => 'Dharmapuri, Tamil Nadu, India',
        'company_phone' => '+91 72003 16099',
        'company_email' => 'info@tekcglobal.com',
        'company_website' => 'https://tekcglobal.com',
        'ceo_name' => 'Ariharasudhan P',
        'ceo_designation' => 'Director',
        'established_date' => '2020-01-01'
    ];
    $siteCount = 8;
    $completedProjects = 3;
    $employeeCount = 18;
    $clientCount = 3;
    $reportCount = 47;
    $departmentStats = [];
    $recentClients = [];
    $roleDistribution = [];
    $teamMembers = [];
    $yearsOfOperation = 5;
}

// Define teams (static for now, can be fetched from employees table)
$teams = [
    ['name' => 'Project Management', 'icon' => 'bi-briefcase', 'count' => 6, 'color' => '#ffc329'],
    ['name' => 'Quality Surveying', 'icon' => 'bi-calculator', 'count' => 3, 'color' => '#ffc329'],
    ['name' => 'Human Resources', 'icon' => 'bi-people', 'count' => 3, 'color' => '#ffc329'],
    ['name' => 'Accounts & Finance', 'icon' => 'bi-currency-rupee', 'count' => 2, 'color' => '#ffc329'],
    ['name' => 'Construction Management', 'icon' => 'bi-building', 'count' => 4, 'color' => '#ffc329'],
    ['name' => 'IFM', 'icon' => 'bi-tools', 'count' => 2, 'color' => '#ffc329']
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us | TEK-C Global Construction ERP</title>

    <?php include('includes/links.php'); ?>
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

        * {
            font-family: "Inter", sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background: #fff;
            color: var(--text);
            overflow-x: hidden;
        }

        .navbar {
            background: rgba(16, 24, 32, 0.96);
            backdrop-filter: blur(14px);
            padding: 15px 0;
            box-shadow: 0 8px 35px rgba(0,0,0,.25);
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff !important;
            font-weight: 900;
            letter-spacing: .5px;
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

        .nav-link:hover,
        .nav-link.active {
            color: var(--yellow) !important;
        }

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

        .search-box::placeholder {
            color: #abb6c3;
        }

        .btn-yellow {
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            color: #111;
            font-weight: 800;
            border: 0;
            border-radius: 12px;
            padding: 12px 24px;
            box-shadow: 0 12px 28px rgba(255, 179, 0, .35);
            transition: .35s;
        }

        .btn-yellow:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 40px rgba(255, 179, 0, .45);
        }

        .btn-light-custom {
            background: #fff;
            color: #111;
            font-weight: 800;
            border-radius: 12px;
            padding: 12px 24px;
            border: 0;
            transition: .35s;
        }

        .btn-light-custom:hover {
            transform: translateY(-3px);
        }

        .hero-about {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 150px 0 80px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-about::before {
            content: "";
            position: absolute;
            width: 500px;
            height: 500px;
            right: -100px;
            top: -100px;
            background: radial-gradient(circle, rgba(255,195,41,.15), transparent 70%);
            border-radius: 50%;
        }

        .hero-about h1 {
            font-size: clamp(42px, 5vw, 68px);
            font-weight: 900;
            line-height: 1.1;
        }

        .hero-about .yellow-text {
            color: var(--yellow);
        }

        section {
            padding: 85px 0;
        }

        .section-title {
            text-align: center;
            margin-bottom: 50px;
        }

        .section-title h2 {
            font-size: clamp(32px, 4vw, 48px);
            font-weight: 900;
            color: #111827;
            letter-spacing: -1px;
        }

        .section-title p {
            color: var(--muted);
            font-size: 17px;
        }

        .stat-circle {
            background: #fff;
            border-radius: 50%;
            width: 180px;
            height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            box-shadow: 0 20px 40px rgba(0,0,0,.1);
            border: 5px solid var(--yellow);
        }

        .stat-number {
            font-size: 48px;
            font-weight: 900;
            color: var(--dark);
        }

        .stat-label {
            color: var(--muted);
            font-weight: 600;
        }

        .value-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            height: 100%;
            transition: .35s;
            box-shadow: 0 10px 32px rgba(17,24,39,.05);
        }

        .value-card:hover {
            transform: translateY(-8px);
            border-color: var(--yellow);
        }

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

        .team-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            transition: .35s;
            height: 100%;
        }

        .team-card:hover {
            transform: translateY(-5px);
            border-color: var(--yellow);
        }

        .team-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 36px;
            color: #fff;
        }

        .client-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            transition: .35s;
            height: 100%;
        }

        .client-card:hover {
            transform: translateY(-5px);
            border-color: var(--yellow);
        }

        .company-logo {
            width: 100%;
            max-width: 200px;
            margin: 0 auto;
        }

        .milestone-item {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }

        .milestone-year {
            font-size: 28px;
            font-weight: 900;
            color: var(--yellow);
            min-width: 100px;
        }

        .milestone-content {
            flex: 1;
        }

        footer {
            background: #101820;
            color: #d8dee8;
            padding: 55px 0 25px;
        }

        footer h6 {
            color: #fff;
            font-weight: 900;
            margin-bottom: 18px;
        }

        footer a {
            display: block;
            color: #aeb8c5;
            text-decoration: none;
            margin-bottom: 11px;
            transition: .3s;
        }

        footer a:hover {
            color: var(--yellow);
            transform: translateX(5px);
        }

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

        .social-icon:hover {
            background: var(--yellow);
            color: #111;
            transform: translateY(-5px);
        }

        @media (max-width: 991px) {
            .search-box {
                min-width: 100%;
                margin: 12px 0;
            }
            
            .stat-circle {
                width: 140px;
                height: 140px;
            }
            
            .stat-number {
                font-size: 36px;
            }
        }
    
    </style>
</head>

<body>


    <?php include 'includes/nav.php'; ?>


<!-- Hero Section -->
<section class="hero-about">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>About <span class="yellow-text">TEK-C Global</span></h1>
                <p class="lead mt-4">India's leading Construction ERP solution provider, empowering builders and contractors with cutting-edge technology.</p>
            </div>
        </div>
    </div>
</section>

<!-- Company Overview -->
<section>
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <h2 class="fw-black mb-4">Who We Are</h2>
                <p class="fs-5 text-muted">
                    TEK-C Global is a comprehensive construction management platform developed by <strong>UKR Group</strong>, 
                    designed to streamline project execution, documentation, and team coordination for modern construction businesses.
                </p>
                <p class="text-muted">
                    With over <strong><?php echo $yearsOfOperation; ?>+ years</strong> of industry expertise, we've built a solution that 
                    addresses the real challenges faced by builders, contractors, and developers across India. Our platform 
                    integrates every aspect of construction management - from daily progress reports to financial approvals, 
                    from HR management to document control.
                </p>
                <div class="row mt-4">
                    <div class="col-4">
                        <div class="text-center">
                            <h3 class="fw-bold text-warning"><?php echo $siteCount; ?>+</h3>
                            <p class="text-muted small">Active Sites</p>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-center">
                            <h3 class="fw-bold text-warning"><?php echo $employeeCount; ?>+</h3>
                            <p class="text-muted small">Team Members</p>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-center">
                            <h3 class="fw-bold text-warning"><?php echo $clientCount; ?>+</h3>
                            <p class="text-muted small">Happy Clients</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <div class="overview-img" style="border-radius: 20px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,.1);">
                    <img src="https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1200&q=80" alt="About TEK-C" class="img-fluid" style="width: 100%; height: auto;">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Company Details -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Company <span class="yellow-text">Information</span></h2>
            <p>Learn more about our organization</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6" data-aos="fade-right">
                <div class="value-card text-start">
                    <div class="value-icon mx-0"><i class="bi bi-building"></i></div>
                    <h4 class="fw-bold mt-3"><?php echo htmlspecialchars($companyDetails['company_name'] ?? 'TEK-C Global'); ?></h4>
                    <p class="text-muted">
                        <i class="bi bi-geo-alt me-2 text-warning"></i> <?php echo htmlspecialchars($companyDetails['company_address'] ?? 'Dharmapuri, Tamil Nadu, India'); ?><br>
                        <i class="bi bi-telephone me-2 text-warning"></i> <?php echo htmlspecialchars($companyDetails['company_phone'] ?? '+91 72003 16099'); ?><br>
                        <i class="bi bi-envelope me-2 text-warning"></i> <?php echo htmlspecialchars($companyDetails['company_email'] ?? 'info@tekcglobal.com'); ?><br>
                        <i class="bi bi-globe me-2 text-warning"></i> <?php echo htmlspecialchars($companyDetails['company_website'] ?? 'https://tekcglobal.com'); ?>
                    </p>
                </div>
            </div>

            <div class="col-md-6" data-aos="fade-left">
                <div class="value-card text-start">
                    <div class="value-icon mx-0"><i class="bi bi-person-badge"></i></div>
                    <h4 class="fw-bold mt-3">Leadership</h4>
                    <p class="text-muted">
                        <strong><?php echo htmlspecialchars($companyDetails['ceo_name'] ?? 'Ariharasudhan P'); ?></strong><br>
                        <?php echo htmlspecialchars($companyDetails['ceo_designation'] ?? 'Director'); ?><br><br>
                        <strong>Established:</strong> <?php echo date('F Y', strtotime($companyDetails['established_date'] ?? '2020-01-01')); ?><br>
                        <strong>Experience:</strong> <?php echo $yearsOfOperation; ?>+ Years in Construction Industry
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Our Impact -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Our <span class="yellow-text">Impact</span></h2>
            <p>Numbers that speak for themselves</p>
        </div>

        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="stat-circle">
                    <div>
                        <div class="stat-number"><?php echo $siteCount; ?></div>
                        <div class="stat-label">Active Sites</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="stat-circle">
                    <div>
                        <div class="stat-number"><?php echo $completedProjects; ?></div>
                        <div class="stat-label">Completed Projects</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="stat-circle">
                    <div>
                        <div class="stat-number"><?php echo $reportCount; ?></div>
                        <div class="stat-label">Reports Generated</div>
                    </div>
                </div>
            </div>

            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="stat-circle">
                    <div>
                        <div class="stat-number">100%</div>
                        <div class="stat-label">Client Satisfaction</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Our Values -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Our Core <span class="yellow-text">Values</span></h2>
            <p>The principles that guide everything we do</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="value-card">
                    <div class="value-icon mx-auto"><i class="bi bi-star-fill"></i></div>
                    <h5 class="fw-bold">Excellence</h5>
                    <p class="text-muted">We strive for excellence in every aspect of our product and service delivery.</p>
                </div>
            </div>

            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="value-card">
                    <div class="value-icon mx-auto"><i class="bi bi-lightbulb-fill"></i></div>
                    <h5 class="fw-bold">Innovation</h5>
                    <p class="text-muted">Continuous innovation to bring cutting-edge solutions to construction management.</p>
                </div>
            </div>

            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="value-card">
                    <div class="value-icon mx-auto"><i class="bi bi-people-fill"></i></div>
                    <h5 class="fw-bold">Collaboration</h5>
                    <p class="text-muted">Building strong partnerships with clients for mutual growth and success.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Our Team -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Our <span class="yellow-text">Team</span></h2>
            <p>Meet the experts behind TEK-C</p>
        </div>

        <div class="row g-4">
            <?php 
            $departments = [
                ['name' => 'Project Management', 'icon' => 'bi-briefcase-fill', 'color' => '#ffc329'],
                ['name' => 'Quality Surveying', 'icon' => 'bi-calculator-fill', 'color' => '#ffc329'],
                ['name' => 'Human Resources', 'icon' => 'bi-people-fill', 'color' => '#ffc329'],
                ['name' => 'Accounts & Finance', 'icon' => 'bi-currency-rupee', 'color' => '#ffc329'],
                ['name' => 'Construction Management', 'icon' => 'bi-building', 'color' => '#ffc329'],
                ['name' => 'IT & Support', 'icon' => 'bi-gear-fill', 'color' => '#ffc329']
            ];
            foreach ($departments as $dept): 
            ?>
            <div class="col-lg-4 col-md-6" data-aos="fade-up">
                <div class="team-card">
                    <div class="team-icon mx-auto" style="background: linear-gradient(135deg, <?php echo $dept['color']; ?>, var(--yellow2));">
                        <i class="<?php echo $dept['icon']; ?>"></i>
                    </div>
                    <h5 class="fw-bold"><?php echo $dept['name']; ?></h5>
                    <p class="text-muted mb-0">Expert professionals dedicated to delivering excellence in construction management solutions.</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Our Clients -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Our <span class="yellow-text">Clients</span></h2>
            <p>Trusted by industry leaders</p>
        </div>

        <div class="row g-4" data-aos="fade-up">
            <?php if (!empty($recentClients)): ?>
                <?php foreach ($recentClients as $client): ?>
                <div class="col-md-4 col-sm-6">
                    <div class="client-card">
                        <h6 class="fw-bold mb-2"><?php echo htmlspecialchars($client['client_name']); ?></h6>
                        <p class="text-muted small mb-0">
                            <?php echo htmlspecialchars($client['client_type']); ?><br>
                            <?php echo htmlspecialchars($client['company_name'] ?? 'Individual'); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-center">
                    <p class="text-muted">Client data loading...</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Milestones -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Our <span class="yellow-text">Journey</span></h2>
            <p>Key milestones in our growth story</p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <div class="milestone-item">
                    <div class="milestone-year">2020</div>
                    <div class="milestone-content">
                        <h5 class="fw-bold">Company Founded</h5>
                        <p class="text-muted">TEK-C Global was established with a vision to revolutionize construction management.</p>
                    </div>
                </div>
                <div class="milestone-item">
                    <div class="milestone-year">2021</div>
                    <div class="milestone-content">
                        <h5 class="fw-bold">First Major Client</h5>
                        <p class="text-muted">Secured partnership with UKR Group as founding client for ERP implementation.</p>
                    </div>
                </div>
                <div class="milestone-item">
                    <div class="milestone-year">2022</div>
                    <div class="milestone-content">
                        <h5 class="fw-bold">Platform Launch</h5>
                        <p class="text-muted">Official launch of TEK-C Construction ERP platform with core modules.</p>
                    </div>
                </div>
                <div class="milestone-item">
                    <div class="milestone-year">2023</div>
                    <div class="milestone-content">
                        <h5 class="fw-bold">Expansion</h5>
                        <p class="text-muted">Expanded to serve 5+ construction companies across Tamil Nadu.</p>
                    </div>
                </div>
                <div class="milestone-item">
                    <div class="milestone-year">2024</div>
                    <div class="milestone-content">
                        <h5 class="fw-bold">Major Milestone</h5>
                        <p class="text-muted">Reached <?php echo $siteCount; ?>+ active sites and <?php echo $employeeCount; ?>+ users on platform.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Call to Action -->
<section class="final-cta">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8 mx-auto text-center" data-aos="fade-up">
                <h2 class="display-5 fw-black">
                    Ready to Transform Your <span class="yellow-text">Construction Business</span>?
                </h2>
                <p class="mt-3 fs-5">Join <?php echo $clientCount; ?>+ clients who trust TEK-C for their construction management needs</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="contact.php" class="btn btn-yellow">
                        <i class="bi bi-calendar2-check me-2"></i> Contact Us
                    </a>
                    <a href="#" class="btn btn-light-custom">
                        <i class="bi bi-download me-2"></i> Download Brochure
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
    AOS.init({
        duration: 1000,
        once: true,
        offset: 80
    });

    // Navbar active link on scroll
    const sections = document.querySelectorAll("section[id]");
    const navLinks = document.querySelectorAll(".nav-link");

    window.addEventListener("scroll", () => {
        let current = "";
        sections.forEach(section => {
            const sectionTop = section.offsetTop - 130;
            if (window.scrollY >= sectionTop) {
                current = section.getAttribute("id");
            }
        });
        navLinks.forEach(link => {
            link.classList.remove("active");
            if (link.getAttribute("href") === "#" + current) {
                link.classList.add("active");
            }
        });
    });

    // Search functionality
    const searchBox = document.querySelector(".search-box");
    if (searchBox) {
        searchBox.addEventListener("keyup", function() {
            const value = this.value.toLowerCase();
            const cards = document.querySelectorAll(".value-card, .team-card, .client-card");
            cards.forEach(card => {
                const text = card.innerText.toLowerCase();
                if (value === "" || text.includes(value)) {
                    card.style.opacity = "1";
                    card.style.display = "block";
                } else {
                    card.style.opacity = ".25";
                    card.style.display = "none";
                }
            });
        });
    }
</script>

</body>
</html>