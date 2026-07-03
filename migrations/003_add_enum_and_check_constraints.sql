-- ============================================================================
-- Migration 003: Add ENUM Constraints & CHECK Constraints
-- Project: Sharek v1.5
-- Date: 2026-06-28
-- Description: Converts free-text status/type columns to ENUM and adds CHECK
--              constraints to enforce data integrity at the database level,
--              independent of application code.
--
-- SAFE TO RUN: ALTER TABLE MODIFY only; no rows deleted.
--              Existing valid values are preserved by ENUM definition.
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET foreign_key_checks = 0;

-- ============================================================================
-- TABLE: users — Add ENUM constraints
-- ============================================================================

-- vehicle_status: only two valid states
ALTER TABLE users
    MODIFY COLUMN vehicle_status ENUM('available','not_available') NOT NULL DEFAULT 'not_available'
    COMMENT 'Driver vehicle availability';

-- level: only four valid levels (matches calculateLevel() PHP logic)
ALTER TABLE users
    MODIFY COLUMN level ENUM('new','Bronze','Silver','Gold') NOT NULL DEFAULT 'new'
    COMMENT 'Gamification level derived from points';

-- points: must be non-negative
-- MySQL 8.0.16+ supports CHECK inline; for compatibility we use a named constraint
ALTER TABLE users
    ADD CONSTRAINT IF NOT EXISTS chk_users_points CHECK (points >= 0);

-- ============================================================================
-- TABLE: trips — Add ENUM constraints
-- ============================================================================

-- service_type: only three valid values (matches VALID_SERVICE_TYPES in api.php)
ALTER TABLE trips
    MODIFY COLUMN service_type ENUM('passenger','delivery','both') NOT NULL DEFAULT 'passenger'
    COMMENT 'Type of trip service';

-- status: only four valid states (matches all status values in api.php)
ALTER TABLE trips
    MODIFY COLUMN status ENUM('active','cancelled','completed','full') NOT NULL DEFAULT 'active'
    COMMENT 'Trip lifecycle status';

-- seats_available: must be non-negative
ALTER TABLE trips
    ADD CONSTRAINT IF NOT EXISTS chk_trips_seats CHECK (seats_available >= 0);

-- price_iqd: must be positive (matches validatePriceIqd() in api.php)
ALTER TABLE trips
    ADD CONSTRAINT IF NOT EXISTS chk_trips_price CHECK (price_iqd > 0);

-- ============================================================================
-- TABLE: bookings — Add ENUM constraints
-- ============================================================================

-- status: all states used in api.php
ALTER TABLE bookings
    MODIFY COLUMN status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending'
    COMMENT 'Booking lifecycle status';

-- seats_booked: must be at least 1
ALTER TABLE bookings
    ADD CONSTRAINT IF NOT EXISTS chk_bookings_seats CHECK (seats_booked >= 1);

-- ============================================================================
-- TABLE: trips — waypoints / via_cities intentionally left as TEXT
-- ============================================================================
-- audit finding #36: this migration used to convert waypoints/via_cities
-- to native JSON columns, but api.php::createTrip() writes them as plain,
-- htmlspecialchars()-escaped comma-separated strings (e.g.
-- "Erbil, Koya, Sulaymaniyah"), not JSON documents — confirmed by
-- js/app.js reading them back with trip.via_cities.split(','). MySQL/
-- MariaDB validates that values written to a JSON column are well-formed
-- JSON, so a bare comma-separated string would fail on INSERT/UPDATE on
-- any database this migration had been applied to, breaking every
-- createTrip() call outright.
--
-- Schema and application now agree on the same representation (TEXT,
-- comma-separated) rather than converting only one side. If a true JSON
-- shape is wanted in the future, api.php must be updated to
-- json_encode()/json_decode() these fields as arrays first, and this
-- column-type change reintroduced at the same time.

-- ============================================================================
-- TABLE: otp_rate_limits — Shrink oversized types
-- ============================================================================
-- registration_otp: 6 digits max
ALTER TABLE users
    MODIFY COLUMN registration_otp VARCHAR(6) DEFAULT NULL COMMENT '6-digit OTP for registration';

-- email_verification_token: 64 hex chars (bin2hex(random_bytes(32)))
ALTER TABLE users
    MODIFY COLUMN email_verification_token VARCHAR(64) DEFAULT NULL COMMENT 'Email verification token';

-- fcm_token: FCM tokens can be 163+ chars; TEXT is safer
ALTER TABLE users
    MODIFY COLUMN fcm_token TEXT DEFAULT NULL COMMENT 'Firebase Cloud Messaging token';

SET foreign_key_checks = 1;

-- ============================================================================
-- Verification
-- ============================================================================
-- SHOW COLUMNS FROM users;
-- SHOW COLUMNS FROM trips;
-- SHOW COLUMNS FROM bookings;
