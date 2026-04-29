<!-- Updated full index.html -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TEK-C Construction Management Software</title>

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

section{
    position:relative;
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

.btn-outline-light-custom{
    border:1px solid rgba(246,173,34,.85);
    color:#fff;
    font-weight:700;
    border-radius:12px;
    padding:14px 28px;
    transition:.35s;
    background:rgba(255,255,255,.04);
    backdrop-filter:blur(8px);
}

.btn-outline-light-custom:hover{
    background:var(--yellow);
    color:#111;
}

/* NAVBAR - FIXED, NOT MERGED WITH CONTENT */
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

/* HERO */
.hero{
    min-height:720px;
    background:
    linear-gradient(90deg,rgba(5,7,9,.96) 0%,rgba(5,7,9,.92) 38%,rgba(5,7,9,.65) 70%,rgba(5,7,9,.95) 100%),
    url('https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=1800&q=80') center/cover;
    padding:105px 0 95px;
    color:#fff;
    overflow:hidden;
}

.hero:after{
    content:"";
    position:absolute;
    inset:0;
    background:radial-gradient(circle at 72% 32%,rgba(246,173,34,.16),transparent 28%);
    pointer-events:none;
}

.hero-content{
    position:relative;
    z-index:2;
}

.hero h1{
    font-size:62px;
    font-weight:900;
    line-height:1.08;
    margin-bottom:28px;
}

.hero p{
    font-size:20px;
    line-height:1.75;
    color:#f3f3f3;
    max-width:640px;
}

.hero-points{
    display:flex;
    flex-wrap:wrap;
    gap:18px;
    margin:36px 0;
}

.hero-points div{
    color:#f4f4f4;
    font-size:14px;
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.12);
    padding:10px 14px;
    border-radius:50px;
}

.hero-points i{
    color:var(--yellow);
    margin-right:8px;
}

.hero-buttons{
    display:flex;
    gap:18px;
    flex-wrap:wrap;
}

.dashboard-device{
    background:#111;
    border:5px solid #dedede;
    border-radius:26px;
    padding:18px;
    position:relative;
    box-shadow:0 35px 90px rgba(0,0,0,.65);
    transform:perspective(900px) rotateY(-4deg);
    max-width:100%;
}

.dashboard-screen{
    background:#f7f7f7;
    border-radius:14px;
    overflow:hidden;
    width:100%;
    display:block;
}

img.dashboard-screen{
    width:100%;
    height:auto;
    object-fit:contain;
}

.phone{
    position:absolute;
    right:-42px;
    bottom:-32px;
    width:138px;
    background:#fff;
    border:6px solid #111;
    border-radius:26px;
    padding:14px;
    color:#111;
    box-shadow:0 20px 45px rgba(0,0,0,.45);
}

.phone h6{
    font-size:12px;
    font-weight:900;
}

.phone-line{
    height:8px;
    background:#eee;
    margin:10px 0;
    border-radius:6px;
}

.phone-line:nth-child(odd){
    width:80%;
}

.laptop-base{
    height:15px;
    width:80%;
    background:#cfcfcf;
    margin:0 auto;
    border-radius:0 0 28px 28px;
}

/* PROBLEM */
.problem{
    padding:85px 0 70px;
}

.problem-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:16px;
    text-align:center;
    padding:34px 20px;
    height:100%;
    box-shadow:0 8px 24px rgba(0,0,0,.08);
    transition:.35s;
}

.problem-card:hover{
    transform:translateY(-8px);
    border-color:rgba(246,173,34,.5);
}

.icon-circle{
    width:70px;
    height:70px;
    border-radius:50%;
    background:#fff2d8;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:0 auto 18px;
    font-size:30px;
    color:#111;
}

.problem-card h5{
    font-size:16px;
    font-weight:900;
    margin-bottom:10px;
}

.problem-card p{
    font-size:13px;
    color:#666;
    margin:0;
    line-height:1.6;
}

/* SOLUTION */
.solution{
    background:#fafafa;
    padding:95px 0;
    overflow:hidden;
}

.solution:after{
    content:"";
    position:absolute;
    right:0;
    bottom:0;
    width:55%;
    height:100%;
    background:url('https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80') center/cover;
    opacity:.08;
}

.solution h2{
    font-size:42px;
    font-weight:900;
    line-height:1.12;
    margin-bottom:25px;
}

.solution p{
    color:#555;
    line-height:1.7;
}

.check-list{
    list-style:none;
    padding:0;
    margin:24px 0;
}

.check-list li{
    margin:14px 0;
    font-size:15px;
    font-weight:600;
    color:#333;
}

.check-list i{
    color:var(--yellow);
    margin-right:10px;
}

.solution-img{
    position:relative;
    z-index:2;
}

/* DARK STRIP */
.dark-strip{
    background:linear-gradient(90deg,#07090b,#11161a,#07090b);
    color:#fff;
    padding:75px 0;
}

.dark-strip h2{
    font-size:36px;
    font-weight:900;
    line-height:1.2;
}

.dark-strip p{
    color:#d7d7d7;
    line-height:1.7;
}

.diff-item{
    text-align:center;
    color:#fff;
    border-left:1px solid rgba(246,173,34,.35);
    min-height:145px;
    padding:22px 18px;
}

.diff-item i{
    font-size:38px;
    color:var(--yellow);
    margin-bottom:16px;
}

.diff-item h5{
    font-size:15px;
    line-height:1.5;
    font-weight:700;
}

/* FEATURES */
.features{
    padding:90px 0 85px;
}

.feature-card{
    border:1px solid var(--line);
    border-radius:16px;
    background:#fff;
    padding:28px;
    min-height:165px;
    display:flex;
    gap:18px;
    align-items:flex-start;
    box-shadow:0 7px 22px rgba(0,0,0,.06);
    transition:.35s;
}

.feature-card:hover{
    transform:translateY(-7px);
    border-color:rgba(246,173,34,.5);
}

.feature-card i{
    font-size:38px;
    color:#111;
    min-width:42px;
}

.feature-card h5{
    font-size:16px;
    font-weight:900;
    margin-bottom:10px;
}

.feature-card p{
    font-size:13px;
    color:#555;
    margin:0;
    line-height:1.6;
}

/* PREVIEW */
.preview{
    background:#f8f8f8;
    padding:95px 0;
}

.preview h2{
    font-size:34px;
    font-weight:900;
    line-height:1.25;
    margin-bottom:18px;
}

.preview p{
    color:#555;
    line-height:1.7;
}

.impact-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:16px;
    padding:32px 15px;
    text-align:center;
    height:100%;
    box-shadow:0 7px 22px rgba(0,0,0,.06);
}

.impact-card i{
    font-size:36px;
    margin-bottom:15px;
}

.impact-card h3{
    color:var(--yellow);
    font-size:38px;
    font-weight:900;
    margin:0 0 6px;
}

.impact-card p{
    margin:0;
    font-size:14px;
    font-weight:700;
    color:#333;
}

/* ROLES */
.roles{
    padding:90px 0;
}

.role-card{
    border:1px solid var(--line);
    border-radius:16px;
    padding:36px 24px;
    text-align:center;
    height:100%;
    box-shadow:0 7px 22px rgba(0,0,0,.06);
    transition:.35s;
}

.role-card:hover{
    transform:translateY(-7px);
    border-color:rgba(246,173,34,.5);
}

.role-card i{
    font-size:48px;
    margin-bottom:18px;
}

.role-card h5{
    font-size:16px;
    font-weight:900;
    margin-bottom:10px;
}

.role-card p{
    font-size:13px;
    margin:0;
    color:#555;
    line-height:1.6;
}

/* AUTHORITY */
.authority{
    padding:90px 0;
    background:#fff;
}

.ukb-box{
    background:#f8f8f8;
    border-radius:18px;
    padding:34px 20px;
    height:100%;
    min-height:260px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px solid var(--line);
}

.ukb-logo{
    font-size:34px;
    font-weight:900;
    line-height:1.1;
}

.project-grid{
    display:grid;
    grid-template-columns:1.2fr 1fr 1fr;
    gap:12px;
}

.project-grid img{
    width:100%;
    height:135px;
    object-fit:cover;
    border-radius:14px;
    box-shadow:0 8px 22px rgba(0,0,0,.12);
}

.project-grid img:first-child{
    grid-row:span 2;
    height:282px;
}

/* PRICING */
.pricing{
    padding:90px 0;
    background:#f8f8f8;
}

.price-card{
    background:#fff;
    border:1px solid var(--line);
    border-radius:18px;
    padding:34px;
    height:100%;
    box-shadow:0 8px 26px rgba(0,0,0,.07);
    transition:.35s;
}

.price-card:hover{
    transform:translateY(-7px);
}

.price-card.featured{
    border:2px solid var(--yellow);
    transform:scale(1.03);
}

.price-card h4{
    font-size:22px;
    font-weight:900;
}

.price-card h2{
    font-size:38px;
    font-weight:900;
    color:var(--yellow);
    margin:18px 0;
}

.price-card ul{
    list-style:none;
    padding:0;
    margin:22px 0;
}

.price-card li{
    margin:12px 0;
    font-size:14px;
    color:#444;
}

.price-card li i{
    color:var(--yellow);
    margin-right:8px;
}

/* CTA */
.final-cta{
    background:linear-gradient(135deg,#f1a51b,#ffc247);
    color:#111;
    padding:75px 0;
    overflow:hidden;
}

.final-cta h2{
    font-size:40px;
    font-weight:900;
    margin-bottom:14px;
}

.final-cta p{
    font-size:18px;
    margin-bottom:28px;
}

.btn-dark-custom{
    background:#101214;
    color:#fff;
    border-radius:12px;
    padding:14px 30px;
    font-weight:800;
}

.btn-dark-custom:hover{
    background:#000;
    color:#fff;
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

.product-box{
    border-left:1px solid #333;
    padding-left:40px;
}

.footer-bottom{
    border-top:1px solid #222;
    margin-top:32px;
    padding-top:18px;
    font-size:13px;
    color:#bbb;
}

/* ANIMATIONS */
.float{
    animation:float 4s ease-in-out infinite;
}

@keyframes float{
    0%,100%{transform:translateY(0)}
    50%{transform:translateY(-12px)}
}

.pulse{
    animation:pulse 2s infinite;
}

@keyframes pulse{
    0%{box-shadow:0 0 0 0 rgba(246,173,34,.6)}
    70%{box-shadow:0 0 0 18px rgba(246,173,34,0)}
    100%{box-shadow:0 0 0 0 rgba(246,173,34,0)}
}

/* RESPONSIVE */
@media(max-width:991px){
    body{
        padding-top:82px;
    }

    .navbar{
        padding:12px 0;
    }

    .navbar-nav{
        border-radius:18px;
        margin-top:18px;
        padding:12px;
    }

    .navbar-nav .nav-link{
        margin:4px 0;
    }

    .hero{
        padding-top:80px;
        min-height:auto;
    }

    .hero h1{
        font-size:42px;
    }

    .dashboard-device{
        margin-top:45px;
        transform:none;
    }

    .phone{
        right:10px;
    }

    .solution:after{
        display:none;
    }

    .solution h2{
        font-size:34px;
    }

    .product-box{
        border-left:none;
        padding-left:0;
        margin-top:25px;
    }

    .price-card.featured{
        transform:none;
    }
}

@media(max-width:575px){
    body{
        padding-top:78px;
    }

    .logo-text h3{
        font-size:20px;
    }

    .logo-icon{
        width:42px;
        height:42px;
    }

    .hero h1{
        font-size:34px;
    }

    .hero p{
        font-size:16px;
    }

    .section-title{
        font-size:26px;
    }

    .phone{
        display:none;
    }

    .project-grid{
        grid-template-columns:1fr;
    }

    .project-grid img:first-child,
    .project-grid img{
        height:210px;
    }

    .hero-buttons .btn{
        width:100%;
    }

    .problem,
    .solution,
    .features,
    .preview,
    .roles,
    .authority,
    .pricing{
        padding:65px 0;
    }

    .final-cta h2{
        font-size:30px;
    }
}
</style>
</head>

<body>

<?php include 'includes/nav.php'; ?>

<section class="hero" id="home">
    <div class="container hero-content">
        <div class="row align-items-center g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <h1>Control the <span class="text-yellow">Process.</span><br>Command the <span class="text-yellow">Project.</span></h1>
                <p>Construction Management Software built for real sites, live tracking, daily reporting, approvals, procurement, workforce and project control.</p>

                <div class="hero-points">
                    <div><i class="fa-solid fa-shield-halved"></i> Real-time Tracking</div>
                    <div><i class="fa-solid fa-users"></i> Better Collaboration</div>
                    <div><i class="fa-solid fa-indian-rupee-sign"></i> Cost Control</div>
                    <div><i class="fa-solid fa-square-check"></i> On-time Delivery</div>
                </div>

                <div class="hero-buttons">
                    <a href="#contact" class="btn btn-yellow pulse"><i class="fa-regular fa-calendar me-2"></i> Book Live Demo <i class="fa-solid fa-arrow-right ms-2"></i></a>
                    <a href="#dashboard" class="btn btn-outline-light-custom">See Dashboard <i class="fa-regular fa-circle-play ms-2"></i></a>
                </div>
            </div>

            <div class="col-lg-6" data-aos="zoom-in">
                <div class="dashboard-device float">
                    <img src="assets/dashboard.png" class="dashboard-screen" alt="Dashboard">
                    <div class="phone">
                        <h6>Daily Reports</h6>
                        <div class="phone-line"></div>
                        <div class="phone-line"></div>
                        <div class="phone-line"></div>
                        <div class="phone-line"></div>
                    </div>
                </div>
                <div class="laptop-base"></div>
            </div>
        </div>
    </div>
</section>

<section class="problem">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Construction Projects Don’t Fail in Design... They Fail in Execution</h2>

        <div class="row g-4">
            <div class="col-lg col-md-4 col-6" data-aos="fade-up">
                <div class="problem-card">
                    <div class="icon-circle"><i class="fa-brands fa-whatsapp"></i></div>
                    <h5>Updates lost in WhatsApp groups</h5>
                    <p>Important information gets buried and missed.</p>
                </div>
            </div>

            <div class="col-lg col-md-4 col-6" data-aos="fade-up" data-aos-delay="80">
                <div class="problem-card">
                    <div class="icon-circle"><i class="fa-regular fa-clock"></i></div>
                    <h5>Delays due to unclear instructions</h5>
                    <p>No clear communication leads to rework.</p>
                </div>
            </div>

            <div class="col-lg col-md-4 col-6" data-aos="fade-up" data-aos-delay="160">
                <div class="problem-card">
                    <div class="icon-circle"><i class="fa-regular fa-eye-slash"></i></div>
                    <h5>No visibility on real progress</h5>
                    <p>Difficult to track actual site progress.</p>
                </div>
            </div>

            <div class="col-lg col-md-4 col-6" data-aos="fade-up" data-aos-delay="240">
                <div class="problem-card">
                    <div class="icon-circle"><i class="fa-solid fa-indian-rupee-sign"></i></div>
                    <h5>Cost overruns from poor tracking</h5>
                    <p>Uncontrolled expenses eat into profits.</p>
                </div>
            </div>

            <div class="col-lg col-md-4 col-12" data-aos="fade-up" data-aos-delay="320">
                <div class="problem-card">
                    <div class="icon-circle"><i class="fa-solid fa-people-group"></i></div>
                    <h5>Confusion in vendor coordination</h5>
                    <p>No clarity on approvals and responsibilities.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="solution" id="about">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-5" data-aos="fade-right">
                <h2>Introducing <span class="text-yellow">TEK-C</span> —<br>Your Construction<br>Command Center</h2>
                <ul class="check-list">
                    <li><i class="fa-solid fa-circle-check"></i> Real-time site tracking & updates</li>
                    <li><i class="fa-solid fa-circle-check"></i> Structured DPR, MOM & RFI management</li>
                    <li><i class="fa-solid fa-circle-check"></i> Clear approval workflows</li>
                    <li><i class="fa-solid fa-circle-check"></i> Cost & procurement control</li>
                    <li><i class="fa-solid fa-circle-check"></i> Team accountability at every level</li>
                </ul>
                <p>From planning to handover — everything in one powerful and simple system.</p>
            </div>

            <div class="col-lg-7 solution-img" data-aos="fade-left">
                <div class="dashboard-device">
                    <img src="assets/report.png" class="dashboard-screen" alt="Report">
                </div>
                <div class="laptop-base"></div>
            </div>
        </div>
    </div>
</section>

<section class="dark-strip">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-3" data-aos="fade-right">
                <h2>Not Built by Coders.<br><span class="text-yellow">Built by Builders.</span></h2>
                <p>Developed from real construction project workflows and execution challenges.</p>
            </div>

            <div class="col-lg-9">
                <div class="row g-0">
                    <div class="col-md-3 col-6 diff-item" data-aos="zoom-in">
                        <i class="fa-solid fa-building-shield"></i>
                        <h5>Manage residential & commercial projects</h5>
                    </div>
                    <div class="col-md-3 col-6 diff-item" data-aos="zoom-in" data-aos-delay="100">
                        <i class="fa-solid fa-eye-slash"></i>
                        <h5>Solve real site visibility problems</h5>
                    </div>
                    <div class="col-md-3 col-6 diff-item" data-aos="zoom-in" data-aos-delay="200">
                        <i class="fa-solid fa-crosshairs"></i>
                        <h5>Convert proven workflows into digital control</h5>
                    </div>
                    <div class="col-md-3 col-6 diff-item" data-aos="zoom-in" data-aos-delay="300">
                        <i class="fa-solid fa-house-lock"></i>
                        <h5>Practical, reliable and results-driven</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="features" id="features">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Everything You Need to Run a Project — In One Place</h2>

        <div class="row g-4">
            <div class="col-lg-3 col-md-6" data-aos="fade-up">
                <div class="feature-card">
                    <i class="fa-regular fa-clipboard"></i>
                    <div>
                        <h5>Daily Reporting System</h5>
                        <p>DPR, MOM, RFI and site reports documented clearly.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="80">
                <div class="feature-card">
                    <i class="fa-solid fa-indian-rupee-sign"></i>
                    <div>
                        <h5>Cost & Procurement Control</h5>
                        <p>Vendor tracking, budget visibility and cost analytics.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="160">
                <div class="feature-card">
                    <i class="fa-regular fa-folder-open"></i>
                    <div>
                        <h5>Document Management</h5>
                        <p>Version control, approval tracking and secure access.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="240">
                <div class="feature-card">
                    <i class="fa-solid fa-users"></i>
                    <div>
                        <h5>Workforce & HR</h5>
                        <p>Attendance, leave, payroll and workforce productivity.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up">
                <div class="feature-card">
                    <i class="fa-regular fa-square-check"></i>
                    <div>
                        <h5>Site & Task Management</h5>
                        <p>Task allocation, progress tracking and checklists.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="80">
                <div class="feature-card">
                    <i class="fa-solid fa-chart-column"></i>
                    <div>
                        <h5>Management Dashboard</h5>
                        <p>Real-time insights, delays, risk alerts and reports.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="160">
                <div class="feature-card">
                    <i class="fa-solid fa-microphone-lines"></i>
                    <div>
                        <h5>RFI, MOM & Approvals</h5>
                        <p>Structured approvals with complete transparency.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="240">
                <div class="feature-card">
                    <i class="fa-solid fa-mobile-screen-button"></i>
                    <div>
                        <h5>Mobile App Access</h5>
                        <p>Access your projects anytime from your mobile.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="preview" id="dashboard">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-3" data-aos="fade-right">
                <h2>See Your Project — Live & Structured</h2>
                <p>One platform. All project information at your fingertips.</p>
                <ul class="check-list">
                    <li><i class="fa-solid fa-check"></i> Live progress tracking</li>
                    <li><i class="fa-solid fa-check"></i> Real-time dashboards</li>
                    <li><i class="fa-solid fa-check"></i> Instant decision support</li>
                </ul>
                <a href="#contact" class="btn btn-yellow">Explore Dashboard <i class="fa-solid fa-arrow-right ms-2"></i></a>
            </div>

            <div class="col-lg-5" data-aos="zoom-in">
                <div class="dashboard-device">
                    <img src="assets/progress.png" class="dashboard-screen" alt="Progress">
                </div>
            </div>

            <div class="col-lg-4" data-aos="fade-left">
                <h2 class="text-center mb-4">Real Impact. Real Results.</h2>
                <div class="row g-4">
                    <div class="col-6">
                        <div class="impact-card">
                            <i class="fa-regular fa-clock"></i>
                            <h3>30%</h3>
                            <p>Faster Decisions</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="impact-card">
                            <i class="fa-solid fa-chart-line"></i>
                            <h3>20%</h3>
                            <p>Cost Savings</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="impact-card">
                            <i class="fa-regular fa-calendar-check"></i>
                            <h3>15%</h3>
                            <p>Delay Reduction</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="impact-card">
                            <i class="fa-regular fa-eye"></i>
                            <h3>100%</h3>
                            <p>Visibility</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="roles">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Built for Every Role in Construction</h2>

        <div class="row g-4">
            <div class="col-lg-3 col-md-6" data-aos="fade-up">
                <div class="role-card">
                    <i class="fa-regular fa-building"></i>
                    <h5>Builders & Developers</h5>
                    <p>Complete project control from planning to handover.</p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="80">
                <div class="role-card">
                    <i class="fa-solid fa-user-helmet-safety"></i>
                    <h5>Project Consultants</h5>
                    <p>Manage multiple projects with ease and clarity.</p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="160">
                <div class="role-card">
                    <i class="fa-solid fa-people-group"></i>
                    <h5>Engineers & Teams</h5>
                    <p>Stay updated, accountable and on track.</p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6" data-aos="fade-up" data-aos-delay="240">
                <div class="role-card">
                    <i class="fa-solid fa-helmet-safety"></i>
                    <h5>Contractors & Vendors</h5>
                    <p>Better coordination, approvals and timely payments.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="authority" id="modules">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-2" data-aos="fade-right">
                <div class="ukb-box">
                    <div class="ukb-logo">
                        <span class="text-yellow">UKB</span><br>
                        <small style="font-size:14px;">CONSTRUCTION<br>MANAGEMENT</small>
                    </div>
                </div>
            </div>

            <div class="col-lg-4" data-aos="fade-up">
                <h2 style="font-weight:900;">Backed by Real Projects. Not Theory.</h2>
                <p>Developed and tested across multiple live projects handled by <b>UKB Construction Management</b>.</p>
                <ul class="check-list">
                    <li><i class="fa-solid fa-check"></i> Proven execution workflow</li>
                    <li><i class="fa-solid fa-check"></i> Practical module structure</li>
                    <li><i class="fa-solid fa-check"></i> Real construction project clarity</li>
                </ul>
                <a href="#contact" class="btn btn-yellow">Know More About UKB <i class="fa-solid fa-arrow-right ms-2"></i></a>
            </div>

            <div class="col-lg-6" data-aos="fade-left">
                <div class="project-grid">
                    <img src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=900&q=80" alt="Construction">
                    <img src="https://images.unsplash.com/photo-1541888946425-d81bb19240f5?auto=format&fit=crop&w=600&q=80" alt="Building">
                    <img src="https://images.unsplash.com/photo-1504307651254-35680f356dfd?auto=format&fit=crop&w=600&q=80" alt="Site">
                    <img src="https://images.unsplash.com/photo-1565008447742-97f6f38c985c?auto=format&fit=crop&w=600&q=80" alt="Construction Worker">
                    <img src="https://images.unsplash.com/photo-1590725140246-20acdee442be?auto=format&fit=crop&w=600&q=80" alt="Project">
                </div>
            </div>
        </div>
    </div>
</section>

<section class="pricing" id="pricing">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Simple Plans for Every Construction Business</h2>
        <p class="section-subtitle" data-aos="fade-up">Choose the right package based on your project size, team size and reporting needs.</p>

        <div class="row g-4">
            <div class="col-lg-4" data-aos="fade-up">
                <div class="price-card">
                    <h4>Starter</h4>
                    <p>For small builders and single-site teams.</p>
                    <h2>Demo</h2>
                    <ul>
                        <li><i class="fa-solid fa-check"></i> Project dashboard</li>
                        <li><i class="fa-solid fa-check"></i> DPR management</li>
                        <li><i class="fa-solid fa-check"></i> Task tracking</li>
                        <li><i class="fa-solid fa-check"></i> Basic reports</li>
                    </ul>
                    <a href="#contact" class="btn btn-yellow w-100">Request Price</a>
                </div>
            </div>

            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="100">
                <div class="price-card featured">
                    <h4>Professional</h4>
                    <p>For growing teams managing multiple projects.</p>
                    <h2>Popular</h2>
                    <ul>
                        <li><i class="fa-solid fa-check"></i> All starter features</li>
                        <li><i class="fa-solid fa-check"></i> Cost & procurement</li>
                        <li><i class="fa-solid fa-check"></i> RFI, MOM and approvals</li>
                        <li><i class="fa-solid fa-check"></i> Document control</li>
                    </ul>
                    <a href="#contact" class="btn btn-yellow w-100">Book Demo</a>
                </div>
            </div>

            <div class="col-lg-4" data-aos="fade-up" data-aos-delay="200">
                <div class="price-card">
                    <h4>Enterprise</h4>
                    <p>For large companies and project management firms.</p>
                    <h2>Custom</h2>
                    <ul>
                        <li><i class="fa-solid fa-check"></i> Multi-project control</li>
                        <li><i class="fa-solid fa-check"></i> Role-based access</li>
                        <li><i class="fa-solid fa-check"></i> Custom reports</li>
                        <li><i class="fa-solid fa-check"></i> Dedicated support</li>
                    </ul>
                    <a href="#contact" class="btn btn-yellow w-100">Contact Sales</a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="final-cta" id="contact">
    <div class="container text-center" data-aos="zoom-in">
        <h2>Ready to Take Control of Your Projects?</h2>
        <p>See your project inside TEK-C and understand how it can improve your execution workflow.</p>
        <div class="d-flex justify-content-center gap-3 flex-wrap">
            <a href="tel:+919876543210" class="btn btn-dark-custom">Book Your Live Demo <i class="fa-solid fa-arrow-right ms-2"></i></a>
            <a href="mailto:info@tekcsoftware.com" class="btn btn-outline-dark fw-bold px-4 py-3 rounded-3">Contact Us <i class="fa-solid fa-arrow-right ms-2"></i></a>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>

<script>
AOS.init({
    duration: 900,
    once: true,
    offset: 90
});

const navbar = document.getElementById('mainNavbar');
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
const sections = document.querySelectorAll('section[id]');

window.addEventListener('scroll', function(){
    if(window.scrollY > 70){
        navbar.classList.add('nav-fixed');
    }else{
        navbar.classList.remove('nav-fixed');
    }

    let currentSection = '';

    sections.forEach(function(section){
        const sectionTop = section.offsetTop - 140;
        const sectionHeight = section.offsetHeight;

        if(window.scrollY >= sectionTop && window.scrollY < sectionTop + sectionHeight){
            currentSection = section.getAttribute('id');
        }
    });

    navLinks.forEach(function(link){
        link.classList.remove('active');

        if(link.getAttribute('href') === '#' + currentSection){
            link.classList.add('active');
        }
    });
});

navLinks.forEach(function(link){
    link.addEventListener('click', function(){
        navLinks.forEach(function(nav){
            nav.classList.remove('active');
        });

        this.classList.add('active');

        const navMenu = document.getElementById('navMenu');
        if(navMenu.classList.contains('show')){
            new bootstrap.Collapse(navMenu).hide();
        }
    });
});
</script>

</body>
</html>