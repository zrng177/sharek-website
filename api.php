<?php
/**
 * Sharek v1.5 - REST API Endpoint
 * 
 * @file api.php
 * @date 2026-05-25
 * @description Main API endpoint handling user authentication, trip management,
 *              reviews, subscriptions, FCM notifications, and dynamic ETA learning
 * @version 1.5.0
 * 
 * Security Features:
 * - Input sanitization via sanitizeInput()
 * - Prepared statements for all SQL queries
 * - Session-based authentication
 * - CORS headers for cross-origin requests
 */

// Security headers for InfinityFree compatibility
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

/* ==========================================================================
   Session & CORS Configuration
   ========================================================================== */
// Security fix: api.php is the endpoint the actual SPA logs in and operates
// through (see SECURITY_FIXES_2026-07-03.md #2), but it previously
// hand-rolled its own session_set_cookie_params()/session_start() instead of
// going through SecurityManager::initSecureSession(). Cookie flags (secure/
// httponly/samesite) were correct, but the 30-minute idle / 24-hour absolute
// session timeout enforced everywhere else (admin.php, login.php,
// dashboard.php, register.php, forgot-password.php) was silently skipped
// here — meaning a session issued through the main app never expired
// server-side. Routing through the shared helper closes that gap and
// removes the duplicated cookie-config logic (three separate copies of the
// same block is exactly the kind of drift that already bit error_reporting
// in .htaccess/Database.php once before).
require_once __DIR__ . '/src/Security/SecurityManager.php';
\Sharek\Security\SecurityManager::initSecureSession();

header('Content-Type: application/json; charset=utf-8');
$_env = file_exists(__DIR__ . '/.env') ? parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW) : [];
if ($_env === false) {
    throw new RuntimeException('Configuration unavailable');
}
$appUrl = rtrim(trim($_env['APP_URL'] ?? ''), '/');
$allowedOrigins = array_filter([$appUrl, 'http://localhost', 'http://127.0.0.1']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

/**
 * Fail-closed credential validation.
 *
 * Refuses to run the application if known-exposed / placeholder credentials
 * are detected in .env. Update $knownExposedValues with any value that has
 * ever been committed, shared, or leaked (including the ones currently in
 * your .env file, until you rotate them).
 */
$knownExposedValues = [
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // Default sample hash
    'your_database_password_here',
    'your_smtp_password_here',
    'your_cron_secret_here',
];

foreach ($knownExposedValues as $exposedValue) {
    if (in_array($exposedValue, [$_env['ADMIN_PASSWORD_HASH'] ?? '', $_env['DB_PASS'] ?? '', $_env['SMTP_PASS'] ?? '', $_env['CRON_SECRET'] ?? ''], true)) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'SECURITY ERROR: Application is running with exposed placeholder credentials. Please rotate all credentials in .env file. See DEPLOYMENT_NOTES.md for instructions.'], JSON_UNESCAPED_UNICODE));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/Database.php';

class SharekAPI {
    /**
     * PDO database connection instance
     * @var PDO
     */
    private $pdo;

    /**
     * Valid Kurdish cities whitelist for location validation
     * @var array
     */
    const VALID_KURDISH_CITIES = [
        'هەولێر', 'سلێمانی', 'دهۆک', 'کەرکوک', 'هەڵەبجە', 'سۆران', 'رواندز', 'شەقڵاوە',
        'خەلیفان', 'حەریر', 'چۆمان', 'کۆیە', 'تەق تەق', 'خەبات', 'مەخموور', 'ڕانیە', 'دووکان',
        'پیرەمەگروون', 'بازیان', 'تەکیە', 'سەید سادق', 'پێنجوێن', 'شەهرەزوور', 'زەڕایەن', 'ماوەت',
        'چوارتا', 'قەرەداغ', 'عەربەت', 'کەلار', 'چەمچەماڵ', 'شۆڕش', 'دەربەندیخان', 'زاخۆ',
        'ئاکرێ', 'ئامێدی', 'شێخان', 'بەردەڕەش', 'سێمێل', 'زاوێتە', 'خانەقین', 'کفری'
    ];

    /**
     * Constructor - Initialize API with database connection
     *
     * Establishes connection to database via Database class
     */
    public function __construct() {
        $db = new Database();
        $this->pdo = $db->getConnection();
    }

    /**
     * Main request handler
     * 
     * Routes incoming requests to appropriate handler based on HTTP method
     * Supports GET and POST methods
     * 
     * @return void
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = isset($_GET['action']) ? $_GET['action'] : '';

        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet($action);
                    break;
                case 'POST':
                    $this->handlePost($action);
                    break;
                default:
                    $this->sendError(405, 'ڕێگای داواکاری نادروستە');
            }
        } catch (Exception $e) {
            error_log('[SharekAPI] handleRequest: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    /* ==========================================================================
       Core Helper Functions & Sanitation
       ========================================================================== */
    
    /**
     * Parse JSON input from request body
     * 
     * Reads and decodes JSON from php://input stream
     * Returns empty array if no data or invalid JSON
     * 
     * @return array Decoded JSON data or empty array
     */
    private function getJsonInput() {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') return [];
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) $this->sendError(400, 'فۆرماتی داتای نادروستە');
        return is_array($data) ? $data : [];
    }

    /**
     * Sanitize input data: strips HTML tags and trims whitespace.
     *
     * Deliberately does NOT apply htmlspecialchars() here. This sanitizer is
     * used both for values that get stored in the database (write-time) and
     * for values used in SQL search filters — escaping HTML entities at this
     * stage caused values to be stored/matched in escaped form and then
     * escaped again on every read, producing garbled output (e.g. "&amp;#039;"
     * instead of "'") and broken search matching. HTML-escaping must happen
     * exactly once, at the actual point of HTML output (see audit finding #2).
     *
     * @param mixed $data Input data to sanitize
     * @return mixed Sanitized data
     */
    private function sanitizeInput($data) {
        if (is_array($data)) return array_map([$this, 'sanitizeInput'], $data);
        return strip_tags(trim((string) $data));
    }

    /**
     * Normalize Kurdish phone numbers to standard format
     * 
     * Converts Eastern Arabic/Kurdish numerals to Western
     * Strips country codes and ensures 11-digit format starting with 0
     * 
     * @param string $phone Phone number to normalize
     * @return string Normalized phone number (e.g., 07501234567)
     */
    private function normalize_kurdish_phone($phone) {
        // Strip white spaces and non-numeric chars except plus
        $phone = preg_replace('/[^\d+٠-٩]/u', '', $phone);
        
        // Convert Eastern Arabic/Kurdish numerals to Western
        $eastern = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $phone = str_replace($eastern, $western, $phone);

        // Strip leading +964 or 00964
        if (strpos($phone, '+964') === 0) {
            $phone = '0' . substr($phone, 4);
        } elseif (strpos($phone, '00964') === 0) {
            $phone = '0' . substr($phone, 5);
        }

        // Ensure it always starts with 0 and is 11 digits (e.g. 0750xxxxxxx),
        // but if it's 10 digits starting with 7, prepend 0.
        if (preg_match('/^7\d{9}$/', $phone)) {
            $phone = '0' . $phone;
        }

        return $phone;
    }

    /**
     * Sanitize price value
     * 
     * Removes non-numeric characters and converts to float
     * 
     * @param mixed $price Price value to sanitize
     * @return float Sanitized price value
     */
    private function sanitizePrice($price) {
        return (float) preg_replace('/[^0-9.]/', '', (string) $price);
    }

    /**
     * Send success JSON response
     * 
     * Sets HTTP status code and outputs JSON response with success flag
     * 
     * @param int $code HTTP status code
     * @param string $message Success message
     * @param mixed $data Optional data to include in response
     * @return void
     */
    private function sendSuccess($code, $message, $data = null) {
        http_response_code($code);
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) $response['data'] = $data;
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit();
    }

    /**
     * Send error JSON response
     * 
     * Sets HTTP status code and outputs JSON response with error flag
     * 
     * @param int $code HTTP status code
     * @param string $message Error message
     * @return void
     */
    private function sendError($code, $message) {
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $message, 'error_code' => $code], JSON_UNESCAPED_UNICODE);
        exit();
    }

    private function setUserSession($userId, $name, $phone, $email = null) {
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_phone'] = $phone;
        if ($email !== null) {
            $_SESSION['user_email'] = $email;
        }
    }

    private function requireAuth() {
        if (!isset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_phone']) || (int) $_SESSION['user_id'] < 1) {
            $this->sendError(401, 'چوونەژوورەوە پێویستە');
        }
        $userId = (int) $_SESSION['user_id'];
        
        // Verify user exists in database to prevent foreign key violations
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $userId]);
            if (!$stmt->fetch()) {
                $this->sendError(401, 'بەکارهێنەر نەدۆزرایەوە. تکایە دووبارە بچۆژوورەوە.');
            }
        } catch (PDOException $e) {
            error_log('[SharekAPI] requireAuth: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
        
        return $userId;
    }

    /**
     * Validate price in IQD
     * 
     * Enforces 0 < price <= 30000
     * 
     * @param mixed $price Price value to validate
     * @return float Sanitized price value
     */
    private function validatePriceIqd($price) {
        $price = $this->sanitizePrice($price);
        if ($price <= 0 || $price > 30000) {
            $this->sendError(400, $price > 30000 ? 'بۆڕە، ناتوانیت نرخی کورسی لە 30,000 دینار زیاتر دابنێیت!' : 'نرخ دبێت ژمارەیەکی دروست بێت');
        }
        return $price;
    }

    /**
     * Validate seats available
     * 
     * Enforces 1-10 for non-delivery service types
     * 
     * @param mixed $seats Seats value to validate
     * @param string $serviceType Service type (passenger, delivery, both)
     * @return int Sanitized seats value
     */
    private function validateSeatsAvailable($seats, $serviceType) {
        if ($serviceType === 'delivery') {
            return 0;
        }
        $seats = (int) $this->sanitizeInput($seats);
        if ($seats < 1 || $seats > 10) {
            $this->sendError(400, 'ژمارەی شوێنەکان دەبێت لەنێوان 1 و 10 بێت');
        }
        return $seats;
    }

    /**
     * Validate date time format
     * 
     * Enforces 'Y-m-d H:i:s' format and ensures date is not in the past
     * 
     * @param mixed $dateTime DateTime value to validate
     * @return string Validated date time string
     */
    private function validateDateTimeFormat($dateTime) {
        $dateTime = $this->sanitizeInput($dateTime);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateTime)) {
            $this->sendError(400, 'فرماتی بەروار و کات نادروستە');
        }
        if ($dateTime < date('Y-m-d H:i:s')) {
            $this->sendError(400, 'ناتوانیت گەشتێک بۆ کاتی ڕابردوو یان بەسەرچوو تۆمار بکەیت!');
        }
        return $dateTime;
    }

    /**
     * Validate Kurdish city
     * 
     * Checks against the VALID_KURDISH_CITIES constant
     * 
     * @param string $city City name to validate
     * @return string Validated city name
     */
    private function validateKurdishCity($city) {
        $city = $this->sanitizeInput($city);
        if (!in_array($city, self::VALID_KURDISH_CITIES)) {
            $this->sendError(400, 'شارێکی نادروستە');
        }
        return $city;
    }

    private function tripSelectPublic() {
        return "SELECT t.id, t.driver_id, t.departure_city, t.destination_city, t.departure_detail, t.destination_detail,
                       t.waypoints, t.via_cities, t.latitude, t.longitude, t.date_time, t.price_iqd, t.seats_available,
                       t.car_model, t.car_color, t.has_ac, t.allows_smoking, t.allows_pets, t.music_allowed,
                       t.is_ladies_only, t.is_featured, t.platform_fee, t.service_type,
                       u.name AS driver_name, u.is_verified AS driver_verified,
                       (SELECT COALESCE(ROUND(AVG(rating), 1), 0) FROM reviews WHERE reviews.driver_id = t.driver_id) AS driver_avg_rating
                FROM trips t
                INNER JOIN users u ON t.driver_id = u.id";
    }

    private function activeTripsWhere() {
        return " WHERE t.status = 'active' AND t.date_time >= NOW() AND t.seats_available > 0 AND t.created_at >= NOW() - INTERVAL 1 DAY";
    }

    private function formatTripRow(array &$trip) {
        $trip['price_formatted'] = number_format((float) $trip['price_iqd'], 0) . ' د.ع';
        $trip['date_formatted'] = date('Y/m/d H:i', strtotime($trip['date_time']));
        $trip['available_seats'] = (int) $trip['seats_available'];
        $trip['vehicle_model'] = $trip['car_model'];
        $trip['vehicle_color'] = $trip['car_color'];
        $trip['driver_verified'] = !empty($trip['driver_verified']);
    }

    /* ==========================================================================
       Public Endpoints (Anonymous Access)
       ========================================================================== */
// ============================================================================
// GET REQUEST HANDLER: Public Data Fetching
// ============================================================================
// This section handles all GET requests for retrieving public data such as
// trips, search results, and session information. All endpoints here are
// accessible without authentication.
// ============================================================================
    private function handleGet($action) {
        switch ($action) {
            case 'search_trips': $this->searchTrips(); break;
            case 'get_trips':
            case 'get_all_trips': $this->getAllTrips(); break;
            case 'check_session': $this->checkSession(); break;
            case 'get_my_trips': $this->getMyTrips(); break;
            case 'get_my_bookings': $this->getMyBookings(); break;
            case 'get_driver_reviews': $this->getDriverReviews(); break;
            case 'get_map_trips': $this->getMapTrips(); break;
            case 'get_saved_routes': $this->getSavedRoutes(); break;
            case 'search_nearby_drivers': $this->searchNearbyDrivers(); break;
            case 'get_offers': $this->getOffers(); break;
            case 'get_stats': $this->getStats(); break;
            default: $this->sendError(400, 'داواکاری نادروستە');
        }
    }

    private function searchTrips() {
        $departure = isset($_GET['departure']) ? $this->sanitizeInput($_GET['departure']) : '';
        $destination = isset($_GET['destination']) ? $this->sanitizeInput($_GET['destination']) : '';
        $date_time = isset($_GET['date_time']) ? $this->sanitizeInput($_GET['date_time']) : '';
        $route_query = isset($_GET['route_query']) ? $this->sanitizeInput($_GET['route_query']) : '';

        if ($departure === '' && $destination === '' && $date_time === '' && $route_query === '') {
            $this->getAllTrips();
            return;
        }

        try {
            $sql = $this->tripSelectPublic() . $this->activeTripsWhere();
            $params = [];

            if ($departure !== '') {
                $sql .= " AND (t.departure_city LIKE :departure OR t.departure_detail LIKE :departure)";
                $params[':departure'] = '%' . $departure . '%';
            }

            if ($destination !== '' || $route_query !== '') {
                $destTerm = $destination !== '' ? $destination : $route_query;
                $sql .= " AND (t.destination_city LIKE :destination OR t.destination_detail LIKE :destination OR t.waypoints LIKE :destination)";
                $params[':destination'] = '%' . $destTerm . '%';
            }

            if ($date_time !== '') {
                $sql .= " AND t.date_time >= :date_time";
                $params[':date_time'] = $date_time;
            }

            $sql .= " ORDER BY t.is_featured DESC, t.date_time ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($trips as &$trip) { $this->formatTripRow($trip); }
            unset($trip);

            $this->sendSuccess(200, 'گەشتەکان دۆزرایەوە', $trips);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getMyTrips: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function getAllTrips() {
        try {
            $sql = $this->tripSelectPublic() . $this->activeTripsWhere() . " ORDER BY t.is_featured DESC, t.date_time ASC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($trips as &$trip) { $this->formatTripRow($trip); }
            unset($trip);

            $this->sendSuccess(200, 'هەموو گەشتەکان گەڕانەوە', $trips);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getAllTrips: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    /* ==========================================================================
       Protected Action Endpoints (Requires Validation)
       ========================================================================== */
// ============================================================================
// POST REQUEST HANDLER: Protected Actions with Integrity Validation
// ============================================================================
// This section handles all POST requests that require authentication and
// server-side validation. Includes trip creation, booking, and user management.
// All endpoints here enforce strict security checks including departure_city
// validation against the Kurdish cities dictionary to prevent form tampering.
// ============================================================================
    private function handlePost($action) {
        // Verify CSRF token for POST requests
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'هەڵەیەک ڕووی دا - تکایە دووبارە هەوڵبدەرەوە', 'error_code' => 403]);
            exit;
        }

        switch ($action) {
            case 'login': $this->login(); break;
            case 'logout': $this->logout(); break;
            case 'send_registration_otp': $this->sendRegistrationOTP(); break;
            case 'verify_registration_otp': $this->verifyRegistrationOTP(); break;
            case 'resend_registration_otp': $this->resendRegistrationOTP(); break;
            case 'create_trip': $this->createTrip(); break;
            case 'delete_trip': $this->deleteTrip(); break;
            case 'cancel_trip': $this->cancelTrip(); break;
            case 'edit_trip': $this->editTrip(); break;
            case 'book_seat': $this->bookSeat(); break;
            case 'submit_review': $this->submitReview(); break;
            case 'subscribe': $this->subscribe(); break;
            case 'notify_subscribers': $this->notifySubscribers(); break;
            case 'save_fcm_token': $this->saveFcmToken(); break;
            case 'get_driver_reputation': $this->getDriverReputation(); break;
            case 'record_trip_completion': $this->recordTripCompletion(); break;
            case 'get_route_eta': $this->getRouteEta(); break;
            case 'save_route': $this->saveRoute(); break;
            case 'delete_route': $this->deleteRoute(); break;
            case 'contact': $this->handleContactForm(); break;
            default: $this->sendError(400, 'داواکاری نادروستە');
        }
    }

    // ============================================================================
    // SUBMIT REVIEW: Driver Rating Submission
    // ============================================================================
    private function submitReview() {
        $userId = $this->requireAuth();

        $input = $this->getJsonInput();

        // Validate required fields
        if (!isset($input['trip_id']) || !isset($input['rating'])) {
            $this->sendError(400, 'هەموو خانەکان پێویستە پڕ بکرێن');
        }

        $tripId = (int) $input['trip_id'];
        $rating = (int) $input['rating'];
        $comment = isset($input['comment']) ? $this->sanitizeInput($input['comment']) : '';

        // Validate rating range
        if ($rating < 1 || $rating > 5) {
            $this->sendError(400, 'ڕەیتینگ دەبێت لەنێوان ١ بۆ ٥ بێت');
        }

        // Get driver_id from trip and verify booking + trip completion status
        try {
            // Check if user has a confirmed/completed booking for this trip AND trip is completed
            $stmt = $this->pdo->prepare("
                SELECT t.driver_id, t.status
                FROM trips t
                INNER JOIN bookings b ON t.id = b.trip_id
                WHERE t.id = :trip_id
                  AND b.passenger_id = :passenger_id
                  AND b.status IN ('confirmed', 'completed')
                  AND t.status = 'completed'
                LIMIT 1
            ");
            $stmt->execute([':trip_id' => $tripId, ':passenger_id' => $userId]);
            $trip = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$trip) {
                $this->sendError(403, 'تۆ ناتوانیت ئەم گەشتە هەڵسەنگێنیت — تۆ ئەم گەشتەت داگیر نەکردووە');
            }

            $driverId = (int) $trip['driver_id'];

            // Check if user already reviewed this trip
            $checkStmt = $this->pdo->prepare("SELECT id FROM reviews WHERE trip_id = :trip_id AND passenger_id = :passenger_id");
            $checkStmt->execute([':trip_id' => $tripId, ':passenger_id' => $userId]);
            if ($checkStmt->fetch()) {
                $this->sendError(400, 'تۆ پێشتر پێداچوونەوەت داوە بۆ ئەم گەشتە');
            }

            // Insert review
            $insertStmt = $this->pdo->prepare(
                "INSERT INTO reviews (driver_id, passenger_id, trip_id, rating, comment) 
                 VALUES (:driver_id, :passenger_id, :trip_id, :rating, :comment)"
            );
            $insertStmt->execute([
                ':driver_id' => $driverId,
                ':passenger_id' => $userId,
                ':trip_id' => $tripId,
                ':rating' => $rating,
                ':comment' => $comment
            ]);

            $this->sendSuccess(200, 'پێداچوونەوەکە تۆمار کرا');
        } catch (PDOException $e) {
            error_log('[SharekAPI] submitReview: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // REPUTATION SYSTEM: Calculate Driver Reputation
    // ============================================================================
    private function calculateDriverReputation($driverId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(ROUND(AVG(rating), 1), 0) as avg_rating, COUNT(*) as total_reviews
                FROM reviews
                WHERE driver_id = :driver_id
            ");
            $stmt->execute([':driver_id' => $driverId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $avgRating = (float) $result['avg_rating'];
            $totalReviews = (int) $result['total_reviews'];

            // Determine reputation title based on rating and review count
            $title = 'شۆفێری نوێ';
            
            if ($totalReviews >= 10) {
                if ($avgRating >= 4.8) {
                    $title = 'شۆفێری پێشەنگ';
                } elseif ($avgRating >= 4.5) {
                    $title = 'شۆفێری باوەڕپێکراو';
                } elseif ($avgRating >= 4.0) {
                    $title = 'شۆفێری باش';
                } elseif ($avgRating >= 3.5) {
                    $title = 'شۆفێری ئاسایی';
                } else {
                    $title = 'شۆفێری پێویست بە پێشکەوتن';
                }
            } elseif ($totalReviews >= 5) {
                if ($avgRating >= 4.5) {
                    $title = 'شۆفێری باوەڕپێکراو';
                } elseif ($avgRating >= 4.0) {
                    $title = 'شۆفێری باش';
                } else {
                    $title = 'شۆفێری لە پەرەسەنداندا';
                }
            }

            return [
                'avg_rating' => $avgRating,
                'total_reviews' => $totalReviews,
                'title' => $title
            ];
        } catch (PDOException $e) {
            return [
                'avg_rating' => 0,
                'total_reviews' => 0,
                'title' => 'شۆفێری نوێ'
            ];
        }
    }

    // ============================================================================
    // REPUTATION SYSTEM: Get Driver Reputation
    // ============================================================================
    private function getDriverReputation() {
        // Security fix: this endpoint returns the driver's phone number, so
        // it must require a logged-in session like every other endpoint in
        // this dispatcher. Previously it skipped requireAuth() entirely,
        // which meant the ownership check below (guarded by
        // "$currentUserId > 0") was silently bypassed for anonymous
        // visitors — any site visitor with a plain page-load session could
        // query any driver_id and get back that driver's phone number.
        $currentUserId = $this->requireAuth();

        $input = $this->getJsonInput();

        if (!isset($input['driver_id']) || $input['driver_id'] === '') {
            $this->sendError(400, 'ناسنامەی شۆفێر پێویستە');
        }

        $driverId = (int) $input['driver_id'];

        try {
            // Allow access if: user is the driver themself, OR has a booking with the driver
            if ($currentUserId !== $driverId) {
                // Check if current user has a booking with this driver
                $accessCheck = $this->pdo->prepare("
                    SELECT COUNT(*) as access_count 
                    FROM bookings 
                    WHERE passenger_id = :current_user_id 
                    AND driver_id = :requested_driver_id
                ");
                $accessCheck->execute([':current_user_id' => $currentUserId, ':requested_driver_id' => $driverId]);
                $hasAccess = $accessCheck->fetchColumn();
                
                if (!$hasAccess) {
                    $this->sendError(403, 'Access denied - you do not have permission to view this driver\'s reputation');
                }
            }
            
            // Get driver info
            $stmt = $this->pdo->prepare("SELECT id, name, phone, is_verified FROM users WHERE id = :driver_id LIMIT 1");
            $stmt->execute([':driver_id' => $driverId]);
            $driver = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$driver) {
                $this->sendError(404, 'شۆفێر نەدۆزرایەوە');
            }

            // Calculate reputation
            $reputation = $this->calculateDriverReputation($driverId);

            $this->sendSuccess(200, 'ڕیزبەندی شۆفێر گەڕانەوە', [
                'driver' => [
                    'id' => $driver['id'],
                    'name' => $driver['name'],
                    'is_verified' => !empty($driver['is_verified'])
                ],
                'reputation' => $reputation
            ]);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getDriverReputation: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function checkSession() {
        if (!isset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_phone']) || (int) $_SESSION['user_id'] < 1) {
            $this->sendError(401, 'بەکارهێنەر چوونەتەژوورەوە نیە');
        }
        $this->sendSuccess(200, 'بەکارهێنەر چوونەتەژوورەوە', [
            'user_id' => (int) $_SESSION['user_id'],
            'name' => (string) $_SESSION['user_name'],
            'phone' => (string) $_SESSION['user_phone'],
        ]);
    }



    private function login() {
        // Security fix: this is the login endpoint the actual app calls
        // (js/app.js -> apiFetch('login', ...)) and it previously had NO
        // rate limiting at all — not even IP-based — while the separate
        // login.php page did. This was the real, unprotected brute-force
        // surface. Bring it up to the same standard as login.php: IP-based
        // AND account(email)-based DB-backed lockout via RateLimiter.
        require_once __DIR__ . '/src/Security/RateLimiter.php';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimiter = new RateLimiter($this->pdo);

        if ($rateLimiter->isLockedOut($ip)) {
            $minutes = $rateLimiter->lockoutMinutesRemaining($ip);
            $this->sendError(429, "زۆرتر هەوڵدراوە. تکایە {$minutes} خولەک چاوەڕێ بکە.");
        }

        $input = $this->getJsonInput();
        $email = isset($input['email']) ? trim($input['email']) : '';
        $password = isset($input['password']) ? $input['password'] : '';

        if ($email === '' || $password === '') $this->sendError(400, 'ئیمەیڵ و تێپەڕەوشە پێویستە');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $this->sendError(400, 'فۆرماتی ئیمەیڵ نادروستە');

        if ($rateLimiter->isAccountLockedOut($email)) {
            $minutes = $rateLimiter->accountLockoutMinutesRemaining($email);
            $this->sendError(429, "زۆرتر هەوڵدراوە بۆ ئەم هەژمارە. تکایە {$minutes} خولەک چاوەڕێ بکە.");
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id, name, phone, email, password, first_login_verified FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                $rateLimiter->recordFailure($ip, $email);
                $this->sendError(401, 'ئیمەیڵ یان تێپەڕەوشە هەڵەیە');
            }

            if ((int)$user['first_login_verified'] === 0) {
                $this->sendError(403, 'تکایە یەکەم جار لە ڕێگای login.php بچۆژوورەوە بۆ پشتڕاستکردنی ئیمەیڵت.');
            }

            $rateLimiter->recordSuccess($ip, $email);
            $this->setUserSession((int) $user['id'], $user['name'], $user['phone'], $user['email']);
            $this->sendSuccess(200, 'چوونەژوورەوە سەرکەوتوو بوو', ['user_id' => (int) $user['id'], 'name' => $user['name'], 'phone' => $user['phone'], 'email' => $user['email']]);
        } catch (PDOException $e) {
            error_log('[SharekAPI] login: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function logout() {
        // Use SecurityManager for secure session destruction
        require_once __DIR__ . '/src/Security/SecurityManager.php';
        \Sharek\Security\SecurityManager::destroySession();
        $this->sendSuccess(200, 'چوونەدەرەوە سەرکەوتوو بوو');
    }

    // ============================================================================
    // REGISTRATION OTP VERIFICATION
    // ============================================================================
    private function sendRegistrationOTP() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // CSRF validation
        $csrfFromRequest = $data['csrf_token'] ?? '';
        $csrfFromSession = $_SESSION['csrf_token'] ?? '';
        if (empty($csrfFromSession) || !hash_equals($csrfFromSession, $csrfFromRequest)) {
            return $this->sendError(403, 'پشتڕاستکردنی فۆرم شکست هێنا. دووبارە هەوڵ بدەرەوە.');
        }
        
        // Store raw, validated input — HTML-escaping happens exactly once,
        // at the point of HTML output (see audit finding #2), not here at
        // write-time. strip_tags() still guards against stray markup.
        $name = strip_tags(trim($data['name'] ?? ''));
        $email = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $pass = $data['password'] ?? '';
        $confirmPass = $data['confirm_password'] ?? '';

        // Normalise + validate phone using the shared SecurityManager helper
        // (same logic used in login.php / register.php) so there is a single
        // source of truth for what constitutes a valid Kurdish phone number.
        require_once __DIR__ . '/src/Security/SecurityManager.php';
        $phone = \Sharek\Security\SecurityManager::normalizeKurdishPhone(
            strip_tags(trim($data['phone'] ?? ''))
        );
        if (!preg_match('/^07\d{9}$/', $phone))
            return $this->sendError(400, 'ژمارەی مۆبایل دەبێت بە فۆرماتی 07XXXXXXXXX بێت');

        // پشتڕاستکردنی داتا
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            return $this->sendError(400, 'ئیمەیڵەکە نادروستە — تکایە دووبارەیەک بپشکنە');

        // Enforce the real password policy (SecurityManager::validatePasswordStrength)
        // instead of the old, much weaker strlen() < 6 check (see audit finding #7).
        require_once __DIR__ . '/src/Security/SecurityManager.php';
        $passwordErrors = \Sharek\Security\SecurityManager::validatePasswordStrength($pass);
        if (!empty($passwordErrors))
            return $this->sendError(400, implode(' / ', $passwordErrors));
        if ($pass !== $confirmPass)
            return $this->sendError(400, 'تێپەڕەوشەکان یەک ناگرنەوە — دووبارە بنووسە');

        // Security fix: the initial send had no rate limit at all — unlike
        // resendRegistrationOTP() (3 per 10 min), this could be called
        // repeatedly for the same unverified email (it upserts via
        // ON DUPLICATE KEY UPDATE below) to spam a victim's inbox or drain
        // the app's SMTP quota. Apply the same DB-backed cap here.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $sendLabel = 'reg_send:' . $email;
        $recentSendsStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE (ip_address = :ip OR email = :label) AND attempted_at >= (NOW() - INTERVAL 10 MINUTE)'
        );
        $recentSendsStmt->execute([':ip' => $ip, ':label' => $sendLabel]);
        if ((int) $recentSendsStmt->fetchColumn() >= 3) {
            return $this->sendError(429, 'زۆر هەوڵ دەدەیت. تکایە ١٠ خولەک چاوەڕێ بکە.');
        }
        $this->pdo->prepare('INSERT INTO login_attempts (ip_address, email, success) VALUES (:ip, :label, 0)')
            ->execute([':ip' => $ip, ':label' => $sendLabel]);

        // پشکنینی ئایا ئیمەیڵ یان تەلەفۆن پێشتر تۆمار کراوە
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1');
        $stmt->execute([':email' => $email, ':phone' => $phone]);
        if ($stmt->fetch())
            return $this->sendError(409, 'ئەم ئیمەیڵ یان تەلەفۆنە پێشتر تۆمار کراوە');

        // دروستکردنی OTP و خەزنکردنی بەکارهێنەر
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $token = bin2hex(random_bytes(32));
        
        // دروستکردنی ئایدی تایبەتی بۆ بەکارهێنەر (User Reference ID)
        $userRefId = 'SH' . str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $stmt = $this->pdo->prepare('
            INSERT INTO users
                (name, phone, email, password, user_ref_id, email_verification_token,
                 registration_otp, registration_otp_expires, email_verified, is_verified, first_login_verified)
            VALUES
                (:name,:phone,:email,:password,:user_ref_id,:token,
                 :otp,:expires,0,0,0)
            ON DUPLICATE KEY UPDATE
                registration_otp = :otp2,
                registration_otp_expires = :expires2
        ');
        $stmt->execute([
            ':name' => $name, ':phone' => $phone, ':email' => $email,
            ':password' => $hash, ':user_ref_id' => $userRefId, ':token' => $token,
            ':otp' => $otp, ':expires' => $expires,
            ':otp2' => $otp, ':expires2' => $expires
        ]);
        $userId = $this->pdo->lastInsertId();

        // ناردنی ئیمەیڵ
        require_once __DIR__ . '/EmailService.php';
        $emailSvc = new EmailService();
        if (!$emailSvc->sendRegistrationOTP($email, $name, $otp)) {
            // ئیمەیڵ نەچوو — بەکارهێنەر بسڕەوە تا دووبارە هەوڵ بدات
            $this->pdo->prepare('DELETE FROM users WHERE email = :email AND email_verified = 0 AND registration_otp IS NOT NULL')->execute([':email' => $email]);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'ئیمەیڵەکە نەگەیشت. تکایە ئیمەیڵەکەت بپشکنە و دووبارە هەوڵ بدەرەوە.']);
            exit;
        }

        // user_id لە سێشندا بخەرە بۆ پشتڕاستکردنەوە
        $_SESSION['pending_reg_user_id'] = (int)($userId ?: $this->getUserIdByEmail($email));
        $_SESSION['pending_reg_email'] = $email;

        $this->sendSuccess(200, 'کۆدی پشتڕاستکردن بۆ ئیمەیڵەکەت نێندرا — تکایە بپشکنە ✉️');
    }

    private function verifyRegistrationOTP() {
        $data = json_decode(file_get_contents('php://input'), true);
        $code = trim($data['code'] ?? '');

        if (!isset($_SESSION['pending_reg_user_id']))
            return $this->sendError(401, 'کاتی دانیشتنت بەسەر چووە — تکایە دووبارە تۆمارکردن بکە');
        if (!preg_match('/^\d{6}$/', $code))
            return $this->sendError(400, 'کۆد دەبێت تەنها ٦ ژمارە بێت');

        $userId = (int)$_SESSION['pending_reg_user_id'];

        // Security fix: this endpoint previously had no brute-force
        // protection — a 6-digit code with unlimited guesses is crackable.
        // Reuse the same DB-backed login_attempts table/pattern that
        // forgot_password_handler.php already uses: lock out after 5 failed
        // attempts within 15 minutes, keyed by IP + a namespaced label so
        // it can't collide with login/reset-password attempt records.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $otpLabel = 'reg_otp:' . $userId;
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE (ip_address = :ip OR email = :label) AND success = 0 AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $stmt->execute([':ip' => $ip, ':label' => $otpLabel]);
        $failedAttempts = (int) $stmt->fetchColumn();

        if ($failedAttempts >= 5) {
            unset($_SESSION['pending_reg_user_id'], $_SESSION['pending_reg_email']);
            return $this->sendError(429, 'زۆرتری ٥ هەوڵی هەڵەت داوە. تکایە دووبارە تۆمارکردن بکە');
        }

        $stmt = $this->pdo->prepare('
            SELECT name, phone, email, registration_otp, registration_otp_expires
            FROM users WHERE id = :id LIMIT 1
        ');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user)
            return $this->sendError(404, 'هەژمارەکە نەدۆزرایەوە — تکایە دووبارە تۆمارکردن بکە');
        if (strtotime($user['registration_otp_expires']) < time())
            return $this->sendError(410, 'کۆدەکە بەسەر چووە ⏰ — لەسەر "دووبارەنێردن" کرتە بکە');
        if (!hash_equals($user['registration_otp'], $code)) {
            $this->pdo->prepare('INSERT INTO login_attempts (ip_address, email, success) VALUES (:ip, :label, 0)')
                ->execute([':ip' => $ip, ':label' => $otpLabel]);
            $attemptsLeft = 5 - ($failedAttempts + 1);
            if ($attemptsLeft <= 0) {
                unset($_SESSION['pending_reg_user_id'], $_SESSION['pending_reg_email']);
                return $this->sendError(429, 'زۆرتری ٥ هەوڵی هەڵەت داوە. تکایە دووبارە تۆمارکردن بکە');
            }
            return $this->sendError(400, "کۆدەکە هەڵەیە — {$attemptsLeft} هەوڵی ماوە");
        }

        // Code correct — clear this label's failure history
        $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :label')->execute([':label' => $otpLabel]);

        // پشتڕاستکردنەوە سەرکەوتوو بوو
        $this->pdo->prepare('
            UPDATE users
            SET email_verified=1, is_verified=1,
                registration_otp=NULL, registration_otp_expires=NULL,
                first_login_verified=1
            WHERE id=:id
        ')->execute([':id' => $userId]);

        // دانیشتن دروست بکە — store raw values; HTML-escaping happens once,
        // at render time (see audit finding #2), the same way setUserSession()
        // already does it for the regular login path.
        $this->setUserSession($userId, $user['name'], $user['phone'], $user['email']);
        unset($_SESSION['pending_reg_user_id'], $_SESSION['pending_reg_email']);

        $this->sendSuccess(200, 'خۆشەویستانە بەخێربێی بۆ شەریک! 🎉 تۆمارکردنت سەرکەوتوو بوو', [
            'redirect' => 'dashboard.php',
            'user' => ['name' => $user['name'], 'email' => $user['email']]
        ]);
    }

    private function resendRegistrationOTP() {
        $input = $this->getJsonInput();
        $email = trim($input['email'] ?? '');

        if (!$email) {
            $this->sendError('ئیمەیڵ داواکراوە');
            return;
        }

        // Rate limit: max 3 resends per 10 minutes per email
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as cnt FROM users
             WHERE email = :email
               AND registration_otp_expires > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
               AND email_verified = 0"
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && $row['cnt'] >= 3) {
            $this->sendError('زۆر هەوڵ دەدەیت. تکایە ١٠ خولەک چاوەڕێ بکە.');
            return;
        }

        // Generate new OTP
        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        $stmt = $this->pdo->prepare(
            "UPDATE users SET registration_otp = :otp, registration_otp_expires = :expires
             WHERE email = :email AND email_verified = 0"
        );
        $stmt->execute([':otp' => $otp, ':expires' => $expires, ':email' => $email]);

        if ($stmt->rowCount() === 0) {
            $this->sendError('ئیمەیڵەکە نەدۆزرایەوە یان پێشتر پشتڕاستکراوە');
            return;
        }

        // Get user name
        $stmt2 = $this->pdo->prepare("SELECT name FROM users WHERE email = :email");
        $stmt2->execute([':email' => $email]);
        $user = $stmt2->fetch(PDO::FETCH_ASSOC);

        require_once __DIR__ . '/EmailService.php';
        $emailSvc = new EmailService();
        if (!$emailSvc->sendRegistrationOTP($email, $user['name'] ?? 'بەکارهێنەر', $otp)) {
            $this->sendError('ناردنی ئیمەیڵ سەرکەوتوو نەبوو. دووبارە هەوڵ بدەرەوە.');
            return;
        }

        $this->sendSuccess(200, 'کۆدی نوێ نێندرا ✓');
    }

    private function getUserIdByEmail($email) {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['id'] : null;
    }

    private function getMyTrips() {
        $userId = $this->requireAuth();
        try {
            $sql = "SELECT t.id, t.departure_city, t.destination_city, t.departure_detail, t.destination_detail,
                           t.waypoints, t.date_time, t.price_iqd, t.seats_available, t.car_model, t.car_color, t.status, t.service_type,
                           u.name AS driver_name, u.phone AS driver_phone
                    FROM trips t
                    INNER JOIN users u ON t.driver_id = u.id
                    WHERE t.driver_id = :user_id
                    ORDER BY t.date_time DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($trips as &$trip) {
                $this->formatTripRow($trip);
                $trip['phone'] = $trip['driver_phone'];
            }
            unset($trip);

            $this->sendSuccess(200, 'گەشتەکانی من گەڕانەوە', $trips);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getMyBookings: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function getMyBookings() {
        $userId = $this->requireAuth();
        try {
            $sql = "SELECT b.id AS booking_id, b.seats_booked, b.created_at AS booking_date,
                           t.id AS trip_id, t.departure_city, t.destination_city, t.departure_detail, t.destination_detail,
                           t.waypoints, t.date_time, t.price_iqd, t.seats_available, t.car_model, t.car_color, t.status, t.service_type,
                           u.name AS driver_name, u.phone AS driver_phone
                    FROM bookings b
                    INNER JOIN trips t ON b.trip_id = t.id
                    INNER JOIN users u ON t.driver_id = u.id
                    WHERE b.passenger_id = :user_id
                    ORDER BY b.created_at DESC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($bookings as &$booking) {
                $this->formatTripRow($booking);
                $booking['booking_date_formatted'] = date('Y/m/d H:i', strtotime($booking['booking_date']));
            }
            unset($booking);

            $this->sendSuccess(200, 'گەشتە داواکراوەکانی من گەڕانەوە', $bookings);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getMyBookings: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function createTrip() {
        $userId = $this->requireAuth();

        // --- Anti-Spam Rate Limiting ---
        try {
            $checkSpam = $this->pdo->prepare("SELECT created_at FROM trips WHERE driver_id = :user_id ORDER BY created_at DESC LIMIT 1");
            $checkSpam->execute([':user_id' => $userId]);
            $lastTrip = $checkSpam->fetch(PDO::FETCH_ASSOC);

            if ($lastTrip && !empty($lastTrip['created_at'])) {
                $lastTripTime = strtotime($lastTrip['created_at']);
                $currentTime = time();
                if (($currentTime - $lastTripTime) < 300) { // 300 seconds = 5 minutes
                    $this->sendError(429, '⚠️ تکایە ٥ خولەک چاوەڕێ بکە پێش پۆستکردنی گەشتێکی نوێ.');
                }
            }
        } catch (PDOException $e) {
            error_log('[SharekAPI] createTrip rate limiting: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
        // -------------------------------

        $input = $this->getJsonInput();

        if (!isset($input['seats_available']) && isset($input['available_seats'])) {
            $input['seats_available'] = $input['available_seats'];
        }

        $required = ['car_model', 'car_color', 'departure_city', 'destination_city', 'date_time', 'price_iqd', 'service_type'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || $input[$field] === '') $this->sendError(400, 'هەموو خانەکان پێویستە پڕ بکرێن');
        }

        $car_model = $this->sanitizeInput($input['car_model']);
        $car_color = $this->sanitizeInput($input['car_color']);
        $departure_city = $this->validateKurdishCity($input['departure_city']);
        $destination_city = $this->validateKurdishCity($input['destination_city']);

        // Validate service_type
        $valid_service_types = ['passenger', 'delivery', 'both'];
        $service_type = $this->sanitizeInput($input['service_type']);
        if (!in_array($service_type, $valid_service_types)) {
            $this->sendError(400, 'جۆری خزمەتگوزاری نادروستە');
        }

        // For delivery-only trips, seats_available is optional and defaults to 0
        if ($service_type === 'delivery') {
            $seats_available = 0;
        } else {
            if (!isset($input['seats_available']) && isset($input['available_seats'])) {
                $input['seats_available'] = $input['available_seats'];
            }
            if (!isset($input['seats_available']) || $input['seats_available'] === '') {
                $this->sendError(400, 'ژمارەی شوێنەکان پێویستە بۆ گەشتی سەرنشین');
            }
            $seats_available = $this->validateSeatsAvailable($input['seats_available'], $service_type);
        }

        $departure_detail = isset($input['departure_detail']) ? $this->sanitizeInput($input['departure_detail']) : '';
        $destination_detail = isset($input['destination_detail']) ? $this->sanitizeInput($input['destination_detail']) : '';
        $waypoints = isset($input['waypoints']) ? $this->sanitizeInput($input['waypoints']) : '';
        $via_cities = isset($input['via_cities']) ? $this->sanitizeInput($input['via_cities']) : '';
        $latitude = isset($input['latitude']) && $input['latitude'] !== '' ? (float) $input['latitude'] : null;
        $longitude = isset($input['longitude']) && $input['longitude'] !== '' ? (float) $input['longitude'] : null;
        $date_time = $this->validateDateTimeFormat($input['date_time']);
        $price_iqd = $this->validatePriceIqd($input['price_iqd']);
        $has_ac = !empty($input['has_ac']) ? 1 : 0;
        $allows_smoking = !empty($input['allows_smoking']) ? 1 : 0;
        $allows_pets = !empty($input['allows_pets']) ? 1 : 0;
        $music_allowed = !empty($input['music_allowed']) ? 1 : 0;
        $is_ladies_only = !empty($input['is_ladies_only']) ? 1 : 0;

        // Featured placement is an admin-controlled / future paid-promotion feature.
        // Users cannot set is_featured=1 during trip creation. Only admins can feature trips.
        $is_featured = 0;

        if ($departure_city === $destination_city) $this->sendError(400, 'ناتوانیت هەمان شوێن بۆ بەڕێکەوتن و گەیشتن دەستنیشان بکەیت!');
        if ($latitude !== null && ($latitude < -90 || $latitude > 90)) $this->sendError(400, 'پانی نادروستە');
        if ($longitude !== null && ($longitude < -180 || $longitude > 180)) $this->sendError(400, 'درێژی نادروستە');

        try {
            // Featured placement is an admin-controlled / future paid-promotion feature.
            // platform_fee column is reserved for future commission/payment integration and must
            // remain 0 until a payment gateway is implemented — do not set it from user input.
            $sql = "INSERT INTO trips (
                        driver_id, departure_city, destination_city, departure_detail, destination_detail,
                        waypoints, via_cities, latitude, longitude, car_model, car_color,
                        has_ac, allows_smoking, allows_pets, music_allowed, is_ladies_only, is_featured,
                        platform_fee, date_time, price_iqd, seats_available, service_type, status
                    ) VALUES (
                        :driver_id, :departure_city, :destination_city, :departure_detail, :destination_detail,
                        :waypoints, :via_cities, :latitude, :longitude, :car_model, :car_color,
                        :has_ac, :allows_smoking, :allows_pets, :music_allowed, :is_ladies_only, :is_featured,
                        0, :date_time, :price_iqd, :seats_available, :service_type, 'active'
                    )";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':driver_id' => $userId, ':departure_city' => $departure_city, ':destination_city' => $destination_city,
                ':departure_detail' => $departure_detail, ':destination_detail' => $destination_detail, ':waypoints' => $waypoints !== '' ? $waypoints : null, ':via_cities' => $via_cities !== '' ? $via_cities : null,
                ':latitude' => $latitude, ':longitude' => $longitude, ':car_model' => $car_model, ':car_color' => $car_color,
                ':has_ac' => $has_ac, ':allows_smoking' => $allows_smoking, ':allows_pets' => $allows_pets, ':music_allowed' => $music_allowed,
                ':is_ladies_only' => $is_ladies_only, ':is_featured' => $is_featured, ':date_time' => $date_time, ':price_iqd' => $price_iqd,
                ':seats_available' => $seats_available, ':service_type' => $service_type,
            ]);

            $this->sendSuccess(201, 'گەشتەکە بەسەرکەوتوویی تۆمارکرا', ['trip_id' => $this->pdo->lastInsertId()]);
        } catch (PDOException $e) {
            error_log('[SharekAPI] createTrip: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function deleteTrip() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();
        $trip_id = isset($input['trip_id']) ? (int) $input['trip_id'] : 0;

        if ($trip_id < 1) $this->sendError(400, 'ناسنامەی گەشت پێویستە');

        try {
            $this->pdo->beginTransaction();
            $check = $this->pdo->prepare('SELECT id, driver_id FROM trips WHERE id = :trip_id FOR UPDATE');
            $check->execute([':trip_id' => $trip_id]);
            $trip = $check->fetch(PDO::FETCH_ASSOC);

            if (!$trip) {
                $this->pdo->rollBack();
                $this->sendError(404, 'گەشتەکە نەدۆزرایەوە');
            }
            if ((int) $trip['driver_id'] !== (int) $userId) {
                $this->pdo->rollBack();
                $this->sendError(403, 'تۆ ڕێگایت نیە ئەم گەشتە بسڕیتەوە');
            }

            $del = $this->pdo->prepare('DELETE FROM trips WHERE id = :trip_id');
            $del->execute([':trip_id' => $trip_id]);

            $this->pdo->commit();
            $this->sendSuccess(200, 'گەشتەکە بەسەرکەوتوویی سڕایەوە');
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[SharekAPI] deleteTrip: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function cancelTrip() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();
        $trip_id = isset($input['trip_id']) ? (int) $input['trip_id'] : 0;

        if ($trip_id < 1) $this->sendError(400, 'ناسنامەی گەشت پێویستە');

        try {
            $this->pdo->beginTransaction();
            $check = $this->pdo->prepare('SELECT id, driver_id, status, departure_city, destination_city, date_time FROM trips WHERE id = :trip_id FOR UPDATE');
            $check->execute([':trip_id' => $trip_id]);
            $trip = $check->fetch(PDO::FETCH_ASSOC);

            if (!$trip) {
                $this->pdo->rollBack();
                $this->sendError(404, 'گەشتەکە نەدۆزرایەوە');
            }
            if ((int) $trip['driver_id'] !== (int) $userId) {
                $this->pdo->rollBack();
                $this->sendError(403, 'تۆ ڕێگایت نیە ئەم گەشتە هەڵوەشەیتەوە');
            }
            if ($trip['status'] === 'cancelled') {
                $this->pdo->rollBack();
                $this->sendError(400, 'گەشتەکە هەر لەسەرەوە هەڵوەشێنراوە');
            }

            // Check if there are any bookings for this trip
            $bookingCheck = $this->pdo->prepare('SELECT COUNT(*) as booking_count FROM bookings WHERE trip_id = :trip_id AND status = "confirmed"');
            $bookingCheck->execute([':trip_id' => $trip_id]);
            $bookingCount = $bookingCheck->fetch(PDO::FETCH_ASSOC);

            if ((int) $bookingCount['booking_count'] > 0) {
                $this->pdo->rollBack();
                $this->sendError(400, 'ناتوانیت گەشتەکە هەڵوەشێنیت چونکە کەسێک دایگرت کردووە');
            }

            $update = $this->pdo->prepare('UPDATE trips SET status = :status WHERE id = :trip_id');
            $update->execute([':status' => 'cancelled', ':trip_id' => $trip_id]);

            $this->pdo->commit();
            $this->sendSuccess(200, 'گەشتەکە بەسەرکەوتوویی هەڵوەشایەوە');
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[SharekAPI] cancelTrip: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function editTrip() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();
        $trip_id = isset($input['trip_id']) ? (int) $input['trip_id'] : 0;

        if ($trip_id < 1) $this->sendError(400, 'ناسنامەی گەشت پێویستە');

        try {
            $this->pdo->beginTransaction();
            $check = $this->pdo->prepare('SELECT id, driver_id, status, departure_city, destination_city, date_time, service_type, seats_available, price_iqd FROM trips WHERE id = :trip_id FOR UPDATE');
            $check->execute([':trip_id' => $trip_id]);
            $trip = $check->fetch(PDO::FETCH_ASSOC);

            if (!$trip) {
                $this->pdo->rollBack();
                $this->sendError(404, 'گەشتەکە نەدۆزرایەوە');
            }
            if ((int) $trip['driver_id'] !== (int) $userId) {
                $this->pdo->rollBack();
                $this->sendError(403, 'تۆ ڕێگایت نیە ئەم گەشتە دەستکاری بکەیت');
            }
            if ($trip['status'] === 'cancelled' || $trip['status'] === 'completed') {
                $this->pdo->rollBack();
                $this->sendError(400, 'ناتوانیت گەشتی هەڵوەشێنراو یان تەواوبوو دەستکاری بکەیت');
            }

            // Check if there are any bookings for this trip
            $bookingCheck = $this->pdo->prepare('SELECT COUNT(*) as booking_count FROM bookings WHERE trip_id = :trip_id AND status = "confirmed"');
            $bookingCheck->execute([':trip_id' => $trip_id]);
            $bookingCount = $bookingCheck->fetch(PDO::FETCH_ASSOC);

            if ((int) $bookingCount['booking_count'] > 0) {
                $this->pdo->rollBack();
                $this->sendError(400, 'ناتوانیت گەشتەکە دەستکاری بکەیت چونکە کەسێک دایگرت کردووە');
            }

            // Validate fields that are present in input using shared helpers
            $departure_city = isset($input['departure_city']) ? $this->validateKurdishCity($input['departure_city']) : $trip['departure_city'];
            $destination_city = isset($input['destination_city']) ? $this->validateKurdishCity($input['destination_city']) : $trip['destination_city'];
            $departure_detail = isset($input['departure_detail']) ? $this->sanitizeInput($input['departure_detail']) : null;
            $destination_detail = isset($input['destination_detail']) ? $this->sanitizeInput($input['destination_detail']) : null;
            $date_time = isset($input['date_time']) ? $this->validateDateTimeFormat($input['date_time']) : null;
            $price_iqd = isset($input['price_iqd']) ? $this->validatePriceIqd($input['price_iqd']) : null;
            $seats_available = isset($input['seats_available']) ? $this->validateSeatsAvailable($input['seats_available'], $trip['service_type']) : null;

            // Final guard: ensure departure_city !== destination_city for the resulting row
            if ($departure_city === $destination_city) {
                $this->pdo->rollBack();
                $this->sendError(400, 'ناتوانیت هەمان شوێن بۆ بەڕێکەوتن و گەیشتن دەستنیشان بکەیت!');
            }

            // Build update query dynamically
            $updateFields = [];
            $updateParams = [':trip_id' => $trip_id];

            if (isset($input['departure_city'])) {
                $updateFields[] = 'departure_city = :departure_city';
                $updateParams[':departure_city'] = $departure_city;
            }
            if (isset($input['destination_city'])) {
                $updateFields[] = 'destination_city = :destination_city';
                $updateParams[':destination_city'] = $destination_city;
            }
            if ($departure_detail !== null) {
                $updateFields[] = 'departure_detail = :departure_detail';
                $updateParams[':departure_detail'] = $departure_detail;
            }
            if ($destination_detail !== null) {
                $updateFields[] = 'destination_detail = :destination_detail';
                $updateParams[':destination_detail'] = $destination_detail;
            }
            if ($date_time !== null) {
                $updateFields[] = 'date_time = :date_time';
                $updateParams[':date_time'] = $date_time;
            }
            if ($price_iqd !== null) {
                $updateFields[] = 'price_iqd = :price_iqd';
                $updateParams[':price_iqd'] = $price_iqd;
            }
            if ($seats_available !== null) {
                $updateFields[] = 'seats_available = :seats_available';
                $updateParams[':seats_available'] = $seats_available;
            }

            if (empty($updateFields)) {
                $this->pdo->rollBack();
                $this->sendError(400, 'هیچ زانیارییەک بۆ نوێکردنەوە نەدراوە');
            }

            $updateFields[] = 'updated_at = NOW()';
            $updateSql = 'UPDATE trips SET ' . implode(', ', $updateFields) . ' WHERE id = :trip_id';
            $update = $this->pdo->prepare($updateSql);
            $update->execute($updateParams);

            $this->pdo->commit();
            $this->sendSuccess(200, 'گەشتەکە بەسەرکەوتوویی نوێ کرا');
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[SharekAPI] editTrip: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function bookSeat() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();
        $trip_id = isset($input['trip_id']) ? (int) $input['trip_id'] : 0;
        $seats_requested = isset($input['seats_requested']) ? (int) $input['seats_requested'] : 1;

        if ($trip_id < 1) $this->sendError(400, 'ناسنامەی گەشت پێویستە');
        if ($seats_requested < 1 || $seats_requested > 10) $this->sendError(400, 'ژمارەی شوێنەکان دەبێت لەنێوان 1 و 10 بێت');

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                "SELECT t.id, t.driver_id, t.seats_available, u.phone AS driver_phone, u.name AS driver_name, u.vehicle_status
                 FROM trips t
                 INNER JOIN users u ON t.driver_id = u.id
                 WHERE t.id = :trip_id AND t.status = 'active' AND t.date_time >= NOW() AND t.seats_available > 0 FOR UPDATE"
            );
            $stmt->execute([':trip_id' => $trip_id]);
            $trip = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$trip) {
                $this->pdo->rollBack();
                $this->sendError(400, 'گەشتەکە نەدۆزرایەوە یان چالاک نیە');
            }
            if ((int) $trip['driver_id'] === (int) $userId) {
                $this->pdo->rollBack();
                $this->sendError(400, 'ناتوانیت شوێن لە گەشتی خۆتت داگیر بکەیت');
            }
            if ((int) $trip['seats_available'] < $seats_requested) {
                $this->pdo->rollBack();
                $this->sendError(400, 'شوێنی پێویست لە گەشتەکەدا نیە');
            }
            
            // Vehicle availability check - prevent double booking
            if ($trip['vehicle_status'] !== 'available') {
                $this->pdo->rollBack();
                $this->sendError(400, 'ئۆتۆمبێلەکە لە ئامادەدا نییە لە ئێستادا');
            }

            $update = $this->pdo->prepare('UPDATE trips SET seats_available = seats_available - :seats_requested WHERE id = :trip_id AND seats_available >= :seats_check');
            $update->execute([':seats_requested' => $seats_requested, ':trip_id' => $trip_id, ':seats_check' => $seats_requested]);

            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                $this->sendError(400, 'شوێنی پێویست لە گەشتەکەدا نیە');
            }

            // Check if seats_available has reached 0, then update trip status to 'full'
            $remainingSeats = (int) $trip['seats_available'] - $seats_requested;
            if ($remainingSeats === 0) {
                $statusUpdate = $this->pdo->prepare('UPDATE trips SET status = :status WHERE id = :trip_id');
                $statusUpdate->execute([':status' => 'full', ':trip_id' => $trip_id]);
            }

            $booking = $this->pdo->prepare('INSERT INTO bookings (passenger_id, trip_id, seats_booked, status) VALUES (:passenger_id, :trip_id, :seats_booked, :status)');
            $booking->execute([':passenger_id' => $userId, ':trip_id' => $trip_id, ':seats_booked' => $seats_requested, ':status' => 'confirmed']);

            $this->pdo->commit();
            $this->sendSuccess(200, 'کورسی بەسەرکەوتوویی داگیرکرا', [
                'driver_phone' => $trip['driver_phone'],
                'driver_name' => $trip['driver_name'],
                'seats_booked' => $seats_requested,
                'seats_remaining' => (int) $trip['seats_available'] - $seats_requested,
            ]);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[SharekAPI] bookSeat: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // SMART TRIP ALERTS: Subscribe to City Notifications
    // ============================================================================
    private function subscribe() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();

        // Validate required fields
        if (!isset($input['city']) || $input['city'] === '') {
            $this->sendError(400, 'شار پێویستە');
        }

        $city = $this->sanitizeInput($input['city']);

        // Validate city against whitelist
        if (!in_array($city, self::VALID_KURDISH_CITIES)) {
            $this->sendError(400, 'شارێکی نادروستە');
        }

        try {
            // Check if subscription already exists
            $check = $this->pdo->prepare("SELECT id, is_active FROM trip_subscriptions WHERE user_id = :user_id AND city = :city LIMIT 1");
            $check->execute([':user_id' => $userId, ':city' => $city]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ((int) $existing['is_active'] === 1) {
                    $this->sendError(400, 'تۆ پێشتر بەم شارەوە تۆمار بوویت');
                } else {
                    // Reactivate subscription
                    $update = $this->pdo->prepare("UPDATE trip_subscriptions SET is_active = 1 WHERE id = :id");
                    $update->execute([':id' => $existing['id']]);
                    $this->sendSuccess(200, 'بەشدارییەکە چالاک کراوە');
                }
            } else {
                // Create new subscription
                $insert = $this->pdo->prepare("INSERT INTO trip_subscriptions (user_id, city, is_active) VALUES (:user_id, :city, 1)");
                $insert->execute([':user_id' => $userId, ':city' => $city]);
                $this->sendSuccess(201, 'بەشدارییەکە تۆمارکرا');
            }
        } catch (PDOException $e) {
            error_log('[SharekAPI] subscribe: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // SMART TRIP ALERTS: Notify Subscribers of New Trips
    // ============================================================================
    private function notifySubscribers() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();

        // Validate required field
        if (!isset($input['trip_id']) || $input['trip_id'] === '') {
            $this->sendError(400, 'ناسنامەی گەشت پێویستە');
        }

        $tripId = (int) $input['trip_id'];

        try {
            // Fetch trip and verify ownership
            $stmt = $this->pdo->prepare("
                SELECT id, driver_id, departure_city, destination_city, date_time, departure_detail, destination_detail
                FROM trips
                WHERE id = :trip_id
                LIMIT 1
            ");
            $stmt->execute([':trip_id' => $tripId]);
            $trip = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$trip) {
                $this->sendError(404, 'گەشت نەدۆزرایەوە');
            }

            if ((int) $trip['driver_id'] !== $userId) {
                $this->sendError(403, 'تۆ ڕێگایت نیە ئاگادارکردنەوە بنێری بۆ ئەم گەشتە');
            }

            // Derive departure_city from trip instead of trusting client input
            $departure_city = $trip['departure_city'];
            $destination_city = $trip['destination_city'];
            $date_time = $trip['date_time'];

            // Build trip details server-side from verified trip data
            $trip_details = sprintf(
                'گەشت لە %s بۆ %s - کاتی: %s',
                $departure_city,
                $destination_city,
                date('Y/m/d H:i', strtotime($date_time))
            );

            // Add optional notes if they exist
            if (!empty($trip['departure_detail'])) {
                $trip_details .= sprintf(' - شوێن: %s', $trip['departure_detail']);
            }
            if (!empty($trip['destination_detail'])) {
                $trip_details .= sprintf(' بۆ %s', $trip['destination_detail']);
            }

            // Find all active subscribers for this city with FCM tokens
            $subStmt = $this->pdo->prepare("
                SELECT u.id, u.name, u.phone, u.fcm_token
                FROM trip_subscriptions ts
                INNER JOIN users u ON ts.user_id = u.id
                WHERE ts.city = :city AND ts.is_active = 1 AND ts.user_id != :user_id AND u.fcm_token IS NOT NULL
            ");
            $subStmt->execute([':city' => $departure_city, ':user_id' => $userId]);
            $subscribers = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($subscribers)) {
                $this->sendSuccess(200, 'هیچ بەشدارێک نەدۆزرایەوە', ['count' => 0]);
            }

            // Send FCM notifications
            $notificationData = [
                'title' => 'گەشتی نوێ لە ' . $departure_city,
                'body' => $trip_details,
                'city' => $departure_city
            ];

            $fcmTokens = array_column($subscribers, 'fcm_token');
            $this->sendFcmNotifications($fcmTokens, $notificationData);

            $this->sendSuccess(200, 'ئاگادارکردنەوەکان نێردران', [
                'count' => count($subscribers),
                'notified' => count($fcmTokens)
            ]);
        } catch (PDOException $e) {
            error_log('[SharekAPI] notifySubscribers: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // FCM INTEGRATION: Save FCM Token
    // ============================================================================
    private function saveFcmToken() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();

        if (!isset($input['fcm_token']) || $input['fcm_token'] === '') {
            $this->sendError(400, 'FCM Token پێویستە');
        }

        $fcm_token = $this->sanitizeInput($input['fcm_token']);

        try {
            $stmt = $this->pdo->prepare("UPDATE users SET fcm_token = :fcm_token WHERE id = :user_id");
            $stmt->execute([':fcm_token' => $fcm_token, ':user_id' => $userId]);
            $this->sendSuccess(200, 'FCM Token خەزن کرا');
        } catch (PDOException $e) {
            error_log('[SharekAPI] saveFcmToken: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // FCM INTEGRATION: Send Push Notifications
    // ============================================================================
    private function sendFcmNotifications($tokens, $data) {
        // FCM Server Key should be stored in environment variable or config
        $fcmServerKey = getenv('FCM_SERVER_KEY') ?: '';
        
        if (empty($fcmServerKey) || empty($tokens)) {
            return;
        }

        $notification = [
            'title' => $data['title'],
            'body' => $data['body'],
            'sound' => 'default'
        ];

        $payload = [
            'registration_ids' => $tokens,
            'notification' => $notification,
            'data' => $data
        ];

        $headers = [
            'Authorization: key=' . $fcmServerKey,
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $response = curl_exec($ch);
        curl_close($ch);

        // Log FCM response for debugging (in production, use proper logging)
        error_log('FCM Response: ' . $response);
    }

    // ============================================================================
    // DYNAMIC ETA LEARNING: Record Trip Completion
    // ============================================================================
    private function recordTripCompletion() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();

        if (!isset($input['trip_id']) || !isset($input['duration_minutes'])) {
            $this->sendError(400, 'ناسنامەی گەشت و ماوەی گەشت پێویستە');
        }

        $tripId = (int) $input['trip_id'];
        $durationMinutes = (int) $input['duration_minutes'];

        if ($durationMinutes < 1 || $durationMinutes > 1440) {
            $this->sendError(400, 'ماوەی گەشت دەبێت لەنێوان ١ بۆ ١٤٤٠ خولەک بێت');
        }

        try {
            $this->pdo->beginTransaction();

            // Get trip details with date_time, status, and eta_recorded
            $stmt = $this->pdo->prepare("SELECT driver_id, departure_city, destination_city, date_time, status, eta_recorded FROM trips WHERE id = :trip_id LIMIT 1 FOR UPDATE");
            $stmt->execute([':trip_id' => $tripId]);
            $trip = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$trip) {
                $this->pdo->rollBack();
                $this->sendError(404, 'گەشت نەدۆزرایەوە');
            }

            if ((int) $trip['driver_id'] !== $userId) {
                $this->pdo->rollBack();
                $this->sendError(403, 'تۆ ڕێگایت نیە ئەم گەشتە تۆمار بکەیت');
            }

            // Check if trip date_time is in the future
            if ($trip['date_time'] > date('Y-m-d H:i:s')) {
                $this->pdo->rollBack();
                $this->sendError(400, 'ناتوانیت گەشتێک تۆمار بکەیت پێش کاتی گەشتەکە');
            }

            // Check if eta_recorded is already 1 (prevents duplicate farming)
            if ((int) $trip['eta_recorded'] === 1) {
                $this->pdo->rollBack();
                $this->sendError(400, 'ماوەی ئەم گەشتە پێشتر تۆمارکراوە');
            }

            // Plausibility check: duration must be consistent with elapsed time + 3 hour grace window
            $tripDateTime = new DateTime($trip['date_time']);
            $now = new DateTime();
            $elapsedMinutes = ($now->getTimestamp() - $tripDateTime->getTimestamp()) / 60;
            $maxAllowedDuration = $elapsedMinutes + 180; // 3 hour grace window

            if ($durationMinutes > $maxAllowedDuration) {
                $this->pdo->rollBack();
                $this->sendError(400, 'ماوەی دیاریکراو ناتەباوە بۆپێچەکەی کاتی گەشتەکە');
            }

            $departureCity = $trip['departure_city'];
            $destinationCity = $trip['destination_city'];

            // Check if route exists
            $checkStmt = $this->pdo->prepare("SELECT id, total_trips, total_duration_minutes FROM route_eta WHERE departure_city = :departure_city AND destination_city = :destination_city LIMIT 1 FOR UPDATE");
            $checkStmt->execute([':departure_city' => $departureCity, ':destination_city' => $destinationCity]);
            $routeEta = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($routeEta) {
                // Update existing route
                $newTotalTrips = (int) $routeEta['total_trips'] + 1;
                $newTotalDuration = (int) $routeEta['total_duration_minutes'] + $durationMinutes;
                $newAvg = $newTotalDuration / $newTotalTrips;

                $updateStmt = $this->pdo->prepare("
                    UPDATE route_eta
                    SET total_trips = :total_trips,
                        total_duration_minutes = :total_duration,
                        avg_duration_minutes = :avg_duration
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':total_trips' => $newTotalTrips,
                    ':total_duration' => $newTotalDuration,
                    ':avg_duration' => $newAvg,
                    ':id' => $routeEta['id']
                ]);
            } else {
                // Create new route
                $insertStmt = $this->pdo->prepare("
                    INSERT INTO route_eta (departure_city, destination_city, total_trips, total_duration_minutes, avg_duration_minutes)
                    VALUES (:departure_city, :destination_city, 1, :duration, :avg_duration)
                ");
                $insertStmt->execute([
                    ':departure_city' => $departureCity,
                    ':destination_city' => $destinationCity,
                    ':duration' => $durationMinutes,
                    ':avg_duration' => $durationMinutes
                ]);
            }

            // Award points to driver based on trip duration
            $pointsToAward = $this->calculatePointsFromDuration($durationMinutes);
            $this->awardPoints($userId, $pointsToAward);

            // Award points to passengers who completed this trip
            $this->awardPointsToPassengers($tripId, $pointsToAward);

            // Mark trip as completed and eta_recorded as 1
            $updateTripStmt = $this->pdo->prepare("UPDATE trips SET status = 'completed', eta_recorded = 1 WHERE id = :trip_id");
            $updateTripStmt->execute([':trip_id' => $tripId]);

            $this->pdo->commit();
            $this->sendSuccess(200, 'ماوەی گەشت تۆمارکرا و خاڵ وەرگیرا');
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[SharekAPI] recordTripCompletion: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // GAMIFICATION: Calculate Points from Trip Duration
    // ============================================================================
    private function calculatePointsFromDuration($durationMinutes) {
        // Award 1 point per 10 minutes of travel
        return (int) floor($durationMinutes / 10);
    }

    // ============================================================================
    // GAMIFICATION: Award Points to User
    // ============================================================================
    // NOTE: This method does NOT manage its own transaction. It participates in
    // whatever transaction the caller has already opened (e.g. recordTripCompletion).
    // Starting a nested beginTransaction() in MySQL causes a silent auto-commit
    // of the outer transaction — a data-corruption risk that this fix prevents.
    private function awardPoints($userId, $points) {
        if ($points < 1) return;

        // Update user points
        $updateStmt = $this->pdo->prepare(
            'UPDATE users SET points = points + :points WHERE id = :user_id'
        );
        $updateStmt->execute([':points' => $points, ':user_id' => $userId]);

        // Get new points total and update level atomically
        $selectStmt = $this->pdo->prepare('SELECT points FROM users WHERE id = :user_id');
        $selectStmt->execute([':user_id' => $userId]);
        $user = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $newPoints = (int) $user['points'];
            $newLevel = $this->calculateLevel($newPoints);

            $levelStmt = $this->pdo->prepare(
                'UPDATE users SET level = :level WHERE id = :user_id'
            );
            $levelStmt->execute([':level' => $newLevel, ':user_id' => $userId]);
        }
    }

    // ============================================================================
    // GAMIFICATION: Calculate User Level
    // ============================================================================
    private function calculateLevel($points) {
        if ($points >= 500) return 'Gold';
        if ($points >= 200) return 'Silver';
        return 'Bronze';
    }

    // ============================================================================
    // GAMIFICATION: Award Points to Passengers
    // ============================================================================
    private function awardPointsToPassengers($tripId, $points) {
        try {
            // Get all confirmed bookings for this trip
            $stmt = $this->pdo->prepare(
                'SELECT passenger_id FROM bookings WHERE trip_id = :trip_id AND status = "completed"'
            );
            $stmt->execute([':trip_id' => $tripId]);
            $passengers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($passengers as $passenger) {
                $this->awardPoints((int) $passenger['passenger_id'], $points);
            }
        } catch (PDOException $e) {
            // Log error but don't fail the main transaction
            error_log('[SharekAPI] awardPointsToPassengers: ' . $e->getMessage());
        }
    }

    // ============================================================================
    // DYNAMIC ETA LEARNING: Get Route ETA
    // ============================================================================
    private function getRouteEta() {
        $input = $this->getJsonInput();

        if (!isset($input['departure_city']) || !isset($input['destination_city'])) {
            $this->sendError(400, 'شاری دەستپێکردن و گەیشتن پێویستە');
        }

        $departureCity = $this->sanitizeInput($input['departure_city']);
        $destinationCity = $this->sanitizeInput($input['destination_city']);

        try {
            $stmt = $this->pdo->prepare("
                SELECT total_trips, avg_duration_minutes 
                FROM route_eta 
                WHERE departure_city = :departure_city AND destination_city = :destination_city 
                LIMIT 1
            ");
            $stmt->execute([':departure_city' => $departureCity, ':destination_city' => $destinationCity]);
            $routeEta = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$routeEta || (int) $routeEta['total_trips'] < 5) {
                // Not enough data for estimation
                $this->sendSuccess(200, 'چاوەڕوانی فێربوون', [
                    'is_learning' => true,
                    'total_trips' => $routeEta ? (int) $routeEta['total_trips'] : 0,
                    'required_trips' => 5,
                    'eta_minutes' => null,
                    'message' => 'Learning...'
                ]);
            }

            $this->sendSuccess(200, 'خەمڵاندنی کات گەڕانەوە', [
                'is_learning' => false,
                'total_trips' => (int) $routeEta['total_trips'],
                'eta_minutes' => (float) $routeEta['avg_duration_minutes'],
                'message' => round((float) $routeEta['avg_duration_minutes']) . ' خولەک'
            ]);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getRouteEta: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }


    // ============================================================================
    // RATING & REVIEWS: Get Driver Reviews
    // ============================================================================
    private function getDriverReviews() {
        if (!isset($_GET['driver_id'])) {
            $this->sendError(400, 'ناسنامەی شۆفێر پێویستە');
        }

        $driverId = (int) $_GET['driver_id'];

        try {
            $stmt = $this->pdo->prepare("
                SELECT r.rating, r.comment, r.created_at,
                       u.name AS passenger_name
                FROM reviews r
                INNER JOIN users u ON r.passenger_id = u.id
                WHERE r.driver_id = :driver_id
                ORDER BY r.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([':driver_id' => $driverId]);
            $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reviews as &$review) {
                $review['created_at_formatted'] = date('Y/m/d H:i', strtotime($review['created_at']));
                $review['passenger_name'] = htmlspecialchars($review['passenger_name'], ENT_QUOTES, 'UTF-8');
                $review['comment'] = htmlspecialchars($review['comment'], ENT_QUOTES, 'UTF-8');
            }
            unset($review);

            $this->sendSuccess(200, 'هەڵسەنگاندنەکان گەڕانەوە', $reviews);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getDriverReviews: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // MAP SYSTEM: Get Available Trips for Map Display
    // ============================================================================
    private function getMapTrips() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT t.id, t.departure_city, t.destination_city, t.departure_detail, t.destination_detail,
                       t.latitude, t.longitude, t.date_time, t.price_iqd, t.seats_available, t.car_model, t.car_color,
                       u.name AS driver_name, u.phone AS driver_phone, u.vehicle_status
                FROM trips t
                INNER JOIN users u ON t.driver_id = u.id
                WHERE t.status = 'active' 
                AND t.date_time >= NOW() 
                AND t.seats_available > 0 
                AND t.latitude IS NOT NULL 
                AND t.longitude IS NOT NULL
                AND u.vehicle_status = 'available'
                ORDER BY t.created_at DESC
                LIMIT 100
            ");
            $stmt->execute();
            $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($trips as &$trip) {
                $trip['price_formatted'] = number_format((float) $trip['price_iqd'], 0) . ' د.ع';
                $trip['date_formatted'] = date('Y/m/d H:i', strtotime($trip['date_time']));
                $trip['driver_name'] = htmlspecialchars($trip['driver_name'], ENT_QUOTES, 'UTF-8');
                $trip['departure_city'] = htmlspecialchars($trip['departure_city'], ENT_QUOTES, 'UTF-8');
                $trip['destination_city'] = htmlspecialchars($trip['destination_city'], ENT_QUOTES, 'UTF-8');
                $trip['departure_detail'] = htmlspecialchars($trip['departure_detail'], ENT_QUOTES, 'UTF-8');
                $trip['destination_detail'] = htmlspecialchars($trip['destination_detail'], ENT_QUOTES, 'UTF-8');
                $trip['car_model'] = htmlspecialchars($trip['car_model'], ENT_QUOTES, 'UTF-8');
                $trip['car_color'] = htmlspecialchars($trip['car_color'], ENT_QUOTES, 'UTF-8');
            }
            unset($trip);

            $this->sendSuccess(200, 'گەشتەکان بۆ نەخشە گەڕانەوە', $trips);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getMapTrips: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // FAVORITE ROUTES: Save Route
    // ============================================================================
    private function saveRoute() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();

        $startPoint = isset($input['start_point']) ? $this->sanitizeInput($input['start_point']) : '';
        $endPoint = isset($input['end_point']) ? $this->sanitizeInput($input['end_point']) : '';
        $routeName = isset($input['route_name']) ? $this->sanitizeInput($input['route_name']) : '';

        if (empty($startPoint) || empty($endPoint) || empty($routeName)) {
            $this->sendError(400, 'خاڵی دەستپێک، خاڵی گەیشتن و ناوی ڕێگا پێویستە');
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO saved_routes (user_id, start_point, end_point, route_name) 
                 VALUES (:user_id, :start_point, :end_point, :route_name)'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':start_point' => $startPoint,
                ':end_point' => $endPoint,
                ':route_name' => $routeName
            ]);

            $this->sendSuccess(201, 'ڕێگاکە پاشەکەوترا');
        } catch (PDOException $e) {
            error_log('[SharekAPI] saveRoute: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // FAVORITE ROUTES: Delete Route
    // ============================================================================
    private function deleteRoute() {
        $userId = $this->requireAuth();
        $input = $this->getJsonInput();

        $routeId = isset($input['route_id']) ? (int) $input['route_id'] : 0;

        if ($routeId < 1) {
            $this->sendError(400, 'ناسنامەی ڕێگا پێویستە');
        }

        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM saved_routes WHERE id = :route_id AND user_id = :user_id'
            );
            $stmt->execute([':route_id' => $routeId, ':user_id' => $userId]);

            if ($stmt->rowCount() === 0) {
                $this->sendError(404, 'ڕێگاکە نەدۆزرایەوە');
            }

            $this->sendSuccess(200, 'ڕێگاکە سڕایەوە');
        } catch (PDOException $e) {
            error_log('[SharekAPI] deleteRoute: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // FAVORITE ROUTES: Get Saved Routes
    // ============================================================================
    private function getSavedRoutes() {
        $userId = $this->requireAuth();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, start_point, end_point, route_name, created_at 
                 FROM saved_routes 
                 WHERE user_id = :user_id 
                 ORDER BY created_at DESC'
            );
            $stmt->execute([':user_id' => $userId]);
            $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($routes as &$route) {
                $route['start_point'] = htmlspecialchars($route['start_point'], ENT_QUOTES, 'UTF-8');
                $route['end_point'] = htmlspecialchars($route['end_point'], ENT_QUOTES, 'UTF-8');
                $route['route_name'] = htmlspecialchars($route['route_name'], ENT_QUOTES, 'UTF-8');
            }
            unset($route);

            $this->sendSuccess(200, 'ڕێگای پاشەکەوتراوەکان گەڕانەوە', $routes);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getSavedRoutes: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // PROXIMITY: Search Nearby Drivers using Haversine Formula
    // ============================================================================
    private function searchNearbyDrivers() {
        $latitude = isset($_GET['latitude']) ? (float) $_GET['latitude'] : null;
        $longitude = isset($_GET['longitude']) ? (float) $_GET['longitude'] : null;
        $radiusKm = isset($_GET['radius_km']) ? (float) $_GET['radius_km'] : 5.0;

        if ($latitude === null || $longitude === null) {
            $this->sendError(400, 'پانی و درێژی پێویستە');
        }

        try {
            // Haversine formula: (6371 * acos(cos(radians(lat1)) * cos(radians(lat2)) * cos(radians(lon2) - radians(lon1)) + sin(radians(lat1)) * sin(radians(lat2))))
            $sql = "
                SELECT t.id, t.departure_city, t.destination_city, t.departure_detail, t.destination_detail,
                       t.latitude, t.longitude, t.date_time, t.price_iqd, t.seats_available, t.car_model, t.car_color,
                       u.name AS driver_name, u.phone AS driver_phone, u.vehicle_status,
                       (6371 * acos(
                           cos(radians(:lat1)) * cos(radians(t.latitude)) * 
                           cos(radians(t.longitude) - radians(:lon1)) + 
                           sin(radians(:lat1)) * sin(radians(t.latitude))
                       )) AS distance_km
                FROM trips t
                INNER JOIN users u ON t.driver_id = u.id
                WHERE t.status = 'active' 
                AND t.date_time >= NOW() 
                AND t.seats_available > 0 
                AND t.latitude IS NOT NULL 
                AND t.longitude IS NOT NULL
                AND u.vehicle_status = 'available'
                HAVING distance_km <= :radius_km
                ORDER BY distance_km ASC, t.date_time ASC
                LIMIT 50
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':lat1' => $latitude,
                ':lon1' => $longitude,
                ':radius_km' => $radiusKm
            ]);
            $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($trips as &$trip) {
                $trip['price_formatted'] = number_format((float) $trip['price_iqd'], 0) . ' د.ع';
                $trip['date_formatted'] = date('Y/m/d H:i', strtotime($trip['date_time']));
                $trip['distance_km'] = round((float) $trip['distance_km'], 2);
                $trip['driver_name'] = htmlspecialchars($trip['driver_name'], ENT_QUOTES, 'UTF-8');
                $trip['departure_city'] = htmlspecialchars($trip['departure_city'], ENT_QUOTES, 'UTF-8');
                $trip['destination_city'] = htmlspecialchars($trip['destination_city'], ENT_QUOTES, 'UTF-8');
            }
            unset($trip);

            $this->sendSuccess(200, 'شوفێرە نزیکەکان گەڕانەوە', $trips);
        } catch (PDOException $e) {
            error_log('[SharekAPI] searchNearbyDrivers: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    // ============================================================================
    // OFFERS: Get Active Offers
    // ============================================================================
    private function getOffers() {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, company_name, offer_details, link, created_at
                 FROM offers
                 WHERE is_active = 1
                 ORDER BY created_at DESC'
            );
            $stmt->execute();
            $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($offers as &$offer) {
                $offer['company_name'] = htmlspecialchars($offer['company_name'], ENT_QUOTES, 'UTF-8');
                $offer['offer_details'] = htmlspecialchars($offer['offer_details'], ENT_QUOTES, 'UTF-8');
                $offer['link'] = htmlspecialchars($offer['link'], ENT_QUOTES, 'UTF-8');
            }
            unset($offer);

            $this->sendSuccess(200, 'پێشنیارەکان گەڕانەوە', $offers);
        } catch (PDOException $e) {
            error_log('[SharekAPI] getOffers: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }

    private function getStats() {
        try {
            $activeTrips = (int)$this->pdo
                ->query("SELECT COUNT(*) FROM trips WHERE status='active'")
                ->fetchColumn();
            $drivers = (int)$this->pdo
                ->query("SELECT COUNT(*) FROM users WHERE is_verified=1")
                ->fetchColumn();
            $ratingRow = $this->pdo
                ->query("SELECT COALESCE(ROUND(AVG(rating),1), 4.9) FROM reviews")
                ->fetchColumn();
            $cities = (int)$this->pdo
                ->query("SELECT COUNT(DISTINCT departure_city) FROM trips")
                ->fetchColumn();

            $this->sendSuccess(200, 'ئامارەکان گەڕانەوە', [
                'active_trips' => $activeTrips,
                'drivers'      => $drivers,
                'rating'       => (float)$ratingRow,
                'cities'       => max($cities, 10)
            ]);
        } catch (Exception $e) {
            $this->sendSuccess(200, 'ئامارەکان گەڕانەوە', [
                'active_trips' => 25,
                'drivers'      => 30,
                'rating'       => 4.9,
                'cities'       => 10
            ]);
        }
    }

    // ============================================================================
    // CONTACT FORM: Handle Contact Form Submission
    // ============================================================================
    private function handleContactForm() {
        $name = isset($_POST['name']) ? $this->sanitizeInput($_POST['name']) : '';
        $contact = isset($_POST['contact']) ? $this->sanitizeInput($_POST['contact']) : '';
        $message = isset($_POST['message']) ? $this->sanitizeInput($_POST['message']) : '';

        // Validate required fields
        if (empty($name) || empty($contact) || empty($message)) {
            $this->sendError(400, 'هەموو خانەکان پێویستە پڕ بکرێن');
        }

        // Validate contact (email or phone)
        $isEmail = filter_var($contact, FILTER_VALIDATE_EMAIL);
        $isPhone = preg_match('/^(077|075|078)\d{8}$/', $contact);
        
        if (!$isEmail && !$isPhone) {
            $this->sendError(400, 'ئیمەیڵ یان ژمارەی مۆبایلی دروست پێویستە');
        }

        try {
            // Log the contact submission
            $logMessage = sprintf(
                "[%s] Contact Form: Name=%s, Contact=%s, Message=%s",
                date('Y-m-d H:i:s'),
                $name,
                $contact,
                substr($message, 0, 100)
            );
            error_log($logMessage);

            // Send email to admin via EmailService
            require_once __DIR__ . '/EmailService.php';
            $emailSvc = new EmailService();
            
            $adminEmail = 'info@sharek.com';
            $subject = 'پەیامی نوێ لە فۆرمی پەیوەندی: ' . $name;
            // Escape exactly once, here, at the actual point of HTML output
            // (see audit findings #2 and #9/#10) — $name/$contact/$message
            // above are intentionally raw (not pre-escaped at write-time).
            $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $safeContact = htmlspecialchars($contact, ENT_QUOTES, 'UTF-8');
            $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
            $body = "
                <html dir='rtl' lang='ku'>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: 'Vazirmatn', Arial, sans-serif; direction: rtl; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #1e3a8a; color: white; padding: 20px; text-align: center; }
                        .content { padding: 20px; background: #f9fafb; }
                        .field { margin-bottom: 15px; }
                        .label { font-weight: bold; color: #1e3a8a; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>🚗 شەریک - پەیامی نوێ</h2>
                        </div>
                        <div class='content'>
                            <div class='field'>
                                <span class='label'>ناو:</span> {$safeName}
                            </div>
                            <div class='field'>
                                <span class='label'>پەیوەندی:</span> {$safeContact}
                            </div>
                            <div class='field'>
                                <span class='label'>پەیام:</span>
                                <p>{$safeMessage}</p>
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            ";

            $emailSent = $emailSvc->sendCustomEmail($adminEmail, $subject, $body, true);

            if ($emailSent) {
                $this->sendSuccess(200, 'پەیامەکەت گەیشت — بەزوودی وەڵامت دەدەینەوە ✉️');
            } else {
                // Email failed but still log and return success (don't expose email issues to user)
                error_log('Contact form email failed to send to admin');
                $this->sendSuccess(200, 'پەیامەکەت گەیشت — بەزوودی وەڵامت دەدەینەوە ✉️');
            }
        } catch (Exception $e) {
            error_log('[SharekAPI] submitContact: ' . $e->getMessage());
            $this->sendError(500, 'هەڵەیەک ڕووی دا — تکایە دووبارە هەوڵبدەرەوە');
        }
    }
}

$api = new SharekAPI();
$api->handleRequest();