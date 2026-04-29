<?php
// contact.php - Contact & Support Page
// Includes contact form, office locations, support options, and working Google Map
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contact Us - TEK-C Construction Management Software</title>

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

/* CONTACT INFO CARDS */
.info-card{
    background: white;
    border-radius: 28px;
    padding: 32px 24px;
    text-align: center;
    height: 100%;
    transition: 0.3s;
    border: 1px solid #eee;
    box-shadow: 0 5px 15px rgba(0,0,0,0.02);
}
.info-card:hover{
    transform: translateY(-5px);
    border-color: var(--yellow);
}
.info-icon{
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
.info-card h4{
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 12px;
}
.info-card p{
    color: #555;
    margin-bottom: 5px;
    font-size: 15px;
}
.info-card a{
    color: var(--yellow);
    text-decoration: none;
    font-weight: 600;
}

/* CONTACT FORM */
.contact-form-section{
    padding: 80px 0;
    background: #f8fafc;
}
.form-card{
    background: white;
    border-radius: 32px;
    padding: 48px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.05);
}
.form-control, .form-select{
    border-radius: 14px;
    padding: 14px 18px;
    border: 1px solid #e2e8f0;
    font-size: 15px;
}
.form-control:focus{
    border-color: var(--yellow);
    box-shadow: 0 0 0 3px rgba(246,173,34,0.2);
}
.btn-submit{
    background: linear-gradient(135deg,var(--yellow),var(--yellow2));
    border: none;
    border-radius: 14px;
    padding: 14px 32px;
    font-weight: 800;
    color: #111;
    width: 100%;
}
.btn-submit:hover{
    transform: translateY(-2px);
}

/* OFFICES */
.offices-section{
    padding: 80px 0;
}
.office-card{
    background: white;
    border-radius: 24px;
    padding: 28px;
    border: 1px solid #eee;
    height: 100%;
}
.office-card h4{
    font-size: 22px;
    font-weight: 800;
    margin-bottom: 15px;
}
.office-card p{
    margin-bottom: 8px;
    color: #555;
    font-size: 14px;
}
.office-card i{
    width: 24px;
    color: var(--yellow);
}

/* MAP */
.map-container{
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    height: 400px;
    width: 100%;
}
.map-container iframe{
    width: 100%;
    height: 100%;
    border: 0;
}

/* FAQ CONTACT */
.faq-contact{
    background: #111;
    color: white;
    padding: 60px 0;
    text-align: center;
}
.faq-contact h3{
    font-size: 32px;
    font-weight: 800;
}
.faq-contact a{
    color: var(--yellow);
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
    .form-card{padding: 28px;}
}
@media(max-width:575px){
    .section-title{font-size: 28px;}
    .page-header h1{font-size: 30px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>Let's <span class="text-yellow">connect</span></h1>
        <p>Have questions about TEK-C? Need a personalized demo? Our team is here to help you take control of your construction projects.</p>
    </div>
</section>

<!-- CONTACT INFO CARDS -->
<section style="padding: 60px 0 0 0;">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="info-card">
                    <div class="info-icon"><i class="fa-solid fa-phone-volume"></i></div>
                    <h4>Call Us</h4>
                    <p><strong>Sales:</strong> +91 1800 123 4567</p>
                    <p><strong>Support:</strong> +91 98765 43210</p>
                    <p class="mt-2"><small>Mon-Fri, 9AM - 6PM IST</small></p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="info-card">
                    <div class="info-icon"><i class="fa-regular fa-envelope"></i></div>
                    <h4>Email Us</h4>
                    <p><strong>General:</strong> <a href="mailto:hello@tekcsoftware.com">hello@tekcsoftware.com</a></p>
                    <p><strong>Support:</strong> <a href="mailto:support@tekcsoftware.com">support@tekcsoftware.com</a></p>
                    <p><strong>Sales:</strong> <a href="mailto:sales@tekcsoftware.com">sales@tekcsoftware.com</a></p>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="info-card">
                    <div class="info-icon"><i class="fa-regular fa-comment-dots"></i></div>
                    <h4>Live Chat</h4>
                    <p>Chat with our team during business hours</p>
                    <a href="#" class="btn btn-sm btn-outline-dark mt-2 rounded-pill">Start Chat <i class="fa-regular fa-message ms-1"></i></a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CONTACT FORM & MAP -->
<section class="contact-form-section">
    <div class="container">
        <div class="row g-5">
            <div class="col-lg-6" data-aos="fade-right">
                <div class="form-card">
                    <h3 class="mb-3 fw-bold">Send us a message</h3>
                    <p class="text-muted mb-4">Fill out the form and our team will get back to you within 24 hours.</p>
                    
                    <form action="submit_contact.php" method="POST" id="contactForm">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Full Name *</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Company / Organization</label>
                                <input type="text" name="company" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email Address *</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input type="tel" name="phone" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">I'm interested in *</label>
                                <select class="form-select" name="interest">
                                    <option>Product Demo</option>
                                    <option>Pricing & Plans</option>
                                    <option>Technical Support</option>
                                    <option>Partnership / Channel</option>
                                    <option>Other</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Message *</label>
                                <textarea rows="4" name="message" class="form-control" placeholder="Tell us about your project requirements..." required></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-submit"><i class="fa-regular fa-paper-plane me-2"></i> Send Message</button>
                            </div>
                            <div class="col-12">
                                <small class="text-muted">By submitting, you agree to our <a href="#">Privacy Policy</a>. We'll never share your data.</small>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="col-lg-6" data-aos="fade-left">
                <div class="map-container mb-4">
                    <!-- Google Maps Embed - Works without API key! -->
                    <iframe 
                        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3806.456789012345!2d78.3772!3d17.4435!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3bcb93f6c0b8f8d5%3A0x8b5a5c5e5f5a5c5e!2sHitech%20City%2C%20Hyderabad%2C%20Telangana!5e0!3m2!1sen!2sin!4v1700000000000!5m2!1sen!2sin" 
                        allowfullscreen="" 
                        loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade">
                    </iframe>
                </div>
                <div class="bg-white p-4 rounded-4 text-center border">
                    <i class="fa-solid fa-location-dot text-yellow fs-3 mb-2"></i>
                    <p class="mb-0"><strong>Registered Office:</strong> 3rd Floor, UKB Tower, Hitech City, Hyderabad - 500081, Telangana, India</p>
                    <a href="https://www.google.com/maps/dir//Hitech+City+Hyderabad" target="_blank" class="btn btn-sm btn-outline-dark mt-3 rounded-pill">
                        <i class="fa-solid fa-directions me-1"></i> Get Directions
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- OFFICE LOCATIONS -->
<section class="offices-section">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Our offices</h2>
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="office-card">
                    <h4><i class="fa-solid fa-building me-2 text-yellow"></i> Hyderabad (HQ)</h4>
                    <p><i class="fa-solid fa-location-dot"></i> 3rd Floor, UKB Tower, Hitech City, Hyderabad - 500081</p>
                    <p><i class="fa-regular fa-clock"></i> Mon-Fri: 9AM - 6PM</p>
                    <p><i class="fa-solid fa-phone"></i> +91 40 6789 1234</p>
                    <a href="https://www.google.com/maps/search/?api=1&query=Hitech+City+Hyderabad" target="_blank" class="btn btn-sm btn-outline-dark mt-2 rounded-pill">View on map <i class="fa-regular fa-arrow-up-right-from-square ms-1"></i></a>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="office-card">
                    <h4><i class="fa-solid fa-city me-2 text-yellow"></i> Mumbai</h4>
                    <p><i class="fa-solid fa-location-dot"></i> 12th Floor, Interface-11, Malad West, Mumbai - 400064</p>
                    <p><i class="fa-regular fa-clock"></i> Mon-Fri: 9AM - 6PM</p>
                    <p><i class="fa-solid fa-phone"></i> +91 22 4567 8901</p>
                    <a href="https://www.google.com/maps/search/?api=1&query=Malad+West+Mumbai" target="_blank" class="btn btn-sm btn-outline-dark mt-2 rounded-pill">View on map <i class="fa-regular fa-arrow-up-right-from-square ms-1"></i></a>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="office-card">
                    <h4><i class="fa-solid fa-charging-station me-2 text-yellow"></i> Bengaluru</h4>
                    <p><i class="fa-solid fa-location-dot"></i> WeWork Galaxy, Residency Road, Bengaluru - 560025</p>
                    <p><i class="fa-regular fa-clock"></i> Mon-Fri: 9AM - 6PM</p>
                    <p><i class="fa-solid fa-phone"></i> +91 80 9876 5432</p>
                    <a href="https://www.google.com/maps/search/?api=1&query=Residency+Road+Bangalore" target="_blank" class="btn btn-sm btn-outline-dark mt-2 rounded-pill">View on map <i class="fa-regular fa-arrow-up-right-from-square ms-1"></i></a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- SUPPORT & FAQ -->
<section class="faq-contact">
    <div class="container">
        <h3>Frequently asked questions</h3>
        <p class="mb-4">Quick answers to common questions. Or <a href="#contact" class="text-yellow">contact support directly.</a></p>
        <div class="row justify-content-center text-start">
            <div class="col-md-5">
                <div class="mb-3"><strong>❓ How fast is support response?</strong><br>Typical response within 2-4 business hours.</div>
                <div><strong>❓ Do you offer onsite training?</strong><br>Yes, for Enterprise plans we provide onsite onboarding.</div>
            </div>
            <div class="col-md-5">
                <div class="mb-3"><strong>❓ Is there a free trial?</strong><br>Absolutely. 14-day trial with full access.</div>
                <div><strong>❓ Can I get a custom quote?</strong><br>Yes, contact our sales team for volume pricing.</div>
            </div>
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

// Active link highlight for contact
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    if(link.getAttribute('href') === '#contact'){
        link.classList.add('active');
    }
});

// Smooth form submission handling (demo)
document.getElementById('contactForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    alert('Thank you for reaching out! Our team will contact you shortly. (Demo mode)');
    this.reset();
});
</script>
</body>
</html>