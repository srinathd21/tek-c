<!-- Sidebar (PROJECT ENGINEER MENU) — MANAGER PANEL TEMPLATE -->
<aside id="sidebar" class="sidebar" aria-label="Sidebar">

  <div class="brand">
    <div class="brand-badge p-0">
      <img src="assets/tek-c.png" alt="TEK-C" />
    </div>
    <div class="brand-title">TEK-C</div>
  </div>

  <div class="nav-section">

    <!-- Dashboard -->
    <a class="side-link" href="index.php">
      <i class="bi bi-grid-1x2"></i>
      <span class="label">Dashboard</span>
    </a>

    <!-- Attendance -->
    <a class="side-link" href="punchin.php">
      <i class="bi bi-fingerprint"></i>
      <span class="label">Attendance</span>
    </a>

    <!-- Projects -->
    <a class="side-link" href="my-sites.php">
      <i class="bi bi-geo-alt"></i>
      <span class="label">My Projects</span>
    </a>

    <!-- Quotation Management -->
    <a class="side-link collapse-toggle"
       data-bs-toggle="collapse"
       href="#quotationMenu"
       role="button"
       aria-expanded="false"
       aria-controls="quotationMenu">
      <i class="bi bi-file-text"></i>
      <span class="label">Quotations</span>
      <span class="ms-auto label chevron-wrap">
        <i class="bi bi-chevron-down chevron"></i>
      </span>
    </a>

    <div class="collapse ps-2 side-submenu-collapse" id="quotationMenu">
      <a class="side-link sub-link" href="quotation-requests.php">
        <i class="bi bi-plus-circle"></i>
        <span class="label">New Request</span>
      </a>

      <a class="side-link sub-link" href="my-quotation-requests.php">
        <i class="bi bi-list-check"></i>
        <span class="label">My Requests</span>
      </a>
    </div>

    <!-- Today's Reports -->
    <a class="side-link" href="emp-reports.php">
      <i class="bi bi-journal-text"></i>
      <span class="label">Reports Hub</span>
    </a>

    <!-- Mail -->
    <a class="side-link collapse-toggle"
       data-bs-toggle="collapse"
       href="#mailMenu"
       role="button"
       aria-expanded="false"
       aria-controls="mailMenu">
      <i class="bi bi-envelope"></i>
      <span class="label">Mail</span>
      <span class="ms-auto label chevron-wrap">
        <i class="bi bi-chevron-down chevron"></i>
      </span>
    </a>

    <div class="collapse ps-2 side-submenu-collapse" id="mailMenu">
      <a class="side-link sub-link" href="mail-inbox.php">
        <i class="bi bi-inbox"></i>
        <span class="label">Inbox</span>
      </a>

      <a class="side-link sub-link" href="mail-compose.php">
        <i class="bi bi-pencil-square"></i>
        <span class="label">Compose</span>
      </a>

      <a class="side-link sub-link" href="mail-sent.php">
        <i class="bi bi-send"></i>
        <span class="label">Sent</span>
      </a>

      <a class="side-link sub-link" href="mail-trash.php">
        <i class="bi bi-trash"></i>
        <span class="label">Trash</span>
      </a>
    </div>

    <!-- Time Management -->
    <a class="side-link collapse-toggle"
       data-bs-toggle="collapse"
       href="#tmMenu"
       role="button"
       aria-expanded="false"
       aria-controls="tmMenu">
      <i class="bi bi-clock-history"></i>
      <span class="label">Time Management</span>
      <span class="ms-auto label chevron-wrap">
        <i class="bi bi-chevron-down chevron"></i>
      </span>
    </a>

    <div class="collapse ps-2 side-submenu-collapse" id="tmMenu">
      <a class="side-link sub-link" href="dpr.php">
        <i class="bi bi-journal-text"></i>
        <span class="label">DPR</span>
      </a>

      <a class="side-link sub-link" href="dar.php">
        <i class="bi bi-check2-square"></i>
        <span class="label">DAR</span>
      </a>

      <a class="side-link sub-link" href="ma.php">
        <i class="bi bi-calendar2-week"></i>
        <span class="label">MA</span>
      </a>

      <a class="side-link sub-link" href="mpt.php">
        <i class="bi bi-list-task"></i>
        <span class="label">MPT</span>
      </a>

      <a class="side-link sub-link" href="mom.php">
        <i class="bi bi-chat-left-text"></i>
        <span class="label">MOM</span>
      </a>

      <a class="side-link sub-link" href="mom-short.php">
        <i class="bi bi-chat-left-quote"></i>
        <span class="label">MOM (Short-term)</span>
      </a>

      <a class="side-link sub-link" href="rfi.php">
        <i class="bi bi-question-circle"></i>
        <span class="label">RFI</span>
      </a>

      <a class="side-link sub-link" href="checklist.php">
        <i class="bi bi-card-checklist"></i>
        <span class="label">Checklist</span>
      </a>

      <a class="side-link sub-link" href="sat.php">
        <i class="bi bi-bar-chart-steps"></i>
        <span class="label">SAT</span>
      </a>

      <a class="side-link sub-link" href="dlar.php">
        <i class="bi bi-file-earmark-spreadsheet"></i>
        <span class="label">DLAR</span>
      </a>

      <a class="side-link sub-link" href="ait.php">
        <i class="bi bi-cpu"></i>
        <span class="label">AIT</span>
      </a>

      <a class="side-link sub-link" href="mas.php">
        <i class="bi bi-diagram-3"></i>
        <span class="label">MAS</span>
      </a>

      <a class="side-link sub-link" href="pd.php">
        <i class="bi bi-graph-up"></i>
        <span class="label">PD</span>
      </a>

      <a class="side-link sub-link" href="pms.php">
        <i class="bi bi-tools"></i>
        <span class="label">PMS</span>
      </a>

      <a class="side-link sub-link" href="vfs.php">
        <i class="bi bi-eye"></i>
        <span class="label">VFS</span>
      </a>

      <a class="side-link sub-link" href="vft.php">
        <i class="bi bi-eye-fill"></i>
        <span class="label">VFT</span>
      </a>

      <a class="side-link sub-link" href="wpt.php">
        <i class="bi bi-database"></i>
        <span class="label">WPT</span>
      </a>

      <a class="side-link sub-link" href="dds.php">
        <i class="bi bi-database"></i>
        <span class="label">DDS</span>
      </a>

      <a class="side-link sub-link" href="ddt.php">
        <i class="bi bi-table"></i>
        <span class="label">DDT</span>
      </a>

      <a class="side-link sub-link" href="dpt.php">
        <i class="bi bi-pie-chart"></i>
        <span class="label">DPT</span>
      </a>
    </div>

    <!-- HR -->
    <a class="side-link collapse-toggle"
       data-bs-toggle="collapse"
       href="#hrMenu"
       role="button"
       aria-expanded="false"
       aria-controls="hrMenu">
      <i class="bi bi-people"></i>
      <span class="label">HR</span>
      <span class="ms-auto label chevron-wrap">
        <i class="bi bi-chevron-down chevron"></i>
      </span>
    </a>

    <div class="collapse ps-2 side-submenu-collapse" id="hrMenu">
      <a class="side-link sub-link" href="my-profile.php">
        <i class="bi bi-person-circle"></i>
        <span class="label">Profile</span>
      </a>

      <a class="side-link sub-link" href="my-attendance.php">
        <i class="bi bi-fingerprint"></i>
        <span class="label">My Attendance</span>
      </a>

      <a class="side-link sub-link" href="leave-ledger.php">
        <i class="bi bi-clock-history"></i>
        <span class="label">Leave Ledger</span>
      </a>

      <a class="side-link sub-link" href="payslips.php">
        <i class="bi bi-receipt"></i>
        <span class="label">Payslips</span>
      </a>

      <!--<a class="side-link sub-link" href="hr-policy.php">-->
      <!--  <i class="bi bi-file-earmark-text"></i>-->
      <!--  <span class="label">HR Policy</span>-->
      <!--</a>-->

      <!--<a class="side-link sub-link" href="salary-loan.php">-->
      <!--  <i class="bi bi-cash-stack"></i>-->
      <!--  <span class="label">Salary Loan Eligibility</span>-->
      <!--</a>-->

      <a class="side-link sub-link" href="attendance-regularization.php">
        <i class="bi bi-pencil-square"></i>
        <span class="label">Attendance Regularization</span>
      </a>

      <a class="side-link sub-link" href="apply-leave.php">
        <i class="bi bi-calendar-plus"></i>
        <span class="label">Apply Leave</span>
      </a>

      <a class="side-link sub-link" href="my-leave-history.php">
        <i class="bi bi-clock-history"></i>
        <span class="label">My Leave History</span>
      </a>
    </div>



    <!-- Logout -->
    <a class="side-link" href="logout.php" id="logoutLink">
      <i class="bi bi-box-arrow-right"></i>
      <span class="label">Logout</span>
    </a>

  </div>

  <div class="sidebar-footer">
    <div class="footer-text">© TEK-C • v1.0</div>
  </div>
</aside>

<div id="overlay" class="overlay" aria-hidden="true"></div>

<style>
  #sidebar {
    position: relative;
    overflow-y: auto;
    overflow-x: hidden;

    /* Hide scrollbar but keep scrolling */
    scrollbar-width: none;
    -ms-overflow-style: none;
  }

  #sidebar::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }

  #sidebar .nav-section {
    overflow-y: auto;
    overflow-x: hidden;

    /* Hide scrollbar but keep scrolling */
    scrollbar-width: none;
    -ms-overflow-style: none;
  }

  #sidebar .nav-section::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }

  #sidebar .side-submenu-collapse .side-link {
    padding-left: 1.9rem;
    border-radius: 10px;
    margin-top: 4px;
  }

  #sidebar .collapse-toggle .chevron,
  #sidebar [data-bs-toggle="collapse"] .chevron {
    transition: transform .2s ease;
  }

  #sidebar .collapse-toggle[aria-expanded="true"] .chevron,
  #sidebar [data-bs-toggle="collapse"][aria-expanded="true"] .chevron {
    transform: rotate(180deg);
  }

  #sidebar .badge {
    margin-left: auto;
    margin-right: 5px;
    padding: 3px 6px;
    border-radius: 10px;
    font-weight: normal;
  }

  #sidebar.collapsed .side-submenu-collapse {
    position: absolute;
    left: calc(100% + 10px);
    top: var(--flyout-top, 80px);
    width: 220px;
    padding: 10px;
    margin: 0;
    background: #fff;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px;
    box-shadow: 0 18px 40px rgba(17, 24, 39, .15);
    z-index: 9999;
  }

  #sidebar.collapsed .side-submenu-collapse .label {
    display: inline !important;
  }

  #sidebar.collapsed .side-submenu-collapse .side-link {
    padding: 10px 10px;
    margin-top: 0;
    border-radius: 12px;
  }

  @media (max-width: 991.98px) {
    #sidebar.collapsed .side-submenu-collapse {
      position: static;
      width: auto;
      padding: 0 0 0 .5rem;
      border: 0;
      box-shadow: none;
      background: transparent;
    }
  }
</style>
