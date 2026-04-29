<?php
// features.php - Detailed Features Page
// Includes full structure, animations, and content consistent with index.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Platform Features - TEK-C Construction Management Software</title>

<?php include 'includes/link.php'; ?>

<style>
:root{
    --yellow:#f6ad22;
    --yellow2:#ffc247;
    --dark:#080b0d;
    --black:#050607;
    --text:#111;
    --muted:#666;
    --line:#e8e8e8;
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

html{
    scroll-behavior:smooth;
    scroll-padding-top:105px;
}

body{
    font-family:'Inter',sans-serif;
    color:var(--text);
    background:#fff;
    overflow-x:hidden;
    padding-top:88px;
}

a{
    text-decoration:none;
}

.section-title{
    font-size:34px;
    font-weight:900;
    text-align:center;
    margin-bottom:34px;
    line-height:1.2;
}

.section-subtitle{
    max-width:760px;
    margin:0 auto 38px;
    text-align:center;
    color:#666;
    font-size:16px;
    line-height:1.7;
}

.text-yellow{
    color:var(--yellow);
}

.btn-yellow{
    background:linear-gradient(135deg,var(--yellow),var(--yellow2));
    color:#111;
    font-weight:800;
    border:none;
    border-radius:12px;
    padding:14px 28px;
    box-shadow:0 10px 25px rgba(246,173,34,.35);
    transition:.35s;
}

.btn-yellow:hover{
    transform:translateY(-3px);
    color:#111;
}

/* NAVBAR */
.navbar{
    position:fixed;
    top:0;
    left:0;
    width:100%;
    z-index:999;
    padding:14px 0;
    background:rgba(5,7,9,.96);
    backdrop-filter:blur(16px);
    box-shadow:0 8px 30px rgba(0,0,0,.28);
    transition:.35s ease;
}

.navbar.nav-fixed{
    padding:10px 0;
    background:rgba(5,7,9,.98);
    box-shadow:0 8px 30px rgba(0,0,0,.38);
}

.logo{
    display:flex;
    align-items:center;
    gap:12px;
    color:#fff;
}

.logo-icon{
    width:48px;
    height:48px;
    background:linear-gradient(135deg,#ffbe35,#e79510);
    clip-path:polygon(50% 0,100% 35%,85% 35%,50% 15%,15% 35%,0 35%);
}

.logo-text h3{
    margin:0;
    color:var(--yellow);
    font-size:32px;
    font-weight:900;
    letter-spacing:.5px;
}

.logo-text span{
    display:block;
    color:#fff;
    font-size:10px;
    margin-top:-6px;
    letter-spacing:.8px;
}

.navbar-nav{
    background:rgba(255,255,255,.07);
    border:1px solid rgba(255,255,255,.1);
    border-radius:50px;
    padding:7px;
    backdrop-filter:blur(12px);
}

.navbar-nav .nav-link{
    color:#fff;
    font-size:14px;
    font-weight:700;
    margin:0 2px;
    padding:10px 16px !important;
    border-radius:50px;
    transition:.3s;
}

.navbar-nav .nav-link:hover,
.navbar-nav .nav-link.active{
    color:#111;
    background:linear-gradient(135deg,var(--yellow),var(--yellow2));
    box-shadow:0 7px 18px rgba(246,173,34,.25);
}

/* PAGE HEADER */
.page-header{
    background: linear-gradient(120deg, #0b0f13 0%, #1a1f25 100%);
    padding: 80px 0 60px;
    color: white;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.page-header::after{
    content:"";
    position:absolute;
    inset:0;
    background: radial-gradient(circle at 20% 30%, rgba(246,173,34,0.12), transparent 40%);
    pointer-events: none;
}
.page-header h1{
    font-size: 52px;
    font-weight: 900;
    margin-bottom: 20px;
}
.page-header p{
    font-size: 20px;
    color: #ddd;
    max-width: 720px;
    margin: 0 auto;
}

/* FEATURE DETAIL BLOCKS */
.feature-block{
    padding: 90px 0;
    border-bottom: 1px solid #f0f0f0;
}
.feature-block:last-child{
    border-bottom: none;
}
.feature-icon-large{
    font-size: 64px;
    color: var(--yellow);
    margin-bottom: 24px;
}
.feature-block h2{
    font-size: 36px;
    font-weight: 800;
    margin-bottom: 25px;
}
.feature-list{
    list-style: none;
    padding: 0;
    margin-top: 28px;
}
.feature-list li{
    margin-bottom: 16px;
    font-size: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.feature-list li i{
    color: var(--yellow);
    font-size: 18px;
    width: 24px;
}
.benefit-badge{
    background: #f8f8f8;
    border-radius: 40px;
    padding: 5px 16px;
    font-size: 13px;
    font-weight: 700;
    display: inline-block;
    margin-bottom: 16px;
    color: var(--yellow);
    border: 1px solid rgba(246,173,34,0.3);
}
.feature-image{
    border-radius: 28px;
    box-shadow: 0 25px 45px -12px rgba(0,0,0,0.25);
    width: 100%;
    transition: transform 0.4s ease;
}
.feature-image:hover{
    transform: scale(1.02);
}

/* COMPARISON TABLE */
.comparison{
    background: #fafafc;
    padding: 80px 0;
}
.comparison h2{
    font-size: 34px;
    font-weight: 800;
    margin-bottom: 48px;
}
.comparison-table{
    background: white;
    border-radius: 32px;
    overflow: hidden;
    box-shadow: 0 20px 35px -12px rgba(0,0,0,0.1);
}
.comparison-table table{
    width: 100%;
    border-collapse: collapse;
}
.comparison-table th,
.comparison-table td{
    padding: 20px 24px;
    text-align: left;
    border-bottom: 1px solid #eee;
}
.comparison-table th{
    background: #111;
    color: white;
    font-weight: 800;
    font-size: 18px;
}
.comparison-table tr:last-child td{
    border-bottom: none;
}
.comparison-table td:first-child{
    font-weight: 700;
    background: #fef9ef;
}
.comparison-table td i.fa-check-circle{
    color: var(--yellow);
    font-size: 20px;
}
.comparison-table td i.fa-times-circle{
    color: #bbb;
    font-size: 20px;
}

/* INTEGRATIONS */
.integrations{
    padding: 80px 0;
}
.integration-grid{
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 30px;
    margin-top: 50px;
}
.integration-item{
    background: white;
    border: 1px solid #eee;
    border-radius: 28px;
    padding: 30px 36px;
    text-align: center;
    width: 180px;
    transition: all 0.25s ease;
}
.integration-item:hover{
    transform: translateY(-8px);
    border-color: var(--yellow);
    box-shadow: 0 20px 30px -12px rgba(0,0,0,0.12);
}
.integration-item i{
    font-size: 44px;
    color: #222;
    margin-bottom: 18px;
}
.integration-item span{
    display: block;
    font-weight: 700;
}

/* CTA BANNER */
.cta-banner{
    background: linear-gradient(135deg, #080b0d, #161c22);
    color: white;
    padding: 70px 0;
    text-align: center;
}
.cta-banner h3{
    font-size: 36px;
    font-weight: 800;
    margin-bottom: 20px;
}
.cta-banner .btn-yellow{
    margin-top: 15px;
}

/* FOOTER */
.footer{
    background:#07090b;
    color:#fff;
    padding:65px 0 20px;
}
.footer h5{
    font-size:14px;
    font-weight:900;
    margin-bottom:18px;
}
.footer a{
    display:block;
    color:#d6d6d6;
    font-size:13px;
    margin:10px 0;
}
.footer a:hover{
    color:var(--yellow);
}
.social a{
    display:inline-flex;
    width:34px;
    height:34px;
    align-items:center;
    justify-content:center;
    background:#1b2025;
    border-radius:50%;
    margin-right:8px;
}
.footer-bottom{
    border-top:1px solid #222;
    margin-top:32px;
    padding-top:18px;
    font-size:13px;
    color:#bbb;
}

@media(max-width:991px){
    body{padding-top:82px;}
    .navbar-nav{
        border-radius:18px;
        margin-top:18px;
        padding:12px;
    }
    .page-header h1{font-size: 38px;}
    .feature-block h2{font-size: 28px;}
    .comparison-table th, .comparison-table td{padding: 12px 16px;}
}
@media(max-width:575px){
    .page-header h1{font-size: 32px;}
    .feature-block{padding: 55px 0;}
    .integration-item{width: 140px; padding: 18px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>Deep dive into <span class="text-yellow">TEK-C</span> capabilities</h1>
        <p>Explore every module built to bring structure, visibility, and performance to your construction projects — from site to head office.</p>
    </div>
</section>

<!-- FEATURE: Daily Reporting System -->
<section class="feature-block" id="daily-reporting">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2" data-aos="fade-left">
                <div class="benefit-badge"><i class="fa-regular fa-newspaper me-1"></i> Daily transparency</div>
                <h2>Daily Progress Reporting (DPR)</h2>
                <p>Stop relying on scattered WhatsApp messages and manual excel sheets. TEK-C brings discipline to daily reporting with real-time structured updates from every project.</p>
                <ul class="feature-list">
                    <li><i class="fa-solid fa-circle-check"></i> Digital DPR with photo/video evidence</li>
                    <li><i class="fa-solid fa-circle-check"></i> Minutes of Meeting (MOM) with action points</li>
                    <li><i class="fa-solid fa-circle-check"></i> Request For Information (RFI) tracking & closure</li>
                    <li><i class="fa-solid fa-circle-check"></i> Daily labor & material consumption logs</li>
                    <li><i class="fa-solid fa-circle-check"></i> Automated delay alerts to management</li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1" data-aos="fade-right">
                <img src="https://images.unsplash.com/photo-1581291518633-83b4ebd1d83e?auto=format&fit=crop&w=800&q=80" alt="Daily reporting dashboard" class="feature-image">
            </div>
        </div>
    </div>
</section>

<!-- FEATURE: Cost & Procurement -->
<section class="feature-block" style="background:#fbfbfb;">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <div class="benefit-badge"><i class="fa-solid fa-indian-rupee-sign me-1"></i> Financial control</div>
                <h2>Cost & Procurement Control</h2>
                <p>Visibility over where every rupee is spent. From material indent to vendor billing, track budgets versus actuals in real time.</p>
                <ul class="feature-list">
                    <li><i class="fa-solid fa-circle-check"></i> Budget vs actual cost analysis</li>
                    <li><i class="fa-solid fa-circle-check"></i> Purchase orders & GRN matching</li>
                    <li><i class="fa-solid fa-circle-check"></i> Vendor-wise payment tracking</li>
                    <li><i class="fa-solid fa-circle-check"></i> Material reconciliation with site stock</li>
                    <li><i class="fa-solid fa-circle-check"></i> Automated cost overrun alerts</li>
                </ul>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <img src="https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?auto=format&fit=crop&w=800&q=80" alt="Procurement dashboard" class="feature-image">
            </div>
        </div>
    </div>
</section>

<!-- FEATURE: Document & Approval Management -->
<section class="feature-block">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2" data-aos="fade-left">
                <div class="benefit-badge"><i class="fa-regular fa-folder-open me-1"></i> Centralized library</div>
                <h2>Document Management & Approvals</h2>
                <p>No more lost drawings or outdated revision chaos. Secure, version-controlled document system with structured approval workflows.</p>
                <ul class="feature-list">
                    <li><i class="fa-solid fa-circle-check"></i> GFC drawings, BOQ, specifications library</li>
                    <li><i class="fa-solid fa-circle-check"></i> RFI, NCR, change order lifecycle</li>
                    <li><i class="fa-solid fa-circle-check"></i> Role-based document access (Engineer/PM/Client)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Multi-level approval system</li>
                    <li><i class="fa-solid fa-circle-check"></i> Audit trail for every document version</li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1" data-aos="fade-right">
                <img src="https://images.unsplash.com/photo-1568992687947-868a62a9f521?auto=format&fit=crop&w=800&q=80" alt="Document management" class="feature-image">
            </div>
        </div>
    </div>
</section>

<!-- FEATURE: Workforce & HR -->
<section class="feature-block" style="background:#fbfbfb;">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <div class="benefit-badge"><i class="fa-solid fa-users me-1"></i> Workforce efficiency</div>
                <h2>Workforce & HR Module</h2>
                <p>Track attendance, manage labours, staff, sub-contractor workers and link productivity to project progress.</p>
                <ul class="feature-list">
                    <li><i class="fa-solid fa-circle-check"></i> Biometric / mobile attendance integration</li>
                    <li><i class="fa-solid fa-circle-check"></i> Labour skill-wise grouping & allocation</li>
                    <li><i class="fa-solid fa-circle-check"></i> Payroll generation & statutory compliance</li>
                    <li><i class="fa-solid fa-circle-check"></i> Contractor workforce tracking</li>
                    <li><i class="fa-solid fa-circle-check"></i> Site-wise manpower cost dashboard</li>
                </ul>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <img src="https://images.unsplash.com/photo-1581091226033-d5c48150dbaa?auto=format&fit=crop&w=800&q=80" alt="Workforce management" class="feature-image">
            </div>
        </div>
    </div>
</section>

<!-- FEATURE: Site & Task Management -->
<section class="feature-block">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6 order-lg-2" data-aos="fade-left">
                <div class="benefit-badge"><i class="fa-regular fa-square-check me-1"></i> Execution control</div>
                <h2>Site Task Management</h2>
                <p>Break down work packages into tasks, assign to engineers, monitor progress percentage and identify bottlenecks instantly.</p>
                <ul class="feature-list">
                    <li><i class="fa-solid fa-circle-check"></i> Work breakdown structure (WBS) mapping</li>
                    <li><i class="fa-solid fa-circle-check"></i> Task assignment with deadline & priority</li>
                    <li><i class="fa-solid fa-circle-check"></i> Real-time progress tracking (planned vs actual)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Delay reason logging and root cause analysis</li>
                    <li><i class="fa-solid fa-circle-check"></i> Daily checklist for site supervisors</li>
                </ul>
            </div>
            <div class="col-lg-6 order-lg-1" data-aos="fade-right">
                <img src="https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=800&q=80" alt="Task management" class="feature-image">
            </div>
        </div>
    </div>
</section>

<!-- FEATURE: Dashboard & Analytics -->
<section class="feature-block" style="background:#fbfbfb;">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <div class="benefit-badge"><i class="fa-solid fa-chart-line me-1"></i> Real-time insights</div>
                <h2>Management Dashboard & Analytics</h2>
                <p>Get bird's eye view of all projects — health scores, critical delays, resource allocation and financial variance at a glance.</p>
                <ul class="feature-list">
                    <li><i class="fa-solid fa-circle-check"></i> Project health traffic light indicator</li>
                    <li><i class="fa-solid fa-circle-check"></i> Cost performance index (CPI) & schedule variance</li>
                    <li><i class="fa-solid fa-circle-check"></i> Custom reports & exportable PDF/Excel</li>
                    <li><i class="fa-solid fa-circle-check"></i> Risk register & predictive alerts</li>
                    <li><i class="fa-solid fa-circle-check"></i> Mobile-first interface for management on-the-go</li>
                </ul>
            </div>
            <div class="col-lg-6" data-aos="fade-left">
                <img src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=800&q=80" alt="Analytics dashboard" class="feature-image">
            </div>
        </div>
    </div>
</section>

<!-- COMPARISON TABLE: Traditional vs TEK-C -->
<section class="comparison">
    <div class="container">
        <div class="text-center" data-aos="fade-up">
            <h2>TEK-C vs Traditional Methods</h2>
            <p class="section-subtitle">Why leading construction firms are switching to a structured digital command center</p>
        </div>
        <div class="comparison-table" data-aos="fade-up">
            <table>
                <thead>
                    <tr><th>Parameter</th><th>Traditional / WhatsApp / Excel</th><th>TEK-C Platform</th></tr>
                </thead>
                <tbody>
                    <tr><td>Daily reporting</td><td><i class="fa-regular fa-circle"></i> Scattered, manual, no trace</td><td><i class="fa-regular fa-check-circle"></i> Structured DPR with auto logs</td></tr>
                    <tr><td>Document version control</td><td><i class="fa-regular fa-circle"></i> Email attachments, confusion</td><td><i class="fa-regular fa-check-circle"></i> Centralized with audit trail</td></tr>
                    <tr><td>Cost tracking</td><td><i class="fa-regular fa-circle"></i> Monthly surprise overruns</td><td><i class="fa-regular fa-check-circle"></i> Real-time budget vs actual</td></tr>
                    <tr><td>Approval workflow</td><td><i class="fa-regular fa-circle"></i> Delays, follow-ups on chat</td><td><i class="fa-regular fa-check-circle"></i> Automated multilevel approvals</td></tr>
                    <tr><td>Task progress visibility</td><td><i class="fa-regular fa-circle"></i> Weekly meeting sync only</td><td><i class="fa-regular fa-check-circle"></i> Live progress from site</td></tr>
                    <tr><td>Site workforce data</td><td><i class="fa-regular fa-circle"></i> Manual muster, errors</td><td><i class="fa-regular fa-check-circle"></i> Digital attendance & productivity</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- INTEGRATIONS SECTION -->
<section class="integrations">
    <div class="container">
        <div class="text-center" data-aos="fade-up">
            <h2 class="section-title">Seamless Integrations</h2>
            <p class="section-subtitle">Connect TEK-C with tools you already use — accounting, storage, communication</p>
        </div>
        <div class="integration-grid" data-aos="fade-up">
            <div class="integration-item"><i class="fa-brands fa-google-drive"></i><span>Drive</span></div>
            <div class="integration-item"><i class="fa-regular fa-file-excel"></i><span>Excel export</span></div>
            <div class="integration-item"><i class="fa-brands fa-whatsapp"></i><span>WhatsApp alerts</span></div>
            <div class="integration-item"><i class="fa-solid fa-cloud-arrow-up"></i><span>Cloud backup</span></div>
            <div class="integration-item"><i class="fa-regular fa-envelope"></i><span>Email reports</span></div>
            <div class="integration-item"><i class="fa-solid fa-calculator"></i><span>Tally (soon)</span></div>
        </div>
    </div>
</section>

<!-- CTA BANNER -->
<section class="cta-banner">
    <div class="container" data-aos="zoom-in">
        <h3>Ready to bring <span class="text-yellow">TEK-C</span> to your projects?</h3>
        <p>Get a personalized walkthrough by our construction domain experts.</p>
        <a href="#contact" class="btn btn-yellow btn-lg">Request Live Demo →</a>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
<script>
AOS.init({ duration: 800, once: true });

const navbar = document.getElementById('mainNavbar');
window.addEventListener('scroll', function(){
    if(window.scrollY > 70){
        navbar.classList.add('nav-fixed');
    }else{
        navbar.classList.remove('nav-fixed');
    }
});

// Active link highlighting (optional)
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    if(link.getAttribute('href') === '#features'){
        link.classList.add('active');
    } else {
        link.classList.remove('active');
    }
});
</script>
</body>
</html>