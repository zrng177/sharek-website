<?php
/**
 * Sharek v1.5 - Registration Page
 * 3-step: Fill form → OTP verify → Done
 */

// Security headers for InfinityFree compatibility
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Load SecurityManager for enterprise-grade session management
require_once __DIR__ . '/src/Security/SecurityManager.php';
use Sharek\Security\SecurityManager;

// Initialize secure session with timeout, X-Forwarded-Proto-aware secure
// cookie flag, and CSRF protection (audit finding #13) — replaces the
// inline session_set_cookie_params() that only checked $_SERVER['HTTPS']
// and missed idle/absolute session-timeout enforcement.
SecurityManager::initSecureSession();

if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    header('Location: dashboard.php');
    exit;
}
$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="تۆمارکردن لە شەریک - یەکەمین پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان">
    <meta name="theme-color" content="#1e3a8a">
    <title>تۆمارکردن | شەریک</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="font" href="https://fonts.gstatic.com/s/vazirmatn/v15/HI6mYUd6BOtE7YjHgqS2U2vL2x2.woff2" type="font/woff2" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/kurdish-typography.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/responsive-fixes.css">
    <link rel="manifest" href="manifest.json">
    <script>
        (function() {
            const s = localStorage.getItem('sharek-theme');
            if (s === 'dark') document.body.classList.add('dark-mode');
        })();
    </script>
    <style>
        .reg-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
            padding: 1.5rem;
        }
        .reg-card {
            background: var(--bg-card);
            padding: 2.5rem;
            border-radius: var(--r-xl);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 440px;
        }
        .reg-header { text-align: center; margin-bottom: 1.75rem; }
        .reg-header h1 { color: var(--navy); font-size: 1.65rem; margin-bottom: 0.4rem; }
        .reg-header p  { color: var(--text-muted); font-size: 0.9rem; }

        /* Step indicator */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 2rem;
        }
        .step-dot {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: var(--bg-card-alt);
            border: 2px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem; font-weight: 700;
            color: var(--text-muted);
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        .step-dot.active  { background: var(--navy); border-color: var(--navy); color: #fff; }
        .step-dot.done    { background: var(--green); border-color: var(--green); color: #fff; }
        .step-line {
            flex: 1; height: 2px;
            background: var(--border);
            max-width: 60px;
            transition: background 0.3s ease;
        }
        .step-line.done { background: var(--green); }

        .form-group { margin-bottom: 1.1rem; }
        .form-group label { display: block; margin-bottom: 0.45rem; font-weight: 600; font-size: 0.88rem; color: var(--text-secondary); text-align: right; }
        .form-group input {
            width: 100%; padding: 0.78rem 1rem;
            border: 1.5px solid var(--border);
            border-radius: var(--r-md);
            font-size: 16px !important; font-family: inherit;
            background: var(--bg-input);
            color: var(--text-secondary);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus { outline: none; border-color: var(--navy-mid); box-shadow: 0 0 0 3px var(--blue-glow); }
        .password-wrap { position: relative; 
        }
        .password-wrap input { 
         padding-right: 3rem; 
         padding-left: 1rem; 
        }
        .toggle-password {
            position: absolute; right: 0.85rem; left: auto; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            font-size: 1.1rem; color: var(--text-muted);
            padding: 0.25rem; min-height: 44px; min-width: 44px;
            display: flex; align-items: center; justify-content: center;
        }
        .btn-submit {
            width: 100%; padding: 0.9rem;
            background: var(--navy); color: white;
            border: none; border-radius: var(--r-md);
            font-family: inherit; font-size: 1rem; font-weight: 700;
            cursor: pointer; transition: background 0.2s, transform 0.15s;
            min-height: 48px; margin-top: 0.5rem;
        }
        .btn-submit:hover:not(:disabled) { background: var(--navy-mid); transform: translateY(-1px); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .msg-box {
            padding: 0.75rem 1rem; border-radius: var(--r-md);
            margin-bottom: 1.1rem; font-size: 0.88rem;
            font-weight: 600; text-align: center; display: none;
        }
        .msg-error   { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger); }
        .msg-success { background: var(--success-bg); color: var(--success); border: 1px solid var(--success); }

        /* OTP boxes */
        .otp-group {
            display: flex; gap: 0.5rem; justify-content: center;
            direction: ltr; margin: 1.25rem 0;
        }
        .otp-input {
            width: 46px; height: 54px;
            text-align: center; font-size: 1.4rem; font-weight: 700;
            border: 2px solid var(--border); border-radius: var(--r-md);
            background: var(--bg-input); color: var(--text-primary);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .otp-input:focus { outline: none; border-color: var(--navy-mid); box-shadow: 0 0 0 3px var(--blue-glow); }

        /* Success screen */
        .success-screen { text-align: center; padding: 1rem 0; display: none; }
        .success-screen .check { font-size: 4rem; margin-bottom: 1rem; animation: popIn 0.5s ease; }
        @keyframes popIn { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .success-screen h2 { color: var(--green); font-size: 1.4rem; margin-bottom: 0.5rem; }
        .success-screen p  { color: var(--text-muted); margin-bottom: 1.5rem; }

        .login-link { text-align: center; margin-top: 1.25rem; font-size: 0.88rem; color: var(--text-muted); }
        .login-link a { color: var(--navy); font-weight: 600; text-decoration: none; }
        .login-link a:hover { text-decoration: underline; }

        /* Step panels */
        .step-panel { display: none; }
        .step-panel.active { display: block; }

        @media (max-width: 768px) {
            .reg-container { align-items: flex-start; padding: 0.75rem; padding-top: 1.5rem; }
            .reg-card { max-width: 100%; box-sizing: border-box; }
        }

        @media (max-width: 480px) {
            .reg-card { padding: 1.5rem 1.125rem; }
            .otp-input { width: 40px; height: 48px; font-size: 1.2rem; }
        }
    </style>
</head>
<body>
    <header class="auth-header">
        <div class="container">
            <a href="index" class="logo-link">🚗 <span>شەریک</span></a>
            <a href="index" class="back-link">← گەڕانەوە بۆ مالپەڕ</a>
        </div>
    </header>

    <div class="reg-container">
        <div class="reg-card">
            <div class="reg-header">
                <h1>🚗 تۆمارکردن بۆ شەریک</h1>
                <p>هەژمارێکی خۆت دروست بکە — خۆرایە</p>
            </div>

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step-dot active" id="sd1">1</div>
                <div class="step-line" id="sl1"></div>
                <div class="step-dot" id="sd2">2</div>
                <div class="step-line" id="sl2"></div>
                <div class="step-dot" id="sd3">3</div>
            </div>

            <!-- Message box -->
            <div class="msg-box" id="msg-box"></div>

            <!-- STEP 1: Form -->
            <div class="step-panel active" id="step1">
                <div class="form-group">
                    <label for="name">ناوی تەواو</label>
                    <input type="text" id="name" placeholder="بۆ نمونە: ئاری حەمەسەعید" autocomplete="name" required>
                </div>
                <div class="form-group">
                    <label for="phone">ژمارەی مۆبایل</label>
                    <input type="tel" id="phone" placeholder="07XXXXXXXXX" autocomplete="tel" dir="ltr" style="text-align: left;" required>
                </div>
                <div class="form-group">
                    <label for="email">ئیمەیڵ</label>
                    <input type="email" id="email" placeholder="example@mail.com" autocomplete="email" dir="ltr" style="text-align: left;" required>
                </div>
                <div class="form-group">
                    <label for="password">تێپەڕەوشە</label>
                    <div class="password-wrap">
                        <input type="password" id="password" placeholder="لانیکەم ٨ پیت، گەورە+بچووک+ژمارە+هێما" autocomplete="new-password" dir="ltr" style="text-align: left;" required minlength="8">
                        <button type="button" class="toggle-password" onclick="togglePw('password')" aria-label="پیشاندانی تێپەڕەوشە">👁️</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="confirm_password">دووبارەکردنەوەی تێپەڕەوشە</label>
                    <div class="password-wrap">
                        <input type="password" id="confirm_password" placeholder="دووبارە بنووسە" autocomplete="new-password" dir="ltr" style="text-align: left;" required>
                        <button type="button" class="toggle-password" onclick="togglePw('confirm_password')" aria-label="پیشاندانی تێپەڕەوشە">👁️</button>
                    </div>
                </div>
                <button class="btn-submit" id="btn-step1" onclick="submitStep1()">
                    <span id="btn1-text">دواتر — ناردنی کۆدی پشتڕاستکردنەوە ✉️</span>
                    <span id="btn1-spin" style="display:none">⏳ چاوەڕێ بکە...</span>
                </button>
                <div class="login-link">
                    هەژمارت هەیە؟ <a href="login">چوونەژوورەوە</a>
                </div>
            </div>

            <!-- STEP 2: OTP -->
            <div class="step-panel" id="step2">
                <p style="text-align:center;color:var(--text-muted);font-size:.9rem;margin-bottom:.5rem">
                    کۆدی ٦ ژمارەیی بۆ ئیمەیڵەکەت نێردرا
                </p>
                <p style="text-align:center;font-weight:700;color:var(--navy);margin-bottom:1rem" id="otp-email-display"></p>
                <div class="otp-group" id="otp-boxes">
                    <input class="otp-input" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input class="otp-input" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input class="otp-input" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input class="otp-input" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input class="otp-input" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                    <input class="otp-input" type="tel" maxlength="1" inputmode="numeric" pattern="[0-9]">
                </div>
                <button class="btn-submit" id="btn-step2" onclick="submitStep2()">
                    <span id="btn2-text">پشتڕاستکردنەوە ✓</span>
                    <span id="btn2-spin" style="display:none">⏳ چاوەڕێ بکە...</span>
                </button>
                <p style="text-align:center;margin-top:1rem;font-size:.85rem;color:var(--text-muted)">
                    کۆدەکەت نەگەیشت؟
                    <a href="#" onclick="resendOTP(); return false;" style="color:var(--navy);font-weight:600" id="resend-link">دووبارە بنێرە</a>
                    <span id="resend-timer" style="display:none"></span>
                </p>
            </div>

            <!-- STEP 3: Success -->
            <div class="step-panel" id="step3">
                <div class="success-screen" style="display:block">
                    <div class="check">✅</div>
                    <h2>تۆمارکردن سەرکەوتوو بوو!</h2>
                    <p>بەخێربێیت بۆ شەریک! ئێستا دەتوانی گەشت بدۆزیتەوە.</p>
                    <a href="dashboard" class="btn-submit" style="display:inline-block;text-decoration:none;text-align:center">
                        چوونەژوورەوەی داشبۆرد 🚗
                    </a>
                </div>
            </div>

        </div>
    </div>

    <script>
    const CSRF = '<?= $csrf ?>';
    let userEmail = '';
    let resendTimeout = null;

    // ---- OTP boxes auto-advance ----
    document.querySelectorAll('.otp-input').forEach((inp, i, all) => {
        inp.addEventListener('input', () => {
            inp.value = inp.value.replace(/\D/g, '');
            if (inp.value && i < all.length - 1) all[i + 1].focus();
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !inp.value && i > 0) all[i - 1].focus();
        });
        inp.addEventListener('paste', e => {
            const txt = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'');
            if (txt.length === 6) {
                all.forEach((box, j) => { box.value = txt[j] || ''; });
                all[5].focus();
                e.preventDefault();
            }
        });
    });

    function getOTP() {
        return Array.from(document.querySelectorAll('.otp-input')).map(i => i.value).join('');
    }

    function showMsg(text, type) {
        const box = document.getElementById('msg-box');
        box.className = 'msg-box ' + (type === 'error' ? 'msg-error' : 'msg-success');
        box.textContent = text;
        box.style.display = 'block';
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideMsg() {
        document.getElementById('msg-box').style.display = 'none';
    }

    function setStep(n) {
        document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
        document.getElementById('step' + n).classList.add('active');
        hideMsg();

        const dots = [1,2,3];
        dots.forEach(d => {
            const dot = document.getElementById('sd' + d);
            dot.classList.remove('active','done');
            if (d < n) dot.classList.add('done');
            else if (d === n) dot.classList.add('active');
        });
        [1,2].forEach(l => {
            const line = document.getElementById('sl' + l);
            line.classList.toggle('done', l < n);
        });
    }

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

    async function submitStep1() {
        const name = document.getElementById('name').value.trim();
        const phone = document.getElementById('phone').value.trim();
        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;
        const confirm_password = document.getElementById('confirm_password').value;

        if (!name || !phone || !email || !password || !confirm_password) {
            showMsg('⚠️ تکایە هەموو خانەکان پڕ بکەرەوە', 'error'); return;
        }
        if (password !== confirm_password) {
            showMsg('⚠️ تێپەڕەوشەکان یەک ناگرنەوە', 'error'); return;
        }
        if (password.length < 8 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password) || !/[^A-Za-z0-9]/.test(password)) {
            showMsg('⚠️ تێپەڕەوشە دەبێت لانیکەم ٨ پیت بێت و پیتی گەورە و بچووک و ژمارە و هێمای تایبەتی تێبگرێت', 'error'); return;
        }

        const btn = document.getElementById('btn-step1');
        document.getElementById('btn1-text').style.display = 'none';
        document.getElementById('btn1-spin').style.display = 'inline';
        btn.disabled = true;

        try {
            const res = await fetch('api.php?action=send_registration_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, phone, email, password, confirm_password, csrf_token: CSRF })
            });
            const data = await res.json();
            if (data.success) {
                userEmail = email;
                document.getElementById('otp-email-display').textContent = email;
                setStep(2);
                document.querySelector('.otp-input').focus();
                startResendTimer(60);
            } else {
                showMsg('⚠️ ' + (data.message || 'هەڵەیەک ڕووی دا'), 'error');
            }
        } catch(e) {
            showMsg('⚠️ کێشەیەک ڕووی دا — ئینتەرنێتەکەت بپشکنە', 'error');
        } finally {
            document.getElementById('btn1-text').style.display = 'inline';
            document.getElementById('btn1-spin').style.display = 'none';
            btn.disabled = false;
        }
    }

    async function submitStep2() {
        const otp = getOTP();
        if (otp.length < 6) {
            showMsg('⚠️ تکایە کۆدی ٦ ژمارەیی داخڵ بکە', 'error'); return;
        }

        const btn = document.getElementById('btn-step2');
        document.getElementById('btn2-text').style.display = 'none';
        document.getElementById('btn2-spin').style.display = 'inline';
        btn.disabled = true;

        try {
            const res = await fetch('api.php?action=verify_registration_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: userEmail, otp, csrf_token: CSRF })
            });
            const data = await res.json();
            if (data.success) {
                setStep(3);
                if (resendTimeout) clearInterval(resendTimeout);
            } else {
                showMsg('⚠️ ' + (data.message || 'کۆدەکە هەڵەیە'), 'error');
                document.querySelectorAll('.otp-input').forEach(i => i.value = '');
                document.querySelector('.otp-input').focus();
            }
        } catch(e) {
            showMsg('⚠️ کێشەیەک ڕووی دا — ئینتەرنێتەکەت بپشکنە', 'error');
        } finally {
            document.getElementById('btn2-text').style.display = 'inline';
            document.getElementById('btn2-spin').style.display = 'none';
            btn.disabled = false;
        }
    }

    async function resendOTP() {
        const link = document.getElementById('resend-link');
        link.style.pointerEvents = 'none';
        link.textContent = '⏳ ناردن...';

        try {
            const res = await fetch('api.php?action=resend_registration_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: userEmail, csrf_token: CSRF })
            });
            const data = await res.json();
            if (data.success) {
                showMsg('✅ کۆدی نوێ نێردرا بۆ ئیمەیڵەکەت', 'success');
                startResendTimer(60);
            } else {
                showMsg('⚠️ ' + (data.message || 'هەڵە لە ناردندا'), 'error');
                link.style.pointerEvents = '';
                link.textContent = 'دووبارە بنێرە';
            }
        } catch(e) {
            link.style.pointerEvents = '';
            link.textContent = 'دووبارە بنێرە';
        }
    }

    function startResendTimer(seconds) {
        const link = document.getElementById('resend-link');
        const timer = document.getElementById('resend-timer');
        link.style.display = 'none';
        timer.style.display = 'inline';
        if (resendTimeout) clearInterval(resendTimeout);
        let left = seconds;
        timer.textContent = '(' + left + 'چ)';
        resendTimeout = setInterval(() => {
            left--;
            timer.textContent = '(' + left + 'چ)';
            if (left <= 0) {
                clearInterval(resendTimeout);
                timer.style.display = 'none';
                link.style.display = 'inline';
                link.style.pointerEvents = '';
                link.textContent = 'دووبارە بنێرە';
            }
        }, 1000);
    }

    </script>
</body>
</html>
