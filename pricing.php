<?php
// pricing.php - Pricing Plans & Subscription Options
// Complete pricing table, feature comparison, FAQs and CTA
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pricing Plans - TEK-C Construction Management Software</title>

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

.btn-outline-dark-custom{
    border:2px solid #ddd;
    background:transparent;
    border-radius:12px;
    padding:12px 24px;
    font-weight:700;
    transition:.3s;
}
.btn-outline-dark-custom:hover{
    border-color:var(--yellow);
    background:rgba(246,173,34,0.05);
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
    background: linear-gradient(135deg, #0a0d12 0%, #141a22 100%);
    padding: 80px 0 60px;
    color: white;
    text-align: center;
}
.page-header h1{
    font-size: 52px;
    font-weight: 900;
    margin-bottom: 20px;
}
.page-header p{
    font-size: 18px;
    color: #ccc;
    max-width: 680px;
    margin: 0 auto;
}
.toggle-switch{
    background: #1e252e;
    border-radius: 60px;
    display: inline-flex;
    padding: 6px;
    margin-top: 35px;
}
.toggle-option{
    padding: 10px 28px;
    border-radius: 50px;
    font-weight: 700;
    cursor: pointer;
    transition: 0.2s;
    color: #bbb;
}
.toggle-option.active{
    background: var(--yellow);
    color: #111;
}

/* PRICE CARDS */
.price-card{
    background: white;
    border-radius: 32px;
    padding: 40px 28px;
    height: 100%;
    transition: 0.3s;
    border: 1px solid #eee;
    position: relative;
    box-shadow: 0 8px 24px rgba(0,0,0,0.04);
}
.price-card:hover{
    transform: translateY(-8px);
    border-color: rgba(246,173,34,0.5);
    box-shadow: 0 25px 40px -14px rgba(0,0,0,0.12);
}
.price-card.featured{
    border: 2px solid var(--yellow);
    transform: scale(1.02);
    background: linear-gradient(to bottom, #fff, #fffcf5);
}
.popular-tag{
    position: absolute;
    top: -14px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--yellow);
    color: #111;
    font-size: 12px;
    font-weight: 800;
    padding: 5px 18px;
    border-radius: 40px;
}
.price-card h3{
    font-size: 26px;
    font-weight: 800;
}
.price{
    font-size: 44px;
    font-weight: 900;
    color: var(--yellow);
    margin: 20px 0 8px;
}
.price small{
    font-size: 14px;
    font-weight: 400;
    color: #777;
}
.price-card ul{
    list-style: none;
    padding: 0;
    margin: 25px 0;
}
.price-card li{
    margin: 14px 0;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.price-card li i.fa-check{
    color: var(--yellow);
    font-size: 16px;
    width: 20px;
}
.price-card li i.fa-times{
    color: #ccc;
    font-size: 14px;
    width: 20px;
}
.btn-price{
    width: 100%;
    margin-top: 15px;
    border-radius: 40px;
    padding: 14px;
    font-weight: 800;
}

/* COMPARISON TABLE */
.comparison-wrap{
    background: #f8fafc;
    padding: 85px 0;
}
.comparison-table{
    background: white;
    border-radius: 36px;
    overflow-x: auto;
    box-shadow: 0 12px 30px rgba(0,0,0,0.05);
}
.comparison-table table{
    width: 100%;
    border-collapse: collapse;
    min-width: 700px;
}
.comparison-table th{
    background: #111;
    color: white;
    padding: 20px 24px;
    font-weight: 800;
}
.comparison-table td{
    padding: 16px 24px;
    border-bottom: 1px solid #f0f0f0;
}
.comparison-table tr:last-child td{
    border-bottom: none;
}
.comparison-table td:first-child{
    font-weight: 700;
    background: #fefdf9;
}
.yellow-check{
    color: var(--yellow);
    font-size: 18px;
}

/* FAQ */
.faq-section{
    padding: 85px 0;
}
.accordion-item{
    border: none;
    border-bottom: 1px solid #eaeef2;
    background: transparent;
}
.accordion-button{
    background: transparent;
    font-weight: 700;
    font-size: 18px;
    padding: 22px 0;
    box-shadow: none;
}
.accordion-button:not(.collapsed){
    background: transparent;
    color: var(--yellow);
}
.accordion-body{
    padding: 0 0 24px 0;
    color: #555;
}

/* CTA */
.cta-strip{
    background: linear-gradient(135deg,#f1a51b,#ffc247);
    padding: 65px 0;
    text-align: center;
    color: #111;
}
.cta-strip h2{
    font-size: 38px;
    font-weight: 900;
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

@media(max-width:991px){
    body{padding-top:82px;}
    .navbar-nav{
        border-radius:18px;
        margin-top:18px;
        padding:12px;
    }
    .page-header h1{font-size: 38px;}
    .price-card.featured{transform: none;}
}
@media(max-width:575px){
    .section-title{font-size: 28px;}
    .price{font-size: 36px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>Simple, transparent <span class="text-yellow">pricing</span></h1>
        <p>Choose the plan that fits your project scale and team size. All plans include core features with free onboarding support.</p>
        
        <div class="toggle-switch" id="billingToggle">
            <span class="toggle-option active" data-billing="monthly">Monthly Billing</span>
            <span class="toggle-option" data-billing="yearly">Yearly Billing <span class="text-yellow">(Save 18%)</span></span>
        </div>
    </div>
</section>

<!-- PRICING CARDS -->
<section style="padding: 70px 0 40px;">
    <div class="container">
        <div class="row g-5">
            <!-- Starter Plan -->
            <div class="col-lg-4" data-aos="fade-up">
                <div class="price-card">
                    <h3>Starter</h3>
                    <p class="text-muted">For small builders & single site</p>
                    <div class="price monthly-price">₹7,999<span>/month</span></div>
                    <div class="price yearly-price d-none">₹6,559<span>/month <small>(billed yearly)</small></span></div>
                    <ul>
                        <li><i class="fa-regular fa-check"></i> Up to 1 project</li>
                        <li><i class="fa-regular fa-check"></i> Core DPR & Task Management</li>
                        <li><i class="fa-regular fa-check"></i> Basic Document Control</li>
                        <li><i class="fa-regular fa-check"></i> Mobile app access</li>
                        <li><i class="fa-regular fa-check"></i> Email support</li>
                        <li><i class="fa-regular fa-times"></i> Advanced analytics</li>
                        <li><i class="fa-regular fa-times"></i> Multi-project dashboard</li>
                    </ul>
                    <a href="#contact" class="btn btn-outline-dark-custom btn-price">Start Free Trial</a>
                </div>
            </div>

            <!-- Professional Plan (Featured) -->
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="100">
                <div class="price-card featured">
                    <div class="popular-tag">🔥 MOST POPULAR</div>
                    <h3>Professional</h3>
                    <p class="text-muted">For growing teams & multiple projects</p>
                    <div class="price monthly-price">₹18,999<span>/month</span></div>
                    <div class="price yearly-price d-none">₹15,579<span>/month <small>(billed yearly)</small></span></div>
                    <ul>
                        <li><i class="fa-regular fa-check"></i> Up to 5 projects</li>
                        <li><i class="fa-regular fa-check"></i> All Starter features</li>
                        <li><i class="fa-regular fa-check"></i> Cost & Procurement Module</li>
                        <li><i class="fa-regular fa-check"></i> RFI, MOM & Approval workflow</li>
                        <li><i class="fa-regular fa-check"></i> Advanced Document Management</li>
                        <li><i class="fa-regular fa-check"></i> Management Dashboard (KPI)</li>
                        <li><i class="fa-regular fa-check"></i> Priority support</li>
                    </ul>
                    <a href="#contact" class="btn btn-yellow btn-price">Request Demo</a>
                </div>
            </div>

            <!-- Enterprise Plan -->
            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
                <div class="price-card">
                    <h3>Enterprise</h3>
                    <p class="text-muted">For large firms & portfolio management</p>
                    <div class="price monthly-price">Custom</div>
                    <div class="price yearly-price d-none">Custom pricing</div>
                    <ul>
                        <li><i class="fa-regular fa-check"></i> Unlimited projects</li>
                        <li><i class="fa-regular fa-check"></i> Everything in Professional</li>
                        <li><i class="fa-regular fa-check"></i> Role-based access control</li>
                        <li><i class="fa-regular fa-check"></i> Custom reports & analytics</li>
                        <li><i class="fa-regular fa-check"></i> API access & integrations</li>
                        <li><i class="fa-regular fa-check"></i> Dedicated account manager</li>
                        <li><i class="fa-regular fa-check"></i> SLA & 24/7 support</li>
                    </ul>
                    <a href="#contact" class="btn btn-outline-dark-custom btn-price">Contact Sales</a>
                </div>
            </div>
        </div>
        <p class="text-center text-muted mt-5 small">*All plans include 14-day free trial, no credit card required. Onboarding & training included.</p>
    </div>
</section>

<!-- COMPARISON TABLE (Detailed Features) -->
<section class="comparison-wrap">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Compare all features side by side</h2>
        <div class="comparison-table" data-aos="fade-up">
            <table>
                <thead>
                    <tr><th>Feature</th><th>Starter</th><th>Professional</th><th>Enterprise</th></tr>
                </thead>
                <tbody>
                    <tr><td>Number of Projects</td><td>1</td><td>5</td><td>Unlimited</td></tr>
                    <tr><td>Daily Progress Report (DPR)</td><td><i class="fa-regular fa-check yellow-check"></i></td><td><i class="fa-regular fa-check yellow-check"></i></td><td><i class="fa-regular fa-check yellow-check"></i></td></tr>
                    <tr><td>RFI & MOM Management</td><td><i class="fa-regular fa-check yellow-check"></i></td><td><i class="fa-regular fa-check yellow-check"></i></td><td><i class="fa-regular fa-check yellow-check"></i></td></tr>
                    <tr><td>Cost & Procurement Control</td><td>-</td><td><i class="fa-regular fa-check yellow-check"></i></td><td><i class="fa-regular fa-check yellow-check"></i></td></tr>
                    <tr><td>Document Version Control</td><td>Basic</td><td>Advanced with workflow</td><td>Full + Audit trail</td></tr>
                    <tr><td>Workforce Management</td><td>-</td><td><i class="fa-regular fa-check yellow-check"></i></td><td><i class="fa-regular fa-check yellow-check"></i></td></tr>
                    <tr><td>Analytics Dashboard</td><td>Limited</td><td>Full KPIs & Reports</td><td>Custom dashboards</td></tr>
                    <tr><td>API Access</td><td>-</td><td>-</td><td><i class="fa-regular fa-check yellow-check"></i></td></tr>
                    <tr><td>Support Level</td><td>Email</td><td>Priority Chat/Email</td><td>24/7 Dedicated</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- FAQ SECTION -->
<section class="faq-section">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Frequently asked questions</h2>
        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <div class="accordion" id="faqAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">Can I switch plans later?</button></h2>
                        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion"><div class="accordion-body">Absolutely. You can upgrade or downgrade anytime. Changes are prorated automatically.</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">Is there a setup fee?</button></h2>
                        <div id="faq2" class="accordion-collapse collapse"><div class="accordion-body">No setup fees. Onboarding, data migration and training are included free for all paid plans.</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">Do you offer custom enterprise pricing?</button></h2>
                        <div id="faq3" class="accordion-collapse collapse"><div class="accordion-body">Yes, contact our sales team for volume discounts, custom module requirements, or long-term contracts.</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">Is there a free trial available?</button></h2>
                        <div id="faq4" class="accordion-collapse collapse"><div class="accordion-body">Yes, 14-day free trial with full access to Professional features. No credit card required.</div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5">Can I add more users?</button></h2>
                        <div id="faq5" class="accordion-collapse collapse"><div class="accordion-body">All plans include unlimited users. You only pay per project or per organization based on plan.</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA SECTION -->
<section class="cta-strip">
    <div class="container" data-aos="zoom-in">
        <h2>Start your 14-day free trial today</h2>
        <p class="mb-4">Experience TEK-C with your real project data. No commitment, cancel anytime.</p>
        <a href="#contact" class="btn btn-dark btn-lg px-5" style="background:#111; border-radius:50px;">Get Started →</a>
        <p class="mt-3 small">Free onboarding call • No credit card</p>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>

<script>
AOS.init({ duration: 700, once: true });

const navbar = document.getElementById('mainNavbar');
window.addEventListener('scroll', () => {
    if(window.scrollY > 70) navbar.classList.add('nav-fixed');
    else navbar.classList.remove('nav-fixed');
});

// Billing toggle (monthly/yearly)
const monthlyPrices = document.querySelectorAll('.monthly-price');
const yearlyPrices = document.querySelectorAll('.yearly-price');
const toggleOptions = document.querySelectorAll('.toggle-option');

function setBilling(type) {
    if(type === 'monthly') {
        monthlyPrices.forEach(el => el.classList.remove('d-none'));
        yearlyPrices.forEach(el => el.classList.add('d-none'));
    } else {
        monthlyPrices.forEach(el => el.classList.add('d-none'));
        yearlyPrices.forEach(el => el.classList.remove('d-none'));
    }
    toggleOptions.forEach(opt => {
        if((type === 'monthly' && opt.dataset.billing === 'monthly') || (type === 'yearly' && opt.dataset.billing === 'yearly')) {
            opt.classList.add('active');
        } else {
            opt.classList.remove('active');
        }
    });
}

toggleOptions.forEach(opt => {
    opt.addEventListener('click', () => {
        setBilling(opt.dataset.billing);
    });
});

// active highlight for pricing link
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    if(link.getAttribute('href') === '#pricing'){
        link.classList.add('active');
    }
});
</script>
</body>
</html>