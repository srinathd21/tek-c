<!-- Sidebar (MANAGER MENU) -->
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
      <i class="bi bi-grid-1x2"></i><span class="label">Dashboard</span>
    </a>

    <!-- My Projects -->
    <a class="side-link" href="my-projects.php">
      <i class="bi bi-kanban"></i><span class="label">My Projects</span>
    </a>

    <!-- My Team -->
    <a class="side-link" href="my-team.php">
      <i class="bi bi-people"></i><span class="label">My Team</span>
    </a>

    <!-- My Profile -->
    <a class="side-link" href="my-profile.php">
      <i class="bi bi-person-circle"></i><span class="label">My Profile</span>
    </a>

    <!-- Progress Report -->
    <a class="side-link" href="reports.php">
      <i class="bi bi-graph-up-arrow"></i><span class="label">Progress Report</span>
    </a>

    <!-- Time Management (Bootstrap collapse like Admin sidebar) -->
    <a class="side-link collapse-toggle" data-bs-toggle="collapse" href="#tmMenu" role="button" aria-expanded="false" aria-controls="tmMenu">
      <i class="bi bi-clock-history"></i><span class="label">Leave Management</span>
      <span class="ms-auto label chevron-wrap"><i class="bi bi-chevron-down chevron"></i></span>
    </a>

    <div class="collapse ps-2 side-submenu-collapse" id="tmMenu">
      <a class="side-link sub-link" href="leave-app-rej-list.php">
        <i class="bi bi-card-checklist"></i><span class="label">Leave Request List</span>
      </a>
      <a class="side-link sub-link" href="leave-request-list.php">
        <i class="bi bi-calendar-plus"></i><span class="label">Apply Leave</span>
      </a>
    </div>

    <!-- Logout -->
    <a class="side-link" href="logout.php" id="logoutLink">
      <i class="bi bi-box-arrow-right"></i><span class="label">Logout</span>
    </a>

  </div>

  <div class="sidebar-footer">
    <div class="footer-text">© TEK-C • v1.0</div>
  </div>
</aside>

<div id="overlay" class="overlay" aria-hidden="true"></div>

<style>
  /* Submenu indent */
  #sidebar .side-submenu-collapse .side-link {
    padding-left: 1.9rem;
    border-radius: 10px;
    margin-top: 4px;
  }

  /* Chevron animation */
  #sidebar .collapse-toggle .chevron {
    transition: transform .2s ease;
  }

  #sidebar .collapse-toggle[aria-expanded="true"] .chevron {
    transform: rotate(180deg);
  }

  /* Optional active submenu look (inherits your .side-link styles if already defined) */
  /* #sidebar .side-submenu-collapse .side-link.active {
    background: rgba(45,156,219,.12);
    color: var(--blue, #2d9cdb);
  } */

  /* If sidebar collapsed mode exists in your layout */
  #sidebar {
    position: relative;
  }

  #sidebar.collapsed .side-submenu-collapse {
    position: absolute;
    left: calc(100% + 10px);
    top: var(--tm-top, 80px);
    width: 220px;
    padding: 10px;
    margin: 0;
    background: #fff;
    border: 1px solid var(--border, #e5e7eb);
    border-radius: 14px;
    box-shadow: 0 18px 40px rgba(17,24,39,.15);
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
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const sidebar = document.getElementById('sidebar');
  const logoutLink = document.getElementById('logoutLink');
  const currentPage = window.location.pathname.split('/').pop() || 'index.php';

  // Bootstrap check
  const hasBootstrapCollapse = typeof bootstrap !== 'undefined' && bootstrap.Collapse;

  // All sidebar links
  const sideLinks = document.querySelectorAll('#sidebar .side-link');
  sideLinks.forEach(link => link.classList.remove('active'));

  // Active link highlighting (skip collapse toggles like href="#tmMenu")
  let activeLink = null;
  sideLinks.forEach(link => {
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#')) return;
    if (href === currentPage) {
      link.classList.add('active');
      activeLink = link;
    }
  });

  // Expand parent collapse(s) for active page
  if (activeLink && hasBootstrapCollapse) {
    let parentCollapse = activeLink.closest('.collapse');

    while (parentCollapse) {
      const instance = bootstrap.Collapse.getInstance(parentCollapse) ||
                       new bootstrap.Collapse(parentCollapse, { toggle: false });
      instance.show();

      const toggle = document.querySelector('#sidebar [data-bs-toggle="collapse"][href="#' + parentCollapse.id + '"]');
      if (toggle) toggle.setAttribute('aria-expanded', 'true');

      parentCollapse = parentCollapse.parentElement
        ? parentCollapse.parentElement.closest('.collapse')
        : null;
    }
  }

  // Auto-close other top-level collapses when opening one (same behavior as admin)
  const collapseToggles = document.querySelectorAll('#sidebar [data-bs-toggle="collapse"]');

  collapseToggles.forEach(toggle => {
    toggle.addEventListener('click', function () {
      const targetSelector = this.getAttribute('href');
      const targetCollapse = targetSelector ? document.querySelector(targetSelector) : null;
      if (!targetCollapse) return;

      // collapsed-sidebar flyout position support
      if (sidebar && sidebar.classList.contains('collapsed')) {
        sidebar.style.setProperty('--tm-top', this.offsetTop + 'px');
      }

      if (!hasBootstrapCollapse) return;

      // If target already open, let Bootstrap toggle it normally
      if (targetCollapse.classList.contains('show')) return;

      // Close other open top-level collapses
      document.querySelectorAll('#sidebar .nav-section > .collapse.show').forEach(openEl => {
        if (openEl !== targetCollapse) {
          const bs = bootstrap.Collapse.getInstance(openEl) ||
                     new bootstrap.Collapse(openEl, { toggle: false });
          bs.hide();

          const openToggle = document.querySelector('#sidebar [data-bs-toggle="collapse"][href="#' + openEl.id + '"]');
          if (openToggle) openToggle.setAttribute('aria-expanded', 'false');
        }
      });
    });
  });

  // Sync aria-expanded on Bootstrap collapse events + collapsed flyout position
  document.querySelectorAll('#sidebar .collapse').forEach(collapseEl => {
    collapseEl.addEventListener('show.bs.collapse', function () {
      const toggle = document.querySelector('#sidebar [data-bs-toggle="collapse"][href="#' + this.id + '"]');
      if (toggle && sidebar && sidebar.classList.contains('collapsed')) {
        sidebar.style.setProperty('--tm-top', toggle.offsetTop + 'px');
      }
    });

    collapseEl.addEventListener('shown.bs.collapse', function () {
      const toggle = document.querySelector('#sidebar [data-bs-toggle="collapse"][href="#' + this.id + '"]');
      if (toggle) toggle.setAttribute('aria-expanded', 'true');
    });

    collapseEl.addEventListener('hidden.bs.collapse', function () {
      const toggle = document.querySelector('#sidebar [data-bs-toggle="collapse"][href="#' + this.id + '"]');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    });
  });

  // Reposition flyout on resize if collapsed + open
  window.addEventListener('resize', function () {
    if (!sidebar || !sidebar.classList.contains('collapsed')) return;
    const tmCollapse = document.getElementById('tmMenu');
    const tmToggle = document.querySelector('#sidebar [data-bs-toggle="collapse"][href="#tmMenu"]');
    if (tmCollapse && tmCollapse.classList.contains('show') && tmToggle) {
      sidebar.style.setProperty('--tm-top', tmToggle.offsetTop + 'px');
    }
  });

  // Watch sidebar collapse/expand class changes
  if (sidebar) {
    const observer = new MutationObserver(function () {
      const tmCollapse = document.getElementById('tmMenu');
      const tmToggle = document.querySelector('#sidebar [data-bs-toggle="collapse"][href="#tmMenu"]');
      if (sidebar.classList.contains('collapsed') && tmCollapse && tmCollapse.classList.contains('show') && tmToggle) {
        sidebar.style.setProperty('--tm-top', tmToggle.offsetTop + 'px');
      }
    });

    observer.observe(sidebar, {
      attributes: true,
      attributeFilter: ['class']
    });
  }

  // Close flyout on outside click (collapsed mode only)
  document.addEventListener('click', function (e) {
    if (!sidebar || !sidebar.classList.contains('collapsed') || !hasBootstrapCollapse) return;

    const tmCollapse = document.getElementById('tmMenu');
    const tmToggle = document.querySelector('#sidebar [data-bs-toggle="collapse"][href="#tmMenu"]');
    if (!tmCollapse || !tmToggle || !tmCollapse.classList.contains('show')) return;

    const clickedInside = tmToggle.contains(e.target) || tmCollapse.contains(e.target);
    if (!clickedInside) {
      const bs = bootstrap.Collapse.getInstance(tmCollapse) || new bootstrap.Collapse(tmCollapse, { toggle: false });
      bs.hide();
    }
  });

  // Logout confirmation
  if (logoutLink) {
    logoutLink.addEventListener('click', function (e) {
      const ok = confirm('Are you sure you want to logout?');
      if (!ok) e.preventDefault();
    });
  }
});
</script>