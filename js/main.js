/* Sharek Website - Main JavaScript */
/* Kurdish Sorani (سۆرانی) - RTL Direction */

// Dark Mode Toggle
function initDarkMode() {
    const savedTheme = localStorage.getItem('sharek-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = savedTheme === 'dark' || (!savedTheme && prefersDark);

    if (isDark) {
        document.body.classList.add('dark-mode');
    }

    const themeToggle = document.querySelector('.theme-toggle');
    if (themeToggle) {
        // Sync icon with current actual state
        themeToggle.textContent = isDark ? '☀️' : '🌙';
        themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');

        themeToggle.addEventListener('click', () => {
            const nowDark = document.body.classList.toggle('dark-mode');
            themeToggle.textContent = nowDark ? '☀️' : '🌙';
            themeToggle.setAttribute('aria-pressed', nowDark ? 'true' : 'false');
            localStorage.setItem('sharek-theme', nowDark ? 'dark' : 'light');
        });
    }
}

// Mobile Hamburger Menu
function initMobileMenu() {
    const menuToggle = document.querySelector('.menu-toggle');
    const headerNav = document.querySelector('.header-nav');
    
    if (menuToggle && headerNav) {
        menuToggle.addEventListener('click', () => {
            headerNav.classList.toggle('active');
            menuToggle.textContent = headerNav.classList.contains('active') ? '✕' : '☰';
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!headerNav.contains(e.target) && !menuToggle.contains(e.target)) {
                headerNav.classList.remove('active');
                menuToggle.textContent = '☰';
            }
        });
        
        // Close menu when clicking on a link
        headerNav.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                headerNav.classList.remove('active');
                menuToggle.textContent = '☰';
            });
        });
    }
}

// Smooth Scroll
function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            // Guard against bare "#" placeholder links (audit finding #20).
            // document.querySelector('#') throws "SyntaxError: '#' is not a
            // valid selector", which was an uncaught exception on every
            // click of a placeholder link (social icons, disabled legal
            // links, etc.) since those are also matched by a[href^="#"].
            if (targetId.length <= 1) {
                return;
            }
            const target = document.querySelector(targetId);
            if (target) {
                const headerOffset = 80;
                const elementPosition = target.getBoundingClientRect().top;
                const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        });
    });
}

// Active Nav Highlight
function initActiveNav() {
    const sections = document.querySelectorAll('section[id]');
    const navLinks = document.querySelectorAll('.nav a');

    function highlightNav() {
        const scrollPosition = window.scrollY + 100;

        sections.forEach(section => {
            const sectionTop = section.offsetTop;
            const sectionHeight = section.offsetHeight;
            const sectionId = section.getAttribute('id');

            if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
                navLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === `#${sectionId}`) {
                        link.classList.add('active');
                    }
                });
            }
        });
    }

    window.addEventListener('scroll', highlightNav);
    highlightNav(); // Initial check
}

// Initialize all functions when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    initDarkMode();
    initMobileMenu();
    initSmoothScroll();
    initActiveNav();
    initPageTransitions();
});

// Page Transitions for Auth Links
function initPageTransitions() {
    document.querySelectorAll('a[href="register.php"], a[href="login.php"]').forEach(link => {
        link.addEventListener('click', () => {
            document.body.classList.add('page-exit');
        });
    });
}
