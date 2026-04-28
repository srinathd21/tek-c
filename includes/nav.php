<style>
       .btn-yellow {
            background: linear-gradient(135deg, var(--yellow), var(--yellow2));
            color: #111;
            font-weight: 700;
            border: 0;
            border-radius: 7px;
            padding: 8px 14px;
            box-shadow: 0 12px 28px rgba(255, 179, 0, .35);
            transition: .35s;
        }

        .btn-yellow:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 40px rgba(255, 179, 0, .45);
        }
</style>
<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg fixed-top">
    <div class="container">
        <a class="navbar-brand" href="#">
            <img src="assets/logo.png" class="logo-box">
                
            </img>
            <div class="logo-text">
                TEK-C
                <span>GLOBAL</span>
            </div>
        </a>

        <button class="navbar-toggler bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<ul class="navbar-nav mx-auto">
    <li class="nav-item">
        <a class="nav-link <?= ($currentPage == 'index.php') ? 'active' : ''; ?>" href="index.php">Home</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($currentPage == 'about.php') ? 'active' : ''; ?>" href="about.php">About Us</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($currentPage == 'modules.php') ? 'active' : ''; ?>" href="modules.php">Product Overview</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($currentPage == 'pricing.php') ? 'active' : ''; ?>" href="pricing.php">Pricing</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($currentPage == 'contact.php') ? 'active' : ''; ?>" href="contact.php">Contact Us</a>
    </li>
</ul>

            <div class="d-lg-flex align-items-center gap-3">
                
                <a href="demo.php" class="btn btn-yellow">Request Demo</a>
            </div>
        </div>
    </div>
</nav>