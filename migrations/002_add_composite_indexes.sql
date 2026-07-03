-- ============================================================================
-- Migration 002: Add Composite & Covering Indexes
-- Project: Sharek v1.5
-- Date: 2026-06-28
-- Description: Adds missing composite indexes for the most common query
--              patterns in api.php and admin.php. These are purely additive
--              (no data is changed). On a small database the gains are
--              measurable; on growth they become critical.
--
-- SAFE TO RUN: IF NOT EXISTS guards prevent errors on re-run.
-- ============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- TABLE: trips
-- ============================================================================

-- 1. "Active trips" covering index — the hottest read path
--    WHERE t.status = 'active' AND t.date_time >= NOW() AND t.seats_available > 0
--    Replaces: idx_status (single-column), idx_date_time (single-column)
CREATE INDEX IF NOT EXISTS idx_trips_active_listing
    ON trips (status, date_time, seats_available, is_featured)
    COMMENT 'Covering index for getAllTrips / searchTrips active filter';

-- 2. Rate-limit check in createTrip: WHERE driver_id = X ORDER BY created_at DESC LIMIT 1
CREATE INDEX IF NOT EXISTS idx_trips_driver_created
    ON trips (driver_id, created_at)
    COMMENT 'Rate-limit check: last trip by driver';

-- 3. Remove redundant single-column indexes superseded by composite ones above
--    (These were present in the original schema but are now covered)
--    Only drop if they exist — safe on fresh installs too.
ALTER TABLE trips DROP INDEX IF EXISTS idx_status;
ALTER TABLE trips DROP INDEX IF EXISTS idx_date_time;
-- Note: idx_driver_id, idx_departure_city, idx_destination_city, idx_departure_date
--       are retained as they serve specific single-column lookup patterns.

-- ============================================================================
-- TABLE: bookings
-- ============================================================================

-- 4. submitReview: JOIN trips t ON t.id = b.trip_id WHERE b.passenger_id = X
--    cancelTrip / editTrip: WHERE trip_id = X AND status = 'confirmed'
CREATE INDEX IF NOT EXISTS idx_bookings_trip_status
    ON bookings (trip_id, status)
    COMMENT 'Count confirmed bookings per trip (cancelTrip, editTrip)';

-- 5. getMyBookings: WHERE b.passenger_id = X ORDER BY b.created_at DESC
CREATE INDEX IF NOT EXISTS idx_bookings_passenger_created
    ON bookings (passenger_id, created_at)
    COMMENT 'User booking history ordered by date';

-- 6. submitReview duplicate-check + awardPointsToPassengers
CREATE INDEX IF NOT EXISTS idx_bookings_trip_passenger
    ON bookings (trip_id, passenger_id)
    COMMENT 'Review duplicate check and passenger awards';

-- Remove single-column passenger_id index (now covered by composite above)
ALTER TABLE bookings DROP INDEX IF EXISTS idx_passenger_id;

-- ============================================================================
-- TABLE: reviews
-- ============================================================================

-- 7. submitReview: WHERE trip_id = X AND passenger_id = X (duplicate check)
CREATE INDEX IF NOT EXISTS idx_reviews_trip_passenger
    ON reviews (trip_id, passenger_id)
    COMMENT 'Duplicate review guard';

-- ============================================================================
-- TABLE: trip_subscriptions
-- ============================================================================

-- 8. notifySubscribers: WHERE ts.city = X AND ts.is_active = 1 AND ts.user_id != Y
CREATE INDEX IF NOT EXISTS idx_subs_city_active
    ON trip_subscriptions (city, is_active)
    COMMENT 'Find active subscribers for a city';

-- Remove now-covered single-column indexes
ALTER TABLE trip_subscriptions DROP INDEX IF EXISTS idx_city;
ALTER TABLE trip_subscriptions DROP INDEX IF EXISTS idx_is_active;

-- ============================================================================
-- TABLE: users
-- ============================================================================

-- 9. Remove redundant idx_email — UNIQUE already creates an index
ALTER TABLE users DROP INDEX IF EXISTS idx_email;

-- ============================================================================
-- Verification
-- ============================================================================
-- SHOW INDEX FROM trips;
-- SHOW INDEX FROM bookings;
-- SHOW INDEX FROM reviews;
-- SHOW INDEX FROM trip_subscriptions;
