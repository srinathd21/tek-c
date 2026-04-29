<?php
// about.php - About TEK-C & UKB Construction Management
// Company story, mission, team, and the vision behind the software
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>About Us - TEK-C Construction Management Software</title>

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
    padding: 100px 0 80px;
    color: white;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.page-header::after{
    content:"";
    position:absolute;
    inset:0;
    background: radial-gradient(circle at 70% 20%, rgba(246,173,34,0.12), transparent 50%);
}
.page-header h1{
    font-size: 56px;
    font-weight: 900;
    margin-bottom: 20px;
    position: relative;
    z-index: 2;
}
.page-header p{
    font-size: 20px;
    color: #ddd;
    max-width: 720px;
    margin: 0 auto;
    position: relative;
    z-index: 2;
}

/* MISSION SECTION */
.mission-section{
    padding: 85px 0;
    background: #fff;
}
.mission-box{
    background: #fefaf2;
    border-radius: 32px;
    padding: 48px 40px;
    text-align: center;
    border: 1px solid rgba(246,173,34,0.2);
}
.mission-box h2{
    font-size: 32px;
    font-weight: 900;
    margin-bottom: 20px;
}
.mission-box p{
    font-size: 18px;
    line-height: 1.7;
    color: #444;
    max-width: 800px;
    margin: 0 auto;
}

/* STORY SECTION */
.story-section{
    padding: 0 0 85px 0;
}
.story-img{
    border-radius: 32px;
    width: 100%;
    box-shadow: 0 20px 35px -12px rgba(0,0,0,0.15);
}
.story-content h2{
    font-size: 34px;
    font-weight: 800;
    margin-bottom: 24px;
}
.story-content p{
    font-size: 16px;
    line-height: 1.7;
    color: #555;
    margin-bottom: 20px;
}

/* VALUES */
.values-section{
    background: #f8f9fc;
    padding: 85px 0;
}
.value-card{
    background: white;
    border-radius: 28px;
    padding: 36px 28px;
    height: 100%;
    text-align: center;
    transition: 0.3s;
    border: 1px solid #eee;
}
.value-card:hover{
    transform: translateY(-6px);
    border-color: var(--yellow);
}
.value-icon{
    width: 70px;
    height: 70px;
    background: #fff5e6;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    font-size: 32px;
    color: var(--yellow);
}
.value-card h4{
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 15px;
}
.value-card p{
    color: #666;
    font-size: 14px;
    line-height: 1.6;
}

/* TEAM SECTION */
.team-section{
    padding: 85px 0;
}
.team-card{
    text-align: center;
    background: white;
    border-radius: 28px;
    padding: 32px 20px;
    border: 1px solid #eee;
    transition: 0.3s;
}
.team-card:hover{
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.08);
}
.team-avatar{
    width: 140px;
    height: 140px;
    background: linear-gradient(135deg, #f6ad22, #ffcf7a);
    border-radius: 50%;
    margin: 0 auto 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 58px;
    color: white;
    font-weight: 800;
}
.team-card h4{
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 5px;
}
.team-card .designation{
    color: var(--yellow);
    font-weight: 700;
    font-size: 13px;
    margin-bottom: 12px;
}
.team-card .bio{
    font-size: 13px;
    color: #666;
    padding: 0 12px;
}

/* UKB BADGE */
.ukb-section{
    background: #0a0e12;
    color: white;
    padding: 70px 0;
}
.ukb-logo-large{
    font-size: 64px;
    font-weight: 900;
    line-height: 1.2;
}
.ukb-logo-large span{
    color: var(--yellow);
}

/* CTA */
.cta-about{
    background: linear-gradient(135deg, #f1a51b, #ffc247);
    padding: 70px 0;
    text-align: center;
    color: #111;
}
.cta-about h2{
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
    .page-header h1{font-size: 42px;}
    .mission-box{padding: 32px 24px;}
    .story-content{margin-top: 30px;}
}
@media(max-width:575px){
    .section-title{font-size: 28px;}
    .page-header h1{font-size: 32px;}
    .ukb-logo-large{font-size: 42px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>Built by <span class="text-yellow">builders</span>, for builders.</h1>
        <p>TEK-C was born on construction sites — solving real delays, cost overruns, and execution chaos.</p>
    </div>
</section>

<!-- MISSION STATEMENT -->
<section class="mission-section">
    <div class="container">
        <div class="mission-box" data-aos="fade-up">
            <h2>Our Mission</h2>
            <p>To empower construction professionals with a digital command center that brings <strong class="text-yellow">complete visibility, accountability, and control</strong> to every project — from foundation to handover.</p>
        </div>
    </div>
</section>

<!-- OUR STORY -->
<section class="story-section">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <img src="https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=900&q=80" alt="Construction site story" class="story-img">
            </div>
            <div class="col-lg-6 story-content" data-aos="fade-left">
                <h2>The story behind <span class="text-yellow">TEK-C</span></h2>
                <p>In 2018, our founding team at UKB Construction Management was managing multiple residential and commercial projects simultaneously. We realized that despite having experienced engineers, clear designs, and motivated teams — projects were still facing delays, cost escalations, and miscommunication.</p>
                <p>Updates were scattered across WhatsApp groups. Daily reports were buried in email threads. Approvals took weeks. And there was no single source of truth for project progress.</p>
                <p>We searched for a construction management software that truly understood on-ground realities. When we didn't find one, we decided to build it ourselves. TEK-C was designed by construction professionals, tested on real sites, and refined over 5+ years of live project execution.</p>
                <p><strong>Today, TEK-C powers projects worth over ₹500+ Crores and helps construction teams deliver on time, within budget.</strong></p>
            </div>
        </div>
    </div>
</section>

<!-- CORE VALUES -->
<section class="values-section">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Our core values</h2>
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="value-card">
                    <div class="value-icon"><i class="fa-solid fa-eye"></i></div>
                    <h4>Radical Transparency</h4>
                    <p>Every stakeholder deserves real-time visibility into project health, delays, and costs — no hidden surprises.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="value-card">
                    <div class="value-icon"><i class="fa-solid fa-helmet-safety"></i></div>
                    <h4>Site-First Design</h4>
                    <p>Our software is built for engineers and supervisors on the ground — simple, fast, and practical.</p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="value-card">
                    <div class="value-icon"><i class="fa-solid fa-handshake"></i></div>
                    <h4>Accountability</h4>
                    <p>We believe clarity in roles, tasks, and approvals drives performance. TEK-C makes responsibility visible.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- THE TEAM -->
<section class="team-section">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Leadership & founding team</h2>
        <p class="section-subtitle" data-aos="fade-up">Construction veterans, technology experts, and project management professionals.</p>
        <div class="row g-4">
            <div class="col-lg-3 col-md-6" data-aos="fade-up">
                <div class="team-card">
                    <div class="team-avatar"><i class="fa-solid fa-user-tie"></i></div>
                    <h4>Arun Krishnamurthy</h4>
                    <div class="designation">CEO & Founder</div>
                    <p class="bio">20+ years in construction management. Former Project Director at L&T, led UKB Group operations.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="80">
                <div class="team-card">
                    <div class="team-avatar"><i class="fa-solid fa-chart-line"></i></div>
                    <h4>Priya Mehta</h4>
                    <div class="designation">Chief Product Officer</div>
                    <p class="bio">Civil engineer turned product leader. Passionate about digitizing construction workflows.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="160">
                <div class="team-card">
                    <div class="team-avatar"><i class="fa-solid fa-microchip"></i></div>
                    <h4>Rahul Nair</h4>
                    <div class="designation">CTO</div>
                    <p class="bio">Tech architect with expertise in cloud platforms and real-time data systems.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="240">
                <div class="team-card">
                    <div class="team-avatar"><i class="fa-solid fa-helmet-safety"></i></div>
                    <h4>Suresh Babu</h4>
                    <div class="designation">Head of Operations</div>
                    <p class="bio">15 years of site execution experience. Ensures TEK-C solves real site problems.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- UKB CONSTRUCTION MANAGEMENT BADGE -->
<section class="ukb-section">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-md-5" data-aos="fade-right">
                <div class="ukb-logo-large">
                    <span>UKB</span><br>
                    CONSTRUCTION<br>
                    MANAGEMENT
                </div>
            </div>
            <div class="col-md-7" data-aos="fade-left">
                <h3 style="font-weight:800; margin-bottom:15px;">TEK-C is powered by <span class="text-yellow">UKB Group</span></h3>
                <p style="color:#ccc; font-size:16px; line-height:1.6;">UKB Construction Management has delivered over 2.5 million sq.ft. of residential and commercial projects across India. TEK-C is the result of decades of on-ground experience — turning proven workflows into a powerful software platform.</p>
                <ul class="check-list" style="list-style:none; padding:0; margin-top:20px;">
                    <li style="color:#ddd; margin:10px 0;"><i class="fa-regular fa-circle-check text-yellow me-2"></i> 25+ years of construction excellence</li>
                    <li style="color:#ddd; margin:10px 0;"><i class="fa-regular fa-circle-check text-yellow me-2"></i> 50+ successfully delivered projects</li>
                    <li style="color:#ddd; margin:10px 0;"><i class="fa-regular fa-circle-check text-yellow me-2"></i> Trusted by leading developers & contractors</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- STATS / MILESTONES -->
<section class="values-section" style="background:white;">
    <div class="container">
        <div class="row g-4 text-center">
            <div class="col-md-3 col-6" data-aos="fade-up">
                <h2 class="text-yellow" style="font-size: 44px; font-weight:900;">500+</h2>
                <p>Crores worth projects managed</p>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="50">
                <h2 class="text-yellow" style="font-size: 44px; font-weight:900;">150+</h2>
                <p>Active users across India</p>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="100">
                <h2 class="text-yellow" style="font-size: 44px; font-weight:900;">35+</h2>
                <p>Projects digitalized</p>
            </div>
            <div class="col-md-3 col-6" data-aos="fade-up" data-aos-delay="150">
                <h2 class="text-yellow" style="font-size: 44px; font-weight:900;">98%</h2>
                <p>Client retention rate</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-about">
    <div class="container" data-aos="zoom-in">
        <h2>Ready to transform your construction projects?</h2>
        <p class="mb-4">Join 150+ construction professionals who trust TEK-C for project control.</p>
        <a href="#contact" class="btn btn-dark btn-lg px-5" style="background:#111; border-radius:50px;">Start your free trial →</a>
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

// Active link highlight for about
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    if(link.getAttribute('href') === '#about'){
        link.classList.add('active');
    } else {
        link.classList.remove('active');
    }
});
</script>
</body>
</html>