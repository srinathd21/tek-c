<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TEK-C Dashboard</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <style>
    /* ✅ KEEP ALL YOUR SAME CSS HERE (unchanged) */
    :root{
      --bg: #f4f6f9;
      --surface: #ffffff;
      --sidebar: #2f3a45;
      --yellow: #f2c94c;
      --blue:#2d9cdb;
      --orange:#f2994a;
      --green:#27ae60;
      --red:#eb5757;
      --muted:#6b7280;
      --border:#e9ecef;
      --shadow: 0 10px 28px rgba(31,41,55,.08);
      --radius: 16px;

      --topbar-h: 68px;
      --sidebar-w: 260px;
      --sidebar-w-collapsed: 86px;
    }

    html, body { height: 100%; }
    body{
      margin: 0;
      background: var(--bg);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji";
      color:#111827;
      overflow: hidden;
    }

    .app{ height: 100vh; display:flex; overflow:hidden; }

    .sidebar{
      width: var(--sidebar-w);
      background: var(--sidebar);
      color:#fff;
      padding: 15px 14px 18px;
      display:flex; flex-direction:column; gap:14px;
      height:100vh; overflow:auto;
      scrollbar-width: thin;
      scrollbar-color: rgba(255,255,255,.25) transparent;
      transition: width .2s ease, left .2s ease, padding .2s ease;
      flex: 0 0 auto;
    }
    .sidebar::-webkit-scrollbar { width: 10px; }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.18); border-radius: 10px; }
    .sidebar::-webkit-scrollbar-track { background: transparent; }

    .brand{ display:flex; align-items:center; gap:12px; padding:2px 8px 10px; min-height:56px; margin-top:-4px; }
    .brand-badge{ width:42px; height:42px; border-radius:10px; background: linear-gradient(135deg, var(--yellow), #ffd66b);
      display:grid; place-items:center; color:#1f2937; box-shadow:0 10px 20px rgba(242,201,76,.25);
      font-weight:900; flex:0 0 auto; overflow:hidden; }
    .brand-badge img{ width:100%; height:100%; object-fit:cover; display:block; }
    .brand-title{ font-size:22px; font-weight:800; letter-spacing:.5px; line-height:1; white-space:nowrap; margin-top:-2px; }

    .nav-section{ margin-top:6px; display:flex; flex-direction:column; gap:10px; }
    .side-link{ display:flex; align-items:center; gap:12px; padding:12px 12px; border-radius:12px; color:#dbe3ea; text-decoration:none; font-weight:700; transition:.15s ease; white-space:nowrap; }
    .side-link:hover{ background: rgba(255,255,255,.06); color:#fff; }
    .side-link i{ font-size:18px; opacity:.95; flex:0 0 auto; }
    .side-link .label{ overflow:hidden; text-overflow:ellipsis; }
    .side-link.active{ background: var(--yellow); color:#1f2937; box-shadow:0 12px 20px rgba(242,201,76,.18); }
    .side-link.active i{ color:#1f2937; }

    .sidebar-footer{ margin-top:auto; padding-top:10px; border-top:1px solid rgba(255,255,255,.08);
      font-size:12px; color:#cbd5e1; padding-left:8px; padding-bottom:6px; }

    .sidebar.collapsed{ width: var(--sidebar-w-collapsed); padding-left:10px; padding-right:10px; }
    .sidebar.collapsed .brand-title,
    .sidebar.collapsed .side-link .label,
    .sidebar.collapsed .sidebar-footer .footer-text{ display:none; }
    .sidebar.collapsed .side-link{ justify-content:center; padding:12px 10px; gap:0; }
    .sidebar.collapsed .side-link i{ font-size:20px; }
    .sidebar.collapsed .brand{ justify-content:center; padding-left:0; padding-right:0; }

    .main{ flex:1; display:flex; flex-direction:column; min-width:0; height:100vh; overflow:hidden; }

    .topbar{
      height: var(--topbar-h);
      display:flex; align-items:center; justify-content:space-between;
      padding: 12px 18px;
      background: #ffffffcc;
      backdrop-filter: blur(10px);
      border-bottom: 1px solid var(--border);
      position: sticky; top:0; z-index:20;
      flex: 0 0 auto;
    }

    .top-left{ display:flex; align-items:center; gap:10px; }
    .hamburger{ border:0; background:transparent; font-size:22px; padding:6px 10px; border-radius:12px; }
    .hamburger:hover{ background:#f3f4f6; }
    .dots{ display:flex; gap:6px; align-items:center; padding:6px 10px; border-radius:12px; color:#4b5563; }
    .dot{ width:6px; height:6px; border-radius:50%; background:#9ca3af; }

    .top-right{ display:flex; align-items:center; gap:10px; }
    .pill{ border:1px solid var(--border); background:#fff; border-radius:999px; padding:6px 10px; display:flex; align-items:center; gap:10px; box-shadow:0 8px 18px rgba(31,41,55,.05); }
    .avatar{ width:34px; height:34px; border-radius:50%; background: linear-gradient(135deg, var(--yellow), #ffd66b);
      display:grid; place-items:center; color:#1f2937; font-weight:900; flex:0 0 auto; }
    .user-meta{ line-height:1.05; }
    .user-meta .name{ font-weight:800; font-size:13px; }
    .user-meta .mail{ color: var(--muted); font-size:12px; }

    .icon-btn{ width:38px; height:38px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#4b5563; box-shadow:0 8px 18px rgba(31,41,55,.05); }
    .icon-btn:hover{ background:#f9fafb; }

    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

    .main-footer{ border-top:1px solid var(--border); background:#ffffffcc; backdrop-filter: blur(10px); padding:12px 22px; color:#6b7280; font-weight:700; font-size:12px; }

    .panel{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding:16px 16px 12px; height:100%; }
    .panel-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .panel-title{ font-weight:900; font-size:18px; color:#1f2937; margin:0; }
    .panel-menu{ width:36px; height:36px; border-radius:12px; border:1px solid var(--border); background:#fff; display:grid; place-items:center; color:#6b7280; }

    .stat-card{ background: var(--surface); border:1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow);
      padding:14px 16px; height:90px; display:flex; align-items:center; gap:14px; }
    .stat-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; color:#fff; font-size:20px; flex:0 0 auto; }
    .stat-ic.blue{ background: var(--blue); }
    .stat-ic.orange{ background: var(--orange); }
    .stat-ic.green{ background: var(--green); }
    .stat-ic.red{ background: var(--red); }
    .stat-label{ color:#4b5563; font-weight:750; font-size:13px; }
    .stat-value{ font-size:30px; font-weight:900; line-height:1; margin-top:2px; }

    .table thead th{ font-size:12px; letter-spacing:.2px; color:#6b7280; font-weight:800; border-bottom:1px solid var(--border)!important; }
    .table td{ vertical-align:middle; border-color: var(--border); font-weight:650; color:#374151; padding-top:14px; padding-bottom:14px; }

    .badge-pill{ border-radius:999px; padding:8px 12px; font-weight:900; font-size:12px; border:1px solid transparent; display:inline-flex; align-items:center; gap:8px; }
    .badge-pill .mini-dot{ width:8px; height:8px; border-radius:50%; background: currentColor; opacity:.9; }
    .ontrack{ color: var(--green); background: rgba(39,174,96,.12); border-color: rgba(39,174,96,.18); }
    .atrisk{ color: var(--red); background: rgba(235,87,87,.12); border-color: rgba(235,87,87,.18); }
    .delayed{ color:#b7791f; background: rgba(242,201,76,.20); border-color: rgba(242,201,76,.28); }

    .muted-link{ color:#6b7280; font-weight:800; text-decoration:none; }
    .muted-link:hover{ color:#374151; }

    .activity-item{ display:flex; gap:12px; padding:12px 0; border-top:1px solid var(--border); }
    .activity-item:first-child{ border-top:0; padding-top:6px; }
    .activity-avatar{ width:42px; height:42px; border-radius:50%; background: linear-gradient(135deg, var(--yellow), #ffd66b);
      display:grid; place-items:center; font-weight:900; color:#1f2937; flex:0 0 auto; }
    .activity-title{ font-weight:850; margin:0; color:#1f2937; font-size:14px; }
    .activity-sub{ margin:2px 0 0; color:#6b7280; font-weight:650; font-size:12px; }

    .chart-wrap{ height:190px; }
    .donut-wrap{ height:240px; }

    .legend{ display:flex; flex-wrap:wrap; gap:18px 26px; padding:6px 2px 4px; align-items:center; }
    .legend-item{ display:flex; align-items:center; gap:8px; font-weight:800; color:#374151; }
    .legend-dot{ width:10px; height:10px; border-radius:50%; background:#999; }

    .container-fluid.maxw{ max-width:1220px; margin-left:auto; margin-right:auto; }
    body.wide .container-fluid.maxw{ max-width:none !important; margin-left:0 !important; margin-right:0 !important; }

    .overlay{ display:none; position:fixed; inset:0; background: rgba(17,24,39,.45); z-index:25; }
    .overlay.show{ display:block; }

    @media (max-width: 991.98px){
      .sidebar{ position:fixed; left:-290px; top:0; z-index:30; box-shadow:0 20px 50px rgba(0,0,0,.25); }
      .sidebar.open{ left:0; }
      .sidebar.collapsed{ width: var(--sidebar-w); }
      body.wide .container-fluid.maxw{ max-width:1220px !important; margin-left:auto !important; margin-right:auto !important; }
      .content-scroll{ padding:18px; }
      .dots{ display:none !important; }
      .user-meta{ display:none !important; }
    }
  </style>
</head>

<body>
  <div class="app">

    <?php include 'includes/sidebar.php'; ?>

    <main class="main" aria-label="Main">

      <?php include 'includes/topbar.php'; ?>

      <div id="contentScroll" class="content-scroll">
        <div class="container-fluid maxw">

          <!-- Stats -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-folder2"></i></div>
                <div>
                  <div class="stat-label">Active Projects</div>
                  <div class="stat-value">12</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-clock-history"></i></div>
                <div>
                  <div class="stat-label">Upcoming Tasks</div>
                  <div class="stat-value">24</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-people-fill"></i></div>
                <div>
                  <div class="stat-label">On-Site Workers</div>
                  <div class="stat-value">256</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                  <div class="stat-label">Alerts</div>
                  <div class="stat-value">5</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Middle row -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Ongoing Projects</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>

                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th style="min-width:220px;">Project Name</th>
                        <th style="min-width:140px;">Status</th>
                        <th style="min-width:130px;">Start Date</th>
                        <th style="min-width:130px;">End Date</th>
                        <th class="text-end" style="width:60px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>Tower A Construction</td>
                        <td><span class="badge-pill ontrack"><span class="mini-dot"></span> On Track</span></td>
                        <td>06 Mar 2021</td>
                        <td>01 Jul 2021</td>
                        <td class="text-end"><a class="muted-link" href="#"><i class="bi bi-box-arrow-up-right"></i></a></td>
                      </tr>
                      <tr>
                        <td>Mall Renovation</td>
                        <td>
                          <span class="badge-pill ontrack" style="color:var(--orange); background: rgba(242,153,74,.14); border-color: rgba(242,153,74,.20);">
                            <span class="mini-dot"></span> On Track
                          </span>
                        </td>
                        <td>06 Mar 2021</td>
                        <td>01 Jul 2021</td>
                        <td class="text-end"><a class="muted-link" href="#"><i class="bi bi-box-arrow-up-right"></i></a></td>
                      </tr>
                      <tr>
                        <td>Can Staff</td>
                        <td><span class="badge-pill atrisk"><span class="mini-dot"></span> At Risk</span></td>
                        <td>02 Mar 2021</td>
                        <td>01 Jul 2021</td>
                        <td class="text-end"><a class="muted-link" href="#"><i class="bi bi-box-arrow-up-right"></i></a></td>
                      </tr>
                      <tr>
                        <td>Moore Project</td>
                        <td><span class="badge-pill delayed"><span class="mini-dot"></span> Delayed</span></td>
                        <td>02 Mar 2021</td>
                        <td>01 Jul 2021</td>
                        <td class="text-end"><a class="muted-link" href="#"><i class="bi bi-box-arrow-up-right"></i></a></td>
                      </tr>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Progress Overview</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>
                <div class="chart-wrap">
                  <canvas id="barChart"></canvas>
                </div>
              </div>
            </div>
          </div>

          <!-- Bottom row -->
          <div class="row g-3 mb-4">
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Recent Activity</h3>
                  <a class="muted-link" href="#" style="font-size:12px;">All updates next</a>
                </div>

                <div class="activity-item">
                  <div class="activity-avatar">👷</div>
                  <div class="flex-grow-1">
                    <p class="activity-title mb-0">John Doe <span class="text-muted" style="font-weight:700;">commented on</span> Tower A Construction</p>
                    <p class="activity-sub">Planned U as dot Expootunicor</p>
                  </div>
                </div>

                <div class="activity-item">
                  <div class="activity-avatar">👷</div>
                  <div class="flex-grow-1">
                    <p class="activity-title mb-0">Micheel Smith <span class="text-muted" style="font-weight:700;">uploaded new</span> blueprints.</p>
                    <p class="activity-sub">Plolah U at 9od Empootunicor</p>
                  </div>
                </div>

                <div class="activity-item">
                  <div class="activity-avatar">👷</div>
                  <div class="flex-grow-1">
                    <p class="activity-title mb-0">Micheel Smith <span class="text-muted" style="font-weight:700;">created a</span> cluster expupdate.</p>
                    <p class="activity-sub">Olathn U as dot tead to geesor</p>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <h3 class="panel-title">Team Performance</h3>
                  <button class="panel-menu" aria-label="More"><i class="bi bi-three-dots"></i></button>
                </div>

                <div class="donut-wrap">
                  <canvas id="donutChart"></canvas>
                </div>

                <div class="legend">
                  <div class="legend-item"><span class="legend-dot" style="background: var(--yellow);"></span> Planning</div>
                  <div class="legend-item"><span class="legend-dot" style="background: var(--orange);"></span> Execution</div>
                  <div class="legend-item"><span class="legend-dot" style="background: #9ca3af;"></span> Monitoring</div>
                  <div class="legend-item"><span class="legend-dot" style="background: #6b7280;"></span> Reporting</div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <?php include 'includes/footer.php'; ?>

    </main>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // ===== Sidebar toggles =====
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("overlay");
    const menuBtn = document.getElementById("menuBtn");

    const isMobile = () => window.matchMedia("(max-width: 991.98px)").matches;

    function openMobileSidebar(){
      sidebar.classList.add("open");
      overlay.classList.add("show");
      overlay.setAttribute("aria-hidden", "false");
    }
    function closeMobileSidebar(){
      sidebar.classList.remove("open");
      overlay.classList.remove("show");
      overlay.setAttribute("aria-hidden", "true");
    }

    function setWideMode(){
      document.body.classList.toggle("wide", sidebar.classList.contains("collapsed") && !isMobile());
    }

    function toggleDesktopCollapse(){
      sidebar.classList.toggle("collapsed");
      setWideMode();
    }

    function handleToggle(){
      if (isMobile()){
        sidebar.classList.remove("collapsed");
        document.body.classList.remove("wide");

        if (sidebar.classList.contains("open")) closeMobileSidebar();
        else openMobileSidebar();
      } else {
        closeMobileSidebar();
        toggleDesktopCollapse();
      }
    }

    menuBtn?.addEventListener("click", handleToggle);
    overlay?.addEventListener("click", closeMobileSidebar);

    window.addEventListener("resize", () => {
      if (!isMobile()){
        closeMobileSidebar();
        setWideMode();
      } else {
        sidebar.classList.remove("collapsed");
        document.body.classList.remove("wide");
        closeMobileSidebar();
      }
    });

    setWideMode();

    // ===== Charts =====
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = "#6b7280";

    const barCtx = document.getElementById("barChart");
    new Chart(barCtx, {
      type: "bar",
      data: {
        labels: ["Mon","Tu","Wot","Thu","Ftl","Sun"],
        datasets: [
          { label: "Series A", data: [8, 6, 12, 18, 14, 24], backgroundColor: "rgba(107,114,128,.8)", borderRadius: 10, barThickness: 18 },
          { label: "Series B", data: [10, 5, 14, 10, 16, 26], backgroundColor: "rgba(242,201,76,.95)", borderRadius: 10, barThickness: 18 }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { font: { weight: 700 } } },
          y: { grid: { color: "rgba(233,236,239,1)" }, border: { display: false }, ticks: { stepSize: 5 } }
        }
      }
    });

    const donutCtx = document.getElementById("donutChart");
    new Chart(donutCtx, {
      type: "doughnut",
      data: {
        labels: ["Planning","Execution","Monitoring","Reporting"],
        datasets: [{
          data: [20,20,20,20],
          backgroundColor: [
            "rgba(242,201,76,.95)",
            "rgba(242,153,74,.95)",
            "rgba(156,163,175,.95)",
            "rgba(107,114,128,.95)"
          ],
          borderWidth: 0,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "68%",
        plugins: { legend: { display: false } }
      },
      plugins: [{
        id: "centerText",
        afterDraw(chart){
          const { ctx } = chart;
          const meta = chart.getDatasetMeta(0);
          if(!meta?.data?.length) return;
          const x = meta.data[0].x;
          const y = meta.data[0].y;

          ctx.save();
          ctx.fillStyle = "#374151";
          ctx.textAlign = "center";
          ctx.textBaseline = "middle";

          ctx.font = "800 14px " + Chart.defaults.font.family;
          ctx.fillText("Team", x, y - 8);
          ctx.font = "900 14px " + Chart.defaults.font.family;
          ctx.fillText("Performance", x, y + 12);
          ctx.restore();
        }
      }]
    });

    document.getElementById("year").textContent = new Date().getFullYear();
  </script>
</body>
</html>
