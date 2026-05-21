<!-- Sidebar -->
<aside id="sidebar" class="sidebar" aria-label="Sidebar">
  <div class="brand">
    <div class="brand-badge p-0">
      <img src="assets/tek-c.png" alt="TEK-C" />
    </div>
    <div class="brand-title">TEK-C</div>
  </div>

  <div class="nav-section">
    <!-- Dashboard -->
    <a class="side-link active" href="index.php">
      <i class="bi bi-grid-1x2"></i><span class="label">Dashboard</span>
    </a>

    <!-- Projects -->
    <a class="side-link" href="projects.php">
      <i class="bi bi-kanban"></i><span class="label">Projects</span>
    </a>
    <!-- NEW: Office Locations -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuOffices" role="button" aria-expanded="false">
      <i class="bi bi-building"></i><span class="label">Offices</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuOffices">
      <a class="side-link" href="add-office.php"><i class="bi bi-plus-circle"></i><span class="label">Add
          Office</span></a>
      <a class="side-link" href="manage-offices.php"><i class="bi bi-building"></i><span class="label">Manage
          Offices</span></a>
      <a class="side-link" href="office-locations.php"><i class="bi bi-map"></i><span class="label">Office
          Locations</span></a>
    </div>
    <!-- Employees -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuEmployees" role="button" aria-expanded="false">
      <i class="bi bi-person-badge"></i><span class="label">Employees</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuEmployees">
      <a class="side-link" href="add-employee.php"><i class="bi bi-person-plus"></i><span class="label">Add
          Employee</span></a>
      <a class="side-link" href="employees.php"><i class="bi bi-people"></i><span class="label">Manage
          Employees</span></a>
    </div>
    <a class="side-link" href="attendance.php">
    <i class="bi bi-calendar-check"></i>
    <span class="label">Attendance</span>
</a>

<a class="side-link" href="leave-requests.php">
    <i class="bi bi-person-workspace"></i>
    <span class="label">Leave Request</span>
</a>
    <!-- Sites -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuSites" role="button" aria-expanded="false">
      <i class="bi bi-geo-alt"></i><span class="label">Sites</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuSites">
      <!-- ✅ ADDED THIS OPTION -->
      <a class="side-link" href="add-site.php"><i class="bi bi-plus-circle"></i><span class="label">Add Site</span></a>

      <a class="side-link" href="manage-sites.php"><i class="bi bi-map"></i><span class="label">Manage Sites</span></a>
    </div>

    <!-- Clients -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuClients" role="button" aria-expanded="false">
      <i class="bi bi-building"></i><span class="label">Clients</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuClients">
      <a class="side-link" href="add-client.php"><i class="bi bi-building-add"></i><span class="label">Add
          Client</span></a>
      <a class="side-link" href="manage-clients.php"><i class="bi bi-buildings"></i><span class="label">Manage
          Clients</span></a>
    </div>

    <!-- Credentials -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuCredentials" role="button" aria-expanded="false">
      <i class="bi bi-key"></i><span class="label">Credentials</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuCredentials">
      <a class="side-link" href="manage-credentials.php"><i class="bi bi-shield-lock"></i><span class="label">Manage
          Credentials</span></a>
    </div>

        <a class="side-link collapse-toggle" data-bs-toggle="collapse" href="#quotationMenu"  title="Vendor Finalization Tender" role="button" aria-expanded="false" aria-controls="quotationMenu">
      <i class="bi bi-file-text"></i>
      <span class="label">Vendor Finalization Tender</span>
      <span class="ms-auto label chevron-wrap">
        <i class="bi bi-chevron-down chevron"></i>
      </span>
    </a>

    <div class="collapse ps-2 side-submenu-collapse" id="quotationMenu">
      <a class="side-link sub-link" href="all-quotation-requests.php">
        <i class="bi bi-inbox"></i>
        <span class="label">All VFT</span>
      </a>

      <a class="side-link sub-link" href="dealers.php">
        <i class="bi bi-shop"></i>
        <span class="label">Dealers</span>
      </a>
    </div>

    <!-- Accounts -->

<!-- 
    <a class="side-link" href="leave-requests.php">
      <i class="bi bi-gear"></i><span class="label">Leave Request List</span>
    </a>
    <div class="collapse ps-2" id="menuAccounts">
      <a class="side-link" href="add-account.php"><i class="bi bi-plus-lg"></i><span class="label">Add Account
          Entry</span></a>
      <a class="side-link" href="accounts.php"><i class="bi bi-journal-text"></i><span class="label">Manage
          Accounts</span></a>
    </div> -->



    <!-- Weekly Bills -->
    <!-- <a class="side-link" data-bs-toggle="collapse" href="#menuWeeklyBills" role="button" aria-expanded="false">
      <i class="bi bi-receipt"></i><span class="label">Weekly Bills</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuWeeklyBills">
      <a class="side-link" href="add-weekly-bill.php"><i class="bi bi-receipt-cutoff"></i><span class="label">Add Weekly
          Bill</span></a>
      <a class="side-link" href="weekly-bills.php"><i class="bi bi-receipt"></i><span class="label">Manage Weekly
          Bills</span></a>
    </div> -->

    <!-- Reports -->
    <a class="side-link" href="emp-reports.php">
      <i class="bi bi-file-earmark-bar-graph"></i><span class="label">Reports Hub</span>
    </a>

        <a class="side-link collapse-toggle" data-bs-toggle="collapse" href="#hrMasterMenu" role="button" aria-expanded="false" aria-controls="hrMasterMenu">
  
     <i class="bi bi-database-gear"></i>
     <span class="label">Document Config</span>
     <span class="ms-auto label chevron-wrap">
       <i class="bi bi-chevron-down chevron"></i>
     </span>
    </a>
<!--  -->
    <div class="collapse ps-2 side-submenu-collapse" id="hrMasterMenu">
     <a class="side-link sub-link" href="manage-stakeholder-types.php">
       <i class="bi bi-people-fill"></i>
       <span class="label">Manage Stakeholder Types</span>
     </a>
     <a class="side-link sub-link" href="vfs_packages.php">
       <i class="bi bi-box-seam"></i>
       <span class="label">VFS Packages</span>
     </a>
    </div>

    <!-- Settings -->
    <a class="side-link" href="manage-settings.php">
      <i class="bi bi-gear"></i><span class="label">Settings</span>
    </a>


    <!-- Logout (NEW) -->
    <a class="side-link" href="logout.php" id="logoutLink">
      <i class="bi bi-box-arrow-right"></i><span class="label">Logout</span>
    </a>
  </div>

  <div class="sidebar-footer">
    <div class="footer-text">© TEK-C • v1.0</div>
  </div>
</aside>

<div id="overlay" class="overlay" aria-hidden="true"></div>

<script>
  // Auto-collapse other sections when one is expanded (works for all collapses)
  document.addEventListener('DOMContentLoaded', function () {
    const collapseLinks = document.querySelectorAll('[data-bs-toggle="collapse"]');

    collapseLinks.forEach(link => {
      link.addEventListener('click', function () {
        const targetId = this.getAttribute('href');
        const targetCollapse = document.querySelector(targetId);
        if (!targetCollapse) return;

        // If clicking already open section, do nothing
        if (targetCollapse.classList.contains('show')) return;

        // Collapse other open collapses at the same level
        const isTopLevelToggle = this.parentElement && this.parentElement.classList.contains('nav-section');

        if (isTopLevelToggle) {
          // Close any open top-level collapses
          document.querySelectorAll('#sidebar .nav-section > .collapse.show').forEach(openEl => {
            if (openEl !== targetCollapse) {
              const bs = bootstrap.Collapse.getInstance(openEl) || new bootstrap.Collapse(openEl, { toggle: false });
              bs.hide();
            }
          });
        } else {
          // Nested: close open collapses inside same collapse container
          const nearestCollapse = this.closest('.collapse');
          if (nearestCollapse) {
            nearestCollapse.querySelectorAll('.collapse.show').forEach(openEl => {
              if (openEl !== targetCollapse) {
                const bs = bootstrap.Collapse.getInstance(openEl) || new bootstrap.Collapse(openEl, { toggle: false });
                bs.hide();
              }
            });
          }
        }
      });
    });

    // Active page highlight + expand correct parent menus
    const currentPage = window.location.pathname.split('/').pop() || 'index.php';
    const sideLinks = document.querySelectorAll('.side-link');

    sideLinks.forEach(link => link.classList.remove('active'));

    sideLinks.forEach(link => {
      const href = link.getAttribute('href');
      if (href && href === currentPage) {
        link.classList.add('active');

        // Expand all parent collapses for this active link
        let parentCollapse = link.closest('.collapse');
        while (parentCollapse) {
          const bs = bootstrap.Collapse.getInstance(parentCollapse) || new bootstrap.Collapse(parentCollapse, { toggle: false });
          bs.show();
          parentCollapse = parentCollapse.parentElement ? parentCollapse.parentElement.closest('.collapse') : null;
        }
      }
    });

    // Optional: confirm before logout
    const logoutLink = document.getElementById('logoutLink');
    if (logoutLink) {
      logoutLink.addEventListener('click', function (e) {
        const ok = confirm('Are you sure you want to logout?');
        if (!ok) e.preventDefault();
      });
    }
  });
</script>