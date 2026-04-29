<?php
// help.php - Help Center & Support Page
// Comprehensive help center with FAQs, knowledge base, and support options
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help Center - TEK-C Construction Management Software</title>

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

/* SEARCH BAR */
.search-section{
    margin-top: -30px;
    position: relative;
    z-index: 10;
}
.search-container{
    max-width: 700px;
    margin: 0 auto;
}
.search-box{
    background: white;
    border-radius: 60px;
    padding: 8px 8px 8px 25px;
    box-shadow: 0 15px 40px rgba(0,0,0,0.12);
    display: flex;
    align-items: center;
}
.search-box input{
    flex: 1;
    border: none;
    padding: 16px 0;
    font-size: 16px;
    outline: none;
}
.search-box button{
    background: var(--yellow);
    border: none;
    border-radius: 50px;
    padding: 12px 28px;
    font-weight: 700;
    color: #111;
    transition: 0.3s;
}
.search-box button:hover{
    background: var(--yellow2);
    transform: scale(1.02);
}

/* HELP CATEGORIES */
.categories-section{
    padding: 70px 0 50px;
}
.category-card{
    background: white;
    border-radius: 24px;
    padding: 32px 24px;
    text-align: center;
    height: 100%;
    transition: 0.3s;
    border: 1px solid #eee;
    box-shadow: 0 4px 12px rgba(0,0,0,0.03);
}
.category-card:hover{
    transform: translateY(-6px);
    border-color: var(--yellow);
}
.category-icon{
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
.category-card h3{
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 12px;
}
.category-card p{
    color: #666;
    font-size: 14px;
    margin-bottom: 18px;
}
.category-card .article-count{
    font-size: 12px;
    color: var(--yellow);
    font-weight: 600;
}

/* FAQ SECTION */
.faq-section{
    background: #f8fafc;
    padding: 70px 0;
}
.faq-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 25px;
}
.faq-item{
    background: white;
    border-radius: 20px;
    padding: 24px;
    border: 1px solid #eee;
    transition: 0.2s;
}
.faq-item:hover{
    border-color: rgba(246,173,34,0.3);
}
.faq-item h4{
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 12px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.faq-item h4 i{
    color: var(--yellow);
    transition: 0.2s;
}
.faq-item .faq-answer{
    font-size: 14px;
    color: #555;
    line-height: 1.6;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}
.faq-item.active .faq-answer{
    max-height: 300px;
    margin-top: 12px;
}
.faq-item.active h4 i{
    transform: rotate(180deg);
}

/* KNOWLEDGE BASE ARTICLES */
.articles-section{
    padding: 70px 0;
}
.article-card{
    background: white;
    border-radius: 20px;
    padding: 24px;
    border: 1px solid #eee;
    height: 100%;
    transition: 0.3s;
}
.article-card:hover{
    border-color: var(--yellow);
    transform: translateY(-3px);
}
.article-card .article-tag{
    display: inline-block;
    background: #fff5e6;
    color: var(--yellow);
    font-size: 11px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 20px;
    margin-bottom: 12px;
}
.article-card h4{
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 10px;
}
.article-card p{
    font-size: 13px;
    color: #666;
    margin-bottom: 15px;
    line-height: 1.5;
}
.article-card a{
    color: var(--yellow);
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
}

/* SUPPORT TICKET */
.support-section{
    background: linear-gradient(135deg, #111, #1a1f25);
    color: white;
    padding: 70px 0;
    text-align: center;
}
.support-card{
    max-width: 600px;
    margin: 0 auto;
}
.support-card h2{
    font-size: 32px;
    font-weight: 800;
    margin-bottom: 15px;
}
.support-card p{
    color: #ccc;
    margin-bottom: 30px;
}
.btn-support{
    background: var(--yellow);
    color: #111;
    font-weight: 800;
    padding: 14px 32px;
    border-radius: 50px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: 0.3s;
}
.btn-support:hover{
    transform: translateY(-2px);
    color: #111;
}

/* CONTACT OPTIONS */
.contact-options{
    padding: 60px 0;
    background: white;
}
.contact-option{
    text-align: center;
    padding: 20px;
}
.contact-option i{
    font-size: 40px;
    color: var(--yellow);
    margin-bottom: 15px;
}
.contact-option h4{
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 8px;
}
.contact-option p{
    color: #666;
    font-size: 14px;
}
.contact-option a{
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
    .faq-grid{grid-template-columns: 1fr;}
}
@media(max-width:575px){
    .section-title{font-size: 28px;}
    .page-header h1{font-size: 30px;}
    .search-box{padding: 5px 5px 5px 18px;}
    .search-box button{padding: 10px 18px;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>How can we <span class="text-yellow">help</span> you?</h1>
        <p>Find answers, guides, and support resources for TEK-C Construction Management Software.</p>
    </div>
</section>

<!-- SEARCH BAR -->
<section class="search-section">
    <div class="container">
        <div class="search-container" data-aos="fade-up">
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search for articles, guides, or FAQs...">
                <button onclick="searchHelp()"><i class="fa-solid fa-search me-2"></i> Search</button>
            </div>
        </div>
    </div>
</section>

<!-- HELP CATEGORIES -->
<section class="categories-section">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Browse by category</h2>
        <div class="row g-4">
            <div class="col-md-3 col-sm-6" data-aos="fade-up">
                <div class="category-card">
                    <div class="category-icon"><i class="fa-regular fa-rocket"></i></div>
                    <h3>Getting Started</h3>
                    <p>Setup, onboarding, and basic navigation</p>
                    <span class="article-count">12 articles</span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6" data-aos="fade-up" data-aos-delay="80">
                <div class="category-card">
                    <div class="category-icon"><i class="fa-regular fa-clipboard"></i></div>
                    <h3>Daily Reporting</h3>
                    <p>DPR, MOM, RFI, site updates</p>
                    <span class="article-count">8 articles</span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6" data-aos="fade-up" data-aos-delay="160">
                <div class="category-card">
                    <div class="category-icon"><i class="fa-solid fa-chart-line"></i></div>
                    <h3>Cost & Procurement</h3>
                    <p>Budgets, POs, vendor management</p>
                    <span class="article-count">10 articles</span>
                </div>
            </div>
            <div class="col-md-3 col-sm-6" data-aos="fade-up" data-aos-delay="240">
                <div class="category-card">
                    <div class="category-icon"><i class="fa-solid fa-users"></i></div>
                    <h3>Workforce Management</h3>
                    <p>Attendance, payroll, contractor mgmt</p>
                    <span class="article-count">6 articles</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ SECTION -->
<section class="faq-section">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Frequently asked questions</h2>
        <div class="faq-grid" id="faqGrid">
            <div class="faq-item" data-aos="fade-up">
                <h4 onclick="toggleFaq(this)">How do I create a new project? <i class="fa-solid fa-chevron-down"></i></h4>
                <div class="faq-answer">Click on "Projects" in the left sidebar, then click the "New Project" button. Fill in project name, location, start date, and budget. You can also upload project documents and assign team members during setup.</div>
            </div>
            <div class="faq-item" data-aos="fade-up" data-aos-delay="50">
                <h4 onclick="toggleFaq(this)">How do I generate a Daily Progress Report? <i class="fa-solid fa-chevron-down"></i></h4>
                <div class="faq-answer">Navigate to the project dashboard, click "Daily Reports" > "New Report". Enter work completed, labor count, material used, upload photos, and submit. The report will be visible to all stakeholders.</div>
            </div>
            <div class="faq-item" data-aos="fade-up" data-aos-delay="100">
                <h4 onclick="toggleFaq(this)">Can I invite multiple users to my account? <i class="fa-solid fa-chevron-down"></i></h4>
                <div class="faq-answer">Yes! Go to "Settings" > "Team Members" > "Invite User". Enter email addresses and assign roles (Admin, Project Manager, Engineer, Viewer). All plans include unlimited users.</div>
            </div>
            <div class="faq-item" data-aos="fade-up" data-aos-delay="150">
                <h4 onclick="toggleFaq(this)">How secure is my data? <i class="fa-solid fa-chevron-down"></i></h4>
                <div class="faq-answer">TEK-C uses 256-bit SSL encryption, regular backups, and role-based access controls. Your data is hosted on secure cloud servers with enterprise-grade security certifications.</div>
            </div>
            <div class="faq-item" data-aos="fade-up" data-aos-delay="200">
                <h4 onclick="toggleFaq(this)">Is there a mobile app? <i class="fa-solid fa-chevron-down"></i></h4>
                <div class="faq-answer">Yes, TEK-C is fully responsive on mobile browsers. Native Android and iOS apps are coming in Q3 2025. You can access all features via mobile web browser.</div>
            </div>
            <div class="faq-item" data-aos="fade-up" data-aos-delay="250">
                <h4 onclick="toggleFaq(this)">How do I cancel my subscription? <i class="fa-solid fa-chevron-down"></i></h4>
                <div class="faq-answer">Go to "Settings" > "Billing" > "Cancel Subscription". You'll continue to have access until the end of your billing period. No hidden cancellation fees.</div>
            </div>
        </div>
    </div>
</section>

<!-- KNOWLEDGE BASE ARTICLES -->
<section class="articles-section">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Popular guides & tutorials</h2>
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="article-card">
                    <span class="article-tag">Getting Started</span>
                    <h4>Complete onboarding guide</h4>
                    <p>Step-by-step walkthrough to set up your account, invite team members, and create your first project.</p>
                    <a href="#">Read more →</a>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="article-card">
                    <span class="article-tag">Reporting</span>
                    <h4>Mastering Daily Progress Reports</h4>
                    <p>Learn best practices for effective daily reporting and how to track project health indicators.</p>
                    <a href="#">Read more →</a>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="article-card">
                    <span class="article-tag">Procurement</span>
                    <h4>Managing vendor payments & POs</h4>
                    <p>Complete guide to purchase orders, GRN matching, and vendor payment tracking.</p>
                    <a href="#">Read more →</a>
                </div>
            </div>
        </div>
        <div class="text-center mt-5">
            <a href="#" class="btn btn-outline-dark-custom">View all articles <i class="fa-regular fa-arrow-right ms-2"></i></a>
        </div>
    </div>
</section>

<!-- SUPPORT TICKET SECTION -->
<section class="support-section">
    <div class="container">
        <div class="support-card" data-aos="zoom-in">
            <h2>Still need help?</h2>
            <p>Our support team is ready to assist you with any questions or technical issues.</p>
            <a href="#" class="btn-support" onclick="openTicketForm()"><i class="fa-regular fa-ticket"></i> Submit a support ticket</a>
        </div>
    </div>
</section>

<!-- CONTACT OPTIONS -->
<section class="contact-options">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4" data-aos="fade-up">
                <div class="contact-option">
                    <i class="fa-regular fa-message"></i>
                    <h4>Live Chat</h4>
                    <p>Mon-Fri, 9AM - 6PM IST</p>
                    <a href="#">Start chat <i class="fa-regular fa-arrow-right ms-1"></i></a>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="100">
                <div class="contact-option">
                    <i class="fa-regular fa-envelope"></i>
                    <h4>Email Support</h4>
                    <p>support@tekcsoftware.com</p>
                    <a href="mailto:support@tekcsoftware.com">Send email <i class="fa-regular fa-arrow-right ms-1"></i></a>
                </div>
            </div>
            <div class="col-md-4" data-aos="fade-up" data-aos-delay="200">
                <div class="contact-option">
                    <i class="fa-solid fa-phone"></i>
                    <h4>Phone Support</h4>
                    <p>+91 1800 123 4567</p>
                    <a href="tel:+9118001234567">Call now <i class="fa-regular fa-arrow-right ms-1"></i></a>
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

// Toggle FAQ answers
function toggleFaq(element) {
    const faqItem = element.closest('.faq-item');
    faqItem.classList.toggle('active');
}

// Search functionality
function searchHelp() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const faqItems = document.querySelectorAll('.faq-item');
    const articleCards = document.querySelectorAll('.article-card');
    let hasResults = false;

    faqItems.forEach(item => {
        const question = item.querySelector('h4').innerText.toLowerCase();
        const answer = item.querySelector('.faq-answer').innerText.toLowerCase();
        if (question.includes(searchTerm) || answer.includes(searchTerm)) {
            item.style.display = 'block';
            hasResults = true;
        } else {
            item.style.display = 'none';
        }
    });

    articleCards.forEach(card => {
        const title = card.querySelector('h4').innerText.toLowerCase();
        const desc = card.querySelector('p').innerText.toLowerCase();
        if (title.includes(searchTerm) || desc.includes(searchTerm)) {
            card.closest('.col-md-4').style.display = 'block';
            hasResults = true;
        } else {
            card.closest('.col-md-4').style.display = 'none';
        }
    });

    if (!hasResults && searchTerm !== '') {
        alert('No results found. Please try different keywords or contact support.');
    }
}

// Allow Enter key to trigger search
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        searchHelp();
    }
});

// Support ticket form (demo)
function openTicketForm() {
    alert('Support ticket system opens here. In the live version, this would open a form to submit your issue.\n\nFor demo purposes, please email support@tekcsoftware.com');
}

// Active link highlight for help
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    if(link.getAttribute('href') === '#help'){
        link.classList.add('active');
    }
});
</script>
</body>
</html>