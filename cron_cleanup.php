<?php
/**
 * Sharek v1.5 - Automated Cleanup Script
 * 
 * @file cron_cleanup.php
 * @date 2026-05-26
 * @description CLI script to clean up old completed trips (older than 1 year)
 * @version 1.5.0
 * 
 * InfinityFree Deployment Note:
 * 
 * This host does NOT support cron jobs.
 * Use cron-job.org (free) to schedule this script:
 * 
 * 1. Go to https://cron-job.org and create a free account.
 * 2. Create a new cronjob with:
 *    - URL: https://yourdomain.com/cron_cleanup.php
 *    - Schedule: Daily at 02:00
 *    - Request method: GET
 *    - Custom header: X-Cron-Secret: CRON_SECRET
 * 3. Replace CRON_SECRET with the value of CRON_SECRET in your .env file.
 *
 * The X-Cron-Secret header is preferred (audit finding #29) because a
 * secret in the URL query string (the old ?secret=... form, still
 * supported below as a fallback for schedulers that can't set custom
 * headers) gets written in plaintext to web server, proxy, and
 * cron-job.org's own access logs, increasing long-term exposure of the
 * secret. If your scheduler can't send custom headers, ?secret=CRON_SECRET
 * still works, but prefer the header where possible.
 * 
 * Either the header or the ?secret= check in this file ensures only
 * cron-job.org (with the right secret) can trigger it.
 * 
 * Security Features:
 * - Token-based authentication (header, with query-string fallback)
 * - Prepared statements for SQL queries
 * - Activity logging to cron_log.txt
 * - Safe DELETE operation (only affects trips table)
 */

/* ==========================================================================
   Security: Token-Based Authentication
   ========================================================================== */
// پاراستنی فایلەکە بە کێلی نهێنی - تەنها کار دەکات ئەگەر کلیلەکە لە URL-ەکەدا هەبێت
// بۆ بەکارهێنانی لە FastCgin یان HTTP request
$env = parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW);
if ($env === false) {
    // Fallback for InfinityFree open_basedir restrictions — same parser
    // used by Database.php / EmailService.php (audit finding #16). Without
    // this, a host where parse_ini_file() fails silently turns CRON_SECRET
    // into '', which makes the cron endpoint permanently return 403 instead
    // of failing the way the rest of the app explicitly guards against.
    $env = cronParseEnvFallback(__DIR__ . '/.env');
}
$secret_key = $env !== false ? ($env['CRON_SECRET'] ?? '') : '';

/**
 * Fallback parser for .env file when parse_ini_file() fails.
 * Used for InfinityFree compatibility with open_basedir restrictions.
 *
 * @param string $file Path to .env file
 * @return array|false Parsed configuration or false on failure
 */
function cronParseEnvFallback($file) {
    if (!file_exists($file) || !is_readable($file)) {
        return false;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];

    foreach ($lines as $line) {
        // Skip comments and empty lines
        if (empty($line) || strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }
    }

    return $env;
}

// Prefer the secret via the X-Cron-Secret request header (audit finding
// #29) — unlike a ?secret=... query parameter, a header is not written
// into URL-based access logs on the web server, any proxy in front of it,
// or the scheduler's own logs. Fall back to the query string for
// schedulers that can't send custom headers.
$providedSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? ($_GET['secret'] ?? '');

if (empty($secret_key) || empty($providedSecret) || !hash_equals($secret_key, $providedSecret)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Forbidden');
}

require_once __DIR__ . '/Database.php';

try {
    /* ==========================================================================
       Database Connection
       ========================================================================== */
    $db = new Database();
    $pdo = $db->getConnection();
    
    /* ==========================================================================
       Delete Logic: Old Completed Trips
       ========================================================================== */
    // سڕینەوەی تەنها گەشتە تەواوبووەکان کە مێژوویان زیاتر لە ساڵێکە
    // بەکارهێنانی PDO Prepared Statements بۆ پاراستن لە SQL Injection
    $deleteStmt = $pdo->prepare("
        DELETE FROM trips 
        WHERE status = 'completed' 
        AND date_time < DATE_SUB(NOW(), INTERVAL 1 YEAR)
    ");
    
    $deleteStmt->execute();
    $deletedTripsCount = $deleteStmt->rowCount();

    /* ==========================================================================
       Cleanup: Stale Login Attempt Records
       (Noted in migrations/004_add_login_rate_limiting.sql as worth doing via cron)
       ========================================================================== */
    $deleteAttemptsStmt = $pdo->prepare(
        'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)'
    );
    $deleteAttemptsStmt->execute();
    $deletedAttemptsCount = $deleteAttemptsStmt->rowCount();

    /* ==========================================================================
       Activity Logging
       ========================================================================== */
    // تۆمارکردنی چالاکی لە فایلی cron_log.txt
    // بنووسێت: کەی کاری کردووە + چەند گەشت سڕدراوەتەوە
    $logMessage = date('Y-m-d H:i:s') . " - Cleanup: Deleted {$deletedTripsCount} old completed trips (older than 1 year), {$deletedAttemptsCount} stale login_attempt rows (older than 1 day)\n";
    @file_put_contents(__DIR__ . '/cron_log.txt', $logMessage, FILE_APPEND | LOCK_EX);

    // پەیامی سەرکەوتوویی بۆ CLI
    echo "Cleanup completed. Deleted {$deletedTripsCount} old completed trips (older than 1 year), {$deletedAttemptsCount} stale login_attempt rows (older than 1 day).\n";

    
} catch (PDOException $e) {
    /* ==========================================================================
       Error Handling
       ========================================================================== */
    // تۆمارکردنی هەڵەکان لە فایلی cron_log.txt
    $errorMessage = date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    @file_put_contents(__DIR__ . '/cron_log.txt', $errorMessage, FILE_APPEND | LOCK_EX);
    
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
