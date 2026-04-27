<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEK-C | Product Overview - Complete Construction ERP Software</title>

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
        
        /* Product overview specific styles */
        .module-card {
        background: #fff;
        border-radius: 16px;
        padding: 2rem;
        box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        height: 100%;
        }
        
        .module-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 40px rgba(0,0,0,0.1);
        }
        
        .module-icon {
        width: 70px;
        height: 70px;
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1.5rem;
        }
        
        .module-icon i {
        font-size: 2rem;
        color: white;
        }
        
        .module-card h3 {
        font-size: 1.4rem;
        font-weight: 700;
        margin-bottom: 1rem;
        }
        
        .module-card p {
        color: #666;
        line-height: 1.6;
        margin-bottom: 1rem;
        }
        
        .feature-list {
        list-style: none;
        padding: 0;
        margin-top: 1rem;
        }
        
        .feature-list li {
        padding: 0.5rem 0;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        gap: 10px;
        }
        
        .feature-list li i {
        color: #f5a623;
        font-size: 0.9rem;
        }
        
        .workflow-step {
        text-align: center;
        padding: 2rem;
        }
        
        .workflow-icon {
        width: 80px;
        height: 80px;
        background: #f8f9fa;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        }
        
        .workflow-icon i {
        font-size: 2rem;
        color: #1e3c72;
        }
        
        .tech-badge {
        background: #f0f2f5;
        padding: 0.5rem 1rem;
        border-radius: 30px;
        font-size: 0.85rem;
        font-weight: 500;
        display: inline-block;
        margin: 0.25rem;
        }
        
        .comparison-table th {
        background: #1e3c72;
        color: white;
        padding: 1rem;
        }
        
        .comparison-table td {
        padding: 1rem;
        vertical-align: middle;
        }
        
        .check-icon {
        color: #28a745;
        font-size: 1.2rem;
        }
        
        .times-icon {
        color: #dc3545;
        font-size: 1.2rem;
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
            Streamline projects, control documents, manage teams, and deliver faster.
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
                                <div class="ul-project-img"><img src="https://images.pexels.com/photos/2760242/pexels-photo-2760242.jpeg?auto=compress&cs=tinysrgb&w=300&h=200&fit=crop" alt="Dashboard Preview"></div>
                                <div class="ul-project-txt">
                                    <span class="ul-project-tag">Core Module</span>
                                    <div class="top">
                                        <div class="left">
                                            <span class="ul-project-price"><span class="number">TEK-C</span> ERP</span>
                                            <a href="#" class="ul-project-title">Sites & Contracts</a>
                                            <p class="ul-project-location">Manage scope, agreements, work orders</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-slide">
                            <div class="ul-project">
                                <div class="ul-project-img"><img src="https://images.pexels.com/photos/3183197/pexels-photo-3183197.jpeg?auto=compress&cs=tinysrgb&w=300&h=200&fit=crop" alt="Role Access"></div>
                                <div class="ul-project-txt">
                                    <span class="ul-project-tag">Security</span>
                                    <div class="top">
                                        <div class="left">
                                            <span class="ul-project-price"><span class="number">TEK-C</span> Access</span>
                                            <a href="#" class="ul-project-title">Role-Based Control</a>
                                            <p class="ul-project-location">Give right teams right level of control</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="swiper-slide">
                            <div class="ul-project">
                                <div class="ul-project-img"><img src="https://images.pexels.com/photos/53621/pexels-photo-53621.jpeg?auto=compress&cs=tinysrgb&w=300&h=200&fit=crop" alt="Document Management"></div>
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
        <!-- PAGE HEADER SECTION -->
        <div class="ul-breadcrumb" style="background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('https://images.pexels.com/photos/159306/construction-site-build-construction-work-159306.jpeg?auto=compress&cs=tinysrgb&w=1600&h=400&fit=crop'); background-size: cover; background-position: center;">
            <div class="wow animate__fadeInUp">
                <h2 class="ul-breadcrumb-title">Product Overview</h2>
                <div class="ul-breadcrumb-nav">
                    <a href="index.php">Home</a>
                    <span class="separator"><i class="flaticon-aro-left"></i></span>
                    <span class="current-page">Product Overview</span>
                </div>
            </div>
        </div>

        <!-- PRODUCT INTRODUCTION -->
        <section class="ul-section-spacing">
            <div class="ul-container">
                <div class="row align-items-center">
                    <div class="col-lg-6 wow animate__fadeInUp">
                        <span class="ul-section-sub-title">Complete Construction ERP</span>
                        <h2 class="ul-section-title">One Platform. Complete Control.</h2>
                        <p class="mb-4">TEK-C is a production-ready ERP software built specifically for construction companies. From site execution to management reporting, everything is connected in one secure platform.</p>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="flaticon-check" style="color: #f5a623;"></i>
                                    <span>15+ Integrated Modules</span>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="flaticon-check" style="color: #f5a623;"></i>
                                    <span>Role-Based Access Control</span>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="flaticon-check" style="color: #f5a623;"></i>
                                    <span>White-Label Ready</span>
                                </div>
                            </div>
                            <div class="col-6 mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="flaticon-check" style="color: #f5a623;"></i>
                                    <span>Multi-Tenant Architecture</span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <a href="#" class="ul-btn">Request Live Demo</a>
                            <a href="#" class="ul-btn ul-btn-outline ms-3">Download Brochure</a>
                        </div>
                    </div>
                    <div class="col-lg-6 wow animate__fadeInUp mt-4 mt-lg-0">
                        <img src="https://images.pexels.com/photos/2760242/pexels-photo-2760242.jpeg?auto=compress&cs=tinysrgb&w=600&h=450&fit=crop" alt="TEK-C Dashboard" class="img-fluid rounded-4 shadow">
                    </div>
                </div>
            </div>
        </section>

        <!-- CORE MODULES GRID -->
        <section class="ul-section-spacing" style="background: #f8f9fa;">
            <div class="ul-container">
                <div class="ul-section-heading text-center justify-content-center wow animate__fadeInUp">
                    <div>
                        <span class="ul-section-sub-title">Core Modules</span>
                        <h2 class="ul-section-title">Everything You Need to Run Construction Projects</h2>
                        <p class="ul-section-descr">15+ integrated modules covering every aspect of construction management</p>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-md-6 col-lg-4 wow animate__fadeInUp">
                        <div class="module-card">
                            <div class="module-icon"><i class="flaticon-buildings"></i></div>
                            <h3>Sites & Contracts</h3>
                            <p>Complete project and contract management with BOQ tracking, work orders, and agreement storage.</p>
                            <ul class="feature-list">
                                <li><i class="flaticon-check"></i> Project Scope Management</li>
                                <li><i class="flaticon-check"></i> Work Order Tracking</li>
                                <li><i class="flaticon-check"></i> Agreement & Contract Storage</li>
                                <li><i class="flaticon-check"></i> BOQ Management</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 wow animate__fadeInUp">
                        <div class="module-card">
                            <div class="module-icon"><i class="flaticon-list-1"></i></div>
                            <h3>DPR, DAR & MPT Reports</h3>
                            <p>Daily Progress Reports, Daily Activity Reports, and Monthly Progress Trackers for complete project visibility.</p>
                            <ul class="feature-list">
                                <li><i class="flaticon-check"></i> Daily Progress Reports (DPR)</li>
                                <li><i class="flaticon-check"></i> Daily Activity Reports (DAR)</li>
                                <li><i class="flaticon-check"></i> Monthly Progress Trackers (MPT)</li>
                                <li><i class="flaticon-check"></i> Delay Analysis Reports (DLAR)</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 wow animate__fadeInUp">
                        <div class="module-card">
                            <div class="module-icon"><i class="flaticon-building"></i></div>
                            <h3>Quotation Management</h3>
                            <p>End-to-end procurement workflow from request to approval with dealer management and comparison.</p>
                            <ul class="feature-list">
                                <li><i class="flaticon-check"></i> Quotation Requests</li>
                                <li><i class="flaticon-check"></i> Dealer Assignment</li>
                                <li><i class="flaticon-check"></i> QS Negotiation</li>
                                <li><i class="flaticon-check"></i> Manager Approval</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 wow animate__fadeInUp">
                        <div class="module-card">
                            <div class="module-icon"><i class="flaticon-change"></i></div>
                            <h3>RFI & AIT Trackers</h3>
                            <p>Request for Information and Action Item Trackers for better compliance and accountability.</p>
                            <ul class="feature-list">
                                <li><i class="flaticon-check"></i> RFI Submission & Response</li>
                                <li><i class="flaticon-check"></i> Action Item Tracking (AIT)</li>
                                <li><i class="flaticon-check"></i> Due Date Monitoring</li>
                                <li><i class="flaticon-check"></i> Status Updates</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 wow animate__fadeInUp">
                        <div class="module-card">
                            <div class="module-icon"><i class="flaticon-property"></i></div>
                            <h3>MOM & MAS Reports</h3>
                            <p>Minutes of Meeting and Meeting Attendance Sheets with digital signatures and action items.</p>
                            <ul class="feature-list">
                                <li><i class="flaticon-check"></i> Meeting Minutes Documentation</li>
                                <li><i class="flaticon-check"></i> Attendee Signatures</li>
                                <li><i class="flaticon-check"></i> Action Item Tracking</li>
                                <li><i class="flaticon-check"></i> Next Meeting Scheduling</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4 wow animate__fadeInUp">
                        <div class="module-card">
                            <div class="module-icon"><i class="flaticon-user"></i></div>
                            <h3>HR & Attendance</h3>
                            <p>Complete workforce management with geolocation-based attendance, leave management, and recruitment.</p>
                            <ul class="feature-list">
                                <li><i class="flaticon-check"></i> Geo-Fenced Attendance</li>
                                <li><i class="flaticon-check"></i> Leave Management</li>
                                <li><i class="flaticon-check"></i> Recruitment & Onboarding</li>
                                <li><i class="flaticon-check"></i> Payroll Components</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- HOW IT WORKS - WORKFLOW -->
        <section class="ul-section-spacing">
            <div class="ul-container">
                <div class="ul-section-heading text-center justify-content-center wow animate__fadeInUp">
                    <div>
                        <span class="ul-section-sub-title">How It Works</span>
                        <h2 class="ul-section-title">Simple, Streamlined Workflow</h2>
                        <p class="ul-section-descr">Designed around real construction processes</p>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-md-4 wow animate__fadeInUp">
                        <div class="workflow-step">
                            <div class="workflow-icon"><i class="flaticon-property"></i></div>
                            <h4>1. Project Setup</h4>
                            <p>Create sites, define scope, upload contracts and work orders. Set up teams with role-based access.</p>
                        </div>
                    </div>
                    <div class="col-md-4 wow animate__fadeInUp">
                        <div class="workflow-step">
                            <div class="workflow-icon"><i class="flaticon-list-1"></i></div>
                            <h4>2. Daily Execution</h4>
                            <p>Site engineers submit DPR, DAR, and site photos. Track manpower, machinery, and materials daily.</p>
                        </div>
                    </div>
                    <div class="col-md-4 wow animate__fadeInUp">
                        <div class="workflow-step">
                            <div class="workflow-icon"><i class="flaticon-building"></i></div>
                            <h4>3. Review & Approve</h4>
                            <p>Managers review reports, approve quotations, respond to RFIs, and track action items.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TECHNOLOGY & SECURITY -->
        <section class="ul-section-spacing" style="background: #f8f9fa;">
            <div class="ul-container">
                <div class="row align-items-center">
                    <div class="col-lg-6 wow animate__fadeInUp">
                        <span class="ul-section-sub-title">Technology & Security</span>
                        <h2 class="ul-section-title">Built for Scale. Secured for Enterprise.</h2>
                        <p>TEK-C is built on modern technology stack with enterprise-grade security features.</p>
                        <div class="mt-4">
                            <span class="tech-badge"><i class="flaticon-check me-1"></i> PHP 7.4+</span>
                            <span class="tech-badge"><i class="flaticon-check me-1"></i> MySQL / MariaDB</span>
                            <span class="tech-badge"><i class="flaticon-check me-1"></i> Bootstrap 5</span>
                            <span class="tech-badge"><i class="flaticon-check me-1"></i> AES-256 Encryption</span>
                            <span class="tech-badge"><i class="flaticon-check me-1"></i> SSL/TLS Security</span>
                            <span class="tech-badge"><i class="flaticon-check me-1"></i> GDPR Compliant</span>
                        </div>
                        <div class="mt-4">
                            <h4>Deployment Options</h4>
                            <ul class="feature-list">
                                <li><i class="flaticon-check"></i> Cloud (SaaS) - Monthly/Annual Subscription</li>
                                <li><i class="flaticon-check"></i> On-Premise - One-time License + Annual Maintenance</li>
                                <li><i class="flaticon-check"></i> White-Label - Complete Branding Control</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-6 wow animate__fadeInUp mt-4 mt-lg-0">
                        <img src="https://images.pexels.com/photos/1181263/pexels-photo-1181263.jpeg?auto=compress&cs=tinysrgb&w=600&h=450&fit=crop" alt="Security Features" class="img-fluid rounded-4 shadow">
                    </div>
                </div>
            </div>
        </section>

        <!-- COMPARISON TABLE -->
        <section class="ul-section-spacing">
            <div class="ul-container">
                <div class="ul-section-heading text-center justify-content-center wow animate__fadeInUp">
                    <div>
                        <span class="ul-section-sub-title">Why TEK-C</span>
                        <h2 class="ul-section-title">Traditional Methods vs TEK-C ERP</h2>
                    </div>
                </div>
                <div class="table-responsive wow animate__fadeInUp">
                    <table class="table comparison-table">
                        <thead>
                            <tr><th>Feature</th><th>Traditional Methods</th><th>TEK-C ERP</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Data Management</td><td>Spreadsheets, paper files, multiple systems</td><td><i class="check-icon flaticon-check"></i> Centralized database</td></tr>
                            <tr><td>Report Generation</td><td>Manual compilation, error-prone</td><td><i class="check-icon flaticon-check"></i> Automated reports in minutes</td></tr>
                            <tr><td>Access Control</td><td>Limited or no control</td><td><i class="check-icon flaticon-check"></i> Role-based permissions</td></tr>
                            <tr><td>Document Storage</td><td>Physical files, scattered digital copies</td><td><i class="check-icon flaticon-check"></i> Central secure repository</td></tr>
                            <tr><td>Procurement Workflow</td><td>Manual vendor coordination</td><td><i class="check-icon flaticon-check"></i> Automated quotation workflow</td></tr>
                            <tr><td>Management Visibility</td><td>Delayed, incomplete information</td><td><i class="check-icon flaticon-check"></i> Real-time dashboard & reporting</td></tr>
                            <tr><td>Cost Tracking</td><td>Difficult to track project costs</td><td><i class="check-icon flaticon-check"></i> Complete cost visibility</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- STATS SECTION -->
        <div class="ul-stats ul-section-spacing">
            <div class="ul-stats-wrapper wow animate__fadeInUp">
                <div class="ul-stats-item"><i class="flaticon-excavator"></i><span class="number">50+</span><span class="txt">Projects Managed</span></div>
                <div class="ul-stats-item"><i class="flaticon-interior-design"></i><span class="number">15+</span><span class="txt">Integrated Modules</span></div>
                <div class="ul-stats-item"><i class="flaticon-buildings"></i><span class="number">6</span><span class="txt">Role Dashboards</span></div>
                <div class="ul-stats-item"><i class="flaticon-map"></i><span class="number">100%</span><span class="txt">Data Centralization</span></div>
            </div>
        </div>

        <!-- FINAL CTA -->
        <div class="ul-app-ad wow animate__fadeInUp">
            <div class="ul-app-ad-container">
                <div class="ul-app-ad-content">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <div class="ul-app-ad-txt">
                                <span class="ul-section-sub-title">Ready to Transform?</span>
                                <h2 class="ul-section-title">License <span class="colored">TEK-C</span> for Your Construction Business</h2>
                                <div class="ul-app-ad-btns mt-4">
                                    <a href="#" class="ul-btn me-3"><i class="flaticon-play me-2"></i> Book Live Demo</a>
                                    <a href="#" class="ul-btn ul-btn-outline"><i class="flaticon-download me-2"></i> Get Pricing</a>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 text-center">
                            <img src="https://placehold.co/200x200/f5a623/ffffff?text=Contact+Sales" alt="Contact" class="img-fluid rounded-3" style="max-width: 180px;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include('includes/footer.php'); ?>
    <?php include('includes/script.php'); ?>

</body>
</html>