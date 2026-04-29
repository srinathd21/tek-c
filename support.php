<?php
// support.php - Customer Support Portal
// Comprehensive support page with ticket system, knowledge base, and contact options
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer Support - TEK-C Construction Management Software</title>

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

/* SUPPORT TABS */
.support-tabs{
    background: white;
    border-bottom: 1px solid #eee;
    margin-top: 30px;
}
.tab-buttons{
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 15px 20px 0;
}
.tab-btn{
    background: transparent;
    border: none;
    padding: 12px 28px;
    font-size: 16px;
    font-weight: 700;
    border-radius: 50px;
    cursor: pointer;
    transition: 0.3s;
    color: #666;
}
.tab-btn:hover{
    background: #f5f5f5;
}
.tab-btn.active{
    background: var(--yellow);
    color: #111;
    box-shadow: 0 4px 12px rgba(246,173,34,0.3);
}
.tab-content{
    display: none;
    padding: 50px 0;
}
.tab-content.active{
    display: block;
}

/* TICKET FORM */
.ticket-form-container{
    max-width: 800px;
    margin: 0 auto;
}
.ticket-card{
    background: white;
    border-radius: 28px;
    padding: 40px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.08);
    border: 1px solid #eee;
}
.form-group{
    margin-bottom: 24px;
}
.form-group label{
    font-weight: 700;
    margin-bottom: 8px;
    display: block;
}
.form-control, .form-select{
    border-radius: 14px;
    padding: 12px 16px;
    border: 1px solid #e2e8f0;
    width: 100%;
    font-size: 14px;
}
.form-control:focus, .form-select:focus{
    border-color: var(--yellow);
    outline: none;
    box-shadow: 0 0 0 3px rgba(246,173,34,0.2);
}
textarea.form-control{
    resize: vertical;
}
.attachment-area{
    border: 2px dashed #ddd;
    border-radius: 16px;
    padding: 30px;
    text-align: center;
    cursor: pointer;
    transition: 0.3s;
}
.attachment-area:hover{
    border-color: var(--yellow);
    background: #fffef8;
}
.btn-submit-ticket{
    background: linear-gradient(135deg,var(--yellow),var(--yellow2));
    border: none;
    border-radius: 14px;
    padding: 14px 28px;
    font-weight: 800;
    color: #111;
    width: 100%;
    font-size: 16px;
}
.btn-submit-ticket:hover{
    transform: translateY(-2px);
}

/* KNOWLEDGE BASE */
.kb-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
}
.kb-category{
    background: white;
    border-radius: 24px;
    padding: 28px;
    border: 1px solid #eee;
    transition: 0.3s;
}
.kb-category:hover{
    transform: translateY(-5px);
    border-color: var(--yellow);
}
.kb-category h3{
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.kb-category h3 i{
    color: var(--yellow);
    font-size: 28px;
}
.kb-articles{
    list-style: none;
    padding: 0;
}
.kb-articles li{
    margin-bottom: 12px;
}
.kb-articles a{
    color: #555;
    text-decoration: none;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: 0.2s;
}
.kb-articles a:hover{
    color: var(--yellow);
    transform: translateX(5px);
}
.kb-articles a i{
    font-size: 12px;
    color: var(--yellow);
}

/* SUPPORT CHANNELS */
.channels-section{
    background: #f8fafc;
    padding: 60px 0;
}
.channel-grid{
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 30px;
}
.channel-card{
    background: white;
    border-radius: 24px;
    padding: 32px 24px;
    text-align: center;
    transition: 0.3s;
    border: 1px solid #eee;
}
.channel-card:hover{
    transform: translateY(-5px);
    border-color: var(--yellow);
}
.channel-icon{
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
.channel-card h4{
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 10px;
}
.channel-card p{
    color: #666;
    font-size: 14px;
    margin-bottom: 8px;
}
.channel-card .detail{
    font-weight: 700;
    color: #111;
    margin-top: 12px;
}
.channel-card a{
    color: var(--yellow);
    text-decoration: none;
    font-weight: 600;
}

/* STATUS CHECK */
.status-section{
    padding: 60px 0;
    background: white;
}
.status-card{
    background: #fefaf2;
    border-radius: 24px;
    padding: 40px;
    text-align: center;
    max-width: 600px;
    margin: 0 auto;
}
.status-card h3{
    font-size: 24px;
    font-weight: 800;
    margin-bottom: 20px;
}
.status-badge{
    display: inline-block;
    background: #2b9348;
    color: white;
    padding: 8px 20px;
    border-radius: 50px;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 20px;
}
.ticket-check-form{
    display: flex;
    gap: 10px;
    margin-top: 20px;
}
.ticket-check-form input{
    flex: 1;
    padding: 12px 16px;
    border: 1px solid #ddd;
    border-radius: 12px;
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
    .ticket-card{padding: 24px;}
    .tab-btn{padding: 8px 18px; font-size: 14px;}
}
@media(max-width:575px){
    .section-title{font-size: 28px;}
    .page-header h1{font-size: 30px;}
    .ticket-check-form{flex-direction: column;}
}
</style>
</head>
<body>

<?php include 'includes/nav.php'; ?>

<section class="page-header">
    <div class="container" data-aos="fade-up">
        <h1>We're here to <span class="text-yellow">support</span> you</h1>
        <p>Get technical help, report issues, or find answers in our knowledge base.</p>
    </div>
</section>

<!-- SUPPORT TABS -->
<div class="support-tabs">
    <div class="container">
        <div class="tab-buttons">
            <button class="tab-btn active" onclick="switchTab('ticket')"><i class="fa-regular fa-ticket me-2"></i> Submit Ticket</button>
            <button class="tab-btn" onclick="switchTab('knowledgebase')"><i class="fa-regular fa-book me-2"></i> Knowledge Base</button>
            <button class="tab-btn" onclick="switchTab('channels')"><i class="fa-regular fa-headset me-2"></i> Support Channels</button>
            <button class="tab-btn" onclick="switchTab('status')"><i class="fa-regular fa-circle-check me-2"></i> Check Status</button>
        </div>
    </div>
</div>

<!-- TAB 1: SUBMIT TICKET -->
<div id="ticketTab" class="tab-content active">
    <div class="container">
        <div class="ticket-form-container" data-aos="fade-up">
            <div class="ticket-card">
                <h2 class="text-center mb-4">Submit a support request</h2>
                <p class="text-center text-muted mb-4">Fill out the form below and our support team will respond within 24 hours.</p>
                
                <form id="supportTicketForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email Address *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Priority *</label>
                                <select class="form-select" name="priority" required>
                                    <option value="low">Low - General inquiry</option>
                                    <option value="medium">Medium - Feature question</option>
                                    <option value="high">High - Technical issue</option>
                                    <option value="urgent">Urgent - System down</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Category *</label>
                                <select class="form-select" name="category" required>
                                    <option>Login & Account Issues</option>
                                    <option>Daily Reporting</option>
                                    <option>Cost & Procurement</option>
                                    <option>Workforce Management</option>
                                    <option>Document Management</option>
                                    <option>Billing & Subscription</option>
                                    <option>Feature Request</option>
                                    <option>Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Project Name (if applicable)</label>
                                <input type="text" class="form-control" name="project">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group">
                                <label>Subject *</label>
                                <input type="text" class="form-control" name="subject" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group">
                                <label>Description *</label>
                                <textarea rows="5" class="form-control" name="description" placeholder="Please provide detailed information about your issue..." required></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group">
                                <label>Attachments (optional)</label>
                                <div class="attachment-area" onclick="document.getElementById('fileInput').click()">
                                    <i class="fa-regular fa-cloud-upload fa-2x mb-2"></i>
                                    <p>Click to upload or drag and drop</p>
                                    <small class="text-muted">Max file size: 10MB (Images, PDF, Excel)</small>
                                    <input type="file" id="fileInput" style="display:none" multiple>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn-submit-ticket"><i class="fa-regular fa-paper-plane me-2"></i> Submit Ticket</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- TAB 2: KNOWLEDGE BASE -->
<div id="knowledgebaseTab" class="tab-content">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Knowledge base</h2>
        <p class="section-subtitle" data-aos="fade-up">Browse articles, guides, and tutorials to find answers quickly.</p>
        
        <div class="kb-grid" data-aos="fade-up">
            <div class="kb-category">
                <h3><i class="fa-regular fa-rocket"></i> Getting Started</h3>
                <ul class="kb-articles">
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> How to create your account</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Setting up your first project</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Inviting team members</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Understanding user roles</a></li>
                </ul>
            </div>
            <div class="kb-category">
                <h3><i class="fa-regular fa-clipboard"></i> Daily Reporting</h3>
                <ul class="kb-articles">
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Creating Daily Progress Reports</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Managing RFIs and MOMs</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Adding photos and documents</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Scheduling recurring reports</a></li>
                </ul>
            </div>
            <div class="kb-category">
                <h3><i class="fa-solid fa-chart-line"></i> Cost & Procurement</h3>
                <ul class="kb-articles">
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Setting up project budgets</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Creating purchase orders</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Vendor management guide</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Tracking expenses</a></li>
                </ul>
            </div>
            <div class="kb-category">
                <h3><i class="fa-solid fa-users"></i> Workforce Management</h3>
                <ul class="kb-articles">
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Managing attendance</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Payroll processing</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Contractor management</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Labour skill tracking</a></li>
                </ul>
            </div>
            <div class="kb-category">
                <h3><i class="fa-solid fa-mobile-screen"></i> Mobile App</h3>
                <ul class="kb-articles">
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Mobile access guide</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Offline mode usage</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Push notifications setup</a></li>
                </ul>
            </div>
            <div class="kb-category">
                <h3><i class="fa-solid fa-gear"></i> Troubleshooting</h3>
                <ul class="kb-articles">
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Common login issues</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Browser compatibility</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Data synchronization errors</a></li>
                    <li><a href="#"><i class="fa-regular fa-file-lines"></i> Performance optimization</a></li>
                </ul>
            </div>
        </div>
        
        <div class="text-center mt-5">
            <a href="#" class="btn btn-outline-dark-custom">View all articles <i class="fa-regular fa-arrow-right ms-2"></i></a>
        </div>
    </div>
</div>

<!-- TAB 3: SUPPORT CHANNELS -->
<div id="channelsTab" class="tab-content">
    <div class="container">
        <h2 class="section-title" data-aos="fade-up">Support channels</h2>
        <p class="section-subtitle" data-aos="fade-up">Choose the best way to reach us based on your needs.</p>
        
        <div class="channel-grid" data-aos="fade-up">
            <div class="channel-card">
                <div class="channel-icon"><i class="fa-regular fa-message"></i></div>
                <h4>Live Chat</h4>
                <p>Quick answers for general questions</p>
                <p class="detail">Response: &lt; 5 min</p>
                <p>Mon-Fri, 9AM - 6PM IST</p>
                <a href="#">Start chat →</a>
            </div>
            <div class="channel-card">
                <div class="channel-icon"><i class="fa-regular fa-envelope"></i></div>
                <h4>Email Support</h4>
                <p>For detailed inquiries and documentation</p>
                <p class="detail">Response: 24 hours</p>
                <p>support@tekcsoftware.com</p>
                <a href="mailto:support@tekcsoftware.com">Send email →</a>
            </div>
            <div class="channel-card">
                <div class="channel-icon"><i class="fa-solid fa-phone"></i></div>
                <h4>Phone Support</h4>
                <p>For urgent technical issues</p>
                <p class="detail">Response: Immediate</p>
                <p>+91 1800 123 4567</p>
                <a href="tel:+9118001234567">Call now →</a>
            </div>
            <div class="channel-card">
                <div class="channel-icon"><i class="fa-brands fa-whatsapp"></i></div>
                <h4>WhatsApp Support</h4>
                <p>Quick messages and media sharing</p>
                <p class="detail">Response: 30 min</p>
                <p>+91 98765 43210</p>
                <a href="https://wa.me/919876543210">Message us →</a>
            </div>
        </div>
        
        <div class="row mt-5">
            <div class="col-md-6 mx-auto">
                <div class="channel-card" style="background:#fefaf2;">
                    <div class="channel-icon"><i class="fa-regular fa-clock"></i></div>
                    <h4>24/7 Emergency Support</h4>
                    <p>For critical system outages affecting live projects</p>
                    <p class="detail">Emergency Hotline: +91 99999 88888</p>
                    <p class="small text-muted">Available 24x7 for Enterprise plans</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TAB 4: CHECK STATUS -->
<div id="statusTab" class="tab-content">
    <div class="container">
        <div class="status-card" data-aos="fade-up">
            <h3>Check ticket status</h3>
            <div class="status-badge">
                <i class="fa-regular fa-circle-check me-1"></i> System Status: All Systems Operational
            </div>
            <p>Enter your ticket ID to check the status of your support request.</p>
            <div class="ticket-check-form">
                <input type="text" placeholder="Enter Ticket ID (e.g., #TKT-12345)" id="ticketId">
                <button class="btn-yellow" onclick="checkTicketStatus()">Check Status</button>
            </div>
            <div id="ticketStatusResult" class="mt-3" style="display:none;">
                <div class="alert alert-info">
                    <strong>Ticket Status:</strong> <span id="statusText"></span>
                </div>
            </div>
            <p class="small text-muted mt-4">Don't have a ticket ID? <a href="#" onclick="switchTab('ticket'); return false;">Submit a new ticket</a></p>
        </div>
        
        <div class="row mt-5">
            <div class="col-md-6 mx-auto">
                <div class="channel-card text-center" style="background:#f8fafc;">
                    <i class="fa-solid fa-chart-line fa-2x text-yellow mb-3"></i>
                    <h4>System Status Dashboard</h4>
                    <p>Check real-time status of TEK-C services</p>
                    <a href="#" class="btn-yellow" style="display:inline-block; padding:10px 24px;">View Status →</a>
                </div>
            </div>
        </div>
    </div>
</div>

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

function switchTab(tabName) {
    // Hide all tabs
    document.getElementById('ticketTab').classList.remove('active');
    document.getElementById('knowledgebaseTab').classList.remove('active');
    document.getElementById('channelsTab').classList.remove('active');
    document.getElementById('statusTab').classList.remove('active');
    
    // Show selected tab
    if(tabName === 'ticket') document.getElementById('ticketTab').classList.add('active');
    else if(tabName === 'knowledgebase') document.getElementById('knowledgebaseTab').classList.add('active');
    else if(tabName === 'channels') document.getElementById('channelsTab').classList.add('active');
    else if(tabName === 'status') document.getElementById('statusTab').classList.add('active');
    
    // Update button active states
    const buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
}

// Ticket form submission
document.getElementById('supportTicketForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    alert('Thank you for submitting a support ticket! Our team will respond within 24 hours.\n\nTicket reference: #TKT-' + Math.floor(Math.random() * 90000 + 10000));
    this.reset();
    document.getElementById('fileInput').value = '';
});

// File attachment handler
document.getElementById('fileInput')?.addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    if(files.length > 0) {
        alert(files.length + ' file(s) selected. Maximum 10MB total.');
    }
});

// Ticket status check
function checkTicketStatus() {
    const ticketId = document.getElementById('ticketId').value;
    if(!ticketId) {
        alert('Please enter a ticket ID');
        return;
    }
    
    const statuses = ['Open - Being reviewed', 'In Progress - Assigned to specialist', 'Resolved - Check your email', 'Closed'];
    const randomStatus = statuses[Math.floor(Math.random() * statuses.length)];
    
    document.getElementById('statusText').innerText = randomStatus;
    document.getElementById('ticketStatusResult').style.display = 'block';
}

// Drag and drop for attachments
const dropArea = document.querySelector('.attachment-area');
if(dropArea) {
    dropArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropArea.style.borderColor = 'var(--yellow)';
        dropArea.style.background = '#fffef8';
    });
    dropArea.addEventListener('dragleave', () => {
        dropArea.style.borderColor = '#ddd';
        dropArea.style.background = '';
    });
    dropArea.addEventListener('drop', (e) => {
        e.preventDefault();
        dropArea.style.borderColor = '#ddd';
        const files = e.dataTransfer.files;
        document.getElementById('fileInput').files = files;
        alert(files.length + ' file(s) attached.');
    });
}

// Active link highlight for support
const navLinks = document.querySelectorAll('.navbar-nav .nav-link');
navLinks.forEach(link => {
    if(link.getAttribute('href') === '#support'){
        link.classList.add('active');
    }
});
</script>
</body>
</html>