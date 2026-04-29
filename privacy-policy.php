<?php
// privacy-policy.php - Privacy Policy Page
// Legal document outlining data collection, usage, and protection practices
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Policy - TEK-C Construction Management Software</title>

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
    padding:14px 28px;
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

/* POLICY CONTENT */
.policy-content{
    padding: 70px 0;
    background: #fff;
}
.policy-card{
    background: white;
    border-radius: 28px;
    padding: 48px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.05);
    border: 1px solid #eee;
}
.policy-card h2{
    font-size: 28px;
    font-weight: 800;
    margin: 35px 0 18px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--yellow);
    display: inline-block;
}
.policy-card h2:first-of-type{
    margin-top: 0;
}
.policy-card h3{
    font-size: 22px;
    font-weight: 700;
    margin: 28px 0 15px 0;
}
.policy-card p{
    font-size: 15px;
    line-height: 1.7;
    color: #444;
    margin-bottom: 18px;
}
.policy-card ul, .policy-card ol{
    margin: 15px 0 20px 25px;
}
.policy-card li{
    font-size: 15px;
    line-height: 1.7;
    color: #444;
    margin-bottom: 8px;
}
.policy-card .last-updated{
    background: #f8f9fc;
    padding: 12px 20px;
    border-radius: 12px;
    margin-bottom: 30px;
    font-size: 14px;
    color: #666;
}
.policy-card .contact-box{
    background: #fefaf2;
    padding: 28px;
    border-radius: 20px;
    margin-top: 40px;
    border-left: 5px solid var(--yellow);
}
.policy-card .contact-box h4{
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 12px;
}
.policy-card .contact-box a{
    color: var(--yellow);
    text-decoration: none;
    font-weight: 600;
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
    .policy-card{padding: 28px;}
}
@media(max-width:575px){
    .section-title{font-size: 28px;}
    .page-header h1{font-size: 30px;}
    .policy-card h2{font-size: 24px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1><span class="text-yellow">Privacy</span> Policy</h1>
        <p>Your trust matters to us. Learn how we collect, use, and protect your information.</p>
    </div>
</section>

<section class="policy-content">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10" data-aos="fade-up">
                <div class="policy-card">
                    <div class="last-updated">
                        <i class="fa-regular fa-calendar-alt me-2"></i> Last Updated: January 15, 2025
                    </div>

                    <h2>1. Introduction</h2>
                    <p>TEK-C Construction Management Software ("TEK-C", "we", "our", or "us") is committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our software platform, website, and related services (collectively, the "Service").</p>
                    <p>By using TEK-C, you consent to the data practices described in this policy. If you do not agree with any terms, please do not use our Service.</p>

                    <h2>2. Information We Collect</h2>
                    <p>We collect information that you voluntarily provide to us when registering for an account, using our platform, requesting a demo, or contacting our support team.</p>
                    
                    <h3>2.1 Personal Information</h3>
                    <ul>
                        <li><strong>Account Information:</strong> Name, email address, phone number, company name, designation, and password.</li>
                        <li><strong>Project Information:</strong> Project details, site locations, budgets, schedules, workforce data, and documents you upload.</li>
                        <li><strong>Communication Data:</strong> Messages, support tickets, demo requests, and feedback you submit.</li>
                        <li><strong>Billing Information:</strong> Payment details, invoice addresses, and transaction history (processed via secure third-party gateways).</li>
                    </ul>

                    <h3>2.2 Automatically Collected Information</h3>
                    <ul>
                        <li><strong>Usage Data:</strong> IP address, browser type, device information, pages visited, time spent, and feature usage patterns.</li>
                        <li><strong>Cookies & Tracking:</strong> We use cookies to enhance user experience, analyze trends, and remember preferences.</li>
                    </ul>

                    <h2>3. How We Use Your Information</h2>
                    <p>We use the collected information for the following purposes:</p>
                    <ul>
                        <li>To provide, operate, and maintain our construction management platform.</li>
                        <li>To process your transactions and manage your subscription.</li>
                        <li>To improve, personalize, and expand our services based on usage patterns.</li>
                        <li>To communicate with you about updates, security alerts, and support messages.</li>
                        <li>To process demo requests, respond to inquiries, and provide customer support.</li>
                        <li>To analyze usage trends and enhance platform security.</li>
                        <li>To comply with legal obligations and enforce our terms of service.</li>
                    </ul>

                    <h2>4. Data Sharing & Disclosure</h2>
                    <p>We do not sell, rent, or trade your personal information to third parties. We may share your data in the following circumstances:</p>
                    <ul>
                        <li><strong>Service Providers:</strong> We engage trusted third-party vendors (cloud hosting, payment processors, analytics) who assist in operating our platform. These parties are bound by data protection agreements.</li>
                        <li><strong>Legal Requirements:</strong> If required by law, court order, or government regulation, we may disclose your information to comply with legal processes.</li>
                        <li><strong>Business Transfers:</strong> In the event of a merger, acquisition, or sale of assets, your information may be transferred with notice to you.</li>
                        <li><strong>With Your Consent:</strong> We may share information with your explicit consent.</li>
                    </ul>

                    <h2>5. Data Security</h2>
                    <p>We implement industry-standard security measures to protect your data, including:</p>
                    <ul>
                        <li>256-bit SSL encryption for data transmission.</li>
                        <li>Role-based access controls and multi-factor authentication options.</li>
                        <li>Regular security audits and vulnerability assessments.</li>
                        <li>Encrypted database storage for sensitive information.</li>
                        <li>Automated backups to prevent data loss.</li>
                    </ul>
                    <p>However, no method of transmission over the internet is 100% secure. While we strive to protect your information, we cannot guarantee absolute security.</p>

                    <h2>6. Data Retention</h2>
                    <p>We retain your personal information for as long as your account is active or as needed to provide you with services. If you cancel your subscription, we will retain your data for 90 days before permanent deletion, after which only anonymized aggregated data may be kept for analytical purposes. You may request earlier data deletion by contacting our support team.</p>

                    <h2>7. Your Rights & Choices</h2>
                    <p>Depending on your location, you may have the following rights regarding your personal data:</p>
                    <ul>
                        <li><strong>Access:</strong> Request a copy of the personal data we hold about you.</li>
                        <li><strong>Correction:</strong> Request correction of inaccurate or incomplete information.</li>
                        <li><strong>Deletion:</strong> Request deletion of your personal data, subject to legal obligations.</li>
                        <li><strong>Restriction:</strong> Request restriction of processing your data.</li>
                        <li><strong>Portability:</strong> Request transfer of your data to another service.</li>
                        <li><strong>Opt-out:</strong> Unsubscribe from marketing communications anytime using the link in emails.</li>
                    </ul>
                    <p>To exercise these rights, contact us at <a href="mailto:privacy@tekcsoftware.com" class="text-yellow">privacy@tekcsoftware.com</a>. We will respond within 30 days.</p>

                    <h2>8. Cookies & Tracking Technologies</h2>
                    <p>We use cookies and similar tracking technologies to:</p>
                    <ul>
                        <li>Remember your login session and preferences.</li>
                        <li>Analyze website traffic and user behavior.</li>
                        <li>Improve platform performance and user experience.</li>
                    </ul>
                    <p>You can control cookies through your browser settings. Disabling cookies may affect certain platform features.</p>

                    <h2>9. Third-Party Links</h2>
                    <p>Our platform may contain links to third-party websites or services. We are not responsible for the privacy practices of these external sites. We encourage you to review their privacy policies before providing any personal information.</p>

                    <h2>10. Children's Privacy</h2>
                    <p>TEK-C is not intended for children under 18 years of age. We do not knowingly collect personal information from minors. If you believe a minor has provided us with personal data, please contact us, and we will take steps to delete such information.</p>

                    <h2>11. International Data Transfers</h2>
                    <p>Your information may be transferred to and processed in countries other than your own. We ensure appropriate safeguards are in place, including standard contractual clauses, to protect your data in accordance with this Privacy Policy.</p>

                    <h2>12. Changes to This Privacy Policy</h2>
                    <p>We may update this Privacy Policy from time to time. Material changes will be notified via email or prominent notice on our platform. The "Last Updated" date at the top of this policy indicates when revisions were made. Your continued use of TEK-C after changes constitutes acceptance of the updated policy.</p>

                    <div class="contact-box">
                        <h4><i class="fa-regular fa-envelope me-2"></i> Contact Us</h4>
                        <p>If you have questions, concerns, or requests regarding this Privacy Policy or our data practices, please contact us:</p>
                        <p><strong>Email:</strong> <a href="mailto:privacy@tekcsoftware.com">privacy@tekcsoftware.com</a><br>
                        <strong>Address:</strong> 3rd Floor, UKB Tower, Hitech City, Hyderabad - 500081, Telangana, India<br>
                        <strong>Phone:</strong> +91 40 6789 1234</p>
                        <p class="mb-0 mt-3"><small>For data protection officer inquiries, please mark your email "Attn: DPO".</small></p>
                    </div>
                </div>
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

// Active link highlight - none for privacy policy
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    link.classList.remove('active');
});
</script>
</body>
</html>