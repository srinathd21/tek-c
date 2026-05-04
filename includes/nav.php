<?php
$currentPage = basename($_SERVER['PHP_SELF']);

function activeMenu($page, $currentPage)
{
    return ($currentPage === $page) ? 'active' : '';
}
?>
<style>
    .logo {
        width: 40px;
    }

    .logo-text>h3 {
        color: white !important;
    }

    footer a {
        text-decoration: none;
    }

    .logo-text {
        font-size: 15px !important;
        margin-top: 5px;
    }

    .logo-text-span>span:nth-child(1),
    .logo-text-span>span:nth-child(2) {
        font-size: 20px;
        font-weight: 700;
    }

    .logo-text-span>span:nth-child(2) {
        color: #fbb938;
    }

    .logo-text>span {
        font-size: 15px !important;
        letter-spacing: 1px;
    }

    #login-btn-mobile {
        display: none;
    }

    #footer-container>div {
        position: relative;
    }

    #footer-container>div::before {
        content: "";
        position: absolute;
        left: -50px;
        top: 0;
        height: 100%;
        border-left: 1px solid #343434;
    }

    /* Remove border for first item */
    #footer-container>div:first-child::before {
        border-left: none;
    }

    @media(max-width:991px) {
        

        section {
            overflow-x: hidden;
        }
        #footer-container>div::before {
            border-left: none;
        }
        #login-btn {
            margin: 10px 0px;
            position: relative;
            left: 50%;
            transform: translateX(-50%);
        }

        #toggle-btn {
            background-color: #101010 !important;
            border: 1px solid #bdbdbd;
            color: #fff !important;
            padding: 8px 15px;
        }

        #toggle-btn>span {
            color: white !important;
            background-color: white;
            font-size: 10px;
        }

        #login-btn-mobile {
            display: block;
        }
    }
</style>
<nav class="navbar navbar-expand-lg" id="mainNavbar">
    <div class="container">
        <a class="navbar-brand logo me-auto" href="index.php">
            <img src="assets/tek-c.png" class="logo"></img>
            <div class="logo-text text-center">
                <div class="logo-text-span d-flex">
                    <span>TEK</span>
                    <span>-C</span>
                </div>
                <span>GLOBAL</span>
            </div>
        </a>
        <a href="erp/" id="login-btn-mobile"
            class="btn btn-yellow mx-3 py-2 px-4 <?php echo activeMenu('book-demo.php', $currentPage); ?>">
            Login
        </a>
        <button id="toggle-btn" class="navbar-toggler bg-light" type="button" data-bs-toggle="collapse"
            data-bs-target="#navMenu">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav mx-auto">
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('index.php', $currentPage); ?>" href="index.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('features.php', $currentPage); ?>"
                        href="features.php">Features</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('modules.php', $currentPage); ?>"
                        href="modules.php">Modules</a>
                </li>
                <!-- <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('pricing.php', $currentPage); ?>"
                        href="pricing.php">Pricing</a>
                </li> -->
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('about.php', $currentPage); ?>" href="about.php">About</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo activeMenu('contact.php', $currentPage); ?>"
                        href="contact.php">Contact</a>
                </li>
            </ul>

            <a href="erp/" id="login-btn"
                class="btn btn-yellow <?php echo activeMenu('book-demo.php', $currentPage); ?>">
                Login
            </a>
        </div>

    </div>
</nav>