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
    
    // Get stats for pricing page
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sites WHERE deleted_at IS NULL");
    $siteCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE employee_status = 'active'");
    $employeeCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients");
    $clientCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM dpr_reports");
    $reportCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total quotations processed
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM quotations");
    $quotationCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch(PDOException $e) {
    $siteCount = 8;
    $employeeCount = 18;
    $clientCount = 3;
    $reportCount = 47;
    $quotationCount = 7;
}

// Construction images
$constructionImages = [
    'hero' => 'https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=1800&q=80',
    'team' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=800&q=80',
    'meeting' => 'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=800&q=80',
    'site' => 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=800&q=80',
    'blueprint' => 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?auto=format&fit=crop&w=800&q=80',
    'office' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=800&q=80',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing | TEK-C Global Construction ERP</title>
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

        .hero-pricing {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 100px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-pricing::before {
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

        .hero-pricing .container { position: relative; z-index: 1; }
        .hero-pricing h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-pricing .yellow-text { color: var(--yellow); }

        section { padding: 85px 0; }
        .section-title { text-align: center; margin-bottom: 50px; }
        .section-title h2 { font-size: clamp(32px, 4vw, 48px); font-weight: 900; color: #111827; letter-spacing: -1px; }
        .section-title p { color: var(--muted); font-size: 17px; }

        .pricing-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px 30px;
            height: 100%;
            transition: .35s;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 32px rgba(17,24,39,.05);
        }

        .pricing-card:hover { transform: translateY(-10px); border-color: var(--yellow); box-shadow: 0 20px 40px rgba(17,24,39,.15); }
        .pricing-card.popular { border: 2px solid var(--yellow); box-shadow: 0 15px 35px rgba(255,195,41,.15); }
        .popular-badge {
            position: absolute;
            top: 20px;
            right: -30px;
            background: var(--yellow);
            color: #111;
            padding: 8px 40px;
            font-size: 12px;
            font-weight: 800;
            transform: rotate(45deg);
        }

        .pricing-icon {
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

        .price { font-size: 48px; font-weight: 900; color: #111827; }
        .price-period { font-size: 16px; color: var(--muted); font-weight: 500; }
        .feature-list-custom { list-style: none; padding: 0; margin: 25px 0; }
        .feature-list-custom li { padding: 10px 0; border-bottom: 1px solid var(--border); }
        .feature-list-custom li i { color: #22c55e; margin-right: 10px; font-weight: bold; }
        .feature-list-custom li.disabled i { color: #ef4444; }
        .feature-list-custom li.disabled { color: var(--muted); text-decoration: line-through; }

        .comparison-table th, .comparison-table td { padding: 15px; vertical-align: middle; }
        .comparison-table .feature-cell { font-weight: 700; background: #f8f9fa; }

        .testimonial-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            height: 100%;
            transition: .35s;
        }
        .testimonial-card:hover { transform: translateY(-5px); border-color: var(--yellow); }
        .stars { color: var(--yellow2); letter-spacing: 3px; margin-bottom: 15px; }
        .avatar { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; }

        .faq-item {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 12px;
            overflow: hidden;
        }
        .accordion-button { font-weight: 800; padding: 20px 24px; }
        .accordion-button:not(.collapsed) { background: rgba(255,195,41,.12); color: #111; box-shadow: none; }

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
            background: url('<?php echo $constructionImages['site']; ?>') center/cover no-repeat;
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

        .toggle-switch {
            display: inline-flex;
            background: #f0f0f0;
            border-radius: 50px;
            padding: 5px;
        }
        .toggle-option {
            padding: 10px 25px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            transition: .3s;
        }
        .toggle-option.active { background: var(--yellow); color: #111; }

        @media (max-width: 991px) {
            .search-box { min-width: 100%; margin: 12px 0; }
            .pricing-card { margin-bottom: 20px; }
        }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-pricing">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>Simple, <span class="yellow-text">Transparent</span> Pricing</h1>
                <p class="lead mt-4">Choose the perfect plan for your construction business. No hidden fees, no surprises.</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2">No Setup Fee</span>
                    <span class="badge bg-warning text-dark me-2 p-2">Cancel Anytime</span>
                    <span class="badge bg-warning text-dark p-2">Free Demo</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Pricing Toggle & Cards -->
<section style="padding: 60px 0 0 0;">
    <div class="container">
        <div class="text-center mb-5" data-aos="fade-up">
            <div class="toggle-switch">
                <span class="toggle-option active" data-plan="monthly">Monthly Billing</span>
                <span class="toggle-option" data-plan="yearly">Yearly Billing <span class="badge bg-success ms-2">Save 20%</span></span>
            </div>
        </div>

        <div class="row g-4">
            <!-- Basic Plan -->
            <div class="col-lg-4" data-aos="fade-up">
                <div class="pricing-card">
                    <div class="pricing-icon mx-auto"><i class="bi bi-rocket-takeoff"></i></div>
                    <h3 class="text-center fw-bold mt-3">Starter</h3>
                    <div class="text-center mt-3">
                        <span class="price monthly-price">₹4,999</span>
                        <span class="price yearly-price" style="display: none;">₹3,999</span>
                        <span class="price-period">/month</span>
                    </div>
                    <p class="text-center text-muted mt-2">Perfect for small builders & contractors</p>
                    <ul class="feature-list-custom">
                        <li><i class="bi bi-check-circle-fill"></i> Up to 5 Active Sites</li>
                        <li><i class="bi bi-check-circle-fill"></i> Up to 20 Employees</li>
                        <li><i class="bi bi-check-circle-fill"></i> Basic Reports (DPR, DAR, MOM)</li>
                        <li><i class="bi bi-check-circle-fill"></i> Quotation Management</li>
                        <li><i class="bi bi-check-circle-fill"></i> Attendance Tracking</li>
                        <li><i class="bi bi-check-circle-fill"></i> Email Support</li>
                        <li class="disabled"><i class="bi bi-x-circle-fill"></i> API Access</li>
                        <li class="disabled"><i class="bi bi-x-circle-fill"></i> White-label Option</li>
                    </ul>
                    <a href="#" class="btn btn-outline-yellow w-100">Get Started</a>
                </div>
            </div>

            <!-- Professional Plan (Popular) -->
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="100">
                <div class="pricing-card popular">
                    <div class="popular-badge">MOST POPULAR</div>
                    <div class="pricing-icon mx-auto"><i class="bi bi-building"></i></div>
                    <h3 class="text-center fw-bold mt-3">Professional</h3>
                    <div class="text-center mt-3">
                        <span class="price monthly-price">₹9,999</span>
                        <span class="price yearly-price" style="display: none;">₹7,999</span>
                        <span class="price-period">/month</span>
                    </div>
                    <p class="text-center text-muted mt-2">Best for growing construction companies</p>
                    <ul class="feature-list-custom">
                        <li><i class="bi bi-check-circle-fill"></i> Up to 20 Active Sites</li>
                        <li><i class="bi bi-check-circle-fill"></i> Unlimited Employees</li>
                        <li><i class="bi bi-check-circle-fill"></i> All Reports (DPR, DAR, MOM, RFI, AIT)</li>
                        <li><i class="bi bi-check-circle-fill"></i> Advanced Quotation & Tendering</li>
                        <li><i class="bi bi-check-circle-fill"></i> HR & Payroll Management</li>
                        <li><i class="bi bi-check-circle-fill"></i> Document Control System</li>
                        <li><i class="bi bi-check-circle-fill"></i> API Access</li>
                        <li><i class="bi bi-check-circle-fill"></i> Priority Support</li>
                        <li class="disabled"><i class="bi bi-x-circle-fill"></i> White-label Option</li>
                    </ul>
                    <a href="#" class="btn btn-yellow w-100">Get Started</a>
                </div>
            </div>

            <!-- Enterprise Plan -->
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
                <div class="pricing-card">
                    <div class="pricing-icon mx-auto"><i class="bi bi-buildings-fill"></i></div>
                    <h3 class="text-center fw-bold mt-3">Enterprise</h3>
                    <div class="text-center mt-3">
                        <span class="price">Custom</span>
                        <span class="price-period"></span>
                    </div>
                    <p class="text-center text-muted mt-2">For large developers & enterprises</p>
                    <ul class="feature-list-custom">
                        <li><i class="bi bi-check-circle-fill"></i> Unlimited Sites</li>
                        <li><i class="bi bi-check-circle-fill"></i> Unlimited Employees</li>
                        <li><i class="bi bi-check-circle-fill"></i> All Features Included</li>
                        <li><i class="bi bi-check-circle-fill"></i> White-label Ready</li>
                        <li><i class="bi bi-check-circle-fill"></i> Custom Workflows</li>
                        <li><i class="bi bi-check-circle-fill"></i> On-premise Deployment</li>
                        <li><i class="bi bi-check-circle-fill"></i> Dedicated Account Manager</li>
                        <li><i class="bi bi-check-circle-fill"></i> 24/7 Priority Support</li>
                        <li><i class="bi bi-check-circle-fill"></i> SLA Guarantee</li>
                    </ul>
                    <a href="#" class="btn btn-outline-yellow w-100">Contact Sales</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Stats Section -->
<section class="bg-light">
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="stat-card-mini" style="background: #fff; padding: 25px; border-radius: 20px;">
                    <h3 class="fw-bold text-warning mb-0"><?php echo $siteCount; ?>+</h3>
                    <p class="text-muted mb-0">Active Sites Managed</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="stat-card-mini" style="background: #fff; padding: 25px; border-radius: 20px;">
                    <h3 class="fw-bold text-warning mb-0"><?php echo $employeeCount; ?>+</h3>
                    <p class="text-muted mb-0">Happy Users</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="stat-card-mini" style="background: #fff; padding: 25px; border-radius: 20px;">
                    <h3 class="fw-bold text-warning mb-0"><?php echo $reportCount; ?>K+</h3>
                    <p class="text-muted mb-0">Reports Generated</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="stat-card-mini" style="background: #fff; padding: 25px; border-radius: 20px;">
                    <h3 class="fw-bold text-warning mb-0">99.9%</h3>
                    <p class="text-muted mb-0">Uptime Guarantee</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Feature Comparison Table -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Compare <span class="yellow-text">Features</span></h2>
            <p>See what's included in each plan</p>
        </div>

        <div class="table-responsive" data-aos="fade-up">
            <table class="table table-bordered comparison-table">
                <thead class="table-dark">
                    <tr><th style="width: 30%;">Feature</th><th style="width: 23%;">Starter</th><th style="width: 23%;">Professional</th><th style="width: 24%;">Enterprise</th></tr>
                </thead>
                <tbody>
                    <tr><td class="feature-cell">Active Sites</td><td>Up to 5</td><td>Up to 20</td><td>Unlimited</td></tr>
                    <tr><td class="feature-cell">Employees</td><td>Up to 20</td><td>Unlimited</td><td>Unlimited</td></tr>
                    <tr><td class="feature-cell">DPR Reports</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="feature-cell">DAR Reports</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="feature-cell">MOM Reports</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="feature-cell">RFI Management</td><td>❌</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="feature-cell">AIT Tracker</td><td>❌</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="feature-cell">Quotation System</td><td>Basic</td><td>Advanced</td><td>Enterprise</td></tr>
                    <tr><td class="feature-cell">HR & Payroll</td><td>Basic</td><td>Full</td><td>Full + Custom</td></tr>
                    <tr><td class="feature-cell">Document Control</td><td>Basic</td><td>Full</td><td>Full + API</td></tr>
                    <tr><td class="feature-cell">Mobile App</td><td>✅</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="feature-cell">API Access</td><td>❌</td><td>✅</td><td>✅</td></tr>
                    <tr><td class="feature-cell">White-label</td><td>❌</td><td>❌</td><td>✅</td></tr>
                    <tr><td class="feature-cell">On-premise Deployment</td><td>❌</td><td>❌</td><td>✅</td></tr>
                    <tr><td class="feature-cell">Priority Support</td><td>Email</td><td>24/7 Chat</td><td>Dedicated</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- Add-on Services -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Add-on <span class="yellow-text">Services</span></h2>
            <p>Customize your plan with additional services</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="value-card" style="text-align: center;">
                    <div class="value-icon mx-auto"><i class="bi bi-person-badge"></i></div>
                    <h5 class="fw-bold">Onboarding & Training</h5>
                    <p class="text-muted">Comprehensive training for your team</p>
                    <h4 class="fw-bold text-warning">₹25,000</h4>
                    <small class="text-muted">One-time fee</small>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="value-card" style="text-align: center;">
                    <div class="value-icon mx-auto"><i class="bi bi-gear"></i></div>
                    <h5 class="fw-bold">Custom Development</h5>
                    <p class="text-muted">Custom features & workflows</p>
                    <h4 class="fw-bold text-warning">Custom Quote</h4>
                    <small class="text-muted">Based on requirements</small>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="value-card" style="text-align: center;">
                    <div class="value-icon mx-auto"><i class="bi bi-cloud-upload"></i></div>
                    <h5 class="fw-bold">Data Migration</h5>
                    <p class="text-muted">Seamless data migration from existing systems</p>
                    <h4 class="fw-bold text-warning">₹15,000</h4>
                    <small class="text-muted">One-time fee</small>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Testimonials -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>What Our <span class="yellow-text">Clients Say</span></h2>
            <p>Trusted by construction companies across India</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6" data-aos="fade-right">
                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p>"TEK-C has transformed our operations. The ROI has been incredible - we've reduced report generation time by 70% and improved site coordination significantly."</p>
                    <div class="d-flex align-items-center gap-3 mt-4">
                        <img class="avatar" src="assets/img/Shanthi.jpg" alt="">
                        <div>
                            <h6 class="fw-bold mb-0">Shanthi Balachandhar</h6>
                            <small class="text-warning fw-bold">Director - TEK-C</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6" data-aos="fade-left">
                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p>"The Professional plan is perfect for our needs. The quotation management and HR features have streamlined our entire workflow. Highly recommended!"</p>
                    <div class="d-flex align-items-center gap-3 mt-4">
                        <img class="avatar" src="assets/img/Balachandar.jpg" alt="" style="object-position: 10% 10%;">
                        <div>
                            <h6 class="fw-bold mb-0">U K Balachandar</h6>
                            <small class="text-warning fw-bold">Founder/ Principal Consultant</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Frequently Asked <span class="yellow-text">Questions</span></h2>
            <p>Everything you need to know about our pricing</p>
        </div>

        <div class="accordion" id="faqAccordion" data-aos="fade-up">
            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" data-bs-toggle="collapse" data-bs-target="#faq1">
                        Is there a setup fee?
                    </button>
                </h2>
                <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">No, there is no setup fee for any of our plans. You only pay the monthly or yearly subscription fee.</div>
                </div>
            </div>
            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq2">
                        Can I upgrade or downgrade my plan?
                    </button>
                </h2>
                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">Yes, you can upgrade or downgrade your plan at any time. Changes will be reflected in your next billing cycle.</div>
                </div>
            </div>
            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq3">
                        Do you offer a free trial?
                    </button>
                </h2>
                <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">Yes, we offer a 14-day free trial on all plans. No credit card required.</div>
                </div>
            </div>
            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq4">
                        What payment methods do you accept?
                    </button>
                </h2>
                <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">We accept all major credit cards, UPI, net banking, and bank transfers for annual plans.</div>
                </div>
            </div>
            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq5">
                        Is there a contract lock-in?
                    </button>
                </h2>
                <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">No, all our plans are month-to-month with no long-term contracts. You can cancel anytime.</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Final CTA -->
<section class="final-cta">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8 mx-auto text-center" data-aos="fade-up">
                <h2 class="display-5 fw-black">Ready to <span class="yellow-text">Get Started</span>?</h2>
                <p class="mt-3 fs-5">Join <?php echo $clientCount; ?>+ satisfied clients using TEK-C for their construction management</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="#" class="btn btn-yellow"><i class="bi bi-calendar2-check me-2"></i> Start Free Trial</a>
                    <a href="#" class="btn btn-light-custom"><i class="bi bi-telephone me-2"></i> Contact Sales</a>
                </div>
                <p class="mt-4 small">No credit card required. Cancel anytime.</p>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
    AOS.init({ duration: 1000, once: true, offset: 80 });

    // Pricing toggle between monthly and yearly
    const monthlyPrices = document.querySelectorAll('.monthly-price');
    const yearlyPrices = document.querySelectorAll('.yearly-price');
    const toggleOptions = document.querySelectorAll('.toggle-option');

    toggleOptions.forEach(option => {
        option.addEventListener('click', () => {
            toggleOptions.forEach(opt => opt.classList.remove('active'));
            option.classList.add('active');
            
            const isYearly = option.getAttribute('data-plan') === 'yearly';
            
            monthlyPrices.forEach(price => {
                price.style.display = isYearly ? 'none' : 'inline';
            });
            yearlyPrices.forEach(price => {
                price.style.display = isYearly ? 'inline' : 'none';
            });
        });
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
</script>

</body>
</html>