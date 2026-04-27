<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEK-C | Pricing - Construction ERP Software Licensing</title>

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
        
        /* Pricing card styles */
        .pricing-card {
            background: #fff;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 5px 30px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
            border: 1px solid #eee;
        }
        
        .pricing-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.12);
        }
        
        .pricing-card.popular {
            border: 2px solid #f5a623;
            transform: scale(1.02);
        }
        
        .popular-badge {
            position: absolute;
            top: -12px;
            right: 20px;
            background: #f5a623;
            color: white;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .pricing-header {
            text-align: center;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #eee;
            margin-bottom: 1.5rem;
        }
        
        .pricing-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .price {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e3c72;
        }
        
        .price small {
            font-size: 0.9rem;
            font-weight: 400;
            color: #666;
        }
        
        .price-sub {
            font-size: 0.85rem;
            color: #888;
            margin-top: 5px;
        }
        
        .pricing-features {
            list-style: none;
            padding: 0;
            margin: 1.5rem 0;
        }
        
        .pricing-features li {
            padding: 0.6rem 0;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .pricing-features li i {
            color: #f5a623;
            font-size: 0.9rem;
            width: 20px;
        }
        
        .pricing-features li.disabled {
            opacity: 0.5;
        }
        
        .pricing-features li.disabled i {
            color: #ccc;
        }
        
        .pricing-card .ul-btn {
            width: 100%;
            text-align: center;
            display: block;
        }
        
        .faq-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .faq-item:hover {
            background: #f0f2f5;
        }
        
        .faq-question {
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .faq-answer {
            display: none;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e0e0e0;
            color: #666;
            line-height: 1.6;
        }
        
        .faq-answer.active {
            display: block;
        }
        
        .enterprise-card {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border-radius: 20px;
            padding: 2.5rem;
            color: white;
            text-align: center;
        }
        
        .enterprise-card .ul-btn-white {
            background: white;
            color: #1e3c72;
            border: none;
        }
        
        .enterprise-card .ul-btn-white:hover {
            background: #f5a623;
            color: white;
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
        <div class="ul-breadcrumb" style="background: linear-gradient(rgba(0,0,0,0.7), rgba(0,0,0,0.7)), url('assets/img1/1600X400.png'); background-size: cover; background-position: center;">
            <div class="wow animate__fadeInUp">
                <h2 class="ul-breadcrumb-title">Pricing & Licensing</h2>
                <div class="ul-breadcrumb-nav">
                    <a href="index.php">Home</a>
                    <span class="separator"><i class="flaticon-aro-left"></i></span>
                    <span class="current-page">Pricing</span>
                </div>
            </div>
        </div>

        <!-- PRICING INTRO -->
        <section class="ul-section-spacing">
            <div class="ul-container">
                <div class="ul-section-heading text-center justify-content-center wow animate__fadeInUp">
                    <div>
                        <span class="ul-section-sub-title">Simple, Transparent Pricing</span>
                        <h2 class="ul-section-title">Choose the Plan That Fits Your Business</h2>
                        <p class="ul-section-descr">Flexible licensing options for construction companies of all sizes</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- PRICING CARDS -->
        <section class="ul-section-spacing pt-0">
            <div class="ul-container">
                <div class="row g-4">
                    <!-- Starter Plan -->
                    <div class="col-lg-4 wow animate__fadeInUp">
                        <div class="pricing-card">
                            <div class="pricing-header">
                                <h3>Starter</h3>
                                <div class="price">₹49,999 <small>/ year</small></div>
                                <div class="price-sub">+18% GST applicable</div>
                            </div>
                            <div class="text-center mb-3">
                                <span class="tech-badge">Up to 5 Users</span>
                                <span class="tech-badge">1 Project Site</span>
                            </div>
                            <ul class="pricing-features">
                                <li><i class="flaticon-check"></i> Sites & Contracts Module</li>
                                <li><i class="flaticon-check"></i> DPR & DAR Reports</li>
                                <li><i class="flaticon-check"></i> Basic Document Management</li>
                                <li><i class="flaticon-check"></i> 5 Employee Records</li>
                                <li><i class="flaticon-check"></i> Email Support</li>
                                <li class="disabled"><i class="flaticon-check"></i> Quotation Management</li>
                                <li class="disabled"><i class="flaticon-check"></i> RFI & AIT Trackers</li>
                                <li class="disabled"><i class="flaticon-check"></i> API Access</li>
                            </ul>
                            <a href="#" class="ul-btn ul-btn-outline">Get Started</a>
                        </div>
                    </div>

                    <!-- Professional Plan (Popular) -->
                    <div class="col-lg-4 wow animate__fadeInUp">
                        <div class="pricing-card popular">
                            <div class="popular-badge">Most Popular</div>
                            <div class="pricing-header">
                                <h3>Professional</h3>
                                <div class="price">₹99,999 <small>/ year</small></div>
                                <div class="price-sub">+18% GST applicable</div>
                            </div>
                            <div class="text-center mb-3">
                                <span class="tech-badge">Up to 15 Users</span>
                                <span class="tech-badge">Up to 5 Project Sites</span>
                            </div>
                            <ul class="pricing-features">
                                <li><i class="flaticon-check"></i> All Starter Features</li>
                                <li><i class="flaticon-check"></i> Quotation Management</li>
                                <li><i class="flaticon-check"></i> RFI & AIT Trackers</li>
                                <li><i class="flaticon-check"></i> MOM & MAS Reports</li>
                                <li><i class="flaticon-check"></i> HR & Attendance Module</li>
                                <li><i class="flaticon-check"></i> 15 Employee Records</li>
                                <li><i class="flaticon-check"></i> Priority Email & Chat Support</li>
                                <li class="disabled"><i class="flaticon-check"></i> White-Label Option</li>
                                <li class="disabled"><i class="flaticon-check"></i> API Access</li>
                            </ul>
                            <a href="#" class="ul-btn">Get Started</a>
                        </div>
                    </div>

                    <!-- Business Plan -->
                    <div class="col-lg-4 wow animate__fadeInUp">
                        <div class="pricing-card">
                            <div class="pricing-header">
                                <h3>Business</h3>
                                <div class="price">₹1,99,999 <small>/ year</small></div>
                                <div class="price-sub">+18% GST applicable</div>
                            </div>
                            <div class="text-center mb-3">
                                <span class="tech-badge">Unlimited Users</span>
                                <span class="tech-badge">Unlimited Project Sites</span>
                            </div>
                            <ul class="pricing-features">
                                <li><i class="flaticon-check"></i> All Professional Features</li>
                                <li><i class="flaticon-check"></i> Unlimited Employee Records</li>
                                <li><i class="flaticon-check"></i> Advanced Analytics Dashboard</li>
                                <li><i class="flaticon-check"></i> Custom Report Builder</li>
                                <li><i class="flaticon-check"></i> API Access</li>
                                <li><i class="flaticon-check"></i> Dedicated Account Manager</li>
                                <li><i class="flaticon-check"></i> 24/7 Priority Support</li>
                                <li><i class="flaticon-check"></i> On-site Training (1 session)</li>
                            </ul>
                            <a href="#" class="ul-btn ul-btn-outline">Get Started</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ENTERPRISE / WHITE-LABEL SECTION -->
        <section class="ul-section-spacing">
            <div class="ul-container">
                <div class="enterprise-card wow animate__fadeInUp">
                    <h2 class="text-white mb-3">Enterprise & White-Label Solutions</h2>
                    <p class="text-white opacity-75 mb-4" style="max-width: 700px; margin: 0 auto;">For large construction groups and software vendors who want to resell TEK-C under their own brand</p>
                    <div class="row justify-content-center mt-4">
                        <div class="col-md-4 mb-3">
                            <div class="bg-white bg-opacity-20 rounded-3 p-3">
                                <i class="flaticon-buildings fs-1 text-white"></i>
                                <p class="text-white mt-2 mb-0">Complete Source Code</p>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="bg-white bg-opacity-20 rounded-3 p-3">
                                <i class="flaticon-user fs-1 text-white"></i>
                                <p class="text-white mt-2 mb-0">Unlimited Users & Sites</p>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="bg-white bg-opacity-20 rounded-3 p-3">
                                <i class="flaticon-list-1 fs-1 text-white"></i>
                                <p class="text-white mt-2 mb-0">Custom Feature Development</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="#" class="ul-btn-white ul-btn px-4 py-2 rounded-3">Contact Sales for Custom Quote</a>
                    </div>
                    <p class="text-white opacity-50 mt-3 small">* White-label license starts at ₹4,99,999/year</p>
                </div>
            </div>
        </section>

        <!-- COMPARISON TABLE -->
        <section class="ul-section-spacing">
            <div class="ul-container">
                <div class="ul-section-heading text-center justify-content-center wow animate__fadeInUp">
                    <div>
                        <span class="ul-section-sub-title">Compare Plans</span>
                        <h2 class="ul-section-title">Detailed Feature Comparison</h2>
                    </div>
                </div>
                <div class="table-responsive wow animate__fadeInUp">
                    <table class="table table-bordered" style="background: white; border-radius: 16px; overflow: hidden;">
                        <thead style="background: #1e3c72; color: white;">
                            <tr>
                                <th style="padding: 1rem;">Feature</th>
                                <th style="padding: 1rem; text-align: center;">Starter</th>
                                <th style="padding: 1rem; text-align: center;">Professional</th>
                                <th style="padding: 1rem; text-align: center;">Business</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><strong>Sites & Contracts Management</strong></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td></tr>
                            <tr><td><strong>DPR, DAR, MPT Reports</strong></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td></tr>
                            <tr><td><strong>Document Management</strong></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td></tr>
                            <tr><td><strong>Quotation Management</strong></td><td class="text-center"><i class="flaticon-close text-danger"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td></tr>
                            <tr><td><strong>RFI & AIT Trackers</strong></td><td class="text-center"><i class="flaticon-close text-danger"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td></tr>
                            <tr><td><strong>MOM & MAS Reports</strong></td><td class="text-center"><i class="flaticon-close text-danger"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td></tr>
                            <tr><td><strong>HR & Attendance Module</strong></td><td class="text-center"><i class="flaticon-close text-danger"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td></tr>
                            <tr><td><strong>Employee Records Limit</strong></td><td class="text-center">5</td><td class="text-center">15</td><td class="text-center">Unlimited</td></tr>
                            <tr><td><strong>Project Sites Limit</strong></td><td class="text-center">1</td><td class="text-center">5</td><td class="text-center">Unlimited</td></tr>
                            <tr><td><strong>API Access</strong></td><td class="text-center"><i class="flaticon-close text-danger"></i></td><td class="text-center"><i class="flaticon-close text-danger"></i></td><td class="text-center"><i class="flaticon-check text-success"></i></td></tr>
                            <tr><td><strong>White-Label Option</strong></td><td class="text-center"><i class="flaticon-close text-danger"></i></td><td class="text-center"><i class="flaticon-close text-danger"></i></td><td class="text-center"><i class="flaticon-close text-danger"></i></td></tr>
                            <tr><td><strong>Support Level</strong></td><td class="text-center">Email</td><td class="text-center">Priority Email & Chat</td><td class="text-center">24/7 Priority + Dedicated Manager</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ADD-ONS SECTION -->
        <section class="ul-section-spacing" style="background: #f8f9fa;">
            <div class="ul-container">
                <div class="ul-section-heading text-center justify-content-center wow animate__fadeInUp">
                    <div>
                        <span class="ul-section-sub-title">Customize Your Plan</span>
                        <h2 class="ul-section-title">Add-On Modules & Services</h2>
                    </div>
                </div>
                <div class="row g-4">
                    <div class="col-md-4 wow animate__fadeInUp">
                        <div class="module-card text-center">
                            <div class="module-icon mx-auto mb-3"><i class="flaticon-property"></i></div>
                            <h3>Additional Site License</h3>
                            <p class="price">₹25,000 <small>/ site / year</small></p>
                            <p>Add extra project sites beyond your plan limit</p>
                        </div>
                    </div>
                    <div class="col-md-4 wow animate__fadeInUp">
                        <div class="module-card text-center">
                            <div class="module-icon mx-auto mb-3"><i class="flaticon-user"></i></div>
                            <h3>Additional User License</h3>
                            <p class="price">₹5,000 <small>/ user / year</small></p>
                            <p>Add extra team members beyond your plan limit</p>
                        </div>
                    </div>
                    <div class="col-md-4 wow animate__fadeInUp">
                        <div class="module-card text-center">
                            <div class="module-icon mx-auto mb-3"><i class="flaticon-building"></i></div>
                            <h3>On-Premise Deployment</h3>
                            <p class="price">Custom Quote</p>
                            <p>Dedicated server installation with full control</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ SECTION -->
        <section class="ul-section-spacing">
            <div class="ul-container">
                <div class="ul-section-heading text-center justify-content-center wow animate__fadeInUp">
                    <div>
                        <span class="ul-section-sub-title">FAQ</span>
                        <h2 class="ul-section-title">Frequently Asked Questions</h2>
                    </div>
                </div>
                <div class="row justify-content-center">
                    <div class="col-lg-8 wow animate__fadeInUp">
                        <div class="faq-item" onclick="toggleFaq(this)">
                            <div class="faq-question">
                                What is included in the annual subscription? <i class="flaticon-arrow-down"></i>
                            </div>
                            <div class="faq-answer">
                                Our annual subscription includes full access to all modules in your chosen plan, software updates, security patches, and technical support. Hosting is included for cloud plans.
                            </div>
                        </div>
                        <div class="faq-item" onclick="toggleFaq(this)">
                            <div class="faq-question">
                                Can I upgrade my plan later? <i class="flaticon-arrow-down"></i>
                            </div>
                            <div class="faq-answer">
                                Yes, you can upgrade your plan at any time. The upgrade cost will be prorated based on your remaining subscription period.
                            </div>
                        </div>
                        <div class="faq-item" onclick="toggleFaq(this)">
                            <div class="faq-question">
                                Do you offer a free trial or demo? <i class="flaticon-arrow-down"></i>
                            </div>
                            <div class="faq-answer">
                                Yes, we offer a free 14-day trial with full access to all Professional plan features. You can also request a personalized demo with our sales team.
                            </div>
                        </div>
                        <div class="faq-item" onclick="toggleFaq(this)">
                            <div class="faq-question">
                                What is the white-label option? <i class="flaticon-arrow-down"></i>
                            </div>
                            <div class="faq-answer">
                                White-label allows you to rebrand TEK-C as your own product. You get complete source code, branding rights, and can resell to your clients. Contact sales for custom pricing.
                            </div>
                        </div>
                        <div class="faq-item" onclick="toggleFaq(this)">
                            <div class="faq-question">
                                Is data migration support available? <i class="flaticon-arrow-down"></i>
                            </div>
                            <div class="faq-answer">
                                Yes, we provide data migration assistance for Business and Enterprise plans. We can help you migrate from spreadsheets or other software.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA SECTION -->
        <div class="ul-app-ad wow animate__fadeInUp">
            <div class="ul-app-ad-container">
                <div class="ul-app-ad-content">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <div class="ul-app-ad-txt">
                                <span class="ul-section-sub-title">Ready to Get Started?</span>
                                <h2 class="ul-section-title">Start Your <span class="colored">14-Day Free Trial</span> Today</h2>
                                <p class="text-white opacity-75">No credit card required. Full access to all features.</p>
                                <div class="ul-app-ad-btns mt-4">
                                    <a href="#" class="ul-btn me-3"><i class="flaticon-play me-2"></i> Start Free Trial</a>
                                    <a href="#" class="ul-btn ul-btn-outline"><i class="flaticon-call me-2"></i> Talk to Sales</a>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 text-center">
                            <img src="https://placehold.co/200x200/f5a623/ffffff?text=14-Day+Trial" alt="Free Trial" class="img-fluid rounded-3" style="max-width: 180px;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include('includes/footer.php'); ?>
    <?php include('includes/script.php'); ?>

    <script>
        function toggleFaq(element) {
            const answer = element.querySelector('.faq-answer');
            const icon = element.querySelector('.faq-question i');
            answer.classList.toggle('active');
            if (answer.classList.contains('active')) {
                icon.classList.remove('flaticon-arrow-down');
                icon.classList.add('flaticon-arrow-up');
            } else {
                icon.classList.remove('flaticon-arrow-up');
                icon.classList.add('flaticon-arrow-down');
            }
        }
    </script>
</body>
</html>