<?php
// Security headers for InfinityFree compatibility
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_save_path(sys_get_temp_dir());
session_start();

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="بگەڕێنەوە بۆ پاسۆردەکەت - شەریک">
    <title>بگەڕێنەوە بۆ پاسۆردەکەت | شەریک</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/kurdish-typography.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/landing.css">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/responsive-fixes.css">
    <script>
        (function() {
            const saved = localStorage.getItem('sharek-theme');
            if (saved === 'dark') document.body.classList.add('dark-mode');
        })();
    </script>
    <style>
        .spam-hint {
            font-size: 0.82rem;
            color: var(--text-muted);
            background: var(--warning-bg);
            border-right: 3px solid var(--amber);
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            margin-top: 0.5rem;
        }
        .password-wrap { position: relative; }
        .password-wrap input { padding-right: 3rem; padding-left: 1rem; }
        .toggle-password {
            position: absolute; right: 0.85rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            font-size: 1.1rem; color: var(--text-muted);
            padding: 0; display: flex; align-items: center; justify-content: center;
            height: 100%;
        }
        .toggle-password:hover { color: var(--text-body); }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <h1 class="logo">شەریک</h1>
                <nav class="nav">
                    <a href="index" class="nav-btn">سەرەتا</a>
                    <a href="how-it-works" class="nav-btn">چۆن کار دەکات</a>
                    <a href="about" class="nav-btn">دەربارەمان</a>
                    <a href="contact" class="nav-btn">پەیوەندی</a>
                </nav>
                <div class="header-auth">
                    <a href="register" class="nav-btn nav-btn-auth">تۆمارکردن</a>
                    <a href="login" class="nav-btn nav-btn-auth">چوونەژوورەوە</a>
                </div>
                <button id="theme-toggle" class="theme-toggle" aria-label="تەبەدڵکردنی مۆد">
                    🌙
                </button>
            </div>
        </div>
    </header>

    <!-- Forgot Password Section -->
    <section class="section" style="min-height: calc(100vh - 200px); display: flex; align-items: center;">
        <div class="container">
            <div class="auth-container">
                <div class="auth-card">
                    <div class="auth-header">
                        <h2>بگەڕێنەوە بۆ پاسۆردەکەت</h2>
                        <p>ئیمەیڵەکەت بنووسە بۆ ناردنی کۆدی پشتڕاستکردن</p>
                    </div>
                    
                    <div id="step-1" class="auth-step">
                        <form id="forgot-form" onsubmit="sendCode(event)">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <div class="form-group">
                                <label>ئیمەیڵەکەت داخڵ بکە</label>
                                <input type="email" name="email" placeholder="ئیمەیڵەکەت بنووسە" dir="ltr" style="text-align: left;" required>
                            </div>
                            <p id="spam-hint" class="spam-hint" style="display:none;">
                                📬 ئەگەر ئیمەیڵەکە لە Inbox نەبوو، تکایە سەیری <strong>Spam</strong> یان <strong>Promotions</strong> بکە.
                            </p>
                            <button type="submit" class="btn btn-primary btn-full">
                                <span id="send-btn-text">ناردنی کۆد</span>
                                <span id="send-spinner" style="display:none">⏳</span>
                            </button>
                        </form>
                        <div id="send-result" style="display:none;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center"></div>
                    </div>
                    
                    <div id="step-2" class="auth-step" style="display:none">
                        <form id="verify-form" onsubmit="verifyCode(event)">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <div class="form-group">
                                <label>کۆدی پشتڕاستکردنەوە (OTP)</label>
                                <input type="text" name="code" placeholder="کۆدی 6 ژمارەیی" maxlength="6" dir="ltr" style="text-align: left;" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-full">
                                <span id="verify-btn-text">پشتڕاستکردن</span>
                                <span id="verify-spinner" style="display:none">⏳</span>
                            </button>
                        </form>
                        <div id="verify-result" style="display:none;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center"></div>
                    </div>
                    
                    <div id="step-3" class="auth-step" style="display:none">
                        <form id="reset-form" onsubmit="resetPassword(event)">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                            <div class="form-group">
                                <label>تێپەڕەوشەی نوێ</label>
                                <div class="password-wrap">
                                    <input type="password" id="password" name="password" placeholder="تێپەڕەوشەی نوێ (لانیکەم ٨ پیت، گەورە+بچووک+ژمارە+هێما)" dir="ltr" style="text-align: left;" required minlength="8">
                                    <button type="button" class="toggle-password" onclick="togglePw('password')" aria-label="پیشاندانی تێپەڕەوشە">👁️</button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>دووبارەکردنەوەی تێپەڕەوشە</label>
                                <div class="password-wrap">
                                    <input type="password" id="confirm_password" name="confirm_password" placeholder="دووبارەکردنەوەی تێپەڕەوشە" dir="ltr" style="text-align: left;" required minlength="8">
                                    <button type="button" class="toggle-password" onclick="togglePw('confirm_password')" aria-label="پیشاندانی تێپەڕەوشە">👁️</button>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-full">
                                <span id="reset-btn-text">گۆڕینی پاسۆرد</span>
                                <span id="reset-spinner" style="display:none">⏳</span>
                            </button>
                        </form>
                        <div id="reset-result" style="display:none;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center"></div>
                    </div>
                    
                    <div class="auth-footer">
                        <p>گەڕانەوە بۆ چوونەژوورەوە؟ <a href="login">کلیک بکە</a></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-bottom">
                <p>© ٢٠٢٦ شەریک — هەموو مافەکان پارێزراون</p>
            </div>
        </div>
    </footer>

    <script src="js/main.js"></script>
    <script>
        let userEmail = '';
        // Toggle password visibility
        function togglePw(id) {
            const inp = document.getElementById(id);
            const btn = inp.nextElementSibling;
            if (inp.type === 'password') {
                inp.type = 'text';
                btn.textContent = '🙈';
            } else {
                inp.type = 'password';
                btn.textContent = '👁️';
            }
        }

        async function sendCode(e) {
            e.preventDefault();
            const email = document.querySelector('[name="email"]').value.trim();
            const result = document.getElementById('send-result');
            const btnText = document.getElementById('send-btn-text');
            const spinner = document.getElementById('send-spinner');
            
            result.style.display = 'none';
            
            if (!email) {
                result.style.cssText = 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = '⚠️ تکایە ئیمەیڵ بنووسە';
                return;
            }
            
            btnText.style.display = 'none';
            spinner.style.display = 'inline';
            
            try {
                const fd = new FormData();
                fd.append('email', email);
                
                const res = await fetch('forgot_password_handler.php', { method: 'POST', body: fd });
                const json = await res.json();
                
                result.style.cssText = json.success
                    ? 'display:block;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center'
                    : 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = json.message;
                
                if (json.success) {
                    userEmail = email;
                    document.getElementById('spam-hint').style.display = 'block';
                    document.getElementById('step-1').style.display = 'none';
                    document.getElementById('step-2').style.display = 'block';
                }
            } catch(e) {
                result.style.cssText = 'display:block;background:#fef2f2;color:#991b1b;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = '⚠️ کێشەیەک ڕووی دا — ئینتەرنێتەکەت بپشکنە';
            } finally {
                btnText.style.display = 'inline';
                spinner.style.display = 'none';
            }
        }
        
        async function verifyCode(e) {
            e.preventDefault();
            const code = document.querySelector('[name="code"]').value.trim();
            const result = document.getElementById('verify-result');
            const btnText = document.getElementById('verify-btn-text');
            const spinner = document.getElementById('verify-spinner');
            
            result.style.display = 'none';
            
            if (!code || code.length !== 6) {
                result.style.cssText = 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = '⚠️ تکایە کۆدی 6 ژمارەیی بنووسە';
                return;
            }
            
            btnText.style.display = 'none';
            spinner.style.display = 'inline';
            
            try {
                const fd = new FormData();
                fd.append('email', userEmail);
                fd.append('code', code);
                
                const res = await fetch('forgot_password_handler.php', { method: 'POST', body: fd });
                const json = await res.json();
                
                result.style.cssText = json.success
                    ? 'display:block;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center'
                    : 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = json.message;
                
                if (json.success) {
                    document.getElementById('step-2').style.display = 'none';
                    document.getElementById('step-3').style.display = 'block';
                }
            } catch(e) {
                result.style.cssText = 'display:block;background:#fef2f2;color:#991b1b;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = '⚠️ کێشەیەک ڕووی دا — ئینتەرنێتەکەت بپشکنە';
            } finally {
                btnText.style.display = 'inline';
                spinner.style.display = 'none';
            }
        }
        
        async function resetPassword(e) {
            e.preventDefault();
            const password = document.querySelector('[name="password"]').value;
            const confirmPassword = document.querySelector('[name="confirm_password"]').value;
            const result = document.getElementById('reset-result');
            const btnText = document.getElementById('reset-btn-text');
            const spinner = document.getElementById('reset-spinner');
            
            result.style.display = 'none';
            
            if (!password || password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password) || !/[^A-Za-z0-9]/.test(password)) {
                result.style.cssText = 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = '⚠️ پاسۆرد دەبێت لانیکەم ٨ پیت بێت و پیتی گەورە و بچووک و ژمارە و هێمای تایبەتی تێبگرێت';
                return;
            }
            
            if (password !== confirmPassword) {
                result.style.cssText = 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = '⚠️ پاسۆردەکان یەک ناچن';
                return;
            }
            
            btnText.style.display = 'none';
            spinner.style.display = 'inline';
            
            try {
                const fd = new FormData();
                fd.append('email', userEmail);
                fd.append('password', password);
                
                const res = await fetch('forgot_password_handler.php', { method: 'POST', body: fd });
                const json = await res.json();
                
                result.style.cssText = json.success
                    ? 'display:block;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center'
                    : 'display:block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:.75rem;border-radius:10px;margin-bottom:1rem;font-weight:600;text-align:center';
                result.textContent = json.message;
                
                if (json.success) {
                    setTimeout(() => {
                        window.location.href = 'login.php';
                    }, 2000);
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
