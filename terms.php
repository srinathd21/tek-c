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
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service | TEK-C Global Construction ERP</title>

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

        .hero-terms {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 80px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-terms::before {
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

        .hero-terms .container { position: relative; z-index: 1; }
        .hero-terms h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-terms .yellow-text { color: var(--yellow); }

        section { padding: 60px 0; }
        
        .terms-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            transition: .3s;
        }
        .terms-card:hover { border-color: var(--yellow); box-shadow: 0 10px 30px rgba(0,0,0,.05); }
        
        .terms-section {
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid var(--border);
        }
        .terms-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        
        .terms-section h3 {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 20px;
            color: #111827;
        }
        .terms-section h3 i { color: var(--yellow); margin-right: 10px; }
        
        .terms-section h4 {
            font-size: 18px;
            font-weight: 700;
            margin: 20px 0 10px;
            color: #374151;
        }
        
        .terms-section p { line-height: 1.8; color: #4b5563; margin-bottom: 15px; }
        .terms-section ul, .terms-section ol { margin-bottom: 15px; padding-left: 20px; }
        .terms-section li { margin-bottom: 8px; line-height: 1.6; color: #4b5563; }
        
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
            .navbar, .search-box, .btn-yellow, footer, .hero-terms { display: none; }
            body { padding-top: 0; }
            .terms-card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-terms">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>Terms of <span class="yellow-text">Service</span></h1>
                <p class="lead mt-4">Please read these terms carefully before using TEK-C Global services.</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-file-text me-1"></i> Legally Binding</span>
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-shield-check me-1"></i> Your Rights Protected</span>
                    <span class="badge bg-warning text-dark p-2"><i class="bi bi-globe me-1"></i> Global Applicability</span>
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
                <div class="terms-card" data-aos="fade-up">
                    
                    <!-- Effective Date -->
                    <div class="effective-date">
                        <i class="bi bi-calendar-check text-warning me-2"></i>
                        <strong>Effective Date:</strong> <?php echo $lastUpdated; ?>
                        <br>
                        <small class="text-muted">These Terms of Service apply to all TEK-C Global products and services.</small>
                    </div>
                    
                    <!-- Table of Contents -->
                    <div class="table-of-contents">
                        <h4><i class="bi bi-list-ul text-warning me-2"></i> Table of Contents</h4>
                        <ul>
                            <li><a href="#acceptance">1. Acceptance of Terms</a></li>
                            <li><a href="#changes">2. Changes to Terms</a></li>
                            <li><a href="#eligibility">3. Eligibility</a></li>
                            <li><a href="#accounts">4. User Accounts</a></li>
                            <li><a href="#services">5. Description of Services</a></li>
                            <li><a href="#fees">6. Fees and Payments</a></li>
                            <li><a href="#user-obligations">7. User Obligations</a></li>
                            <li><a href="#prohibited">8. Prohibited Activities</a></li>
                            <li><a href="#intellectual-property">9. Intellectual Property</a></li>
                            <li><a href="#data">10. Data Ownership and Privacy</a></li>
                            <li><a href="#termination">11. Termination</a></li>
                            <li><a href="#warranty">12. Disclaimer of Warranties</a></li>
                            <li><a href="#liability">13. Limitation of Liability</a></li>
                            <li><a href="#indemnification">14. Indemnification</a></li>
                            <li><a href="#force-majeure">15. Force Majeure</a></li>
                            <li><a href="#governing-law">16. Governing Law</a></li>
                            <li><a href="#dispute-resolution">17. Dispute Resolution</a></li>
                            <li><a href="#severability">18. Severability</a></li>
                            <li><a href="#entire-agreement">19. Entire Agreement</a></li>
                            <li><a href="#contact">20. Contact Information</a></li>
                        </ul>
                    </div>
                    
                    <!-- 1. Acceptance of Terms -->
                    <div id="acceptance" class="terms-section">
                        <h3><i class="bi bi-check-circle"></i> 1. Acceptance of Terms</h3>
                        <p>By accessing or using TEK-C Global's website, software, and services (collectively, the "Service"), you agree to be bound by these Terms of Service ("Terms"). If you do not agree to these Terms, please do not use our Service.</p>
                        <p>These Terms constitute a legally binding agreement between you ("User," "you," or "your") and TEK-C Global ("Company," "we," "us," or "our"). By using our Service, you represent that you have the authority to bind your organization to these Terms.</p>
                    </div>
                    
                    <!-- 2. Changes to Terms -->
                    <div id="changes" class="terms-section">
                        <h3><i class="bi bi-pencil-square"></i> 2. Changes to Terms</h3>
                        <p>We reserve the right to modify these Terms at any time. We will notify you of material changes by:</p>
                        <ul>
                            <li>Posting the updated Terms on this page</li>
                            <li>Sending an email notification to registered users</li>
                            <li>Displaying a notice within our Service</li>
                        </ul>
                        <p>Your continued use of the Service after the effective date constitutes acceptance of the revised Terms. If you do not agree to the changes, you must stop using the Service and cancel your account.</p>
                    </div>
                    
                    <!-- 3. Eligibility -->
                    <div id="eligibility" class="terms-section">
                        <h3><i class="bi bi-person-check"></i> 3. Eligibility</h3>
                        <p>To use our Service, you must:</p>
                        <ul>
                            <li>Be at least 18 years of age</li>
                            <li>Have the legal capacity to enter into a binding agreement</li>
                            <li>Not be prohibited from using our Service under applicable laws</li>
                            <li>Provide accurate and complete registration information</li>
                        </ul>
                        <p>If you are using the Service on behalf of an organization, you represent that you have the authority to bind that organization to these Terms.</p>
                    </div>
                    
                    <!-- 4. User Accounts -->
                    <div id="accounts" class="terms-section">
                        <h3><i class="bi bi-person-badge"></i> 4. User Accounts</h3>
                        <h4>4.1 Account Registration</h4>
                        <p>You must create an account to access certain features of our Service. You agree to provide accurate, current, and complete information during registration and to update it promptly.</p>
                        
                        <h4>4.2 Account Security</h4>
                        <p>You are responsible for maintaining the confidentiality of your login credentials and for all activities that occur under your account. You agree to:</p>
                        <ul>
                            <li>Notify us immediately of any unauthorized access or security breach</li>
                            <li>Use strong passwords and enable two-factor authentication where available</li>
                            <li>Not share your account credentials with others</li>
                            <li>Log out of your account after each session</li>
                        </ul>
                        <p>We are not liable for any loss or damage arising from your failure to comply with these requirements.</p>
                        
                        <h4>4.3 Account Types</h4>
                        <p>We offer different account types (e.g., individual, team, enterprise). Each account type has specific features, limits, and pricing. You may upgrade or downgrade your account at any time, subject to applicable fees.</p>
                    </div>
                    
                    <!-- 5. Description of Services -->
                    <div id="services" class="terms-section">
                        <h3><i class="bi bi-grid-3x3-gap-fill"></i> 5. Description of Services</h3>
                        <p>TEK-C Global provides a construction ERP platform that includes but is not limited to:</p>
                        <ul>
                            <li>Site and project management</li>
                            <li>Daily Progress Reports (DPR) and documentation</li>
                            <li>Quotation and tendering management</li>
                            <li>HR and payroll management</li>
                            <li>Document control system</li>
                            <li>Analytics and reporting dashboards</li>
                            <li>Mobile application for field staff</li>
                            <li>API access for integrations</li>
                        </ul>
                        <p>We reserve the right to modify, suspend, or discontinue any part of the Service at any time, with or without notice. We will not be liable to you or any third party for any modification, suspension, or discontinuation.</p>
                    </div>
                    
                    <!-- 6. Fees and Payments -->
                    <div id="fees" class="terms-section">
                        <h3><i class="bi bi-currency-rupee"></i> 6. Fees and Payments</h3>
                        <h4>6.1 Subscription Fees</h4>
                        <p>Certain features of our Service require payment of subscription fees. All fees are stated in Indian Rupees (INR) and are exclusive of applicable taxes (GST, etc.).</p>
                        
                        <h4>6.2 Billing</h4>
                        <p>We use third-party payment processors to handle billing. By subscribing to a paid plan, you authorize us to charge your selected payment method on a recurring basis (monthly or annually).</p>
                        
                        <h4>6.3 Refunds</h4>
                        <p>Subscription fees are non-refundable except as required by law. If you cancel your subscription, you will not receive a refund for any prepaid fees. You may continue to use the Service until the end of your current billing period.</p>
                        
                        <h4>6.4 Fee Changes</h4>
                        <p>We may change our fees at any time. We will notify you at least 30 days before any fee change. Your continued use of the Service after the fee change constitutes acceptance of the new fees.</p>
                        
                        <h4>6.5 Late Payments</h4>
                        <p>If your payment is late, we may suspend your access to the Service until payment is received. We may charge interest on late payments at the rate of 1.5% per month or the maximum permitted by law.</p>
                    </div>
                    
                    <!-- 7. User Obligations -->
                    <div id="user-obligations" class="terms-section">
                        <h3><i class="bi bi-list-check"></i> 7. User Obligations</h3>
                        <p>You agree to:</p>
                        <ul>
                            <li>Use the Service in compliance with all applicable laws and regulations</li>
                            <li>Maintain the security of your account and data</li>
                            <li>Provide accurate and complete information</li>
                            <li>Use the Service only for legitimate business purposes</li>
                            <li>Cooperate with us in investigating any suspected violations</li>
                            <li>Report any bugs, errors, or security vulnerabilities you discover</li>
                        </ul>
                    </div>
                    
                    <!-- 8. Prohibited Activities -->
                    <div id="prohibited" class="terms-section">
                        <h3><i class="bi bi-ban"></i> 8. Prohibited Activities</h3>
                        <p>You may not:</p>
                        <ul>
                            <li>Use the Service for any illegal purpose or in violation of any laws</li>
                            <li>Attempt to gain unauthorized access to our systems or other users' accounts</li>
                            <li>Interfere with or disrupt the integrity or performance of the Service</li>
                            <li>Reverse engineer, decompile, or disassemble any part of the Service</li>
                            <li>Use automated scripts or bots to access or interact with the Service</li>
                            <li>Upload malicious code, viruses, or harmful content</li>
                            <li>Scrape, crawl, or copy data from the Service without permission</li>
                            <li>Resell, sublicense, or distribute the Service to third parties</li>
                            <li>Use the Service to store or transmit sensitive personal information without proper safeguards</li>
                            <li>Impersonate any person or entity or falsely state your affiliation</li>
                        </ul>
                    </div>
                    
                    <!-- 9. Intellectual Property -->
                    <div id="intellectual-property" class="terms-section">
                        <h3><i class="bi bi-c-circle"></i> 9. Intellectual Property</h3>
                        <h4>9.1 Our Intellectual Property</h4>
                        <p>The Service, including its code, design, layout, graphics, logos, and documentation, is owned by TEK-C Global and is protected by copyright, trademark, and other intellectual property laws. You may not copy, modify, or create derivative works without our express written permission.</p>
                        
                        <h4>9.2 Your Content</h4>
                        <p>You retain ownership of all data, documents, and information you upload to the Service ("Your Content"). By using the Service, you grant us a limited license to host, process, and display Your Content as necessary to provide the Service to you.</p>
                        
                        <h4>9.3 Feedback</h4>
                        <p>If you provide us with feedback, suggestions, or ideas about the Service, you grant us an unrestricted, perpetual, royalty-free license to use that feedback for any purpose without compensation to you.</p>
                    </div>
                    
                    <!-- 10. Data Ownership and Privacy -->
                    <div id="data" class="terms-section">
                        <h3><i class="bi bi-database"></i> 10. Data Ownership and Privacy</h3>
                        <p>You own all data you input into the Service. We do not claim ownership of Your Content. Our collection and use of personal information is governed by our <a href="privacy-policy.php">Privacy Policy</a>.</p>
                        <p>We may collect and use aggregated, anonymized data for analytics, product improvement, and marketing purposes. This data does not identify individual users.</p>
                    </div>
                    
                    <!-- 11. Termination -->
                    <div id="termination" class="terms-section">
                        <h3><i class="bi bi-x-octagon"></i> 11. Termination</h3>
                        <h4>11.1 Termination by You</h4>
                        <p>You may cancel your account at any time by contacting our support team or through your account settings. Upon cancellation, you will lose access to the Service and your data may be deleted after 30 days.</p>
                        
                        <h4>11.2 Termination by Us</h4>
                        <p>We may suspend or terminate your account immediately for:</p>
                        <ul>
                            <li>Violation of these Terms</li>
                            <li>Non-payment of fees</li>
                            <li>Illegal or harmful conduct</li>
                            <li>Extended periods of inactivity (12+ months)</li>
                        </ul>
                        
                        <h4>11.3 Data Export</h4>
                        <p>Upon termination, we will provide you with the ability to export Your Content for 30 days. After that period, we may delete your data permanently.</p>
                    </div>
                    
                    <!-- 12. Disclaimer of Warranties -->
                    <div id="warranty" class="terms-section">
                        <h3><i class="bi bi-exclamation-triangle"></i> 12. Disclaimer of Warranties</h3>
                        <p>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED. WE DO NOT WARRANT THAT:</p>
                        <ul>
                            <li>The Service will be uninterrupted, secure, or error-free</li>
                            <li>Any defects or errors will be corrected</li>
                            <li>The Service will meet your specific requirements</li>
                            <li>Results obtained from the Service will be accurate or reliable</li>
                        </ul>
                        <p>TO THE FULLEST EXTENT PERMITTED BY LAW, WE DISCLAIM ALL WARRANTIES, INCLUDING IMPLIED WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT.</p>
                    </div>
                    
                    <!-- 13. Limitation of Liability -->
                    <div id="liability" class="terms-section">
                        <h3><i class="bi bi-shield-exclamation"></i> 13. Limitation of Liability</h3>
                        <p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, TEK-C GLOBAL AND ITS OFFICERS, DIRECTORS, EMPLOYEES, AND AGENTS SHALL NOT BE LIABLE FOR:</p>
                        <ul>
                            <li>INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES</li>
                            <li>LOSS OF PROFITS, REVENUE, DATA, OR GOODWILL</li>
                            <li>COSTS OF PROCURING SUBSTITUTE GOODS OR SERVICES</li>
                            <li>UNAUTHORIZED ACCESS TO OR ALTERATION OF YOUR DATA</li>
                        </ul>
                        <p>OUR TOTAL LIABILITY FOR ANY CLAIM ARISING OUT OF OR RELATING TO THESE TERMS OR THE SERVICE SHALL NOT EXCEED THE AMOUNT YOU PAID US IN THE 12 MONTHS PRECEDING THE CLAIM.</p>
                        <p>SOME JURISDICTIONS DO NOT ALLOW LIMITATIONS ON LIABILITY, SO THIS LIMITATION MAY NOT APPLY TO YOU.</p>
                    </div>
                    
                    <!-- 14. Indemnification -->
                    <div id="indemnification" class="terms-section">
                        <h3><i class="bi bi-shield-plus"></i> 14. Indemnification</h3>
                        <p>You agree to indemnify, defend, and hold harmless TEK-C Global from any claims, damages, losses, liabilities, and expenses (including attorney's fees) arising from:</p>
                        <ul>
                            <li>Your use of the Service</li>
                            <li>Your violation of these Terms</li>
                            <li>Your violation of any third-party rights, including intellectual property or privacy rights</li>
                            <li>Your content or data</li>
                        </ul>
                    </div>
                    
                    <!-- 15. Force Majeure -->
                    <div id="force-majeure" class="terms-section">
                        <h3><i class="bi bi-cloud-lightning"></i> 15. Force Majeure</h3>
                        <p>We shall not be liable for any delay or failure to perform resulting from causes outside our reasonable control, including but not limited to natural disasters, war, terrorism, riots, embargoes, acts of government, labor disputes, internet failures, or power outages.</p>
                    </div>
                    
                    <!-- 16. Governing Law -->
                    <div id="governing-law" class="terms-section">
                        <h3><i class="bi bi-gavel"></i> 16. Governing Law</h3>
                        <p>These Terms shall be governed by and construed in accordance with the laws of India, without regard to its conflict of law principles. The courts in Dharmapuri, Tamil Nadu shall have exclusive jurisdiction over any disputes arising from these Terms.</p>
                    </div>
                    
                    <!-- 17. Dispute Resolution -->
                    <div id="dispute-resolution" class="terms-section">
                        <h3><i class="bi bi-file-text"></i> 17. Dispute Resolution</h3>
                        <h4>17.1 Informal Resolution</h4>
                        <p>Before filing a formal claim, you agree to contact us to attempt to resolve the dispute informally. We will attempt to resolve the dispute within 30 days.</p>
                        
                        <h4>17.2 Arbitration</h4>
                        <p>If we cannot resolve the dispute informally, any dispute arising from these Terms shall be resolved through binding arbitration administered by the Indian Council of Arbitration. The arbitration shall be conducted in English in Chennai, Tamil Nadu.</p>
                        
                        <h4>17.3 Class Action Waiver</h4>
                        <p>YOU AGREE TO RESOLVE DISPUTES ON AN INDIVIDUAL BASIS AND WAIVE THE RIGHT TO PARTICIPATE IN A CLASS ACTION OR REPRESENTATIVE ACTION.</p>
                    </div>
                    
                    <!-- 18. Severability -->
                    <div id="severability" class="terms-section">
                        <h3><i class="bi bi-journal-code"></i> 18. Severability</h3>
                        <p>If any provision of these Terms is found to be unenforceable or invalid, that provision shall be limited or eliminated to the minimum extent necessary, and the remaining provisions shall remain in full force and effect.</p>
                    </div>
                    
                    <!-- 19. Entire Agreement -->
                    <div id="entire-agreement" class="terms-section">
                        <h3><i class="bi bi-file-earmark-text"></i> 19. Entire Agreement</h3>
                        <p>These Terms, together with our <a href="privacy-policy.php">Privacy Policy</a>, constitute the entire agreement between you and TEK-C Global regarding the Service and supersede all prior agreements and understandings.</p>
                    </div>
                    
                    <!-- 20. Contact Information -->
                    <div id="contact" class="terms-section">
                        <h3><i class="bi bi-envelope"></i> 20. Contact Information</h3>
                        <p>If you have any questions about these Terms, please contact us:</p>
                        <ul style="list-style: none; padding-left: 0;">
                            <li><i class="bi bi-envelope-fill text-warning me-2"></i> <strong>Email:</strong> legal@tekcglobal.com</li>
                            <li><i class="bi bi-telephone-fill text-warning me-2"></i> <strong>Phone:</strong> +91 72003 16099</li>
                            <li><i class="bi bi-geo-alt-fill text-warning me-2"></i> <strong>Address:</strong> Dharmapuri, Tamil Nadu, India</li>
                            <li><i class="bi bi-person-badge text-warning me-2"></i> <strong>Legal Officer:</strong> legal@tekcglobal.com</li>
                        </ul>
                    </div>
                    
                    <!-- Acknowledgment -->
                    <div class="alert alert-light mt-4" style="background: #f8f9fa; border-left: 4px solid var(--yellow);">
                        <i class="bi bi-hand-index-thumb text-warning me-2"></i>
                        <strong>Acknowledgment:</strong> By using TEK-C Global services, you acknowledge that you have read, understood, and agree to be bound by these Terms of Service.
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
                    <p class="text-muted small mb-0">Active Sites Under Terms</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="100">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-people fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2"><?php echo $employeeCount; ?></h3>
                    <p class="text-muted small mb-0">Users Bound by Terms</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="200">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-building fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2"><?php echo $clientCount; ?></h3>
                    <p class="text-muted small mb-0">Client Organizations</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="300">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <i class="bi bi-shield-check fs-2 text-warning"></i>
                    <h3 class="fw-bold mt-2">100%</h3>
                    <p class="text-muted small mb-0">Compliance Committed</p>
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
                <h2 class="display-5 fw-black">Questions About <span class="yellow-text">Terms</span>?</h2>
                <p class="mt-3 fs-5">Our legal team is available to address your concerns</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="contact.php" class="btn btn-yellow btn-lg">
                        <i class="bi bi-envelope me-2"></i> Contact Legal Team
                    </a>
                    <a href="#" class="btn btn-outline-yellow btn-lg" onclick="window.print();">
                        <i class="bi bi-printer me-2"></i> Print Terms
                    </a>
                </div>
                <p class="mt-4 small">For legal inquiries only. For technical support, please visit our <a href="help-center.php" class="text-warning">Help Center</a>.</p>
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