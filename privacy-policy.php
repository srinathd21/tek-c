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
    <title>Privacy Policy | TEK-C Global Construction ERP</title>

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

        .hero-privacy {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 80px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-privacy::before {
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

        .hero-privacy .container { position: relative; z-index: 1; }
        .hero-privacy h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-privacy .yellow-text { color: var(--yellow); }

        section { padding: 60px 0; }
        
        .policy-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            transition: .3s;
        }
        .policy-card:hover { border-color: var(--yellow); box-shadow: 0 10px 30px rgba(0,0,0,.05); }
        
        .policy-section {
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid var(--border);
        }
        .policy-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        
        .policy-section h3 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 20px;
            color: #111827;
        }
        .policy-section h3 i { color: var(--yellow); margin-right: 10px; }
        
        .policy-section h4 {
            font-size: 18px;
            font-weight: 700;
            margin: 20px 0 10px;
            color: #374151;
        }
        
        .policy-section p { line-height: 1.8; color: #4b5563; margin-bottom: 15px; }
        .policy-section ul, .policy-section ol { margin-bottom: 15px; padding-left: 20px; }
        .policy-section li { margin-bottom: 8px; line-height: 1.6; color: #4b5563; }
        
        .effective-date {
            background: #f8f9fa;
            border-left: 4px solid var(--yellow);
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        
        .table-of-contents {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .table-of-contents h4 { margin-bottom: 15px; font-weight: 800; }
        .table-of-contents ul { columns: 2; list-style: none; padding-left: 0; }
        .table-of-contents li { margin-bottom: 10px; }
        .table-of-contents a { text-decoration: none; color: #4b5563; transition: .3s; }
        .table-of-contents a:hover { color: var(--yellow); padding-left: 5px; }

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
            .table-of-contents ul { columns: 1; }
        }
        
        @media print {
            .navbar, .search-box, .btn-yellow, footer, .hero-privacy { display: none; }
            body { padding-top: 0; }
            .policy-card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-privacy">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>Privacy <span class="yellow-text">Policy</span></h1>
                <p class="lead mt-4">Your privacy is important to us. Learn how we collect, use, and protect your information.</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-shield-check me-1"></i> GDPR Compliant</span>
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-lock me-1"></i> Data Protected</span>
                    <span class="badge bg-warning text-dark p-2"><i class="bi bi-globe me-1"></i> Global Standards</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Main Content -->
<section style="padding-top: 0;">
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <div class="policy-card" data-aos="fade-up">
                    
                    <!-- Effective Date -->
                    <div class="effective-date">
                        <i class="bi bi-calendar-check text-warning me-2"></i>
                        <strong>Effective Date:</strong> <?php echo $lastUpdated; ?>
                        <br>
                        <small class="text-muted">This Privacy Policy applies to all TEK-C Global products and services.</small>
                    </div>
                    
                    <!-- Table of Contents -->
                    <div class="table-of-contents">
                        <h4><i class="bi bi-list-ul text-warning me-2"></i> Table of Contents</h4>
                        <ul>
                            <li><a href="#introduction">1. Introduction</a></li>
                            <li><a href="#information-collect">2. Information We Collect</a></li>
                            <li><a href="#use-information">3. How We Use Your Information</a></li>
                            <li><a href="#share-information">4. How We Share Information</a></li>
                            <li><a href="#data-security">5. Data Security</a></li>
                            <li><a href="#data-retention">6. Data Retention</a></li>
                            <li><a href="#cookies">7. Cookies and Tracking</a></li>
                            <li><a href="#third-party">8. Third-Party Services</a></li>
                            <li><a href="#your-rights">9. Your Rights</a></li>
                            <li><a href="#children">10. Children's Privacy</a></li>
                            <li><a href="#international">11. International Data Transfers</a></li>
                            <li><a href="#changes">12. Changes to This Policy</a></li>
                            <li><a href="#contact">13. Contact Us</a></li>
                        </ul>
                    </div>
                    
                    <!-- Introduction -->
                    <div id="introduction" class="policy-section">
                        <h3><i class="bi bi-info-circle"></i> 1. Introduction</h3>
                        <p>TEK-C Global ("we", "our", "us") is committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our construction ERP software, website, and related services (collectively, the "Service").</p>
                        <p>By using our Service, you consent to the data practices described in this policy. If you do not agree with any part of this policy, please do not use our Service.</p>
                        <p>We comply with applicable data protection laws, including the General Data Protection Regulation (GDPR) and the Information Technology Act, 2000 (India).</p>
                    </div>
                    
                    <!-- Information We Collect -->
                    <div id="information-collect" class="policy-section">
                        <h3><i class="bi bi-database"></i> 2. Information We Collect</h3>
                        <h4>2.1 Personal Information You Provide</h4>
                        <p>We collect personal information that you voluntarily provide to us when you:</p>
                        <ul>
                            <li>Register for an account (name, email, phone number, company name)</li>
                            <li>Use our Service (site data, project information, employee records)</li>
                            <li>Contact our support team (communication content)</li>
                            <li>Request a demo or quote (contact information, business requirements)</li>
                            <li>Subscribe to our newsletter (email address)</li>
                            <li>Participate in surveys or promotions (feedback, preferences)</li>
                        </ul>
                        
                        <h4>2.2 Information Automatically Collected</h4>
                        <p>When you access our Service, we automatically collect:</p>
                        <ul>
                            <li><strong>Log Data:</strong> IP address, browser type, operating system, access times, pages viewed</li>
                            <li><strong>Device Information:</strong> Device type, unique device identifiers, mobile network information</li>
                            <li><strong>Usage Data:</strong> Features used, time spent, clicks, and interaction patterns</li>
                            <li><strong>Location Data:</strong> Approximate location based on IP address (with your consent for precise location)</li>
                        </ul>
                        
                        <h4>2.3 Information from Third Parties</h4>
                        <p>We may receive information about you from third parties, including:</p>
                        <ul>
                            <li>Business partners who refer you to our Service</li>
                            <li>Payment processors for billing information</li>
                            <li>Social media platforms when you interact with our content</li>
                        </ul>
                    </div>
                    
                    <!-- How We Use Your Information -->
                    <div id="use-information" class="policy-section">
                        <h3><i class="bi bi-gear"></i> 3. How We Use Your Information</h3>
                        <p>We use the information we collect for the following purposes:</p>
                        <ul>
                            <li><strong>To Provide and Maintain Our Service:</strong> Process transactions, manage accounts, and deliver features</li>
                            <li><strong>To Improve Our Service:</strong> Analyze usage patterns, fix bugs, and develop new features</li>
                            <li><strong>To Communicate With You:</strong> Send service updates, security alerts, and support messages</li>
                            <li><strong>To Market Our Services:</strong> Send promotional materials (you can opt out anytime)</li>
                            <li><strong>To Ensure Security:</strong> Detect and prevent fraud, unauthorized access, and other security issues</li>
                            <li><strong>To Comply with Legal Obligations:</strong> Respond to lawful requests from authorities</li>
                            <li><strong>To Enforce Our Terms:</strong> Investigate and enforce violations of our Terms of Service</li>
                        </ul>
                        <p>We will only process your personal information when we have a lawful basis to do so, including consent, contract performance, legal obligation, or legitimate interests.</p>
                    </div>
                    
                    <!-- How We Share Information -->
                    <div id="share-information" class="policy-section">
                        <h3><i class="bi bi-share"></i> 4. How We Share Information</h3>
                        <p>We do not sell your personal information. We may share your information in the following circumstances:</p>
                        
                        <h4>4.1 Service Providers</h4>
                        <p>We share information with third-party vendors who help us operate our Service, including:</p>
                        <ul>
                            <li>Cloud hosting providers (AWS, DigitalOcean)</li>
                            <li>Payment processors (Razorpay, Stripe)</li>
                            <li>Email service providers (SendGrid, Mailchimp)</li>
                            <li>Analytics providers (Google Analytics, Mixpanel)</li>
                            <li>Customer support platforms (Zendesk)</li>
                        </ul>
                        
                        <h4>4.2 Business Transfers</h4>
                        <p>If we are involved in a merger, acquisition, or sale of assets, your information may be transferred as part of that transaction. We will notify you of any such change.</p>
                        
                        <h4>4.3 Legal Requirements</h4>
                        <p>We may disclose your information if required to do so by law or in response to valid requests by public authorities (e.g., court orders, government agencies).</p>
                        
                        <h4>4.4 With Your Consent</h4>
                        <p>We may share your information with third parties when you have given us explicit consent to do so.</p>
                        
                        <h4>4.5 Aggregated Data</h4>
                        <p>We may share aggregated, anonymized data that does not identify individual users for research, marketing, or analytical purposes.</p>
                    </div>
                    
                    <!-- Data Security -->
                    <div id="data-security" class="policy-section">
                        <h3><i class="bi bi-shield-lock"></i> 5. Data Security</h3>
                        <p>We implement comprehensive security measures to protect your information:</p>
                        <ul>
                            <li><strong>Encryption:</strong> AES-256 encryption for data at rest, TLS 1.3 for data in transit</li>
                            <li><strong>Access Controls:</strong> Strict role-based access controls and multi-factor authentication</li>
                            <li><strong>Regular Audits:</strong> Security assessments and penetration testing</li>
                            <li><strong>Data Backup:</strong> Daily encrypted backups with 30-day retention</li>
                            <li><strong>Employee Training:</strong> Regular security awareness training for all employees</li>
                            <li><strong>Incident Response:</strong> Documented procedures for data breach response</li>
                        </ul>
                        <p>While we strive to protect your information, no transmission over the internet is 100% secure. You use our Service at your own risk.</p>
                    </div>
                    
                    <!-- Data Retention -->
                    <div id="data-retention" class="policy-section">
                        <h3><i class="bi bi-clock-history"></i> 6. Data Retention</h3>
                        <p>We retain your personal information for as long as necessary to fulfill the purposes outlined in this policy, unless a longer retention period is required by law.</p>
                        <ul>
                            <li><strong>Account Information:</strong> Retained until you delete your account, plus 30 days for recovery</li>
                            <li><strong>Usage Data:</strong> Retained for up to 24 months for analytics purposes</li>
                            <li><strong>Transaction Records:</strong> Retained for 7 years for tax and accounting purposes</li>
                            <li><strong>Communication Logs:</strong> Retained for 3 years for support and quality assurance</li>
                        </ul>
                        <p>When you delete your account, we will anonymize or delete your personal information, except where we are required to retain it by law.</p>
                    </div>
                    
                    <!-- Cookies and Tracking -->
                    <div id="cookies" class="policy-section">
                        <h3><i class="bi bi-cookie"></i> 7. Cookies and Tracking</h3>
                        <p>We use cookies and similar tracking technologies to enhance your experience:</p>
                        
                        <h4>7.1 Types of Cookies We Use</h4>
                        <ul>
                            <li><strong>Essential Cookies:</strong> Required for the Service to function (authentication, security)</li>
                            <li><strong>Preference Cookies:</strong> Remember your settings and preferences</li>
                            <li><strong>Analytics Cookies:</strong> Help us understand how you use our Service</li>
                            <li><strong>Marketing Cookies:</strong> Used to deliver relevant advertisements</li>
                        </ul>
                        
                        <h4>7.2 Managing Cookies</h4>
                        <p>You can control cookies through your browser settings. However, disabling essential cookies may affect the functionality of our Service.</p>
                        
                        <h4>7.3 Do Not Track</h4>
                        <p>Our Service does not respond to Do Not Track (DNT) signals. You can set your browser to block or alert you about cookies.</p>
                    </div>
                    
                    <!-- Third-Party Services -->
                    <div id="third-party" class="policy-section">
                        <h3><i class="bi bi-plugin"></i> 8. Third-Party Services</h3>
                        <p>Our Service may contain links to third-party websites, plugins, or services that are not operated by us. This policy does not apply to third-party services. We encourage you to review the privacy policies of any third-party sites you visit.</p>
                        <p>We are not responsible for the privacy practices or content of third-party services.</p>
                    </div>
                    
                    <!-- Your Rights -->
                    <div id="your-rights" class="policy-section">
                        <h3><i class="bi bi-person-standing"></i> 9. Your Rights</h3>
                        <p>Depending on your location, you may have the following rights regarding your personal information:</p>
                        <ul>
                            <li><strong>Right to Access:</strong> Request a copy of your personal information</li>
                            <li><strong>Right to Rectification:</strong> Correct inaccurate or incomplete information</li>
                            <li><strong>Right to Erasure:</strong> Request deletion of your personal information ("right to be forgotten")</li>
                            <li><strong>Right to Restrict Processing:</strong> Limit how we use your information</li>
                            <li><strong>Right to Data Portability:</strong> Receive your data in a structured, machine-readable format</li>
                            <li><strong>Right to Object:</strong> Object to processing based on legitimate interests or direct marketing</li>
                            <li><strong>Right to Withdraw Consent:</strong> Withdraw consent at any time (where consent is the basis for processing)</li>
                            <li><strong>Right to Lodge a Complaint:</strong> File a complaint with your local data protection authority</li>
                        </ul>
                        <p>To exercise these rights, please contact us using the information in the "Contact Us" section. We will respond within 30 days.</p>
                    </div>
                    
                    <!-- Children's Privacy -->
                    <div id="children" class="policy-section">
                        <h3><i class="bi bi-people"></i> 10. Children's Privacy</h3>
                        <p>Our Service is not intended for children under the age of 18. We do not knowingly collect personal information from children. If you are a parent or guardian and believe your child has provided us with personal information, please contact us, and we will take steps to delete that information.</p>
                    </div>
                    
                    <!-- International Data Transfers -->
                    <div id="international" class="policy-section">
                        <h3><i class="bi bi-globe2"></i> 11. International Data Transfers</h3>
                        <p>Your information may be transferred to and processed in countries other than your own. We ensure that appropriate safeguards are in place for such transfers, including:</p>
                        <ul>
                            <li>Standard Contractual Clauses approved by the European Commission</li>
                            <li>Privacy Shield certification (where applicable)</li>
                            <li>Binding Corporate Rules for intra-company transfers</li>
                        </ul>
                        <p>By using our Service, you consent to the transfer of your information to countries that may have different data protection laws than your country.</p>
                    </div>
                    
                    <!-- Changes to This Policy -->
                    <div id="changes" class="policy-section">
                        <h3><i class="bi bi-pencil-square"></i> 12. Changes to This Policy</h3>
                        <p>We may update this Privacy Policy from time to time. We will notify you of any changes by:</p>
                        <ul>
                            <li>Posting the new policy on this page</li>
                            <li>Sending you an email notification (for significant changes)</li>
                            <li>Displaying a notice within our Service</li>
                        </ul>
                        <p>The "Effective Date" at the top of this policy indicates when it was last revised. Your continued use of our Service after any changes constitutes acceptance of the updated policy.</p>
                    </div>
                    
                    <!-- Contact Us -->
                    <div id="contact" class="policy-section">
                        <h3><i class="bi bi-envelope"></i> 13. Contact Us</h3>
                        <p>If you have any questions, concerns, or requests regarding this Privacy Policy or our data practices, please contact us:</p>
                        <ul style="list-style: none; padding-left: 0;">
                            <li><i class="bi bi-envelope-fill text-warning me-2"></i> <strong>Email:</strong> privacy@tekcglobal.com</li>
                            <li><i class="bi bi-telephone-fill text-warning me-2"></i> <strong>Phone:</strong> +91 72003 16099</li>
                            <li><i class="bi bi-geo-alt-fill text-warning me-2"></i> <strong>Address:</strong> <?php echo htmlspecialchars($companyDetails['company_address'] ?? 'Dharmapuri, Tamil Nadu, India'); ?></li>
                            <li><i class="bi bi-person-badge text-warning me-2"></i> <strong>Data Protection Officer:</strong> dpo@tekcglobal.com</li>
                        </ul>
                        <p>We aim to respond to all inquiries within 30 days.</p>
                    </div>
                    
                    <!-- GDPR/CCPA Compliance Note -->
                    <div class="alert alert-light mt-4" style="background: #f8f9fa; border-left: 4px solid var(--yellow);">
                        <i class="bi bi-shield-check text-warning me-2"></i>
                        <strong>GDPR & CCPA Compliance:</strong> TEK-C Global is committed to complying with applicable data protection regulations. If you are a resident of the European Economic Area (EEA) or California, you have additional rights under the GDPR or CCPA. Please contact us to exercise your rights.
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Quick Stats Section -->
<section class="bg-light">
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="fade-up">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-building fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2"><?php echo $siteCount; ?></h3>
                    <p class="text-muted small mb-0">Protected Sites</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="100">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-people fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2"><?php echo $employeeCount; ?></h3>
                    <p class="text-muted small mb-0">Users Protected</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="200">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-file-text fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2">99.9%</h3>
                    <p class="text-muted small mb-0">Data Protection</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="300">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-shield-check fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2">ISO 27001</h3>
                    <p class="text-muted small mb-0">Certified</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contact Call to Action -->
<section class="final-cta" style="background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%); color: #fff; padding: 70px 0; position: relative; overflow: hidden;">
    <div class="container text-center">
        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h2 class="display-5 fw-black">Questions About <span class="yellow-text">Privacy</span>?</h2>
                <p class="mt-3 fs-5">Our privacy team is here to help</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="contact.php" class="btn btn-yellow btn-lg">
                        <i class="bi bi-envelope me-2"></i> Contact Privacy Team
                    </a>
                    <a href="#" class="btn btn-outline-yellow btn-lg" onclick="window.print();">
                        <i class="bi bi-printer me-2"></i> Print Policy
                    </a>
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

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
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