<!-- HR Sidebar (Minimal) -->
<!-- HR Sidebar (Minimal) -->
<aside id="sidebar" class="sidebar" aria-label="Sidebar">
  <div class="brand">
    <div class="brand-badge p-0">
      <img src="assets/tek-c.png" alt="TEK-C" />
    </div>
    <div class="brand-title">TEK-C</div>
  </div>

  <div class="nav-section">

    <!-- HR Dashboard -->
    <a class="side-link active" href="index.php">
      <i class="bi bi-grid-1x2"></i><span class="label">HR Dashboard</span>
    </a>

    <!-- Employees -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuEmployeesHR">
      <i class="bi bi-person-badge"></i><span class="label">Employees</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>

    <div class="collapse ps-2" id="menuEmployeesHR">
      <a class="side-link" href="add-employee.php">
        <i class="bi bi-person-plus"></i><span class="label">Add Employee</span>
      </a>

      <a class="side-link" href="manage-employees.php">
        <i class="bi bi-people"></i><span class="label">Manage Employees</span>
      </a>
    </div>

    <!-- Attendance -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuAttendance">
      <i class="bi bi-calendar-check"></i><span class="label">Attendance</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>

    <div class="collapse ps-2" id="menuAttendance">
      <a class="side-link" href="punchin.php">
        <i class="bi bi-fingerprint"></i><span class="label">Attendance</span>
      </a>
     
      <a class="side-link" href="attendance.php">
        <i class="bi bi-clock-history"></i><span class="label">Manage Attendance</span>
      </a>

      <a class="side-link" href="leave-requests.php">
        <i class="bi bi-calendar2-x"></i><span class="label">Leave Requests</span>
      </a>

      <a class="side-link" href="manage-holidays.php">
        <i class="bi bi-calendar-event"></i><span class="label">Manage Holiday</span>
      </a>
    </div>
    <!-- Hiring -->
<a class="side-link" data-bs-toggle="collapse" href="#menuHiring">
  <i class="bi bi-briefcase"></i>
  <span class="label">Hiring</span>
  <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
</a>

<div class="collapse ps-2" id="menuHiring">

  <a class="side-link" href="hiring-requests.php">
    <i class="bi bi-plus-circle"></i>
    <span class="label">Hiring Request</span>
  </a>

  <a class="side-link" href="candidates.php">
    <i class="bi bi-people"></i>
    <span class="label">Candidates</span>
  </a>

  <a class="side-link" href="interviews.php">
    <i class="bi bi-camera-video"></i>
    <span class="label">Interviews</span>
  </a>

  <a class="side-link" href="offer-approval.php">
    <i class="bi bi-check-circle"></i>
    <span class="label">Offer Approval</span>
  </a>

  <a class="side-link" href="onboarding.php">
    <i class="bi bi-person-check"></i>
    <span class="label">Onboarding</span>
  </a>

</div>
    <!-- Payroll -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuPayroll">
      <i class="bi bi-cash-coin"></i><span class="label">Payroll</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>

    <div class="collapse ps-2" id="menuPayroll">
      <a class="side-link" href="payroll.php">
        <i class="bi bi-receipt"></i><span class="label">Payroll</span>
      </a>

      <a class="side-link" href="payslips.php">
        <i class="bi bi-file-earmark-text"></i><span class="label">Payslips</span>
      </a>
    </div>

    <!-- Mail -->
    <a class="side-link" data-bs-toggle="collapse" href="#menuMail">
      <i class="bi bi-envelope"></i><span class="label">Mail</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>

    <div class="collapse ps-2" id="menuMail">
      <a class="side-link" href="mail-inbox.php">
        <i class="bi bi-inbox"></i><span class="label">Inbox</span>
      </a>

      <a class="side-link" href="mail-compose.php">
        <i class="bi bi-pencil-square"></i><span class="label">Compose</span>
      </a>

      <a class="side-link" href="mail-sent.php">
        <i class="bi bi-send"></i><span class="label">Sent</span>
      </a>

      <a class="side-link" href="mail-drafts.php">
        <i class="bi bi-file-earmark"></i><span class="label">Drafts</span>
      </a>

      <a class="side-link" href="mail-scheduled.php">
        <i class="bi bi-clock"></i><span class="label">Scheduled</span>
      </a>

      <a class="side-link" href="mail-spam.php">
        <i class="bi bi-exclamation-circle"></i><span class="label">Spam</span>
      </a>

      <a class="side-link" href="mail-trash.php">
        <i class="bi bi-trash"></i><span class="label">Trash</span>
      </a>
    </div>

    <!-- Reports Hub -->
    <a class="side-link" href="reports-hub.php">
      <i class="bi bi-bar-chart"></i><span class="label">Reports Hub</span>
    </a>
    <!-- My Profile -->
<a class="side-link" data-bs-toggle="collapse" href="#menuMyProfile">
  <i class="bi bi-person-circle"></i>
  <span class="label">My Profile</span>
  <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
</a>

<div class="collapse ps-2" id="menuMyProfile">

  <a class="side-link" href="my-profile.php">
    <i class="bi bi-person"></i>
    <span class="label">Profile</span>
  </a>

  <a class="side-link" href="attendance-regularization.php">
    <i class="bi bi-clock-history"></i>
    <span class="label">Attendance Regularization</span>
  </a>

  <a class="side-link" href="apply-leave.php">
    <i class="bi bi-calendar-plus"></i>
    <span class="label">Apply Leave</span>
  </a>

  <a class="side-link" href="leave-request.php">
    <i class="bi bi-calendar-check"></i>
    <span class="label">My Leave History</span>
  </a>

  <a class="side-link" href="salary-loan.php">
    <i class="bi bi-cash-stack"></i>
    <span class="label">Salary Loan</span>
  </a>

</div>
    <!-- Logout -->
    <a class="side-link" href="logout.php" id="logoutLink">
      <i class="bi bi-box-arrow-right"></i><span class="label">Logout</span>
    </a>

  </div>

  <div class="sidebar-footer">
    <div class="footer-text">© TEK-C • HR Panel</div>
  </div>
</aside>

<div id="overlay" class="overlay" aria-hidden="true"></div>

<script>
// Auto-collapse other sections when one is expanded
document.addEventListener('DOMContentLoaded', function() {
  const collapseLinks = document.querySelectorAll('[data-bs-toggle="collapse"]');

  collapseLinks.forEach(link => {
    link.addEventListener('click', function() {
      const targetId = this.getAttribute('href');
      const targetCollapse = document.querySelector(targetId);
      if (!targetCollapse) return;

      // If clicking already open section, do nothing
      if (targetCollapse.classList.contains('show')) return;

      // Close any open top-level collapses
      document.querySelectorAll('#sidebar .nav-section > .collapse.show').forEach(openEl => {
        if (openEl !== targetCollapse) {
          const bs = bootstrap.Collapse.getInstance(openEl) || new bootstrap.Collapse(openEl, { toggle: false });
          bs.hide();
        }
      });
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
