<style>
  @media (max-width: 768px) {
  .content-scroll {
    padding: 12px 10px 12px !important;   /* was 22px */
  }

  .container-fluid.maxw {
    padding-left: 6px !important;
    padding-right: 6px !important;
  }

  .panel {
    padding: 12px !important;
    margin-bottom: 12px;
    border-radius: 14px;
  }

  .sec-head {
    padding: 10px !important;
    border-radius: 12px;
  }
}
  @media (max-width: 991.98px){
  .main{
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
  }
  .sidebar{
    position: fixed !important;
    transform: translateX(-100%);
    z-index: 1040 !important;
  }
  .sidebar.open, .sidebar.active, .sidebar.show{
    transform: translateX(0) !important;
  }
}
</style>
<footer class="main-footer">
  <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div>© <span id="year"></span> TEK-C. All rights reserved.</div>
    <div class="d-flex gap-3">
      <a href="#" class="text-decoration-none" style="color:#6b7280;">Privacy</a>
      <a href="#" class="text-decoration-none" style="color:#6b7280;">Terms</a>
      <a href="#" class="text-decoration-none" style="color:#6b7280;">Support</a>
    </div>
  </div>
</footer>
