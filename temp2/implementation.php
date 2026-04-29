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

// Implementation phases
$implementationPhases = [
    1 => [
        'name' => 'Discovery & Planning',
        'icon' => 'bi-search-heart',
        'duration' => '1-2 weeks',
        'tasks' => [
            'Initial consultation and requirement gathering',
            'Team stakeholder identification',
            'System architecture review',
            'Customization requirements documentation',
            'Data migration strategy planning',
            'Success criteria definition',
            'Project timeline finalization'
        ],
        'deliverables' => [
            'Project kickoff report',
            'Requirements specification document',
            'Implementation roadmap',
            'Data migration plan'
        ]
    ],
    2 => [
        'name' => 'Setup & Configuration',
        'icon' => 'bi-gear-wide-connected',
        'duration' => '2-3 weeks',
        'tasks' => [
            'Server/Cloud environment setup',
            'Base system installation',
            'User role and permission configuration',
            'Site and project structure setup',
            'Report template customization',
            'Integration configuration',
            'Data migration execution'
        ],
        'deliverables' => [
            'Configured TEK-C environment',
            'Migrated data from legacy systems',
            'User access matrix',
            'System configuration document'
        ]
    ],
    3 => [
        'name' => 'Training & Testing',
        'icon' => 'bi-mortarboard',
        'duration' => '2-3 weeks',
        'tasks' => [
            'Admin training session',
            'End-user training workshops',
            'System acceptance testing (SAT)',
            'User acceptance testing (UAT)',
            'Bug fixes and refinements',
            'Performance optimization',
            'Documentation handover'
        ],
        'deliverables' => [
            'Training completion certificates',
            'User manuals and guides',
            'UAT sign-off document',
            'Performance test reports'
        ]
    ],
    4 => [
        'name' => 'Go-Live & Support',
        'icon' => 'bi-rocket-takeoff',
        'duration' => '1-2 weeks + ongoing',
        'tasks' => [
            'Production environment deployment',
            'Live system monitoring',
            'Hyper-care support period',
            'Issue resolution and fine-tuning',
            'Knowledge transfer to internal team',
            'Post-implementation review',
            'Ongoing maintenance setup'
        ],
        'deliverables' => [
            'Go-live announcement',
            'Support SLA agreement',
            'Post-implementation report',
            'Ongoing support plan'
        ]
    ]
];

// Training modules
$trainingModules = [
    ['name' => 'Admin Training', 'duration' => '2 days', 'audience' => 'System Administrators', 'topics' => 'User management, role configuration, system settings, reporting'],
    ['name' => 'Site Management', 'duration' => '1 day', 'audience' => 'Project Managers', 'topics' => 'Site creation, contract management, project tracking, document control'],
    ['name' => 'Report Generation', 'duration' => '1 day', 'audience' => 'Project Engineers', 'topics' => 'DPR, DAR, MOM, RFI, AIT creation and management'],
    ['name' => 'HR & Payroll', 'duration' => '2 days', 'audience' => 'HR Team', 'topics' => 'Employee management, attendance, leave, payroll, onboarding'],
    ['name' => 'Quotation Management', 'duration' => '1 day', 'audience' => 'QS Team', 'topics' => 'RFQ creation, vendor management, quotation comparison, approval workflow'],
    ['name' => 'Mobile App Training', 'duration' => '0.5 day', 'audience' => 'All Field Staff', 'topics' => 'Mobile attendance, report submission, photo upload, notifications']
];

// Success metrics
$successMetrics = [
    ['metric' => 'Faster Reporting', 'improvement' => '70%', 'icon' => 'bi-speedometer2', 'description' => 'Reduction in report preparation and approval time'],
    ['metric' => 'Better Visibility', 'improvement' => '100%', 'icon' => 'bi-eye', 'description' => 'Real-time visibility across all construction sites'],
    ['metric' => 'Cost Savings', 'improvement' => '35%', 'icon' => 'bi-piggy-bank', 'description' => 'Reduction in operational and administrative costs'],
    ['metric' => 'Improved Compliance', 'improvement' => '95%', 'icon' => 'bi-shield-check', 'description' => 'Documentation and approval compliance rate'],
    ['metric' => 'Faster Approvals', 'improvement' => '60%', 'icon' => 'bi-clock-history', 'description' => 'Reduction in approval cycle time'],
    ['metric' => 'Data Accuracy', 'improvement' => '99%', 'icon' => 'bi-check-circle', 'description' => 'Accuracy of reported data']
];

// Implementation case studies
$caseStudies = [
    [
        'company' => 'UKB Construction',
        'size' => 'Mid-size Contractor',
        'sites' => 8,
        'employees' => 45,
        'timeline' => '8 weeks',
        'result' => 'Reduced reporting time by 75%, improved site coordination',
        'testimonial' => 'TEK-C transformed our operations completely. The implementation was smooth and the team was very supportive.'
    ],
    [
        'company' => 'Anandhamayam Developers',
        'size' => 'Small Builder',
        'sites' => 3,
        'employees' => 15,
        'timeline' => '4 weeks',
        'result' => 'Streamlined approval workflow, 90% reduction in paperwork',
        'testimonial' => 'The implementation team understood our needs perfectly. Now we manage everything from one platform.'
    ],
    [
        'company' => 'Metro Infrastructure',
        'size' => 'Large Developer',
        'sites' => 15,
        'employees' => 120,
        'timeline' => '12 weeks',
        'result' => 'Enterprise-wide visibility, integrated with existing ERP',
        'testimonial' => 'TEK-C delivered exactly what they promised. The integration capabilities are impressive.'
    ]
];

// Construction images
$constructionImages = [
    'hero' => 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1800&q=80',
    'planning' => 'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=800&q=80',
    'training' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=800&q=80',
    'support' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=800&q=80',
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Implementation | TEK-C Global Construction ERP</title>

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

        .hero-implementation {
            background: linear-gradient(135deg, #101820 0%, #1a2a3a 100%);
            padding: 180px 0 100px;
            color: #fff;
            position: relative;
            overflow: hidden;
        }

        .hero-implementation::before {
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

        .hero-implementation .container { position: relative; z-index: 1; }
        .hero-implementation h1 { font-size: clamp(42px, 5vw, 68px); font-weight: 900; line-height: 1.1; }
        .hero-implementation .yellow-text { color: var(--yellow); }

        section { padding: 85px 0; }
        .section-title { text-align: center; margin-bottom: 50px; }
        .section-title h2 { font-size: clamp(32px, 4vw, 48px); font-weight: 900; color: #111827; letter-spacing: -1px; }
        .section-title p { color: var(--muted); font-size: 17px; }

        .phase-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 30px;
            transition: .35s;
            position: relative;
            overflow: hidden;
        }
        .phase-card:hover { transform: translateY(-5px); border-color: var(--yellow); box-shadow: 0 15px 35px rgba(0,0,0,.1); }
        .phase-number {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 48px;
            font-weight: 900;
            color: rgba(255,195,41,.15);
        }
        .phase-icon {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            font-size: 28px;
            color: #fff;
        }
        .timeline-badge {
            display: inline-block;
            background: #f0f0f0;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
        }

        .task-list, .deliverable-list {
            list-style: none;
            padding-left: 0;
        }
        .task-list li, .deliverable-list li {
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }
        .task-list li:last-child, .deliverable-list li:last-child { border-bottom: none; }
        .task-list li i, .deliverable-list li i { color: #22c55e; margin-right: 10px; }

        .metric-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            transition: .35s;
            height: 100%;
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

        .case-study-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 25px;
            height: 100%;
            transition: .35s;
        }
        .case-study-card:hover { transform: translateY(-5px); border-color: var(--yellow); box-shadow: 0 10px 25px rgba(0,0,0,.1); }
        .quote-icon {
            font-size: 40px;
            color: var(--yellow);
            opacity: 0.3;
            margin-bottom: 15px;
        }

        .training-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 15px;
            transition: .3s;
        }
        .training-card:hover { background: #f8f9fa; border-color: var(--yellow); }

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
<section class="hero-implementation">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8" data-aos="fade-up">
                <h1>Seamless <span class="yellow-text">Implementation</span> Process</h1>
                <p class="lead mt-4">Get your TEK-C system up and running with our proven implementation methodology</p>
                <div class="mt-4">
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-clock me-1"></i> 4-8 Weeks Typical</span>
                    <span class="badge bg-warning text-dark me-2 p-2"><i class="bi bi-person-check me-1"></i> Dedicated Team</span>
                    <span class="badge bg-warning text-dark p-2"><i class="bi bi-headset me-1"></i> Ongoing Support</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Implementation Phases -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Implementation <span class="yellow-text">Phases</span></h2>
            <p>A structured approach to ensure successful deployment</p>
        </div>

        <?php foreach ($implementationPhases as $phaseNum => $phase): ?>
        <div class="phase-card" data-aos="fade-up">
            <div class="phase-number">0<?php echo $phaseNum; ?></div>
            <div class="row">
                <div class="col-md-4">
                    <div class="phase-icon"><i class="<?php echo $phase['icon']; ?>"></i></div>
                    <h3 class="fw-bold mb-2"><?php echo $phase['name']; ?></h3>
                    <span class="timeline-badge"><i class="bi bi-clock me-1"></i> <?php echo $phase['duration']; ?></span>
                </div>
                <div class="col-md-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-check-square me-2 text-warning"></i> Key Tasks</h6>
                    <ul class="task-list">
                        <?php foreach ($phase['tasks'] as $task): ?>
                        <li><i class="bi bi-check-circle-fill"></i> <?php echo $task; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-file-text me-2 text-warning"></i> Deliverables</h6>
                    <ul class="deliverable-list">
                        <?php foreach ($phase['deliverables'] as $deliverable): ?>
                        <li><i class="bi bi-file-check-fill"></i> <?php echo $deliverable; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Timeline Visual -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Typical <span class="yellow-text">Timeline</span></h2>
            <p>Based on project size and complexity</p>
        </div>

        <div class="row" data-aos="fade-up">
            <div class="col-12">
                <div class="position-relative">
                    <div class="progress mb-4" style="height: 10px; border-radius: 10px;">
                        <div class="progress-bar bg-warning" style="width: 15%">Week 1-2</div>
                        <div class="progress-bar bg-warning" style="width: 25%">Week 3-5</div>
                        <div class="progress-bar bg-warning" style="width: 25%">Week 6-8</div>
                        <div class="progress-bar bg-warning" style="width: 35%">Week 9+</div>
                    </div>
                    <div class="row text-center">
                        <div class="col-3">
                            <small class="fw-bold">Discovery & Planning</small>
                            <br><small class="text-muted">1-2 weeks</small>
                        </div>
                        <div class="col-3">
                            <small class="fw-bold">Setup & Configuration</small>
                            <br><small class="text-muted">2-3 weeks</small>
                        </div>
                        <div class="col-3">
                            <small class="fw-bold">Training & Testing</small>
                            <br><small class="text-muted">2-3 weeks</small>
                        </div>
                        <div class="col-3">
                            <small class="fw-bold">Go-Live & Support</small>
                            <br><small class="text-muted">Ongoing</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Training Modules -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Comprehensive <span class="yellow-text">Training</span> Programs</h2>
            <p>Empower your team with the knowledge they need</p>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
                <?php foreach ($trainingModules as $module): ?>
                <div class="training-card" data-aos="fade-up">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <h6 class="fw-bold mb-0"><?php echo $module['name']; ?></h6>
                            <small class="text-muted"><i class="bi bi-clock me-1"></i> <?php echo $module['duration']; ?></small>
                        </div>
                        <div class="col-md-3">
                            <span class="badge bg-warning text-dark"><?php echo $module['audience']; ?></span>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-0 small text-muted"><?php echo $module['topics']; ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- Success Metrics -->
<section class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Measurable <span class="yellow-text">Success</span> Metrics</h2>
            <p>What our clients achieve with TEK-C</p>
        </div>

        <div class="row g-4">
            <?php foreach ($successMetrics as $metric): ?>
            <div class="col-md-4 col-6" data-aos="zoom-in">
                <div class="metric-card">
                    <div class="metric-icon mx-auto"><i class="<?php echo $metric['icon']; ?>"></i></div>
                    <h3 class="fw-bold text-warning mb-0"><?php echo $metric['improvement']; ?></h3>
                    <p class="fw-bold mb-1"><?php echo $metric['metric']; ?></p>
                    <small class="text-muted"><?php echo $metric['description']; ?></small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Case Studies -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Success <span class="yellow-text">Stories</span></h2>
            <p>Real results from real clients</p>
        </div>

        <div class="row g-4">
            <?php foreach ($caseStudies as $study): ?>
            <div class="col-md-4" data-aos="fade-up">
                <div class="case-study-card">
                    <div class="quote-icon"><i class="bi bi-quote"></i></div>
                    <h5 class="fw-bold"><?php echo $study['company']; ?></h5>
                    <div class="mb-3">
                        <span class="badge bg-light text-dark me-2"><?php echo $study['size']; ?></span>
                        <span class="badge bg-light text-dark"><?php echo $study['sites']; ?> Sites</span>
                    </div>
                    <p class="small text-muted mb-2"><i class="bi bi-clock me-1"></i> Implementation: <?php echo $study['timeline']; ?></p>
                    <p class="small mb-2"><i class="bi bi-trophy me-1 text-warning"></i> <?php echo $study['result']; ?></p>
                    <hr>
                    <p class="small fst-italic mb-0">"<?php echo $study['testimonial']; ?>"</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Support During Implementation -->
<section class="bg-light">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6" data-aos="fade-right">
                <h2 class="fw-black">Dedicated <span class="yellow-text">Support</span> Throughout</h2>
                <p class="text-muted">You're never alone during the implementation journey</p>
                
                <div class="mt-4">
                    <div class="d-flex mb-4">
                        <div class="me-3"><i class="bi bi-person-badge fs-2 text-warning"></i></div>
                        <div><h6 class="fw-bold">Dedicated Project Manager</h6><p class="text-muted small">Single point of contact for your implementation</p></div>
                    </div>
                    <div class="d-flex mb-4">
                        <div class="me-3"><i class="bi bi-headset fs-2 text-warning"></i></div>
                        <div><h6 class="fw-bold">24/7 Technical Support</h6><p class="text-muted small">During and after implementation</p></div>
                    </div>
                    <div class="d-flex mb-4">
                        <div class="me-3"><i class="bi bi-journal-bookmark-fill fs-2 text-warning"></i></div>
                        <div><h6 class="fw-bold">Documentation & Guides</h6><p class="text-muted small">Comprehensive documentation for reference</p></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <div class="image-showcase" style="background: linear-gradient(135deg, #101820, #1a2a3a); border-radius: 24px; padding: 40px; text-align: center; min-height: 350px; display: flex; align-items: center; justify-content: center; flex-direction: column;">
                    <i class="bi bi-person-check display-1 text-warning mb-4"></i>
                    <h3 class="text-white">Ready to Start?</h3>
                    <p class="text-white-50">Get a personalized implementation plan</p>
                    <a href="contact.php" class="btn btn-yellow mt-3">
                        <i class="bi bi-calendar-check me-2"></i> Schedule Consultation
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Implementation <span class="yellow-text">FAQ</span></h2>
            <p>Common questions about our implementation process</p>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="faq-item mb-3" data-aos="fade-up">
                    <div class="faq-question p-3 bg-white rounded-3" style="cursor: pointer;">
                        <h6 class="fw-bold mb-0"><i class="bi bi-question-circle text-warning me-2"></i> How long does implementation typically take?</h6>
                    </div>
                    <div class="faq-answer p-3 d-none">
                        <p class="text-muted mb-0">Implementation typically takes 4-8 weeks depending on project size, complexity, and customization requirements. Small businesses may be up and running in as little as 4 weeks.</p>
                    </div>
                </div>
                
                <div class="faq-item mb-3" data-aos="fade-up">
                    <div class="faq-question p-3 bg-white rounded-3" style="cursor: pointer;">
                        <h6 class="fw-bold mb-0"><i class="bi bi-question-circle text-warning me-2"></i> What resources do we need to provide?</h6>
                    </div>
                    <div class="faq-answer p-3 d-none">
                        <p class="text-muted mb-0">We need a project sponsor, key user representatives, access to existing data for migration, and participation in training sessions. We'll work with your schedule.</p>
                    </div>
                </div>
                
                <div class="faq-item mb-3" data-aos="fade-up">
                    <div class="faq-question p-3 bg-white rounded-3" style="cursor: pointer;">
                        <h6 class="fw-bold mb-0"><i class="bi bi-question-circle text-warning me-2"></i> Do you provide training?</h6>
                    </div>
                    <div class="faq-answer p-3 d-none">
                        <p class="text-muted mb-0">Yes, we provide comprehensive training for all user roles including administrators, managers, and field staff. Training can be onsite or remote.</p>
                    </div>
                </div>
                
                <div class="faq-item mb-3" data-aos="fade-up">
                    <div class="faq-question p-3 bg-white rounded-3" style="cursor: pointer;">
                        <h6 class="fw-bold mb-0"><i class="bi bi-question-circle text-warning me-2"></i> Can we customize the system during implementation?</h6>
                    </div>
                    <div class="faq-answer p-3 d-none">
                        <p class="text-muted mb-0">Yes, the discovery phase identifies customization needs. Additional customizations can be discussed and implemented during the setup phase.</p>
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
                <h2 class="display-5 fw-black">Ready to Start Your <span class="yellow-text">Implementation</span>?</h2>
                <p class="mt-3 fs-5">Let's discuss your requirements and create a custom implementation plan</p>
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

    // FAQ toggle functionality
    const faqItems = document.querySelectorAll('.faq-item');
    faqItems.forEach(item => {
        const question = item.querySelector('.faq-question');
        const answer = item.querySelector('.faq-answer');
        
        question.addEventListener('click', () => {
            answer.classList.toggle('d-none');
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