<?php
/**
 * Sharek v1.5 - Admin Panel
 * 
 * @file admin.php
 * @date 2026-05-25
 * @description Administrative dashboard for managing trips, drivers, and system statistics
 * @version 1.5.0
 * 
 * Security Features:
 * - Session-based authentication
 * - Password hashing with bcrypt
 * - Prepared statements for SQL queries
 * - Input sanitization
 */

// Security headers for InfinityFree compatibility
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Load SecurityManager for enterprise-grade session management
require_once __DIR__ . '/src/Security/SecurityManager.php';
use Sharek\Security\SecurityManager;

// Initialize secure session with timeout and the X-Forwarded-Proto-aware
// secure cookie flag (audit finding #13) — replaces the inline
// session_set_cookie_params() duplicated across entry points.
SecurityManager::initSecureSession();

// Generate the admin-specific CSRF token if it doesn't exist yet. This is
// kept separate from SecurityManager's own csrf_token, since the admin
// panel uses a dedicated admin_csrf_token.
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/src/Security/RateLimiter.php';

// Load environment variables from .env file
$env = parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW);

// Admin password hash - loaded from .env file
if (empty($env['ADMIN_PASSWORD_HASH'])) {
    http_response_code(503);
    die('Admin not configured.');
}
define('ADMIN_PASSWORD_HASH', $env['ADMIN_PASSWORD_HASH']);

$db = new Database();
$pdo = $db->getConnection();

$loginError = '';

// Check for CSRF error from redirect
if (isset($_GET['error']) && $_GET['error'] === 'csrf') {
    $loginError = 'هەڵەیەک ڕووی دا - تکایە دووبارە هەوڵبدەرەوە';
}

/**
 * Handle admin logout
 *
 * Clears admin session and redirects to login page
 */
if (isset($_POST['admin_logout'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $_POST['csrf_token'])) {
        $loginError = 'هەڵەیەک ڕووی دا - تکایە دووبارە هەوڵبدەرەوە';
    } else {
        unset($_SESSION['sharek_admin']);
        header('Location: admin.php');
        exit;
    }
}

/**
 * Handle admin login
 *
 * Verifies password and establishes admin session
 * Uses password_verify for secure authentication
 */
if (isset($_POST['admin_login'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $_POST['csrf_token'])) {
        $loginError = 'هەڵەیەک ڕووی دا - تکایە دووبارە هەوڵبدەرەوە';
    } else {
        // Server-side, IP-based rate limiting persisted in the DB — replaces
        // the old $_SESSION counter, which was trivially bypassable by
        // discarding the session cookie (audit finding #4). Reuses the same
        // RateLimiter already in place for the regular login page.
        $ip = $_SERVER['REMOTE_ADDR'];
        $rateLimiter = new RateLimiter($pdo);

        if ($rateLimiter->isLockedOut($ip)) {
            $minutes = $rateLimiter->lockoutMinutesRemaining($ip);
            $loginError = "زۆرتری هەوڵدراوە. تکایە {$minutes} خولەک چاوەڕێ بکە.";
        } else {
            // Pass the raw, trimmed password directly to password_verify().
            // Escaping/stripping tags before verification (the previous
            // behavior) could silently mangle a legitimate password
            // containing <, >, &, ", or ' and reject valid credentials —
            // with no security benefit, since the value is never rendered
            // as HTML (audit finding #11).
            $pass = isset($_POST['admin_password']) ? trim($_POST['admin_password']) : '';

            if (password_verify($pass, ADMIN_PASSWORD_HASH)) {
                $_SESSION['sharek_admin'] = true;
                // Reset rate limit on successful login
                $rateLimiter->recordSuccess($ip, 'admin');
                // Regenerate session ID and CSRF token for security against session fixation
                session_regenerate_id(true);
                $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: admin.php');
                exit;
            } else {
                $rateLimiter->recordFailure($ip, 'admin');
                $loginError = 'تێپەڕەوشەی بەڕێوەبەر هەڵەیە';
            }
        }
    }
}

/**
 * Admin session verification
 * 
 * Checks if user has valid admin session
 */
$isAdmin = isset($_SESSION['sharek_admin']) && $_SESSION['sharek_admin'] === true;

/**
 * Handle admin POST actions
 *
 * Processes driver verification and trip deletion
 * Uses prepared statements for security
 */
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token for all POST actions
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $_POST['csrf_token'])) {
        // For AJAX requests, return JSON error
        if (isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'هەڵەیەک ڕووی دا - تکایە دووبارە هەوڵبدەرەوە']);
            exit;
        }
        // For form submissions, redirect with error
        header('Location: admin.php?error=csrf');
        exit;
    }

    if (isset($_POST['verify_driver_id'])) {
        $driverId = (int) $_POST['verify_driver_id'];
        if ($driverId > 0) {
            $stmt = $pdo->prepare('UPDATE users SET is_verified = 1 WHERE id = :id');
            $stmt->execute([':id' => $driverId]);
        }
    }
    if (isset($_POST['delete_trip_id'])) {
        $tripId = (int) $_POST['delete_trip_id'];
        if ($tripId > 0) {
            $stmt = $pdo->prepare('DELETE FROM trips WHERE id = :id');
            $stmt->execute([':id' => $tripId]);
        }
    }
    if (isset($_POST['feature_trip_id'])) {
        // Featured placement is an admin-controlled / future paid-promotion feature.
        // platform_fee column is reserved for future commission/payment integration and must
        // remain 0 until a payment gateway is implemented — do not set it from user input.
        $tripId = (int) $_POST['feature_trip_id'];
        if ($tripId > 0) {
            $stmt = $pdo->prepare('UPDATE trips SET is_featured = 1 WHERE id = :id');
            $stmt->execute([':id' => $tripId]);
        }
    }
    if (isset($_POST['unfeature_trip_id'])) {
        // Featured placement is an admin-controlled / future paid-promotion feature.
        // platform_fee column is reserved for future commission/payment integration and must
        // remain 0 until a payment gateway is implemented — do not set it from user input.
        $tripId = (int) $_POST['unfeature_trip_id'];
        if ($tripId > 0) {
            $stmt = $pdo->prepare('UPDATE trips SET is_featured = 0 WHERE id = :id');
            $stmt->execute([':id' => $tripId]);
        }
    }
    if (isset($_POST['delete_user_id'])) {
        $userId = (int) $_POST['delete_user_id'];
        if ($userId > 0) {
            // All child tables (bookings, trips, reviews, subscriptions, saved_routes)
            // have ON DELETE CASCADE FKs to users.id — one DELETE is sufficient and atomic.
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => $userId]);
        }
    }
    if (isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
        header('Content-Type: application/json');
        $testEmail = trim($_POST['test_email'] ?? '');
        
        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'ئیمەیڵەکە نادروستە']);
            exit;
        }
        
        require_once __DIR__ . '/EmailService.php';
        $emailSvc = new EmailService();
        
        try {
            $emailSvc->mailer->clearAddresses();
            $emailSvc->mailer->clearAttachments();
            $emailSvc->mailer->addAddress($testEmail);
            $emailSvc->mailer->Subject = 'SMTP Test — Sharek';
            $emailSvc->mailer->Body = 'ئەم ئیمەیڵە تاقیکردنەوەی SMTP یە. کاتی ناردن: ' . date('Y-m-d H:i:s');
            $emailSvc->mailer->send();
            echo json_encode(['success' => true, 'message' => 'ئیمەیڵەکە سەرکەوتووانە نێندرا']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $emailSvc->mailer->ErrorInfo]);
        }
        exit;
    }
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        /**
         * Export trips to CSV
         * 
         * Generates CSV file with all trip data for download
         */
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="trips_export_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8 support in Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // CSV headers
        fputcsv($output, ['ID', 'Departure City', 'Destination City', 'Departure Detail', 'Destination Detail', 'Waypoints', 'Date Time', 'Price (IQD)', 'Seats Available', 'Status', 'Featured', 'Driver ID', 'Driver Name', 'Driver Phone', 'Verified']);
        
        // Fetch all trips
        $sql = "SELECT t.id, t.departure_city, t.destination_city, t.departure_detail, t.destination_detail,
                       t.waypoints, t.date_time, t.price_iqd, t.seats_available, t.status, t.is_featured,
                       u.id AS driver_id, u.name AS driver_name, u.phone AS driver_phone, u.is_verified
                FROM trips t
                INNER JOIN users u ON t.driver_id = u.id
                ORDER BY t.created_at DESC";
        $trips = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        // Write trip data
        foreach ($trips as $trip) {
            fputcsv($output, [
                $trip['id'],
                $trip['departure_city'],
                $trip['destination_city'],
                $trip['departure_detail'],
                $trip['destination_detail'],
                $trip['waypoints'],
                $trip['date_time'],
                $trip['price_iqd'],
                $trip['seats_available'],
                $trip['status'],
                $trip['is_featured'],
                $trip['driver_id'],
                $trip['driver_name'],
                $trip['driver_phone'],
                $trip['is_verified']
            ]);
        }
        
        fclose($output);
        exit;
    }
    header('Location: admin.php');
    exit;
}

/**
 * Initialize data arrays
 * 
 * Stats for dashboard metrics
 * Trips and drivers lists for display
 */
$stats = ['users' => 0, 'trips' => 0, 'bookings' => 0, 'active_trips' => 0, 'verified_drivers' => 0];
$trips = [];
$drivers = [];
$users = [];

/**
 * Fetch dashboard data for authenticated admin
 * 
 * Retrieves statistics, trips, and driver reputation data
 * Uses prepared statements for security
 */
if ($isAdmin) {
    // Fetch statistics using prepared statements
    $stats['users'] = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $stats['trips'] = (int) $pdo->query('SELECT COUNT(*) FROM trips')->fetchColumn();
    $stats['bookings'] = (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    $stats['active_trips'] = (int) $pdo->query("SELECT COUNT(*) FROM trips WHERE status = 'active' AND date_time >= NOW()")->fetchColumn();
    $stats['verified_drivers'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_verified = 1')->fetchColumn();

    // Fetch trips with driver information
    $sql = "SELECT t.id, t.departure_city, t.destination_city, t.departure_detail, t.destination_detail,
                   t.waypoints, t.date_time, t.price_iqd, t.seats_available, t.status, t.is_featured,
                   u.id AS driver_id, u.name AS driver_name, u.phone AS driver_phone, u.is_verified
            FROM trips t
            INNER JOIN users u ON t.driver_id = u.id
            ORDER BY t.created_at DESC";
    $trips = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Fetch drivers sorted by reputation
    $driverSql = "SELECT u.id, u.name, u.phone, u.is_verified,
                         COALESCE(ROUND(AVG(r.rating), 1), 0) as avg_rating,
                         COUNT(r.id) as total_reviews
                  FROM users u
                  LEFT JOIN reviews r ON u.id = r.driver_id
                  GROUP BY u.id, u.name, u.phone, u.is_verified
                  ORDER BY avg_rating DESC, total_reviews DESC";
    $drivers = $pdo->query($driverSql)->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all users with optional search
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    if ($search) {
        $userSql = "SELECT u.id, u.user_ref_id, u.name, u.phone, u.email, u.is_verified, u.email_verified,
                           (SELECT COUNT(*) FROM trips WHERE driver_id = u.id) as trip_count,
                           (SELECT COUNT(*) FROM bookings WHERE passenger_id = u.id) as booking_count
                    FROM users u
                    WHERE u.name LIKE :search OR u.user_ref_id LIKE :search OR u.phone LIKE :search
                    ORDER BY u.created_at DESC";
        $stmt = $pdo->prepare($userSql);
        $stmt->execute([':search' => "%$search%"]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $userSql = "SELECT u.id, u.user_ref_id, u.name, u.phone, u.email, u.is_verified, u.email_verified,
                           (SELECT COUNT(*) FROM trips WHERE driver_id = u.id) as trip_count,
                           (SELECT COUNT(*) FROM bookings WHERE passenger_id = u.id) as booking_count
                    FROM users u
                    ORDER BY u.created_at DESC";
        $users = $pdo->query($userSql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="بەڕێوەبردنی شەریک - یەکەمین پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان">
    <meta name="theme-color" content="#1e3a8a">
    <title>بەڕێوەبردنی شەریک</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="font" href="https://fonts.gstatic.com/s/vazirmatn/v15/HI6mYUd6BOtE7YjHgqS2U2vL2x2.woff2" type="font/woff2" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/kurdish-typography.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/profile.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/responsive-fixes.css">
    <link rel="manifest" href="manifest.json">
    <script>
        (function() {
            const saved = localStorage.getItem('sharek-theme');
            if (saved === 'dark') document.body.classList.add('dark-mode');
        })();
    </script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('./service-worker.js')
                    .then((registration) => {
                        console.log('ServiceWorker registration successful');
                    })
                    .catch((error) => {
                        console.log('ServiceWorker registration failed:', error);
                    });
            });
        }
    </script>
</head>
<body class="admin-body">
<?php if (!$isAdmin): ?>
    <main class="admin-login-wrap">
        <form method="post" class="admin-login-form">
            <h1>🔐 بەڕێوەبردنی شەریک</h1>
            <p>تەنها بۆ کارمەندانی بەڕێوەبردن</p>
            <?php if ($loginError): ?>
                <p class="admin-error"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="admin_password">تێپەڕەوشەی بەڕێوەبەر</label>
                <input type="password" id="admin_password" name="admin_password" required>
            </div>
            <button type="submit" name="admin_login" class="btn btn-primary">چوونەژوورەوە</button>
            <p class="admin-hint"><a href="index.html">گەڕانەوە بۆ ماڵپەڕ</a></p>
        </form>
    </main>
<?php else: ?>
    <header class="admin-header">
        <div class="container admin-header-inner">
            <h1>📊 داشبۆردی بەڕێوەبردنی شەریک</h1>
            <div class="admin-header-actions">
                <button onclick="showSmtpTest()" class="btn btn-secondary">📧 تاقیکردنەوەی SMTP</button>
                <a href="index.html" class="btn btn-secondary">ماڵپەڕی سەرەکی</a>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" name="admin_logout" class="btn btn-danger">🚪 دەرچوون</button>
                </form>
            </div>
        </div>
    </header>

    <main class="container admin-main">
        <section class="admin-stats-grid">
            <div class="admin-stat-card">
                <span class="admin-stat-label">بەکارهێنەران</span>
                <strong class="admin-stat-value"><?php echo $stats['users']; ?></strong>
            </div>
            <div class="admin-stat-card">
                <span class="admin-stat-label">گەشتەکان</span>
                <strong class="admin-stat-value"><?php echo $stats['trips']; ?></strong>
            </div>
            <div class="admin-stat-card">
                <span class="admin-stat-label">داگیرکردنەکان</span>
                <strong class="admin-stat-value"><?php echo $stats['bookings']; ?></strong>
            </div>
            <div class="admin-stat-card">
                <span class="admin-stat-label">گەشتی چالاک</span>
                <strong class="admin-stat-value"><?php echo $stats['active_trips']; ?></strong>
            </div>
            <div class="admin-stat-card">
                <span class="admin-stat-label">شۆفێری پشتڕاستکراو</span>
                <strong class="admin-stat-value"><?php echo $stats['verified_drivers']; ?></strong>
            </div>
        </section>

        <section class="admin-table-section">
            <h2>شۆفێرەکان بەپێی ڕیزبەندی</h2>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ناو</th>
                            <th>تەلەفۆن</th>
                            <th>ڕێکخەری</th>
                            <th>پێداچوونەوەکان</th>
                            <th>ناونیشان</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($drivers)): ?>
                        <tr><td colspan="6">هیچ شۆفێرێک نیە</td></tr>
                    <?php else: ?>
                        <?php foreach ($drivers as $driver): ?>
                            <?php
                                // Calculate reputation title
                                $avgRating = (float) $driver['avg_rating'];
                                $totalReviews = (int) $driver['total_reviews'];
                                $title = 'شۆفێری نوێ';
                                $badgeClass = 'badge-reputation-new';
                                
                                if ($totalReviews >= 10) {
                                    if ($avgRating >= 4.8) {
                                        $title = 'شۆفێری پێشەنگ';
                                        $badgeClass = 'badge-reputation-elite';
                                    } elseif ($avgRating >= 4.5) {
                                        $title = 'شۆفێری باوەڕپێکراو';
                                        $badgeClass = 'badge-reputation-trusted';
                                    } elseif ($avgRating >= 4.0) {
                                        $title = 'شۆفێری باش';
                                        $badgeClass = 'badge-reputation-good';
                                    } elseif ($avgRating >= 3.5) {
                                        $title = 'شۆفێری ئاسایی';
                                        $badgeClass = 'badge-reputation-average';
                                    } else {
                                        $title = 'شۆفێری پێویست بە پێشکەوتن';
                                        $badgeClass = 'badge-reputation-average';
                                    }
                                } elseif ($totalReviews >= 5) {
                                    if ($avgRating >= 4.5) {
                                        $title = 'شۆفێری باوەڕپێکراو';
                                        $badgeClass = 'badge-reputation-trusted';
                                    } elseif ($avgRating >= 4.0) {
                                        $title = 'شۆفێری باش';
                                        $badgeClass = 'badge-reputation-good';
                                    } else {
                                        $title = 'شۆفێری لە پەرەسەنداندا';
                                        $badgeClass = 'badge-reputation-average';
                                    }
                                }
                            ?>
                            <tr>
                                <td><?php echo (int) $driver['id']; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($driver['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($driver['is_verified'])): ?>
                                        <span class="badge-verified">✔ پشتڕاست</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($driver['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span class="rating-stars"><?php echo str_repeat('★', (int) $avgRating); ?><?php echo str_repeat('☆', 5 - (int) $avgRating); ?></span>
                                    <small><?php echo $avgRating; ?></small>
                                </td>
                                <td><?php echo $totalReviews; ?></td>
                                <td><span class="badge-reputation <?php echo $badgeClass; ?>"><?php echo $title; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-table-section">
            <div class="admin-section-header">
                <h2>بەکارهێنەران</h2>
                <form method="get" class="admin-search-form">
                    <input type="text" name="search" placeholder="گەڕان بە ناو یان ئایدی..." value="<?php echo htmlspecialchars($_GET['search'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn btn-secondary">🔍 گەڕان</button>
                </form>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ئایدی</th>
                            <th>ناو</th>
                            <th>تەلەفۆن</th>
                            <th>ئیمەیڵ</th>
                            <th>گەشت</th>
                            <th>داگیرکردن</th>
                            <th>دۆخ</th>
                            <th>کردارەکان</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="9">هیچ بەکارهێنەرێک نیە</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo (int) $user['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($user['user_ref_id'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td><?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) $user['trip_count']; ?></td>
                                <td><?php echo (int) $user['booking_count']; ?></td>
                                <td>
                                    <?php if (!empty($user['is_verified'])): ?>
                                        <span class="badge-verified">✔ پشتڕاست</span>
                                    <?php else: ?>
                                        <span class="badge-unverified">✕ ناپشتڕاست</span>
                                    <?php endif; ?>
                                    <?php if (!empty($user['email_verified'])): ?>
                                        <span class="badge-verified">✔ ئیمەیڵ</span>
                                    <?php endif; ?>
                                </td>
                                <td class="admin-actions">
                                    <form method="post" class="admin-inline-form" onsubmit="return confirm('دڵنیایت لە سڕینەوەی بەکارهێنەر؟ هەموو داتاکانی ئەم بەکارهێنەرە دەسڕێتەوە.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_user_id" value="<?php echo (int) $user['id']; ?>">
                                        <button type="submit" class="btn btn-admin-delete">🗑️ سڕینەوە</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-table-section">
            <div class="admin-section-header">
                <h2>لیستی گەشتە چالاکەکان</h2>
                <a href="?export=csv" class="btn btn-csv">📥 Export CSV</a>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>      
                        <tr>
                            <th>#</th>
                            <th>شۆفێر</th>
                            <th>ڕێگا</th>
                            <th>بەروار</th>
                            <th>نرخ</th>
                            <th>کورسی</th>
                            <th>دۆخ</th>
                            <th>کردارەکان</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($trips)): ?>
                        <tr><td colspan="8">هیچ تۆمارێک نیە</td></tr>
                    <?php else: ?>
                        <?php foreach ($trips as $trip): ?>
                            <tr>
                                <td><?php echo (int) $trip['id']; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($trip['driver_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <br><small><?php echo htmlspecialchars($trip['driver_phone'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php if (!empty($trip['is_verified'])): ?>
                                        <span class="badge-verified">✔ پشتڕاست</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($trip['departure_city'] . ' ← ' . $trip['destination_city'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($trip['waypoints']): ?>
                                        <br><small>ڕێ:R <?php echo htmlspecialchars($trip['waypoints'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(date('Y/m/d H:i', strtotime($trip['date_time'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((float) $trip['price_iqd'], 0); ?> د.ع</td>
                                <td><?php echo (int) $trip['seats_available']; ?></td>
                                <td><?php echo htmlspecialchars($trip['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="admin-actions">
                                    <?php if (empty($trip['is_verified'])): ?>
                                    <form method="post" class="admin-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="verify_driver_id" value="<?php echo (int) $trip['driver_id']; ?>">
                                        <button type="submit" class="btn btn-verify">✔️ پشتڕاستکردنی شۆفێر</button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (!empty($trip['is_featured'])): ?>
                                    <form method="post" class="admin-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="unfeature_trip_id" value="<?php echo (int) $trip['id']; ?>">
                                        <button type="submit" class="btn btn-secondary">⭐ لادان لە خەڵات</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" class="admin-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="feature_trip_id" value="<?php echo (int) $trip['id']; ?>">
                                        <button type="submit" class="btn btn-secondary">⭐ خەڵاتدان</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="post" class="admin-inline-form" onsubmit="return confirm('دڵنیایت لە سڕینەوە؟');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_trip_id" value="<?php echo (int) $trip['id']; ?>">
                                        <button type="submit" class="btn btn-admin-delete">🗑️ سڕینەوەی پۆست</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <!-- SMTP Test Modal -->
    <div id="smtp-test-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:white; padding:2rem; border-radius:12px; max-width:400px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,0.2);">
            <h2 style="margin-top:0; color:var(--navy);">📧 تاقیکردنەوەی SMTP</h2>
            <p style="color:var(--text-muted); margin-bottom:1rem;">ئیمەیڵی تاقیکردنەوە بنووسە:</p>
            <input type="hidden" id="smtp-csrf-token" value="<?php echo htmlspecialchars($_SESSION['admin_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="email" id="smtp-test-email" placeholder="admin@example.com" style="width:100%; padding:0.75rem; border:1px solid #ddd; border-radius:8px; margin-bottom:1rem; font-size:1rem;">
            <div id="smtp-test-result" style="margin-bottom:1rem; padding:0.75rem; border-radius:8px; display:none;"></div>
            <div style="display:flex; gap:0.5rem; justify-content:flex-end;">
                <button onclick="hideSmtpTest()" style="padding:0.75rem 1.5rem; border:1px solid #ddd; background:white; border-radius:8px; cursor:pointer;">داخستن</button>
                <button onclick="testSmtp()" style="padding:0.75rem 1.5rem; background:var(--navy); color:white; border:none; border-radius:8px; cursor:pointer;">ناردن</button>
            </div>
        </div>
    </div>

    <script>
        function showSmtpTest() {
            document.getElementById('smtp-test-modal').style.display = 'flex';
            document.getElementById('smtp-test-result').style.display = 'none';
            document.getElementById('smtp-test-email').value = '';
        }

        function hideSmtpTest() {
            document.getElementById('smtp-test-modal').style.display = 'none';
        }

        async function testSmtp() {
            const email = document.getElementById('smtp-test-email').value.trim();
            const csrfToken = document.getElementById('smtp-csrf-token').value;
            const resultDiv = document.getElementById('smtp-test-result');

            if (!email || !email.includes('@')) {
                resultDiv.style.display = 'block';
                resultDiv.style.background = '#fee2e2';
                resultDiv.style.color = '#dc2626';
                resultDiv.textContent = '❌ ئیمەیڵەکە نادروستە';
                return;
            }

            resultDiv.style.display = 'block';
            resultDiv.style.background = '#dbeafe';
            resultDiv.style.color = '#1e40af';
            resultDiv.textContent = '⏳ دەست پێ دەکات...';

            try {
                const res = await fetch('admin.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=test_smtp&test_email=${encodeURIComponent(email)}&csrf_token=${encodeURIComponent(csrfToken)}`
                });
                const json = await res.json();
                
                if (json.success) {
                    resultDiv.style.background = '#d1fae5';
                    resultDiv.style.color = '#065f46';
                    resultDiv.textContent = '✅ ' + (json.message || 'ئیمەیڵەکە سەرکەوتووانە نێندرا');
                } else {
                    resultDiv.style.background = '#fee2e2';
                    resultDiv.style.color = '#dc2626';
                    resultDiv.textContent = '❌ ' + (json.error || 'هەڵەیەک ڕووی دا');
                }
            } catch (e) {
                resultDiv.style.background = '#fee2e2';
                resultDiv.style.color = '#dc2626';
                resultDiv.textContent = '❌ کێشەیەک ڕووی دا — تکایە دووبارە هەوڵ بدەرەوە';
            }
        }
    </script>
<?php endif; ?>
</body>
</html>
