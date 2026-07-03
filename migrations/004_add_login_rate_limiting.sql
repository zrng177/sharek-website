-- ============================================================================
-- Migration 004: Server-side Login Rate Limiting
-- Project: Sharek v1.5
-- Date: 2026-06-30
-- Description: Replaces the session-based login attempt counter (which an
--              attacker could bypass simply by clearing cookies) with a
--              server-side table keyed by IP address + email, with a
--              time-decayed lockout window.
--
-- SAFE TO RUN: CREATE TABLE IF NOT EXISTS only; no existing data touched.
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(255) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    INDEX idx_ip_time (ip_address, attempted_at),
    INDEX idx_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional cleanup: old rows beyond the lockout window are harmless but can
-- be purged periodically (e.g. via cron_cleanup.php) with:
-- DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY);
