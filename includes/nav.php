<?php
$currentPage = basename($_SERVER['PHP_SELF']);

function activeMenu($page, $currentPage) {
    return ($currentPage === $page) ? 'active' : '';
}
?>
<style>
    .logo{
        width: 40px;
    }
    
    footer a{
        text-decoration: none;
    }
    .logo-text{
        font-size: 15px !important;
    }
    @media(max-width:991px){
    section{
        overflow-x: hidden;
    }
    #book-now-btn{
        margin: 10px 0px;
        position: relative;
        left: 50%;
        transform: translateX(-50%);
    }
   
    #toggle-btn{
        background-color: #101010 !important;
        border: 1px solid #bdbdbd;
        color: #fff !important;
        padding: 8px 15px;
    }
    #toggle-btn>span{
        color: white !important;
        background-color: white;
        font-size: 10px;
    }
}

</style>
<nav class="navbar navbar-expand-lg" id="mainNavbar">
    <div class="container">
        <a class="navbar-brand logo" href="index.php">
            <img src="assets/logo.png" class="logo"></img>
            <div class="logo-text">
                <h3>TEK-C</h3>
                <span>Construction Software</span>
            </div>
        </a>

        <button id="toggle-btn" class="navbar-toggler bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav mx-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('index.php', $currentPage); ?>" href="index.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('features.php', $currentPage); ?>" href="features.php">Features</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('modules.php', $currentPage); ?>" href="modules.php">Modules</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('pricing.php', $currentPage); ?>" href="pricing.php">Pricing</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('about.php', $currentPage); ?>" href="about.php">About</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('contact.php', $currentPage); ?>" href="contact.php">Contact</a>
                </li>
            </ul>

            <a href="book-demo.php" id="book-now-btn" class="btn btn-yellow <?php echo activeMenu('book-demo.php', $currentPage); ?>">
                Book Live Demo
            </a>
        </div>
    </div>
</nav>