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
    
    // Get recent support tickets (if table exists)
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM support_tickets WHERE status = 'open'");
        $openTickets = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch(PDOException $e) {
        $openTickets = 0;
    }
    
} catch(PDOException $e) {
    $siteCount = 8;
    $employeeCount = 18;
    $clientCount = 3;
    $openTickets = 0;
}

// Knowledge base categories
$knowledgeCategories = [
    ['name' => 'Getting Started', 'icon' => 'bi-rocket-takeoff', 'article_count' => 12, 'color' => '#ffc329'],
    ['name' => 'Site Management', 'icon' => 'bi-building', 'article_count' => 8, 'color' => '#ffc329'],
    ['name' => 'Reports & Documentation', 'icon' => 'bi-journal-text', 'article_count' => 15, 'color' => '#ffc329'],
    ['name' => 'Quotations & Tendering', 'icon' => 'bi-calculator', 'article_count' => 10, 'color' => '#ffc329'],
    ['name' => 'HR & Payroll', 'icon' => 'bi-people', 'article_count' => 9, 'color' => '#ffc329'],
    ['name' => 'Mobile App', 'icon' => 'bi-phone', 'article_count' => 6, 'color' => '#ffc329'],
    ['name' => 'Integrations', 'icon' => 'bi-puzzle', 'article_count' => 7, 'color' => '#ffc329'],
    ['name' => 'Troubleshooting', 'icon' => 'bi-tools', 'article_count' => 11, 'color' => '#ffc329'],
];

// Popular articles
$popularArticles = [
    ['title' => 'How to create a new site', 'views' => 1234, 'category' => 'Site Management'],
    ['title' => 'Submitting Daily Progress Report (DPR)', 'views' => 987, 'category' => 'Reports'],
    ['title' => 'Managing employee attendance', 'views' => 856, 'category' => 'HR & Payroll'],
    ['title' => 'Creating and comparing quotations', 'views' => 765, 'category' => 'Quotations'],
    ['title' => 'Setting up user roles and permissions', 'views' => 654, 'category' => 'Getting Started'],
    ['title' => 'Mobile app installation guide', 'views' => 543, 'category' => 'Mobile App'],
];

// FAQ categories
$faqCategories = [
    'General' => [
        ['question' => 'What is TEK-C?', 'answer' => 'TEK-C is a comprehensive construction ERP software designed for builders, contractors, and developers to manage sites, reports, quotations, HR, and more in one platform.'],
        ['question' => 'Is TEK-C cloud-based?', 'answer' => 'Yes, TEK-C is available as a cloud-based SaaS platform, and we also offer on-premise deployment for enterprise clients.'],
        ['question' => 'Can I access TEK-C on mobile?', 'answer' => 'Yes, TEK-C has a mobile app available for both iOS and Android devices for attendance, reports, and approvals.'],
    ],
    'Technical' => [
        ['question' => 'What browsers are supported?', 'answer' => 'TEK-C supports all modern browsers including Chrome, Firefox, Safari, and Edge (latest versions).'],
        ['question' => 'Is my data secure?', 'answer' => 'Yes, we use bank-grade encryption, regular backups, and comply with data protection regulations to keep your data secure.'],
        ['question' => 'How often is data backed up?', 'answer' => 'We perform daily automated backups and keep them for 30 days. Enterprise clients can request custom backup schedules.'],
    ],
    'Billing' => [
        ['question' => 'How does pricing work?', 'answer' => 'We offer monthly and yearly subscription plans based on the number of sites and features required. Contact sales for custom enterprise pricing.'],
        ['question' => 'Can I upgrade my plan?', 'answer' => 'Yes, you can upgrade or downgrade your plan at any time. Changes take effect in the next billing cycle.'],
        ['question' => 'Is there a setup fee?', 'answer' => 'No, there are no setup fees for any of our plans. You only pay the subscription fee.'],
    ],
    'Support' => [
        ['question' => 'How do I get support?', 'answer' => 'You can reach us via email, phone, live chat, or submit a ticket through the help center. Support is available 24/7 for enterprise clients.'],
        ['question' => 'What is your response time?', 'answer' => 'We typically respond within 24 hours for standard queries and within 4 hours for priority issues.'],
        ['question' => 'Do you offer training?', 'answer' => 'Yes, we provide comprehensive onboarding training and have video tutorials available in our knowledge base.'],
    ],
];

// Video tutorials
$videoTutorials = [
    ['title' => 'Getting Started with TEK-C', 'duration' => '5:30', 'thumbnail' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
    ['title' => 'How to Create DPR Reports', 'duration' => '8:15', 'thumbnail' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
    ['title' => 'Managing Quotations', 'duration' => '6:45', 'thumbnail' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
    ['title' => 'HR & Payroll Setup', 'duration' => '10:20', 'thumbnail' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
];

// Construction images
$constructionImages = [
    'hero' => 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1800&q=80',
    'support' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=800&q=80',
    'office' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=800&q=80',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Center | TEK-C Global Construction ERP</title>

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

        .hero-help {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 80px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-help::before {
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

        .hero-help .container { position: relative; z-index: 1; }
        .hero-help h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-help .yellow-text { color: var(--yellow); }

        .search-container {
            max-width: 600px;
            margin: 30px auto 0;
        }
        .help-search {
            background: #fff;
            border: none;
            border-radius: 60px;
            padding: 15px 25px;
            font-size: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,.2);
        }
        .help-search:focus { outline: none; box-shadow: 0 10px 30px rgba(255,195,41,.3); }

        section { padding: 85px 0; }
        .section-title { text-align: center; margin-bottom: 50px; }
        .section-title h2 { font-size: clamp(32px, 4vw, 48px); font-weight: 900; color: #111827; letter-spacing: -1px; }
        .section-title p { color: var(--muted); font-size: 17px; }

        .category-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            transition: .35s;
            height: 100%;
            cursor: pointer;
        }
        .category-card:hover { transform: translateY(-8px); border-color: var(--yellow); box-shadow: 0 15px 35px rgba(0,0,0,.1); }
        .category-icon {
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

        .article-card, .faq-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            transition: .3s;
            cursor: pointer;
        }
        .article-card:hover, .faq-card:hover { background: #f8f9fa; border-color: var(--yellow); transform: translateX(5px); }

        .video-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            transition: .35s;
            cursor: pointer;
        }
        .video-card:hover { transform: translateY(-5px); border-color: var(--yellow); box-shadow: 0 10px 25px rgba(0,0,0,.1); }
        .video-thumbnail {
            position: relative;
            height: 180px;
            background-size: cover;
            background-position: center;
        }
        .play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 50px;
            height: 50px;
            background: var(--yellow);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: .3s;
        }
        .play-btn:hover { transform: translate(-50%, -50%) scale(1.1); }

        .support-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            height: 100%;
            transition: .35s;
        }
        .support-card:hover { transform: translateY(-5px); border-color: var(--yellow); }

        .ticket-card {
            background: linear-gradient(135deg, #101820, #1a2a3a);
            border-radius: 20px;
            padding: 35px;
            color: #fff;
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
        }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- Hero Section -->
<section class="hero-help">
    <div class="container text-center">
        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>How can we <span class="yellow-text">help</span> you?</h1>
                <p class="lead mt-3">Find answers, guides, and support resources for TEK-C</p>
                
                <div class="search-container">
                    <div class="input-group">
                        <input type="text" class="form-control help-search" id="searchInput" placeholder="Search for articles, guides, or FAQs...">
                        <button class="btn btn-yellow" style="border-radius: 0 60px 60px 0; padding: 15px 30px;">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2">Popular: DPR Reports</span>
                    <span class="badge bg-warning text-dark me-2 p-2">Site Setup</span>
                    <span class="badge bg-warning text-dark p-2">Mobile App</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Quick Stats -->
<section style="padding: 40px 0;">
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="zoom-in">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <h3 class="fw-bold text-warning mb-0">78</h3>
                    <p class="text-muted mb-0">Knowledge Articles</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <h3 class="fw-bold text-warning mb-0">24/7</h3>
                    <p class="text-muted mb-0">Support Available</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <h3 class="fw-bold text-warning mb-0">&lt;24hr</h3>
                    <p class="text-muted mb-0">Response Time</p>
                </div>
            </div>
            <div class="col-md-3 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="stat-card-mini" style="background: #fff; padding: 20px; border-radius: 16px; border: 1px solid var(--border);">
                    <h3 class="fw-bold text-warning mb-0">98%</h3>
                    <p class="text-muted mb-0">Satisfaction Rate</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Knowledge Base Categories -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Knowledge <span class="yellow-text">Base</span></h2>
            <p>Browse articles by category</p>
        </div>

        <div class="row g-4">
            <?php foreach ($knowledgeCategories as $category): ?>
            <div class="col-lg-3 col-md-4 col-6" data-aos="fade-up">
                <div class="category-card">
                    <div class="category-icon mx-auto"><i class="<?php echo $category['icon']; ?>"></i></div>
                    <h5 class="fw-bold"><?php echo $category['name']; ?></h5>
                    <p class="text-muted small mb-0"><?php echo $category['article_count']; ?> articles</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Popular Articles -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Most <span class="yellow-text">Popular</span> Articles</h2>
            <p>Most viewed guides and tutorials</p>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
                <?php foreach ($popularArticles as $article): ?>
                <div class="article-card" data-aos="fade-up">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-1"><?php echo $article['title']; ?></h6>
                            <small class="text-muted"><i class="bi bi-folder me-1"></i> <?php echo $article['category']; ?></small>
                        </div>
                        <div class="text-end">
                            <small class="text-muted"><i class="bi bi-eye me-1"></i> <?php echo number_format($article['views']); ?> views</small>
                            <i class="bi bi-chevron-right ms-3 text-warning"></i>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Frequently Asked <span class="yellow-text">Questions</span></h2>
            <p>Quick answers to common questions</p>
        </div>

        <div class="row">
            <div class="col-lg-10 mx-auto">
                <?php foreach ($faqCategories as $categoryName => $faqs): ?>
                <h5 class="fw-bold mt-4 mb-3"><?php echo $categoryName; ?></h5>
                <?php foreach ($faqs as $faq): ?>
                <div class="faq-card" data-aos="fade-up">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-1"><?php echo $faq['question']; ?></h6>
                            <p class="text-muted small mb-0"><?php echo $faq['answer']; ?></p>
                        </div>
                        <i class="bi bi-question-circle text-warning fs-4"></i>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- Video Tutorials -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Video <span class="yellow-text">Tutorials</span></h2>
            <p>Watch and learn with our step-by-step guides</p>

            <div class="row g-4 mt-3">
                <?php foreach ($videoTutorials as $video): ?>
                <div class="col-md-3 col-6" data-aos="fade-up">
                    <div class="video-card">
                        <div class="video-thumbnail" style="background-image: url('<?php echo $video['thumbnail']; ?>');">
                            <div class="play-btn">
                                <i class="bi bi-play-fill fs-3"></i>
                            </div>
                        </div>
                        <div class="p-3">
                            <h6 class="fw-bold mb-1"><?php echo $video['title']; ?></h6>
                            <small class="text-muted"><i class="bi bi-clock me-1"></i> <?php echo $video['duration']; ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- Support Options -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Get <span class="yellow-text">Support</span></h2>
            <p>Choose how you'd like to reach us</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="support-card">
                    <div class="category-icon mx-auto"><i class="bi bi-chat-dots-fill"></i></div>
                    <h5 class="fw-bold">Live Chat</h5>
                    <p class="text-muted">Chat with our support team in real-time</p>
                    <button class="btn btn-yellow" onclick="startChat()">
                        <i class="bi bi-chat me-2"></i> Start Chat
                    </button>
                    <p class="text-muted small mt-3">Available 24/7 for enterprise clients</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="support-card">
                    <div class="category-icon mx-auto"><i class="bi bi-envelope-fill"></i></div>
                    <h5 class="fw-bold">Email Support</h5>
                    <p class="text-muted">Send us an email and we'll respond within 24 hours</p>
                    <a href="mailto:support@tekcglobal.com" class="btn btn-outline-yellow">
                        <i class="bi bi-envelope me-2"></i> support@tekcglobal.com
                    </a>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="support-card">
                    <div class="category-icon mx-auto"><i class="bi bi-telephone-fill"></i></div>
                    <h5 class="fw-bold">Phone Support</h5>
                    <p class="text-muted">Call us directly for immediate assistance</p>
                    <h4 class="fw-bold text-warning">+91 72003 16099</h4>
                    <p class="text-muted small mt-2">Mon-Fri: 9AM - 6PM IST</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Submit Ticket Section -->
<section>
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-7" data-aos="fade-right">
                <div class="ticket-card">
                    <h3 class="fw-bold mb-3">Submit a <span class="yellow-text">Support Ticket</span></h3>
                    <p class="mb-4">Can't find what you're looking for? Submit a ticket and our team will help you.</p>
                    
                    <form action="" method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <input type="text" class="form-control" placeholder="Your Name" required style="border-radius: 10px; padding: 12px;">
                            </div>
                            <div class="col-md-6">
                                <input type="email" class="form-control" placeholder="Email Address" required style="border-radius: 10px; padding: 12px;">
                            </div>
                            <div class="col-12">
                                <select class="form-select" required style="border-radius: 10px; padding: 12px;">
                                    <option value="">Select Category</option>
                                    <option>Technical Issue</option>
                                    <option>Billing Question</option>
                                    <option>Feature Request</option>
                                    <option>General Inquiry</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <input type="text" class="form-control" placeholder="Subject" required style="border-radius: 10px; padding: 12px;">
                            </div>
                            <div class="col-12">
                                <textarea class="form-control" rows="4" placeholder="Describe your issue in detail..." required style="border-radius: 10px; padding: 12px;"></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-yellow w-100">
                                    <i class="bi bi-send me-2"></i> Submit Ticket
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-lg-5" data-aos="fade-left">
                <div class="support-card h-100 d-flex flex-column justify-content-center">
                    <div class="text-center">
                        <i class="bi bi-clock-history display-1 text-warning"></i>
                        <h4 class="fw-bold mt-3">Average Response Time</h4>
                        <div class="display-4 fw-bold text-warning">&lt; 24 Hours</div>
                        <p class="text-muted mt-3">Priority support available for enterprise clients</p>
                        <hr>
                        <p class="mb-0"><i class="bi bi-check-circle-fill text-warning me-2"></i> 98% customer satisfaction</p>
                        <p><i class="bi bi-check-circle-fill text-warning me-2"></i> 500+ tickets resolved monthly</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- System Status -->
<section class="bg-light">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-8" data-aos="fade-right">
                <h2 class="fw-black">System <span class="yellow-text">Status</span></h2>
                <p class="text-muted">Current health of TEK-C services</p>
                
                <div class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-white rounded-3">
                        <div>
                            <i class="bi bi-cloud fs-4 me-3 text-warning"></i>
                            <strong>TEK-C Platform</strong>
                        </div>
                        <span class="badge bg-success px-3 py-2">Operational</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-white rounded-3">
                        <div>
                            <i class="bi bi-database fs-4 me-3 text-warning"></i>
                            <strong>Database Services</strong>
                        </div>
                        <span class="badge bg-success px-3 py-2">Operational</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-white rounded-3">
                        <div>
                            <i class="bi bi-phone fs-4 me-3 text-warning"></i>
                            <strong>Mobile App API</strong>
                        </div>
                        <span class="badge bg-success px-3 py-2">Operational</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center p-3 bg-white rounded-3">
                        <div>
                            <i class="bi bi-envelope fs-4 me-3 text-warning"></i>
                            <strong>Email Notifications</strong>
                        </div>
                        <span class="badge bg-warning px-3 py-2">Degraded Performance</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-4" data-aos="fade-left">
                <div class="support-card text-center">
                    <i class="bi bi-newspaper display-1 text-warning"></i>
                    <h5 class="fw-bold mt-3">Subscribe to Updates</h5>
                    <p class="text-muted">Get notified about system status changes</p>
                    <div class="input-group">
                        <input type="email" class="form-control" placeholder="Your email">
                        <button class="btn btn-yellow">Subscribe</button>
                    </div>
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
                <h2 class="display-5 fw-black">Still need <span class="yellow-text">help</span>?</h2>
                <p class="mt-3 fs-5">Our support team is ready to assist you</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4">
                    <a href="#" class="btn btn-yellow btn-lg">
                        <i class="bi bi-chat-dots me-2"></i> Start Live Chat
                    </a>
                    <a href="contact.php" class="btn btn-outline-yellow btn-lg">
                        <i class="bi bi-envelope me-2"></i> Contact Support
                    </a>
                </div>
                <p class="mt-4 small">Or call us directly: <strong class="text-warning">+91 72003 16099</strong></p>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
    AOS.init({ duration: 1000, once: true, offset: 80 });

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const articles = document.querySelectorAll('.article-card');
    const faqs = document.querySelectorAll('.faq-card');

    function searchHelp() {
        const searchTerm = searchInput.value.toLowerCase();
        
        articles.forEach(article => {
            const text = article.innerText.toLowerCase();
            article.style.display = text.includes(searchTerm) ? 'block' : 'none';
        });
        
        faqs.forEach(faq => {
            const text = faq.innerText.toLowerCase();
            faq.style.display = text.includes(searchTerm) ? 'block' : 'none';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('keyup', searchHelp);
    }

    // Start chat function
    function startChat() {
        alert('Live chat feature coming soon! Please email support@tekcglobal.com for immediate assistance.');
    }

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