<?php
// modules.php - Complete Modules Breakdown Page
// Displays all TEK-C modules in an organized, detailed tabular & card format
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Software Modules - TEK-C Construction Management</title>

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

.section-title{
    font-size:34px;
    font-weight:900;
    text-align:center;
    margin-bottom:20px;
    line-height:1.2;
}

.section-subtitle{
    max-width:760px;
    margin:0 auto 48px;
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
    background: linear-gradient(115deg, #0a0e12 0%, #12181f 100%);
    padding: 85px 0 65px;
    color: white;
    text-align: center;
    position: relative;
}
.page-header h1{
    font-size: 54px;
    font-weight: 900;
    margin-bottom: 18px;
}
.page-header p{
    font-size: 19px;
    color: #ccc;
    max-width: 700px;
    margin: 0 auto;
}

/* MODULES GRID (Cards) */
.modules-grid{
    padding: 80px 0;
    background: #fefefe;
}
.module-card{
    background: white;
    border-radius: 28px;
    padding: 36px 28px;
    height: 100%;
    transition: all 0.35s ease;
    border: 1px solid #efefef;
    box-shadow: 0 12px 28px -10px rgba(0,0,0,0.06);
    position: relative;
    overflow: hidden;
}
.module-card:hover{
    transform: translateY(-10px);
    border-color: rgba(246,173,34,0.4);
    box-shadow: 0 25px 40px -15px rgba(0,0,0,0.15);
}
.module-icon{
    font-size: 52px;
    color: var(--yellow);
    margin-bottom: 22px;
}
.module-card h3{
    font-size: 24px;
    font-weight: 800;
    margin-bottom: 18px;
}
.module-card p{
    color: #555;
    font-size: 15px;
    line-height: 1.55;
    margin-bottom: 20px;
}
.module-features{
    list-style: none;
    padding: 0;
    margin: 20px 0 0;
    border-top: 1px dashed #e2e2e2;
    padding-top: 18px;
}
.module-features li{
    font-size: 13px;
    margin: 10px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.module-features li i{
    color: var(--yellow);
    font-size: 14px;
    width: 18px;
}
.badge-module{
    background: #fff4e4;
    color: #b45f06;
    font-size: 11px;
    font-weight: 800;
    padding: 5px 12px;
    border-radius: 40px;
    display: inline-block;
    margin-bottom: 15px;
}

/* MODULE DETAIL TABLE (deep dive) */
.module-deep{
    background: #f8f9fc;
    padding: 80px 0;
}
.deep-card{
    background: white;
    border-radius: 32px;
    padding: 38px;
    box-shadow: 0 8px 28px rgba(0,0,0,0.05);
    margin-bottom: 35px;
}
.deep-card h2{
    font-size: 28px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 25px;
    border-left: 6px solid var(--yellow);
    padding-left: 20px;
}
.deep-card h2 i{
    color: var(--yellow);
    font-size: 32px;
}
.deep-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px,1fr));
    gap: 24px;
    margin-top: 20px;
}
.deep-item{
    background: #fefef7;
    border-radius: 20px;
    padding: 20px;
    border: 1px solid #f0e9dd;
}
.deep-item h4{
    font-weight: 800;
    font-size: 18px;
    margin-bottom: 12px;
}
.deep-item p{
    font-size: 14px;
    color: #5a5a5a;
    margin: 0;
}

/* ROADMAP */
.roadmap{
    padding: 75px 0;
    background: #111;
    color: white;
}
.roadmap h2{
    font-size: 34px;
    font-weight: 800;
}
.roadmap-timeline{
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 25px;
    margin-top: 50px;
}
.roadmap-item{
    flex: 1;
    background: #1c2128;
    border-radius: 28px;
    padding: 28px;
    border-left: 5px solid var(--yellow);
}
.roadmap-item h4{
    font-weight: 800;
    font-size: 20px;
    margin-bottom: 12px;
}
.roadmap-item p{
    color: #bcbcbc;
    font-size: 14px;
}

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

@media (max-width:991px){
    body{padding-top:82px;}
    .navbar-nav{
        border-radius:18px;
        margin-top:18px;
        padding:12px;
    }
    .page-header h1{font-size: 38px;}
    .deep-card{padding: 24px;}
}

@media (max-width:575px){
    .page-header h1{font-size: 30px;}
    .section-title{font-size: 28px;}
    .modules-grid{padding: 50px 0;}
    .module-card{padding: 24px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>Modular by design.<br>Complete by <span class="text-yellow">functionality</span>.</h1>
        <p>TEK-C is built as an integrated suite of modules that work together seamlessly — from site reporting to procurement, HR, and management dashboards.</p>
    </div>
</section>

<!-- MODULES OVERVIEW (Cards) -->
<section class="modules-grid">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Every module, purpose-built for construction</h2>
        <div class="row g-4">
            <!-- Module 1 -->
            <div class="col-lg-4 col-md-6" data-aos="fade-up">
                <div class="module-card">
                    <div class="badge-module">CORE</div>
                    <div class="module-icon"><i class="fa-regular fa-clipboard"></i></div>
                    <h3>Daily Reporting</h3>
                    <p>DPR, MOM, RFI, site images, delay logs — all captured in a structured digital format.</p>
                    <ul class="module-features">
                        <li><i class="fa-regular fa-circle-check"></i> Daily Progress Report</li>
                        <li><i class="fa-regular fa-circle-check"></i> Minutes of Meeting</li>
                        <li><i class="fa-regular fa-circle-check"></i> RFI tracking & closure</li>
                    </ul>
                </div>
            </div>
            <!-- Module 2 -->
            <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="80">
                <div class="module-card">
                    <div class="badge-module">FINANCE</div>
                    <div class="module-icon"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                    <h3>Cost & Procurement</h3>
                    <p>Monitor budgets, purchase orders, vendor payments, and material reconciliation.</p>
                    <ul class="module-features">
                        <li><i class="fa-regular fa-circle-check"></i> Budget vs Actual</li>
                        <li><i class="fa-regular fa-circle-check"></i> Vendor management</li>
                        <li><i class="fa-regular fa-circle-check"></i> Purchase order workflow</li>
                    </ul>
                </div>
            </div>
            <!-- Module 3 -->
            <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="160">
                <div class="module-card">
                    <div class="badge-module">DOCUMENT</div>
                    <div class="module-icon"><i class="fa-regular fa-folder-open"></i></div>
                    <h3>Document Management</h3>
                    <p>Version controlled drawings, contracts, BOQ, and approval workflow.</p>
                    <ul class="module-features">
                        <li><i class="fa-regular fa-circle-check"></i> Centralized library</li>
                        <li><i class="fa-regular fa-circle-check"></i> Multi-level approvals</li>
                        <li><i class="fa-regular fa-circle-check"></i> Audit trail</li>
                    </ul>
                </div>
            </div>
            <!-- Module 4 -->
            <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="0">
                <div class="module-card">
                    <div class="badge-module">HR & LABOUR</div>
                    <div class="module-icon"><i class="fa-solid fa-users"></i></div>
                    <h3>Workforce Management</h3>
                    <p>Attendance, contractor workforce, payroll, and site productivity tracking.</p>
                    <ul class="module-features">
                        <li><i class="fa-regular fa-circle-check"></i> Biometric/mobile attendance</li>
                        <li><i class="fa-regular fa-circle-check"></i> Labour costing</li>
                        <li><i class="fa-regular fa-circle-check"></i> Contractor billing</li>
                    </ul>
                </div>
            </div>
            <!-- Module 5 -->
            <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="80">
                <div class="module-card">
                    <div class="badge-module">EXECUTION</div>
                    <div class="module-icon"><i class="fa-regular fa-square-check"></i></div>
                    <h3>Site Task Management</h3>
                    <p>Task breakdown, assignment, progress tracking, and delay root cause.</p>
                    <ul class="module-features">
                        <li><i class="fa-regular fa-circle-check"></i> WBS mapping</li>
                        <li><i class="fa-regular fa-circle-check"></i> Daily checklists</li>
                        <li><i class="fa-regular fa-circle-check"></i> Planned vs Actual</li>
                    </ul>
                </div>
            </div>
            <!-- Module 6 -->
            <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="160">
                <div class="module-card">
                    <div class="badge-module">ANALYTICS</div>
                    <div class="module-icon"><i class="fa-solid fa-chart-line"></i></div>
                    <h3>Dashboard & Reports</h3>
                    <p>Real-time KPI, project health, risk alerts, and exportable reports.</p>
                    <ul class="module-features">
                        <li><i class="fa-regular fa-circle-check"></i> Management cockpit</li>
                        <li><i class="fa-regular fa-circle-check"></i> Custom analytics</li>
                        <li><i class="fa-regular fa-circle-check"></i> Delay prediction</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- DEEP DIVE: Detailed module breakdown -->
<section class="module-deep">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Deep dive into module capabilities</h2>
        <p class="section-subtitle" data-aos="fade-up">Every module includes granular features designed for real-world construction challenges</p>

        <div class="deep-card" data-aos="fade-up">
            <h2><i class="fa-regular fa-clipboard"></i> Daily Reporting Module</h2>
            <div class="deep-grid">
                <div class="deep-item"><h4>DPR Generation</h4><p>Auto-populated daily progress with photos, quantity achieved, manpower, and delays.</p></div>
                <div class="deep-item"><h4>Minutes of Meeting</h4><p>Structured MOM with action items, responsibility, and follow-up reminders.</p></div>
                <div class="deep-item"><h4>RFI Workflow</h4><p>Request for Information raised by site, attached drawings, auto-escalation.</p></div>
                <div class="deep-item"><h4>Delay Logging</h4><p>Categorize delay reasons (material, labour, design, client) with time impact.</p></div>
            </div>
        </div>

        <div class="deep-card" data-aos="fade-up">
            <h2><i class="fa-solid fa-indian-rupee-sign"></i> Cost & Procurement Module</h2>
            <div class="deep-grid">
                <div class="deep-item"><h4>Budget Tracking</h4><p>Project budget broken down by work package, real-time variance alerts.</p></div>
                <div class="deep-item"><h4>Purchase Orders</h4><p>Create, approve, track POs against materials received (GRN).</p></div>
                <div class="deep-item"><h4>Vendor Payment</h4><p>Link invoices to POs, track due payments, maintain vendor ledger.</p></div>
                <div class="deep-item"><h4>Material Reconciliation</h4><p>Compare issued vs consumed material, identify theft or wastage.</p></div>
            </div>
        </div>

        <div class="deep-card" data-aos="fade-up">
            <h2><i class="fa-regular fa-folder-open"></i> Document & Approval Module</h2>
            <div class="deep-grid">
                <div class="deep-item"><h4>Drawing Register</h4><p>Version control for GFC, shop drawings, as-built with review status.</p></div>
                <div class="deep-item"><h4>Contract & BOQ</h4><p>Store contracts, track BOQ revisions, link to budget vs actual.</p></div>
                <div class="deep-item"><h4>Approval Matrix</h4><p>Role-based approval flow for RFI, change orders, invoices.</p></div>
                <div class="deep-item"><h4>Audit Trail</h4><p>Complete history of who accessed, modified, approved documents.</p></div>
            </div>
        </div>

        <div class="deep-card" data-aos="fade-up">
            <h2><i class="fa-solid fa-users"></i> Workforce & HR Module</h2>
            <div class="deep-grid">
                <div class="deep-item"><h4>Attendance Tracking</h4><p>Mobile check-in / biometric integration with site-wise muster.</p></div>
                <div class="deep-item"><h4>Labour Skill Mapping</h4><p>Categorize workers by skill (mason, steel fixer, carpenter) and allocation.</p></div>
                <div class="deep-item"><h4>Payroll Generation</h4><p>Auto calculate wages based on attendance, overtime, statutory deductions.</p></div>
                <div class="deep-item"><h4>Contractor Workforce</h4><p>Track agency-supplied labour, compliance documents, billing.</p></div>
            </div>
        </div>

        <div class="deep-card" data-aos="fade-up">
            <h2><i class="fa-regular fa-square-check"></i> Site Task Management</h2>
            <div class="deep-grid">
                <div class="deep-item"><h4>WBS & Activities</h4><p>Work breakdown structure from project schedule to daily tasks.</p></div>
                <div class="deep-item"><h4>Task Assignment</h4><p>Assign to engineer/supervisor with deadline, priority and checklist.</p></div>
                <div class="deep-item"><h4>Progress Updating</h4><p>Mobile-based percent complete, photos, comments for each task.</p></div>
                <div class="deep-item"><h4>Delay Analysis</h4><p>Root cause tagging, automatic delay reporting to management.</p></div>
            </div>
        </div>

        <div class="deep-card" data-aos="fade-up">
            <h2><i class="fa-solid fa-chart-line"></i> Analytics & Dashboard</h2>
            <div class="deep-grid">
                <div class="deep-item"><h4>Project Health Score</h4><p>Red/Amber/Green indicator based on cost, schedule, quality.</p></div>
                <div class="deep-item"><h4>Budget vs Actual Dashboard</h4><p>Graphical view of cost overrun at package level.</p></div>
                <div class="deep-item"><h4>Custom Report Builder</h4><p>Drag-drop KPIs, schedule PDF/Excel reports via email.</p></div>
                <div class="deep-item"><h4>Risk Register</h4><p>Identify, log and track mitigation actions for project risks.</p></div>
            </div>
        </div>
    </div>
</section>

<!-- ROADMAP -->
<section class="roadmap">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-4" data-aos="fade-right">
                <h2>What's next on <span class="text-yellow">TEK-C</span> roadmap?</h2>
                <p>We’re constantly adding new features based on feedback from construction partners.</p>
                <a href="#contact" class="btn btn-yellow mt-3">Suggest a feature</a>
            </div>
            <div class="col-lg-8" data-aos="fade-left">
                <div class="roadmap-timeline">
                    <div class="roadmap-item"><h4>Q3 2025</h4><p>BIM integration · 3D model viewer</p></div>
                    <div class="roadmap-item"><h4>Q4 2025</h4><p>AI-based delay prediction · Automated report generation</p></div>
                    <div class="roadmap-item"><h4>Q1 2026</h4><p>Equipment & machinery tracking · IoT sensor integration</p></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="modules-grid" style="background:#fff8f0; padding: 60px 0;">
    <div class="container text-center">
        <h2 class="section-title" data-aos="fade-up">Need a custom module for your specific workflow?</h2>
        <p class="section-subtitle" data-aos="fade-up">TEK-C is fully customizable. We can tailor any module or build new ones based on your SOPs.</p>
        <div data-aos="fade-up">
            <a href="#contact" class="btn btn-yellow btn-lg">Talk to our team →</a>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>

<script>
AOS.init({ duration: 700, once: true });

const navbar = document.getElementById('mainNavbar');
window.addEventListener('scroll', function(){
    if(window.scrollY > 70){
        navbar.classList.add('nav-fixed');
    }else{
        navbar.classList.remove('nav-fixed');
    }
});

// active highlight for modules link
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    if(link.getAttribute('href') === '#modules'){
        link.classList.add('active');
    } else if(link.getAttribute('href') !== '#'){
        // optional
    }
});
</script>
</body>
</html>