<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEK-C Global | Construction ERP Software</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <!-- Google Font -->
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

        /* .search-box {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 12px;
            color: #fff;
            padding: 8px 10px;
            min-width: 260px;
        }

        .search-box::placeholder {
            color: #abb6c3;
        } */

        .btn-yellow {
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            color: #111;
            font-weight: 700;
            border: 0;
            border-radius: 7px;
            padding: 8px 14px;
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

        .hero {
            position: relative;
            min-height: 760px;
            background:
                linear-gradient(90deg, rgba(16,24,32,.96), rgba(16,24,32,.82), rgba(16,24,32,.45)),
                url("https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=1800&q=80");
            background-size: cover;
            background-position: center;
            padding-top: 125px;
            color: #fff;
            overflow: hidden;
        }

        .hero::before {
            content: "";
            position: absolute;
            width: 500px;
            height: 500px;
            right: -160px;
            top: 100px;
            background: radial-gradient(circle, rgba(255,195,41,.35), transparent 65%);
            animation: pulseGlow 5s infinite alternate;
        }

        @keyframes pulseGlow {
            from { transform: scale(.9); opacity: .5; }
            to { transform: scale(1.2); opacity: 1; }
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255,195,41,.35);
            background: rgba(255,195,41,.1);
            color: var(--yellow);
            padding: 8px 18px;
            border-radius: 30px;
            font-weight: 800;
            margin-bottom: 24px;
        }

        .hero h1 {
            font-size: clamp(42px, 6vw, 78px);
            font-weight: 900;
            line-height: 1.05;
            letter-spacing: -2px;
        }

        .hero h1 span,
        .yellow-text {
            color: var(--yellow);
        }

        .hero p {
            font-size: 19px;
            color: #d6dde6;
            max-width: 650px;
            line-height: 1.8;
        }

        .hero-screen {
            background: #fff;
            border-radius: 24px;
            padding: 14px;
            box-shadow: 0 35px 90px rgba(0,0,0,.45);
            transform: perspective(1000px) rotateY(-7deg) rotateX(3deg);
            animation: float 4s ease-in-out infinite;
        }

        @keyframes float {
            0%,100% { transform: perspective(1000px) rotateY(-7deg) rotateX(3deg) translateY(0); }
            50% { transform: perspective(1000px) rotateY(-4deg) rotateX(2deg) translateY(-18px); }
        }

        .app-window {
            background: #f7f8fb;
            border-radius: 18px;
            overflow: hidden;
            display: grid;
            grid-template-columns: 190px 1fr;
            min-height: 420px;
        }

        .app-sidebar {
            background: #17212b;
            color: #fff;
            padding: 18px;
        }

        .side-link {
            padding: 11px 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 8px;
            color: #cbd5e1;
        }

        .side-link.active {
            background: var(--yellow);
            color: #111;
            font-weight: 800;
        }

        .app-content {
            padding: 22px;
            color: #111;
        }

        .stat-card-mini {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 8px 25px rgba(0,0,0,.04);
        }

        .chart-line {
            height: 60px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(255,195,41,.25), rgba(255,195,41,.05));
            position: relative;
            overflow: hidden;
        }

        .chart-line::after {
            content: "";
            position: absolute;
            inset: 20px 10px;
            border-top: 4px solid var(--yellow);
            border-radius: 50%;
            transform: rotate(-8deg);
        }

        .hero-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 35px;
        }

        .hero-tags span {
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.06);
            padding: 10px 15px;
            border-radius: 12px;
            color: #dbe3ec;
            font-weight: 600;
            font-size: 14px;
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

        .overview-img {
            border-radius: 26px;
            min-height: 320px;
            background:
                linear-gradient(rgba(255,255,255,.15), rgba(255,255,255,.15)),
                url("https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1200&q=80");
            background-size: cover;
            background-position: center;
            box-shadow: 0 25px 60px rgba(0,0,0,.15);
            position: relative;
        }

        .overview-device {
            position: absolute;
            right: -20px;
            bottom: -30px;
            width: 280px;
            background: #fff;
            border-radius: 20px;
            padding: 15px;
            box-shadow: 0 20px 50px rgba(0,0,0,.18);
        }

        .metric-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            height: 100%;
            box-shadow: 0 12px 35px rgba(17,24,39,.05);
            transition: .35s;
        }

        .metric-card:hover,
        .module-card:hover,
        .contact-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 60px rgba(17,24,39,.12);
        }

        .metric-icon,
        .module-icon,
        .big-icon {
            width: 68px;
            height: 68px;
            border-radius: 20px;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 30px;
            box-shadow: 0 15px 35px rgba(255,195,41,.35);
        }

        .metric-card h3 {
            font-size: 42px;
            font-weight: 900;
            margin: 16px 0 0;
        }

        .module-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 28px;
            height: 100%;
            transition: .35s;
            box-shadow: 0 10px 32px rgba(17,24,39,.05);
        }

        .module-card h5 {
            font-weight: 900;
            margin-top: 20px;
            margin-bottom: 12px;
        }

        .module-card p {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
        }

        .product-row {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px 22px;
            margin-bottom: 14px;
            display: grid;
            grid-template-columns: 55px 1fr repeat(4, 110px);
            gap: 18px;
            align-items: center;
            box-shadow: 0 8px 25px rgba(0,0,0,.035);
            transition: .3s;
        }

        .product-row:hover {
            transform: translateX(8px);
            border-color: var(--yellow);
        }

        .product-icon {
            width: 48px;
            height: 48px;
            border-radius: 15px;
            background: rgba(255,195,41,.18);
            color: var(--yellow2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 23px;
        }

        .tag-small {
            background: #f4f6f9;
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            color: #4b5563;
        }

        .dark-process {
            background: linear-gradient(135deg, #101820, #151f28);
            color: #fff;
            overflow: hidden;
        }

        .process-card {
            background: #fff;
            color: #111;
            border-radius: 18px;
            padding: 26px;
            height: 100%;
            position: relative;
            box-shadow: 0 22px 45px rgba(0,0,0,.25);
        }

        .process-no {
            font-size: 36px;
            font-weight: 900;
            color: #fff;
            margin-bottom: 18px;
        }

        .arrow-line {
            position: absolute;
            right: -35px;
            top: 50%;
            color: var(--yellow);
            font-size: 40px;
            z-index: 2;
        }

        .business-card,
        .deploy-card,
        .faq-card,
        .step-card,
        .contact-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 30px;
            height: 100%;
            box-shadow: 0 10px 32px rgba(17,24,39,.05);
        }

        .deploy-card ul {
            list-style: none;
            padding: 0;
            margin: 20px 0 0;
        }

        .deploy-card li {
            margin-bottom: 13px;
            color: var(--muted);
        }

        .deploy-card li i {
            color: #22c55e;
            margin-right: 8px;
        }

        .benefit-card {
            background: #fff;
            border-left: 1px solid var(--border);
            padding: 25px;
            height: 100%;
        }

        .benefit-card h3 {
            font-size: 44px;
            font-weight: 900;
            color: #111;
        }

        .testimonial-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 12px 35px rgba(17,24,39,.05);
            height: 100%;
        }

        .stars {
            color: var(--yellow2);
            letter-spacing: 3px;
        }

        .avatar {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            object-fit: cover;
        }

        .faq-item {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .accordion-button {
            font-weight: 800;
            padding: 20px 24px;
        }

        .accordion-button:not(.collapsed) {
            background: rgba(255,195,41,.12);
            color: #111;
            box-shadow: none;
        }

        .cta-option {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 32px;
            height: 100%;
            text-align: center;
            transition: .35s;
            box-shadow: 0 10px 35px rgba(17,24,39,.05);
        }

        .cta-option:hover {
            transform: translateY(-10px);
            border-color: var(--yellow);
        }

        .contact-card {
            text-align: center;
            transition: .35s;
        }

        .contact-card i {
            font-size: 42px;
            color: #374151;
            margin-bottom: 18px;
        }

        .final-cta {
            background:
                linear-gradient(90deg, rgba(16,24,32,.96), rgba(16,24,32,.72)),
                url("https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1600&q=80");
            background-size: cover;
            background-position: center;
            color: #fff;
            padding: 70px 0;
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

        .floating-shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,195,41,.18);
            filter: blur(3px);
            animation: shapeMove 8s infinite linear alternate;
        }

        .shape1 {
            width: 90px;
            height: 90px;
            left: 8%;
            top: 18%;
        }

        .shape2 {
            width: 60px;
            height: 60px;
            right: 15%;
            bottom: 16%;
            animation-delay: 2s;
        }

        @keyframes shapeMove {
            from { transform: translateY(0) rotate(0); }
            to { transform: translateY(-40px) rotate(25deg); }
        }

        @media (max-width: 991px) {
            .search-box {
                min-width: 100%;
                margin: 12px 0;
            }

            .hero {
                padding-top: 115px;
                min-height: auto;
                padding-bottom: 70px;
            }

            .hero-screen {
                margin-top: 45px;
                transform: none;
                animation: none;
            }

            .app-window {
                grid-template-columns: 1fr;
            }

            .app-sidebar {
                display: none;
            }

            .product-row {
                grid-template-columns: 55px 1fr;
            }

            .product-row .tag-small {
                display: inline-block;
            }

            .arrow-line {
                display: none;
            }

            .overview-device {
                position: relative;
                right: auto;
                bottom: auto;
                margin: 20px auto 0;
                width: 90%;
            }
        }
    </style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<!-- HERO -->
<section class="hero" id="home">
    <div class="floating-shape shape1"></div>
    <div class="floating-shape shape2"></div>

    <div class="container position-relative">
        <div class="row align-items-center">
            <div class="col-lg-6" data-aos="fade-right">
                

                <h1>
                    Complete Project <br>
                    <span>Lifecycle Management</span>
                </h1>

                <p class="mt-4">
                    From Daily Progress Reports to Quotation Comparison, AIT, MOM, RFI and document approvals —
                    TEK-C brings every construction workflow into one smart ERP system.
                </p>

                <div class="d-flex flex-wrap gap-3 mt-4">
                    <a href="#modules" class="btn btn-yellow">
                        Explore Modules <i class="bi bi-arrow-right ms-2"></i>
                    </a>
                    <a href="#contact" class="btn btn-light-custom">
                        <i class="bi bi-calendar-check me-2"></i> Book a Live Demo
                    </a>
                </div>

                <a href="#" class="d-inline-block mt-4 text-warning fw-bold text-decoration-none">
                    <i class="bi bi-download me-2"></i> Download Brochure
                </a>

                <div class="hero-tags">
                    <span><i class="bi bi-check-circle text-warning me-1"></i> Ready to Deploy</span>
                    <span><i class="bi bi-cloud-check text-warning me-1"></i> Multi-Branch Architecture</span>
                    <span><i class="bi bi-person-lock text-warning me-1"></i> Role Based Access</span>
                    <span><i class="bi bi-headset text-warning me-1"></i> Active Support</span>
                </div>
            </div>

            <div class="col-lg-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="hero-screen">
                    <div class="app-window">
                        <div class="app-sidebar">
                            <div class="d-flex align-items-center gap-2 mb-4">
                                <div class="logo-box" style="width:35px;height:35px;border-radius:9px;">
                                    <i class="bi bi-send-fill"></i>
                                </div>
                                <strong>TEK-C</strong>
                            </div>

                            <div class="side-link active"><i class="bi bi-speedometer2 me-2"></i> Dashboard</div>
                            <div class="side-link"><i class="bi bi-building me-2"></i> Sites & Projects</div>
                            <div class="side-link"><i class="bi bi-journal-text me-2"></i> Daily Reports</div>
                            <div class="side-link"><i class="bi bi-folder2-open me-2"></i> Documents</div>
                            <div class="side-link"><i class="bi bi-shield-check me-2"></i> Quotations</div>
                            <div class="side-link"><i class="bi bi-people me-2"></i> HRMS</div>
                            <div class="side-link"><i class="bi bi-graph-up me-2"></i> Reports</div>
                        </div>

                        <div class="app-content">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="fw-black mb-0">Dashboard</h5>
                                <small class="text-muted">May 2026 - FY 2026</small>
                            </div>

                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="stat-card-mini">
                                        <small>Site Progress</small>
                                        <h3 class="fw-bold">68%</h3>
                                        <div class="chart-line"></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-card-mini">
                                        <small>Active Contracts</small>
                                        <h3 class="fw-bold">24</h3>
                                        <div class="chart-line"></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-card-mini">
                                        <small>Pending Approvals</small>
                                        <h3 class="fw-bold">12</h3>
                                        <div class="chart-line"></div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-card-mini">
                                        <small>Documents</small>
                                        <h3 class="fw-bold">1,245</h3>
                                        <div class="chart-line"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <h6 class="fw-bold">Project Progress</h6>
                                <div class="mb-2">KFC - Client: Ariharasudhan P <span class="float-end">42%</span></div>
                                <div class="progress mb-3" style="height:8px;"><div class="progress-bar bg-warning" style="width:42%"></div></div>

                                <div class="mb-2">Ecommer - Client: Ariharasudhan P <span class="float-end">78%</span></div>
                                <div class="progress mb-3" style="height:8px;"><div class="progress-bar bg-warning" style="width:78%"></div></div>

                                <div class="mb-2">Demo Project - Client: UKB Construction <span class="float-end">55%</span></div>
                                <div class="progress" style="height:8px;"><div class="progress-bar bg-warning" style="width:55%"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-warning fw-bold mt-4">
            India’s Construction ERP by UKR Group
        </div>
    </div>
</section>

<!-- ABOUT SECTION - Data from sites table -->
<section id="about">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-5" data-aos="fade-right">
                <div class="overview-img">
                    <div class="overview-device">
                        <div class="stat-card-mini">
                            <h6 class="fw-bold">Live Project View</h6>
                            <div class="chart-line mb-3"></div>
                            <div class="d-flex justify-content-between">
                                <span>Active Projects</span>
                                <strong>8 Sites</strong>
                            </div>
                            <div class="progress mt-2" style="height:8px;">
                                <div class="progress-bar bg-warning" style="width:82%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7" data-aos="fade-left">
                <h2 class="fw-black display-5">
                    Complete Construction ERP Software
                </h2>
                <h5 class="yellow-text fw-bold mb-3">Sell Confidence. Deliver Control.</h5>

                <p class="text-muted fs-5">
                    TEK-C is a production-ready construction ERP that manages sites, contracts,
                    daily reports, HR, quotations, approvals and documents — all in one connected platform.
                    White-label ready and built for scalability across projects and locations.
                </p>

                <div class="row g-4 mt-4">
                    <div class="col-md-3 col-6">
                        <div class="metric-card text-center">
                            <div class="metric-icon mx-auto"><i class="bi bi-buildings"></i></div>
                            <h3>8</h3>
                            <p class="mb-0 text-muted">Active Sites</p>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="metric-card text-center">
                            <div class="metric-icon mx-auto"><i class="bi bi-person-badge"></i></div>
                            <h3>18</h3>
                            <p class="mb-0 text-muted">Employees</p>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="metric-card text-center">
                            <div class="metric-icon mx-auto"><i class="bi bi-file-text"></i></div>
                            <h3>34</h3>
                            <p class="mb-0 text-muted">DPR Reports</p>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="metric-card text-center">
                            <div class="metric-icon mx-auto"><i class="bi bi-quote"></i></div>
                            <h3>7</h3>
                            <p class="mb-0 text-muted">Quotations</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- MODULES SECTION - based on DB modules and reports -->
<section id="modules" class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>What <span class="yellow-text">TEK-C</span> Offers to Construction Groups</h2>
            <p>15+ integrated modules that mirror real construction workflows.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-2 col-md-4 col-6" data-aos="zoom-in">
                <div class="module-card text-center">
                    <div class="module-icon mx-auto"><i class="bi bi-building"></i></div>
                    <h5>Sites & Contracts</h5>
                    <p>Manage 8 active sites, contract values, and site-wise dashboards.</p>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 col-6" data-aos="zoom-in" data-aos-delay="100">
                <div class="module-card text-center">
                    <div class="module-icon mx-auto"><i class="bi bi-clipboard-data"></i></div>
                    <h5>Daily Reports</h5>
                    <p>DPR, DAR, MOM, MA, RFI, AIT, DLAR, MPT fully integrated.</p>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 col-6" data-aos="zoom-in" data-aos-delay="200">
                <div class="module-card text-center">
                    <div class="module-icon mx-auto"><i class="bi bi-file-pdf"></i></div>
                    <h5>Document Control</h5>
                    <p>Contract documents, drawings, site photos, version control.</p>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 col-6" data-aos="zoom-in" data-aos-delay="300">
                <div class="module-card text-center">
                    <div class="module-icon mx-auto"><i class="bi bi-calculator"></i></div>
                    <h5>Quotation & Tendering</h5>
                    <p>RFQ, quotations from dealers, comparison and QS approval.</p>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 col-6" data-aos="zoom-in" data-aos-delay="400">
                <div class="module-card text-center">
                    <div class="module-icon mx-auto"><i class="bi bi-people"></i></div>
                    <h5>HRMS</h5>
                    <p>Attendance, leave requests, hiring, onboarding and payroll.</p>
                </div>
            </div>

            <div class="col-lg-2 col-md-4 col-6" data-aos="zoom-in" data-aos-delay="500">
                <div class="module-card text-center">
                    <div class="module-icon mx-auto"><i class="bi bi-shield-lock"></i></div>
                    <h5>Role Access</h5>
                    <p>Admin, Director, HR, Manager, QS, Project Engineer roles.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- PRODUCT LIST - Features based on actual DB tables -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Why <span class="yellow-text">TEK-C</span> as a Product</h2>
        </div>

        <div data-aos="fade-up">
            <div class="product-row">
                <div class="product-icon"><i class="bi bi-journal-text"></i></div>
                <div>
                    <h6 class="fw-bold mb-1">Daily Progress Report (DPR)</h6>
                    <p class="mb-0 text-muted">12 DPR reports captured with manpower, machinery, material and constraints.</p>
                </div>
                <span class="tag-small">Labour</span>
                <span class="tag-small">Progress %</span>
                <span class="tag-small">Photos</span>
                <span class="tag-small">Reports</span>
            </div>

            <div class="product-row">
                <div class="product-icon"><i class="bi bi-calculator-fill"></i></div>
                <div>
                    <h6 class="fw-bold mb-1">Quotation Workflow</h6>
                    <p class="mb-0 text-muted">7 RFQs created, quotations from dealers, QS negotiation and final approval.</p>
                </div>
                <span class="tag-small">Comparison</span>
                <span class="tag-small">Negotiation</span>
                <span class="tag-small">Vendors</span>
                <span class="tag-small">Analytics</span>
            </div>

            <div class="product-row">
                <div class="product-icon"><i class="bi bi-pencil-square"></i></div>
                <div>
                    <h6 class="fw-bold mb-1">RFI & AIT Tracker</h6>
                    <p class="mb-0 text-muted">3 RFIs raised, 1 AIT with multiple action items tracked to completion.</p>
                </div>
                <span class="tag-small">RFI</span>
                <span class="tag-small">AIT</span>
                <span class="tag-small">Due Date</span>
                <span class="tag-small">Status</span>
            </div>

            <div class="product-row">
                <div class="product-icon"><i class="bi bi-chat-text"></i></div>
                <div>
                    <h6 class="fw-bold mb-1">MOM & MAS Reports</h6>
                    <p class="mb-0 text-muted">8 MOM reports, 13 MA reports with minutes and action points recorded.</p>
                </div>
                <span class="tag-small">Minutes</span>
                <span class="tag-small">Action Points</span>
                <span class="tag-small">MAS</span>
                <span class="tag-small">Follow-up</span>
            </div>

            <div class="product-row">
                <div class="product-icon"><i class="bi bi-geo-alt-fill"></i></div>
                <div>
                    <h6 class="fw-bold mb-1">HR & Attendance with Geolocation</h6>
                    <p class="mb-0 text-muted">23 attendance records, 2 office locations, leave management and 4 leave requests.</p>
                </div>
                <span class="tag-small">Attendance</span>
                <span class="tag-small">Geolocation</span>
                <span class="tag-small">Leave</span>
                <span class="tag-small">Onboarding</span>
            </div>
        </div>
    </div>
</section>

<!-- PROCESS SECTION -->
<section class="dark-process">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2 class="text-white">How <span class="yellow-text">TEK-C</span> Works</h2>
        </div>

        <div class="row g-4">
            <div class="col-lg-3" data-aos="fade-up">
                <div class="process-no">01</div>
                <div class="process-card">
                    <div class="module-icon mb-3"><i class="bi bi-cloud-upload"></i></div>
                    <h5 class="fw-bold">Capture Site Data</h5>
                    <p class="text-muted mb-0">DPR, labour, machinery, material and photo updates from site engineers.</p>
                    <div class="arrow-line"><i class="bi bi-arrow-right"></i></div>
                </div>
            </div>

            <div class="col-lg-3" data-aos="fade-up" data-aos-delay="100">
                <div class="process-no">02</div>
                <div class="process-card">
                    <div class="module-icon mb-3"><i class="bi bi-check2-square"></i></div>
                    <h5 class="fw-bold">Review & Approvals</h5>
                    <p class="text-muted mb-0">Quotations, AIT, MOM, attendance regularization and workflow approvals.</p>
                    <div class="arrow-line"><i class="bi bi-arrow-right"></i></div>
                </div>
            </div>

            <div class="col-lg-3" data-aos="fade-up" data-aos-delay="200">
                <div class="process-no">03</div>
                <div class="process-card">
                    <div class="module-icon mb-3"><i class="bi bi-database-check"></i></div>
                    <h5 class="fw-bold">Centralise Operations</h5>
                    <p class="text-muted mb-0">Documents, contracts, HR, attendance and all site data in one place.</p>
                    <div class="arrow-line"><i class="bi bi-arrow-right"></i></div>
                </div>
            </div>

            <div class="col-lg-3" data-aos="fade-up" data-aos-delay="300">
                <div class="process-no">04</div>
                <div class="process-card">
                    <div class="module-icon mb-3"><i class="bi bi-bar-chart-line"></i></div>
                    <h5 class="fw-bold">Track & Decide</h5>
                    <p class="text-muted mb-0">Dashboards, activity logs, reports and management insights.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- BUSINESS TYPES -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Built for Every Construction Business</h2>
            <p>Built fits for business sizes</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="business-card">
                    <div class="big-icon mb-4"><i class="bi bi-building"></i></div>
                    <h5 class="fw-bold">Small Builder</h5>
                    <p class="text-muted">Perfect for growing builders looking for simple, powerful control.</p>
                    <span class="tag-small">Fast Deployment</span>
                    <span class="tag-small">Simple Workflow</span>
                </div>
            </div>

            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="business-card">
                    <div class="big-icon mb-4"><i class="bi bi-cone-striped"></i></div>
                    <h5 class="fw-bold">Mid Size Contractor</h5>
                    <p class="text-muted">Operational hub for contractors managing multiple sites and teams.</p>
                    <span class="tag-small">Scalable Workflow</span>
                    <span class="tag-small">Multi-site Visibility</span>
                </div>
            </div>

            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="business-card">
                    <div class="big-icon mb-4"><i class="bi bi-buildings-fill"></i></div>
                    <h5 class="fw-bold">Large Developer</h5>
                    <p class="text-muted">Enterprise-ready ERP for large portfolios and complex projects.</p>
                    <span class="tag-small">Enterprise Control</span>
                    <span class="tag-small">Role Permission</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- DEPLOYMENT + BENEFITS -->
<section class="bg-light" id="pricing">
    <div class="container">
        <div class="row g-5 align-items-stretch">
            <div class="col-lg-5" data-aos="fade-right">
                <h2 class="fw-black mb-4">Deployment Options</h2>

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="deploy-card">
                            <div class="module-icon mb-3"><i class="bi bi-cloud-check"></i></div>
                            <h5 class="fw-bold">Cloud SaaS</h5>
                            <ul>
                                <li><i class="bi bi-check-circle"></i> Quick start, no infrastructure</li>
                                <li><i class="bi bi-check-circle"></i> Secure cloud access</li>
                                <li><i class="bi bi-check-circle"></i> Automatic updates</li>
                                <li><i class="bi bi-check-circle"></i> Pay-as-you-go flexibility</li>
                            </ul>
                            <p class="text-primary fw-bold mt-3">Recommended for most teams</p>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="deploy-card">
                            <div class="module-icon mb-3"><i class="bi bi-server"></i></div>
                            <h5 class="fw-bold">On-Premise / White-label</h5>
                            <ul>
                                <li><i class="bi bi-check-circle"></i> Custom branding & domain</li>
                                <li><i class="bi bi-check-circle"></i> Host in your environment</li>
                                <li><i class="bi bi-check-circle"></i> Full data control</li>
                                <li><i class="bi bi-check-circle"></i> Enterprise-grade scalability</li>
                            </ul>
                            <p class="text-primary fw-bold mt-3">Best for enterprises</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7" data-aos="fade-left">
                <h2 class="fw-black mb-4 text-center">Why Teams Choose TEK-C</h2>

                <div class="row g-0 bg-white rounded-4 shadow-sm overflow-hidden">
                    <div class="col-md-3 col-6">
                        <div class="benefit-card text-center">
                            <h3>34</h3>
                            <h6 class="fw-bold">Reports Created</h6>
                            <p class="text-muted small mb-0">DPR, DAR, MOM, MPT, DLAR.</p>
                        </div>
                    </div>

                    <div class="col-md-3 col-6">
                        <div class="benefit-card text-center">
                            <h3>100%</h3>
                            <h6 class="fw-bold">Approval Control</h6>
                            <p class="text-muted small mb-0">Track approvals with audit trails.</p>
                        </div>
                    </div>

                    <div class="col-md-3 col-6">
                        <div class="benefit-card text-center">
                            <h3>4</h3>
                            <h6 class="fw-bold">Active Hiring</h6>
                            <p class="text-muted small mb-0">Open positions filled.</p>
                        </div>
                    </div>

                    <div class="col-md-3 col-6">
                        <div class="benefit-card text-center">
                            <h3>360°</h3>
                            <h6 class="fw-bold">Improved Visibility</h6>
                            <p class="text-muted small mb-0">Real-time insight across sites.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- TESTIMONIAL -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <p class="fw-bold mb-1">Trusted by Construction Leaders</p>
            <h2>What Clients Say About <span class="yellow-text">TEK-C</span></h2>
        </div>

        <div class="row g-4">
            <div class="col-md-6" data-aos="fade-right">
                <div class="testimonial-card">
                    <div class="stars mb-3">★★★★★</div>
                    <p>
                        “TEK-C has transformed the way we manage our projects. Daily-based access and
                        structured document control have improved approvals and reduced delays across sites.”
                    </p>
                    <div class="d-flex align-items-center gap-3 mt-4">
                        <img class="avatar" src="https://randomuser.me/api/portraits/men/32.jpg" alt="">
                        <div>
                            <h6 class="fw-bold mb-0">Ariharasudhan P</h6>
                            <small class="text-warning fw-bold">Director - UKB Construction</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6" data-aos="fade-left">
                <div class="testimonial-card">
                    <div class="stars mb-3">★★★★★</div>
                    <p>
                        “From DPR to AIT and MOM, everything is now structured and easy to monitor.
                        TEK-C gives us the control and insights we need every day.”
                    </p>
                    <div class="d-flex align-items-center gap-3 mt-4">
                        <img class="avatar" src="https://randomuser.me/api/portraits/men/45.jpg" alt="">
                        <div>
                            <h6 class="fw-bold mb-0">U K Balachandar</h6>
                            <small class="text-warning fw-bold">CEO - UKB Group</small>
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
            <h2>FAQ</h2>
        </div>

        <div class="accordion" id="faqAccordion" data-aos="fade-up">
            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button" data-bs-toggle="collapse" data-bs-target="#faq1">
                        Is TEK-C ready to deploy?
                    </button>
                </h2>
                <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        Yes, TEK-C is a production-ready ERP with prebuilt modules and dashboards. Currently managing 8 active sites with real data.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq2">
                        Can it be customised for our workflows?
                    </button>
                </h2>
                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        Yes, TEK-C supports custom workflows, approvals, fields, reports and role-based permissions.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq3">
                        Does it support multiple projects and roles?
                    </button>
                </h2>
                <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        Yes, currently supporting 8 sites, 5 departments (PM, QS, HR, CM, IFM) and 7 employee roles.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq4">
                        Can we use TEK-C as a white-label ERP?
                    </button>
                </h2>
                <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        Yes, TEK-C can be white-labelled with your brand, domain and logo.
                    </div>
                </div>
            </div>

            <div class="faq-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq5">
                        Do you provide onboarding and support?
                    </button>
                </h2>
                <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body">
                        Yes, onboarding, training, documentation and support can be provided.
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- NEXT STEP -->
<section>
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Choose Your Next Step</h2>
            <p>Select the action that works best for you and your team.</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4" data-aos="zoom-in">
                <div class="cta-option">
                    <i class="bi bi-play-btn display-3"></i>
                    <h5 class="fw-bold mt-3">Live Demo</h5>
                    <p class="text-muted">See TEK-C in action with a personalised walkthrough.</p>
                    <a href="#" class="btn btn-yellow">Book a Live Demo</a>
                </div>
            </div>

            <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
                <div class="cta-option">
                    <i class="bi bi-file-earmark-text display-3"></i>
                    <h5 class="fw-bold mt-3">Pricing & Brochure</h5>
                    <p class="text-muted">Download detailed pricing and product brochure.</p>
                    <a href="#" class="btn btn-yellow">Download Now</a>
                </div>
            </div>

            <div class="col-md-4" data-aos="zoom-in" data-aos-delay="200">
                <div class="cta-option">
                    <i class="bi bi-headset display-3"></i>
                    <h5 class="fw-bold mt-3">Talk to Sales</h5>
                    <p class="text-muted">Speak with experts and find the right solution.</p>
                    <a href="#" class="btn btn-yellow">Talk to Sales Team</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CONTACT -->
<section id="contact" class="bg-light">
    <div class="container">
        <div class="section-title" data-aos="fade-up">
            <h2>Connect With TEK-C</h2>
            <p>We're here to help you build better.</p>
        </div>

        <div class="row g-4">
            <div class="col-lg col-md-4 col-6" data-aos="fade-up">
                <div class="contact-card">
                    <i class="bi bi-envelope"></i>
                    <h6 class="fw-bold">Email</h6>
                    <p class="text-muted mb-0">info@tekcglobal.com</p>
                </div>
            </div>

            <div class="col-lg col-md-4 col-6" data-aos="fade-up" data-aos-delay="100">
                <div class="contact-card">
                    <i class="bi bi-telephone"></i>
                    <h6 class="fw-bold">Phone</h6>
                    <p class="text-muted mb-0">+91 72003 16099</p>
                </div>
            </div>

            <div class="col-lg col-md-4 col-6" data-aos="fade-up" data-aos-delay="200">
                <div class="contact-card">
                    <i class="bi bi-linkedin"></i>
                    <h6 class="fw-bold">LinkedIn</h6>
                    <p class="text-muted mb-0">/company/tek-c-global</p>
                </div>
            </div>

            <div class="col-lg col-md-4 col-6" data-aos="fade-up" data-aos-delay="300">
                <div class="contact-card">
                    <i class="bi bi-youtube"></i>
                    <h6 class="fw-bold">YouTube</h6>
                    <p class="text-muted mb-0">/tekcglobal</p>
                </div>
            </div>

            <div class="col-lg col-md-4 col-6" data-aos="fade-up" data-aos-delay="400">
                <div class="contact-card">
                    <i class="bi bi-handshake"></i>
                    <h6 class="fw-bold">Partner With Us</h6>
                    <p class="text-muted mb-0">partner@tekcglobal.com</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FINAL CTA -->
<section class="final-cta">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-7" data-aos="fade-right">
                <p class="fw-bold mb-2">Start Your ERP Journey</p>
                <h2 class="display-5 fw-black">
                    License <span class="yellow-text">TEK-C</span> for Your <br>
                    Construction Group Today
                </h2>

                <div class="d-flex flex-wrap gap-3 mt-4">
                    <a href="#" class="btn btn-yellow">
                        <i class="bi bi-calendar2-check me-2"></i> Book a Live Demo
                    </a>
                    <a href="#" class="btn btn-light-custom">
                        <i class="bi bi-telephone me-2"></i> Talk to Sales Team
                    </a>
                </div>
            </div>

            <div class="col-lg-5 d-none d-lg-block" data-aos="zoom-in">
                <div class="hero-screen">
                    <div class="stat-card-mini">
                        <h5 class="fw-bold">ERP Analytics</h5>
                        <div class="chart-line mb-3"></div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="stat-card-mini">
                                    <small>Projects</small>
                                    <h3 class="fw-bold">8</h3>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="stat-card-mini">
                                    <small>Reports</small>
                                    <h3 class="fw-bold">34</h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

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

    // Search highlight simple behaviour
    document.querySelector(".search-box").addEventListener("keyup", function () {
        const value = this.value.toLowerCase();
        const cards = document.querySelectorAll(".module-card, .product-row");

        cards.forEach(card => {
            const text = card.innerText.toLowerCase();
            card.style.opacity = value === "" || text.includes(value) ? "1" : ".25";
            card.style.transform = value !== "" && text.includes(value) ? "scale(1.03)" : "";
        });
    });
</script>

</body>
</html>