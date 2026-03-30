<!-- Sidebar (Manager + Team Lead Menu) -->
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
      <i class="bi bi-geo-alt"></i><span class="label">My Projects</span>
    </a>

    <!-- Attendance -->
    <a class="side-link" href="punchin.php">
      <i class="bi bi-calendar-check"></i><span class="label">Attendance</span>
    </a>

    <!-- Today Task -->
    <a class="side-link" href="today-tasks.php">
      <i class="bi bi-check2-square"></i><span class="label">Today Task</span>
    </a>

    <!-- Time Management -->
    <a class="side-link" data-bs-toggle="collapse" href="#tmMenu">
      <i class="bi bi-clock-history"></i><span class="label">Time Management</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="tmMenu">
      <a class="side-link" href="dpr.php"><i class="bi bi-journal-text"></i><span class="label">DPR</span></a>
      <a class="side-link" href="dar.php"><i class="bi bi-clipboard-check"></i><span class="label">DAR</span></a>
      <a class="side-link" href="ma.php"><i class="bi bi-calendar2-week"></i><span class="label">MA</span></a>
      <a class="side-link" href="mpt.php"><i class="bi bi-list-task"></i><span class="label">MPT</span></a>
      <a class="side-link" href="mom.php"><i class="bi bi-chat-left-text"></i><span class="label">MOM</span></a>
    </div>

    <!-- Checklist -->
    <a class="side-link" href="checklist.php">
      <i class="bi bi-card-checklist"></i><span class="label">Checklist</span>
    </a>

    <!-- ----------------- Team Lead Options ----------------- -->

    <!-- QUOTATION MANAGEMENT - NEW SECTION FOR TEAM LEADS -->
    <a class="side-link" data-bs-toggle="collapse" href="#quotationMenu">
      <i class="bi bi-file-text"></i><span class="label">Quotations</span>
      <span class="ms-auto label position-relative">
        <i class="bi bi-chevron-down"></i>
        <!-- Notification badge for pending assignments -->
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem; display: none;" id="pendingQuotationBadge">0</span>
      </span>
    </a>
    <div class="collapse ps-2" id="quotationMenu">
      <!-- Assigned Quotations -->
      <a class="side-link" href="assigned-quotations.php">
        <i class="bi bi-inbox"></i><span class="label">Quotation Request</span>
        <span class="ms-auto badge bg-warning" id="assignedCount" style="font-size: 0.7rem;">0</span>
      </a>
      
      <!-- Quotations with QS -->
      <a class="side-link" href="quotations-with-qs.php">
        <i class="bi bi-arrow-right-square"></i><span class="label">With QS</span>
      </a>
      
      <!-- Quotations Finalized -->
      <a class="side-link" href="finalized-quotations.php">
        <i class="bi bi-check-circle"></i><span class="label">Finalized</span>
      </a>
      
      <!-- Quotations History -->
      <a class="side-link" href="my-quotations-history.php">
        <i class="bi bi-clock-history"></i><span class="label">History</span>
      </a>
      
      <!-- Dealers Directory -->
      <a class="side-link" href="dealers.php">
        <i class="bi bi-shop"></i><span class="label">Dealers</span>
      </a>
    </div>

    <!-- Task Approval -->
    <a class="side-link" href="task-approval.php">
      <i class="bi bi-check2-square"></i><span class="label">Task Approval</span>
    </a>

    <!-- Mail -->
    <a class="side-link" data-bs-toggle="collapse" href="#mailMenu">
      <i class="bi bi-envelope"></i><span class="label">Mail</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="mailMenu">
      <a class="side-link" href="inbox.php"><i class="bi bi-inbox"></i><span class="label">Inbox</span></a>
      <a class="side-link" href="compose.php"><i class="bi bi-pencil-square"></i><span class="label">Compose</span></a>
      <a class="side-link" href="sent.php"><i class="bi bi-send"></i><span class="label">Sent</span></a>
      <a class="side-link" href="drafts.php"><i class="bi bi-file-earmark-text"></i><span class="label">Drafts</span></a>
      <a class="side-link" href="scheduled.php"><i class="bi bi-calendar-event"></i><span class="label">Scheduled</span></a>
      <a class="side-link" href="spam.php"><i class="bi bi-exclamation-octagon"></i><span class="label">Spam</span></a>
      <a class="side-link" href="trash.php"><i class="bi bi-trash"></i><span class="label">Trash</span></a>
    </div>

    <!-- Reports Hub -->
    <a class="side-link" href="reports-hub.php">
      <i class="bi bi-bar-chart-line"></i><span class="label">Reports Hub</span>
    </a>

    <!-- HR -->
    <a class="side-link" data-bs-toggle="collapse" href="#hrMenu">
      <i class="bi bi-people"></i><span class="label">HR</span>
      <span class="ms-auto label"><i class="bi bi-chevron-down"></i></span>
    </a>
    <div class="collapse ps-2" id="hrMenu">
      <a class="side-link" href="my-profile.php"><i class="bi bi-person-circle"></i><span class="label">Profile</span></a>
      <a class="side-link" href="my-attendance.php"><i class="bi bi-person-badge"></i><span class="label">Attendance Profile</span></a>
      <a class="side-link" href="leave-ledger.php"><i class="bi bi-journal-bookmark"></i><span class="label">Leave Ledger</span></a>
      <a class="side-link" href="payslips.php"><i class="bi bi-receipt"></i><span class="label">Payslips</span></a>
      <a class="side-link" href="hr-policy.php"><i class="bi bi-file-earmark-medical"></i><span class="label">HR Policy</span></a>
      <a class="side-link" href="salary-loan.php"><i class="bi bi-cash-stack"></i><span class="label">Salary Loan</span></a>
      <a class="side-link" href="attendance-regularization.php"><i class="bi bi-calendar2-check"></i><span class="label">Attendance Regularization</span></a>
      <a class="side-link" href="apply-leave.php"><i class="bi bi-calendar-plus"></i><span class="label">Apply Leave</span></a>
      <a class="side-link" href="my-leave-history.php"><i class="bi bi-clock-history"></i><span class="label">My Leave History</span></a>
    </div>

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
  .side-toggle, [data-bs-toggle="collapse"] {
    width:100%;
    background:transparent;
    border:none;
    text-align:left;
    display:flex;
    align-items:center;
    gap:.6rem;
    cursor:pointer;
  }
  .side-toggle .chevron, [data-bs-toggle="collapse"] .bi-chevron-down{
    margin-left:auto;
    transition: transform .2s ease;
  }
  .side-toggle[aria-expanded="true"] .chevron,
  [data-bs-toggle="collapse"][aria-expanded="true"] .bi-chevron-down {
    transform: rotate(180deg);
  }

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

  /* Badge styling */
  .badge {
    font-weight: 700;
    padding: 3px 6px;
  }
  
  /* Position relative for notification badge */
  .position-relative {
    position: relative;
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const currentPage = window.location.pathname.split('/').pop() || 'index.php';

  // Highlight top-level links
  document.querySelectorAll('.side-link:not([data-bs-toggle="collapse"])').forEach(link=>link.classList.remove('active'));
  document.querySelectorAll('.side-link:not([data-bs-toggle="collapse"])').forEach(link=>{
    if(link.getAttribute('href') === currentPage) link.classList.add('active');
  });

  // Highlight sublinks and expand parent
  document.querySelectorAll('.side-link[href]').forEach(link=>{
    if(link.getAttribute('href') === currentPage){
      link.classList.add('active');
      // expand parent collapse if exists
      let parent = link.closest('.collapse');
      if(parent) {
        new bootstrap.Collapse(parent, {toggle: true});
        // Also expand any nested collapses
        let nestedParent = parent.closest('.collapse');
        if(nestedParent) new bootstrap.Collapse(nestedParent, {toggle: true});
      }
    }
  });

  // Confirm logout
  const logoutLink = document.getElementById('logoutLink');
  if(logoutLink){
    logoutLink.addEventListener('click', function(e){
      if(!confirm('Are you sure you want to logout?')) e.preventDefault();
    });
  }

  // Load quotation counts for Team Lead
  loadQuotationCounts();
});

// Function to load quotation counts via AJAX
function loadQuotationCounts() {
  if (typeof fetch === 'undefined') return;
  
  fetch('get-quotation-counts.php')
    .then(response => response.json())
    .then(data => {
      // Update assigned count
      const assignedEl = document.getElementById('assignedCount');
      if (assignedEl && data.assigned > 0) {
        assignedEl.textContent = data.assigned;
        assignedEl.style.display = 'inline';
      } else if (assignedEl) {
        assignedEl.style.display = 'none';
      }
      
      // Update in progress count
      const inProgressEl = document.getElementById('inProgressCount');
      if (inProgressEl && data.in_progress > 0) {
        inProgressEl.textContent = data.in_progress;
        inProgressEl.style.display = 'inline';
      } else if (inProgressEl) {
        inProgressEl.style.display = 'none';
      }
      
      // Update ready to submit count
      const toSubmitEl = document.getElementById('toSubmitCount');
      if (toSubmitEl && data.to_submit > 0) {
        toSubmitEl.textContent = data.to_submit;
        toSubmitEl.style.display = 'inline';
      } else if (toSubmitEl) {
        toSubmitEl.style.display = 'none';
      }
      
      // Update total pending badge on main menu if any pending
      const totalPending = data.assigned + data.in_progress + data.to_submit;
      const pendingBadge = document.getElementById('pendingQuotationBadge');
      if (pendingBadge && totalPending > 0) {
        pendingBadge.textContent = totalPending > 9 ? '9+' : totalPending;
        pendingBadge.style.display = 'inline';
      } else if (pendingBadge) {
        pendingBadge.style.display = 'none';
      }
    })
    .catch(error => console.error('Error loading quotation counts:', error));
}
</script>