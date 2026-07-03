<?php
/**
 * Sharek v1.6 - Security Manager
 * 
 * @file src/Security/SecurityManager.php
 * @date 2026-06-28
 * @description Enterprise-grade security utilities for authentication and authorization
 * @version 1.6.0
 * 
 * Security Features:
 * - Session management with timeout and binding
 * - CSRF token generation and validation
 * - Password strength validation
 * - Rate limiting utilities
 * - Secure token generation
 */

declare(strict_types=1);

namespace Sharek\Security;

class SecurityManager {
    
    /**
     * Session timeout values (in seconds)
     */
    private const SESSION_MAX_LIFETIME = 24 * 60 * 60; // 24 hours
    private const SESSION_IDLE_TIMEOUT = 30 * 60; // 30 minutes
    
    /**
     * Password requirements
     */
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_REQUIRE_UPPERCASE = true;
    private const PASSWORD_REQUIRE_LOWERCASE = true;
    private const PASSWORD_REQUIRE_NUMBER = true;
    private const PASSWORD_REQUIRE_SPECIAL = true;
    
    /**
     * Initialize secure session with enterprise-grade protection
     */
    public static function initSecureSession(): void {
        // Configure secure session parameters
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'secure' => self::isSecureConnection(),
            'httponly' => true,
            'samesite' => 'Lax' // Will upgrade to Strict in Phase 3
        ]);
        
        session_save_path(sys_get_temp_dir());
        session_start();
        
        // Enforce session timeout
        self::enforceSessionTimeout();
        
        // Generate CSRF token if not exists
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        // Bind session to user agent (optional, can be disabled for mobile users)
        // self::bindSessionToUserAgent();
    }
    
    /**
     * Check if current connection is secure (HTTPS)
     */
    private static function isSecureConnection(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
    
    /**
     * Enforce session timeout (both absolute and idle)
     */
    private static function enforceSessionTimeout(): void {
        $now = time();
        
        // Check absolute session lifetime
        if (isset($_SESSION['session_created_at'])) {
            if ($now - $_SESSION['session_created_at'] > self::SESSION_MAX_LIFETIME) {
                self::destroySession();
                return;
            }
        } else {
            $_SESSION['session_created_at'] = $now;
        }
        
        // Check idle timeout
        if (isset($_SESSION['last_activity'])) {
            if ($now - $_SESSION['last_activity'] > self::SESSION_IDLE_TIMEOUT) {
                self::destroySession();
                return;
            }
        }
        $_SESSION['last_activity'] = $now;
    }
    
    /**
     * Bind session to user agent (prevent session hijacking)
     * Note: Disabled by default for mobile compatibility
     */
    private static function bindSessionToUserAgent(): void {
        $userAgent = md5($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        if (!isset($_SESSION['user_agent'])) {
            $_SESSION['user_agent'] = $userAgent;
        } elseif ($_SESSION['user_agent'] !== $userAgent) {
            self::destroySession();
        }
    }
    
    /**
     * Regenerate session ID to prevent session fixation
     */
    public static function regenerateSession(): void {
        session_regenerate_id(true);
        $_SESSION['session_created_at'] = time();
        $_SESSION['last_activity'] = time();
        
        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    /**
     * Destroy session securely
     */
    public static function destroySession(): void {
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken(string $token): bool {
        if (empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Get current CSRF token
     */
    public static function getCsrfToken(): string {
        return $_SESSION['csrf_token'] ?? '';
    }
    
    /**
     * Validate password strength
     */
    public static function validatePasswordStrength(string $password): array {
        $errors = [];
        
        // Check minimum length
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            $errors[] = "پاسۆرد دەبێت لەکەم " . self::PASSWORD_MIN_LENGTH . " نووسە بێت";
        }
        
        // Check uppercase
        if (self::PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "پاسۆرد دەبێت پیتە گەورەکان تێدابێت";
        }
        
        // Check lowercase
        if (self::PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = "پاسۆرد دەبێت پیتە بچووکەکان تێدابێت";
        }
        
        // Check numbers
        if (self::PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors[] = "پاسۆرد دەبێت ژمارە تێدابێت";
        }
        
        // Check special characters
        if (self::PASSWORD_REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "پاسۆرد دەبێت نووسەی تایبەتی تێدابێت";
        }
        
        // Check against common weak passwords
        $commonPasswords = [
            '12345678', 'password', '123456789', '1234567890',
            'qwerty', 'abc123', 'password123', 'admin123'
        ];
        
        if (in_array(strtolower($password), $commonPasswords)) {
            $errors[] = "تکایە پاسۆردی بەهێزتر هەڵبژێرە";
        }
        
        return $errors;
    }
    
    /**
     * Generate cryptographically secure random token
     */
    public static function generateSecureToken(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Generate 6-digit OTP for email verification
     */
    public static function generateOTP(): string {
        return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate password reset token (longer for security)
     */
    public static function generatePasswordResetToken(): string {
        return bin2hex(random_bytes(32)) . bin2hex(random_bytes(16));
    }
    
    /**
     * Hash a token for secure storage
     */
    public static function hashToken(string $token): string {
        return hash('sha256', $token);
    }
    
    /**
     * Verify a token against its hash
     */
    public static function verifyToken(string $token, string $hash): bool {
        return hash_equals($hash, self::hashToken($token));
    }
    
    /**
     * Sanitize user input to prevent XSS
     */
    public static function sanitizeInput(string $input): string {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate email format
     */
    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Convert Eastern Arabic numerals (٠-٩) to Western digits (0-9).
     * Must run before any \d-based stripping/validation, since \d does not
     * match Eastern Arabic numerals — running strip first deletes them
     * before they can ever be converted (audit finding #8).
     */
    private static function easternToWesternDigits(string $value): string {
        $eastern = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($eastern, $western, $value);
    }

    /**
     * Validate Kurdish phone number format
     */
    public static function validateKurdishPhone(string $phone): bool {
        // Convert Eastern Arabic numerals first, then strip all non-numeric characters
        $phone = self::easternToWesternDigits($phone);
        $phone = preg_replace('/[^\d]/', '', $phone);
        
        // Check if it's 11 digits starting with 0 (Iraqi format)
        return preg_match('/^07\d{9}$/', $phone) === 1;
    }
    
    /**
     * Normalize Kurdish phone number to standard format
     */
    public static function normalizeKurdishPhone(string $phone): string {
        // Convert Eastern Arabic numerals first, then strip all non-numeric characters
        $phone = self::easternToWesternDigits($phone);
        $phone = preg_replace('/[^\d]/', '', $phone);
        
        // Ensure it starts with 0 and is 11 digits
        if (preg_match('/^7\d{9}$/', $phone)) {
            $phone = '0' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool {
        return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
    }
    
    /**
     * Get current user ID
     */
    public static function getCurrentUserId(): int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    }
    
    /**
     * Check if current user is admin
     */
    public static function isAdmin(): bool {
        return isset($_SESSION['sharek_admin']) && $_SESSION['sharek_admin'] === true;
    }
    
    /**
     * Require authentication (redirects to login if not authenticated)
     */
    public static function requireAuth(): void {
        if (!self::isAuthenticated()) {
            header('Location: login.php');
            exit;
        }
    }
    
    /**
     * Require admin access (redirects if not admin)
     */
    public static function requireAdmin(): void {
        if (!self::isAdmin()) {
            header('Location: login.php');
            exit;
        }
    }
}