<!-- Sidebar (MANAGER MENU) — Checklist separated (NOT under Time Management) -->
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

    <!-- My Sites -->
    <a class="side-link" href="my-sites.php">
      <i class="bi bi-geo-alt"></i><span class="label">My Sites</span>
    </a>

    <!-- Today Task -->
    <a class="side-link" href="today-tasks.php">
      <i class="bi bi-check2-square"></i><span class="label">Today Task</span>
    </a>

    <!-- ✅ Time Management (Dropdown + Flyout when sidebar collapsed) -->
    <button class="side-link side-toggle" type="button"
            id="tmToggle"
            aria-expanded="false"
            aria-controls="tmMenu"
            title="Time Management">
      <i class="bi bi-clock-history"></i>
      <span class="label">Time Management</span>
    </button>

    <div class="side-submenu" id="tmMenu" hidden>
      <a class="side-sublink" href="dpr.php">
        <i class="bi bi-journal-text"></i><span class="label">DPR</span>
      </a>
      <a class="side-sublink" href="dar.php">
        <i class="bi bi-clipboard-check"></i><span class="label">DAR</span>
      </a>
      <a class="side-sublink" href="ma.php">
        <i class="bi bi-calendar2-week"></i><span class="label">MA</span>
      </a>
      <a class="side-sublink" href="mpt.php">
        <i class="bi bi-list-task"></i><span class="label">MPT</span>
      </a>
      <a class="side-sublink" href="mom.php">
        <i class="bi bi-chat-left-text"></i><span class="label">MOM</span>
      </a>
    </div>

    <!-- ✅ Checklist (Separated, NOT under Time Management) -->
    <a class="side-link" href="checklist.php">
      <i class="bi bi-card-checklist"></i><span class="label">Checklist</span>
    </a>

    <!-- My Profile -->
    <a class="side-link" href="my-profile.php">
      <i class="bi bi-person-circle"></i><span class="label">My Profile</span>
    </a>

    <!-- Report -->
    <a class="side-link" href="report.php">
      <i class="bi bi-file-earmark-text"></i><span class="label">Report</span>
    </a>

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
  /* ---------- Time Management submenu styles ---------- */

  .side-toggle{
    width:100%;
    background:transparent;
    border:none;
    text-align:left;
    display:flex;
    align-items:center;
    gap:.6rem;
    cursor:pointer;
  }
  .side-toggle .chevron{ transition: transform .2s ease; }
  .side-toggle[aria-expanded="true"] .chevron{ transform: rotate(180deg); }

  .side-submenu{
    margin: 6px 0 10px;
    padding-left: 38px;
    display:flex;
    flex-direction:column;
    gap:6px;
  }

  .side-sublink{
    display:flex;
    align-items:center;
    gap:.6rem;
    padding: 8px 10px;
    border-radius: 10px;
    text-decoration:none;
    color: inherit;
    font-weight: 800;
    opacity:.95;
  }
  .side-sublink:hover{ background: rgba(0,0,0,.05); }
  .side-sublink.active{ background: rgba(45,156,219,.12); color: var(--blue, #2d9cdb); }

  /* Collapsed flyout */
  #sidebar{ position: relative; }

  #sidebar.collapsed .side-submenu{
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

  #sidebar.collapsed .side-submenu .label{
    display: inline !important;
  }

  #sidebar.collapsed .side-sublink{
    padding: 10px 10px;
    border-radius: 12px;
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const currentPage = window.location.pathname.split('/').pop() || 'index.php';

  // Highlight top-level links (excluding the toggle button)
  const sideLinks = document.querySelectorAll('.side-link:not(.side-toggle)');
  sideLinks.forEach(link => link.classList.remove('active'));
  sideLinks.forEach(link => {
    const href = link.getAttribute('href');
    if (href && href === currentPage) link.classList.add('active');
  });

  // Highlight submenu links and auto-open if active
  const subLinks = document.querySelectorAll('.side-sublink');
  let hasActiveSub = false;
  subLinks.forEach(a => {
    a.classList.remove('active');
    const href = a.getAttribute('href');
    if (href && href === currentPage) {
      a.classList.add('active');
      hasActiveSub = true;
    }
  });

  const sidebar  = document.getElementById('sidebar');
  const tmToggle = document.getElementById('tmToggle');
  const tmMenu   = document.getElementById('tmMenu');

  function isCollapsed(){
    return sidebar && sidebar.classList.contains('collapsed');
  }

  function setFlyoutTop(){
    if (!sidebar || !tmToggle) return;
    const top = tmToggle.offsetTop;
    sidebar.style.setProperty('--tm-top', top + 'px');
  }

  function setTm(open){
    if (!tmToggle || !tmMenu) return;
    if (open && isCollapsed()) setFlyoutTop();
    tmToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    tmMenu.hidden = !open;
    try { localStorage.setItem('tm_open', open ? '1' : '0'); } catch(e){}
  }

  // Initial open state: saved OR submenu active
  let openInit = false;
  try { openInit = (localStorage.getItem('tm_open') === '1'); } catch(e){}
  if (hasActiveSub) openInit = true;
  setTm(openInit);

  // Toggle click
  if (tmToggle) {
    tmToggle.addEventListener('click', function(e){
      e.preventDefault();
      const isOpen = tmToggle.getAttribute('aria-expanded') === 'true';
      setTm(!isOpen);
    });
  }

  // Close flyout when clicking outside
  document.addEventListener('click', function(e){
    if (!tmToggle || !tmMenu) return;
    const isOpen = tmToggle.getAttribute('aria-expanded') === 'true';
    if (!isOpen) return;
    const clickedInside = tmToggle.contains(e.target) || tmMenu.contains(e.target);
    if (!clickedInside) setTm(false);
  });

  window.addEventListener('resize', function(){
    const isOpen = tmToggle && tmToggle.getAttribute('aria-expanded') === 'true';
    if (isOpen && isCollapsed()) setFlyoutTop();
  });

  const observer = new MutationObserver(() => {
    const isOpen = tmToggle && tmToggle.getAttribute('aria-expanded') === 'true';
    if (isOpen && isCollapsed()) setFlyoutTop();
  });
  if (sidebar) observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });

  // Confirm before logout
  const logoutLink = document.getElementById('logoutLink');
  if (logoutLink) {
    logoutLink.addEventListener('click', function(e) {
      const ok = confirm('Are you sure you want to logout?');
      if (!ok) e.preventDefault();
    });
  }
});
</script>
