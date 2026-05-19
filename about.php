<?php
// about.php - TEK-C About Page
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - TEK-C Construction Management Software</title>

    <?php include 'includes/link.php'; ?>

    <style>
        :root {
            --yellow: #f6ad22;
            --yellow2: #ffc247;
            --dark: #080b0d;
            --black: #050607;
            --text: #111;
            --muted: #666;
            --line: #e8e8e8;
            --green: #3d9b45;
            --red: #df5353;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 105px;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background: #fff;
            overflow-x: hidden;
            padding-top: 88px;
        }

        a {
            text-decoration: none;
        }

        .text-yellow {
            color: var(--yellow);
        }

        .btn-yellow {
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            color: #111;
            font-weight: 800;
            border: none;
            border-radius: 8px;
            padding: 14px 28px;
            box-shadow: 0 10px 25px rgba(246, 173, 34, .35);
            transition: .35s;
        }

        .btn-yellow:hover {
            transform: translateY(-3px);
            color: #111;
        }

        .btn-outline-light-custom {
            border: 1px solid rgba(255, 255, 255, .55);
            color: #fff;
            border-radius: 8px;
            padding: 13px 28px;
            font-weight: 800;
            background: rgba(255, 255, 255, .04);
        }

        .btn-outline-light-custom:hover {
            background: #fff;
            color: #111;
        }

        /* NAVBAR */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 999;
            padding: 14px 0;
            background: rgba(5, 7, 9, .96);
            backdrop-filter: blur(16px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, .28);
            transition: .35s ease;
        }

        .navbar.nav-fixed {
            padding: 10px 0;
            background: rgba(5, 7, 9, .98);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #ffbe35, #e79510);
            clip-path: polygon(50% 0, 100% 35%, 85% 35%, 50% 15%, 15% 35%, 0 35%);
        }

        .logo-text h3 {
            margin: 0;
            color: var(--yellow);
            font-size: 32px;
            font-weight: 900;
            letter-spacing: .5px;
        }

        .logo-text span {
            display: block;
            color: #fff;
            font-size: 10px;
            margin-top: -6px;
            letter-spacing: .8px;
        }

        .navbar-nav {
            background: rgba(255, 255, 255, .07);
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 50px;
            padding: 7px;
            backdrop-filter: blur(12px);
        }

        .navbar-nav .nav-link {
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            margin: 0 2px;
            padding: 10px 16px !important;
            border-radius: 50px;
            transition: .3s;
        }

        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active {
            color: #111;
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            box-shadow: 0 7px 18px rgba(246, 173, 34, .25);
        }

        /* HERO */
        .about-hero {
            min-height: 540px;
            background:
                linear-gradient(90deg, rgba(5, 7, 9, .98) 0%, rgba(5, 7, 9, .83) 45%, rgba(5, 7, 9, .18) 100%),
                url('https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=1800&q=85') center/cover no-repeat;
            color: #fff;
            display: flex;
            align-items: center;
            position: relative;
        }

        .breadcrumb-custom {
            font-size: 13px;
            color: #ddd;
            margin-bottom: 26px;
        }

        .breadcrumb-custom i {
            font-size: 10px;
            margin: 0 8px;
            color: #aaa;
        }

        .hero-label {
            color: var(--yellow);
            font-size: 13px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .about-hero h1 {
            font-size: 54px;
            font-weight: 900;
            line-height: 1.08;
            max-width: 720px;
            margin-bottom: 22px;
        }

        .about-hero p {
            max-width: 540px;
            color: #e7e7e7;
            font-size: 17px;
            line-height: 1.7;
        }

        .hero-actions {
            display: flex;
            gap: 18px;
            margin-top: 28px;
            flex-wrap: wrap;
        }

        /* STORY */
        .story-wrap {
            padding: 45px 0 25px;
        }

        .story-img {
            height: 330px;
            width: 100%;
            object-fit: cover;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, .13);
        }

        .small-label {
            color: #d99a1e;
            font-size: 12px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .story-content h2 {
            font-size: 31px;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 20px;
        }

        .story-content p {
            color: #555;
            font-size: 15px;
            line-height: 1.75;
            margin-bottom: 12px;
        }

        .stats-box {

            padding-left: 45px;
        }

        .stat-item {
            display: flex;
            gap: 18px;
            align-items: center;
            margin-bottom: 32px;
        }

        .stat-icon {
            width: 48px;
            color: var(--yellow);
            font-size: 32px;
            text-align: center;
        }

        .stat-item h3 {
            margin: 0;
            font-size: 34px;
            font-weight: 900;
        }

        .stat-item p {
            margin: 0;
            font-size: 13px;
            color: #555;
        }

        /* PROBLEM SOLUTION */
        .problem-solution {
            padding: 10px 0 35px;
        }

        .problem-card,
        .solution-card {
            border-radius: 10px;
            padding: 35px;
            min-height: 305px;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .problem-card {
            background: #fff2f2;
        }

        .solution-card {
            background: #f1faef;
        }

        .problem-card h3,
        .solution-card h3 {
            font-size: 27px;
            font-weight: 900;
            line-height: 1.15;
            margin-bottom: 20px;
        }

        .list-clean {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .list-clean li {
            margin-bottom: 10px;
            font-size: 14px;
            color: #333;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .problem-card li i {
            color: var(--red);
        }

        .solution-card li i {
            color: var(--green);
        }

        .alert-note {
            background: rgba(255, 255, 255, .6);
            border: 1px solid rgba(223, 83, 83, .25);
            border-radius: 8px;
            padding: 17px;
            margin-top: 22px;
            display: flex;
            align-items: center;
            gap: 14px;
            font-weight: 800;
            color: #df5353;
            font-size: 14px;
        }

        .solution-note {
            background: rgba(255, 255, 255, .65);
            border: 1px solid rgba(61, 155, 69, .22);
            border-radius: 8px;
            padding: 17px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            font-weight: 800;
            color: #3d7e3e;
            max-width: 430px;
        }

        .solution-device {
            position: absolute;
            right: 18px;
            bottom: 18px;
            width: 255px;
            max-width: 42%;
        }

        /* DIFFERENT */
        .different {
            padding: 20px 0 45px;
            text-align: center;
        }

        .section-title {
            font-size: 31px;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 10px;
        }

        .section-subtitle {
            color: #666;
            max-width: 760px;
            margin: 0 auto 35px;
            font-size: 14px;
            line-height: 1.7;
        }

        .diff-card {
            padding: 22px 20px;
            border-right: 1px solid #e5e5e5;
            height: 100%;
        }

        .diff-card.last {
            border-right: none;
        }

        .diff-card i {
            font-size: 42px;
            color: var(--yellow);
            margin-bottom: 18px;
        }

        .diff-card h4 {
            font-size: 16px;
            font-weight: 900;
            margin-bottom: 9px;
        }

        .diff-card p {
            color: #555;
            font-size: 13px;
            line-height: 1.5;
            margin: 0;
        }

        /* VISION CARDS */
        .vision-section {
            padding: 25px 0 45px;
        }

        .vision-card {
            min-height: 285px;
            border-radius: 10px;
            padding: 30px;
            overflow: hidden;
            position: relative;
            height: 100%;
        }

        .vision-card h3 {
            font-size: 23px;
            font-weight: 900;
            line-height: 1.18;
            margin-bottom: 15px;
            position: relative;
            z-index: 2;
        }

        .vision-card p {
            font-size: 14px;
            color: #333;
            line-height: 1.65;
            max-width: 70%;
            position: relative;
            z-index: 2;
        }

        .vision-card img {
            position: absolute;
            right: 0;
            bottom: 0;
            width: 65%;
            height: 75%;
            object-fit: cover;
            opacity: .55;
        }

        .vision-blue {
            background: #eef6ff;
        }

        .vision-orange {
            background: #fff4ea;
        }

        .vision-purple {
            background: #f6efff;
        }

        /* LOWER INFO */
        .lower-info {
            padding: 35px 0 45px;
            border-top: 1px solid #eee;
        }

        .eco-card,
        .practice-card,
        .values-card {
            height: 100%;
        }

        .eco-icons {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-top: 24px;
        }

        .eco-item {
            text-align: center;
            font-size: 12px;
            color: #444;
            font-weight: 700;
        }

        .eco-item i {
            display: block;
            font-size: 32px;
            color: var(--yellow);
            margin-bottom: 8px;
        }

        .practice-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }

        .practice-grid img {
            width: 100%;
            height: 95px;
            object-fit: cover;
            border-radius: 8px;
        }

        .values-card ul {
            list-style: none;
            padding: 0;
            margin: 15px 0 0;
        }

        .values-card li {
            font-size: 14px;
            margin-bottom: 13px;
            color: #444;
        }

        .values-card li i {
            color: var(--yellow);
            margin-right: 8px;
        }

        /* CTA */
        .final-cta {
            background:
                linear-gradient(90deg, rgba(5, 7, 9, .98), rgba(5, 7, 9, .8)),
                url('https://images.unsplash.com/photo-1590650046871-92c887180603?auto=format&fit=crop&w=1600&q=85') center/cover no-repeat;
            color: #fff;
            padding: 45px 0;
            border-radius: 0;
        }

        .final-cta h2 {
            font-size: 34px;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 10px;
        }

        .final-cta p {
            color: #ddd;
            margin: 0;
        }

        /* FOOTER */
        .footer {
            background: #07090b;
            color: #fff;
            padding: 65px 0 20px;
        }

        .footer h5 {
            font-size: 14px;
            font-weight: 900;
            margin-bottom: 18px;
        }

        .footer a {
            display: block;
            color: #d6d6d6;
            font-size: 13px;
            margin: 10px 0;
        }

        .footer a:hover {
            color: var(--yellow);
        }

        .social a {
            display: inline-flex;
            width: 34px;
            height: 34px;
            align-items: center;
            justify-content: center;
            background: #1b2025;
            border-radius: 50%;
            margin-right: 8px;
        }

        .footer-bottom {
            border-top: 1px solid #222;
            margin-top: 32px;
            padding-top: 18px;
            font-size: 13px;
            color: #bbb;
        }

        @media(max-width:991px) {
            body {
                padding-top: 82px;
            }

            .navbar-nav {
                border-radius: 18px;
                margin-top: 18px;
                padding: 12px;
            }

            .about-hero h1 {
                font-size: 40px;
            }

            .stats-box {
                border-left: 0;
                padding-left: 0;
                margin-top: 25px;
            }

            .solution-device {
                display: none;
            }

            .vision-card p {
                max-width: 100%;
            }

            .vision-card img {
                opacity: .22;
            }

            .diff-card {
                border-right: none;
                border-bottom: 1px solid #eee;
            }
        }

        @media(max-width:575px) {
            .about-hero {
                min-height: auto;
                padding: 55px 0;
            }

            .about-hero h1 {
                font-size: 32px;
            }

            .section-title {
                font-size: 25px;
            }

            .story-content h2 {
                font-size: 25px;
            }

            .problem-card,
            .solution-card {
                padding: 24px;
            }

            .eco-icons {
                grid-template-columns: repeat(3, 1fr);
            }

            .final-cta h2 {
                font-size: 26px;
            }
        }
    </style>
</head>

<body>

    <?php include 'includes/nav.php'; ?>

    <!-- HERO -->
    <section class="about-hero">
        <div class="container">
            <div class="row">
                <div class="col-lg-7" data-aos="fade-right">
                    <div class="breadcrumb-custom">
                        Home <i class="fa-solid fa-chevron-right"></i> About Us
                    </div>

                    <div class="hero-label">About TEK-C</div>
                    <h1>Built from Real Sites. Designed for <br>
                        <span class="text-yellow">Total Control.</span>
                    </h1>
                    <p>
                        TEK-C is the digital evolution of real construction project management.
                        Built by UKB Construction Management Pvt Ltd — from years of on-ground
                        execution, challenges, and proven systems.
                    </p>

                    <div class="hero-actions">
                        <a href="tel:+91 78290 42156" class="btn btn-yellow">
                            Contact us<i class="fa-solid fa-arrow-right ms-2"></i>
                        </a>

                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- STORY -->
    <section class="story-wrap">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6" data-aos="fade-right">
                    <img src="Downloads/about.png"
                        class="story-img" alt="Construction project">
                </div>

                <div class="col-lg-6 story-content" data-aos="fade-up">
                    <div class="small-label">Our Story</div>
                    <h2>From Execution Challenges to a Structured System.</h2>
                    <p>
                        Every construction project faces the same issues — unstructured communication,
                        delays, lack of visibility, cost overruns and coordination gaps.
                    </p>
                    <p>
                        At UKB Construction Management, we didn’t just observe these problems —
                        we solved them on real sites.
                    </p>
                    <p>
                        TEK-C was born from this experience.
                    </p>
                    <p>
                        What started as internal systems and processes has now become a complete
                        construction management platform.
                    </p>
                </div>


            </div>

            <div class="my-3" data-aos="fade-left">
                <div class="stats-box row">
                    <div class="stat-item col-6 col-lg-3">
                        <div class="stat-icon"><i class="fa-regular fa-calendar-days"></i></div>
                        <div>
                            <h3>10+</h3>
                            <p>Years of Experience</p>
                        </div>
                    </div>

                    <div class="stat-item col-6 col-lg-3">
                        <div class="stat-icon"><i class="fa-regular fa-building"></i></div>
                        <div>
                            <h3>50+</h3>
                            <p>Projects Managed</p>
                        </div>
                    </div>

                    <div class="stat-item col-6 col-lg-3">
                        <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                        <div>
                            <h3>100+</h3>
                            <p>Professional Team</p>
                        </div>
                    </div>

                    <div class="stat-item col-6 col-lg-3">
                        <div class="stat-icon"><i class="fa-solid fa-location-dot"></i></div>
                        <div>
                            <h3 style="font-size:18px;">Across</h3>
                            <p>Bangalore & Beyond</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- PROBLEM & SOLUTION -->
    <section class="problem-solution">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-5" data-aos="fade-right">
                    <div class="problem-card">
                        <div class="small-label" style="color:#df5353;">The Problem We Saw</div>
                        <h3>Construction Doesn’t Lack Talent — It Lacks Systems.</h3>

                        <ul class="list-clean">
                            <li><i class="fa-regular fa-circle-xmark"></i> Too many tools and spreadsheets</li>
                            <li><i class="fa-regular fa-circle-xmark"></i> No single source of truth</li>
                            <li><i class="fa-regular fa-circle-xmark"></i> Decisions delayed</li>
                            <li><i class="fa-regular fa-circle-xmark"></i> Information scattered</li>
                            <li><i class="fa-regular fa-circle-xmark"></i> Accountability is unclear</li>
                        </ul>

                        <div class="alert-note">
                            <i class="fa-regular fa-lightbulb fa-2x"></i>
                            <span>The industry needed a practical, execution-focused system.</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7" data-aos="fade-left">
                    <div class="solution-card">
                        <div class="small-label" style="color:#3d9b45;">Our Solution</div>
                        <h3>A System Built for<br>Real Construction Execution.</h3>

                        <ul class="list-clean">
                            <li><i class="fa-solid fa-circle-check"></i> Structured daily reporting (DPR / MOM / RFI)
                            </li>
                            <li><i class="fa-solid fa-circle-check"></i> Real-time project tracking & dashboards</li>
                            <li><i class="fa-solid fa-circle-check"></i> Cost and procurement control</li>
                            <li><i class="fa-solid fa-circle-check"></i> Document & approval management</li>
                            <li><i class="fa-solid fa-circle-check"></i> Team accountability at every level</li>
                        </ul>

                        <div class="solution-note">
                            <i class="fa-solid fa-bullseye fa-2x"></i>
                            <span>From planning to handover — everything is connected.</span>
                        </div>

                        <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=600&q=80"
                            class="solution-device" alt="Dashboard">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- DIFFERENCE -->
    <section class="different">
        <div class="container">
            <div class="small-label" data-aos="fade-up">What Makes Us Different</div>
            <h2 class="section-title" data-aos="fade-up">Not Built by Coders. Built by Builders.</h2>
            <p class="section-subtitle" data-aos="fade-up">
                Unlike generic software, TEK-C is built from live project experience, designed for real site
                conditions and focused on execution, not theory.
            </p>

            <div class="row g-0">
                <div class="col-lg-3 col-md-6" data-aos="fade-up">
                    <div class="diff-card">
                        <i class="fa-solid fa-helmet-safety"></i>
                        <h4>Real Construction DNA</h4>
                        <p>Built from years of on-ground project management.</p>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="80">
                    <div class="diff-card">
                        <i class="fa-solid fa-people-arrows"></i>
                        <h4>Execution Focused</h4>
                        <p>Designed to solve real problems in real time.</p>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="160">
                    <div class="diff-card">
                        <i class="fa-solid fa-desktop"></i>
                        <h4>Practical & Easy to Use</h4>
                        <p>Simple interface, powerful workflows.</p>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="240">
                    <div class="diff-card last">
                        <i class="fa-solid fa-shield-halved"></i>
                        <h4>Reliable & Scalable</h4>
                        <p>Trusted by construction professionals.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- VISION MISSION APPROACH -->
    <section class="vision-section">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4" data-aos="fade-up">
                    <div class="vision-card vision-blue">
                        <div class="small-label">Our Vision</div>
                        <h3>To Bring Predictability<br>to Construction Projects.</h3>
                        <p>
                            We aim to eliminate chaos in execution, bring clarity to every stakeholder,
                            enable faster, smarter decisions and make project outcomes predictable.
                        </p>
                        <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=700&q=80"
                            alt="">
                    </div>
                </div>

                <div class="col-lg-4" data-aos="fade-up" data-aos-delay="100">
                    <div class="vision-card vision-orange">
                        <div class="small-label">Our Mission</div>
                        <h3>To Transform Construction<br>Management into a System.</h3>
                        <p>
                            Our mission is to convert real-world construction workflows into a scalable,
                            digital system that drives efficiency, transparency and success.
                        </p>
                        <img src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=700&q=80"
                            alt="">
                    </div>
                </div>

                <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
                    <div class="vision-card vision-purple">
                        <div class="small-label">Our Approach</div>
                        <h3>Execution First.<br>Technology Next.</h3>
                        <p>
                            We believe technology should simplify execution — not complicate it.
                            That’s why TEK-C is simple to use, structured in workflow and powerful in control.
                        </p>
                        <img src="https://images.unsplash.com/photo-1581094794329-c8112a89af12?auto=format&fit=crop&w=700&q=80"
                            alt="">
                    </div>
                </div>
            </div>
        </div>
    </section>
    <div class="container mb-5">
        <div class="row">
            <div class="col-lg-12" data-aos="fade-right">
                <div class="eco-icons">
                    <div class="eco-item">
                        <i class="fa-regular fa-building"></i>
                        Builders &<br>Developers
                    </div>
                    <div class="eco-item">
                        <i class="fa-solid fa-user-tie"></i>
                        Project<br>Management Consultants
                    </div>
                    <div class="eco-item">
                        <i class="fa-solid fa-users-gear"></i>
                        Site Engineers<br>& Project Teams
                    </div>
                    <div class="eco-item">
                        <i class="fa-solid fa-helmet-safety"></i>
                        Contractors<br>& Vendors
                    </div>
                    <div class="eco-item">
                        <i class="fa-solid fa-drafting-compass"></i>
                        Architects<br>& Consultants
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- LOWER INFO -->
    <section class="lower-info">
        <div class="container">
            <div class="row align-items-stretch ">


                <div class="col-lg-6" data-aos="fade-up">
                    <div class="practice-card">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="small-label">Backed by Real Projects</div>
                                <h3 class="fw-bold">Proven in Practice.<br>Not Just in Theory.</h3>
                                <p style="font-size:14px;color:#555;line-height:1.6;">
                                    TEK-C is continuously refined through live project execution,
                                    real team usage and ongoing improvements.
                                </p>
                                <a href="contact.php" class="btn btn-sm btn-yellow mt-2">
                                    Know More About TEK-C <i class="fa-solid fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                            <div class="col-md-6">
                                <div class="practice-grid">
                                    <img src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=400&q=80"
                                        alt="">
                                    <img src="https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=400&q=80"
                                        alt="">
                                    <img src="https://images.unsplash.com/photo-1590650046871-92c887180603?auto=format&fit=crop&w=400&q=80"
                                        alt="">
                                    <img src="https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=400&q=80"
                                        alt="">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6" data-aos="fade-left">
                    <div class="values-card">
                        <div class="small-label">Our Values</div>
                        <ul>
                            <li><i class="fa-regular fa-circle-check"></i> Integrity in everything we do</li>
                            <li><i class="fa-regular fa-circle-check"></i> Commitment to our partners</li>
                            <li><i class="fa-regular fa-circle-check"></i> Innovation with purpose</li>
                            <li><i class="fa-regular fa-circle-check"></i> Accountability at every level</li>
                            <li><i class="fa-regular fa-circle-check"></i> Success through collaboration</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="final-cta">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-lg-6" data-aos="fade-right">
                    <h2>Ready to Experience<br>Structured Project Control?</h2>
                    <p>See how TEK-C can transform your project execution.</p>
                </div>

                <div class="col-lg-6 text-lg-end" data-aos="fade-left">

                    <a href="tel:+91 78290 42156" class="btn btn-outline-light-custom mb-2">
                        Contact Us <i class="fa-solid fa-arrow-right ms-2"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <?php include 'includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>

    <script>
        AOS.init({
            duration: 750,
            once: true,
            offset: 80
        });

        const navbar = document.getElementById('mainNavbar');
        if (navbar) {
            window.addEventListener('scroll', () => {
                if (window.scrollY > 70) {
                    navbar.classList.add('nav-fixed');
                } else {
                    navbar.classList.remove('nav-fixed');
                }
            });
        }
    </script>

</body>

</html>