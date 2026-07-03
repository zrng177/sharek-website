<?php
// Security headers for InfinityFree compatibility
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/src/Security/SecurityManager.php';
use Sharek\Security\SecurityManager;

// Initialize secure session (consistent with all other PHP endpoints)
SecurityManager::initSecureSession();

header('Content-Type: application/json');

// CSRF validation for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!SecurityManager::validateCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'پشتڕاستکردنی فۆرم شکست هێنا. دووبارە هەوڵ بدەرەوە.']);
        exit;
    }
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/src/Security/SecurityManager.php';
use Sharek\Security\SecurityManager;

$db = new Database();
$pdo = $db->getConnection();

// Server-side, IP-and-email-keyed throttle/lockout, persisted in the DB
// (the same login_attempts table src/Security/RateLimiter.php uses for
// login) so it cannot be bypassed by discarding the session cookie — see
// audit findings #3 and #5.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/**
 * Count recent attempts (within $minutes) tied to this IP and/or label.
 */
function countRecentAttempts(PDO $pdo, string $ip, string $label, int $minutes, bool $onlyFailures = false): int {
    $sql = 'SELECT COUNT(*) FROM login_attempts WHERE (ip_address = :ip OR email = :label) AND attempted_at >= (NOW() - INTERVAL :minutes MINUTE)';
    if ($onlyFailures) {
        $sql .= ' AND success = 0';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stmt->bindValue(':label', $label, PDO::PARAM_STR);
    $stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function recordAttempt(PDO $pdo, string $ip, string $label, bool $success): void {
    $pdo->prepare('INSERT INTO login_attempts (ip_address, email, success) VALUES (:ip, :label, :success)')
        ->execute([':ip' => $ip, ':label' => $label, ':success' => $success ? 1 : 0]);
}

function clearAttempts(PDO $pdo, string $label): void {
    $pdo->prepare('DELETE FROM login_attempts WHERE email = :label')->execute([':label' => $label]);
}

// Step 1: Send code to email
if (isset($_POST['email']) && !isset($_POST['code']) && !isset($_POST['password'])) {
    $email = trim($_POST['email']);

    // Rate limiting: max 3 reset requests per 15 minutes, keyed to IP and
    // target email, persisted server-side (immune to clearing cookies).
    $requestLabel = 'pwreset_req:' . $email;
    if (countRecentAttempts($pdo, $ip, $requestLabel, 15) >= 3) {
        echo json_encode(['success' => false, 'message' => 'زۆرتری ٣ داواکاری کردووە. تکایە ١٥ خولەک چاوەڕێ بکە.']);
        exit;
    }
    recordAttempt($pdo, $ip, $requestLabel, false);

    // Check if email exists and get user name
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Generic response regardless of whether the account exists, so an
    // attacker can't enumerate registered emails (audit finding #5).
    $genericResponse = ['success' => true, 'message' => 'ئەگەر ئەم ئیمەیڵە تۆمارکراو بێت، کۆدی پشتڕاستکردن بۆی نێردرا'];

    if (!$user) {
        echo json_encode($genericResponse);
        exit;
    }

    // Generate 6-digit code using cryptographically secure random_int
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Store code in session with expiry (15 minutes)
    $_SESSION['reset_code'] = $code;
    $_SESSION['reset_email'] = $email;
    $_SESSION['reset_expiry'] = time() + 900; // 15 minutes
    $_SESSION['reset_verified'] = false; // Not yet verified

    // Send code via email
    $emailService = new EmailService();
    $sent = $emailService->sendRegistrationOTP($email, $user['name'] ?? 'بەکارهێنەر', $code);
    if (!$sent) {
        // A genuine delivery failure (e.g. SMTP outage) — this doesn't leak
        // whether the email is registered, since we already know it is.
        echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕوویدا لە ناردنی کۆد، تکایە دووبارە هەوڵ بدەرەوە']);
        exit;
    }
    echo json_encode($genericResponse);
    exit;
}

// Step 2: Verify code
if (isset($_POST['email']) && isset($_POST['code']) && !isset($_POST['password'])) {
    $email = trim($_POST['email']);
    $code = trim($_POST['code']);
    $otpLabel = 'pwreset_otp:' . $email;

    // Check if session variables exist
    if (!isset($_SESSION['reset_code']) ||
        !isset($_SESSION['reset_email']) ||
        !isset($_SESSION['reset_expiry'])) {
        echo json_encode(['success' => false, 'message' => 'کێشەیەک هەیە، تکایە دووبارە دەست پێ بکە']);
        exit;
    }

    // Check if email matches
    if ($_SESSION['reset_email'] !== $email) {
        echo json_encode(['success' => false, 'message' => 'کێشەیەک هەیە، تکایە دووبارە دەست پێ بکە']);
        exit;
    }

    // Server-side, IP-and-email-keyed lockout: max 5 failed attempts per 15
    // minutes, persisted in the DB so it survives a discarded session
    // cookie (audit finding #3).
    $failedAttempts = countRecentAttempts($pdo, $ip, $otpLabel, 15, true);
    if ($failedAttempts >= 5) {
        unset($_SESSION['reset_code'], $_SESSION['reset_email'], $_SESSION['reset_expiry'], $_SESSION['reset_verified']);
        echo json_encode(['success' => false, 'message' => 'زۆرتری ٥ هەوڵی هەڵەت داوە. تکایە دووبارە دەست پێ بکە']);
        exit;
    }

    // Check if code has expired
    if (time() > $_SESSION['reset_expiry']) {
        echo json_encode(['success' => false, 'message' => 'کۆدەکە بەسەرچووە، تکایە دووبارە دەست پێ بکە']);
        exit;
    }

    // Check if code is correct (constant-time comparison, consistent with
    // api.php::verifyRegistrationOTP() — audit finding #12)
    if (!hash_equals((string)$_SESSION['reset_code'], (string)$code)) {
        recordAttempt($pdo, $ip, $otpLabel, false);
        $attemptsLeft = 5 - ($failedAttempts + 1);
        if ($attemptsLeft <= 0) {
            unset($_SESSION['reset_code'], $_SESSION['reset_email'], $_SESSION['reset_expiry'], $_SESSION['reset_verified']);
            echo json_encode(['success' => false, 'message' => 'زۆرتری ٥ هەوڵی هەڵەت داوە. تکایە دووبارە دەست پێ بکە']);
            exit;
        }
        echo json_encode(['success' => false, 'message' => "کۆدەکە هەڵەیە. $attemptsLeft هەوڵی ماوە"]);
        exit;
    }

    // Code is correct - set verified flag and clear this label's failure history
    clearAttempts($pdo, $otpLabel);
    $_SESSION['reset_verified'] = true;

    echo json_encode(['success' => true, 'message' => 'کۆدەکە پشتڕاستکرا']);
    exit;
}

// Step 3: Reset password
if (isset($_POST['email']) && isset($_POST['password']) && !isset($_POST['code'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Require ALL three checks before allowing password update
    if (!isset($_SESSION['reset_email']) ||
        !isset($_SESSION['reset_verified']) ||
        !isset($_SESSION['reset_expiry']) ||
        $_SESSION['reset_email'] !== $email ||
        $_SESSION['reset_verified'] !== true ||
        time() > $_SESSION['reset_expiry']) {
        echo json_encode(['success' => false, 'message' => 'کێشەیەک هەیە، تکایە دووبارە دەست پێ بکە']);
        exit;
    }

    // Enforce the real password policy server-side (audit findings #6 and
    // #7) instead of relying solely on the client-side JS check.
    $passwordErrors = SecurityManager::validatePasswordStrength($password);
    if (!empty($passwordErrors)) {
        echo json_encode(['success' => false, 'message' => implode(' / ', $passwordErrors)]);
        exit;
    }

    // Hash new password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Update password in database
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->execute([$hashedPassword, $email]);

    // Clear ALL reset session variables and any recorded OTP failures
    clearAttempts($pdo, 'pwreset_otp:' . $email);
    unset($_SESSION['reset_code']);
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_expiry']);
    unset($_SESSION['reset_verified']);

    echo json_encode(['success' => true, 'message' => 'پاسۆردەکە بە سەرکەوتوویی گۆڕدرا، ئێستا دەتوانیت بچیتەژوورەوە']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'داواکاری نادروستە']);
