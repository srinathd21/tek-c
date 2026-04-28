<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEK-C | Construction ERP Software - About Us</title>

    <?php include('includes/links.php'); ?>
    <style>
        /* Additional inline styles for product emphasis */
        .ul-featured-property .ul-project-title {
            font-weight: 700;
        }

        .ul-banner-slide-title {
            font-size: 3.2rem;
            line-height: 1.2;
        }

        .text-accent {
            color: #f5a623;
        }

        .ul-stats-item .number {
            font-size: 2.8rem;
            font-weight: 800;
        }

        /* Team card image styling */
        .ul-team-card-img img {
            width: 100%;
            height: 280px;
            object-fit: cover;
            border-radius: 16px 16px 0 0;
        }

        .reviewer-image img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }

        .ul-partners-slider img {
            max-height: 80px;
            width: auto;
            filter: grayscale(0%);
            opacity: 0.7;
            transition: all 0.3s ease;
        }

        .ul-partners-slider img:hover {
            opacity: 1;
            filter: grayscale(0%);
        }
    </style>
</head>

<body>
    <div class="preloader" id="preloader">
        <div class="loader"></div>
    </div>

    <!-- SIDEBAR SECTION START -->
    <div class="ul-sidebar">
        <div class="ul-sidebar-header">
            <div class="ul-sidebar-header-logo">
                <a href="index.php">
                    <img src="assets/img/logo-dark.svg" alt="TEK-C Logo" class="logo">
                </a>
            </div>
            <button class="ul-sidebar-closer"><i class="flaticon-close"></i></button>
        </div>
        <div class="ul-sidebar-header-nav-wrapper d-block d-lg-none"></div>
        <p class="ul-sidebar-descr d-none d-lg-flex">
            TEK-C is a ready-to-deploy construction ERP software designed for builders, developers, and contractors.
            Streamline projects, control documents, manage teams, and deliver faster. Perfect for construction firms
            looking to digitize operations.
        </p>
        <div class="ul-sidebar-slider-wrapper d-none d-lg-flex">
            <div class="ul-sidebar-slider-nav ul-slider-nav">
                <button class="prev"><i class="flaticon-arrow"></i></button>
                <button class="next"><i class="flaticon-right-arrow"></i></button>
            </div>
            <div class="slider-wrapper">
                <div class="ul-sidebar-slider swiper">
                    <div class="swiper-wrapper">
                        <div class="swiper-slide">
                            <div class="ul-project">
                                <div class="ul-project-img"><img
                                        src="https://images.pexels.com/photos/2760242/pexels-photo-2760242.jpeg?auto=compress&cs=tinysrgb&w=300&h=200&fit=crop"
                                        alt="Dashboard Preview"></div>
                                <div class="ul-project-txt">
                                    <span class="ul-project-tag">Core Module</span>
                                    <div class="top">
                                        <div class="left">
                                            <span class="ul-project-price"><span class="number">TEK-C</span> ERP</span>
                                            <a href="#" class="ul-project-title">Sites & Contracts</a>
                                            <p class="ul-project-location">Manage scope, agreements, work orders, and
                                                site records</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-slide">
                            <div class="ul-project">
                                <div class="ul-project-img"><img
                                        src="https://images.pexels.com/photos/3183197/pexels-photo-3183197.jpeg?auto=compress&cs=tinysrgb&w=300&h=200&fit=crop"
                                        alt="Role Access"></div>
                                <div class="ul-project-txt">
                                    <span class="ul-project-tag">Security</span>
                                    <div class="top">
                                        <div class="left">
                                            <span class="ul-project-price"><span class="number">TEK-C</span>
                                                Access</span>
                                            <a href="#" class="ul-project-title">Role-Based Control</a>
                                            <p class="ul-project-location">Give right teams right level of control</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-slide">
                            <div class="ul-project">
                                <div class="ul-project-img"><img
                                        src="https://images.pexels.com/photos/53621/pexels-photo-53621.jpeg?auto=compress&cs=tinysrgb&w=300&h=200&fit=crop"
                                        alt="Document Management"></div>
                                <div class="ul-project-txt">
                                    <span class="ul-project-tag">Document Hub</span>
                                    <div class="top">
                                        <div class="left">
                                            <span class="ul-project-price"><span class="number">TEK-C</span> Docs</span>
                                            <a href="#" class="ul-project-title">Centralized Storage</a>
                                            <p class="ul-project-location">Drawings, approvals, photos, records</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ul-sidebar-footer">
            <span class="ul-sidebar-footer-title">Follow us</span>
            <div class="ul-sidebar-footer-social">
                <a href="#"><i class="flaticon-facebook"></i></a>
                <a href="#"><i class="flaticon-twitter"></i></a>
                <a href="#"><i class="flaticon-instagram"></i></a>
                <a href="#"><i class="flaticon-linkedin"></i></a>
            </div>
        </div>
    </div>
    <!-- SIDEBAR SECTION END -->

    <!-- SEARCH MODAL -->
    <div class="ul-search-form-wrapper flex-grow-1 flex-shrink-0">
        <button class="ul-search-closer"><i class="flaticon-close"></i></button>
        <form action="#" class="ul-search-form">
            <div class="ul-search-form-right">
                <input type="search" name="search" id="ul-search" placeholder="Search Modules, Features...">
                <button type="submit"><span class="icon"><i class="flaticon-search"></i></span></button>
            </div>
        </form>
    </div>
    <?php include('includes/nav.php'); ?>

    <main>
        <!-- BREADCRUMB SECTION START -->
        <div class="ul-breadcrumb"
            style="background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('assets/img1/1600X400.png'); background-size: cover; background-position: center;">
            <div class="wow animate__fadeInUp">
                <h2 class="ul-breadcrumb-title">About TEK-C</h2>
                <div class="ul-breadcrumb-nav">
                    <a href="index.php">Home</a>
                    <span class="separator"><i class="flaticon-aro-left"></i></span>
                    <span class="current-page">About Us</span>
                </div>
            </div>
        </div>
        <!-- BREADCRUMB SECTION END -->

        <div class="ul-inner-page-content-wrapper">
            <!-- WHY CHOOSE US SECTION START -->
            <section class="ul-why-choose-us ul-section-spacing wow animate__fadeInUp">
                <div class="ul-container">
                    <div class="row row-cols-lg-2 row-cols-1 align-items-center">
                        <div class="col">
                            <div class="ul-why-choose-us-imgs">
                                <div class="img"><img
                                        src="https://images.pexels.com/photos/2760242/pexels-photo-2760242.jpeg?auto=compress&cs=tinysrgb&w=400&h=500&fit=crop"
                                        alt="TEK-C ERP Dashboard"></div>
                                <div class="img">
                                    <img src="https://images.pexels.com/photos/159306/construction-site-build-construction-work-159306.jpeg?auto=compress&cs=tinysrgb&w=400&h=300&fit=crop"
                                        alt="Construction Management">
                                    <div class="icon"><i class="flaticon-home-agreement"></i></div>
                                </div>
                            </div>
                        </div>

                        <div class="col">
                            <div class="ul-why-choose-us-txt">
                                <span class="ul-section-sub-title">Why TEK-C</span>
                                <h2 class="ul-section-title">Built by Construction Experts for Construction Teams</h2>
                                <p class="ul-why-choose-us-heading-descr">A complete ERP solution designed around real
                                    construction workflows</p>

                                <div class="ul-why-choose-us-list">
                                    <div class="ul-why-choose-us-list-item">
                                        <div class="icon"><i class="flaticon-property"></i></div>
                                        <div class="txt">
                                            <h3 class="ul-why-choose-us-list-item-title">15+ Integrated Modules</h3>
                                            <p class="ul-why-choose-us-list-item-descr">From Sites & Contracts to DPR,
                                                Quotations, RFI, AIT, MOM, HR, and Attendance — everything you need in
                                                one platform.</p>
                                        </div>
                                    </div>

                                    <div class="ul-why-choose-us-list-item">
                                        <div class="icon"><i class="flaticon-list-1"></i></div>
                                        <div class="txt">
                                            <h3 class="ul-why-choose-us-list-item-title">Role-Based Access Control</h3>
                                            <p class="ul-why-choose-us-list-item-descr">Secure permissions for Admin,
                                                HR, QS, Accounts, Project Managers, Team Leads, and Site Engineers.</p>
                                        </div>
                                    </div>

                                    <div class="ul-why-choose-us-list-item">
                                        <div class="icon"><i class="flaticon-change"></i></div>
                                        <div class="txt">
                                            <h3 class="ul-why-choose-us-list-item-title">White-Label Ready</h3>
                                            <p class="ul-why-choose-us-list-item-descr">Deploy under your own brand.
                                                Customizable, scalable, and built for multi-tenant architecture.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <!-- WHY CHOOSE US SECTION END -->


            <!-- PARTNERS SECTION START -->
            <div class="ul-container ul-section-spacing">
                <div class="wow animate__fadeInUp">
                    <div class="ul-partners-slider swiper">
                        <div class="swiper-wrapper align-items-center">
                            <div class="swiper-slide"><img
                                    src="https://placehold.co/150x80/f8f9fa/1e3c72?text=UKB+Group" alt="Partner Logo">
                            </div>
                            <div class="swiper-slide"><img
                                    src="https://placehold.co/150x80/f8f9fa/1e3c72?text=Construction+Leaders"
                                    alt="Partner Logo"></div>
                            <div class="swiper-slide"><img
                                    src="https://placehold.co/150x80/f8f9fa/1e3c72?text=ERP+Partners"
                                    alt="Partner Logo"></div>
                            <div class="swiper-slide"><img
                                    src="https://placehold.co/150x80/f8f9fa/1e3c72?text=Tech+Alliance"
                                    alt="Partner Logo"></div>
                            <div class="swiper-slide"><img
                                    src="https://placehold.co/150x80/f8f9fa/1e3c72?text=Industry+Leaders"
                                    alt="Partner Logo"></div>
                            <div class="swiper-slide"><img
                                    src="https://placehold.co/150x80/f8f9fa/1e3c72?text=Trusted+Partners"
                                    alt="Partner Logo"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- PARTNERS SECTION END -->


            <!-- ABOUT SECTION START -->
            <section class="ul-about ul-inner-about ul-section-spacing">
                <div class="ul-container my-0 wow animate__fadeInUp">
                    <div class="row row-cols-lg-2 row-cols-1 align-items-center ul-bs-row">
                        <div class="col">
                            <div class="ul-about-txt ul-inner-about-txt">
                                <h2 class="ul-section-title">Our Mission: Digitizing Construction Management</h2>
                                <div>
                                    <p>TEK-C was born from real construction challenges. Built by UKB Group — a
                                        construction management company with years of on-ground experience — TEK-C is
                                        designed to solve the daily struggles of project tracking, document control,
                                        team coordination, and procurement management.</p>
                                    <p>We believe every construction firm, from small builders to large developers,
                                        deserves a digital backbone that brings clarity, accountability, and control.
                                        That's why we built TEK-C — a complete ERP system that mirrors actual
                                        construction workflows and helps teams deliver faster.</p>
                                </div>

                                <div class="ul-about-txt-bottom ul-inner-about-txt-bottom">
                                    <ul class="ul-inner-about-list">
                                        <li><i class="flaticon-read-more-icon"></i> 15+ integrated construction modules
                                        </li>
                                        <li><i class="flaticon-read-more-icon"></i> Role-based secure access for every
                                            team</li>
                                        <li><i class="flaticon-read-more-icon"></i> Centralized document & approval
                                            management</li>
                                        <li><i class="flaticon-read-more-icon"></i> Real-time project tracking &
                                            reporting</li>
                                    </ul>

                                    <div class="ul-about-stats ul-inner-about-stats">
                                        <div class="ul-about-stat ul-inner-about-stat">
                                            <span class="number">50+</span>
                                            <span class="txt">Projects Managed</span>
                                        </div>
                                        <div class="ul-about-stat ul-inner-about-stat">
                                            <span class="number">100%</span>
                                            <span class="txt">Data Centralization</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col">
                            <div class="ul-about-img ul-inner-about-img">
                                <img src="assets/img1/500X500.png" height="300"
                                    alt="TEK-C Platform">
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <!-- ABOUT SECTION END -->


            <!-- TEAM SECTION START -->
            <section class="ul-team ul-section-spacing">
                <div class="ul-container wow animate__fadeInUp">
                    <div class="ul-section-heading">
                        <div class="left">
                            <span class="ul-section-sub-title">Leadership</span>
                            <h2 class="ul-section-title">The Minds Behind TEK-C</h2>
                        </div>
                        <div class="right flex-shrink-0">
                            <a href="#" class="ul-btn">Meet Full Team</a>
                        </div>
                    </div>

                    <div class="ul-team-slider swiper">
                        <div class="swiper-wrapper">
                            <div class="swiper-slide">
                                <div class="ul-team-card">
                                    <div class="ul-team-card-img">
                                        <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="Team Member">
                                    </div>
                                    <div class="ul-team-card-txt">
                                        <div class="ul-team-card-socials">
                                            <div class="ul-team-card-socials-links">
                                                <a href="#"><i class="flaticon-facebook"></i></a>
                                                <a href="#"><i class="flaticon-twitter"></i></a>
                                                <a href="#"><i class="flaticon-linkedin"></i></a>
                                            </div>
                                            <div class="ul-team-card-socials-icon"><i class="flaticon-share"></i></div>
                                        </div>
                                        <h4 class="ul-team-card-title"><a href="#">U K Balachandar</a></h4>
                                        <span class="ul-team-card-subtitle">Founder & CEO</span>
                                    </div>
                                </div>
                            </div>

                            <div class="swiper-slide">
                                <div class="ul-team-card">
                                    <div class="ul-team-card-img">
                                        <img src="https://randomuser.me/api/portraits/men/45.jpg" alt="Team Member">
                                    </div>
                                    <div class="ul-team-card-txt">
                                        <div class="ul-team-card-socials">
                                            <div class="ul-team-card-socials-links">
                                                <a href="#"><i class="flaticon-facebook"></i></a>
                                                <a href="#"><i class="flaticon-twitter"></i></a>
                                                <a href="#"><i class="flaticon-linkedin"></i></a>
                                            </div>
                                            <div class="ul-team-card-socials-icon"><i class="flaticon-share"></i></div>
                                        </div>
                                        <h4 class="ul-team-card-title"><a href="#">Ariharasudhan P</a></h4>
                                        <span class="ul-team-card-subtitle">CTO & Product Lead</span>
                                    </div>
                                </div>
                            </div>

                            <div class="swiper-slide">
                                <div class="ul-team-card">
                                    <div class="ul-team-card-img">
                                        <img src="https://randomuser.me/api/portraits/men/52.jpg" alt="Team Member">
                                    </div>
                                    <div class="ul-team-card-txt">
                                        <div class="ul-team-card-socials">
                                            <div class="ul-team-card-socials-links">
                                                <a href="#"><i class="flaticon-facebook"></i></a>
                                                <a href="#"><i class="flaticon-twitter"></i></a>
                                                <a href="#"><i class="flaticon-linkedin"></i></a>
                                            </div>
                                            <div class="ul-team-card-socials-icon"><i class="flaticon-share"></i></div>
                                        </div>
                                        <h4 class="ul-team-card-title"><a href="#">Dharani K</a></h4>
                                        <span class="ul-team-card-subtitle">Head of Operations</span>
                                    </div>
                                </div>
                            </div>

                            <div class="swiper-slide">
                                <div class="ul-team-card">
                                    <div class="ul-team-card-img">
                                        <img src="https://randomuser.me/api/portraits/men/28.jpg" alt="Team Member">
                                    </div>
                                    <div class="ul-team-card-txt">
                                        <div class="ul-team-card-socials">
                                            <div class="ul-team-card-socials-links">
                                                <a href="#"><i class="flaticon-facebook"></i></a>
                                                <a href="#"><i class="flaticon-twitter"></i></a>
                                                <a href="#"><i class="flaticon-linkedin"></i></a>
                                            </div>
                                            <div class="ul-team-card-socials-icon"><i class="flaticon-share"></i></div>
                                        </div>
                                        <h4 class="ul-team-card-title"><a href="#">Srinath D</a></h4>
                                        <span class="ul-team-card-subtitle">HR & Talent Lead</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="ul-team-slider-nav ul-slider-nav">
                            <button class="prev"><i class="flaticon-arrow"></i></button>
                            <button class="next"><i class="flaticon-right-arrow"></i></button>
                        </div>
                    </div>
                </div>

                <div class="ul-section-vectors ul-team-vectors">
                    <img src="assets/img/team-vector-1.png" alt="vector" class="wow animate__fadeInLeft">
                    <img src="assets/img/team-vector-2.png" alt="vector" class="wow animate__fadeInRight">
                </div>
            </section>
            <!-- TEAM SECTION END -->


            <!-- TESTIMONIAL SECTION START -->
            <section class="ul-testimonial ul-section-spacing">
                <div class="ul-testimonial-container">
                    <div
                        class="row row-cols-lg-2 row-cols-1 gx-0 align-items-center flex-lg-row flex-column-reverse gy-5">
                        <div class="col">
                            <div class="ul-testimonial-img wow animate__fadeInUp">
                                <img src="https://images.pexels.com/photos/3184292/pexels-photo-3184292.jpeg?auto=compress&cs=tinysrgb&w=600&h=500&fit=crop"
                                    alt="Team using TEK-C">
                            </div>
                        </div>
                        <div class="col">
                            <div class="ul-testimonial-txt wow animate__fadeInUp">
                                <div class="ul-section-heading">
                                    <div><span class="ul-section-sub-title">Client Success</span>
                                        <h2 class="ul-section-title">What Construction Leaders Say</h2>
                                    </div>
                                </div>
                                <div class="ul-testimonial-slider swiper">
                                    <div class="swiper-wrapper">
                                        <div class="swiper-slide">
                                            <div class="ul-testimony">
                                                <div class="top">
                                                    <div class="ul-testimony-reviewer-img">
                                                        <img src="https://randomuser.me/api/portraits/men/68.jpg"
                                                            alt="Reviewer">
                                                    </div>
                                                    <div class="ul-testimony-reviewer-info">
                                                        <h3 class="ul-testimony-reviewer-name">Ramesh Kumar</h3>
                                                        <h4 class="ul-testimony-reviewer-role">Project Director</h4>
                                                        <div class="ul-testimony-rating"><i class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i class="flaticon-star"></i>
                                                        </div>
                                                    </div>
                                                    <div class="ul-testimony-quotation-icon"><img
                                                            src="assets/img/quotation-icon.svg" alt="quote"></div>
                                                </div>
                                                <p class="ul-testimony-txt">"TEK-C transformed our project tracking.
                                                    Daily Progress Reports and Quotation Management alone saved us 20%
                                                    in costs. Now we have complete visibility from site to management."
                                                </p>
                                            </div>
                                        </div>
                                        <div class="swiper-slide">
                                            <div class="ul-testimony">
                                                <div class="top">
                                                    <div class="ul-testimony-reviewer-img">
                                                        <img src="https://randomuser.me/api/portraits/women/45.jpg"
                                                            alt="Reviewer">
                                                    </div>
                                                    <div class="ul-testimony-reviewer-info">
                                                        <h3 class="ul-testimony-reviewer-name">Priya K.</h3>
                                                        <h4 class="ul-testimony-reviewer-role">Operations Head</h4>
                                                        <div class="ul-testimony-rating"><i class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i class="flaticon-star"></i>
                                                        </div>
                                                    </div>
                                                    <div class="ul-testimony-quotation-icon"><img
                                                            src="assets/img/quotation-icon.svg" alt="quote"></div>
                                                </div>
                                                <p class="ul-testimony-txt">"Role-based access and document control
                                                    helped us streamline approvals. The AIT module ensured no task falls
                                                    through cracks. Best decision for our operations."</p>
                                            </div>
                                        </div>
                                        <div class="swiper-slide">
                                            <div class="ul-testimony">
                                                <div class="top">
                                                    <div class="ul-testimony-reviewer-img">
                                                        <img src="https://randomuser.me/api/portraits/men/75.jpg"
                                                            alt="Reviewer">
                                                    </div>
                                                    <div class="ul-testimony-reviewer-info">
                                                        <h3 class="ul-testimony-reviewer-name">Senthil Murugan</h3>
                                                        <h4 class="ul-testimony-reviewer-role">General Manager</h4>
                                                        <div class="ul-testimony-rating"><i class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i
                                                                class="flaticon-star"></i><i class="flaticon-star"></i>
                                                        </div>
                                                    </div>
                                                    <div class="ul-testimony-quotation-icon"><img
                                                            src="assets/img/quotation-icon.svg" alt="quote"></div>
                                                </div>
                                                <p class="ul-testimony-txt">"From HR to site reporting, TEK-C gives us a
                                                    single source of truth. The centralized document storage and
                                                    approval workflows are game-changers."</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ul-testimonial-slider-pagination"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <!-- TESTIMONIAL SECTION END -->


            <!-- FEATURES SECTION START -->
            <section class="ul-features ul-section-spacing">
                <div class="ul-container">
                    <div class="ul-features-content wow animate__fadeInUp">
                        <div class="row ul-bs-row">
                            <div class="col-lg-3">
                                <div class="ul-section-heading ul-features-heading">
                                    <div>
                                        <span class="ul-section-sub-title">Core Capabilities</span>
                                        <h2 class="ul-section-title">Key Features</h2>
                                        <a href="#" class="ul-features-heading-btn ul-btn">Request Demo</a>
                                    </div>
                                    <img src="assets/img/features-vector.svg" alt="vector"
                                        class="vector wow animate__fadeInLeft">
                                </div>
                            </div>

                            <div class="col-lg-9">
                                <div class="ul-features-slider swiper">
                                    <div class="swiper-wrapper">
                                        <div class="swiper-slide">
                                            <div class="ul-feature">
                                                <div class="ul-feature-icon"><i class="flaticon-buildings"></i></div>
                                                <div class="ul-feature-txt">
                                                    <h3 class="ul-feature-title"><a href="#">Sites & Contracts</a></h3>
                                                    <span class="ul-feature-sub-title">BOQ, Work Orders,
                                                        Agreements</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="swiper-slide">
                                            <div class="ul-feature">
                                                <div class="ul-feature-icon"><i class="flaticon-building"></i></div>
                                                <div class="ul-feature-txt">
                                                    <h3 class="ul-feature-title"><a href="#">DPR & DAR & MPT</a></h3>
                                                    <span class="ul-feature-sub-title">Daily & Monthly Reports</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="swiper-slide">
                                            <div class="ul-feature">
                                                <div class="ul-feature-icon"><i class="flaticon-house-1"></i></div>
                                                <div class="ul-feature-txt">
                                                    <h3 class="ul-feature-title"><a href="#">Quotations</a></h3>
                                                    <span class="ul-feature-sub-title">Procurement & Comparison</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="swiper-slide">
                                            <div class="ul-feature">
                                                <div class="ul-feature-icon"><i class="flaticon-building"></i></div>
                                                <div class="ul-feature-txt">
                                                    <h3 class="ul-feature-title"><a href="#">RFI & AIT</a></h3>
                                                    <span class="ul-feature-sub-title">Action Items & Compliance</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="swiper-slide">
                                            <div class="ul-feature">
                                                <div class="ul-feature-icon"><i class="flaticon-property"></i></div>
                                                <div class="ul-feature-txt">
                                                    <h3 class="ul-feature-title"><a href="#">HR & Attendance</a></h3>
                                                    <span class="ul-feature-sub-title">Geo-fenced Tracking</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="ul-slider-action-wrapper ul-features-slider-action-wrapper">
                                    <button class="ul-features-slider-prev">Previous</button>
                                    <div
                                        class="ul-slider-pagination-progress ul-features-slider-pagination flex-shrink-0">
                                    </div>
                                    <button class="ul-features-slider-next">Next</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <!-- FEATURES SECTION END -->


            <!-- STATS SECTION START -->
            <div class="ul-stats ul-section-spacing">
                <div class="ul-stats-wrapper wow animate__fadeInUp">
                    <div class="ul-stats-item">
                        <i class="flaticon-excavator"></i>
                        <span class="number">15+</span>
                        <span class="txt">Integrated Modules</span>
                    </div>
                    <div class="ul-stats-item">
                        <i class="flaticon-interior-design"></i>
                        <span class="number">6</span>
                        <span class="txt">Role-based Dashboards</span>
                    </div>
                    <div class="ul-stats-item">
                        <i class="flaticon-buildings"></i>
                        <span class="number">100%</span>
                        <span class="txt">Centralized Data</span>
                    </div>
                    <div class="ul-stats-item">
                        <i class="flaticon-map"></i>
                        <span class="number">24/7</span>
                        <span class="txt">Secure Cloud Access</span>
                    </div>
                </div>
            </div>
            <!-- STATS SECTION END -->


            <!-- FINAL CTA SECTION -->
            <div class="ul-app-ad wow animate__fadeInUp">
                <div class="ul-app-ad-container">
                    <div class="ul-app-ad-content">
                        <div class="row align-items-start gy-5">
                            <div class="col-lg-7">
                                <div class="ul-app-ad-txt">
                                    <span class="ul-section-sub-title">Start Your ERP Journey</span>
                                    <h2 class="ul-section-title">License <span class="colored">TEK-C</span> for Your
                                        Construction Group Today</h2>
                                    <div class="ul-app-ad-btns">
                                        <button><i class="flaticon-play"></i><span><span class="sub-title">Book
                                                    a</span><span class="title">Live Demo</span></span></button>
                                        <button><i class="flaticon-play"></i><span><span class="sub-title">Talk
                                                    to</span><span class="title">Sales Team</span></span></button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-5">
                                <div class="ul-app-ad-imgs">
                                    <div class="ul-app-ad-img">
                                        
                                        <img src="https://images.pexels.com/photos/2760242/pexels-photo-2760242.jpeg?auto=compress&cs=tinysrgb&w=200&h=300&fit=crop"
                                            alt="App ss" class="ul-app-ad-ss-1">
                                    </div>
                                    <div class="ul-app-ad-img">
                                        <img src="https://images.pexels.com/photos/3183197/pexels-photo-3183197.jpeg?auto=compress&cs=tinysrgb&w=200&h=300&fit=crop"
                                            alt="App Screenshot" class="ul-app-ad-ss-2">
                                    </div>
                                    <img src="assets/img/app-ad-img-vector.svg" alt="vector" class="vector">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- FINAL CTA SECTION END -->
        </div>
    </main>

    <?php include('includes/footer.php'); ?>
    <?php include('includes/script.php'); ?>

</body>

</html>