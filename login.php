<?php
/**
 * Sharek v1.5 - Login Page
 * 
 * @file login.php
 * @date 2026-05-26
 * @description User login page with email and password authentication
 * @version 1.5.0
 * 
 * Security Features:
 * - Session-based authentication
 * - password_verify() for password verification
 * - Prepared statements for SQL queries
 * - Input sanitization with htmlspecialchars()
 */

// Security headers for InfinityFree compatibility
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Load SecurityManager for enterprise-grade session management
require_once __DIR__ . '/src/Security/SecurityManager.php';
use Sharek\Security\SecurityManager;

// Initialize secure session with timeout and CSRF protection
SecurityManager::initSecureSession();

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/src/Security/RateLimiter.php';

// Redirect if already logged in
if (SecurityManager::isAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token using SecurityManager
    if (!SecurityManager::validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'تکایە دووبارە هەوڵبدەرەوە';
    } else {
        // Rate limiting: server-side, IP-based, persists independent of the
        // client's cookies (see src/Security/RateLimiter.php).
        $ip = $_SERVER['REMOTE_ADDR'];

        try {
            $db = new Database();
            $pdo = $db->getConnection();
            $rateLimiter = new RateLimiter($pdo);

            if ($rateLimiter->isLockedOut($ip)) {
                $minutes = $rateLimiter->lockoutMinutesRemaining($ip);
                $error = "زۆرتر هەوڵدراوە. تکایە {$minutes} خولەک چاوەڕێ بکە.";
            } else {
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $password = isset($_POST['password']) ? $_POST['password'] : '';

                if (empty($email) || empty($password)) {
                    $error = 'ئیمەیڵ و تێپەڕەوشە پێویستە';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'فۆرماتی ئیمەیڵ نادروستە';
                } elseif ($rateLimiter->isAccountLockedOut($email)) {
                    // Security fix: the IP-based check above doesn't stop a
                    // credential-stuffing attack spread across many IPs
                    // against one specific account. This closes that gap.
                    $minutes = $rateLimiter->accountLockoutMinutesRemaining($email);
                    $error = "زۆرتر هەوڵدراوە بۆ ئەم هەژمارە. تکایە {$minutes} خولەک چاوەڕێ بکە.";
                } else {
                    $stmt = $pdo->prepare('SELECT id, name, phone, email, password, first_login_verified FROM users WHERE email = :email LIMIT 1');
                    $stmt->execute([':email' => $email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$user || !password_verify($password, $user['password'])) {
                        $rateLimiter->recordFailure($ip, $email);
                        $error = 'ئیمەیڵ یان تێپەڕەوشە هەڵەیە';
                    } else {
                        // First login verification is now handled by registration OTP flow
                        // Legacy first_login_code/first_login_code_expires branch removed
                        if ((int)$user['first_login_verified'] === 0) {
                            $error = 'تکایە یەکەم جار لە ڕێگای register.php بچۆژوورەوە بۆ پشتڕاستکردنی ئیمەیڵت.';
                        } else {
                            $rateLimiter->recordSuccess($ip, $email);

                            // Set user session using SecurityManager — store raw
                            // values; HTML-escaping happens once, at render time
                            // (see audit finding #2), not here at write-time.
                            $_SESSION['user_id'] = (int) $user['id'];
                            $_SESSION['user_name'] = $user['name'];
                            $_SESSION['user_phone'] = $user['phone'];
                            $_SESSION['user_email'] = $user['email'];

                            // Regenerate session ID to prevent session fixation
                            SecurityManager::regenerateSession();
                            unset($_SESSION['login_attempts'], $_SESSION['login_lockout_until']);

                            header('Location: dashboard.php');
                            exit;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('[Login] Database error: ' . $e->getMessage());
            $error = 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="چوونەژوورەوە لە شەریک - یەکەمین پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان">
    <meta name="theme-color" content="#1e3a8a">
    <title>چوونەژوورەوە | شەریک</title>
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
            const saved = localStorage.getItem('sharek-theme');
            if (saved === 'dark') document.body.classList.add('dark-mode');
        })();
    </script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('./service-worker.js')
                    .then((registration) => {
                        console.log('ServiceWorker registration successful');
                    })
                    .catch((error) => {
                        console.log('ServiceWorker registration failed:', error);
                    });
            });
        }
    </script>
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
            padding: 1.5rem;
        }

        .login-card {
            background: var(--bg-card);
            padding: 2.5rem;
            border-radius: var(--r-xl);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .login-header {
            margin-bottom: 2rem;
        }

        .login-header h1 {
            color: var(--navy);
            margin: 0 0 0.5rem 0;
            font-size: 1.75rem;
        }

        .login-header p {
            color: var(--text-muted);
            margin: 0;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-body);
            font-weight: 500;
            text-align: right;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: var(--r-md);
            font-size: 16px !important;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--navy);
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            padding-right: 3rem;
            padding-left: 1rem;
        }

        .toggle-password {
            position: absolute;
            right: 1rem;
            left: auto;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.2rem;
            color: var(--text-muted);
            padding: 0;
            min-height: 44px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password:hover {
            color: var(--text-body);
        }

        .btn-submit {
            width: 100%;
            padding: 0.875rem;
            background: var(--navy);
            color: white;
            border: none;
            border-radius: var(--r-md);
            font-family: inherit;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-submit:hover {
            background: var(--navy-mid);
        }

        .error-message {
            background: var(--danger-bg);
            color: var(--danger);
            padding: 0.75rem;
            border-radius: var(--r-md);
            margin-bottom: 1.25rem;
            text-align: center;
        }

        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            color: var(--text-muted);
        }

        .register-link a {
            color: var(--navy);
            text-decoration: none;
            font-weight: 500;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .forgot-password {
            text-align: center;
            margin-top: 1rem;
        }

        .forgot-password a {
            color: var(--navy-light);
            text-decoration: none;
            font-size: 0.875rem;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .login-container {
                align-items: flex-start;
                padding: 1rem;
                padding-top: 2rem;
            }
            .login-card {
                padding: 1.5rem 1.125rem;
                max-width: 100%;
                box-sizing: border-box;
            }
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 1.25rem 1rem;
            }
            .login-header h1 {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
    <!-- Auth Header -->
    <header class="auth-header">
        <div class="container">
            <a href="index.html" class="logo-link">🚗 <span>شەریک</span></a>
            <a href="index.html" class="back-link">← گەڕانەوە بۆ مالپەڕ</a>
        </div>
    </header>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>🚗 چوونەژوورەوە بۆ شەریک</h1>
                <p>بەکارهێنەری تۆمارکراو</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="email">ئیمەیڵ</label>
                    <input type="email" id="email" name="email" placeholder="example@mail.com" autocomplete="email" dir="ltr" style="text-align: left;" required>
                </div>
                
                <div class="form-group">
                    <label for="password">تێپەڕەوشە</label>
                    <div class="password-wrap">
                        <input type="password" id="password" name="password" placeholder="تێپەڕەوشە" autocomplete="current-password" dir="ltr" style="text-align: left;" required>
                        <button type="button" class="toggle-password" id="toggleBtn"  onclick="togglePassword('password')">👁️</button>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">چوونەژوورەوە</button>
            </form>
            
            <div class="forgot-password">
                <a href="forgot-password.php">بیرچوونی تێپەڕەوشە؟</a>
            </div>
            
            <div class="register-link">
                هەژمارت نییە؟ <a href="register.php">تۆمارکردن</a>
            </div>
            
            <div style="text-align: center; margin-top: 1rem;">
                <a href="index.html" style="color: var(--text-muted); text-decoration: none; font-size: 0.875rem;">گەڕانەوە بۆ ماڵپەڕ</a>
            </div>
        </div>
    </div>
    
    <script>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = document.getElementById('toggleBtn'); // بەکارهێنانی ID
            
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = '🙈';
            } else {
                input.type = 'password';
                button.textContent = '👁️';
            }
        }

        // Submit lock
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('.btn-submit');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'چاوەڕوانبە...';
            
            setTimeout(function() {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }, 8000);
        });
    </script>
</body>
</html>
