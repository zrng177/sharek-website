<?php
// Security fix: was a third independent copy of the session-cookie config
// (api.php and SecurityManager.php were the other two) — now shares the
// same helper used everywhere else, so cookie flags and session timeout
// behavior can't drift out of sync again.
require_once __DIR__ . '/src/Security/SecurityManager.php';
\Sharek\Security\SecurityManager::initSecureSession();
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="پەیوەندی لەگەڵ شەریک">
    <title>پەیوەندی | شەریک 🚗</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/shared-design.css">
    <style>
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #0a1433; }
        body.dark-mode .form-group label { color: #f0f6ff; }
        .form-group input, .form-group textarea {
            width: 100%; padding: 0.75rem 1rem; border: 1.5px solid #dde4f5;
            border-radius: 10px; font-family: inherit; font-size: 1rem;
            transition: border-color 0.2s; background: #fff; color: #0a1433;
        }
        body.dark-mode .form-group input, body.dark-mode .form-group textarea {
            background: #111827; border-color: #1e2d4a; color: #f0f6ff;
        }
        .form-group input:focus, .form-group textarea:focus {
            outline: none; border-color: #1e3a8a; box-shadow: 0 0 0 3px rgba(30,58,138,0.1);
        }
        .form-group textarea { min-height: 150px; resize: vertical; }
    </style>
</head>
<body>
<script>
    (function() { if (localStorage.getItem('sharek-theme') === 'dark') document.body.classList.add('dark-mode'); })();
</script>

<!-- ============ SIDEBAR OVERLAY ============ -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ============ SIDEBAR ============ -->
<aside class="sharek-sidebar" id="mainSidebar" role="navigation" aria-label="Navigation Sidebar">
    <div class="sidebar-header">
        <a href="index.html" class="sidebar-brand">شەریک<span>.</span></a>
        <button class="sidebar-close" onclick="closeSidebar()" aria-label="داخستنی سایدبار">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <nav class="sidebar-nav">
        <div class="sidebar-section-label">مەنیو</div>
        <a href="index.html" class="sidebar-nav-link" onclick="closeSidebar()">
            <i class="bi bi-house-fill"></i> سەرەتا
        </a>
        <a href="how-it-works.html" class="sidebar-nav-link" onclick="closeSidebar()">
            <i class="bi bi-question-circle-fill"></i> چۆن کار دەکات
        </a>
        <a href="about.html" class="sidebar-nav-link" onclick="closeSidebar()">
            <i class="bi bi-info-circle-fill"></i> دەربارەمان
        </a>
        <a href="index.html#features" class="sidebar-nav-link" onclick="closeSidebar()">
            <i class="bi bi-stars"></i> تایبەتمەندییەکان
        </a>
        <a href="offers.html" class="sidebar-nav-link" onclick="closeSidebar()">
            <i class="bi bi-gift-fill"></i> پێشنیارەکان
        </a>
        <a href="contact.php" class="sidebar-nav-link active" onclick="closeSidebar()">
            <i class="bi bi-envelope-fill"></i> پەیوەندی
        </a>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section-label">گەشت</div>
        <a href="dashboard.php" class="sidebar-nav-link" onclick="closeSidebar()">
            <i class="bi bi-search"></i> دۆزینەوەی گەشت
        </a>
    </nav>
    <div class="sidebar-auth">
        <a href="register.php" class="sidebar-btn-register">
            <i class="bi bi-person-plus-fill me-2"></i> تۆمارکردن — خۆرایە ✓
        </a>
        <a href="login.php" class="sidebar-btn-login">
            <i class="bi bi-box-arrow-in-right me-2"></i> چوونەژوورەوە
        </a>
    </div>
</aside>

<!-- ============ NAVBAR ============ -->
<nav class="navbar navbar-expand-lg fixed-top sharek-navbar" id="main-nav" role="navigation" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand" href="index.html">شەریک<span>.</span></a>

        <div class="d-flex align-items-center gap-2 d-lg-none">
            <button class="btn-theme-toggle" id="theme-toggle-mobile" aria-label="تەبەدڵکردنی مۆد">
                <i class="bi bi-moon-stars"></i>
            </button>
            <button class="btn-sidebar-open" onclick="openSidebar()" aria-label="کردنەوەی مەنیو" aria-expanded="false" aria-controls="mainSidebar">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="collapse navbar-collapse" id="navbarContact">
            <ul class="navbar-nav mx-auto gap-1">
                <li class="nav-item"><a class="nav-link" href="index.html">سەرەتا</a></li>
                <li class="nav-item"><a class="nav-link" href="how-it-works.html">چۆن کار دەکات</a></li>
                <li class="nav-item"><a class="nav-link" href="about.html">دەربارەمان</a></li>
                <li class="nav-item"><a class="nav-link" href="index.html#features">تایبەتمەندییەکان</a></li>
                <li class="nav-item"><a class="nav-link" href="offers.html">پێشنیارەکان</a></li>
                <li class="nav-item"><a class="nav-link active" href="contact.php">پەیوەندی</a></li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <button class="btn-theme-toggle" id="theme-toggle" aria-label="تەبەدڵکردنی مۆد">
                    <i class="bi bi-moon-stars"></i>
                </button>
                <button class="btn-sidebar-open d-none d-lg-inline-flex" onclick="openSidebar()" aria-label="کردنەوەی سایدبار">
                    <i class="bi bi-layout-sidebar-inset-reverse"></i>
                </button>
                <a href="register.php" class="btn-nav-outline">تۆمارکردن</a>
                <a href="login.php" class="btn-nav-primary">چوونەژوورەوە</a>
            </div>
        </div>
    </div>
</nav>

<!-- ============ HERO ============ -->
<section class="hero-page">
    <div class="container">
        <nav class="hero-breadcrumb"><a href="index.html">سەرەتا</a><span>/</span><span>پەیوەندی</span></nav>
        <h1>چۆن یارمەتیت بدەین؟ 💬</h1>
        <p class="hero-subtitle">پەیوەندی لەگەڵ ئێمە بکە — هەموو کات ئامادەین</p>
    </div>
</section>

<!-- ============ CONTACT SECTION ============ -->
<section class="section-padding">
    <div class="container">
        <div class="section-title reveal">
            <div class="section-label">پەیوەندی</div>
            <h2>پەیوەندی بکە بە ئێمەوە</h2>
            <p>هەر پرسیارێکت هەیە؟ ئێمە ئامادەین</p>
        </div>
        <div class="row g-4 justify-content-center">
            <div class="col-lg-5">
                <div class="sharek-card reveal h-100">
                    <h3 class="mb-4">زانیاری پەیوەندی</h3>
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="sharek-card-icon flex-shrink-0" style="width:50px;height:50px;font-size:1.2rem;"><i class="bi bi-envelope"></i></div>
                        <div><div class="text-muted small">ئیمەیڵ</div><div class="fw-bold">***@***.***</div></div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="sharek-card-icon flex-shrink-0" style="width:50px;height:50px;font-size:1.2rem;"><i class="bi bi-telephone"></i></div>
                        <div><div class="text-muted small">تەلەفۆن</div><div class="fw-bold">+964 000 000 0000</div></div>
                    </div>
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="sharek-card-icon flex-shrink-0" style="width:50px;height:50px;font-size:1.2rem;"><i class="bi bi-geo-alt"></i></div>
                        <div><div class="text-muted small">شوێن</div><div class="fw-bold">دەربەندیخان، کوردستان</div></div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="#" class="btn btn-outline-primary btn-sm disabled-link"><i class="bi bi-facebook me-1"></i> فەیسبووک</a>
                        <a href="#" class="btn btn-outline-danger btn-sm disabled-link"><i class="bi bi-instagram me-1"></i> ئینستاگرام</a>
                        <a href="#" class="btn btn-outline-info btn-sm disabled-link"><i class="bi bi-telegram me-1"></i> تێلەگرام</a>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="sharek-card reveal">
                    <h3 class="mb-4">نامە بنووسە</h3>
                    <div id="contact-result" style="display:none;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center"></div>
                    <div class="form-group">
                        <label for="name">ناو</label>
                        <input type="text" id="name" name="name" required placeholder="بۆ نمونە: ئاری حەمەسەعید">
                    </div>
                    <div class="form-group">
                        <label for="contact">ئیمەیڵ یان تەلەفۆن</label>
                        <input type="text" id="contact" name="contact" required placeholder="ئیمەیڵ یان ژمارەی مۆبایل" dir="ltr" style="text-align: left;">
                    </div>
                    <div class="form-group">
                        <label for="message">پەیامەکەت</label>
                        <textarea id="message" name="message" required placeholder="پەیامەکەت بنووسە..."></textarea>
                    </div>
                    <button type="button" class="btn-sharek-primary w-100 justify-content-center" onclick="submitContact()">
                        <span id="contact-btn-text"><i class="bi bi-send me-2"></i> ناردنی پەیام</span>
                        <span id="contact-spinner" style="display:none">⏳</span>
                    </button>
                </div>
                <div class="text-center mt-3">
                    <p class="text-muted">پرسیارێکت هەیە؟ <a href="how-it-works.html" style="color:#1e3a8a;font-weight:600;">پرسیار و وەڵامەکان ببینە ←</a></p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ FOOTER ============ -->
<footer class="sharek-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4">
                <span class="footer-brand-name">شەریک<span>.</span></span>
                <p>یەکەمین پلاتفۆرمی گەشت لە کوردستان. گەشتت ئاسانتر، ئابووریتر، و سەلامەتر.</p>
                <div class="footer-social">
                    <a href="#" class="disabled-link" aria-label="فەیسبووک"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="disabled-link" aria-label="ئینستاگرام"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="disabled-link" aria-label="تێلەگرام"><i class="bi bi-telegram"></i></a>
                </div>
            </div>
            <div class="col-md-2">
                <h5>بەستەرەکان</h5>
                <ul>
                    <li><a href="index.html">سەرەتا</a></li>
                    <li><a href="how-it-works.html">چۆن کار دەکات</a></li>
                    <li><a href="about.html">دەربارەمان</a></li>
                    <li><a href="index.html#features">تایبەتمەندییەکان</a></li>
                    <li><a href="contact.php">پەیوەندی</a></li>
                </ul>
            </div>
            <div class="col-md-2">
                <h5>یاسایی</h5>
                <ul>
                    <li><a href="#" class="disabled-link">سیاسەتی تایبەتمەندی</a></li>
                    <li><a href="#" class="disabled-link">مەرجەکانی بەکارهێنان</a></li>
                    <li><a href="#" class="disabled-link">سیاسەتی کوکی</a></li>
                </ul>
            </div>
            <div class="col-md-4">
                <h5>پەیوەندی</h5>
                <ul>
                    <li>📧 ***@***.***</li>
                    <li>📱 +964 000 000 0000</li>
                    <li>📍 دەربەندیخان، کوردستان</li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© ٢٠٢٦ شەریک — هەموو مافەکان پارێزراون</p>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const CSRF = '<?= $csrf ?>';

    // ---- DARK MODE INIT ----
    (function() {
        const t = document.getElementById('theme-toggle');
        const tm = document.getElementById('theme-toggle-mobile');
        const isDark = document.body.classList.contains('dark-mode');
        if (t) t.innerHTML = isDark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
        if (tm) tm.innerHTML = isDark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
    })();

    function toggleTheme() {
        document.body.classList.toggle('dark-mode');
        const isDark = document.body.classList.contains('dark-mode');
        localStorage.setItem('sharek-theme', isDark ? 'dark' : 'light');
        const icon = isDark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
        const t = document.getElementById('theme-toggle');
        const tm = document.getElementById('theme-toggle-mobile');
        if (t) t.innerHTML = icon;
        if (tm) tm.innerHTML = icon;
    }
    document.getElementById('theme-toggle').addEventListener('click', toggleTheme);
    const tmb = document.getElementById('theme-toggle-mobile');
    if (tmb) tmb.addEventListener('click', toggleTheme);

    // ---- SIDEBAR ----
    function openSidebar() {
        document.getElementById('mainSidebar').classList.add('open');
        document.getElementById('sidebarOverlay').classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        document.getElementById('mainSidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
        document.body.style.overflow = '';
    }
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

    // ---- NAVBAR SCROLL ----
    window.addEventListener('scroll', () => {
        document.getElementById('main-nav').classList.toggle('scrolled', window.scrollY > 50);
    }, { passive: true });

    // ---- SCROLL REVEAL ----
    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
    }, { threshold: 0.1 });
    document.querySelectorAll('.reveal').forEach(el => obs.observe(el));

    // ---- CONTACT FORM AJAX ----
    async function submitContact() {
        const name    = document.querySelector('[name="name"]').value.trim();
        const contact = document.querySelector('[name="contact"]').value.trim();
        const message = document.querySelector('[name="message"]').value.trim();
        const result  = document.getElementById('contact-result');
        const btnText = document.getElementById('contact-btn-text');
        const spinner = document.getElementById('contact-spinner');

        result.style.display = 'none';

        if (!name || !contact || !message) {
            result.style.cssText = 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
            result.textContent = '⚠️ تکایە هەموو خانەکان پڕ بکەرەوە';
            return;
        }

        btnText.style.display = 'none';
        spinner.style.display = 'inline';

        try {
            const fd = new FormData();
            fd.append('name', name);
            fd.append('contact', contact);
            fd.append('message', message);
            fd.append('csrf_token', CSRF);

            const res  = await fetch('contact_handler.php', { method: 'POST', body: fd });
            const json = await res.json();

            result.style.cssText = json.success
                ? 'display:block;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center'
                : 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
            result.textContent = json.message;

            if (json.success) {
                document.querySelector('[name="name"]').value = '';
                document.querySelector('[name="contact"]').value = '';
                document.querySelector('[name="message"]').value = '';
            }
        } catch(e) {
            result.style.cssText = 'display:block;background:#fef2f2;color:#991b1b;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
            result.textContent = '⚠️ کێشەیەک ڕووی دا — ئینتەرنێتەکەت بپشکنە';
        } finally {
            btnText.style.display = 'inline';
            spinner.style.display = 'none';
        }
    }
</script>
</body>
</html>
