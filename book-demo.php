<?php
// book-demo.php - Book a Live Demo Page
// Dedicated page for scheduling personalized product demonstrations
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book a Live Demo - TEK-C Construction Management Software</title>

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

.text-yellow{
    color:var(--yellow);
}

.btn-yellow{
    background:linear-gradient(135deg,var(--yellow),var(--yellow2));
    color:#111;
    font-weight:800;
    border:none;
    border-radius:12px;
    padding:14px 32px;
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
    padding:12px 28px;
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
    background: linear-gradient(115deg, #0a0e12 0%, #161c24 100%);
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

/* DEMO FORM SECTION */
.demo-section{
    padding: 80px 0;
    background: #f8fafc;
}
.demo-card{
    background: white;
    border-radius: 32px;
    padding: 48px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.05);
}
.demo-card h2{
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 15px;
}
.form-control, .form-select{
    border-radius: 14px;
    padding: 14px 18px;
    border: 1px solid #e2e8f0;
    font-size: 15px;
}
.form-control:focus, .form-select:focus{
    border-color: var(--yellow);
    box-shadow: 0 0 0 3px rgba(246,173,34,0.2);
}
.btn-submit{
    background: linear-gradient(135deg,var(--yellow),var(--yellow2));
    border: none;
    border-radius: 14px;
    padding: 16px 32px;
    font-weight: 800;
    color: #111;
    width: 100%;
    font-size: 16px;
}
.btn-submit:hover{
    transform: translateY(-2px);
}

/* BENEFITS SECTION */
.benefits-section{
    padding: 60px 0;
    background: white;
}
.benefit-item{
    text-align: center;
    padding: 20px;
}
.benefit-icon{
    width: 70px;
    height: 70px;
    background: #fff5e6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 32px;
    color: var(--yellow);
}
.benefit-item h4{
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 10px;
}
.benefit-item p{
    color: #666;
    font-size: 14px;
}

/* WHAT TO EXPECT */
.expect-section{
    background: #111;
    color: white;
    padding: 70px 0;
}
.expect-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 30px;
    margin-top: 40px;
}
.expect-item{
    text-align: center;
    padding: 20px;
}
.expect-item i{
    font-size: 40px;
    color: var(--yellow);
    margin-bottom: 20px;
}
.expect-item h4{
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 12px;
}
.expect-item p{
    color: #ccc;
    font-size: 14px;
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
    .demo-card{padding: 28px;}
}
@media(max-width:575px){
    .section-title{font-size: 28px;}
    .page-header h1{font-size: 30px;}
    .demo-card h2{font-size: 24px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>See <span class="text-yellow">TEK-C</span> in action</h1>
        <p>Book a personalized 30-minute live demo with our construction software experts. See how TEK-C can transform your project execution.</p>
    </div>
</section>

<!-- DEMO FORM SECTION -->
<section class="demo-section">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-7" data-aos="fade-right">
                <div class="demo-card">
                    <h2>Schedule your live demo</h2>
                    <p class="text-muted mb-4">Fill out the form below and our team will reach out to confirm your preferred time slot.</p>
                    
                    <form action="submit_demo.php" method="POST" id="demoForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Full Name *</label>
                                <input type="text" name="fullname" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Designation / Role</label>
                                <input type="text" name="designation" class="form-control" placeholder="e.g., Project Manager, Owner">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email Address *</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Phone Number *</label>
                                <input type="tel" name="phone" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Company / Organization *</label>
                                <input type="text" name="company" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Number of active projects</label>
                                <select class="form-select" name="project_count">
                                    <option>1-2 projects</option>
                                    <option>3-5 projects</option>
                                    <option>6-10 projects</option>
                                    <option>10+ projects</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Preferred date *</label>
                                <input type="date" name="preferred_date" class="form-control" id="demoDate" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Preferred time slot</label>
                                <select class="form-select" name="time_slot">
                                    <option>10:00 AM - 11:00 AM IST</option>
                                    <option>11:30 AM - 12:30 PM IST</option>
                                    <option>2:00 PM - 3:00 PM IST</option>
                                    <option>3:30 PM - 4:30 PM IST</option>
                                    <option>5:00 PM - 6:00 PM IST</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">What would you like to see in the demo? *</label>
                                <textarea rows="3" name="demo_focus" class="form-control" placeholder="e.g., Daily reporting, cost tracking, workforce management, document approvals..." required></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="agreeCheck" required>
                                    <label class="form-check-label small" for="agreeCheck">
                                        I agree to receive communications from TEK-C regarding this demo and related software updates.
                                    </label>
                                </div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-submit"><i class="fa-regular fa-calendar-check me-2"></i> Schedule Demo</button>
                            </div>
                            <div class="col-12">
                                <small class="text-muted">* We respect your privacy. Your information will only be used to schedule the demo.</small>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="col-lg-5" data-aos="fade-left">
                <div class="demo-card" style="background: linear-gradient(135deg, #fff9f0, white); border: 2px solid rgba(246,173,34,0.2);">
                    <h3><i class="fa-regular fa-clock text-yellow me-2"></i> What to expect</h3>
                    <ul class="list-unstyled mt-4">
                        <li class="mb-3"><i class="fa-regular fa-circle-check text-yellow me-2"></i> <strong>30-min live walkthrough</strong> – See real dashboard and modules</li>
                        <li class="mb-3"><i class="fa-regular fa-circle-check text-yellow me-2"></i> <strong>Tailored to your needs</strong> – Focus on modules relevant to your projects</li>
                        <li class="mb-3"><i class="fa-regular fa-circle-check text-yellow me-2"></i> <strong>Q&A with expert</strong> – Get answers from construction domain specialists</li>
                        <li class="mb-3"><i class="fa-regular fa-circle-check text-yellow me-2"></i> <strong>Pricing & custom quote</strong> – Understand plans that fit your scale</li>
                        <li><i class="fa-regular fa-circle-check text-yellow me-2"></i> <strong>Free trial access</strong> – Get 14-day trial after demo</li>
                    </ul>
                    <hr class="my-4">
                    <div class="text-center">
                        <i class="fa-solid fa-headset fs-1 text-yellow mb-2"></i>
                        <p class="mb-0 small">Have questions before booking?<br><a href="tel:+9118001234567" class="fw-bold text-dark">Call +91 1800 123 4567</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- BENEFITS SECTION -->
<section class="benefits-section">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Why book a demo with <span class="text-yellow">TEK-C</span>?</h2>
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="benefit-item">
                    <div class="benefit-icon"><i class="fa-solid fa-user-helmet-safety"></i></div>
                    <h4>Construction Experts</h4>
                    <p>Your demo will be led by someone who understands construction workflows, not just software.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="benefit-item">
                    <div class="benefit-icon"><i class="fa-solid fa-chart-line"></i></div>
                    <h4>Real Project Data</h4>
                    <p>See TEK-C working with actual construction project scenarios and data.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="benefit-item">
                    <div class="benefit-icon"><i class="fa-regular fa-building"></i></div>
                    <h4>Tailored for Your Scale</h4>
                    <p>Whether you're a small builder or large developer, we'll show relevant features.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- WHAT TO EXPECT SECTION -->
<section class="expect-section">
    <div class="container">
        <h2 class="section-title text-white" data-aos="fade-up">What happens after booking?</h2>
        <div class="expect-grid">
            <div class="expect-item" data-aos="fade-up">
                <i class="fa-regular fa-envelope"></i>
                <h4>Step 1: Confirmation</h4>
                <p>You'll receive an email confirmation within 2 hours with the scheduled time.</p>
            </div>
            <div class="expect-item" data-aos="fade-up" data-aos-delay="100">
                <i class="fa-regular fa-calendar-check"></i>
                <h4>Step 2: Reminder</h4>
                <p>A reminder with Google Meet/Teams link will be sent 1 hour before demo.</p>
            </div>
            <div class="expect-item" data-aos="fade-up" data-aos-delay="200">
                <i class="fa-regular fa-comments"></i>
                <h4>Step 3: Live Demo</h4>
                <p>30-min interactive session + Q&A with our construction software specialist.</p>
            </div>
            <div class="expect-item" data-aos="fade-up" data-aos-delay="300">
                <i class="fa-regular fa-rocket"></i>
                <h4>Step 4: Trial Access</h4>
                <p>14-day free trial activated immediately after demo.</p>
            </div>
        </div>
    </div>
</section>

<!-- TRUST BADGES -->
<section style="padding: 50px 0; background: white;">
    <div class="container text-center">
        <p class="text-muted mb-3">Trusted by construction firms across India</p>
        <div class="d-flex flex-wrap justify-content-center gap-4 align-items-center">
            <span class="fw-bold text-secondary">🏗️ UKB Group</span>
            <span class="fw-bold text-secondary">🏢 L&T Alumni</span>
            <span class="fw-bold text-secondary">🏘️ CREDAI Member</span>
            <span class="fw-bold text-secondary">📐 500+ Crores Projects</span>
        </div>
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

// Set minimum date to today
const today = new Date().toISOString().split('T')[0];
document.getElementById('demoDate').setAttribute('min', today);

// Form submission handler
document.getElementById('demoForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    alert('Thank you for requesting a demo! Our team will contact you within 24 hours to confirm your preferred time slot.');
    this.reset();
    // Reset date min again
    document.getElementById('demoDate').value = '';
});
</script>
</body>
</html>