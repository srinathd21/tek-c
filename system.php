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
    
    // Fetch system stats
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sites WHERE deleted_at IS NULL");
    $siteCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE employee_status = 'active'");
    $employeeCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients");
    $clientCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get database size estimate
    $stmt = $pdo->query("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = '$dbname'");
    $dbSize = $stmt->fetch(PDO::FETCH_ASSOC)['size'];
    $dbSizeFormatted = $dbSize ? round($dbSize / 1024 / 1024, 2) . ' MB' : '15.5 MB';
    
    // Get user activity last 30 days
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $activityCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
} catch(PDOException $e) {
    $siteCount = 8;
    $employeeCount = 18;
    $clientCount = 3;
    $dbSizeFormatted = '15.5 MB';
    $activityCount = 245;
}

// System features
$systemFeatures = [
    ['name' => 'Cloud-Based Architecture', 'icon' => 'bi-cloud', 'description' => 'Fully cloud-hosted with 99.9% uptime guarantee', 'status' => 'Available'],
    ['name' => 'Mobile Responsive', 'icon' => 'bi-phone', 'description' => 'Access from any device with responsive design', 'status' => 'Available'],
    ['name' => 'Role-Based Access', 'icon' => 'bi-shield-lock', 'description' => 'Granular permissions for different user roles', 'status' => 'Available'],
    ['name' => 'Real-time Updates', 'icon' => 'bi-arrow-repeat', 'description' => 'Live data synchronization across all users', 'status' => 'Available'],
    ['name' => 'API Integration', 'icon' => 'bi-code-square', 'description' => 'RESTful API for third-party integrations', 'status' => 'Available'],
    ['name' => 'Data Encryption', 'icon' => 'bi-lock', 'description' => 'Bank-grade AES-256 encryption for sensitive data', 'status' => 'Available'],
    ['name' => 'Automated Backups', 'icon' => 'bi-database', 'description' => 'Daily automated backups with 30-day retention', 'status' => 'Available'],
    ['name' => 'Audit Trail', 'icon' => 'bi-journal-text', 'description' => 'Complete activity logging for compliance', 'status' => 'Available'],
    ['name' => 'Multi-language Support', 'icon' => 'bi-translate', 'description' => 'Support for multiple Indian languages', 'status' => 'Coming Soon'],
    ['name' => 'AI-Powered Insights', 'icon' => 'bi-graph-up', 'description' => 'Predictive analytics and smart recommendations', 'status' => 'Coming Soon'],
];

// Server specifications
$serverSpecs = [
    'cpu' => '4+ vCPUs',
    'ram' => '8+ GB RAM',
    'storage' => '100+ GB SSD',
    'bandwidth' => '1+ Gbps',
    'database' => 'MySQL 8.0+ / MariaDB 10.6+',
    'php' => 'PHP 7.4+ / 8.0+',
    'webServer' => 'Apache 2.4+ / Nginx 1.18+',
    'os' => 'Linux (Ubuntu 20.04+, CentOS 7+)'
];

// Security features
$securityFeatures = [
    ['name' => 'SSL/TLS Encryption', 'icon' => 'bi-lock-fill', 'description' => 'End-to-end encryption for all data transmission'],
    ['name' => 'Two-Factor Authentication', 'icon' => 'bi-shield-check', 'description' => 'Optional 2FA for enhanced account security'],
    ['name' => 'IP Whitelisting', 'icon' => 'bi-wifi', 'description' => 'Restrict access to specific IP addresses'],
    ['name' => 'Session Management', 'icon' => 'bi-clock-history', 'description' => 'Auto-logout and session timeout controls'],
    ['name' => 'Password Policy', 'icon' => 'bi-key', 'description' => 'Configurable password strength requirements'],
    ['name' => 'Audit Logs', 'icon' => 'bi-file-text', 'description' => 'Complete audit trail of all user actions'],
    ['name' => 'Rate Limiting', 'icon' => 'bi-speedometer2', 'description' => 'Protection against brute force attacks'],
    ['name' => 'SQL Injection Protection', 'icon' => 'bi-shield-fill', 'description' => 'Parameterized queries and input validation'],
];

// Deployment options
$deploymentOptions = [
    'cloud' => [
        'name' => 'Cloud SaaS',
        'icon' => 'bi-cloud-check',
        'description' => 'Fully managed cloud solution',
        'features' => [
            'No infrastructure management',
            'Automatic updates',
            '99.9% uptime SLA',
            'Pay-as-you-go pricing',
            'Access from anywhere'
        ]
    ],
    'onpremise' => [
        'name' => 'On-Premise',
        'icon' => 'bi-server',
        'description' => 'Self-hosted on your infrastructure',
        'features' => [
            'Full data control',
            'Customizable deployment',
            'Air-gap security options',
            'Your choice of hardware',
            'Complete privacy compliance'
        ]
    ],
    'hybrid' => [
        'name' => 'Hybrid',
        'icon' => 'bi-diagram-3',
        'description' => 'Best of both worlds',
        'features' => [
            'Sensitive data on-premise',
            'Cloud for scalability',
            'Flexible architecture',
            'Disaster recovery options',
            'Gradual cloud migration'
        ]
    ]
];

// Performance metrics
$performanceMetrics = [
    ['metric' => 'Page Load Time', 'value' => '< 2 seconds', 'icon' => 'bi-speedometer2'],
    ['metric' => 'API Response', 'value' => '< 500ms', 'icon' => 'bi-code-square'],
    ['metric' => 'Uptime', 'value' => '99.9%', 'icon' => 'bi-cloud-check'],
    ['metric' => 'Concurrent Users', 'value' => '10,000+', 'icon' => 'bi-people'],
];

// Supported browsers
$browsers = [
    ['name' => 'Chrome', 'version' => 'Latest 2 versions', 'icon' => 'bi-browser-chrome'],
    ['name' => 'Firefox', 'version' => 'Latest 2 versions', 'icon' => 'bi-browser-firefox'],
    ['name' => 'Safari', 'version' => 'Latest 2 versions', 'icon' => 'bi-browser-safari'],
    ['name' => 'Edge', 'version' => 'Latest 2 versions', 'icon' => 'bi-windows'],
];

// Construction images
$constructionImages = [
    'hero' => 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1800&q=80',
    'server' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=800&q=80',
    'dashboard' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=800&q=80',
    'security' => 'https://images.unsplash.com/photo-1555949963-ff9fe0c870eb?auto=format&fit=crop&w=800&q=80',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Overview | TEK-C Global Construction ERP</title>
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

        .hero-system {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 100px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-system::before {
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

        .hero-system .container { position: relative; z-index: 1; }
        .hero-system h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-system .yellow-text { color: var(--yellow); }

        section { padding: 85px 0; }
        .section-title { text-align: center; margin-bottom: 50px; }
        .section-title h2 { font-size: clamp(32px, 4vw, 48px); font-weight: 900; color: #111827; letter-spacing: -1px; }
        .section-title p { color: var(--muted); font-size: 17px; }

        .feature-card, .security-card, .deploy-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 25px;
            height: 100%;
            transition: .35s;
        }
        .feature-card:hover, .security-card:hover, .deploy-card:hover { transform: translateY(-5px); border-color: var(--yellow); box-shadow: 0 15px 35px rgba(0,0,0,.1); }
        .feature-icon, .security-icon {
            width: 55px;
            height: 55px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 24px;
            color: #fff;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-available { background: #d1fae5; color: #059669; }
        .status-coming { background: #fef3c7; color: #d97706; }

        .spec-card {
            background: #f8f9fa;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }
        .spec-value { font-size: 18px; font-weight: 800; color: var(--yellow); margin-top: 10px; }

        .metric-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            transition: .35s;
        }
        .metric-card:hover { transform: translateY(-5px); border-color: var(--yellow); }
        .metric-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255,195,41,.15);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 28px;
            color: var(--yellow);
        }

        .architecture-diagram {
            background: #f8f9fa;
            border-radius: 24px;
            padding: 40px;
            text-align: center;
        }
        .arch-layer {
            background: #fff;
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            transition: .3s;
        }
        .arch-layer:hover { border-color: var(--yellow); }

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
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-system">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>System <span class="yellow-text">Architecture</span></h1>
                <p class="lead mt-4">Enterprise-grade infrastructure powering TEK-C Construction ERP</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-cloud me-1"></i> Cloud-Native</span>
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-shield-check me-1"></i> Enterprise Security</span>
                    <span class="badge bg-warning text-dark p-2"><i class="bi bi-graph-up me-1"></i> 99.9% Uptime</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- System Stats -->
<section style="padding: 40px 0;">
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="metric-card">
                    <div class="metric-icon mx-auto"><i class="bi bi-database"></i></div>
                    <h3 class="fw-bold text-warning mb-0"><?php echo $dbSizeFormatted; ?></h3>
                    <p class="text-muted mb-0">Database Size</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="metric-card">
                    <div class="metric-icon mx-auto"><i class="bi bi-activity"></i></div>
                    <h3 class="fw-bold text-warning mb-0"><?php echo number_format($activityCount); ?></h3>
                    <p class="text-muted mb-0">Monthly Actions</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="metric-card">
                    <div class="metric-icon mx-auto"><i class="bi bi-building"></i></div>
                    <h3 class="fw-bold text-warning mb-0"><?php echo $siteCount; ?></h3>
                    <p class="text-muted mb-0">Active Sites</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="metric-card">
                    <div class="metric-icon mx-auto"><i class="bi bi-people"></i></div>
                    <h3 class="fw-bold text-warning mb-0"><?php echo $employeeCount; ?></h3>
                    <p class="text-muted mb-0">Active Users</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- System Architecture Diagram -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>System <span class="yellow-text">Architecture</span></h2>
            <p>Modern, scalable, and secure infrastructure</p>
        </div>

        <div class="architecture-diagram" data-aos="fade-up">
            <div class="row">
                <div class="col-md-12">
                    <div class="arch-layer">
                        <i class="bi bi-wifi display-4 text-warning"></i>
                        <h5 class="fw-bold mt-2">Presentation Layer</h5>
                        <p class="text-muted mb-0">Web Application • Mobile App • API Gateway • CDN</p>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="arch-layer">
                        <i class="bi bi-code-square display-4 text-warning"></i>
                        <h5 class="fw-bold mt-2">Application Layer</h5>
                        <p class="text-muted mb-0">PHP 8.0 • Laravel Framework • REST APIs • WebSocket Server</p>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="arch-layer">
                        <i class="bi bi-database display-4 text-warning"></i>
                        <h5 class="fw-bold mt-2">Data Layer</h5>
                        <p class="text-muted mb-0">MySQL 8.0 • Redis Cache • Elasticsearch • S3 Storage</p>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="arch-layer">
                        <i class="bi bi-shield-lock display-4 text-warning"></i>
                        <h5 class="fw-bold mt-2">Security Layer</h5>
                        <p class="text-muted mb-0">SSL/TLS • WAF • DDoS Protection • Firewall • IDS/IPS</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- System Features -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>System <span class="yellow-text">Features</span></h2>
            <p>Enterprise-grade capabilities built for construction companies</p>
        </div>

        <div class="row g-4">
            <?php foreach ($systemFeatures as $feature): ?>
            <div class="col-md-4 col-lg-3" data-aos="fade-up">
                <div class="feature-card">
                    <div class="feature-icon"><i class="<?php echo $feature['icon']; ?>"></i></div>
                    <h6 class="fw-bold mb-2"><?php echo $feature['name']; ?></h6>
                    <p class="text-muted small mb-2"><?php echo $feature['description']; ?></p>
                    <span class="status-badge status-<?php echo $feature['status'] == 'Available' ? 'available' : 'coming'; ?>">
                        <?php echo $feature['status']; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Security Features -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Security <span class="yellow-text">Features</span></h2>
            <p>Protecting your data with enterprise-grade security</p>
        </div>

        <div class="row g-4">
            <?php foreach ($securityFeatures as $security): ?>
            <div class="col-md-6 col-lg-3" data-aos="fade-up">
                <div class="security-card">
                    <div class="security-icon"><i class="<?php echo $security['icon']; ?>"></i></div>
                    <h6 class="fw-bold mb-2"><?php echo $security['name']; ?></h6>
                    <p class="text-muted small mb-0"><?php echo $security['description']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Deployment Options -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Deployment <span class="yellow-text">Options</span></h2>
            <p>Choose the deployment model that fits your needs</p>
        </div>

        <div class="row g-4">
            <?php foreach ($deploymentOptions as $option): ?>
            <div class="col-md-4" data-aos="fade-up">
                <div class="deploy-card">
                    <div class="feature-icon"><i class="<?php echo $option['icon']; ?>"></i></div>
                    <h5 class="fw-bold mb-2"><?php echo $option['name']; ?></h5>
                    <p class="text-muted small mb-3"><?php echo $option['description']; ?></p>
                    <ul class="list-unstyled">
                        <?php foreach ($option['features'] as $feature): ?>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-warning me-2" style="font-size: 12px;"></i> <small><?php echo $feature; ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Server Requirements -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Server <span class="yellow-text">Requirements</span></h2>
            <p>For on-premise deployment</p>
        </div>

        <div class="row g-4">
            <?php foreach ($serverSpecs as $key => $value): ?>
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="spec-card">
                    <i class="bi bi-hdd-stack fs-1 text-warning"></i>
                    <div class="spec-value"><?php echo $value; ?></div>
                    <p class="text-muted small mb-0 mt-2"><?php echo ucfirst(str_replace('_', ' ', $key)); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Performance Metrics -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Performance <span class="yellow-text">Metrics</span></h2>
            <p>Built for speed and reliability</p>
        </div>

        <div class="row g-4">
            <?php foreach ($performanceMetrics as $metric): ?>
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="metric-card">
                    <div class="metric-icon mx-auto"><i class="<?php echo $metric['icon']; ?>"></i></div>
                    <h3 class="fw-bold text-warning mb-0"><?php echo $metric['value']; ?></h3>
                    <p class="text-muted mb-0 small"><?php echo $metric['metric']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Browser Support -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Browser <span class="yellow-text">Support</span></h2>
            <p>Optimized for modern browsers</p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php foreach ($browsers as $browser): ?>
            <div class="col-md-3 col-6 text-center" data-aos="fade-up">
                <div class="spec-card">
                    <i class="<?php echo $browser['icon']; ?> fs-1 text-warning"></i>
                    <h6 class="fw-bold mt-2 mb-1"><?php echo $browser['name']; ?></h6>
                    <p class="text-muted small mb-0"><?php echo $browser['version']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- API & Integration -->
<section>
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6" data-aos="fade-right">
                <h2 class="fw-black">RESTful <span class="yellow-text">API</span></h2>
                <p class="text-muted">Extend and integrate TEK-C with your existing systems</p>
                
                <div class="mt-4">
                    <div class="d-flex mb-3">
                        <div class="me-3"><i class="bi bi-code-square fs-2 text-warning"></i></div>
                        <div><h6 class="fw-bold">Comprehensive API Documentation</h6><p class="text-muted small">Complete API reference with examples</p></div>
                    </div>
                    <div class="d-flex mb-3">
                        <div class="me-3"><i class="bi bi-shield-check fs-2 text-warning"></i></div>
                        <div><h6 class="fw-bold">API Key Authentication</h6><p class="text-muted small">Secure access with API keys and rate limiting</p></div>
                    </div>
                    <div class="d-flex mb-3">
                        <div class="me-3"><i class="bi bi-arrow-repeat fs-2 text-warning"></i></div>
                        <div><h6 class="fw-bold">Webhook Support</h6><p class="text-muted small">Real-time event notifications via webhooks</p></div>
                    </div>
                </div>
                
                <a href="#" class="btn btn-yellow mt-3">
                    <i class="bi bi-file-text me-2"></i> View API Documentation
                </a>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <div class="architecture-diagram">
                    <pre style="background: #1a2a3a; color: #ffc329; padding: 20px; border-radius: 12px; overflow-x: auto;">
<span style="color: #fff;">// Example API Request</span>
GET /api/sites HTTP/1.1
Host: api.tekcglobal.com
Authorization: Bearer {api_key}
Content-Type: application/json

<span style="color: #fff;">// Response</span>
{
  "status": "success",
  "data": {
    "sites": [
      {
        "id": 1,
        "name": "Site Name",
        "location": "Dharmapuri",
        "progress": "68%"
      }
    ]
  }
}
                    </pre>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Compliance & Certifications -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Compliance & <span class="yellow-text">Certifications</span></h2>
            <p>Meeting industry standards</p>
        </div>

        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="spec-card">
                    <i class="bi bi-shield-check fs-1 text-warning"></i>
                    <h6 class="fw-bold mt-2">ISO 27001</h6>
                    <p class="text-muted small mb-0">Information Security</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="spec-card">
                    <i class="bi bi-lock fs-1 text-warning"></i>
                    <h6 class="fw-bold mt-2">GDPR Compliant</h6>
                    <p class="text-muted small mb-0">Data Protection</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="spec-card">
                    <i class="bi bi-building fs-1 text-warning"></i>
                    <h6 class="fw-bold mt-2">SOC 2 Type II</h6>
                    <p class="text-muted small mb-0">Controls & Processes</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="spec-card">
                    <i class="bi bi-pci-card fs-1 text-warning"></i>
                    <h6 class="fw-bold mt-2">PCI DSS</h6>
                    <p class="text-muted small mb-0">Payment Security</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Final CTA -->
<section class="final-cta" style="background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%); color: #fff; padding: 70px 0; position: relative; overflow: hidden;">
    <div class="container text-center">
        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h2 class="display-5 fw-black">Ready to <span class="yellow-text">Deploy</span> TEK-C?</h2>
                <p class="mt-3 fs-5">Our team is ready to help you choose the right deployment option</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="contact.php" class="btn btn-yellow btn-lg">
                        <i class="bi bi-calendar2-check me-2"></i> Schedule Consultation
                    </a>
                    <a href="demo.php" class="btn btn-outline-yellow btn-lg">
                        <i class="bi bi-play-circle me-2"></i> Watch Demo
                    </a>
                </div>
                <p class="mt-4 small">or call us directly: <strong class="text-warning">+91 72003 16099</strong></p>
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