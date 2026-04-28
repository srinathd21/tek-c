<?php
// Start session if needed
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'u209621005_tekc';
$username = 'root';
$password = '';

// Initialize variables for form submission
$success_message = '';
$error_message = '';

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $message = $_POST['message'] ?? '';
    
    // Basic validation
    if (empty($name) || empty($email) || empty($message)) {
        $error_message = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        // Here you would typically send email or save to database
        // For now, we'll just show success message
        $success_message = 'Thank you for contacting us! We will get back to you soon.';
        
        // Optional: Insert into database
        // try {
        //     $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        //     $stmt = $pdo->prepare("INSERT INTO contact_queries (name, email, phone, subject, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        //     $stmt->execute([$name, $email, $phone, $subject, $message]);
        // } catch(PDOException $e) {
        //     // Handle error
        // }
    }
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch company details
    $stmt = $pdo->query("SELECT * FROM company_details ORDER BY id DESC LIMIT 1");
    $companyDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch stats for the page
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sites WHERE deleted_at IS NULL");
    $siteCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE employee_status = 'active'");
    $employeeCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM clients");
    $clientCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch(PDOException $e) {
    $companyDetails = [
        'company_name' => 'TEK-C Global',
        'company_address' => 'Dharmapuri, Tamil Nadu, India',
        'company_phone' => '+91 72003 16099',
        'company_email' => 'info@tekcglobal.com',
        'company_website' => 'https://tekcglobal.com'
    ];
    $siteCount = 8;
    $employeeCount = 18;
    $clientCount = 3;
}

// Construction images
$constructionImages = [
    'hero' => 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1800&q=80',
    'office' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=800&q=80',
    'team' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=800&q=80',
    'site' => 'https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=800&q=80',
    'map' => 'https://images.unsplash.com/photo-1524661135-423995f22d0e?auto=format&fit=crop&w=800&q=80',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us | TEK-C Global Construction ERP</title>

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

        .hero-contact {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 100px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-contact::before {
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

        .hero-contact .container { position: relative; z-index: 1; }
        .hero-contact h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-contact .yellow-text { color: var(--yellow); }

        section { padding: 85px 0; }
        .section-title { text-align: center; margin-bottom: 50px; }
        .section-title h2 { font-size: clamp(32px, 4vw, 48px); font-weight: 900; color: #111827; letter-spacing: -1px; }
        .section-title p { color: var(--muted); font-size: 17px; }

        .contact-info-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            transition: .35s;
            height: 100%;
            box-shadow: 0 10px 32px rgba(17,24,39,.05);
        }

        .contact-info-card:hover {
            transform: translateY(-8px);
            border-color: var(--yellow);
            box-shadow: 0 20px 40px rgba(17,24,39,.12);
        }

        .info-icon {
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

        .contact-form-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 10px 32px rgba(17,24,39,.05);
        }

        .form-control-custom {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            transition: .3s;
        }

        .form-control-custom:focus {
            border-color: var(--yellow);
            box-shadow: 0 0 0 3px rgba(255,195,41,.1);
            outline: none;
        }

        .map-container {
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 10px 32px rgba(17,24,39,.1);
        }

        .stat-card-mini {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            transition: .35s;
        }

        .stat-card-mini:hover {
            transform: translateY(-5px);
            border-color: var(--yellow);
        }

        .alert-custom {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
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
            .contact-form-card { padding: 25px; }
        }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-contact">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>Get in <span class="yellow-text">Touch</span></h1>
                <p class="lead mt-4">Have questions? We'd love to hear from you. Our team is here to help.</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2">24/7 Support</span>
                    <span class="badge bg-warning text-dark me-2 p-2">Quick Response</span>
                    <span class="badge bg-warning text-dark p-2">Dedicated Team</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contact Info Cards -->
<section style="padding: 60px 0 0 0;">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="contact-info-card">
                    <div class="info-icon mx-auto"><i class="bi bi-geo-alt-fill"></i></div>
                    <h5 class="fw-bold">Visit Us</h5>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($companyDetails['company_address'] ?? 'Dharmapuri, Tamil Nadu, India'); ?>
                    </p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="contact-info-card">
                    <div class="info-icon mx-auto"><i class="bi bi-telephone-fill"></i></div>
                    <h5 class="fw-bold">Call Us</h5>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($companyDetails['company_phone'] ?? '+91 72003 16099'); ?><br>
                        <small>Mon-Fri, 9AM to 6PM</small>
                    </p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="contact-info-card">
                    <div class="info-icon mx-auto"><i class="bi bi-envelope-fill"></i></div>
                    <h5 class="fw-bold">Email Us</h5>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($companyDetails['company_email'] ?? 'info@tekcglobal.com'); ?><br>
                        <small>We respond within 24 hours</small>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contact Form & Map -->
<section>
    <div class="container">
        <div class="row g-5">
            <!-- Contact Form -->
            <div class="col-lg-6" data-aos="fade-right">
                <div class="contact-form-card">
                    <h3 class="fw-bold mb-4">Send us a <span class="yellow-text">Message</span></h3>
                    
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-custom">
                            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-custom">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Full Name *</label>
                                <input type="text" name="name" class="form-control form-control-custom" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email Address *</label>
                                <input type="email" name="email" class="form-control form-control-custom" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input type="tel" name="phone" class="form-control form-control-custom">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Subject</label>
                                <select name="subject" class="form-select form-control-custom">
                                    <option value="General Inquiry">General Inquiry</option>
                                    <option value="Sales">Sales & Pricing</option>
                                    <option value="Support">Technical Support</option>
                                    <option value="Partnership">Partnership</option>
                                    <option value="Demo Request">Demo Request</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Message *</label>
                                <textarea name="message" class="form-control form-control-custom" rows="5" required></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-yellow w-100">
                                    <i class="bi bi-send me-2"></i> Send Message
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <div class="mt-4 pt-3 text-center">
                        <p class="text-muted small mb-0">
                            <i class="bi bi-shield-check me-1"></i> Your information is safe with us
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Map & Location -->
            <div class="col-lg-6" data-aos="fade-left">
                <div class="map-container">
                    <iframe 
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d15601.580239102572!2d78.002846!3d12.042497!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3baaf9d0b5c9b8a1%3A0x0!2zMTLCsDAyJzMzLjAiTiA3OMKwMDAnMTEuOSJF!5e0!3m2!1sen!2sin!4v1699999999999!5m2!1sen!2sin" 
                        width="100%" 
                        height="400" 
                        style="border:0;" 
                        allowfullscreen="" 
                        loading="lazy">
                    </iframe>
                </div>
                
                <!-- Additional Contact Details -->
                <div class="row g-3 mt-4">
                    <div class="col-6">
                        <div class="contact-info-card" style="padding: 20px;">
                            <i class="bi bi-clock-history fs-2 text-warning"></i>
                            <h6 class="fw-bold mt-2 mb-0">Business Hours</h6>
                            <small class="text-muted">Mon-Fri: 9:00 AM - 6:00 PM<br>Sat: 10:00 AM - 2:00 PM</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="contact-info-card" style="padding: 20px;">
                            <i class="bi bi-globe fs-2 text-warning"></i>
                            <h6 class="fw-bold mt-2 mb-0">Connect With Us</h6>
                            <div class="mt-2">
                                <a href="#" class="text-dark me-2"><i class="bi bi-linkedin fs-5"></i></a>
                                <a href="#" class="text-dark me-2"><i class="bi bi-twitter-x fs-5"></i></a>
                                <a href="#" class="text-dark me-2"><i class="bi bi-facebook fs-5"></i></a>
                                <a href="#" class="text-dark"><i class="bi bi-youtube fs-5"></i></a>
                            </div>
                        </div>
                    </div>
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
                <div class="stat-card-mini">
                    <h3 class="fw-bold text-warning mb-0"><?php echo $siteCount; ?>+</h3>
                    <p class="text-muted mb-0">Active Sites</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="stat-card-mini">
                    <h3 class="fw-bold text-warning mb-0"><?php echo $employeeCount; ?>+</h3>
                    <p class="text-muted mb-0">Team Members</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="stat-card-mini">
                    <h3 class="fw-bold text-warning mb-0"><?php echo $clientCount; ?>+</h3>
                    <p class="text-muted mb-0">Happy Clients</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="stat-card-mini">
                    <h3 class="fw-bold text-warning mb-0">24/7</h3>
                    <p class="text-muted mb-0">Support Available</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Office Locations -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Our <span class="yellow-text">Offices</span></h2>
            <p>We're located across India to serve you better</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="contact-info-card">
                    <div class="info-icon mx-auto"><i class="bi bi-building"></i></div>
                    <h5 class="fw-bold">Head Office</h5>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($companyDetails['company_address'] ?? 'Dharmapuri, Tamil Nadu, India'); ?>
                    </p>
                    <hr class="my-3">
                    <p class="text-muted small mb-0">
                        <i class="bi bi-telephone me-1"></i> <?php echo htmlspecialchars($companyDetails['company_phone'] ?? '+91 72003 16099'); ?>
                    </p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="contact-info-card">
                    <div class="info-icon mx-auto"><i class="bi bi-building"></i></div>
                    <h5 class="fw-bold">Bangalore Office</h5>
                    <p class="text-muted mb-0">
                        MG Road, Bangalore<br>
                        Karnataka, India - 560001
                    </p>
                    <hr class="my-3">
                    <p class="text-muted small mb-0">
                        <i class="bi bi-telephone me-1"></i> +91 80 1234 5678
                    </p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="contact-info-card">
                    <div class="info-icon mx-auto"><i class="bi bi-building"></i></div>
                    <h5 class="fw-bold">Chennai Office</h5>
                    <p class="text-muted mb-0">
                        Anna Salai, Chennai<br>
                        Tamil Nadu, India - 600002
                    </p>
                    <hr class="my-3">
                    <p class="text-muted small mb-0">
                        <i class="bi bi-telephone me-1"></i> +91 44 1234 5678
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Support Team -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Our <span class="yellow-text">Support Team</span></h2>
            <p>Reach out directly to our dedicated support team</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="contact-info-card">
                    <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="Support" class="rounded-circle mb-3" style="width: 80px; height: 80px; object-fit: cover;">
                    <h5 class="fw-bold">Sales Support</h5>
                    <p class="text-muted">For pricing and demo inquiries</p>
                    <p class="mb-0"><i class="bi bi-envelope me-2"></i> sales@tekcglobal.com</p>
                    <p><i class="bi bi-telephone me-2"></i> +91 72003 16099</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="contact-info-card">
                    <img src="https://randomuser.me/api/portraits/women/68.jpg" alt="Support" class="rounded-circle mb-3" style="width: 80px; height: 80px; object-fit: cover;">
                    <h5 class="fw-bold">Technical Support</h5>
                    <p class="text-muted">For technical assistance</p>
                    <p class="mb-0"><i class="bi bi-envelope me-2"></i> support@tekcglobal.com</p>
                    <p><i class="bi bi-telephone me-2"></i> +91 72003 16098</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="contact-info-card">
                    <img src="https://randomuser.me/api/portraits/men/45.jpg" alt="Support" class="rounded-circle mb-3" style="width: 80px; height: 80px; object-fit: cover;">
                    <h5 class="fw-bold">Partnerships</h5>
                    <p class="text-muted">For partnership opportunities</p>
                    <p class="mb-0"><i class="bi bi-envelope me-2"></i> partners@tekcglobal.com</p>
                    <p><i class="bi bi-telephone me-2"></i> +91 72003 16097</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Contact Section -->
<section>
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <h2 class="fw-black">Frequently Asked <span class="yellow-text">Questions</span></h2>
                <p class="text-muted">Find quick answers to common questions</p>
                
                <div class="mt-4">
                    <div class="mb-3">
                        <h6 class="fw-bold"><i class="bi bi-question-circle text-warning me-2"></i> How quickly do you respond?</h6>
                        <p class="text-muted ms-4">We typically respond within 24 hours during business days.</p>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold"><i class="bi bi-question-circle text-warning me-2"></i> Do you offer free demos?</h6>
                        <p class="text-muted ms-4">Yes, we offer free personalized demos for all our plans.</p>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold"><i class="bi bi-question-circle text-warning me-2"></i> Is there a free trial available?</h6>
                        <p class="text-muted ms-4">Yes, we offer a 14-day free trial with no credit card required.</p>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold"><i class="bi bi-question-circle text-warning me-2"></i> What support options are available?</h6>
                        <p class="text-muted ms-4">Email, phone, live chat, and dedicated account manager for enterprise plans.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <div class="image-showcase" style="background: linear-gradient(135deg, #101820, #1a2a3a); border-radius: 24px; padding: 40px; text-align: center; min-height: 350px; display: flex; align-items: center; justify-content: center; flex-direction: column;">
                    <i class="bi bi-headset display-1 text-warning mb-4"></i>
                    <h3 class="text-white">Need Immediate Help?</h3>
                    <p class="text-white-50">Chat with our support team live</p>
                    <a href="#" class="btn btn-yellow mt-3">
                        <i class="bi bi-chat-dots me-2"></i> Start Live Chat
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Final CTA -->
<section class="final-cta" style="background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%); color: #fff; padding: 70px 0; position: relative; overflow: hidden;">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8 mx-auto text-center" data-aos="fade-up">
                <h2 class="display-5 fw-black">Ready to <span class="yellow-text">Get Started</span>?</h2>
                <p class="mt-3 fs-5">Join <?php echo $clientCount; ?>+ satisfied clients using TEK-C for their construction management</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="#" class="btn btn-yellow"><i class="bi bi-calendar2-check me-2"></i> Schedule a Demo</a>
                    <a href="#" class="btn btn-outline-yellow"><i class="bi bi-download me-2"></i> Download Brochure</a>
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

    // Form validation enhancement
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const name = this.querySelector('[name="name"]').value;
            const email = this.querySelector('[name="email"]').value;
            const message = this.querySelector('[name="message"]').value;
            
            if (!name.trim() || !email.trim() || !message.trim()) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    }
</script>

</body>
</html>