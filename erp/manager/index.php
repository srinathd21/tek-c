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
    :root {
      --dash-bg: #f5f7fb;
      --dash-card: #ffffff;
      --dash-border: #e5e7eb;
      --dash-text: #111827;
      --dash-muted: #6b7280;
      --dash-soft: #f9fafb;
      --dash-shadow: 0 12px 30px rgba(15, 23, 42, .06);
      --dash-radius: 16px;
    }

    body {
      background: var(--dash-bg);
    }

    .content-scroll {
      flex: 1 1 auto;
      overflow: auto;
      padding: 18px;
    }

    .dashboard-wrapper {
      max-width: 1500px;
      margin: 0 auto;
    }

    .dashboard-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 16px;
    }

    .dashboard-heading h1 {
      font-size: 20px;
      font-weight: 900;
      color: var(--dash-text);
      margin: 0;
    }

    .dashboard-heading p {
      margin: 3px 0 0;
      color: var(--dash-muted);
      font-size: 12px;
      font-weight: 600;
    }

    .panel {
      background: var(--dash-card);
      border: 1px solid var(--dash-border);
      border-radius: var(--dash-radius);
      box-shadow: var(--dash-shadow);
      padding: 14px;
      height: 100%;
    }

    .panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 10px;
    }

    .panel-title {
      font-weight: 900;
      font-size: 15px;
      color: var(--dash-text);
      margin: 0;
    }

    .panel-subtitle {
      color: var(--dash-muted);
      font-size: 11px;
      font-weight: 700;
      margin-top: 2px;
    }

    .panel-menu {
      width: 31px;
      height: 31px;
      border-radius: 10px;
      border: 1px solid var(--dash-border);
      background: #fff;
      display: grid;
      place-items: center;
      color: var(--dash-muted);
      transition: .2s ease;
    }

    .panel-menu:hover {
      background: var(--dash-soft);
      color: var(--dash-text);
    }

    .stat-card {
      background: var(--dash-card);
      border: 1px solid var(--dash-border);
      border-radius: var(--dash-radius);
      box-shadow: var(--dash-shadow);
      padding: 13px 14px;
      min-height: 82px;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .stat-ic {
      width: 40px;
      height: 40px;
      border-radius: 13px;
      display: grid;
      place-items: center;
      color: #fff;
      font-size: 18px;
      flex: 0 0 auto;
    }

    .stat-ic.blue { background: var(--blue, #2f80ed); }
    .stat-ic.orange { background: var(--orange, #f2994a); }
    .stat-ic.green { background: var(--green, #27ae60); }
    .stat-ic.red { background: var(--red, #eb5757); }

    .stat-label {
      color: var(--dash-muted);
      font-weight: 800;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .3px;
    }

    .stat-value {
      font-size: 25px;
      font-weight: 950;
      line-height: 1;
      margin-top: 3px;
      color: var(--dash-text);
    }

    .compact-table-wrap {
      border: 1px solid var(--dash-border);
      border-radius: 14px;
      overflow: hidden;
      background: #fff;
    }

    .compact-table {
      margin: 0;
      font-size: 12px;
      min-width: 680px;
    }

    .compact-table thead th {
      background: #f8fafc;
      color: #64748b;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: .45px;
      font-weight: 900;
      border-bottom: 1px solid var(--dash-border) !important;
      padding: 9px 11px;
      white-space: nowrap;
    }

    .compact-table tbody td {
      padding: 9px 11px;
      vertical-align: middle;
      border-color: #eef2f7;
      color: #334155;
      font-weight: 700;
      white-space: nowrap;
    }

    .compact-table tbody tr {
      transition: .15s ease;
    }

    .compact-table tbody tr:hover {
      background: #fbfdff;
    }

    .table-title-cell {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
    }

    .table-icon {
      width: 28px;
      height: 28px;
      border-radius: 9px;
      display: grid;
      place-items: center;
      background: #eff6ff;
      color: #2563eb;
      font-size: 14px;
      flex: 0 0 auto;
    }

    .table-primary-text {
      color: #111827;
      font-size: 12px;
      font-weight: 900;
      line-height: 1.2;
    }

    .table-secondary-text {
      color: #64748b;
      font-size: 10.5px;
      font-weight: 700;
      margin-top: 1px;
      line-height: 1.2;
    }

    .badge-pill {
      border-radius: 999px;
      padding: 5px 8px;
      font-weight: 900;
      font-size: 10.5px;
      border: 1px solid transparent;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      line-height: 1;
    }

    .badge-pill .mini-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: currentColor;
    }

    .ontrack {
      color: #15803d;
      background: #dcfce7;
      border-color: #bbf7d0;
    }

    .progressing {
      color: #2563eb;
      background: #dbeafe;
      border-color: #bfdbfe;
    }

    .atrisk {
      color: #b91c1c;
      background: #fee2e2;
      border-color: #fecaca;
    }

    .delayed {
      color: #a16207;
      background: #fef3c7;
      border-color: #fde68a;
    }

    .joined {
      color: #6d28d9;
      background: #ede9fe;
      border-color: #ddd6fe;
    }

    .muted-link {
      color: #64748b;
      font-weight: 900;
      text-decoration: none;
      font-size: 12px;
    }

    .muted-link:hover {
      color: #111827;
    }

    .avatar-mini {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      font-size: 11px;
      font-weight: 950;
      color: #fff;
      background: linear-gradient(135deg, #334155, #64748b);
      flex: 0 0 auto;
    }

    .activity-item {
      display: flex;
      gap: 10px;
      padding: 10px 0;
      border-top: 1px solid var(--dash-border);
    }

    .activity-item:first-child {
      border-top: 0;
      padding-top: 2px;
    }

    .activity-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--yellow, #f2c94c), #ffd66b);
      display: grid;
      place-items: center;
      font-weight: 900;
      color: #1f2937;
      flex: 0 0 auto;
      font-size: 15px;
    }

    .activity-title {
      font-weight: 850;
      margin: 0;
      color: #1f2937;
      font-size: 12.5px;
      line-height: 1.35;
    }

    .activity-sub {
      margin: 2px 0 0;
      color: #6b7280;
      font-weight: 650;
      font-size: 11px;
    }

    .chart-wrap {
      height: 188px;
    }

    .donut-wrap {
      height: 220px;
    }

    .legend {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 16px;
      padding: 4px 2px 0;
      align-items: center;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 7px;
      font-weight: 800;
      color: #374151;
      font-size: 11.5px;
    }

    .legend-dot {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: #999;
    }

    @media (max-width: 991.98px) {
      .content-scroll {
        padding: 14px;
      }

      .dashboard-heading {
        align-items: flex-start;
        flex-direction: column;
      }

      .compact-table {
        min-width: 720px;
      }
    }
  </style>
</head>

<body>
  <div class="app">

    <?php include 'includes/sidebar.php'; ?>

    <main class="main" aria-label="Main">

      <?php include 'includes/topbar.php'; ?>

      <div id="contentScroll" class="content-scroll">
        <div class="container-fluid dashboard-wrapper">

          <div class="dashboard-heading">
            <div>
              <h1>Dashboard</h1>
              <p>Compact overview of projects, employees, activity and performance.</p>
            </div>
          </div>

          <!-- Stats -->
          <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-folder2"></i></div>
                <div>
                  <div class="stat-label">Active Projects</div>
                  <div class="stat-value">12</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-clock-history"></i></div>
                <div>
                  <div class="stat-label">Upcoming Tasks</div>
                  <div class="stat-value">24</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-people-fill"></i></div>
                <div>
                  <div class="stat-label">Employees</div>
                  <div class="stat-value">256</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                  <div class="stat-label">Alerts</div>
                  <div class="stat-value">5</div>
                </div>
              </div>
            </div>
          </div>

          <!-- Main row -->
          <div class="row g-3 mb-3">

            <!-- Ongoing Projects -->
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Ongoing Projects</h3>
                    <div class="panel-subtitle">Current project status and timelines</div>
                  </div>
                  <button class="panel-menu" type="button" aria-label="More">
                    <i class="bi bi-three-dots"></i>
                  </button>
                </div>

                <div class="table-responsive compact-table-wrap">
                  <table class="table compact-table align-middle">
                    <thead>
                      <tr>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Manager</th>
                        <th class="text-end">Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-building"></i></div>
                            <div>
                              <div class="table-primary-text">Tower A Construction</div>
                              <div class="table-secondary-text">Commercial block</div>
                            </div>
                          </div>
                        </td>
                        <td><span class="badge-pill ontrack"><span class="mini-dot"></span> On Track</span></td>
                        <td>06 Mar 2021</td>
                        <td>01 Jul 2021</td>
                        <td>John Doe</td>
                        <td class="text-end">
                          <a class="muted-link" href="#"><i class="bi bi-box-arrow-up-right"></i></a>
                        </td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-shop"></i></div>
                            <div>
                              <div class="table-primary-text">Mall Renovation</div>
                              <div class="table-secondary-text">Interior upgrade</div>
                            </div>
                          </div>
                        </td>
                        <td><span class="badge-pill progressing"><span class="mini-dot"></span> Progressing</span></td>
                        <td>10 Mar 2021</td>
                        <td>15 Jul 2021</td>
                        <td>Michael Smith</td>
                        <td class="text-end">
                          <a class="muted-link" href="#"><i class="bi bi-box-arrow-up-right"></i></a>
                        </td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-tools"></i></div>
                            <div>
                              <div class="table-primary-text">Can Staff</div>
                              <div class="table-secondary-text">Workforce setup</div>
                            </div>
                          </div>
                        </td>
                        <td><span class="badge-pill atrisk"><span class="mini-dot"></span> At Risk</span></td>
                        <td>02 Mar 2021</td>
                        <td>01 Jul 2021</td>
                        <td>David Lee</td>
                        <td class="text-end">
                          <a class="muted-link" href="#"><i class="bi bi-box-arrow-up-right"></i></a>
                        </td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-kanban"></i></div>
                            <div>
                              <div class="table-primary-text">Moore Project</div>
                              <div class="table-secondary-text">Planning phase</div>
                            </div>
                          </div>
                        </td>
                        <td><span class="badge-pill delayed"><span class="mini-dot"></span> Delayed</span></td>
                        <td>02 Mar 2021</td>
                        <td>01 Jul 2021</td>
                        <td>Sarah Paul</td>
                        <td class="text-end">
                          <a class="muted-link" href="#"><i class="bi bi-box-arrow-up-right"></i></a>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>

            <!-- Progress Overview -->
            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Progress Overview</h3>
                    <div class="panel-subtitle">Weekly project progress</div>
                  </div>
                  <button class="panel-menu" type="button" aria-label="More">
                    <i class="bi bi-three-dots"></i>
                  </button>
                </div>

                <div class="chart-wrap">
                  <canvas id="barChart"></canvas>
                </div>
              </div>
            </div>

          </div>

          <!-- New tables row -->
          <div class="row g-3 mb-3">

            <!-- Recent Joined Employees -->
            <div class="col-12 col-xl-6">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Recent Joined Employees</h3>
                    <div class="panel-subtitle">Latest employee onboarding list</div>
                  </div>
                  <a class="muted-link" href="onboarding.php">View All</a>
                </div>

                <div class="table-responsive compact-table-wrap">
                  <table class="table compact-table align-middle">
                    <thead>
                      <tr>
                        <th>Employee</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="avatar-mini">AK</div>
                            <div>
                              <div class="table-primary-text">Arun Kumar</div>
                              <div class="table-secondary-text">EMP-1024</div>
                            </div>
                          </div>
                        </td>
                        <td>Site Engineer</td>
                        <td>12 May 2026</td>
                        <td><span class="badge-pill joined"><span class="mini-dot"></span> Joined</span></td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="avatar-mini">SP</div>
                            <div>
                              <div class="table-primary-text">Sneha Priya</div>
                              <div class="table-secondary-text">EMP-1023</div>
                            </div>
                          </div>
                        </td>
                        <td>HR Executive</td>
                        <td>10 May 2026</td>
                        <td><span class="badge-pill ontrack"><span class="mini-dot"></span> Active</span></td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="avatar-mini">MR</div>
                            <div>
                              <div class="table-primary-text">Manoj Raj</div>
                              <div class="table-secondary-text">EMP-1022</div>
                            </div>
                          </div>
                        </td>
                        <td>Supervisor</td>
                        <td>08 May 2026</td>
                        <td><span class="badge-pill progressing"><span class="mini-dot"></span> Training</span></td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="avatar-mini">DV</div>
                            <div>
                              <div class="table-primary-text">Divya V</div>
                              <div class="table-secondary-text">EMP-1021</div>
                            </div>
                          </div>
                        </td>
                        <td>Accountant</td>
                        <td>05 May 2026</td>
                        <td><span class="badge-pill ontrack"><span class="mini-dot"></span> Active</span></td>
                      </tr>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>

            <!-- Recent Projects -->
            <div class="col-12 col-xl-6">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Recent Projects</h3>
                    <div class="panel-subtitle">Newly created project records</div>
                  </div>
                  <a class="muted-link" href="my-sites.php">View All</a>
                </div>

                <div class="table-responsive compact-table-wrap">
                  <table class="table compact-table align-middle">
                    <thead>
                      <tr>
                        <th>Project</th>
                        <th>Client</th>
                        <th>Created</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-house-gear"></i></div>
                            <div>
                              <div class="table-primary-text">Green Villa Site</div>
                              <div class="table-secondary-text">Residential</div>
                            </div>
                          </div>
                        </td>
                        <td>Green Homes</td>
                        <td>14 May 2026</td>
                        <td><span class="badge-pill progressing"><span class="mini-dot"></span> Planning</span></td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-building-check"></i></div>
                            <div>
                              <div class="table-primary-text">Metro Office Block</div>
                              <div class="table-secondary-text">Commercial</div>
                            </div>
                          </div>
                        </td>
                        <td>Metro Corp</td>
                        <td>11 May 2026</td>
                        <td><span class="badge-pill ontrack"><span class="mini-dot"></span> Started</span></td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-bricks"></i></div>
                            <div>
                              <div class="table-primary-text">Warehouse Phase 2</div>
                              <div class="table-secondary-text">Industrial</div>
                            </div>
                          </div>
                        </td>
                        <td>Prime Logistics</td>
                        <td>07 May 2026</td>
                        <td><span class="badge-pill delayed"><span class="mini-dot"></span> Pending</span></td>
                      </tr>

                      <tr>
                        <td>
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-cone-striped"></i></div>
                            <div>
                              <div class="table-primary-text">Road Extension</div>
                              <div class="table-secondary-text">Infrastructure</div>
                            </div>
                          </div>
                        </td>
                        <td>City Works</td>
                        <td>02 May 2026</td>
                        <td><span class="badge-pill atrisk"><span class="mini-dot"></span> Review</span></td>
                      </tr>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>

          </div>

          <!-- Bottom row -->
          <div class="row g-3 mb-4">

            <!-- Recent Activity -->
            <div class="col-12 col-xl-8">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Recent Activity</h3>
                    <div class="panel-subtitle">Latest updates from the workspace</div>
                  </div>
                  <a class="muted-link" href="#">View All</a>
                </div>

                <div class="activity-item">
                  <div class="activity-avatar">👷</div>
                  <div class="flex-grow-1">
                    <p class="activity-title">
                      John Doe <span class="text-muted" style="font-weight:700;">commented on</span> Tower A Construction
                    </p>
                    <p class="activity-sub">Updated site inspection note and completion remarks.</p>
                  </div>
                </div>

                <div class="activity-item">
                  <div class="activity-avatar">📄</div>
                  <div class="flex-grow-1">
                    <p class="activity-title">
                      Michael Smith <span class="text-muted" style="font-weight:700;">uploaded new</span> blueprints
                    </p>
                    <p class="activity-sub">Blueprint file added for Mall Renovation.</p>
                  </div>
                </div>

                <div class="activity-item">
                  <div class="activity-avatar">✅</div>
                  <div class="flex-grow-1">
                    <p class="activity-title">
                      Sneha Priya <span class="text-muted" style="font-weight:700;">completed</span> onboarding verification
                    </p>
                    <p class="activity-sub">Employee documents verified successfully.</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Team Performance -->
            <div class="col-12 col-xl-4">
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">Team Performance</h3>
                    <div class="panel-subtitle">Department-wise work split</div>
                  </div>
                  <button class="panel-menu" type="button" aria-label="More">
                    <i class="bi bi-three-dots"></i>
                  </button>
                </div>

                <div class="donut-wrap">
                  <canvas id="donutChart"></canvas>
                </div>

                <div class="legend">
                  <div class="legend-item">
                    <span class="legend-dot" style="background: var(--yellow, #f2c94c);"></span> Planning
                  </div>
                  <div class="legend-item">
                    <span class="legend-dot" style="background: var(--orange, #f2994a);"></span> Execution
                  </div>
                  <div class="legend-item">
                    <span class="legend-dot" style="background: #9ca3af;"></span> Monitoring
                  </div>
                  <div class="legend-item">
                    <span class="legend-dot" style="background: #6b7280;"></span> Reporting
                  </div>
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
    document.addEventListener('DOMContentLoaded', function () {
      const sidebar = document.getElementById("sidebar");
      const overlay = document.getElementById("overlay");
      const menuBtn = document.getElementById("menuBtn");

      const isMobile = () => window.matchMedia("(max-width: 991.98px)").matches;

      function openMobileSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.add("open");
        overlay.classList.add("show");
        overlay.setAttribute("aria-hidden", "false");
      }

      function closeMobileSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove("open");
        overlay.classList.remove("show");
        overlay.setAttribute("aria-hidden", "true");
      }

      function setWideMode() {
        if (!sidebar) return;
        document.body.classList.toggle("wide", sidebar.classList.contains("collapsed") && !isMobile());
      }

      function toggleDesktopCollapse() {
        if (!sidebar) return;
        sidebar.classList.toggle("collapsed");
        setWideMode();
      }

      function handleToggle() {
        if (!sidebar) return;

        if (isMobile()) {
          sidebar.classList.remove("collapsed");
          document.body.classList.remove("wide");

          if (sidebar.classList.contains("open")) {
            closeMobileSidebar();
          } else {
            openMobileSidebar();
          }
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

      window.addEventListener("resize", function () {
        if (!sidebar) return;

        if (!isMobile()) {
          closeMobileSidebar();
          setWideMode();
        } else {
          sidebar.classList.remove("collapsed");
          document.body.classList.remove("wide");
          closeMobileSidebar();
        }
      });

      setWideMode();

      const yearElement = document.getElementById("year");
      if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
      }

      if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = "#64748b";

        const barCtx = document.getElementById("barChart");

        if (barCtx) {
          new Chart(barCtx, {
            type: "bar",
            data: {
              labels: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
              datasets: [
                {
                  label: "Completed",
                  data: [8, 6, 12, 18, 14, 10, 24],
                  backgroundColor: "rgba(100,116,139,.82)",
                  borderRadius: 8,
                  barThickness: 16
                },
                {
                  label: "Pending",
                  data: [10, 5, 14, 10, 16, 8, 26],
                  backgroundColor: "rgba(242,201,76,.95)",
                  borderRadius: 8,
                  barThickness: 16
                }
              ]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  display: false
                },
                tooltip: {
                  titleFont: { size: 12, weight: 'bold' },
                  bodyFont: { size: 11 }
                }
              },
              scales: {
                x: {
                  grid: { display: false },
                  ticks: {
                    font: {
                      size: 10,
                      weight: 800
                    }
                  }
                },
                y: {
                  grid: {
                    color: "rgba(226,232,240,1)"
                  },
                  border: {
                    display: false
                  },
                  ticks: {
                    stepSize: 5,
                    font: {
                      size: 10,
                      weight: 700
                    }
                  }
                }
              }
            }
          });
        }

        const donutCtx = document.getElementById("donutChart");

        if (donutCtx) {
          new Chart(donutCtx, {
            type: "doughnut",
            data: {
              labels: ["Planning", "Execution", "Monitoring", "Reporting"],
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
                },
                tooltip: {
                  titleFont: { size: 12, weight: 'bold' },
                  bodyFont: { size: 11 }
                }
              }
            },
            plugins: [{
              id: "centerText",
              afterDraw(chart) {
                const ctx = chart.ctx;
                const meta = chart.getDatasetMeta(0);

                if (!meta || !meta.data || !meta.data.length) return;

                const x = meta.data[0].x;
                const y = meta.data[0].y;

                ctx.save();
                ctx.fillStyle = "#334155";
                ctx.textAlign = "center";
                ctx.textBaseline = "middle";

                ctx.font = "800 12px " + Chart.defaults.font.family;
                ctx.fillText("Team", x, y - 7);

                ctx.font = "900 12px " + Chart.defaults.font.family;
                ctx.fillText("Performance", x, y + 11);

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