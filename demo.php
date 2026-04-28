<?php
// Start session
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'u209621005_tekc';
$username = 'root';
$password = '';

// Initialize variables
$success_message = '';
$error_message = '';
$booking_success = false;

// Handle demo booking form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $company_name = $_POST['company_name'] ?? '';
    $designation = $_POST['designation'] ?? '';
    $preferred_date = $_POST['preferred_date'] ?? '';
    $preferred_time = $_POST['preferred_time'] ?? '';
    $module_interest = isset($_POST['module_interest']) ? implode(', ', $_POST['module_interest']) : '';
    $team_size = $_POST['team_size'] ?? '';
    $message = $_POST['message'] ?? '';
    
    // Basic validation
    if (empty($full_name) || empty($email) || empty($phone) || empty($preferred_date)) {
        $error_message = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create demo_requests table if not exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS demo_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(190) NOT NULL,
                phone VARCHAR(15) NOT NULL,
                company_name VARCHAR(200),
                designation VARCHAR(100),
                preferred_date DATE NOT NULL,
                preferred_time VARCHAR(50),
                module_interest TEXT,
                team_size VARCHAR(50),
                message TEXT,
                status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
            // Insert demo request
            $stmt = $pdo->prepare("INSERT INTO demo_requests (full_name, email, phone, company_name, designation, preferred_date, preferred_time, module_interest, team_size, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$full_name, $email, $phone, $company_name, $designation, $preferred_date, $preferred_time, $module_interest, $team_size, $message]);
            
            $booking_success = true;
            $success_message = 'Thank you for requesting a demo! Our team will contact you within 24 hours to confirm your slot.';
            
            // Clear form data
            $_POST = array();
            
        } catch(PDOException $e) {
            $error_message = 'Unable to process your request. Please try again later.';
        }
    }
}

// Fetch stats for the page
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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

// Available time slots
$timeSlots = [
    '10:00 AM', '10:30 AM', '11:00 AM', '11:30 AM',
    '12:00 PM', '12:30 PM', '2:00 PM', '2:30 PM',
    '3:00 PM', '3:30 PM', '4:00 PM', '4:30 PM'
];

// Module options
$modules = [
    'Site & Project Management' => 'bi-building',
    'Daily Reports (DPR, DAR, MOM)' => 'bi-journal-text',
    'Quotation & Tendering' => 'bi-calculator',
    'HR & Payroll Management' => 'bi-people',
    'Document Control System' => 'bi-folder2-open',
    'RFI & AIT Tracker' => 'bi-question-circle',
    'Analytics & Dashboard' => 'bi-graph-up',
    'Mobile Application' => 'bi-phone'
];

// Team size options
$teamSizes = [
    '1-10 employees',
    '11-50 employees',
    '51-200 employees',
    '201-500 employees',
    '500+ employees'
];

// Construction images
$constructionImages = [
    'hero' => 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1800&q=80',
    'demo1' => 'https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=800&q=80',
    'demo2' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=800&q=80',
    'demo3' => 'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=800&q=80',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request a Demo | TEK-C Global Construction ERP</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

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

        .hero-demo {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 100px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-demo::before {
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

        .hero-demo .container { position: relative; z-index: 1; }
        .hero-demo h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-demo .yellow-text { color: var(--yellow); }

        section { padding: 85px 0; }
        .section-title { text-align: center; margin-bottom: 50px; }
        .section-title h2 { font-size: clamp(32px, 4vw, 48px); font-weight: 900; color: #111827; letter-spacing: -1px; }
        .section-title p { color: var(--muted); font-size: 17px; }

        .demo-form-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 10px 32px rgba(17,24,39,.08);
        }

        .form-control-custom, .form-select-custom {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            transition: .3s;
        }

        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--yellow);
            box-shadow: 0 0 0 3px rgba(255,195,41,.1);
            outline: none;
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 10px;
        }
        .checkbox-item {
            flex: 1;
            min-width: calc(33.33% - 10px);
        }
        .module-checkbox {
            background: #f8f9fa;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 15px;
            cursor: pointer;
            transition: .3s;
        }
        .module-checkbox:hover { background: rgba(255,195,41,.1); border-color: var(--yellow); }
        .module-checkbox input { margin-right: 10px; }
        .module-checkbox label { cursor: pointer; margin: 0; font-weight: 500; }

        .benefit-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            height: 100%;
            transition: .35s;
        }
        .benefit-card:hover { transform: translateY(-8px); border-color: var(--yellow); }
        .benefit-icon {
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

        .step-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            height: 100%;
            position: relative;
        }
        .step-number {
            width: 40px;
            height: 40px;
            background: var(--yellow);
            color: #111;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
        }

        .alert-custom {
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .testimonial-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 25px;
            height: 100%;
        }
        .stars { color: var(--yellow2); letter-spacing: 3px; margin-bottom: 15px; }
        .avatar { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; }

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
            .demo-form-card { padding: 25px; }
            .checkbox-item { min-width: calc(50% - 10px); }
        }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-demo">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>See <span class="yellow-text">TEK-C</span> in Action</h1>
                <p class="lead mt-4">Schedule a personalized demo and discover how TEK-C can transform your construction management.</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-clock me-1"></i> 30-Min Demo</span>
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-person-check me-1"></i> Expert Guidance</span>
                    <span class="badge bg-warning text-dark p-2"><i class="bi bi-question-circle me-1"></i> Q&A Session</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Demo Benefits -->
<section style="padding: 60px 0 0 0;">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="benefit-card">
                    <div class="benefit-icon mx-auto"><i class="bi bi-person-video3"></i></div>
                    <h5 class="fw-bold">Live Product Walkthrough</h5>
                    <p class="text-muted">See TEK-C in action with a live demonstration of key features and workflows.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="benefit-card">
                    <div class="benefit-icon mx-auto"><i class="bi bi-chat-dots"></i></div>
                    <h5 class="fw-bold">Q&A Session</h5>
                    <p class="text-muted">Get all your questions answered by our product experts during the demo.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="benefit-card">
                    <div class="benefit-icon mx-auto"><i class="bi bi-puzzle"></i></div>
                    <h5 class="fw-bold">Customized Experience</h5>
                    <p class="text-muted">Demo tailored to your specific business needs and requirements.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Demo Booking Form -->
<section>
    <div class="container">
        <div class="row g-5">
            <!-- Form Column -->
            <div class="col-lg-7" data-aos="fade-right">
                <div class="demo-form-card">
                    <h3 class="fw-bold mb-2">Request a <span class="yellow-text">Demo</span></h3>
                    <p class="text-muted mb-4">Fill out the form and our team will contact you within 24 hours.</p>
                    
                    <?php if ($success_message): ?>
                        <div class="alert alert-success alert-custom">
                            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success_message; ?>
                            <?php if ($booking_success): ?>
                                <div class="mt-3">
                                    <a href="index.php" class="btn btn-sm btn-outline-success">Back to Home</a>
                                    <a href="contact.php" class="btn btn-sm btn-outline-success ms-2">Contact Support</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-custom">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!$booking_success): ?>
                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Full Name *</label>
                                <input type="text" name="full_name" class="form-control form-control-custom" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email Address *</label>
                                <input type="email" name="email" class="form-control form-control-custom" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Phone Number *</label>
                                <input type="tel" name="phone" class="form-control form-control-custom" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Company Name</label>
                                <input type="text" name="company_name" class="form-control form-control-custom" value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Designation</label>
                                <input type="text" name="designation" class="form-control form-control-custom" value="<?php echo htmlspecialchars($_POST['designation'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Team Size</label>
                                <select name="team_size" class="form-select form-select-custom">
                                    <option value="">Select team size</option>
                                    <?php foreach ($teamSizes as $size): ?>
                                        <option value="<?php echo $size; ?>" <?php echo (($_POST['team_size'] ?? '') == $size) ? 'selected' : ''; ?>><?php echo $size; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Preferred Date *</label>
                                <input type="date" name="preferred_date" class="form-control form-control-custom" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" value="<?php echo htmlspecialchars($_POST['preferred_date'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Preferred Time</label>
                                <select name="preferred_time" class="form-select form-select-custom">
                                    <option value="">Select preferred time</option>
                                    <?php foreach ($timeSlots as $slot): ?>
                                        <option value="<?php echo $slot; ?>" <?php echo (($_POST['preferred_time'] ?? '') == $slot) ? 'selected' : ''; ?>><?php echo $slot; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Modules Interested In</label>
                                <div class="checkbox-group">
                                    <?php foreach ($modules as $module => $icon): ?>
                                        <div class="checkbox-item">
                                            <div class="module-checkbox">
                                                <input type="checkbox" name="module_interest[]" value="<?php echo $module; ?>" id="module_<?php echo md5($module); ?>" <?php echo (isset($_POST['module_interest']) && in_array($module, $_POST['module_interest'])) ? 'checked' : ''; ?>>
                                                <label for="module_<?php echo md5($module); ?>"><i class="<?php echo $icon; ?> me-1"></i> <?php echo $module; ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Additional Message / Requirements</label>
                                <textarea name="message" class="form-control form-control-custom" rows="4" placeholder="Tell us about your specific requirements or questions..."><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-yellow w-100">
                                    <i class="bi bi-calendar-check me-2"></i> Schedule Demo
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <div class="mt-4 pt-3 text-center">
                        <p class="text-muted small mb-0">
                            <i class="bi bi-shield-check me-1"></i> Your information is secure. We respect your privacy.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Info Column -->
            <div class="col-lg-5" data-aos="fade-left">
                <!-- How It Works -->
                <div class="demo-form-card mb-4">
                    <h4 class="fw-bold mb-3">How It <span class="yellow-text">Works</span></h4>
                    <div class="step-card mb-3">
                        <div class="step-number">1</div>
                        <i class="bi bi-pencil-square fs-1 text-warning"></i>
                        <h6 class="fw-bold mt-2">Fill the Form</h6>
                        <p class="text-muted small mb-0">Tell us about your requirements and preferred time.</p>
                    </div>
                    <div class="step-card mb-3">
                        <div class="step-number">2</div>
                        <i class="bi bi-chat-dots fs-1 text-warning"></i>
                        <h6 class="fw-bold mt-2">Schedule Confirmation</h6>
                        <p class="text-muted small mb-0">Our team contacts you to confirm the demo slot.</p>
                    </div>
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <i class="bi bi-camera-reels fs-1 text-warning"></i>
                        <h6 class="fw-bold mt-2">Live Demo Session</h6>
                        <p class="text-muted small mb-0">Join the personalized demo and get your questions answered.</p>
                    </div>
                </div>
                
                <!-- What to Expect -->
                <div class="demo-form-card">
                    <h4 class="fw-bold mb-3">What to <span class="yellow-text">Expect</span></h4>
                    <ul class="list-unstyled">
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-warning me-2"></i> 30-45 minute personalized demo</li>
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-warning me-2"></i> Live product walkthrough</li>
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-warning me-2"></i> Q&A with product expert</li>
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-warning me-2"></i> Customized to your business needs</li>
                        <li class="mb-3"><i class="bi bi-check-circle-fill text-warning me-2"></i> No obligation to purchase</li>
                        <li><i class="bi bi-check-circle-fill text-warning me-2"></i> Free consultation included</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Testimonials -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>What Clients Say About <span class="yellow-text">Our Demos</span></h2>
            <p>See why construction professionals choose TEK-C</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p>"The demo was incredibly helpful. Our team got to see exactly how TEK-C would work for our specific needs. Highly recommend!"</p>
                    <div class="d-flex align-items-center gap-3 mt-3">
                        <img class="avatar" src="https://randomuser.me/api/portraits/men/32.jpg" alt="">
                        <div>
                            <h6 class="fw-bold mb-0">Rajesh Kumar</h6>
                            <small class="text-warning">Project Director</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p>"Great experience! The team was knowledgeable and answered all our questions. The customized demo showed exactly what we needed."</p>
                    <div class="d-flex align-items-center gap-3 mt-3">
                        <img class="avatar" src="https://randomuser.me/api/portraits/women/68.jpg" alt="">
                        <div>
                            <h6 class="fw-bold mb-0">Priya Sharma</h6>
                            <small class="text-warning">Operations Manager</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p>"The demo exceeded our expectations. We signed up right after because the product perfectly fits our construction management needs."</p>
                    <div class="d-flex align-items-center gap-3 mt-3">
                        <img class="avatar" src="https://randomuser.me/api/portraits/men/45.jpg" alt="">
                        <div>
                            <h6 class="fw-bold mb-0">Sundar Rajan</h6>
                            <small class="text-warning">Managing Director</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Stats Section -->
<section>
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="stat-card-mini" style="background: #fff; padding: 25px; border-radius: 20px; border: 1px solid var(--border);">
                    <h3 class="fw-bold text-warning mb-0"><?php echo $siteCount; ?>+</h3>
                    <p class="text-muted mb-0">Active Sites</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="stat-card-mini" style="background: #fff; padding: 25px; border-radius: 20px; border: 1px solid var(--border);">
                    <h3 class="fw-bold text-warning mb-0"><?php echo $employeeCount; ?>+</h3>
                    <p class="text-muted mb-0">Users Onboarded</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="stat-card-mini" style="background: #fff; padding: 25px; border-radius: 20px; border: 1px solid var(--border);">
                    <h3 class="fw-bold text-warning mb-0">100%</h3>
                    <p class="text-muted mb-0">Satisfaction Rate</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="stat-card-mini" style="background: #fff; padding: 25px; border-radius: 20px; border: 1px solid var(--border);">
                    <h3 class="fw-bold text-warning mb-0">24hr</h3>
                    <p class="text-muted mb-0">Response Time</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Demo Section -->
<section class="bg-light">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6" data-aos="fade-right">
                <h2 class="fw-black">Frequently Asked <span class="yellow-text">Questions</span></h2>
                <p class="text-muted">About our demo process</p>
                
                <div class="mt-4">
                    <div class="mb-3">
                        <h6 class="fw-bold"><i class="bi bi-question-circle text-warning me-2"></i> How long is the demo?</h6>
                        <p class="text-muted ms-4">Our standard demo lasts 30-45 minutes, but we can adjust based on your needs.</p>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold"><i class="bi bi-question-circle text-warning me-2"></i> Is the demo free?</h6>
                        <p class="text-muted ms-4">Yes, all our demos are completely free with no obligation to purchase.</p>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold"><i class="bi bi-question-circle text-warning me-2"></i> Can I bring my team?</h6>
                        <p class="text-muted ms-4">Absolutely! We encourage you to invite your entire decision-making team.</p>
                    </div>
                    <div class="mb-3">
                        <h6 class="fw-bold"><i class="bi bi-question-circle text-warning me-2"></i> What if I need to reschedule?</h6>
                        <p class="text-muted ms-4">Just contact us and we'll be happy to reschedule at your convenience.</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <div class="demo-form-card text-center">
                    <i class="bi bi-headset display-1 text-warning mb-3"></i>
                    <h4>Prefer to talk <span class="yellow-text">now</span>?</h4>
                    <p class="text-muted">Call us directly to schedule your demo</p>
                    <h3 class="fw-bold mb-3">+91 72003 16099</h3>
                    <a href="contact.php" class="btn btn-outline-yellow">Contact Sales Team</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Final CTA -->
<section class="final-cta" style="background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%); color: #fff; padding: 70px 0; position: relative; overflow: hidden;">
    <div class="container">
        <div class="row align-items-center text-center">
            <div class="col-lg-8 mx-auto" data-aos="fade-up">
                <h2 class="display-5 fw-black">Ready to Transform Your <span class="yellow-text">Construction Business</span>?</h2>
                <p class="mt-3 fs-5">Book your personalized demo today and see TEK-C in action</p>
                <div class="mt-4">
                    <a href="#demo-form" class="btn btn-yellow btn-lg">
                        <i class="bi bi-calendar2-check me-2"></i> Schedule Demo Now
                    </a>
                </div>
                <p class="mt-3 small">No credit card required • Free consultation • Cancel anytime</p>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
    AOS.init({ duration: 1000, once: true, offset: 80 });

    // Initialize date picker
    flatpickr("input[type=date]", {
        minDate: "today",
        maxDate: new Date().fp_incr(30),
        dateFormat: "Y-m-d",
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

    // Module checkbox styling
    document.querySelectorAll('.module-checkbox').forEach(checkbox => {
        checkbox.addEventListener('click', function(e) {
            const input = this.querySelector('input');
            if (e.target !== input) {
                input.checked = !input.checked;
            }
            this.style.background = input.checked ? 'rgba(255,195,41,.15)' : '#f8f9fa';
            this.style.borderColor = input.checked ? '#ffc329' : '#e9edf3';
        });
        
        const input = checkbox.querySelector('input');
        if (input.checked) {
            checkbox.style.background = 'rgba(255,195,41,.15)';
            checkbox.style.borderColor = '#ffc329';
        }
    });
</script>

</body>
</html>