<?php
/**
 * Sharek Website - Contact Form Handler
 * 
 * @file contact_handler.php
 * @date 2026-06-19
 * @description Handles contact form submissions, validates CSRF, notifies admin via EmailService
 * @version 1.5.0
 */

header('Content-Type: application/json');

// Load SecurityManager for enterprise-grade session management
require_once __DIR__ . '/src/Security/SecurityManager.php';
use Sharek\Security\SecurityManager;

// Initialize secure session with timeout, X-Forwarded-Proto-aware secure
// cookie flag, and CSRF protection (audit finding #13) — replaces the
// inline session_set_cookie_params() that only checked $_SERVER['HTTPS'].
SecurityManager::initSecureSession();

// Sanitize input: trim and strip slashes only. HTML-escaping happens once,
// at the actual point of HTML output below (see audit finding #2) — not
// here at write/log time.
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    return $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    $csrfToken = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        echo json_encode([
            'success' => false,
            'message' => 'پشتڕاستکردنی فۆرم شکست هێنا. دووبارە هەوڵ بدەرەوە.'
        ]);
        exit;
    }

    // Rate limiting: max 5 submissions per 10 minutes per IP, persisted
    // server-side in the same login_attempts table used elsewhere (reusing
    // its ip_address/email/attempted_at columns as a generic throttle log
    // — 'email' here holds a namespaced label, not the submitter's email).
    // Previously this form had no rate limiting at all: CSRF stops
    // cross-site forgery, but a logged-in-session visitor could still
    // submit it repeatedly to spam the admin inbox and grow the log file.
    require_once __DIR__ . '/Database.php';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $contactLabel = 'contact_form';
    try {
        $db = new Database();
        $pdo = $db->getConnection();
        $rlStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND email = :label AND attempted_at >= (NOW() - INTERVAL 10 MINUTE)'
        );
        $rlStmt->execute([':ip' => $ip, ':label' => $contactLabel]);
        if ((int) $rlStmt->fetchColumn() >= 5) {
            echo json_encode([
                'success' => false,
                'message' => 'زۆر هەوڵ دەدەیت. تکایە ١٠ خولەک چاوەڕێ بکە.'
            ]);
            exit;
        }
        $pdo->prepare('INSERT INTO login_attempts (ip_address, email, success) VALUES (:ip, :label, 0)')
            ->execute([':ip' => $ip, ':label' => $contactLabel]);
    } catch (PDOException $e) {
        // If the rate-limit check itself fails (e.g. DB hiccup), fail open
        // rather than blocking legitimate contact submissions — this is a
        // spam throttle, not an auth control.
        error_log('[ContactForm] rate limit check failed: ' . $e->getMessage());
    }

    // Get and sanitize form data
    $name = isset($_POST['name']) ? sanitizeInput($_POST['name']) : '';
    $contact = isset($_POST['contact']) ? sanitizeInput($_POST['contact']) : '';
    $message = isset($_POST['message']) ? sanitizeInput($_POST['message']) : '';
    
    // Validate required fields
    if (empty($name) || empty($contact) || empty($message)) {
        echo json_encode([
            'success' => false,
            'message' => 'تکایە هەموو خانەکان پڕ بکەرەوە'
        ]);
        exit;
    }
    
    // Validate email or phone
    if (!filter_var($contact, FILTER_VALIDATE_EMAIL) && !preg_match('/^[0-9+\-\s]+$/', $contact)) {
        echo json_encode([
            'success' => false,
            'message' => 'ئیمەیڵ یان تەلەفۆنی نادروستە'
        ]);
        exit;
    }
    
    // Log to secure temp folder
    $logMessage = date('Y-m-d H:i:s') . " | Name: {$name} | Contact: {$contact} | Message: {$message}\n";
    $logFile = sys_get_temp_dir() . '/sharek_contact.log';
    @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

    // Send notification email to admin
    require_once __DIR__ . '/EmailService.php';
    $env = parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW);
    $adminEmail = !empty($env['ADMIN_EMAIL']) ? $env['ADMIN_EMAIL'] : 'info@sharek.com';

    try {
        $emailSvc = new EmailService();
        $subject = 'پەیامێکی نوێ لە فۆرمی پەیوەندی: ' . $name;
        // Escape exactly once, here, at the actual point of HTML output
        // (see audit findings #2 and #9/#10).
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeContact = htmlspecialchars($contact, ENT_QUOTES, 'UTF-8');
        $body = "
            <html dir='rtl' lang='ku'>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: Tahoma, Arial, sans-serif; direction: rtl; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px; }
                    .header { background: #0f2557; color: white; padding: 15px; text-align: center; border-radius: 6px 6px 0 0; }
                    .body { padding: 20px; line-height: 1.6; color: #333; }
                    .field { margin-bottom: 12px; }
                    .label { font-weight: bold; color: #0f2557; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🚗 شەریک - پەیامی نوێ</h2>
                    </div>
                    <div class='body'>
                        <div class='field'>
                            <span class='label'>ناو:</span> {$safeName}
                        </div>
                        <div class='field'>
                            <span class='label'>پەیوەندی:</span> {$safeContact}
                        </div>
                        <div class='field'>
                            <span class='label'>پەیام:</span>
                            <p>" . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . "</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        $emailSvc->sendCustomEmail($adminEmail, $subject, $body, true);
        
        echo json_encode([
            'success' => true,
            'message' => 'پەیامەکەت نێردرا! 🎉'
        ]);
    } catch (Exception $e) {
        error_log('Contact email failed to send: ' . $e->getMessage());
        // Return success even if email failed, as log is written.
        echo json_encode([
            'success' => true,
            'message' => 'پەیامەکەت نێردرا! 🎉'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}
