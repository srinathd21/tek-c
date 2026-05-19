// assets/js/sidebar-toggle.js

// ===== Sidebar toggles =====
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

  document.body.classList.toggle(
    "wide",
    sidebar.classList.contains("collapsed") && !isMobile()
  );
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

// Auto-collapse other sections when one is expanded
function setupSidebarAccordion() {
  const collapseLinks = document.querySelectorAll('#sidebar [data-bs-toggle="collapse"]');

  collapseLinks.forEach(link => {
    link.addEventListener("click", function () {
      const targetId = this.getAttribute("href");

      if (!targetId || !targetId.startsWith("#")) return;

      const targetCollapse = document.querySelector(targetId);

      if (!targetCollapse) return;

      // If clicking already expanded section, don't collapse others
      if (targetCollapse.classList.contains("show")) {
        return;
      }

      collapseLinks.forEach(otherLink => {
        if (otherLink === this) return;

        const otherTargetId = otherLink.getAttribute("href");

        if (!otherTargetId || !otherTargetId.startsWith("#")) return;

        const otherCollapse = document.querySelector(otherTargetId);

        if (!otherCollapse) return;

        if (otherCollapse.classList.contains("show")) {
          const bsCollapse =
            bootstrap.Collapse.getInstance(otherCollapse) ||
            new bootstrap.Collapse(otherCollapse, { toggle: false });

          bsCollapse.hide();
        }
      });
    });
  });
}

// Scroll sidebar automatically to active link
function scrollActiveSidebarLink() {
  if (!sidebar) return;

  const activeLink = sidebar.querySelector(".side-link.active");

  if (!activeLink) return;

  setTimeout(() => {
    activeLink.scrollIntoView({
      behavior: "smooth",
      block: "center",
      inline: "nearest"
    });
  }, 300);
}

// Set active page highlight and auto-expand current section
function setActivePage() {
  if (!sidebar) return;

  const currentPage = window.location.pathname.split("/").pop() || "index.php";
  const sideLinks = sidebar.querySelectorAll(".side-link");

  sideLinks.forEach(link => {
    link.classList.remove("active");

    const href = link.getAttribute("href");

    if (href === currentPage) {
      link.classList.add("active");

      const parentCollapse = link.closest(".collapse");

      if (parentCollapse) {
        const bsCollapse =
          bootstrap.Collapse.getInstance(parentCollapse) ||
          new bootstrap.Collapse(parentCollapse, { toggle: false });

        bsCollapse.show();
      }
    }

    // Special case if you have manage-credentials.php inside Admin menu
    if (currentPage === "manage-credentials.php" && href === "manage-credentials.php") {
      link.classList.add("active");

      const adminCollapse = document.getElementById("menuAdmin");

      if (adminCollapse) {
        const bsCollapse =
          bootstrap.Collapse.getInstance(adminCollapse) ||
          new bootstrap.Collapse(adminCollapse, { toggle: false });

        bsCollapse.show();
      }
    }
  });

  scrollActiveSidebarLink();
}

// Set submenu flyout position when sidebar is collapsed
function setupCollapsedSubmenuFlyout() {
  if (!sidebar) return;

  const collapseLinks = sidebar.querySelectorAll('[data-bs-toggle="collapse"]');

  collapseLinks.forEach(link => {
    link.addEventListener("click", function () {
      if (!sidebar.classList.contains("collapsed") || isMobile()) return;

      const targetId = this.getAttribute("href");

      if (!targetId || !targetId.startsWith("#")) return;

      const targetCollapse = document.querySelector(targetId);

      if (!targetCollapse) return;

      const linkRect = this.getBoundingClientRect();
      const sidebarRect = sidebar.getBoundingClientRect();

      const topValue = linkRect.top - sidebarRect.top;

      targetCollapse.style.setProperty("--flyout-top", `${topValue}px`);
    });
  });
}

// Initialize sidebar functionality
function initSidebar() {
  if (!sidebar) return;

  if (menuBtn) {
    menuBtn.addEventListener("click", handleToggle);
  }

  if (overlay) {
    overlay.addEventListener("click", closeMobileSidebar);
  }

  window.addEventListener("resize", () => {
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
  setupSidebarAccordion();
  setupCollapsedSubmenuFlyout();
  setActivePage();

  const yearElement = document.getElementById("year");

  if (yearElement) {
    yearElement.textContent = new Date().getFullYear();
  }
}

// Initialize when DOM is loaded
document.addEventListener("DOMContentLoaded", initSidebar);