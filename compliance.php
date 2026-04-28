<?php
// Start session
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'u209621005_tekc';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch stats
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sites WHERE deleted_at IS NULL");
    $siteCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE employee_status = 'active'");
    $employeeCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients");
    $clientCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch(PDOException $e) {
    $siteCount = 8;
    $employeeCount = 18;
    $clientCount = 3;
}

// Last updated date
$lastUpdated = "April 28, 2026";

// Certifications
$certifications = [
    ['name' => 'ISO 27001:2022', 'icon' => 'bi-shield-check', 'status' => 'Certified', 'date' => '2024', 'description' => 'Information Security Management System'],
    ['name' => 'ISO 9001:2015', 'icon' => 'bi-award', 'status' => 'Certified', 'date' => '2024', 'description' => 'Quality Management System'],
    ['name' => 'SOC 2 Type II', 'icon' => 'bi-building', 'status' => 'In Progress', 'date' => '2025', 'description' => 'Service Organization Control'],
    ['name' => 'GDPR Compliant', 'icon' => 'bi-globe', 'status' => 'Compliant', 'date' => '2023', 'description' => 'EU Data Protection Regulation'],
    ['name' => 'CCPA Ready', 'icon' => 'bi-person-check', 'status' => 'Compliant', 'date' => '2023', 'description' => 'California Consumer Privacy Act'],
    ['name' => 'PCI DSS', 'icon' => 'bi-credit-card', 'status' => 'Level 1', 'date' => '2024', 'description' => 'Payment Card Industry Security'],
];

// Data protection measures
$dataProtection = [
    ['name' => 'Encryption at Rest', 'icon' => 'bi-lock', 'standard' => 'AES-256', 'description' => 'All stored data encrypted using industry-standard AES-256 encryption'],
    ['name' => 'Encryption in Transit', 'icon' => 'bi-shield', 'standard' => 'TLS 1.3', 'description' => 'Data transmitted using TLS 1.3 protocol for maximum security'],
    ['name' => 'Access Controls', 'icon' => 'bi-person-lock', 'standard' => 'RBAC + MFA', 'description' => 'Role-based access control with multi-factor authentication'],
    ['name' => 'Backup & Recovery', 'icon' => 'bi-database', 'standard' => 'Daily + 30-day', 'description' => 'Automated daily backups with 30-day retention period'],
    ['name' => 'Audit Logging', 'icon' => 'bi-journal-text', 'standard' => 'Complete Trail', 'description' => 'Comprehensive audit trail of all user actions and system events'],
    ['name' => 'Penetration Testing', 'icon' => 'bi-bug', 'standard' => 'Quarterly', 'description' => 'Regular independent security assessments'],
];

// Regulatory frameworks
$regulatoryFrameworks = [
    ['name' => 'Information Technology Act, 2000', 'country' => 'India', 'status' => 'Compliant', 'description' => 'Indian cyber law and data protection requirements'],
    ['name' => 'Digital Personal Data Protection Act, 2023', 'country' => 'India', 'status' => 'Preparing', 'description' => 'New Indian data protection framework'],
    ['name' => 'GDPR (EU) 2016/679', 'country' => 'European Union', 'status' => 'Compliant', 'description' => 'European data protection regulation'],
    ['name' => 'CCPA (California)', 'country' => 'USA', 'status' => 'Compliant', 'description' => 'California consumer privacy rights'],
    ['name' => 'LGPD (Brazil)', 'country' => 'Brazil', 'status' => 'In Progress', 'description' => 'Brazilian general data protection law'],
    ['name' => 'PIPEDA (Canada)', 'country' => 'Canada', 'status' => 'In Progress', 'description' => 'Canadian privacy framework'],
];

// Compliance policies
$policies = [
    ['name' => 'Data Privacy Policy', 'icon' => 'bi-file-text', 'version' => 'v2.1', 'updated' => 'April 2026', 'link' => 'privacy-policy.php'],
    ['name' => 'Terms of Service', 'icon' => 'bi-file-earmark-text', 'version' => 'v2.1', 'updated' => 'April 2026', 'link' => 'terms.php'],
    ['name' => 'Acceptable Use Policy', 'icon' => 'bi-check-circle', 'version' => 'v1.5', 'updated' => 'January 2026', 'link' => '#'],
    ['name' => 'Data Retention Policy', 'icon' => 'bi-clock-history', 'version' => 'v1.2', 'updated' => 'March 2026', 'link' => '#'],
    ['name' => 'Incident Response Plan', 'icon' => 'bi-exclamation-triangle', 'version' => 'v2.0', 'updated' => 'February 2026', 'link' => '#'],
    ['name' => 'Business Continuity Plan', 'icon' => 'bi-arrow-repeat', 'version' => 'v1.8', 'updated' => 'December 2025', 'link' => '#'],
];

// Security features
$securityFeatures = [
    ['name' => 'Two-Factor Authentication (2FA)', 'status' => 'Available', 'icon' => 'bi-shield-lock'],
    ['name' => 'Single Sign-On (SSO)', 'status' => 'Enterprise', 'icon' => 'bi-key'],
    ['name' => 'IP Whitelisting', 'status' => 'Available', 'icon' => 'bi-wifi'],
    ['name' => 'Session Management', 'status' => 'Available', 'icon' => 'bi-clock'],
    ['name' => 'Password Policy Enforcement', 'status' => 'Available', 'icon' => 'bi-key-fill'],
    ['name' => 'Automated Backup', 'status' => 'Available', 'icon' => 'bi-database-check'],
];

// Construction images
$constructionImages = [
    'hero' => 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1800&q=80',
    'security' => 'https://images.unsplash.com/photo-1555949963-ff9fe0c870eb?auto=format&fit=crop&w=800&q=80',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance | TEK-C Global Construction ERP</title>

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
        .btn-outline-yellow {
            background: transparent;
            border: 2px solid var(--yellow);
            color: var(--yellow);
            font-weight: 800;
            border-radius: 12px;
            padding: 12px 24px;
            transition: .35s;
        }
        .btn-outline-yellow:hover { background: var(--yellow); color: #111; transform: translateY(-3px); }

        .hero-compliance {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 80px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-compliance::before {
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

        .hero-compliance .container { position: relative; z-index: 1; }
        .hero-compliance h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-compliance .yellow-text { color: var(--yellow); }

        section { padding: 60px 0; }
        
        .compliance-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 25px;
            height: 100%;
            transition: .35s;
        }
        .compliance-card:hover { transform: translateY(-5px); border-color: var(--yellow); box-shadow: 0 10px 30px rgba(0,0,0,.05); }
        
        .cert-badge {
            display: inline-block;
            width: 60px;
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            font-size: 28px;
            color: #fff;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-certified { background: #d1fae5; color: #059669; }
        .status-progress { background: #fef3c7; color: #d97706; }
        .status-compliant { background: #dbeafe; color: #2563eb; }
        
        .policy-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            transition: .3s;
        }
        .policy-card:hover { background: #f8f9fa; border-color: var(--yellow); }
        
        .security-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid var(--border);
        }
        .security-item:last-child { border-bottom: none; }

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
        }
        
        @media print {
            .navbar, .search-box, .btn-yellow, footer, .hero-compliance { display: none; }
            body { padding-top: 0; }
        }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-compliance">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>Compliance & <span class="yellow-text">Certifications</span></h1>
                <p class="lead mt-4">TEK-C Global is committed to maintaining the highest standards of security, privacy, and regulatory compliance.</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-shield-check me-1"></i> ISO 27001 Certified</span>
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-globe me-1"></i> GDPR Compliant</span>
                    <span class="badge bg-warning text-dark p-2"><i class="bi bi-lock me-1"></i> SOC 2 Ready</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Certifications Section -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Our <span class="yellow-text">Certifications</span></h2>
            <p>Industry-recognized standards we adhere to</p>
        </div>

        <div class="row g-4">
            <?php foreach ($certifications as $cert): ?>
            <div class="col-md-4 col-lg-4" data-aos="fade-up">
                <div class="compliance-card text-center">
                    <div class="cert-badge mx-auto"><i class="<?php echo $cert['icon']; ?>"></i></div>
                    <h5 class="fw-bold mt-2"><?php echo $cert['name']; ?></h5>
                    <span class="status-badge status-<?php echo strtolower($cert['status'] == 'Certified' ? 'certified' : ($cert['status'] == 'Compliant' ? 'compliant' : 'progress')); ?> mb-2 d-inline-block">
                        <?php echo $cert['status']; ?>
                    </span>
                    <p class="text-muted small mt-2 mb-0"><?php echo $cert['description']; ?></p>
                    <small class="text-muted">Certified since <?php echo $cert['date']; ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Data Protection Measures -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Data Protection <span class="yellow-text">Measures</span></h2>
            <p>How we protect your data</p>
        </div>

        <div class="row g-4">
            <?php foreach ($dataProtection as $measure): ?>
            <div class="col-md-6 col-lg-4" data-aos="fade-up">
                <div class="compliance-card">
                    <div class="d-flex align-items-center mb-3">
                        <div class="cert-badge me-3" style="width: 50px; height: 50px; font-size: 24px;"><i class="<?php echo $measure['icon']; ?>"></i></div>
                        <div>
                            <h6 class="fw-bold mb-0"><?php echo $measure['name']; ?></h6>
                            <small class="text-warning fw-bold"><?php echo $measure['standard']; ?></small>
                        </div>
                    </div>
                    <p class="text-muted small mb-0"><?php echo $measure['description']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Regulatory Frameworks -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Regulatory <span class="yellow-text">Frameworks</span></h2>
            <p>Compliance with global regulations</p>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <div class="compliance-card">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Regulation</th>
                                    <th>Jurisdiction</th>
                                    <th>Status</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($regulatoryFrameworks as $framework): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo $framework['name']; ?></td>
                                    <td><span class="badge bg-light text-dark"><?php echo $framework['country']; ?></span></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($framework['status'] == 'Compliant' ? 'certified' : ($framework['status'] == 'Preparing' ? 'progress' : 'progress')); ?>">
                                            <?php echo $framework['status']; ?>
                                        </span>
                                    </td>
                                    <td><small class="text-muted"><?php echo $framework['description']; ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Compliance Policies -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Compliance <span class="yellow-text">Policies</span></h2>
            <p>Our commitment to transparency</p>
        </div>

        <div class="row g-4">
            <?php foreach ($policies as $policy): ?>
            <div class="col-md-6 col-lg-4" data-aos="fade-up">
                <div class="policy-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <div class="d-flex align-items-center">
                            <i class="<?php echo $policy['icon']; ?> fs-3 text-warning me-3"></i>
                            <div>
                                <h6 class="fw-bold mb-0"><?php echo $policy['name']; ?></h6>
                                <small class="text-muted">Version <?php echo $policy['version']; ?> • Updated <?php echo $policy['updated']; ?></small>
                            </div>
                        </div>
                        <a href="<?php echo $policy['link']; ?>" class="btn btn-sm btn-outline-yellow">View</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Security Features -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Security <span class="yellow-text">Features</span></h2>
            <p>Built-in security capabilities</p>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="compliance-card">
                    <?php foreach ($securityFeatures as $feature): ?>
                    <div class="security-item">
                        <div>
                            <i class="<?php echo $feature['icon']; ?> text-warning me-2"></i>
                            <strong><?php echo $feature['name']; ?></strong>
                        </div>
                        <span class="badge <?php echo $feature['status'] == 'Available' ? 'bg-success' : ($feature['status'] == 'Enterprise' ? 'bg-warning' : 'bg-secondary'); ?> text-white">
                            <?php echo $feature['status']; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Compliance Statements -->
<section class="bg-light">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-6" data-aos="fade-right">
                <div class="compliance-card">
                    <div class="cert-badge" style="width: 50px; height: 50px; font-size: 24px;"><i class="bi bi-file-text"></i></div>
                    <h5 class="fw-bold mt-3">GDPR Compliance Statement</h5>
                    <p class="text-muted small">TEK-C Global is fully compliant with the General Data Protection Regulation (GDPR). We process personal data lawfully, transparently, and for specified purposes. Users have the right to access, rectify, erase, and port their data.</p>
                    <a href="privacy-policy.php" class="text-warning text-decoration-none">View our GDPR compliance details →</a>
                </div>
            </div>
            <div class="col-md-6" data-aos="fade-left">
                <div class="compliance-card">
                    <div class="cert-badge" style="width: 50px; height: 50px; font-size: 24px;"><i class="bi bi-shield-check"></i></div>
                    <h5 class="fw-bold mt-3">Data Processing Agreement (DPA)</h5>
                    <p class="text-muted small">For customers who need a Data Processing Agreement, we offer standard DPAs that comply with GDPR requirements. Contact our legal team to request a DPA.</p>
                    <a href="contact.php" class="text-warning text-decoration-none">Request DPA →</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Third-Party Audits -->
<section>
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6" data-aos="fade-right">
                <h2 class="fw-black">Independent <span class="yellow-text">Audits</span></h2>
                <p class="text-muted">We engage third-party security firms to conduct regular audits of our systems and processes.</p>
                
                <div class="mt-4">
                    <div class="d-flex mb-3">
                        <div class="me-3"><i class="bi bi-check-circle-fill text-warning"></i></div>
                        <div><strong>Annual Penetration Testing</strong> - Independent security assessments</div>
                    </div>
                    <div class="d-flex mb-3">
                        <div class="me-3"><i class="bi bi-check-circle-fill text-warning"></i></div>
                        <div><strong>Quarterly Vulnerability Scans</strong> - Automated and manual scanning</div>
                    </div>
                    <div class="d-flex mb-3">
                        <div class="me-3"><i class="bi bi-check-circle-fill text-warning"></i></div>
                        <div><strong>Code Security Reviews</strong> - Regular code audits and reviews</div>
                    </div>
                    <div class="d-flex mb-3">
                        <div class="me-3"><i class="bi bi-check-circle-fill text-warning"></i></div>
                        <div><strong>SOC 2 Type II Audit</strong> - Controls and processes verification</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <div class="compliance-card text-center" style="background: linear-gradient(135deg, #101820, #1a2a3a); color: #fff;">
                    <i class="bi bi-file-earmark-check display-1 text-warning mb-3"></i>
                    <h4>Request Compliance Documentation</h4>
                    <p class="text-white-50">Need our compliance documentation for your audit? Contact our compliance team.</p>
                    <a href="contact.php" class="btn btn-yellow mt-2">
                        <i class="bi bi-envelope me-2"></i> Request Documents
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Quick Stats -->
<section class="bg-light">
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-shield-check fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2">ISO 27001</h3>
                    <p class="text-muted small mb-0">Certified</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-globe fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2">3+</h3>
                    <p class="text-muted small mb-0">Global Regulations</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-building fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2"><?php echo $clientCount; ?></h3>
                    <p class="text-muted small mb-0">Trusted Clients</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-lock fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2">100%</h3>
                    <p class="text-muted small mb-0">Data Encrypted</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contact Compliance Team -->
<section class="final-cta" style="background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%); color: #fff; padding: 70px 0; position: relative; overflow: hidden;">
    <div class="container text-center">
        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h2 class="display-5 fw-black">Questions About <span class="yellow-text">Compliance</span>?</h2>
                <p class="mt-3 fs-5">Our compliance team is ready to assist you</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="contact.php" class="btn btn-yellow btn-lg">
                        <i class="bi bi-envelope me-2"></i> Contact Compliance Team
                    </a>
                    <a href="#" class="btn btn-outline-yellow btn-lg" onclick="window.print();">
                        <i class="bi bi-printer me-2"></i> Print Compliance Info
                    </a>
                </div>
                <p class="mt-4 small">For compliance inquiries: <strong class="text-warning">compliance@tekcglobal.com</strong></p>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
    AOS.init({ duration: 1000, once: true, offset: 80 });

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
</script>

</body>
</html>