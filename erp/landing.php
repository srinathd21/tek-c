<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>TEK-C | Construction Management Software</title>
  <meta name="description" content="TEK-C Construction Management Software — centralized tracking, role-based access, reporting, and documentation for UKB workflows." />

  <!-- Optional: Bootstrap (used only for icons + tiny utilities). Remove if you want pure CSS. -->
  <link rel="preconnect" href="https://cdn.jsdelivr.net" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <style>
    :root{
      --brand:#F9C52A;        /* TEK-C yellow */
      --ink:#0f172a;          /* near-black */
      --muted:#64748b;        /* slate */
      --bg:#f6f7fb;           /* light background */
      --card:#ffffff;
      --line:#e7e9f2;
      --shadow: 0 18px 45px rgba(2,6,23,.08);
      --shadow2: 0 10px 28px rgba(2,6,23,.08);
      --radius: 18px;
      --radius2: 26px;
      --max: 1180px;
    }

    *{ box-sizing:border-box; }
    html,body{ height:100%; }
    body{
      margin:0;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color:var(--ink);
      background: #fff;
      line-height:1.55;
    }
    a{ color:inherit; text-decoration:none; }
    img{ max-width:100%; display:block; }

    /* Layout helpers */
    .container{ width:min(var(--max), calc(100% - 44px)); margin-inline:auto; }
    .grid{ display:grid; gap:22px; }
    .btn{
      display:inline-flex; align-items:center; justify-content:center; gap:10px;
      padding:12px 18px;
      border-radius: 14px;
      font-weight:900;
      border:1px solid transparent;
      transition:.15s ease;
      cursor:pointer;
      user-select:none;
      white-space:nowrap;
    }
    .btn-primary{ background: var(--brand); color: #111827; box-shadow: 0 12px 26px rgba(249,197,42,.34); }
    .btn-primary:hover{ transform: translateY(-1px); filter: brightness(.98); }
    .btn-ghost{ background: rgba(255,255,255,.72); border-color: rgba(2,6,23,.12); }
    .btn-ghost:hover{ transform: translateY(-1px); background:#fff; }
    .pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 12px;
      border-radius: 999px;
      background: rgba(249,197,42,.18);
      border: 1px solid rgba(249,197,42,.35);
      font-weight:900;
      font-size:12px;
      color:#111827;
    }
    .badge{
      display:inline-flex; align-items:center; justify-content:center;
      padding:6px 10px;
      font-size:12px;
      font-weight:900;
      color:#0b1223;
      background: rgba(2,6,23,.06);
      border: 1px solid rgba(2,6,23,.10);
      border-radius: 999px;
    }
    .card{
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow2);
    }
    .muted{ color: var(--muted); }
    .h2{
      margin:0 0 8px;
      font-size: clamp(24px, 2.2vw, 34px);
      letter-spacing:-.5px;
      font-weight:1100;
      line-height:1.1;
    }
    .lead{
      margin:0;
      font-size: 14px;
      font-weight: 850;
      color: rgba(15,23,42,.74);
      max-width: 62ch;
    }

    /* Top bar / Navbar */
    .topbar{
      background:#fff;
      border-bottom: 1px solid rgba(2,6,23,.08);
      position: sticky;
      top:0;
      z-index: 20;
    }
    .nav{
      display:flex; align-items:center; justify-content:space-between;
      padding: 14px 0;
      gap: 18px;
    }
    .brand{
      display:flex; align-items:center; gap:12px;
      min-width: 240px;
    }
    .logo{
      width: 44px; height: 44px; border-radius: 14px;
      background: var(--brand);
      display:grid; place-items:center;
      font-weight:1200;
      box-shadow: 0 12px 26px rgba(249,197,42,.30);
    }
    .brand b{ display:block; font-size:14px; line-height:1.1; }
    .brand span{ display:block; font-size:12px; font-weight:900; color: rgba(15,23,42,.70); margin-top:2px; }
    .navlinks{
      display:flex; align-items:center; gap:18px;
      font-weight:950;
      color: rgba(15,23,42,.80);
    }
    .navlinks a{ padding:8px 10px; border-radius: 12px; }
    .navlinks a:hover{ background: rgba(2,6,23,.04); }
    .navright{ display:flex; align-items:center; gap:10px; }
    .navmeta{
      display:flex; align-items:center; gap:12px;
      color: rgba(15,23,42,.72);
      font-weight: 900;
      font-size: 12px;
    }
    .navmeta i{ opacity:.9; }

    /* Hero (like the uploaded “Construction Planning & Management” split) */
    .hero{
      background: linear-gradient(180deg, #ffffff 0%, var(--bg) 100%);
      position: relative;
      overflow:hidden;
    }
    .hero::before{
      content:"";
      position:absolute; inset:-160px -220px auto auto;
      width: 520px; height: 520px;
      background: radial-gradient(circle at 30% 30%, rgba(249,197,42,.35), rgba(249,197,42,0) 65%);
      transform: rotate(10deg);
      pointer-events:none;
    }
    .hero::after{
      content:"";
      position:absolute; inset:auto auto -240px -240px;
      width: 660px; height: 660px;
      background: radial-gradient(circle at 35% 35%, rgba(2,6,23,.10), rgba(2,6,23,0) 62%);
      pointer-events:none;
    }
    .hero-wrap{
      padding: 58px 0 44px;
      display:grid;
      grid-template-columns: 1.05fr .95fr;
      gap: 26px;
      align-items:center;
      position:relative;
      z-index:1;
    }
    .hero h1{
      margin: 14px 0 10px;
      font-size: clamp(32px, 3.4vw, 54px);
      line-height:1.02;
      letter-spacing: -1px;
      font-weight: 1200;
    }
    .hero p{
      margin: 0 0 20px;
      color: rgba(15,23,42,.74);
      font-weight: 850;
      font-size: 14px;
      max-width: 66ch;
    }
    .hero-actions{
      display:flex; gap:12px; flex-wrap:wrap;
      margin-top: 18px;
    }
    .hero-stats{
      margin-top: 18px;
      display:flex; flex-wrap:wrap; gap:10px;
    }

    /* Device mockups (no external images needed) */
    .devices{
      position:relative;
      min-height: 420px;
    }
    .device{
      position:absolute;
      border-radius: 26px;
      background: #0b1223;
      box-shadow: var(--shadow);
      border: 1px solid rgba(255,255,255,.10);
      overflow:hidden;
    }
    .device .bar{
      height: 44px;
      background: linear-gradient(90deg, rgba(255,255,255,.10), rgba(255,255,255,.04));
      display:flex; align-items:center; justify-content:space-between;
      padding: 0 14px;
      color: rgba(255,255,255,.75);
      font-weight: 900;
      font-size: 12px;
    }
    .dots{ display:flex; gap:6px; }
    .dots span{ width:10px; height:10px; border-radius:999px; background: rgba(255,255,255,.18); }
    .device .screen{
      background: linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.02));
      padding: 14px;
      height: calc(100% - 44px);
    }
    .kpi-grid{
      display:grid; grid-template-columns: repeat(3,1fr); gap:10px;
      margin-bottom: 12px;
    }
    .kpi{
      background: rgba(255,255,255,.07);
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 14px;
      padding: 10px;
    }
    .kpi b{ color:#fff; font-weight:1100; font-size: 13px; display:block; }
    .kpi span{ color: rgba(255,255,255,.70); font-size: 11px; font-weight: 850; }
    .list{
      background: rgba(255,255,255,.06);
      border: 1px solid rgba(255,255,255,.10);
      border-radius: 16px;
      padding: 10px;
      display:grid;
      gap: 8px;
    }
    .row{
      background: rgba(255,255,255,.06);
      border-radius: 12px;
      padding: 10px;
      display:flex; justify-content:space-between; gap:10px;
      color: rgba(255,255,255,.78);
      font-weight: 850;
      font-size: 12px;
    }
    .row em{ font-style:normal; color:#fff; font-weight:1000; }

    .device.desktop{ width: 520px; height: 330px; right: 0; top: 10px; }
    .device.laptop{ width: 420px; height: 270px; right: 40px; bottom: 0; border-radius: 22px; }
    .device.tablet{ width: 230px; height: 320px; left: 18px; bottom: 18px; border-radius: 22px; }
    .device.phone{ width: 140px; height: 280px; left: 0; bottom: 0; border-radius: 22px; }

    .float-card{
      position:absolute;
      left: 32px;
      top: 16px;
      background: rgba(255,255,255,.90);
      border: 1px solid rgba(2,6,23,.10);
      border-radius: 16px;
      box-shadow: var(--shadow2);
      padding: 12px 14px;
      width: 240px;
      backdrop-filter: blur(6px);
    }
    .float-card b{ display:block; font-weight:1100; }
    .float-card small{ color: rgba(15,23,42,.70); font-weight: 900; }

    /* Section spacing */
    section{ padding: 56px 0; }
    .section-head{
      display:flex; align-items:flex-end; justify-content:space-between;
      gap:18px; margin-bottom: 18px;
    }

    /* Feature blocks (like “Mobile Application Integration” / “Construction Mobile Apps”) */
    .split-section{
      background:#fff;
    }
    .split-wrap{
      display:grid;
      grid-template-columns: .95fr 1.05fr;
      gap: 22px;
      align-items:center;
    }
    .panel{
      border-radius: var(--radius2);
      border: 1px solid var(--line);
      background: linear-gradient(180deg, #fff 0%, #fbfbff 100%);
      box-shadow: var(--shadow2);
      overflow:hidden;
    }
    .panel .p-top{
      padding: 18px 18px 10px;
      display:flex; align-items:center; justify-content:space-between;
      gap:10px;
    }
    .panel .p-top b{ font-size: 14px; font-weight: 1100; }
    .panel .p-body{ padding: 0 18px 18px; }
    .checklist{
      margin: 10px 0 0;
      padding:0;
      list-style:none;
      display:grid;
      gap: 10px;
    }
    .checklist li{
      display:flex; gap:10px; align-items:flex-start;
      padding: 10px 12px;
      border: 1px solid rgba(2,6,23,.08);
      border-radius: 16px;
      background:#fff;
      box-shadow: 0 10px 22px rgba(2,6,23,.06);
      font-weight: 900;
      font-size: 13px;
      color: rgba(15,23,42,.86);
    }
    .checklist i{
      width: 28px; height: 28px; border-radius: 10px;
      display:grid; place-items:center;
      background: rgba(249,197,42,.22);
      border: 1px solid rgba(249,197,42,.35);
      color:#0b1223;
      flex: 0 0 auto;
      margin-top: 1px;
    }

    /* Implementation steps (like “Hassle-free implementation … 3 steps”) */
    .steps{
      background: var(--bg);
    }
    .steps-wrap{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 22px;
      align-items:center;
    }
    .illustration{
      border-radius: var(--radius2);
      background: linear-gradient(180deg, #fff 0%, #fef7dd 100%);
      border: 1px solid rgba(249,197,42,.30);
      box-shadow: var(--shadow);
      padding: 18px;
      position:relative;
      overflow:hidden;
      min-height: 360px;
    }
    .illus-blob{
      position:absolute; inset:auto -140px -140px auto;
      width: 360px; height: 360px; border-radius: 999px;
      background: radial-gradient(circle at 30% 30%, rgba(249,197,42,.55), rgba(249,197,42,0) 65%);
      transform: rotate(-10deg);
    }
    .illus-grid{
      position:relative;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 14px;
    }
    .illus-card{
      border-radius: 18px;
      background:#fff;
      border: 1px solid rgba(2,6,23,.08);
      box-shadow: 0 12px 26px rgba(2,6,23,.08);
      padding: 14px;
    }
    .illus-card b{ display:block; font-weight:1100; margin-bottom:4px; }
    .illus-card small{ color: rgba(15,23,42,.70); font-weight: 900; }

    .step-list{ display:grid; gap: 12px; }
    .step{
      padding: 14px 14px;
      border-radius: var(--radius);
      border: 1px solid rgba(2,6,23,.08);
      background:#fff;
      box-shadow: 0 12px 26px rgba(2,6,23,.06);
      display:flex; gap: 12px; align-items:flex-start;
    }
    .step .num{
      width: 44px; height: 44px; border-radius: 16px;
      background: rgba(249,197,42,.22);
      border: 1px solid rgba(249,197,42,.35);
      display:grid; place-items:center;
      font-weight:1200;
      flex: 0 0 auto;
    }
    .step b{ display:block; font-weight:1100; }
    .step p{ margin:6px 0 0; color: rgba(15,23,42,.70); font-weight: 850; font-size: 13px; }

    /* Quotation section */
    .quote{
      background:#fff;
    }
    .quote-wrap{
      display:grid;
      grid-template-columns: 1fr;
      gap: 18px;
    }
    .quote-head{
      padding: 18px;
      border-radius: var(--radius2);
      background: linear-gradient(90deg, rgba(249,197,42,.25), rgba(249,197,42,.06));
      border: 1px solid rgba(249,197,42,.35);
      box-shadow: var(--shadow2);
    }
    .quote-head .meta{
      display:grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-top: 12px;
    }
    .meta .m{
      background: rgba(255,255,255,.75);
      border: 1px solid rgba(2,6,23,.08);
      border-radius: 16px;
      padding: 12px;
      box-shadow: 0 10px 22px rgba(2,6,23,.06);
    }
    .meta .m small{ display:block; color: rgba(15,23,42,.70); font-weight: 900; }
    .meta .m b{ display:block; font-weight:1100; }

    .quote-cards{
      display:grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 18px;
      align-items:start;
    }
    .quote-cards .card{ padding: 16px; }
    .quote-cards h3{
      margin: 0 0 10px;
      font-size: 18px;
      font-weight:1100;
      letter-spacing:-.3px;
    }
    .quote-cards ul{
      margin:0;
      padding-left: 18px;
      color: rgba(15,23,42,.78);
      font-weight: 850;
      font-size: 13px;
    }
    .quote-cards li{ margin: 8px 0; }

    .table{
      width:100%;
      border-collapse: collapse;
      overflow:hidden;
      border-radius: 16px;
      border: 1px solid rgba(2,6,23,.10);
      background:#fff;
    }
    .table th, .table td{
      text-align:left;
      padding: 10px 12px;
      border-bottom: 1px solid rgba(2,6,23,.08);
      vertical-align: top;
      font-size: 13px;
      font-weight: 850;
      color: rgba(15,23,42,.84);
    }
    .table th{
      background: rgba(2,6,23,.04);
      font-weight: 1100;
      color: rgba(15,23,42,.92);
    }
    .table tr:last-child td{ border-bottom:none; }
    .money{ font-weight:1200; }

    /* CTA / Footer */
    .cta{
      background: linear-gradient(180deg, var(--bg) 0%, #fff 100%);
    }
    .cta-box{
      border-radius: var(--radius2);
      background: linear-gradient(90deg, #0b1223 0%, #111827 100%);
      color:#fff;
      border: 1px solid rgba(255,255,255,.12);
      box-shadow: var(--shadow);
      padding: 22px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 18px;
      flex-wrap: wrap;
    }
    .cta-box h3{ margin:0; font-size: 20px; font-weight:1100; letter-spacing:-.3px; }
    .cta-box p{ margin:6px 0 0; color: rgba(255,255,255,.75); font-weight: 850; font-size: 13px; }
    .cta-actions{ display:flex; gap: 10px; flex-wrap:wrap; }
    .btn-dark{
      background:#fff;
      color:#0b1223;
      border:1px solid rgba(255,255,255,.14);
      box-shadow: 0 12px 26px rgba(0,0,0,.20);
    }
    .btn-dark:hover{ transform: translateY(-1px); filter: brightness(.98); }

    footer{
      padding: 26px 0 36px;
      color: rgba(15,23,42,.70);
      font-weight: 900;
      font-size: 12px;
    }
    .foot{
      display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;
      border-top: 1px solid rgba(2,6,23,.08);
      padding-top: 14px;
    }

    /* Responsive */
    @media (max-width: 980px){
      .navlinks{ display:none; }
      .hero-wrap{ grid-template-columns: 1fr; }
      .devices{ min-height: 520px; }
      .device.desktop{ width: 92%; right: 0; left: 8%; }
      .device.laptop{ width: 78%; right: 6%; }
      .device.tablet{ left: 8%; }
      .device.phone{ left: 0; }
      .split-wrap{ grid-template-columns: 1fr; }
      .steps-wrap{ grid-template-columns: 1fr; }
      .quote-cards{ grid-template-columns: 1fr; }
      .quote-head .meta{ grid-template-columns: 1fr; }
    }
  </style>
</head>

<body>

<!-- NAVBAR -->
<header class="topbar">
  <div class="container">
    <div class="nav">
      <a class="brand" href="#top" aria-label="TEK-C Home">
        <div class="logo">TEK</div>
        <div>
          <b>TEK-C <span class="badge" style="margin-left:8px;">UKB Group</span></b>
          <span>Construction Management Software</span>
        </div>
      </a>

      <nav class="navlinks" aria-label="Main">
        <a href="#modules">Modules</a>
        <a href="#mobile">Mobile</a>
        <a href="#implementation">Implementation</a>
        <a href="#quotation">Quotation</a>
        <a href="#contact">Contact</a>
      </nav>

      <div class="navright">
        <div class="navmeta" aria-label="Contact">
          <span><i class="bi bi-telephone"></i> +91 72003 14099</span>
          <span><i class="bi bi-envelope"></i> info@hifi11.in</span>
        </div>
        <a class="btn btn-primary" href="#contact"><i class="bi bi-lightning-charge-fill"></i> Request Demo</a>
      </div>
    </div>
  </div>
</header>

<!-- HERO -->
<section class="hero" id="top">
  <div class="container">
    <div class="hero-wrap">
      <div>
        <span class="pill"><i class="bi bi-shield-check"></i> Role-based access • Secure documentation</span>
        <h1>Construction Planning &amp; Management — built for TEK-C workflows</h1>
        <p>
          Replace scattered Excel formats with a centralized, web-based platform for DPR, QS measurements,
          project tracking, document vault, checklists, and reporting—across every stage of construction.
        </p>

        <div class="hero-actions">
          <a class="btn btn-primary" href="#quotation"><i class="bi bi-file-earmark-text"></i> View Quotation</a>
          <a class="btn btn-ghost" href="#modules"><i class="bi bi-grid-3x3-gap"></i> Explore Modules</a>
        </div>

        <div class="hero-stats" aria-label="Highlights">
          <span class="badge"><i class="bi bi-journal-check" style="margin-right:6px;"></i>60–65 formats → auto reports</span>
          <span class="badge"><i class="bi bi-folder2-open" style="margin-right:6px;"></i>Document Vault + numbering</span>
          <span class="badge"><i class="bi bi-phone" style="margin-right:6px;"></i>Mobile-ready UI</span>
          <span class="badge"><i class="bi bi-clock-history" style="margin-right:6px;"></i>12–13 week delivery plan</span>
        </div>
      </div>

      <!-- Device mockups (visual similar to screenshot; no external images required) -->
      <div class="devices" aria-label="Product preview">
        <div class="float-card">
          <b>Live Project Dashboard</b>
          <small>Progress • Issues • Quality • Billing</small>
        </div>

        <div class="device desktop" aria-hidden="true">
          <div class="bar">
            <div class="dots"><span></span><span></span><span></span></div>
            <div>TEK-C Dashboard</div>
            <div><i class="bi bi-search"></i></div>
          </div>
          <div class="screen">
            <div class="kpi-grid">
              <div class="kpi"><b>Sites</b><span>12 Active</span></div>
              <div class="kpi"><b>DPR</b><span>Auto PDF</span></div>
              <div class="kpi"><b>QS</b><span>WPM + BOQ</span></div>
            </div>
            <div class="list">
              <div class="row"><span>Today DPR</span><em>Completed</em></div>
              <div class="row"><span>Open RFIs</span><em>7</em></div>
              <div class="row"><span>QC Issues</span><em>3</em></div>
              <div class="row"><span>Weekly Bill</span><em>Ready</em></div>
            </div>
          </div>
        </div>

        <div class="device laptop" aria-hidden="true" style="opacity:.95;">
          <div class="bar">
            <div class="dots"><span></span><span></span><span></span></div>
            <div>Reports</div>
            <div><i class="bi bi-download"></i></div>
          </div>
          <div class="screen">
            <div class="kpi-grid">
              <div class="kpi"><b>DPR-001</b><span>PDF</span></div>
              <div class="kpi"><b>MOM-002</b><span>PDF</span></div>
              <div class="kpi"><b>WPM</b><span>XLSX</span></div>
            </div>
            <div class="list">
              <div class="row"><span>Export</span><em>Excel / PDF</em></div>
              <div class="row"><span>Branding</span><em>UKB</em></div>
            </div>
          </div>
        </div>

        <div class="device tablet" aria-hidden="true" style="opacity:.92;">
          <div class="bar">
            <div class="dots"><span></span><span></span><span></span></div>
            <div>Site Tools</div>
            <div><i class="bi bi-list"></i></div>
          </div>
          <div class="screen">
            <div class="list">
              <div class="row"><span>Daily Photos</span><em>Upload</em></div>
              <div class="row"><span>Checklist</span><em>Fill</em></div>
              <div class="row"><span>Snag</span><em>Create</em></div>
              <div class="row"><span>RFI</span><em>Raise</em></div>
            </div>
          </div>
        </div>

        <div class="device phone" aria-hidden="true" style="opacity:.88;">
          <div class="bar">
            <div class="dots"><span></span><span></span><span></span></div>
            <div>TEK-C</div>
            <div><i class="bi bi-three-dots-vertical"></i></div>
          </div>
          <div class="screen">
            <div class="list">
              <div class="row"><span>DPR</span><em>+</em></div>
              <div class="row"><span>Attendance</span><em>✓</em></div>
              <div class="row"><span>Material</span><em>Log</em></div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- MODULES -->
<section id="modules">
  <div class="container">
    <div class="section-head">
      <div>
        <h2 class="h2">Complete Business Software Solutions — TEK-C Module Coverage</h2>
        <p class="lead">A full web application with documentation system, tailored to UKB workflows.</p>
      </div>
      <a class="btn btn-ghost" href="#contact"><i class="bi bi-chat-dots"></i> Talk to us</a>
    </div>

    <div class="grid" style="grid-template-columns: repeat(3, 1fr);">
      <div class="card" style="padding:16px;">
        <div class="pill" style="margin-bottom:10px;"><i class="bi bi-clock-history"></i> Time &amp; Progress</div>
        <ul class="muted" style="margin:0; padding-left:18px; font-weight:850; font-size:13px;">
          <li>DPR, WPT/WPM, workforce summary</li>
          <li>Delay report, MOM, work program tracker</li>
          <li>Daily/weekly photo documentation</li>
        </ul>
      </div>

      <div class="card" style="padding:16px;">
        <div class="pill" style="margin-bottom:10px;"><i class="bi bi-rulers"></i> Quantity Surveying (Basic)</div>
        <ul class="muted" style="margin:0; padding-left:18px; font-weight:850; font-size:13px;">
          <li>BOQ, measurement sheet entry</li>
          <li>Work progress measurement (WPM)</li>
          <li>Contractor bill summary, PQS reports</li>
        </ul>
      </div>

      <div class="card" style="padding:16px;">
        <div class="pill" style="margin-bottom:10px;"><i class="bi bi-kanban"></i> Project Management</div>
        <ul class="muted" style="margin:0; padding-left:18px; font-weight:850; font-size:13px;">
          <li>Issue register, RFI register</li>
          <li>Snag/Punch list, task tracking</li>
          <li>Basic project dashboard</li>
        </ul>
      </div>

      <div class="card" style="padding:16px;">
        <div class="pill" style="margin-bottom:10px;"><i class="bi bi-cone-striped"></i> Construction Management</div>
        <ul class="muted" style="margin:0; padding-left:18px; font-weight:850; font-size:13px;">
          <li>Labour allocation, asset tracking</li>
          <li>Material inward/outward logs</li>
          <li>Work orders, site instructions, safety log</li>
        </ul>
      </div>

      <div class="card" style="padding:16px;">
        <div class="pill" style="margin-bottom:10px;"><i class="bi bi-house-check"></i> Interior Fit-Out</div>
        <ul class="muted" style="margin:0; padding-left:18px; font-weight:850; font-size:13px;">
          <li>Shop drawing tracker, approvals</li>
          <li>Joinery/finish schedule tracking</li>
          <li>Fitout issue management</li>
        </ul>
      </div>

      <div class="card" style="padding:16px;">
        <div class="pill" style="margin-bottom:10px;"><i class="bi bi-folder2-open"></i> Document Vault + Reporting</div>
        <ul class="muted" style="margin:0; padding-left:18px; font-weight:850; font-size:13px;">
          <li>Folder-based management, tagging, search</li>
          <li>Auto numbering (DPR-001, MOM-002…)</li>
          <li>60–65 templates → auto PDF/Excel reports</li>
        </ul>
      </div>
    </div>

    <div style="margin-top:18px;" class="card">
      <div style="padding:16px 16px 0;">
        <div class="pill"><i class="bi bi-people"></i> HR + Accounts + Quality + Checklists</div>
      </div>
      <div style="padding:16px;">
        <div class="grid" style="grid-template-columns: repeat(4, 1fr); gap:12px;">
          <div class="badge" style="justify-content:flex-start; padding:10px 12px;"><i class="bi bi-person-badge" style="margin-right:8px;"></i>Employee Master</div>
          <div class="badge" style="justify-content:flex-start; padding:10px 12px;"><i class="bi bi-calendar2-check" style="margin-right:8px;"></i>Attendance + Leave</div>
          <div class="badge" style="justify-content:flex-start; padding:10px 12px;"><i class="bi bi-receipt" style="margin-right:8px;"></i>Weekly Bills (NMR + petty cash)</div>
          <div class="badge" style="justify-content:flex-start; padding:10px 12px;"><i class="bi bi-cash-stack" style="margin-right:8px;"></i>Accounts (basic ledger)</div>
          <div class="badge" style="justify-content:flex-start; padding:10px 12px;"><i class="bi bi-shield-exclamation" style="margin-right:8px;"></i>Quality (QIR + NC log)</div>
          <div class="badge" style="justify-content:flex-start; padding:10px 12px;"><i class="bi bi-ui-checks" style="margin-right:8px;"></i>Checklist Builder</div>
          <div class="badge" style="justify-content:flex-start; padding:10px 12px;"><i class="bi bi-download" style="margin-right:8px;"></i>Excel/PDF Export</div>
          <div class="badge" style="justify-content:flex-start; padding:10px 12px;"><i class="bi bi-lock" style="margin-right:8px;"></i>Role-Based Security</div>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- MOBILE SECTION (like the screenshot) -->
<section class="split-section" id="mobile">
  <div class="container">
    <div class="split-wrap">
      <div class="panel">
        <div class="p-top">
          <b><i class="bi bi-phone" style="margin-right:8px;"></i>Mobile Application Integration</b>
          <span class="badge">iOS &amp; Android ready</span>
        </div>
        <div class="p-body">
          <h2 class="h2" style="margin-top:0;">Construction Mobile Apps for Project Sites</h2>
          <p class="lead">
            Use TEK-C on-site to capture DPR updates, photos, RFIs, checklists, and approvals—fast, secure, and searchable.
          </p>

          <ul class="checklist">
            <li><i class="bi bi-file-earmark-arrow-down"></i> Export to Excel, CSV, or PDF</li>
            <li><i class="bi bi-person-lock"></i> Role-based user access</li>
            <li><i class="bi bi-shield-lock"></i> User authentication &amp; security</li>
            <li><i class="bi bi-layout-text-sidebar"></i> Custom dashboards per role</li>
            <li><i class="bi bi-buildings"></i> Multi-company / intercompany support</li>
            <li><i class="bi bi-search"></i> Search invoices, RFIs, reports, and documents</li>
          </ul>

          <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
            <a class="btn btn-primary" href="#contact"><i class="bi bi-box-arrow-in-right"></i> Get Demo Access</a>
            <a class="btn btn-ghost" href="#implementation"><i class="bi bi-diagram-3"></i> See Implementation</a>
          </div>
        </div>
      </div>

      <!-- Visual block -->
      <div class="panel" style="background: linear-gradient(180deg, #ffffff 0%, #f6f7fb 100%);">
        <div class="p-body" style="padding-top:18px;">
          <div class="card" style="border-radius:26px; padding:16px; box-shadow: var(--shadow);">
            <div class="pill"><i class="bi bi-lightning-charge"></i> On-site capture → Auto reports</div>
            <div style="margin-top:12px;" class="grid">
              <div class="badge" style="justify-content:flex-start; padding:12px 12px;">
                <i class="bi bi-camera" style="margin-right:8px;"></i> Photo documentation
              </div>
              <div class="badge" style="justify-content:flex-start; padding:12px 12px;">
                <i class="bi bi-journal-check" style="margin-right:8px;"></i> DPR &amp; MOM
              </div>
              <div class="badge" style="justify-content:flex-start; padding:12px 12px;">
                <i class="bi bi-clipboard2-check" style="margin-right:8px;"></i> Quality + checklists
              </div>
              <div class="badge" style="justify-content:flex-start; padding:12px 12px;">
                <i class="bi bi-exclamation-diamond" style="margin-right:8px;"></i> Snag + RFI
              </div>
            </div>
            <div style="margin-top:12px;" class="muted">
              <b style="color:var(--ink); font-weight:1100;">Tip:</b> Keep all site records inside TEK-C Document Vault with role-based security and unique numbering.
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- IMPLEMENTATION (3 steps) -->
<section class="steps" id="implementation">
  <div class="container">
    <div class="section-head">
      <div>
        <h2 class="h2">Hassle-free implementation of TEK-C in just 3 steps</h2>
        <p class="lead">Great outcomes start with great conversations—discover, train, and take off with confidence.</p>
      </div>
      <span class="badge"><i class="bi bi-award" style="margin-right:6px;"></i>Complete documentation included</span>
    </div>

    <div class="steps-wrap">
      <div class="illustration" aria-label="Implementation illustration">
        <div class="illus-blob"></div>
        <div class="pill"><i class="bi bi-diagram-3"></i> Implementation Roadmap</div>

        <div class="illus-grid">
          <div class="illus-card">
            <b>Roles &amp; Menus</b>
            <small>Admin • HR • QS • Accounts • Managers • Engineers</small>
          </div>
          <div class="illus-card">
            <b>Reports Engine</b>
            <small>Auto PDF/XLSX with UKB branding</small>
          </div>
          <div class="illus-card">
            <b>Document Vault</b>
            <small>Search • tagging • numbering • security</small>
          </div>
          <div class="illus-card">
            <b>Site Workflow</b>
            <small>DPR • photos • QC • weekly bills</small>
          </div>
        </div>

        <div style="margin-top:14px;" class="muted">
          <b style="color:var(--ink); font-weight:1100;">Tech stack:</b> Laravel / MySQL / Bootstrap / PHPSpreadsheet / mPDF (responsive).
        </div>
      </div>

      <div class="step-list">
        <div class="step">
          <div class="num">1</div>
          <div>
            <b>Discover</b>
            <p>We gather requirements and map TEK-C modules to your exact UKB business process and Excel formats.</p>
          </div>
        </div>

        <div class="step">
          <div class="num">2</div>
          <div>
            <b>Training</b>
            <p>Train, test, and migrate—validate workflows, roles, permissions, and reporting templates with your team.</p>
          </div>
        </div>

        <div class="step">
          <div class="num">3</div>
          <div>
            <b>Take off</b>
            <p>Final validation, QA, and deployment support—go live with a secure documentation system and reporting engine.</p>
          </div>
        </div>

        <div class="card" style="padding:14px;">
          <div class="pill"><i class="bi bi-clock"></i> Project timeline</div>
          <p class="muted" style="margin:10px 0 0; font-weight:850; font-size:13px;">
            12–13 weeks with phased delivery (urgent modules first) and parallel architecture/DB design.
          </p>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- QUOTATION (product details) -->
<section class="quote" id="quotation">
  <div class="container">
    <div class="quote-wrap">

      <div class="quote-head">
        <div class="pill"><i class="bi bi-file-earmark-text"></i> QUOTATION FOR CUSTOM CONSTRUCTION MANAGEMENT SOFTWARE</div>
        <h2 class="h2" style="margin-top:10px;">Complete Business Software Solutions — Proposal for Web-Based Construction Management Software</h2>
        <p class="lead">Scope: Full web application with complete documentation system (custom-built for UKB workflows).</p>

        <div class="meta">
          <div class="m">
            <small>Submitted by</small>
            <b>Ecommer.in – Complete Business Software Solutions</b>
            <small style="margin-top:6px;">Director Email</small>
            <b>director@hifi11.in</b>
          </div>
          <div class="m">
            <small>Client</small>
            <b>UKB Construction Management Pvt Ltd</b>
            <small style="margin-top:6px;">Quotation No.</small>
            <b>QTN/UKB/2026-01</b>
          </div>
          <div class="m">
            <small>Date</small>
            <b>25/01/2026</b>
            <small style="margin-top:6px;">Phone</small>
            <b>7200314099</b>
          </div>
        </div>
      </div>

      <div class="quote-cards">

        <div class="card">
          <h3>1) Project Introduction</h3>
          <p class="muted" style="margin:0; font-weight:850; font-size:13px;">
            Development of a complete, customized Construction Management Software covering Quantity Surveying,
            Project Management, Construction Management, Interior Fitout, HR, Accounts, Weekly Billing, Quality,
            Checklists, and Reporting. The system replaces Excel-based formats with a centralized web solution.
          </p>

          <div style="height:12px;"></div>

          <h3>2) Scope — Full Module Coverage (Summary)</h3>
          <ul>
            <li><b>Time &amp; Progress:</b> DPR, WPT/WPM, MOM, delay reports, work program tracker, photo documentation.</li>
            <li><b>QS (Basic):</b> BOQ, measurement, WPM, contractor billing, PQS auto reports.</li>
            <li><b>Project Management:</b> Issues, RFIs, snag/punch list, tasks, basic dashboard.</li>
            <li><b>Construction:</b> Labour, equipment/assets, materials log, work orders, safety incidents.</li>
            <li><b>Interior Fitout:</b> Drawing tracker, approvals, joinery/finish schedule, fitout issues.</li>
            <li><b>HR &amp; Admin:</b> Employee master, attendance, leave, salary export, staff documents, allocations.</li>
            <li><b>Accounts (Basic):</b> Vendor bills, contractor payments, petty cash, expenses, cashflow ledger.</li>
            <li><b>Weekly Bills:</b> NMR labour entry, weekly petty cash, weekly summary, Excel/PDF download.</li>
            <li><b>Quality:</b> Pour card, QIR, QC checklists, non-conformance log.</li>
            <li><b>Checklists:</b> Soil nail, shotcrete, structural/QC, custom builder, PDF export.</li>
            <li><b>Document Vault:</b> Folder-based, file numbering, tagging/search, role-based security.</li>
            <li><b>Reporting Engine:</b> Convert 60–65 templates into auto PDF/Excel reports with UKB branding.</li>
          </ul>
        </div>

        <div class="card">
          <h3>3) Technology Stack</h3>
          <ul>
            <li>PHP Laravel (secured applications)</li>
            <li>MySQL database</li>
            <li>HTML, CSS, Bootstrap, jQuery</li>
            <li>PHPSpreadsheet for Excel</li>
            <li>mPDF / TcPdf / DOMPdf for PDF reports</li>
            <li>Fully responsive web interface</li>
          </ul>

          <div style="height:12px;"></div>

          <h3>4) Project Timeline (12–13 Weeks)</h3>
          <table class="table" aria-label="Timeline table">
            <thead>
              <tr>
                <th style="width:28%;">Phase</th>
                <th>Deliverables</th>
                <th style="width:18%;">Duration</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><b>Phase 1 (Urgent)</b></td>
                <td>DAR, MPT, MOM, PEC, DPR (working modules + PDF reports)</td>
                <td><b>2 Weeks</b></td>
              </tr>
              <tr>
                <td><b>Phase 2</b></td>
                <td>Architecture + DB design (parallel completion)</td>
                <td><b>1 Week</b></td>
              </tr>
              <tr>
                <td><b>Phase 3</b></td>
                <td>Reporting module (remaining formats)</td>
                <td><b>2 Weeks</b></td>
              </tr>
              <tr>
                <td><b>Phase 4</b></td>
                <td>HR &amp; Admin module</td>
                <td><b>2 Weeks</b></td>
              </tr>
              <tr>
                <td><b>Phase 5</b></td>
                <td>QS + Project Management modules</td>
                <td><b>2.5 Weeks</b></td>
              </tr>
              <tr>
                <td><b>Phase 6</b></td>
                <td>Construction + Interior Fitout modules</td>
                <td><b>1.5 Weeks</b></td>
              </tr>
              <tr>
                <td><b>Phase 7</b></td>
                <td>Quality + Checklists module</td>
                <td><b>1 Week</b></td>
              </tr>
              <tr>
                <td><b>Phase 8</b></td>
                <td>Accounts + Weekly Bills module</td>
                <td><b>1 Week</b></td>
              </tr>
              <tr>
                <td><b>Phase 9</b></td>
                <td>Final testing, QA, deployment support</td>
                <td><b>1 Week</b></td>
              </tr>
            </tbody>
          </table>

        </div>
      </div>

      <div class="card" style="padding:16px;">
        <h3 style="margin:0 0 10px; font-size:18px; font-weight:1100;">5) Price Breakdown (₹)</h3>
        <div class="grid" style="grid-template-columns: 1fr; gap:12px;">
          <table class="table" aria-label="Price breakdown table">
            <thead>
              <tr>
                <th>Module</th>
                <th style="width:18%;">Cost</th>
                <th>Justification</th>
              </tr>
            </thead>
            <tbody>
              <tr><td>Time &amp; Progress Module</td><td class="money">30,000</td><td>High-frequency reports + PDF/Excel + photo handling.</td></tr>
              <tr><td>HR &amp; Admin Module</td><td class="money">25,000</td><td>Attendance/leave logic + staff master + payroll export.</td></tr>
              <tr><td>QS Module (Basic)</td><td class="money">30,000</td><td>BOQ + measurement + WPM + billing logic.</td></tr>
              <tr><td>Project Management Module</td><td class="money">15,000</td><td>Issue/RFI/Snag workflows + dashboard.</td></tr>
              <tr><td>Construction Management Module</td><td class="money">15,000</td><td>Material, labour, equipment, work orders.</td></tr>
              <tr><td>Interior Fit-Out Module</td><td class="money">10,000</td><td>Approvals, joinery tracker, fitout logs.</td></tr>
              <tr><td>Weekly Bills Module</td><td class="money">15,000</td><td>NMR + petty cash + weekly summaries + PDF/Excel.</td></tr>
              <tr><td>Quality Management Module</td><td class="money">10,000</td><td>QIR + QC + non-conformance.</td></tr>
              <tr><td>Checklist Module</td><td class="money">10,000</td><td>Dynamic &amp; standard checklists + PDF.</td></tr>
              <tr><td>Accounts Module (Basic)</td><td class="money">15,000</td><td>Vendor/contractor payments + ledger.</td></tr>
              <tr><td>Document Vault</td><td class="money">10,000</td><td>File storage, search, numbering.</td></tr>
              <tr><td>Reporting Engine (25 Templates)</td><td class="money">15,000</td><td>Excel → DB → Auto PDF/XLSX integration.</td></tr>
              <tr><td>Architecture &amp; DB Setup</td><td class="money">10,000</td><td>Foundation, roles, menu structure.</td></tr>
              <tr><td>Testing &amp; Deployment Support</td><td class="money">10,000</td><td>QA, debugging, deployment configuration.</td></tr>
              <tr>
                <td><b>GRAND TOTAL</b></td>
                <td class="money"><b>1,90,000</b></td>
                <td><b>Complete Development Cost (Web Only)</b></td>
              </tr>
            </tbody>
          </table>

          <div class="grid" style="grid-template-columns: repeat(3, 1fr); gap:12px;">
            <div class="card" style="padding:14px; box-shadow:none;">
              <h3 style="margin:0 0 8px; font-size:16px; font-weight:1100;">6) Payment Terms</h3>
              <ul class="muted" style="margin:0; padding-left:18px; font-weight:850; font-size:13px;">
                <li>40% – Project Start (Advance)</li>
                <li>30% – After HR + Reporting completion</li>
                <li>20% – After QS + PM completion</li>
                <li>10% – After final delivery</li>
              </ul>
            </div>
            <div class="card" style="padding:14px; box-shadow:none;">
              <h3 style="margin:0 0 8px; font-size:16px; font-weight:1100;">7) Exclusions</h3>
              <ul class="muted" style="margin:0; padding-left:18px; font-weight:850; font-size:13px;">
                <li>Mobile application (Android/iOS)</li>
                <li>Domain &amp; hosting</li>
                <li>Tender comparison / estimation system</li>
                <li>Advanced payroll, GST automation</li>
              </ul>
            </div>
            <div class="card" style="padding:14px; box-shadow:none;">
              <h3 style="margin:0 0 8px; font-size:16px; font-weight:1100;">8) Validity</h3>
              <p class="muted" style="margin:0; font-weight:850; font-size:13px;">
                Quotation valid for <b>30 days</b> from the date of issue.
              </p>
              <div style="height:10px;"></div>
              <h3 style="margin:0 0 6px; font-size:16px; font-weight:1100;">9) Acceptance</h3>
              <p class="muted" style="margin:0; font-weight:850; font-size:13px;">
                If this meets your requirement, please approve to initiate the project.
              </p>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta" id="contact">
  <div class="container">
    <div class="cta-box">
      <div>
        <h3>Ready to launch TEK-C for UKB?</h3>
        <p>Request a demo + implementation plan (roles, modules, reporting templates, and documentation system).</p>
      </div>
      <div class="cta-actions">
        <a class="btn btn-primary" href="mailto:director@hifi11.in"><i class="bi bi-envelope-paper"></i> Email director@hifi11.in</a>
        <a class="btn btn-dark" href="tel:7200314099"><i class="bi bi-telephone"></i> Call 7200314099</a>
        <a class="btn btn-ghost" href="#top"><i class="bi bi-arrow-up"></i> Back to top</a>
      </div>
    </div>

    <footer>
      <div class="foot">
        <span>© 2026 TEK-C – A UKB Group Company • Secure Construction Management Platform</span>
        <span>Ecommer.in – Complete Business Software Solutions • www.hifi11.in • info@hifi11.in</span>
      </div>
    </footer>
  </div>
</section>

</body>
</html>