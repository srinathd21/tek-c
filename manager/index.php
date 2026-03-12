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

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />
  
  <style>
    /* Content Styles - Inline */
    .content-scroll{ flex:1 1 auto; overflow:auto; padding:22px 22px 14px; }

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

    @media (max-width: 991.98px){
      .content-scroll{ padding:18px; }
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

  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  
  <!-- TEK-C Custom JavaScript -->
  <script src="assets/js/sidebar-toggle.js"></script>
  
  <script>
    // Dashboard Charts JavaScript - Inline
    document.addEventListener('DOMContentLoaded', function() {
      // ===== Sidebar toggles ===== (if not in sidebar-toggle.js)
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

      if (menuBtn) {
        menuBtn.addEventListener("click", handleToggle);
      }
      
      if (overlay) {
        overlay.addEventListener("click", closeMobileSidebar);
      }

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

      // Set current year in footer
      const yearElement = document.getElementById("year");
      if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
      }

      // ===== Dashboard Charts =====
      // Only run if charts exist on page
      if (document.getElementById('barChart') || document.getElementById('donutChart')) {
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = "#6b7280";

        // Bar Chart
        const barCtx = document.getElementById("barChart");
        if (barCtx) {
          new Chart(barCtx, {
            type: "bar",
            data: {
              labels: ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"],
              datasets: [
                { 
                  label: "Series A", 
                  data: [8, 6, 12, 18, 14, 10, 24], 
                  backgroundColor: "rgba(107,114,128,.8)", 
                  borderRadius: 10, 
                  barThickness: 18 
                },
                { 
                  label: "Series B", 
                  data: [10, 5, 14, 10, 16, 8, 26], 
                  backgroundColor: "rgba(242,201,76,.95)", 
                  borderRadius: 10, 
                  barThickness: 18 
                }
              ]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: { 
                legend: { 
                  display: false 
                } 
              },
              scales: {
                x: { 
                  grid: { 
                    display: false 
                  }, 
                  ticks: { 
                    font: { 
                      weight: 700 
                    } 
                  } 
                },
                y: { 
                  grid: { 
                    color: "rgba(233,236,239,1)" 
                  }, 
                  border: { 
                    display: false 
                  }, 
                  ticks: { 
                    stepSize: 5 
                  } 
                }
              }
            }
          });
        }

        // Donut Chart
        const donutCtx = document.getElementById("donutChart");
        if (donutCtx) {
          new Chart(donutCtx, {
            type: "doughnut",
            data: {
              labels: ["Planning","Execution","Monitoring","Reporting"],
              datasets: [{
                data: [25, 35, 20, 20],
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
              plugins: { 
                legend: { 
                  display: false 
                } 
              }
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
        }
      }
    });
  </script>
  
</body>
</html>