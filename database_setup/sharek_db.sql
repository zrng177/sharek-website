-- Sharek v1.6 - Complete & Corrected Database Schema
-- Generated: 2026-06-28
-- Database: sharek_db
--
-- CHANGES FROM v1.5:
--   [CRITICAL] Added: users.user_ref_id, users.email_verified, users.vehicle_status
--   [CRITICAL] Added: trips.car_model, trips.car_color
--   [FIX] Converted: status/type columns to ENUM for DB-level integrity
--   [FIX] Added: CHECK constraints (points >= 0, seats >= 0, price > 0)
--   [PERF] Added: 8 composite covering indexes for hottest query patterns
--   [PERF] Removed: Redundant idx_email (UNIQUE already creates an index)
--   [FIX] waypoints/via_cities kept as TEXT (comma-separated), matching how
--        api.php::createTrip() actually writes/reads them — a native JSON
--        type here would reject the plain comma-separated strings the app
--        writes and break every trip creation (see migrations/003's note)
--   [CLEAN] Right-sized: VARCHAR lengths for token/OTP fields

-- ============================================
-- 1. Database Creation
-- ============================================
CREATE DATABASE IF NOT EXISTS sharek_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sharek_db;

-- ============================================
-- 2. Users Table
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    user_ref_id                 VARCHAR(20)  DEFAULT NULL UNIQUE           COMMENT 'Human-readable reference ID e.g. SH012345',
    name                        VARCHAR(255) NOT NULL,
    phone                       VARCHAR(20)  NOT NULL,
    email                       VARCHAR(255) NOT NULL UNIQUE,
    password                    VARCHAR(255) NOT NULL,
    -- Verification flags
    is_verified                 TINYINT(1)   NOT NULL DEFAULT 0,
    email_verified              TINYINT(1)   NOT NULL DEFAULT 0            COMMENT 'Email OTP verified flag',
    first_login_verified        TINYINT(1)   NOT NULL DEFAULT 1,
    -- Gamification
    points                      INT          NOT NULL DEFAULT 0,
    level                       ENUM('new','Bronze','Silver','Gold')
                                             NOT NULL DEFAULT 'new',
    -- Driver-specific
    vehicle_status              ENUM('available','not_available')
                                             NOT NULL DEFAULT 'not_available',
    fcm_token                   TEXT         DEFAULT NULL                   COMMENT 'Firebase push notification token',
    -- OTP / token fields (right-sized)
    email_verification_token    VARCHAR(64)  DEFAULT NULL,
    registration_otp            VARCHAR(6)   DEFAULT NULL,
    registration_otp_expires    DATETIME     DEFAULT NULL,
    -- Timestamps
    created_at                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT chk_users_points CHECK (points >= 0),

    -- Indexes (email UNIQUE already creates an index — no separate INDEX needed)
    INDEX idx_phone         (phone),
    INDEX idx_is_verified   (is_verified),
    INDEX idx_user_ref_id   (user_ref_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. Trips Table
-- ============================================
CREATE TABLE IF NOT EXISTS trips (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    driver_id           INT             NOT NULL,
    -- Route
    departure_city      VARCHAR(255)    NOT NULL,
    destination_city    VARCHAR(255)    NOT NULL,
    departure_detail    VARCHAR(255)    DEFAULT NULL,
    destination_detail  VARCHAR(255)    DEFAULT NULL,
    waypoints           TEXT            DEFAULT NULL COMMENT 'Intermediate waypoints, comma-separated (matches api.php::createTrip write format)',
    via_cities          TEXT            DEFAULT NULL COMMENT 'Via city names, comma-separated (matches api.php::createTrip write format)',
    -- Location
    latitude            DECIMAL(10, 8)  DEFAULT NULL,
    longitude           DECIMAL(11, 8)  DEFAULT NULL,
    -- Schedule & capacity
    date_time           DATETIME        NOT NULL,
    seats_available     INT             NOT NULL DEFAULT 0,
    -- Vehicle (used in createTrip / editTrip / listing queries)
    car_model           VARCHAR(100)    DEFAULT NULL,
    car_color           VARCHAR(50)     DEFAULT NULL,
    -- Pricing
    price_iqd           DECIMAL(12, 0)  NOT NULL,
    platform_fee        DECIMAL(12, 0)  NOT NULL DEFAULT 0 COMMENT 'Reserved for future payment gateway',
    -- Classification (ENUM enforces valid values at DB level)
    service_type        ENUM('passenger','delivery','both')
                                        NOT NULL DEFAULT 'passenger',
    status              ENUM('active','cancelled','completed','full')
                                        NOT NULL DEFAULT 'active',
    -- Amenities
    is_featured         TINYINT(1)      NOT NULL DEFAULT 0,
    is_ladies_only      TINYINT(1)      NOT NULL DEFAULT 0,
    has_ac              TINYINT(1)      NOT NULL DEFAULT 0,
    allows_smoking      TINYINT(1)      NOT NULL DEFAULT 0,
    allows_pets         TINYINT(1)      NOT NULL DEFAULT 0,
    music_allowed       TINYINT(1)      NOT NULL DEFAULT 0,
    eta_recorded        TINYINT(1)      NOT NULL DEFAULT 0,
    -- Timestamps
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT chk_trips_seats  CHECK (seats_available >= 0),
    CONSTRAINT chk_trips_price  CHECK (price_iqd > 0),

    -- Foreign keys
    CONSTRAINT fk_trips_driver FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Composite covering index for the "active trips" listing (hottest query)
    -- Replaces individual idx_status + idx_date_time
    INDEX idx_trips_active_listing  (status, date_time, seats_available, is_featured),
    -- Rate-limit check: last trip created by driver (createTrip anti-spam)
    INDEX idx_trips_driver_created  (driver_id, created_at),
    -- City search
    INDEX idx_departure_city        (departure_city),
    INDEX idx_destination_city      (destination_city),
    -- Departure city + date range
    INDEX idx_departure_date        (departure_city, date_time)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. Bookings Table
-- ============================================
CREATE TABLE IF NOT EXISTS bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    trip_id         INT     NOT NULL,
    passenger_id    INT     NOT NULL,
    seats_booked    INT     NOT NULL,
    status          ENUM('pending','confirmed','completed','cancelled')
                            NOT NULL DEFAULT 'pending',
    booking_date    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT chk_bookings_seats CHECK (seats_booked >= 1),

    -- Foreign keys
    CONSTRAINT fk_bookings_trip      FOREIGN KEY (trip_id)      REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_passenger FOREIGN KEY (passenger_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Composite indexes (replace single-column idx_trip_id, idx_passenger_id, idx_status)
    INDEX idx_bookings_trip_status       (trip_id, status),       -- cancelTrip / editTrip confirm count
    INDEX idx_bookings_passenger_created (passenger_id, booking_date), -- getMyBookings
    INDEX idx_bookings_trip_passenger    (trip_id, passenger_id)  -- review guard + award lookup

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. Reviews Table
-- ============================================
CREATE TABLE IF NOT EXISTS reviews (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    driver_id       INT         NOT NULL,
    passenger_id    INT         NOT NULL,
    trip_id         INT         NOT NULL,
    rating          TINYINT     NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment         TEXT        DEFAULT NULL,
    created_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Foreign keys
    CONSTRAINT fk_reviews_driver    FOREIGN KEY (driver_id)    REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_passenger FOREIGN KEY (passenger_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_trip      FOREIGN KEY (trip_id)      REFERENCES trips(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_reviews_driver            (driver_id),
    INDEX idx_reviews_trip_passenger    (trip_id, passenger_id)  -- duplicate-review guard

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. Route ETA Table
-- ============================================
CREATE TABLE IF NOT EXISTS route_eta (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    departure_city          VARCHAR(255)    NOT NULL,
    destination_city        VARCHAR(255)    NOT NULL,
    total_trips             INT             NOT NULL DEFAULT 0,
    total_duration_minutes  INT             NOT NULL DEFAULT 0,
    avg_duration_minutes    DECIMAL(10, 2)  NOT NULL DEFAULT 0,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_route (departure_city, destination_city)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. Trip Subscriptions Table
-- ============================================
CREATE TABLE IF NOT EXISTS trip_subscriptions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT             NOT NULL,
    city        VARCHAR(255)    NOT NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_subs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    INDEX idx_user_city        (user_id, city),
    INDEX idx_subs_city_active (city, is_active)  -- notifySubscribers: city + active filter

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. Saved Routes Table
-- ============================================
CREATE TABLE IF NOT EXISTS saved_routes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT             NOT NULL,
    start_point VARCHAR(255)    NOT NULL,
    end_point   VARCHAR(255)    NOT NULL,
    route_name  VARCHAR(255)    DEFAULT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_saved_routes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    INDEX idx_saved_routes_user (user_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. Offers Table
-- ============================================
CREATE TABLE IF NOT EXISTS offers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_name    VARCHAR(255)    NOT NULL,
    offer_details   TEXT            DEFAULT NULL,
    link            VARCHAR(500)    DEFAULT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_company  (company_name),
    INDEX idx_offers_active  (is_active)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. Sample Data - Offers (Development Only)
-- Remove before production deployment
-- ============================================
INSERT INTO offers (company_name, offer_details, link, is_active) VALUES
('Fast Food Delivery', '10% discount on food delivery', 'https://example.com', 1),
('Gas Station', 'Free car wash with fuel purchase', 'https://example.com', 1)
ON DUPLICATE KEY UPDATE company_name = VALUES(company_name);

-- ============================================
-- 11. (removed) OTP Rate Limits Table
-- This table was never referenced by any PHP file in the project — actual
-- OTP-resend throttling is implemented via a COUNT(*) query against the
-- users table (api.php::resendRegistrationOTP) and PHP session counters
-- (forgot_password_handler.php). Removed as dead schema (audit finding #24)
-- instead of leaving an unused table that suggests rate-limiting state
-- lives somewhere it doesn't.
-- ============================================

-- ============================================
-- 12. Login Attempts Table (server-side, IP-based rate limiting)
-- Required by src/Security/RateLimiter.php — without this table,
-- every login attempt throws a PDOException and login.php fails.
-- ============================================
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
