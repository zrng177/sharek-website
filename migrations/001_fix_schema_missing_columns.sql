-- ============================================================================
-- Migration 001: Add Missing Columns (Critical Fix)
-- Project: Sharek v1.5
-- Date: 2026-06-28
-- Description: Adds all columns referenced in api.php / admin.php that are
--              absent from the original sharek_db.sql schema. Running the
--              application without these columns causes PDOException crashes
--              on registration, trip creation, and user management.
--
-- SAFE TO RUN: Uses IF NOT EXISTS / IGNORE patterns — no data is destroyed.
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET foreign_key_checks = 0;

-- ============================================================================
-- TABLE: users — Add missing columns
-- ============================================================================

-- user_ref_id: Human-readable user reference ID (e.g. SH012345)
-- Used in: api.php sendRegistrationOTP(), admin.php user listing
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS user_ref_id VARCHAR(20) DEFAULT NULL UNIQUE COMMENT 'Human-readable user reference ID, e.g. SH012345',
    ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Email OTP verification flag (1=verified)',
    ADD COLUMN IF NOT EXISTS vehicle_status ENUM('available','not_available') NOT NULL DEFAULT 'not_available' COMMENT 'Driver vehicle availability status';
-- audit finding #37: was previously VARCHAR(50) here, while sharek_db.sql
-- (fresh install) already defined this column as the ENUM above, and
-- migration 003 later converted it to the same ENUM. That meant a fresh
-- install and a site mid-upgrade could briefly (or indefinitely, if
-- migration 003 was skipped) have different column types and different
-- data-integrity guarantees for the same field. Both paths now agree on
-- the ENUM immediately, without depending on migration 003 also running.

-- Add index for user_ref_id lookups in admin search
ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_user_ref_id (user_ref_id);

-- ============================================================================
-- TABLE: trips — Add missing columns
-- ============================================================================

-- car_model and car_color: stored per-trip in api.php createTrip() / editTrip()
ALTER TABLE trips
    ADD COLUMN IF NOT EXISTS car_model VARCHAR(100) DEFAULT NULL COMMENT 'Vehicle model (e.g. Toyota Corolla)',
    ADD COLUMN IF NOT EXISTS car_color VARCHAR(50) DEFAULT NULL COMMENT 'Vehicle color (e.g. White)';

-- ============================================================================
-- Data integrity: backfill email_verified from is_verified for existing rows
-- is_verified=1 implies the user completed email OTP, so set email_verified=1
-- ============================================================================
UPDATE users
SET email_verified = 1
WHERE is_verified = 1 AND email_verified = 0;

SET foreign_key_checks = 1;

-- ============================================================================
-- Verification queries (run after migration to confirm success):
-- ============================================================================
-- SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT, IS_NULLABLE
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_NAME = 'users'
--   AND COLUMN_NAME IN ('user_ref_id','email_verified','vehicle_status')
--   AND TABLE_SCHEMA = DATABASE();
--
-- SELECT COLUMN_NAME, DATA_TYPE
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_NAME = 'trips'
--   AND COLUMN_NAME IN ('car_model','car_color')
--   AND TABLE_SCHEMA = DATABASE();
