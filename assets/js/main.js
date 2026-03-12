document.addEventListener("DOMContentLoaded", (event) => {
    // preloader
    const preloader = document.getElementById('preloader');
    preloader.style.display = 'none';
    document.body.style.position = 'static';

    // HEADER NAV IN MOBILE
    if (document.querySelector(".ul-header-nav")) {
        const ulSidebar = document.querySelector(".ul-sidebar");
        const ulSidebarOpener = document.querySelector(".ul-header-sidebar-opener");
        const ulSidebarCloser = document.querySelector(".ul-sidebar-closer");
        const ulMobileMenuContent = document.querySelector(".to-go-to-sidebar-in-mobile");
        const ulHeaderNavMobileWrapper = document.querySelector(".ul-sidebar-header-nav-wrapper");
        const ulHeaderNavOgWrapper = document.querySelector(".ul-header-nav-wrapper");

        function updateMenuPosition() {
            if (window.innerWidth < 992) {
                ulHeaderNavMobileWrapper.appendChild(ulMobileMenuContent);
            }

            if (window.innerWidth >= 992) {
                ulHeaderNavOgWrapper.appendChild(ulMobileMenuContent);
            }
        }

        updateMenuPosition();

        window.addEventListener("resize", () => {
            updateMenuPosition();
        });

        ulSidebarOpener.addEventListener("click", () => {
            ulSidebar.classList.add("active");
        });

        ulSidebarCloser.addEventListener("click", () => {
            ulSidebar.classList.remove("active");
        });


        // menu dropdown/submenu in mobile
        const ulHeaderNavMobile = document.querySelector(".ul-header-nav");
        const ulHeaderNavMobileItems = ulHeaderNavMobile.querySelectorAll(".has-sub-menu");
        ulHeaderNavMobileItems.forEach((item) => {
            if (window.innerWidth < 992) {
                item.addEventListener("click", () => {
                    item.classList.toggle("active");
                });
            }
        });
    }

    // header search in mobile start
    const ulHeaderSearchOpener = document.querySelector(".ul-header-search-opener");
    const ulHeaderSearchCloser = document.querySelector(".ul-search-closer");
    if (ulHeaderSearchOpener) {
        ulHeaderSearchOpener.addEventListener("click", () => {
            document.querySelector(".ul-search-form-wrapper").classList.add("active");
        });
    }

    if (ulHeaderSearchCloser) {
        ulHeaderSearchCloser.addEventListener("click", () => {
            document.querySelector(".ul-search-form-wrapper").classList.remove("active");
        });
    }
    // header search in mobile end


    // sticky header
    const ulHeader = document.querySelector(".ul-header");
    if (ulHeader) {
        window.addEventListener("scroll", () => {
            if (window.scrollY > 80) {
                ulHeader.classList.add("sticky");
            } else {
                ulHeader.classList.remove("sticky");
            }
        });
    }


    // about page partners slider
    new Swiper(".ul-partners-slider", {
        slidesPerView: 2,
        loop: true,
        autoplay: true,
        spaceBetween: 30,
        breakpoints: {
            480: {
                slidesPerView: 3,
            },
            768: {
                slidesPerView: 4,
            },
            992: {
                slidesPerView: 5,
            },
            1200: {
                slidesPerView: 6,
                spaceBetween: 80,
            }
        }
    });

    // team slider
    new Swiper(".ul-team-slider", {
        slidesPerView: 4,
        loop: true,
        autoplay: true,
        spaceBetween: 30,
        navigation: {
            nextEl: ".ul-team-slider-nav .next",
            prevEl: ".ul-team-slider-nav .prev",
        },
        breakpoints: {
            0: {
                slidesPerView: 1,
            },
            480: {
                slidesPerView: 2,
                spaceBetween: 20,
            },
            768: {
                slidesPerView: 3,
                spaceBetween: 25,
            },
            992: {
                slidesPerView: 4,
                spaceBetween: 25,
            },
            1600: {
                slidesPerView: 4,
                spaceBetween: 26,
            },
            1700: {
                slidesPerView: 4,
                spaceBetween: 30,
            }
        }
    });

    // testimonial slider
    new Swiper(".ul-inner-testimonial-slider", {
        slidesPerView: 1,
        loop: true,
        autoplay: true,
        spaceBetween: 15,
        pagination: {
            el: ".ul-inner-testimonial-slider-pagination",
            type: "progressbar",
        },
        navigation: {
            prevEl: ".ul-inner-testimonial-slider-prev",
            nextEl: ".ul-inner-testimonial-slider-next",
        },
        breakpoints: {
            576: {
                slidesPerView: 2,
            },
            992: {
                spaceBetween: 20,
                slidesPerView: 3,
            },
            1200: {
                spaceBetween: 20,
                slidesPerView: 3,
            },
            1680: {
                slidesPerView: 3,
                spaceBetween: 26,
            },
            1700: {
                slidesPerView: 3,
                spaceBetween: 30,
            }
        }
    });

    // wow js - animation on scroll
    new WOW({}).init();

    new Swiper(".ul-features-slider", {
        slidesPerView: 1,
        loop: true,
        autoplay: true,
        spaceBetween: 15,
        pagination: {
            el: ".ul-features-slider-pagination",
            type: "progressbar",
        },
        navigation: {
            prevEl: ".ul-features-slider-prev",
            nextEl: ".ul-features-slider-next",
        },
        breakpoints: {
            480: {
                slidesPerView: 2,
            },
            576: {
                spaceBetween: 15,
                slidesPerView: 3,
            },
            1200: {
                spaceBetween: 20,
                slidesPerView: 3,
            },
            1680: {
                slidesPerView: 3,
                spaceBetween: 26,
            },
            1700: {
                slidesPerView: 3,
                spaceBetween: 30,
            }
        }
    });

    // projects filters
    const projectFilterSelect = document.querySelectorAll(".ul-projects-search-filters select");
    if (projectFilterSelect) {
        projectFilterSelect.forEach(selectInput => {
            new SlimSelect({
                select: selectInput,
                settings: {
                    showSearch: false,
                    contentLocation: document.querySelector('.ul-projects-search-filters')
                }
            })
        });
    }

    // project search filters
    const projectSearchFiltersExpandBtn = document.querySelector(".ul-projects-search-filters-expand-btn");
    if (projectSearchFiltersExpandBtn) {
        projectSearchFiltersExpandBtn.addEventListener("click", function () {
            document.querySelector(".ul-projects-search-filters").classList.toggle("expanded");
        })
    }


    // project details img slider
    var ulProjectDetailsImgSlider = new Swiper(".ul-project-details-img-slider-thumb", {
        spaceBetween: 24,
        slidesPerView: 4,
        freeMode: true,
        watchSlidesProgress: true,
        autoplay: true,
        loop: true,
        navigation: {
            nextEl: ".ul-project-details-img-slider-thumb-nav .next",
            prevEl: ".ul-project-details-img-slider-thumb-nav .prev",
        },
        breakpoints: {
            0: {
                spaceBetween: 15,
                slidesPerView: 2,
            },
            480: {
                slidesPerView: 3,
                spaceBetween: 15,
            },
            768: {
                slidesPerView: 4,
                spaceBetween: 15,
            },
            992: {
                spaceBetween: 16,
            },
            1200: {
                spaceBetween: 18,
            },
            1600: {
                spaceBetween: 24,
            }
        }
    });

    new Swiper(".ul-project-details-img-slider", {
        autoplay: true,
        loop: true,
        thumbs: {
            swiper: ulProjectDetailsImgSlider,
        },
    });


    const reviewHighlightSelect = document.querySelector(".review-highlight-point");
    if (reviewHighlightSelect) {
        new SlimSelect({
            select: reviewHighlightSelect.querySelector("select"),
            settings: {
                showSearch: false,
                contentLocation: reviewHighlightSelect,
            },
            cssClasses: {
                option: "ul-option",
            }
        })
    }



    // banner slider
    const bannerAddressSlider = new Swiper(".ul-banner-address-slider", {
        slidesPerView: 1,
        loop: true,
        autoplay: true,
    });

    new Swiper(".ul-banner-slider", {
        slidesPerView: 1,
        loop: true,
        autoplay: true,
        spaceBetween: 0,
        navigation: {
            nextEl: ".ul-banner-slider-nav .next",
            prevEl: ".ul-banner-slider-nav .prev",
        },
        pagination: {
            el: ".ul-banner-slider-pagination",
            clickable: true,
            renderBullet: function (index, className) {
                const slideNumber = String(index + 1).padStart(2, '0'); // Format to '01', '02', etc.
                return `<span class="${className}">${slideNumber}</span>`;
            },
        },
        thumbs: {
            swiper: bannerAddressSlider,
        },
    });

    // homepage search filters
    const propertyFilterSelect = document.querySelectorAll(".ul-property-filter-search-form .form-group");
    if (propertyFilterSelect) {
        propertyFilterSelect.forEach(formGroup => {
            new SlimSelect({
                select: formGroup.querySelector("select"),
                settings: {
                    showSearch: false,
                    // contentLocation: document.querySelector('.ul-property-filter-search-form .form-group'),
                },
                cssClasses: {
                    option: "ul-option",
                }
            })
        });
    }

    // featured properties title slider
    if (document.querySelector(".ul-featured-properties-title-slider")) {
        new Splide('.ul-featured-properties-title-slider', {
            arrows: false,
            pagination: false,
            type: 'loop',
            drag: 'free',
            focus: 'center',
            perPage: 9,
            autoWidth: true,
            gap: 15,
            autoScroll: {
                speed: 1.5,
            },
        }).mount(window.splide.Extensions);
    };


    // featured properties slider
    new Swiper(".ul-featured-properties-slider", {
        slidesPerView: 4,
        loop: true,
        autoplay: true,
        navigation: {
            nextEl: ".ul-featured-properties-slider-nav .next",
            prevEl: ".ul-featured-properties-slider-nav .prev",
        },
        breakpoints: {
            0: {
                slidesPerView: 1,
            },
            480: {
                slidesPerView: 2,
            },
            992: {
                slidesPerView: 3,
            },
            1400: {
                slidesPerView: 4,
            }
        }
    });

    // facilities slider
    new Swiper(".ul-facilities-img-slider", {
        slidesPerView: "auto",
        spaceBetween: 20,
        loop: true,
        autoplay: true,
        navigation: {
            nextEl: ".ul-facilities-img-slider-nav .next",
            prevEl: ".ul-facilities-img-slider-nav .prev",
        }
    });

    // testimonial slider
    new Swiper(".ul-testimonial-slider", {
        slidesPerView: 1.3,
        spaceBetween: 30,
        loop: true,
        autoplay: true,
        pagination: {
            el: ".ul-testimonial-slider-pagination",
            clickable: true,
        },
        breakpoints: {
            0: {
                slidesPerView: 1,
            },
            768: {
                slidesPerView: 2,
                spaceBetween: 20,
            },
            992: {
                slidesPerView: 1.3,
                spaceBetween: 27,
            },
            1800: {
                spaceBetween: 30
            }
        }
    });

    // sidebar slider
    new Swiper(".ul-sidebar-slider", {
        slidesPerView: 1.8,
        spaceBetween: 30,
        loop: true,
        autoplay: true,
        navigation: {
            nextEl: ".ul-sidebar-slider-nav .next",
            prevEl: ".ul-sidebar-slider-nav .prev",
        },
        breakpoints: {
            0: {
                slidesPerView: 1,
            },
            1400: {
                slidesPerView: 1.8,
                spaceBetween: 30,
            }
        }
    });


    // INDEX-2 JS
    // banner slider
    const bannerAddressSlider2 = new Swiper(".ul-2-banner-slider", {
        slidesPerView: 4,
        spaceBetween: 24,
        loop: true,
        autoplay: true,
        breakpoints: {
            0: {
                slidesPerView: 1,
            },
            480: {
                slidesPerView: 2,
            },
            768: {
                slidesPerView: 3,
            },
            992: {
                slidesPerView: 4,
            }
        }
    });

    // testimonial slider
    new Splide('#ul-2-testimonial-slider', {
        type: 'loop',
        perPage: 3,
        arrows: false,
        pagination: false,
        gap: 'clamp(16px,1.26vw,24px)',
        breakpoints: {
            768: {
                perPage: 2,
            },
            480: {
                perPage: 1,
            }
        }
    }).mount(window.splide.Extensions);

    // testimonial slider right to left
    new Splide('#ul-2-testimonial-slider-2', {
        type: 'loop',
        perPage: 3,
        arrows: false,
        pagination: false,
        gap: 'clamp(16px,1.26vw,24px)',
        direction: 'rtl',
        breakpoints: {
            768: {
                perPage: 2,
            },
            480: {
                perPage: 1,
            }
        }
    }).mount(window.splide.Extensions);
});