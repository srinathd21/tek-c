    <?php
// terms.php - Terms of Service / Terms & Conditions
// Legal document governing use of TEK-C platform and services
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Terms of Service - TEK-C Construction Management Software</title>

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

/* TERMS CONTENT */
.terms-content{
    padding: 70px 0;
    background: #fff;
}
.terms-card{
    background: white;
    border-radius: 28px;
    padding: 48px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.05);
    border: 1px solid #eee;
}
.terms-card h2{
    font-size: 28px;
    font-weight: 800;
    margin: 35px 0 18px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--yellow);
    display: inline-block;
}
.terms-card h2:first-of-type{
    margin-top: 0;
}
.terms-card h3{
    font-size: 22px;
    font-weight: 700;
    margin: 28px 0 15px 0;
}
.terms-card p{
    font-size: 15px;
    line-height: 1.7;
    color: #444;
    margin-bottom: 18px;
}
.terms-card ul, .terms-card ol{
    margin: 15px 0 20px 25px;
}
.terms-card li{
    font-size: 15px;
    line-height: 1.7;
    color: #444;
    margin-bottom: 8px;
}
.terms-card .last-updated{
    background: #f8f9fc;
    padding: 12px 20px;
    border-radius: 12px;
    margin-bottom: 30px;
    font-size: 14px;
    color: #666;
}
.terms-card .important-note{
    background: #fff8e7;
    border-left: 4px solid var(--yellow);
    padding: 18px 24px;
    border-radius: 12px;
    margin: 25px 0;
}
.terms-card .contact-box{
    background: #fefaf2;
    padding: 28px;
    border-radius: 20px;
    margin-top: 40px;
    border-left: 5px solid var(--yellow);
}
.terms-card .contact-box h4{
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 12px;
}
.terms-card .contact-box a{
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
    .terms-card{padding: 28px;}
}
@media(max-width:575px){
    .section-title{font-size: 28px;}
    .page-header h1{font-size: 30px;}
    .terms-card h2{font-size: 24px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>Terms of <span class="text-yellow">Service</span></h1>
        <p>Please read these terms carefully before using the TEK-C platform.</p>
    </div>
</section>

<section class="terms-content">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10" data-aos="fade-up">
                <div class="terms-card">
                    <div class="last-updated">
                        <i class="fa-regular fa-calendar-alt me-2"></i> Effective Date: January 15, 2025
                    </div>

                    <div class="important-note">
                        <i class="fa-solid fa-scale-balanced text-yellow me-2"></i> <strong>Legal Agreement</strong><br>
                        By accessing or using TEK-C Construction Management Software, you agree to be bound by these Terms of Service. If you do not agree, please do not use our platform.
                    </div>

                    <h2>1. Acceptance of Terms</h2>
                    <p>These Terms of Service ("Terms") govern your access to and use of TEK-C Construction Management Software, including any related websites, applications, APIs, and services (collectively, the "Service"). The Service is provided by TEK-C ("Company," "we," "us," or "our"). By creating an account, accessing, or using the Service, you acknowledge that you have read, understood, and agree to be bound by these Terms, including any future modifications.</p>

                    <h2>2. Eligibility</h2>
                    <p>To use the Service, you must:</p>
                    <ul>
                        <li>Be at least 18 years of age or the age of majority in your jurisdiction.</li>
                        <li>Have the legal capacity to enter into a binding agreement.</li>
                        <li>Provide accurate, current, and complete information during registration.</li>
                        <li>Not be prohibited from using the Service under applicable laws.</li>
                    </ul>
                    <p>If you are using the Service on behalf of an organization, you represent that you have authority to bind that organization to these Terms.</p>

                    <h2>3. Account Registration & Security</h2>
                    <p>You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account. You agree to:</p>
                    <ul>
                        <li>Notify us immediately of any unauthorized access or security breach.</li>
                        <li>Use strong passwords and enable multi-factor authentication where available.</li>
                        <li>Not share your account credentials with unauthorized individuals.</li>
                        <li>Accept responsibility for all actions taken under your account.</li>
                    </ul>
                    <p>We reserve the right to suspend or terminate accounts that violate these Terms or pose a security risk.</p>

                    <h2>4. Subscription Plans & Payments</h2>
                    <h3>4.1 Paid Plans</h3>
                    <p>Certain features of TEK-C require a paid subscription. By purchasing a subscription, you agree to pay the applicable fees as described during checkout. All fees are in Indian Rupees (INR) unless otherwise specified.</p>
                    
                    <h3>4.2 Billing & Renewal</h3>
                    <p>Subscriptions automatically renew at the end of each billing cycle (monthly or yearly) unless you cancel before the renewal date. You authorize us to charge your payment method for renewal fees. Cancellation requests must be submitted through your account settings or by contacting support.</p>
                    
                    <h3>4.3 Refund Policy</h3>
                    <p>We offer a 14-day free trial for new users. For paid subscriptions, refunds are generally not provided for partial billing periods. However, if you experience technical issues that cannot be resolved, please contact our support team for review on a case-by-case basis.</p>
                    
                    <h3>4.4 Fee Changes</h3>
                    <p>We may adjust subscription fees with 30 days' advance notice via email. Continued use after fee changes constitutes acceptance of the new pricing.</p>

                    <h2>5. Free Trial</h2>
                    <p>New users may be eligible for a 14-day free trial. Trial periods are limited to one per organization. At the end of the trial, you must purchase a subscription to continue using the Service, or your account will be downgraded or deactivated.</p>

                    <h2>6. User Responsibilities & Prohibited Conduct</h2>
                    <p>You agree to use the Service only for lawful purposes and in accordance with these Terms. You shall not:</p>
                    <ul>
                        <li>Upload, share, or store illegal, infringing, or harmful content.</li>
                        <li>Attempt to gain unauthorized access to other user accounts or systems.</li>
                        <li>Reverse engineer, decompile, or disassemble any part of the Service.</li>
                        <li>Use the Service to transmit malware, viruses, or other harmful code.</li>
                        <li>Interfere with or disrupt the integrity or performance of the Service.</li>
                        <li>Use automated scripts or bots to scrape or extract data without authorization.</li>
                        <li>Share or resell access to the Service to third parties without written permission.</li>
                        <li>Violate any applicable local, state, national, or international laws.</li>
                    </ul>

                    <h2>7. Data Ownership & Intellectual Property</h2>
                    <h3>7.1 Your Data</h3>
                    <p>You retain full ownership of all data, documents, and information you upload or create within TEK-C ("Your Data"). We do not claim ownership over Your Data. You grant us a limited license to access, process, and store Your Data solely to provide the Service to you.</p>
                    
                    <h3>7.2 Our Intellectual Property</h3>
                    <p>The Service, including its code, design, features, algorithms, trademarks, and logos, is owned by TEK-C and protected by copyright, trademark, and other intellectual property laws. You may not copy, modify, or create derivative works without our express written consent.</p>
                    
                    <h3>7.3 Feedback</h3>
                    <p>If you provide suggestions, ideas, or feedback about the Service, you grant us a perpetual, royalty-free license to use and implement such feedback without compensation.</p>

                    <h2>8. Data Security & Privacy</h2>
                    <p>We take data security seriously. Our data handling practices are outlined in our <a href="privacy-policy.php" class="text-yellow">Privacy Policy</a>. By using the Service, you consent to the collection and use of your information as described in the Privacy Policy.</p>
                    <p>While we implement industry-standard security measures, no system is 100% secure. You are responsible for backing up your critical data.</p>

                    <h2>9. Service Availability & Modifications</h2>
                    <p>We strive to maintain high availability of the Service but do not guarantee uninterrupted access. We may perform scheduled maintenance, which will be communicated in advance when possible. We reserve the right to modify, suspend, or discontinue any feature of the Service with reasonable notice.</p>

                    <h2>10. Third-Party Integrations</h2>
                    <p>TEK-C may integrate with third-party services (e.g., payment gateways, cloud storage). We are not responsible for the practices, security, or reliability of these third-party services. Your use of third-party integrations is subject to their respective terms of service.</p>

                    <h2>11. Termination & Suspension</h2>
                    <p>Either party may terminate your subscription at any time. Upon termination:</p>
                    <ul>
                        <li>You will lose access to your account and data.</li>
                        <li>We will retain your data for 90 days after termination, after which it will be permanently deleted (unless legal obligations require longer retention).</li>
                        <li>No refunds will be provided for prepaid but unused subscription periods.</li>
                    </ul>
                    <p>We may suspend or terminate your account immediately for violations of these Terms, illegal activity, or to protect the security of other users.</p>

                    <h2>12. Disclaimer of Warranties</h2>
                    <p>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, WHETHER EXPRESS OR IMPLIED. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE. YOU ASSUME FULL RESPONSIBILITY FOR YOUR USE OF THE SERVICE AND ANY CONSTRUCTION DECISIONS MADE BASED ON INFORMATION PROVIDED THROUGH THE PLATFORM.</p>

                    <h2>13. Limitation of Liability</h2>
                    <p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, TEK-C AND ITS OFFICERS, DIRECTORS, EMPLOYEES, AND AFFILIATES SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, INCLUDING BUT NOT LIMITED TO LOSS OF PROFITS, DATA, OR BUSINESS OPPORTUNITIES, ARISING OUT OF OR RELATED TO YOUR USE OF THE SERVICE. OUR TOTAL LIABILITY SHALL NOT EXCEED THE AMOUNT YOU PAID US DURING THE TWELVE (12) MONTHS PRIOR TO THE CLAIM.</p>

                    <h2>14. Indemnification</h2>
                    <p>You agree to indemnify and hold harmless TEK-C and its affiliates from any claims, damages, losses, or expenses (including reasonable legal fees) arising out of your use of the Service, violation of these Terms, or infringement of any third-party rights.</p>

                    <h2>15. Governing Law & Dispute Resolution</h2>
                    <p>These Terms shall be governed by the laws of India, without regard to conflict of law principles. Any disputes arising from these Terms or your use of the Service shall be resolved through binding arbitration in Hyderabad, Telangana, in accordance with the Arbitration and Conciliation Act, 1996. Each party shall bear its own arbitration costs. Class actions and jury trials are waived.</p>

                    <h2>16. Force Majeure</h2>
                    <p>We shall not be liable for delays or failures in performance resulting from causes beyond our reasonable control, including natural disasters, pandemics, war, terrorism, labor disputes, or internet outages.</p>

                    <h2>17. Modifications to Terms</h2>
                    <p>We may update these Terms from time to time. Material changes will be notified via email or prominent notice on our platform at least 30 days in advance. Your continued use of the Service after the effective date constitutes acceptance of the revised Terms.</p>

                    <h2>18. Severability</h2>
                    <p>If any provision of these Terms is found to be unenforceable or invalid, that provision shall be limited or removed to the minimum extent necessary, and the remaining provisions shall remain in full force and effect.</p>

                    <h2>19. Entire Agreement</h2>
                    <p>These Terms, together with the Privacy Policy, constitute the entire agreement between you and TEK-C regarding your use of the Service and supersede all prior agreements.</p>

                    <div class="contact-box">
                        <h4><i class="fa-regular fa-envelope me-2"></i> Contact Information</h4>
                        <p>If you have questions about these Terms, please contact us:</p>
                        <p><strong>Email:</strong> <a href="mailto:legal@tekcsoftware.com">legal@tekcsoftware.com</a><br>
                        <strong>Address:</strong> 3rd Floor, UKB Tower, Hitech City, Hyderabad - 500081, Telangana, India<br>
                        <strong>Phone:</strong> +91 40 6789 1234</p>
                        <p class="mb-0 mt-3"><small>For legal notices, please send via registered mail to the above address.</small></p>
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

// Active link highlight - none for terms page
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    link.classList.remove('active');
});
</script>
</body>
</html>