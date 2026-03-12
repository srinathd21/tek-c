<!-- HR Sidebar -->
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
      <i class="bi bi-grid-1x2"></i>
      <span class="label">Dashboard</span>
    </a>

    <!-- Employees -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuEmployeesHR" role="button" aria-expanded="false">
      <i class="bi bi-people"></i>
      <span class="label">Employees</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuEmployeesHR">
      <a class="side-link" href="add-employee.php">
        <i class="bi bi-person-plus"></i>
        <span class="label">Add Employee</span>
      </a>
      <a class="side-link" href="manage-employees.php">
        <i class="bi bi-person-badge"></i>
        <span class="label">Manage Employee</span>
      </a>
    </div>

    <!-- Attendance -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuAttendance" role="button" aria-expanded="false">
      <i class="bi bi-calendar-check"></i>
      <span class="label">Attendance</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuAttendance">
      <a class="side-link" href="attendance.php">
        <i class="bi bi-clock-history"></i>
        <span class="label">Manage Attendance</span>
      </a>
      <a class="side-link" href="leave-requests.php">
        <i class="bi bi-calendar2-x"></i>
        <span class="label">Leave Request</span>
      </a>
      <a class="side-link" href="manage-holidays.php">
        <i class="bi bi-calendar-event"></i>
        <span class="label">Manage Holiday</span>
      </a>
    </div>

    <!-- Payroll -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuPayroll" role="button" aria-expanded="false">
      <i class="bi bi-cash-coin"></i>
      <span class="label">Payroll</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuPayroll">
      <a class="side-link" href="payslips.php">
        <i class="bi bi-file-earmark-text"></i>
        <span class="label">Payslips</span>
      </a>
      <a class="side-link" href="payroll.php">
        <i class="bi bi-receipt"></i>
        <span class="label">Payroll</span>
      </a>
    </div>

    <!-- Mail -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuMail" role="button" aria-expanded="false">
      <i class="bi bi-envelope"></i>
      <span class="label">Mail</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuMail">
      <a class="side-link" href="mail-inbox.php">
        <i class="bi bi-inbox"></i>
        <span class="label">Inbox</span>
      </a>
      <a class="side-link" href="mail-compose.php">
        <i class="bi bi-pencil-square"></i>
        <span class="label">Compose</span>
      </a>
      <a class="side-link" href="mail-sent.php">
        <i class="bi bi-send"></i>
        <span class="label">Sent</span>
      </a>
      <a class="side-link" href="mail-drafts.php">
        <i class="bi bi-file-earmark"></i>
        <span class="label">Drafts</span>
      </a>
      <a class="side-link" href="mail-scheduled.php">
        <i class="bi bi-clock"></i>
        <span class="label">Scheduled</span>
      </a>
      <a class="side-link" href="mail-spam.php">
        <i class="bi bi-exclamation-octagon"></i>
        <span class="label">Spam</span>
      </a>
      <a class="side-link" href="mail-trash.php">
        <i class="bi bi-trash"></i>
        <span class="label">Trash</span>
      </a>
    </div>

    <!-- Reports Hub -->
    <a class="side-link" href="reports-hub.php">
      <i class="bi bi-bar-chart-line"></i>
      <span class="label">Reports Hub</span>
    </a>

    <!-- My Profile -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuMyProfile" role="button" aria-expanded="false">
      <i class="bi bi-person-circle"></i>
      <span class="label">My Profile</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="menuMyProfile">
      <a class="side-link" href="attendance-regularization.php">
        <i class="bi bi-calendar-check"></i>
        <span class="label">Attendance Regularization</span>
      </a>
      <a class="side-link" href="my-leave-history.php">
        <i class="bi bi-clock-history"></i>
        <span class="label">My Leave History</span>
      </a>
      <a class="side-link" href="salary-loan.php">
        <i class="bi bi-cash-stack"></i>
        <span class="label">Salary Loan</span>
      </a>
      <a class="side-link" href="apply-leave.php">
        <i class="bi bi-calendar-plus"></i>
        <span class="label">Apply Leave</span>
      </a>
    </div>

    <!-- Logout -->
    <a class="side-link" href="logout.php" id="logoutLink">
      <i class="bi bi-box-arrow-right"></i>
      <span class="label">Logout</span>
    </a>

  </div>

  <div class="sidebar-footer">
    <div class="footer-text">© TEK-C • HR Panel</div>
  </div>
</aside>

<div id="overlay" class="overlay" aria-hidden="true"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const collapseLinks = document.querySelectorAll('[data-bs-toggle="collapse"]');

  collapseLinks.forEach(link => {
    link.addEventListener('click', function() {
      const targetId = this.getAttribute('href');
      const targetCollapse = document.querySelector(targetId);
      if (!targetCollapse) return;

      if (targetCollapse.classList.contains('show')) return;

      document.querySelectorAll('#sidebar .nav-section > .collapse.show').forEach(openEl => {
        if (openEl !== targetCollapse) {
          const bs = bootstrap.Collapse.getInstance(openEl) || new bootstrap.Collapse(openEl, { toggle: false });
          bs.hide();
        }
      });
    });
  });

  const currentPage = window.location.pathname.split('/').pop() || 'index.php';
  const sideLinks = document.querySelectorAll('.side-link');

  sideLinks.forEach(link => link.classList.remove('active'));

  sideLinks.forEach(link => {
    const href = link.getAttribute('href');
    if (href && href === currentPage) {
      link.classList.add('active');

      let parentCollapse = link.closest('.collapse');
      while (parentCollapse) {
        const bs = bootstrap.Collapse.getInstance(parentCollapse) || new bootstrap.Collapse(parentCollapse, { toggle: false });
        bs.show();

        const trigger = document.querySelector(`[href="#${parentCollapse.id}"]`);
        if (trigger) trigger.classList.add('active');

        parentCollapse = parentCollapse.parentElement
          ? parentCollapse.parentElement.closest('.collapse')
          : null;
      }
    }
  });

  if (currentPage === 'index.php') {
    const dash = document.querySelector('.side-link[href="index.php"]');
    if (dash) dash.classList.add('active');
  }

  const logoutLink = document.getElementById('logoutLink');
  if (logoutLink) {
    logoutLink.addEventListener('click', function(e) {
      const ok = confirm('Are you sure you want to logout?');
      if (!ok) e.preventDefault();
    });
  }
});
</script>