// assets/js/sidebar-toggle.js

// ===== Sidebar toggles =====
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

// Auto-collapse other sections when one is expanded
function setupSidebarAccordion() {
  const collapseLinks = document.querySelectorAll('[data-bs-toggle="collapse"]');
  
  collapseLinks.forEach(link => {
    link.addEventListener('click', function(e) {
      const targetId = this.getAttribute('href');
      const targetCollapse = document.querySelector(targetId);
      
      // If clicking on an already expanded section, don't collapse others
      if (targetCollapse.classList.contains('show')) {
        return;
      }
      
      // Collapse all other open sections
      collapseLinks.forEach(otherLink => {
        if (otherLink !== this) {
          const otherTargetId = otherLink.getAttribute('href');
          const otherCollapse = document.querySelector(otherTargetId);
          
          if (otherCollapse.classList.contains('show')) {
            // Use Bootstrap's collapse method
            const bsCollapse = bootstrap.Collapse.getInstance(otherCollapse) || new bootstrap.Collapse(otherCollapse, {toggle: false});
            bsCollapse.hide();
          }
        }
      });
    });
  });
}

// Set active page highlight and auto-expand current section
function setActivePage() {
  const currentPage = window.location.pathname.split('/').pop() || 'index.php';
  const sideLinks = document.querySelectorAll('.side-link');
  
  sideLinks.forEach(link => {
    // Remove active class from all links first
    link.classList.remove('active');
    
    // Check if this link points to current page
    const href = link.getAttribute('href');
    if (href === currentPage) {
      link.classList.add('active');
      
      // If this link is inside a collapse, expand its parent
      const parentCollapse = link.closest('.collapse');
      if (parentCollapse) {
        // Use Bootstrap's collapse method to show
        const bsCollapse = bootstrap.Collapse.getInstance(parentCollapse) || new bootstrap.Collapse(parentCollapse, {toggle: false});
        bsCollapse.show();
      }
    }
    
    // Handle manage-credentials.php specifically
    if (currentPage === 'manage-credentials.php' && href === 'manage-credentials.php') {
      link.classList.add('active');
      // Expand Admin section
      const adminCollapse = document.getElementById('menuAdmin');
      if (adminCollapse) {
        const bsCollapse = bootstrap.Collapse.getInstance(adminCollapse) || new bootstrap.Collapse(adminCollapse, {toggle: false});
        bsCollapse.show();
      }
    }
  });
}

// Initialize sidebar functionality
function initSidebar() {
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
  setupSidebarAccordion();
  setActivePage();
  
  // Set current year in footer
  const yearElement = document.getElementById("year");
  if (yearElement) {
    yearElement.textContent = new Date().getFullYear();
  }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', initSidebar);