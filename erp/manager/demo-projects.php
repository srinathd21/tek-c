<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>TEK-C Projects</title>

  <link rel="apple-touch-icon" sizes="180x180" href="assets/fav/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/fav/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/fav/favicon-16x16.png">
  <link rel="manifest" href="assets/fav/site.webmanifest">

  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />

  <!-- TEK-C Custom Styles -->
  <link href="assets/css/layout-styles.css" rel="stylesheet" />
  <link href="assets/css/topbar.css" rel="stylesheet" />
  <link href="assets/css/footer.css" rel="stylesheet" />

  <style>
    :root {
      --page-bg: #f5f7fb;
      --card-bg: #ffffff;
      --border: #e5e7eb;
      --text: #111827;
      --muted: #6b7280;
      --soft: #f8fafc;
      --shadow: 0 10px 26px rgba(15, 23, 42, .055);
      --radius: 15px;
    }

    body { background: var(--page-bg); }

    .content-scroll {
      flex: 1 1 auto;
      overflow: auto;
      padding: 16px;
    }

    .projects-wrapper {
      width: 100%;
      max-width: 100%;
      margin: 0 auto;
    }

    .page-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 14px;
    }

    .page-heading h1 {
      font-size: 19px;
      font-weight: 900;
      color: var(--text);
      margin: 0;
    }

    .page-heading p {
      margin: 3px 0 0;
      color: var(--muted);
      font-size: 12px;
      font-weight: 600;
    }

    .primary-btn {
      border: 0;
      background: #111827;
      color: #fff;
      height: 36px;
      padding: 0 14px;
      border-radius: 11px;
      font-size: 12px;
      font-weight: 900;
      display: inline-flex;
      align-items: center;
      gap: 7px;
      text-decoration: none;
      white-space: nowrap;
    }

    .primary-btn:hover {
      background: #020617;
      color: #fff;
    }

    .stat-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 12px 13px;
      min-height: 78px;
      display: flex;
      align-items: center;
      gap: 11px;
    }

    .stat-ic {
      width: 38px;
      height: 38px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      color: #fff;
      font-size: 17px;
      flex: 0 0 auto;
    }

    .stat-ic.blue { background: var(--blue, #2f80ed); }
    .stat-ic.orange { background: var(--orange, #f2994a); }
    .stat-ic.green { background: var(--green, #27ae60); }
    .stat-ic.red { background: var(--red, #eb5757); }

    .stat-label {
      color: var(--muted);
      font-weight: 800;
      font-size: 10.5px;
      text-transform: uppercase;
      letter-spacing: .3px;
    }

    .stat-value {
      font-size: 24px;
      font-weight: 950;
      line-height: 1;
      margin-top: 3px;
      color: var(--text);
    }

    .panel {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 13px;
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
      font-size: 14px;
      color: var(--text);
      margin: 0;
    }

    .panel-subtitle {
      color: var(--muted);
      font-size: 11px;
      font-weight: 700;
      margin-top: 2px;
    }

    .filter-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .search-box {
      position: relative;
      flex: 1 1 260px;
      max-width: 430px;
    }

    .search-box i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 13px;
    }

    .search-box input {
      width: 100%;
      height: 36px;
      border: 1px solid var(--border);
      border-radius: 11px;
      background: #fff;
      padding: 0 12px 0 34px;
      font-size: 12px;
      font-weight: 700;
      color: var(--text);
      outline: none;
    }

    .search-box input:focus {
      border-color: #bfdbfe;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .filter-select {
      height: 36px;
      border: 1px solid var(--border);
      border-radius: 11px;
      background-color: #fff;
      padding: 0 42px 0 12px;
      font-size: 12px;
      font-weight: 800;
      color: #334155;
      outline: none;
      min-width: 145px;
      line-height: 36px;
      cursor: pointer;

      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;

      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' fill='none' viewBox='0 0 18 18'%3E%3Cpath d='M4.5 7L9 11.5L13.5 7' stroke='%23334155' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-size: 18px 18px;
    }

    .filter-select:focus {
      border-color: #bfdbfe;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, .10);
    }

    .filter-select::-ms-expand {
      display: none;
    }

    .compact-table-wrap {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 13px;
      overflow: hidden;
      background: #fff;
    }

    .compact-table {
      width: 100%;
      margin: 0;
      font-size: 11.5px;
      table-layout: fixed;
    }

    .compact-table thead th {
      background: var(--soft);
      color: #64748b;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: .45px;
      font-weight: 900;
      border-bottom: 1px solid var(--border) !important;
      padding: 8px 9px;
      white-space: nowrap;
    }

    .compact-table tbody td {
      padding: 8px 9px;
      vertical-align: middle;
      border-color: #eef2f7;
      color: #334155;
      font-weight: 700;
    }

    .compact-table tbody tr { transition: .15s ease; }
    .compact-table tbody tr:hover { background: #fbfdff; }

    .col-project { width: 28%; }
    .col-client { width: 15%; }
    .col-status { width: 12%; }
    .col-timeline { width: 16%; }
    .col-budget { width: 8%; }
    .col-progress { width: 9%; }
    .col-action { width: 12%; }

    .table-title-cell {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
    }

    .table-icon {
      width: 26px;
      height: 26px;
      border-radius: 8px;
      display: grid;
      place-items: center;
      background: #eff6ff;
      color: #2563eb;
      font-size: 13px;
      flex: 0 0 auto;
    }

    .table-primary-text {
      color: #111827;
      font-size: 11.5px;
      font-weight: 900;
      line-height: 1.2;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .table-secondary-text {
      color: #64748b;
      font-size: 10px;
      font-weight: 700;
      margin-top: 1px;
      line-height: 1.2;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .badge-pill {
      border-radius: 999px;
      padding: 5px 8px;
      font-weight: 900;
      font-size: 10px;
      border: 1px solid transparent;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      line-height: 1;
      white-space: nowrap;
    }

    .badge-pill .mini-dot {
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: currentColor;
    }

    .ontrack { color: #15803d; background: #dcfce7; border-color: #bbf7d0; }
    .progressing { color: #2563eb; background: #dbeafe; border-color: #bfdbfe; }
    .atrisk { color: #b91c1c; background: #fee2e2; border-color: #fecaca; }
    .delayed { color: #a16207; background: #fef3c7; border-color: #fde68a; }
    .pending { color: #6d28d9; background: #ede9fe; border-color: #ddd6fe; }

    .progress-mini {
      width: 100%;
      max-width: 90px;
    }

    .progress {
      height: 5px;
      background: #e5e7eb;
      border-radius: 999px;
      overflow: hidden;
    }

    .progress-bar { border-radius: 999px; }

    .progress-value {
      font-size: 10px;
      font-weight: 900;
      color: #475569;
      margin-bottom: 3px;
    }

    .action-group {
      display: flex;
      justify-content: flex-end;
      gap: 5px;
      white-space: nowrap;
    }

    .action-btn {
      width: 27px;
      height: 27px;
      border-radius: 9px;
      border: 1px solid var(--border);
      background: #fff;
      display: inline-grid;
      place-items: center;
      text-decoration: none;
      font-size: 11px;
      transition: .15s ease;
    }

    .view-btn {
      color: #475569;
      background: #f8fafc;
      border-color: #e2e8f0;
    }

    .view-btn:hover {
      background: #e2e8f0;
      color: #0f172a;
    }

    .edit-btn {
      color: #2563eb;
      background: #eff6ff;
      border-color: #bfdbfe;
    }

    .edit-btn:hover {
      background: #dbeafe;
      color: #1d4ed8;
    }

    .delete-btn {
      color: #dc2626;
      background: #fef2f2;
      border-color: #fecaca;
    }

    .delete-btn:hover {
      background: #fee2e2;
      color: #b91c1c;
    }

    .pagination-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      padding-top: 12px;
    }

    .pagination-info {
      color: var(--muted);
      font-size: 11px;
      font-weight: 700;
    }

    .page-btn {
      border: 1px solid var(--border);
      background: #fff;
      color: #334155;
      border-radius: 9px;
      min-width: 30px;
      height: 30px;
      padding: 0 10px;
      font-size: 11px;
      font-weight: 900;
    }

    .page-btn.active {
      background: #111827;
      color: #fff;
      border-color: #111827;
    }

    @media (max-width: 1199.98px) {
      .compact-table { table-layout: auto; }
      .compact-table-wrap { overflow-x: visible; }
      .compact-table thead { display: none; }

      .compact-table,
      .compact-table tbody,
      .compact-table tr,
      .compact-table td {
        display: block;
        width: 100%;
      }

      .compact-table tbody tr {
        border-bottom: 1px solid var(--border);
        padding: 10px;
      }

      .compact-table tbody tr:last-child { border-bottom: 0; }

      .compact-table tbody td {
        border: 0;
        padding: 6px 0;
        display: flex;
        justify-content: space-between;
        gap: 12px;
      }

      .compact-table tbody td::before {
        content: attr(data-label);
        font-size: 10px;
        font-weight: 900;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .35px;
        flex: 0 0 95px;
      }

      .compact-table tbody td:first-child {
        display: block;
      }

      .compact-table tbody td:first-child::before {
        display: none;
      }

      .progress-mini { max-width: 150px; }
      .action-group { justify-content: flex-start; }
    }

    @media (max-width: 991.98px) {
      .content-scroll { padding: 13px; }

      .page-heading {
        align-items: flex-start;
        flex-direction: column;
      }

      .filter-bar { align-items: stretch; }

      .search-box,
      .filter-select {
        max-width: 100%;
        width: 100%;
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
        <div class="container-fluid projects-wrapper px-0">

          <div class="page-heading">
            <div>
              <h1>Projects</h1>
              <p>Manage all ongoing, recent, delayed and pending project records.</p>
            </div>

            <a href="add-project.php" class="primary-btn">
              <i class="bi bi-plus-circle"></i>
              Add Project
            </a>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic blue"><i class="bi bi-folder2-open"></i></div>
                <div>
                  <div class="stat-label">Total Projects</div>
                  <div class="stat-value">16</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic green"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                  <div class="stat-label">On Track</div>
                  <div class="stat-value">8</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic orange"><i class="bi bi-hourglass-split"></i></div>
                <div>
                  <div class="stat-label">In Progress</div>
                  <div class="stat-value">5</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <div class="stat-card">
                <div class="stat-ic red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                  <div class="stat-label">At Risk</div>
                  <div class="stat-value">3</div>
                </div>
              </div>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-12">
              <div class="panel">

                <div class="panel-header">
                  <div>
                    <h3 class="panel-title">All Projects</h3>
                    <div class="panel-subtitle">Compact project table without horizontal scroll</div>
                  </div>
                </div>

                <div class="filter-bar">
                  <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="projectSearch" placeholder="Search project, client, manager or location...">
                  </div>

                  <div class="d-flex gap-2 flex-wrap">
                    <select class="filter-select" id="statusFilter">
                      <option value="">All Status</option>
                      <option value="on track">On Track</option>
                      <option value="progressing">Progressing</option>
                      <option value="at risk">At Risk</option>
                      <option value="delayed">Delayed</option>
                      <option value="pending">Pending</option>
                    </select>

                    <select class="filter-select" id="typeFilter">
                      <option value="">All Types</option>
                      <option value="commercial">Commercial</option>
                      <option value="residential">Residential</option>
                      <option value="industrial">Industrial</option>
                      <option value="infrastructure">Infrastructure</option>
                      <option value="renovation">Renovation</option>
                    </select>
                  </div>
                </div>

                <div class="compact-table-wrap">
                  <table class="table compact-table align-middle" id="projectsTable">
                    <thead>
                      <tr>
                        <th class="col-project">Project</th>
                        <th class="col-client">Client</th>
                        <th class="col-status">Status</th>
                        <th class="col-timeline">Timeline</th>
                        <th class="col-budget">Budget</th>
                        <th class="col-progress">Progress</th>
                        <th class="col-action text-end">Actions</th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr data-status="on track" data-type="commercial">
                        <td data-label="Project">
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-building"></i></div>
                            <div>
                              <div class="table-primary-text">Tower A Construction</div>
                              <div class="table-secondary-text">PRJ-1001 • Commercial • Chennai</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Client">
                          <div class="table-primary-text">TEK-C Infra</div>
                          <div class="table-secondary-text">Manager: John Doe</div>
                        </td>
                        <td data-label="Status"><span class="badge-pill ontrack"><span class="mini-dot"></span> On Track</span></td>
                        <td data-label="Timeline">
                          <div class="table-primary-text">06 Mar 2021</div>
                          <div class="table-secondary-text">to 01 Jul 2021</div>
                        </td>
                        <td data-label="Budget">₹1.8Cr</td>
                        <td data-label="Progress">
                          <div class="progress-mini">
                            <div class="progress-value">78%</div>
                            <div class="progress"><div class="progress-bar bg-success" style="width:78%"></div></div>
                          </div>
                        </td>
                        <td data-label="Actions">
                          <div class="action-group">
                            <a class="action-btn view-btn" href="view-project.php?id=1001" title="View"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit-btn" href="edit-project.php?id=1001" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <a class="action-btn delete-btn" href="delete-project.php?id=1001" title="Delete" onclick="return confirm('Are you sure you want to delete this project?');"><i class="bi bi-trash"></i></a>
                          </div>
                        </td>
                      </tr>

                      <tr data-status="progressing" data-type="renovation">
                        <td data-label="Project">
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-shop"></i></div>
                            <div>
                              <div class="table-primary-text">Mall Renovation</div>
                              <div class="table-secondary-text">PRJ-1002 • Renovation • Bengaluru</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Client">
                          <div class="table-primary-text">Metro Retail</div>
                          <div class="table-secondary-text">Manager: Michael Smith</div>
                        </td>
                        <td data-label="Status"><span class="badge-pill progressing"><span class="mini-dot"></span> Progressing</span></td>
                        <td data-label="Timeline">
                          <div class="table-primary-text">10 Mar 2021</div>
                          <div class="table-secondary-text">to 15 Jul 2021</div>
                        </td>
                        <td data-label="Budget">₹95L</td>
                        <td data-label="Progress">
                          <div class="progress-mini">
                            <div class="progress-value">64%</div>
                            <div class="progress"><div class="progress-bar bg-primary" style="width:64%"></div></div>
                          </div>
                        </td>
                        <td data-label="Actions">
                          <div class="action-group">
                            <a class="action-btn view-btn" href="view-project.php?id=1002" title="View"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit-btn" href="edit-project.php?id=1002" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <a class="action-btn delete-btn" href="delete-project.php?id=1002" title="Delete" onclick="return confirm('Are you sure you want to delete this project?');"><i class="bi bi-trash"></i></a>
                          </div>
                        </td>
                      </tr>

                      <tr data-status="at risk" data-type="commercial">
                        <td data-label="Project">
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-tools"></i></div>
                            <div>
                              <div class="table-primary-text">Can Staff</div>
                              <div class="table-secondary-text">PRJ-1003 • Commercial • Coimbatore</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Client">
                          <div class="table-primary-text">Can Group</div>
                          <div class="table-secondary-text">Manager: David Lee</div>
                        </td>
                        <td data-label="Status"><span class="badge-pill atrisk"><span class="mini-dot"></span> At Risk</span></td>
                        <td data-label="Timeline">
                          <div class="table-primary-text">02 Mar 2021</div>
                          <div class="table-secondary-text">to 01 Jul 2021</div>
                        </td>
                        <td data-label="Budget">₹58L</td>
                        <td data-label="Progress">
                          <div class="progress-mini">
                            <div class="progress-value">42%</div>
                            <div class="progress"><div class="progress-bar bg-danger" style="width:42%"></div></div>
                          </div>
                        </td>
                        <td data-label="Actions">
                          <div class="action-group">
                            <a class="action-btn view-btn" href="view-project.php?id=1003" title="View"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit-btn" href="edit-project.php?id=1003" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <a class="action-btn delete-btn" href="delete-project.php?id=1003" title="Delete" onclick="return confirm('Are you sure you want to delete this project?');"><i class="bi bi-trash"></i></a>
                          </div>
                        </td>
                      </tr>

                      <tr data-status="delayed" data-type="commercial">
                        <td data-label="Project">
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-kanban"></i></div>
                            <div>
                              <div class="table-primary-text">Moore Project</div>
                              <div class="table-secondary-text">PRJ-1004 • Commercial • Madurai</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Client">
                          <div class="table-primary-text">Moore Developers</div>
                          <div class="table-secondary-text">Manager: Sarah Paul</div>
                        </td>
                        <td data-label="Status"><span class="badge-pill delayed"><span class="mini-dot"></span> Delayed</span></td>
                        <td data-label="Timeline">
                          <div class="table-primary-text">02 Mar 2021</div>
                          <div class="table-secondary-text">to 01 Jul 2021</div>
                        </td>
                        <td data-label="Budget">₹72L</td>
                        <td data-label="Progress">
                          <div class="progress-mini">
                            <div class="progress-value">35%</div>
                            <div class="progress"><div class="progress-bar bg-warning" style="width:35%"></div></div>
                          </div>
                        </td>
                        <td data-label="Actions">
                          <div class="action-group">
                            <a class="action-btn view-btn" href="view-project.php?id=1004" title="View"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit-btn" href="edit-project.php?id=1004" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <a class="action-btn delete-btn" href="delete-project.php?id=1004" title="Delete" onclick="return confirm('Are you sure you want to delete this project?');"><i class="bi bi-trash"></i></a>
                          </div>
                        </td>
                      </tr>

                      <tr data-status="progressing" data-type="residential">
                        <td data-label="Project">
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-house-gear"></i></div>
                            <div>
                              <div class="table-primary-text">Green Villa Site</div>
                              <div class="table-secondary-text">PRJ-2001 • Residential • Chennai</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Client">
                          <div class="table-primary-text">Green Homes</div>
                          <div class="table-secondary-text">Manager: Arun Kumar</div>
                        </td>
                        <td data-label="Status"><span class="badge-pill progressing"><span class="mini-dot"></span> Planning</span></td>
                        <td data-label="Timeline">
                          <div class="table-primary-text">14 May 2026</div>
                          <div class="table-secondary-text">to 20 Dec 2026</div>
                        </td>
                        <td data-label="Budget">₹45L</td>
                        <td data-label="Progress">
                          <div class="progress-mini">
                            <div class="progress-value">18%</div>
                            <div class="progress"><div class="progress-bar bg-primary" style="width:18%"></div></div>
                          </div>
                        </td>
                        <td data-label="Actions">
                          <div class="action-group">
                            <a class="action-btn view-btn" href="view-project.php?id=2001" title="View"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit-btn" href="edit-project.php?id=2001" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <a class="action-btn delete-btn" href="delete-project.php?id=2001" title="Delete" onclick="return confirm('Are you sure you want to delete this project?');"><i class="bi bi-trash"></i></a>
                          </div>
                        </td>
                      </tr>

                      <tr data-status="on track" data-type="commercial">
                        <td data-label="Project">
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-building-check"></i></div>
                            <div>
                              <div class="table-primary-text">Metro Office Block</div>
                              <div class="table-secondary-text">PRJ-2002 • Commercial • Bengaluru</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Client">
                          <div class="table-primary-text">Metro Corp</div>
                          <div class="table-secondary-text">Manager: Michael Smith</div>
                        </td>
                        <td data-label="Status"><span class="badge-pill ontrack"><span class="mini-dot"></span> Started</span></td>
                        <td data-label="Timeline">
                          <div class="table-primary-text">11 May 2026</div>
                          <div class="table-secondary-text">to 15 Feb 2027</div>
                        </td>
                        <td data-label="Budget">₹1.2Cr</td>
                        <td data-label="Progress">
                          <div class="progress-mini">
                            <div class="progress-value">22%</div>
                            <div class="progress"><div class="progress-bar bg-success" style="width:22%"></div></div>
                          </div>
                        </td>
                        <td data-label="Actions">
                          <div class="action-group">
                            <a class="action-btn view-btn" href="view-project.php?id=2002" title="View"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit-btn" href="edit-project.php?id=2002" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <a class="action-btn delete-btn" href="delete-project.php?id=2002" title="Delete" onclick="return confirm('Are you sure you want to delete this project?');"><i class="bi bi-trash"></i></a>
                          </div>
                        </td>
                      </tr>

                      <tr data-status="pending" data-type="industrial">
                        <td data-label="Project">
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-bricks"></i></div>
                            <div>
                              <div class="table-primary-text">Warehouse Phase 2</div>
                              <div class="table-secondary-text">PRJ-2003 • Industrial • Coimbatore</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Client">
                          <div class="table-primary-text">Prime Logistics</div>
                          <div class="table-secondary-text">Manager: David Lee</div>
                        </td>
                        <td data-label="Status"><span class="badge-pill pending"><span class="mini-dot"></span> Pending</span></td>
                        <td data-label="Timeline">
                          <div class="table-primary-text">07 May 2026</div>
                          <div class="table-secondary-text">to 28 Jan 2027</div>
                        </td>
                        <td data-label="Budget">₹85L</td>
                        <td data-label="Progress">
                          <div class="progress-mini">
                            <div class="progress-value">10%</div>
                            <div class="progress"><div class="progress-bar bg-secondary" style="width:10%"></div></div>
                          </div>
                        </td>
                        <td data-label="Actions">
                          <div class="action-group">
                            <a class="action-btn view-btn" href="view-project.php?id=2003" title="View"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit-btn" href="edit-project.php?id=2003" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <a class="action-btn delete-btn" href="delete-project.php?id=2003" title="Delete" onclick="return confirm('Are you sure you want to delete this project?');"><i class="bi bi-trash"></i></a>
                          </div>
                        </td>
                      </tr>

                      <tr data-status="at risk" data-type="infrastructure">
                        <td data-label="Project">
                          <div class="table-title-cell">
                            <div class="table-icon"><i class="bi bi-cone-striped"></i></div>
                            <div>
                              <div class="table-primary-text">Road Extension</div>
                              <div class="table-secondary-text">PRJ-2004 • Infrastructure • Madurai</div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Client">
                          <div class="table-primary-text">City Works</div>
                          <div class="table-secondary-text">Manager: Sarah Paul</div>
                        </td>
                        <td data-label="Status"><span class="badge-pill atrisk"><span class="mini-dot"></span> Review</span></td>
                        <td data-label="Timeline">
                          <div class="table-primary-text">02 May 2026</div>
                          <div class="table-secondary-text">to 30 Apr 2027</div>
                        </td>
                        <td data-label="Budget">₹2.4Cr</td>
                        <td data-label="Progress">
                          <div class="progress-mini">
                            <div class="progress-value">15%</div>
                            <div class="progress"><div class="progress-bar bg-danger" style="width:15%"></div></div>
                          </div>
                        </td>
                        <td data-label="Actions">
                          <div class="action-group">
                            <a class="action-btn view-btn" href="view-project.php?id=2004" title="View"><i class="bi bi-eye"></i></a>
                            <a class="action-btn edit-btn" href="edit-project.php?id=2004" title="Edit"><i class="bi bi-pencil-square"></i></a>
                            <a class="action-btn delete-btn" href="delete-project.php?id=2004" title="Delete" onclick="return confirm('Are you sure you want to delete this project?');"><i class="bi bi-trash"></i></a>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="pagination-wrap">
                  <div class="pagination-info">Showing 1 to 8 of 16 project records</div>

                  <div class="d-flex gap-1">
                    <button class="page-btn" type="button">Prev</button>
                    <button class="page-btn active" type="button">1</button>
                    <button class="page-btn" type="button">2</button>
                    <button class="page-btn" type="button">Next</button>
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

  <!-- Sidebar Toggle JS -->
  <script src="assets/js/sidebar-toggle.js"></script>

  <!-- Project Search and Filter JS only -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const searchInput = document.getElementById('projectSearch');
      const statusFilter = document.getElementById('statusFilter');
      const typeFilter = document.getElementById('typeFilter');
      const tableRows = document.querySelectorAll('#projectsTable tbody tr');

      function filterProjects() {
        const searchValue = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const statusValue = statusFilter ? statusFilter.value.toLowerCase().trim() : '';
        const typeValue = typeFilter ? typeFilter.value.toLowerCase().trim() : '';

        tableRows.forEach(function (row) {
          const rowText = row.innerText.toLowerCase();
          const rowStatus = row.getAttribute('data-status') || '';
          const rowType = row.getAttribute('data-type') || '';

          const matchesSearch = rowText.includes(searchValue);
          const matchesStatus = !statusValue || rowStatus === statusValue;
          const matchesType = !typeValue || rowType === typeValue;

          row.style.display = matchesSearch && matchesStatus && matchesType ? '' : 'none';
        });
      }

      if (searchInput) searchInput.addEventListener('input', filterProjects);
      if (statusFilter) statusFilter.addEventListener('change', filterProjects);
      if (typeFilter) typeFilter.addEventListener('change', filterProjects);
    });
  </script>

</body>
</html>