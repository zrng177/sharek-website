<?php
// Security headers for InfinityFree compatibility
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Load SecurityManager for enterprise-grade session management
require_once __DIR__ . '/src/Security/SecurityManager.php';
use Sharek\Security\SecurityManager;

// Initialize secure session with timeout, X-Forwarded-Proto-aware secure
// cookie flag, and CSRF protection (audit finding #13) — replaces the
// inline session_set_cookie_params() that only checked $_SERVER['HTTPS']
// and missed idle/absolute session-timeout enforcement.
SecurityManager::initSecureSession();
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php');
    exit;
}
$currentUserId   = (int)$_SESSION['user_id'];
$currentUserName = htmlspecialchars($_SESSION['user_name'] ?? '', ENT_QUOTES, 'UTF-8');
$currentPhone    = htmlspecialchars($_SESSION['user_phone'] ?? '', ENT_QUOTES, 'UTF-8');
$currentEmail    = htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!--
    Sharek v1.5 - Main Application Interface
    
    @file dashboard.php
    @date 2026-05-25
    @description Main HTML interface for Sharek carpooling platform with dashboard,
                 trip posting, search, and user management features
    @version 1.5.0
    
    Design Features:
    - Responsive RTL layout for Kurdish language
    - Dark mode support via CSS variables
    - Leaflet map integration for trip visualization
    - PWA-ready with manifest and service worker
    - Consistent use of .badge, .btn-custom, and CSS variables
-->
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="description" content="شەریک - پلاتفۆڕمی هاوبەشکردنی گەشت لە کوردستان">
    <meta name="theme-color" content="#1e3a8a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>شەریک - هاوبەشکردنی گەشت</title>
    <link rel="manifest" href="manifest.json">
    <!-- <link rel="apple-touch-icon" href="icon-192.png"> -->
    <!-- Vazirmatn RTL Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="font" href="https://fonts.gstatic.com/s/vazirmatn/v15/HI6mYUd6BOtE7YjHgqS2U2vL2x2.woff2" type="font/woff2" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/kurdish-typography.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/map.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/responsive-fixes.css">
    <style>
        /* Mobile fixes for dashboard */
        
        /* Prevent iOS zoom on inputs */
        .form-input, input, select, textarea {
            font-size: 16px !important;
        }
        
        /* Trip cards stack vertically on mobile */
        @media (max-width: 480px) {
            .trips-grid {
                display: flex;
                flex-direction: column;
            }
            .trip-card {
                width: 100% !important;
                max-width: 100%;
                box-sizing: border-box;
            }
        }
        
        /* Wide cards overflow */
        .filter-card, .search-results {
            overflow-x: auto;
        }
        
        /* Navigation touch targets */
        .nav-btn {
            min-height: 44px;
            min-width: 44px;
        }
    </style>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
</head>
<body class="dashboard-page">
<script>
(function() {
    const s = localStorage.getItem('sharek-theme');
    const p = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (s === 'dark' || (!s && p)) document.body.classList.add('dark-mode');
})();
</script>
    <!-- ==========================================================================
       Header Section - Navigation and User Authentication
       ========================================================================== -->
    <header class="header">
        <div class="container">
            <div class="header-content">
                <h1 class="logo">شەریک</h1>
                <nav class="nav">
                    <button class="nav-btn active" data-tab="dashboard">داشبۆرد</button>
                    <button class="nav-btn" data-tab="post-trip">تۆمارکردنی گەشت</button>
                    <button class="nav-btn" data-tab="search-trips">گەڕان بەدوای گەشت</button>
                    <a href="map.php" class="nav-btn">🗺️ نەخشە</a>
                    <button class="nav-btn nav-btn-my-trips" data-tab="my-trips" style="display: none;">💼 گەشتەکانی من</button>
                    <button class="nav-btn nav-btn-my-bookings" data-tab="my-bookings" style="display: none;">🧳 گەشتە داواکراوەکانی من</button>
                </nav>
                <div id="header-auth" class="header-auth">
                    <span id="user-welcome" class="user-welcome" style="display: none;">بەخێربێیت، <?= $currentUserName ?></span>
                    <button type="button" id="auth-open-btn" class="nav-btn nav-btn-auth">چوونەژوورەوە</button>
                    <button type="button" id="logout-btn" class="nav-btn nav-btn-logout" style="display: none;">🚪 چوونەدەرەوە</button>
                </div>
                <button id="theme-toggle" class="theme-toggle" aria-label="تەبەدڵکردنی مۆد">
                    🌙
                </button>
            </div>
        </div>
    </header>

    <!-- ==========================================================================
       Main Content Area
       ========================================================================== -->
    <main class="main">
        <div class="container">
            <!-- Alert Container for Notifications -->
            <div id="alert-container" class="alert-container"></div>

            <!-- Dashboard Section -->
            <section id="dashboard-section" class="section tab-section active">
                <div class="dashboard-header">
                    <h2>بەخێربێیت بۆ شەریک، <?= $currentUserName ?></h2>
                    <p>پلاتفۆڕمی هاوبەشکردنی گەشت لە کوردستان</p>
                </div>
                <div id="dashboard-map" class="map-container"></div>
                <div class="dashboard-stats">
                    <div class="stat-card">
                        <div class="stat-icon">🚗</div>
                        <div class="stat-info">
                            <h3>گەشتە چالاکەکان</h3>
                            <p class="stat-number" id="active-trips-count">0</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-info">
                            <h3>شۆفێرەکان</h3>
                            <p class="stat-number">25+</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🌟</div>
                        <div class="stat-info">
                            <h3>ڕێژەی ڕەزامەندی</h3>
                            <p class="stat-number">98%</p>
                        </div>
                    </div>
                </div>

                <!-- Saved Routes Section -->
                <div class="saved-routes-section" id="saved-routes-section" style="display: none;">
                    <div class="section-header">
                        <h3>⭐ ڕێگای دڵخواز</h3>
                        <button type="button" class="btn btn-secondary" id="save-current-route-btn">پاشەکەوتکردنی ڕێگای ئێستا</button>
                    </div>
                    <div id="saved-routes-list" class="saved-routes-list"></div>
                </div>
                <!-- DASHBOARD SEARCH & FILTER SECTION -->
                <div class="filter-card">
                    <div class="filter-card-header">
                        <h3>فلتەری گەشتەکان</h3>
                    </div>
                    <div class="filter-card-body">
                        <div class="filter-row">
                            <div class="filter-item filter-item-full">
                                <label for="search-via-town">🔍 گەڕان بە ناوی شارۆچکە</label>
                                <input type="text" id="search-via-town" class="form-input" placeholder="کۆیە، چەمچەماڵ...">
                            </div>
                            <div class="filter-item filter-item-full">
                                <label for="filter-destination">🏁 شاری مەبەست</label>
                                <select id="filter-destination" class="form-input">
                                    <option value="">هەموو شارەکان</option>
                                    <option value="هەولێر">هەولێر</option>
                                    <option value="سلێمانی">سلێمانی</option>
                                    <option value="دهۆک">دهۆک</option>
                                    <option value="کەرکوک">کەرکوک</option>
                                    <option value="هەڵەبجە">هەڵەبجە</option>
                                    <option value="سۆران">سۆران</option>
                                    <option value="رواندز">رواندز</option>
                                    <option value="شەقڵاوە">شەقڵاوە</option>
                                    <option value="خەلیفان">خەلیفان</option>
                                    <option value="حەریر">حەریر</option>
                                    <option value="چۆمان">چۆمان</option>
                                    <option value="کۆیە">کۆیە</option>
                                    <option value="تەق تەق">تەق تەق</option>
                                    <option value="خەبات">خەبات</option>
                                    <option value="مەخموور">مەخموور</option>
                                    <option value="ڕانیە">ڕانیە</option>
                                    <option value="دووکان">دووکان</option>
                                    <option value="پیرەمەگروون">پیرەمەگروون</option>
                                    <option value="بازیان">بازیان</option>
                                    <option value="تەکیە">تەکیە</option>
                                    <option value="سەید سادق">سەید سادق</option>
                                    <option value="پێنجوێن">پێنجوێن</option>
                                    <option value="شەهرەزوور">شەهرەزوور</option>
                                    <option value="زەڕایەن">زەڕایەن</option>
                                    <option value="ماوەت">ماوەت</option>
                                    <option value="چوارتا">چوارتا</option>
                                    <option value="قەرەداغ">قەرەداغ</option>
                                    <option value="عەربەت">عەربەت</option>
                                    <option value="کەلار">کەلار</option>
                                    <option value="چەمچەماڵ">چەمچەماڵ</option>
                                    <option value="شۆڕش">شۆڕش</option>
                                    <option value="دەربەندیخان">دەربەندیخان</option>
                                    <option value="زاخۆ">زاخۆ</option>
                                    <option value="ئاکرێ">ئاکرێ</option>
                                    <option value="ئامێدی">ئامێدی</option>
                                    <option value="شێخان">شێخان</option>
                                    <option value="بەردەڕەش">بەردەڕەش</option>
                                    <option value="سێمێل">سێمێل</option>
                                    <option value="زاوێتە">زاوێتە</option>
                                    <option value="خانەقین">خانەقین</option>
                                    <option value="کفری">کفری</option>
                                </select>
                            </div>
                        </div>
                        <div class="filter-row">
                            <div class="filter-item">
                                <label for="proximity-filter">📍 شوفێرە نزیکەکان</label>
                                <div class="proximity-inputs">
                                    <input type="checkbox" id="proximity-enabled" class="checkbox-input">
                                    <input type="number" id="proximity-radius" class="form-input form-input-small" placeholder="5" min="1" max="50" value="5">
                                    <span class="proximity-label">km</span>
                                </div>
                            </div>
                            <div class="filter-item">
                                <button type="button" class="btn btn-primary btn-full" id="search-nearby-btn">گەڕان بە نزیکی</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="recent-trips">
                    <h3>گەشتەکانی ئەمڕۆ</h3>
                    <div id="trips-feed-container" class="trips-grid">
                        <div class="loading-spinner">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="post-trip-section" class="section tab-section">
                <div class="section-header">
                    <h2>تۆمارکردنی گەشتی نوێ</h2>
                    <p>زانیارییەکانی گەشتەکەت بنووسە بۆ هاوبەشکردنی لەگەڵ گەشتیاران</p>
                </div>
                <form id="trip-form" class="form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="driver-name">ناوی شۆفێر</label>
                            <input type="text" id="driver-name" name="driver_name" placeholder="ناوی تە" maxlength="60" required>
                        </div>
                        <div class="form-group">
                            <label for="driver-phone">ژمارەی تەلەفۆن</label>
                            <input type="tel" id="driver-phone" name="driver_phone" placeholder="07501234567" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="vehicle-model">جۆری ئۆتۆمبێل</label>
                            <input type="text" id="vehicle-model" name="vehicle_model" placeholder="تۆیۆتا کامری" maxlength="60" required>
                        </div>
                        <div class="form-group">
                            <label for="vehicle-color">ڕەنگ</label>
                            <input type="text" id="vehicle-color" name="vehicle_color" placeholder="سپی" maxlength="60" required>
                        </div>
                    </div>
                    <div class="amenities-grid">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="has-ac" name="has_ac" checked>
                                <span>❄️ کارکردنی سپلیت / ساردکەرەوە</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="allows-smoking" name="allows_smoking">
                                <span>🚬 ڕێگەپێدراوە بە جگەرەکێشان</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="allows_pets" name="allows_pets">
                                <span>🐕 هێنانی ئاژەڵی ماڵی</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="music_allowed" name="music_allowed" checked>
                                <span>🎵 گوێگرتن لە مۆسیقا</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="is_ladies_only" name="is_ladies_only">
                                <span>👩‍✈️ گەشتەکە تەنها تایبەتە بە خانمەکان</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="neighborhood_detail">وردەکاری شوێنی دەستپێکردن (گەڕەک / ناوچە)</label>
                            <input type="text" id="neighborhood_detail" name="neighborhood_detail" placeholder="نموونە: گەڕەکی ئەنکاوا، نزیک بازاڕ" maxlength="255">
                        </div>
                        <div class="form-group">
                            <label for="destination_detail">وردەکاری شوێنی گەیشتن</label>
                            <input type="text" id="destination_detail" name="destination_detail" placeholder="نموونە: نزیک سەنتەری شار" maxlength="255">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="waypoints">شوێنە ناوەڕاستەکان (ڕێگای گەشت)</label>
                        <textarea id="waypoints" name="waypoints" rows="2" placeholder="نموونە: کۆیە، کەلار، چەمچەماڵ (بە کۆما جیا بکەرەوە)"></textarea>
                    </div>
                    <div class="form-group map-form-group">
                        <label>📍 شوێنی دەستپێکردن لەسەر نەخشە دیاری بکە</label>
                        <p class="map-hint">کلیک لەسەر نەخشە بکە بۆ دیاریکردنی شوێنەکەت</p>
                        <div id="map" class="trip-map-container">
                            <button id="recenter-map-btn" class="recenter-map-btn" title="دووبارە شوێن بگرە">📍</button>
                        </div>
                        <input type="hidden" id="trip-latitude" name="latitude">
                        <input type="hidden" id="trip-longitude" name="longitude">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="trip-departure">شاری دەستپێکردن</label>
                            <select id="trip-departure" name="departure_city" required readonly>
                                <option value="">📍 چاوەڕوانبێ بۆ دیاریکردنی لۆکەیشن...</option>
                                <option value="هەولێر">هەولێر (Erbil)</option>
                                <option value="سلێمانی">سلێمانی (Sulaymaniyah)</option>
                                <option value="دهۆک">دهۆک (Duhok)</option>
                                <option value="کەرکوک">کەرکوک (Kirkuk)</option>
                                <option value="هەڵەبجە">هەڵەبجە (Halabja)</option>
                                <option value="سۆران">سۆران / دیانا (Soran)</option>
                                <option value="رواندز">رواندز (Rawanduz)</option>
                                <option value="شەقڵاوە">شەقڵاوە (Shaqlawa)</option>
                                <option value="خەلیفان">خەلیفان (Khalifan)</option>
                                <option value="حەریر">حەریر (Harir)</option>
                                <option value="چۆمان">چۆمان / باڵەکایەتی (Choman)</option>
                                <option value="حاجی ئۆمەران">حاجی ئۆمەران (Haji Omeran)</option>
                                <option value="قەسەرێ">قەسەرێ (Qasre)</option>
                                <option value="سمێلان">سمێلان (Smilan)</option>
                                <option value="گەڵاڵە">گەڵاڵە (Galala)</option>
                                <option value="مێرگەسوور">مێرگەسوور (Mergasor)</option>
                                <option value="شێروان مەزن">شێروان مەزن (Sherwan Mazan)</option>
                                <option value="بارزان">بارزان (Barzan)</option>
                                <option value="سیدەکان">سیدەکان / برادۆست (Sidakan)</option>
                                <option value="وەرتێ">وەرتێ (Warte)</option>
                                <option value="پیرمام">پیرمام / مەسیف (Pirmam)</option>
                                <option value="کۆیە">کۆیە (Koya)</option>
                                <option value="تەق تەق">تەق تەق (Taq Taq)</option>
                                <option value="خەبات">خەبات (Khabat)</option>
                                <option value="کەڵەک">کەڵەک / ڕزگاری (Kalak)</option>
                                <option value="مەخموور">مەخموور (Makhmour)</option>
                                <option value="قووشتەپە">قووشتەپە (Qushtapa)</option>
                                <option value="بەحرکە">بەحرکە (Baharka)</option>
                                <option value="کەسنەزان">کەسنەزان (Kasnazan)</option>
                                <option value="دارەتوو">دارەتوو (Daratoo)</option>
                                <option value="بنەسڵاوە">بنەسڵاوە (Bnaslawa)</option>
                                <option value="گوێڕ">گوێڕ (Guwer)</option>
                                <option value="شەمامک">شەمامک (Shamamk)</option>
                                <option value="ڕانیە">ڕانیە (Ranya)</option>
                                <option value="چوارقوڕنە">چوارقوڕنە (Chwarqurna)</option>
                                <option value="حاجیاوا">حاجیاوا (Hajiawa)</option>
                                <option value="سەرکەپکان">سەرکەپکان (Sarkapkan)</option>
                                <option value="بێتواتە">بێتواتە (Betwata)</option>
                                <option value="قەڵادزێ">قەڵادزێ / پشدەر (Qaladiza)</option>
                                <option value="سەنگەسەر">سەنگەسەر (Sangasor)</option>
                                <option value="ژاراوە">ژاراوە (Zharawa)</option>
                                <option value="ئیسێوێ">ئیسێوێ (Iswei)</option>
                                <option value="هێرۆ">هێرۆ (Hero)</option>
                                <option value="هەڵشۆ">هەڵشۆ (Halsho)</option>
                                <option value="دووکان">دووکان (Dukan)</option>
                                <option value="پیرەمەگروون">پیرەمەگروون (Piramagrun)</option>
                                <option value="بازیان">بازیان (Bazyan)</option>
                                <option value="تەکیە">تەکیەی کاکەمەند (Takya)</option>
                                <option value="سەید سادق">سەید سادق (Said Sadiq)</option>
                                <option value="پێنجوێن">پێنجوێن (Penjwen)</option>
                                <option value="گەرمک">گەرمک (Garmk)</option>
                                <option value="شەهرەزوور">شەهرەزوور / هەڵەبجەی تازە (Sharazoor)</option>
                                <option value="زەڕایەن">زەڕایەن / وارماوا (Zarayen)</option>
                                <option value="ماوەت">ماوەت (Mawat)</option>
                                <option value="چوارتا">چوارتا / شارباژێڕ (Chwarta)</option>
                                <option value="سیتەک">سیتەک (Sitek)</option>
                                <option value="قەرەداغ">قەرەداغ (Qaradagh)</option>
                                <option value="عەربەت">عەربەت (Arbat)</option>
                                <option value="بەکراژۆ">بەکراژۆ (Bakrajo)</option>
                                <option value="کەلار">کەلار (Kalar)</option>
                                <option value="رزگاری">رزگاری / حەسیرە (Rzgari)</option>
                                <option value="باوەنوور">باوەنوور (Bawanur)</option>
                                <option value="شێخ تەویل">شێخ تەویل / بەمۆ (Sheikh Tawil)</option>
                                <option value="چەمچەماڵ">چەمچەماڵ (Chamchamal)</option>
                                <option value="شۆڕش">شۆڕش (Shorish)</option>
                                <option value="سەنگاو">سەنگاو (Sangaw)</option>
                                <option value="ئاغجەلەر">ئاغجەلەر (Aghjalar)</option>
                                <option value="قادرکەرەم">قادرکەرەم (Qadir Karam)</option>
                                <option value="دەربەندیخان">دەربەندیخان (Darbandikhan)</option>
                                <option value="کفری">کفری (Kifri)</option>
                                <option value="خانەقین">خانەقین (Khanaqin)</option>
                                <option value="قەرەتەپە">قەرەتەپە (Qaratapa)</option>
                                <option value="سەعدییە">سەعدییە (Saadiya)</option>
                                <option value="جەلەولا">جەلەولا (Jalawla)</option>
                                <option value="جەبارە">جەبارە (Jabara)</option>
                                <option value="مەندەلی">مەندەلی (Mandali)</option>
                                <option value="داقووق">داقووق (Daquq)</option>
                                <option value="حەویجە">حەویجە (Hawija)</option>
                                <option value="التون کۆپری">پردێ / التون کۆپری (Pirde)</option>
                                <option value="زاخۆ">زاخۆ (Zakho)</option>
                                <option value="باتێفا">باتێفا (Batifa)</option>
                                <option value="ئاکرێ">ئاکرێ (Akre)</option>
                                <option value="بجیل">بجیل (Bijil)</option>
                                <option value="گردەسێن">گردەسێن (Girdasin)</option>
                                <option value="ئامێدی">ئامێدی (Amedi)</option>
                                <option value="دێرەلووک">دێرەلووک (Deralok)</option>
                                <option value="شیلادزێ">شیلادزێ (Sheladize)</option>
                                <option value="بامەڕنێ">بامەڕنێ (Bamerne)</option>
                                <option value="سەرسەنگ">سەرسەنگ (Sarsang)</option>
                                <option value="کانی ماسێ">کانی ماسێ (Kani Mase)</option>
                                <option value="شێخان">شێخان (Shekhan)</option>
                                <option value="کەلەکچی">کەلەکچی (Kalakchi)</option>
                                <option value="بەردەڕەش">بەردەڕەش (Bardarash)</option>
                                <option value="سێمێل">سێمێل (Semel)</option>
                                <option value="زاوێتە">زاوێتە (Zawita)</option>
                                <option value="مانگێش">مانگێش (Mangesh)</option>
                                <option value="تەوێڵە">تەوێڵە / هەورامان (Tawella)</option>
                                <option value="بیارە">بیارە (Byara)</option>
                                <option value="خورماڵ">خورماڵ (Khurmal)</option>
                                <option value="سیروان">سیروان (Sirwan)</option>
                                <option value="بەمۆ">بەمۆ / گڵێجاڵ (Bamo)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="trip-destination">شاری مەبەست</label>
                            <select id="trip-destination" name="destination_city" required readonly>
                                <option value="">هەڵبژێرە</option>
                                <option value="هەولێر">هەولێر (Erbil)</option>
                                <option value="سلێمانی">سلێمانی (Sulaymaniyah)</option>
                                <option value="دهۆک">دهۆک (Duhok)</option>
                                <option value="کەرکوک">کەرکوک (Kirkuk)</option>
                                <option value="هەڵەبجە">هەڵەبجە (Halabja)</option>
                                <option value="سۆران">سۆران / دیانا (Soran)</option>
                                <option value="رواندز">رواندز (Rawanduz)</option>
                                <option value="شەقڵاوە">شەقڵاوە (Shaqlawa)</option>
                                <option value="خەلیفان">خەلیفان (Khalifan)</option>
                                <option value="حەریر">حەریر (Harir)</option>
                                <option value="چۆمان">چۆمان / باڵەکایەتی (Choman)</option>
                                <option value="حاجی ئۆمەران">حاجی ئۆمەران (Haji Omeran)</option>
                                <option value="قەسەرێ">قەسەرێ (Qasre)</option>
                                <option value="سمێلان">سمێلان (Smilan)</option>
                                <option value="گەڵاڵە">گەڵاڵە (Galala)</option>
                                <option value="مێرگەسوور">مێرگەسوور (Mergasor)</option>
                                <option value="شێروان مەزن">شێروان مەزن (Sherwan Mazan)</option>
                                <option value="بارزان">بارزان (Barzan)</option>
                                <option value="سیدەکان">سیدەکان / برادۆست (Sidakan)</option>
                                <option value="وەرتێ">وەرتێ (Warte)</option>
                                <option value="پیرمام">پیرمام / مەسیف (Pirmam)</option>
                                <option value="کۆیە">کۆیە (Koya)</option>
                                <option value="تەق تەق">تەق تەق (Taq Taq)</option>
                                <option value="خەبات">خەبات (Khabat)</option>
                                <option value="کەڵەک">کەڵەک / ڕزگاری (Kalak)</option>
                                <option value="مەخموور">مەخموور (Makhmour)</option>
                                <option value="قووشتەپە">قووشتەپە (Qushtapa)</option>
                                <option value="بەحرکە">بەحرکە (Baharka)</option>
                                <option value="کەسنەزان">کەسنەزان (Kasnazan)</option>
                                <option value="دارەتوو">دارەتوو (Daratoo)</option>
                                <option value="بنەسڵاوە">بنەسڵاوە (Bnaslawa)</option>
                                <option value="گوێڕ">گوێڕ (Guwer)</option>
                                <option value="شەمامک">شەمامک (Shamamk)</option>
                                <option value="ڕانیە">ڕانیە (Ranya)</option>
                                <option value="چوارقوڕنە">چوارقوڕنە (Chwarqurna)</option>
                                <option value="حاجیاوا">حاجیاوا (Hajiawa)</option>
                                <option value="سەرکەپکان">سەرکەپکان (Sarkapkan)</option>
                                <option value="بێتواتە">بێتواتە (Betwata)</option>
                                <option value="قەڵادزێ">قەڵادزێ / پشدەر (Qaladiza)</option>
                                <option value="سەنگەسەر">سەنگەسەر (Sangasor)</option>
                                <option value="ژاراوە">ژاراوە (Zharawa)</option>
                                <option value="ئیسێوێ">ئیسێوێ (Iswei)</option>
                                <option value="هێرۆ">هێرۆ (Hero)</option>
                                <option value="هەڵشۆ">هەڵشۆ (Halsho)</option>
                                <option value="دووکان">دووکان (Dukan)</option>
                                <option value="پیرەمەگروون">پیرەمەگروون (Piramagrun)</option>
                                <option value="بازیان">بازیان (Bazyan)</option>
                                <option value="تەکیە">تەکیەی کاکەمەند (Takya)</option>
                                <option value="سەید سادق">سەید سادق (Said Sadiq)</option>
                                <option value="پێنجوێن">پێنجوێن (Penjwen)</option>
                                <option value="گەرمک">گەرمک (Garmk)</option>
                                <option value="شەهرەزوور">شەهرەزوور / هەڵەبجەی تازە (Sharazoor)</option>
                                <option value="زەڕایەن">زەڕایەن / وارماوا (Zarayen)</option>
                                <option value="ماوەت">ماوەت (Mawat)</option>
                                <option value="چوارتا">چوارتا / شارباژێڕ (Chwarta)</option>
                                <option value="سیتەک">سیتەک (Sitek)</option>
                                <option value="قەرەداغ">قەرەداغ (Qaradagh)</option>
                                <option value="عەربەت">عەربەت (Arbat)</option>
                                <option value="بەکراژۆ">بەکراژۆ (Bakrajo)</option>
                                <option value="کەلار">کەلار (Kalar)</option>
                                <option value="رزگاری">رزگاری / حەسیرە (Rzgari)</option>
                                <option value="باوەنوور">باوەنوور (Bawanur)</option>
                                <option value="شێخ تەویل">شێخ تەویل / بەمۆ (Sheikh Tawil)</option>
                                <option value="چەمچەماڵ">چەمچەماڵ (Chamchamal)</option>
                                <option value="شۆڕش">شۆڕش (Shorish)</option>
                                <option value="سەنگاو">سەنگاو (Sangaw)</option>
                                <option value="ئاغجەلەر">ئاغجەلەر (Aghjalar)</option>
                                <option value="قادرکەرەم">قادرکەرەم (Qadir Karam)</option>
                                <option value="دەربەندیخان">دەربەندیخان (Darbandikhan)</option>
                                <option value="کفری">کفری (Kifri)</option>
                                <option value="خانەقین">خانەقین (Khanaqin)</option>
                                <option value="قەرەتەپە">قەرەتەپە (Qaratapa)</option>
                                <option value="سەعدییە">سەعدییە (Saadiya)</option>
                                <option value="جەلەولا">جەلەولا (Jalawla)</option>
                                <option value="جەبارە">جەبارە (Jabara)</option>
                                <option value="مەندەلی">مەندەلی (Mandali)</option>
                                <option value="داقووق">داقووق (Daquq)</option>
                                <option value="حەویجە">حەویجە (Hawija)</option>
                                <option value="التون کۆپری">پردێ / التون کۆپری (Pirde)</option>
                                <option value="زاخۆ">زاخۆ (Zakho)</option>
                                <option value="باتێفا">باتێفا (Batifa)</option>
                                <option value="ئاکرێ">ئاکرێ (Akre)</option>
                                <option value="بجیل">بجیل (Bijil)</option>
                                <option value="گردەسێن">گردەسێن (Girdasin)</option>
                                <option value="ئامێدی">ئامێدی (Amedi)</option>
                                <option value="دێرەلووک">دێرەلووک (Deralok)</option>
                                <option value="شیلادزێ">شیلادزێ (Sheladize)</option>
                                <option value="بامەڕنێ">بامەڕنێ (Bamerne)</option>
                                <option value="سەرسەنگ">سەرسەنگ (Sarsang)</option>
                                <option value="کانی ماسێ">کانی ماسێ (Kani Mase)</option>
                                <option value="شێخان">شێخان (Shekhan)</option>
                                <option value="کەلەکچی">کەلەکچی (Kalakchi)</option>
                                <option value="بەردەڕەش">بەردەڕەش (Bardarash)</option>
                                <option value="سێمێل">سێمێل (Semel)</option>
                                <option value="زاوێتە">زاوێتە (Zawita)</option>
                                <option value="مانگێش">مانگێش (Mangesh)</option>
                                <option value="تەوێڵە">تەوێڵە / هەورامان (Tawella)</option>
                                <option value="بیارە">بیارە (Byara)</option>
                                <option value="خورماڵ">خورماڵ (Khurmal)</option>
                                <option value="سیروان">سیروان (Sirwan)</option>
                                <option value="بەمۆ">بەمۆ / گڵێجاڵ (Bamo)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="trip-date">بەرواری گەشت</label>
                            <input type="date" id="trip-date" name="trip_date" required>
                        </div>
                        <div class="form-group">
                            <label for="trip-time">کات</label>
                            <input type="time" id="trip-time" name="trip_time" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="available-seats">جێگای بەردەست</label>
                            <input type="number" id="available-seats" name="available_seats" min="1" max="7" value="4" required>
                        </div>
                        <div class="form-group">
                            <label for="price">نرخ (دینار)</label>
                            <input type="number" id="price" name="price" min="1000" step="500" placeholder="10000" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="trip-notes">تێبینی (بەخۆڕایی)</label>
                        <textarea id="trip-notes" name="trip_notes" rows="2" placeholder="زانیاری زیاتر دەربارەی گەشتەکە..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">تۆمارکردنی گەشت</button>
                </form>
            </section>

            <section id="search-trips-section" class="section tab-section">
                <div class="section-header">
                    <h2>گەڕان بەدوای گەشت</h2>
                    <p>گەشتی گونجاو بۆ گەشتیارانی دیکە بدۆزەرەوە</p>
                </div>
                <div class="search-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="search-departure">شاری دەستپێکردن</label>
                            <select id="search-departure" class="form-input">
                                <option value="">هەڵبژێرە</option>
                                <option value="هەولێر">هەولێر (Erbil)</option>
                                <option value="سلێمانی">سلێمانی (Sulaymaniyah)</option>
                                <option value="دهۆک">دهۆک (Duhok)</option>
                                <option value="کەرکوک">کەرکوک (Kirkuk)</option>
                                <option value="هەڵەبجە">هەڵەبجە (Halabja)</option>
                                <option value="سۆران">سۆران / دیانا (Soran)</option>
                                <option value="رواندز">رواندز (Rawanduz)</option>
                                <option value="شەقڵاوە">شەقڵاوە (Shaqlawa)</option>
                                <option value="خەلیفان">خەلیفان (Khalifan)</option>
                                <option value="حەریر">حەریر (Harir)</option>
                                <option value="چۆمان">چۆمان / باڵەکایەتی (Choman)</option>
                                <option value="حاجی ئۆمەران">حاجی ئۆمەران (Haji Omeran)</option>
                                <option value="قەسەرێ">قەسەرێ (Qasre)</option>
                                <option value="سمێلان">سمێلان (Smilan)</option>
                                <option value="گەڵاڵە">گەڵاڵە (Galala)</option>
                                <option value="مێرگەسوور">مێرگەسوور (Mergasor)</option>
                                <option value="شێروان مەزن">شێروان مەزن (Sherwan Mazan)</option>
                                <option value="بارزان">بارزان (Barzan)</option>
                                <option value="سیدەکان">سیدەکان / برادۆست (Sidakan)</option>
                                <option value="وەرتێ">وەرتێ (Warte)</option>
                                <option value="پیرمام">پیرمام / مەسیف (Pirmam)</option>
                                <option value="کۆیە">کۆیە (Koya)</option>
                                <option value="تەق تەق">تەق تەق (Taq Taq)</option>
                                <option value="خەبات">خەبات (Khabat)</option>
                                <option value="کەڵەک">کەڵەک / ڕزگاری (Kalak)</option>
                                <option value="مەخموور">مەخموور (Makhmour)</option>
                                <option value="قووشتەپە">قووشتەپە (Qushtapa)</option>
                                <option value="بەحرکە">بەحرکە (Baharka)</option>
                                <option value="کەسنەزان">کەسنەزان (Kasnazan)</option>
                                <option value="دارەتوو">دارەتوو (Daratoo)</option>
                                <option value="بنەسڵاوە">بنەسڵاوە (Bnaslawa)</option>
                                <option value="گوێڕ">گوێڕ (Guwer)</option>
                                <option value="شەمامک">شەمامک (Shamamk)</option>
                                <option value="ڕانیە">ڕانیە (Ranya)</option>
                                <option value="چوارقوڕنە">چوارقوڕنە (Chwarqurna)</option>
                                <option value="حاجیاوا">حاجیاوا (Hajiawa)</option>
                                <option value="سەرکەپکان">سەرکەپکان (Sarkapkan)</option>
                                <option value="بێتواتە">بێتواتە (Betwata)</option>
                                <option value="قەڵادزێ">قەڵادزێ / پشدەر (Qaladiza)</option>
                                <option value="سەنگەسەر">سەنگەسەر (Sangasor)</option>
                                <option value="ژاراوە">ژاراوە (Zharawa)</option>
                                <option value="ئیسێوێ">ئیسێوێ (Iswei)</option>
                                <option value="هێرۆ">هێرۆ (Hero)</option>
                                <option value="هەڵشۆ">هەڵشۆ (Halsho)</option>
                                <option value="دووکان">دووکان (Dukan)</option>
                                <option value="پیرەمەگروون">پیرەمەگروون (Piramagrun)</option>
                                <option value="بازیان">بازیان (Bazyan)</option>
                                <option value="تەکیە">تەکیەی کاکەمەند (Takya)</option>
                                <option value="سەید سادق">سەید سادق (Said Sadiq)</option>
                                <option value="پێنجوێن">پێنجوێن (Penjwen)</option>
                                <option value="گەرمک">گەرمک (Garmk)</option>
                                <option value="شەهرەزوور">شەهرەزوور / هەڵەبجەی تازە (Sharazoor)</option>
                                <option value="زەڕایەن">زەڕایەن / وارماوا (Zarayen)</option>
                                <option value="ماوەت">ماوەت (Mawat)</option>
                                <option value="چوارتا">چوارتا / شارباژێڕ (Chwarta)</option>
                                <option value="سیتەک">سیتەک (Sitek)</option>
                                <option value="قەرەداغ">قەرەداغ (Qaradagh)</option>
                                <option value="عەربەت">عەربەت (Arbat)</option>
                                <option value="بەکراژۆ">بەکراژۆ (Bakrajo)</option>
                                <option value="کەلار">کەلار (Kalar)</option>
                                <option value="رزگاری">رزگاری / حەسیرە (Rzgari)</option>
                                <option value="باوەنوور">باوەنوور (Bawanur)</option>
                                <option value="شێخ تەویل">شێخ تەویل / بەمۆ (Sheikh Tawil)</option>
                                <option value="چەمچەماڵ">چەمچەماڵ (Chamchamal)</option>
                                <option value="شۆڕش">شۆڕش (Shorish)</option>
                                <option value="سەنگاو">سەنگاو (Sangaw)</option>
                                <option value="ئاغجەلەر">ئاغجەلەر (Aghjalar)</option>
                                <option value="قادرکەرەم">قادرکەرەم (Qadir Karam)</option>
                                <option value="دەربەندیخان">دەربەندیخان (Darbandikhan)</option>
                                <option value="کفری">کفری (Kifri)</option>
                                <option value="خانەقین">خانەقین (Khanaqin)</option>
                                <option value="قەرەتەپە">قەرەتەپە (Qaratapa)</option>
                                <option value="سەعدییە">سەعدییە (Saadiya)</option>
                                <option value="جەلەولا">جەلەولا (Jalawla)</option>
                                <option value="جەبارە">جەبارە (Jabara)</option>
                                <option value="مەندەلی">مەندەلی (Mandali)</option>
                                <option value="داقووق">داقووق (Daquq)</option>
                                <option value="حەویجە">حەویجە (Hawija)</option>
                                <option value="التون کۆپری">پردێ / التون کۆپری (Pirde)</option>
                                <option value="زاخۆ">زاخۆ (Zakho)</option>
                                <option value="باتێفا">باتێفا (Batifa)</option>
                                <option value="ئاکرێ">ئاکرێ (Akre)</option>
                                <option value="بجیل">بجیل (Bijil)</option>
                                <option value="گردەسێن">گردەسێن (Girdasin)</option>
                                <option value="ئامێدی">ئامێدی (Amedi)</option>
                                <option value="دێرەلووک">دێرەلووک (Deralok)</option>
                                <option value="شیلادزێ">شیلادزێ (Sheladize)</option>
                                <option value="بامەڕنێ">بامەڕنێ (Bamerne)</option>
                                <option value="سەرسەنگ">سەرسەنگ (Sarsang)</option>
                                <option value="کانی ماسێ">کانی ماسێ (Kani Mase)</option>
                                <option value="شێخان">شێخان (Shekhan)</option>
                                <option value="کەلەکچی">کەلەکچی (Kalakchi)</option>
                                <option value="بەردەڕەش">بەردەڕەش (Bardarash)</option>
                                <option value="سێمێل">سێمێل (Semel)</option>
                                <option value="زاوێتە">زاوێتە (Zawita)</option>
                                <option value="مانگێش">مانگێش (Mangesh)</option>
                                <option value="تەوێڵە">تەوێڵە / هەورامان (Tawella)</option>
                                <option value="بیارە">بیارە (Byara)</option>
                                <option value="خورماڵ">خورماڵ (Khurmal)</option>
                                <option value="سیروان">سیروان (Sirwan)</option>
                                <option value="بەمۆ">بەمۆ / گڵێجاڵ (Bamo)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="search-destination">شاری مەبەست</label>
                            <select id="search-destination" class="form-input">
                                <option value="">هەڵبژێرە</option>
                                <option value="هەولێر">هەولێر (Erbil)</option>
                                <option value="سلێمانی">سلێمانی (Sulaymaniyah)</option>
                                <option value="دهۆک">دهۆک (Duhok)</option>
                                <option value="کەرکوک">کەرکوک (Kirkuk)</option>
                                <option value="هەڵەبجە">هەڵەبجە (Halabja)</option>
                                <option value="سۆران">سۆران / دیانا (Soran)</option>
                                <option value="رواندز">رواندز (Rawanduz)</option>
                                <option value="شەقڵاوە">شەقڵاوە (Shaqlawa)</option>
                                <option value="خەلیفان">خەلیفان (Khalifan)</option>
                                <option value="حەریر">حەریر (Harir)</option>
                                <option value="چۆمان">چۆمان / باڵەکایەتی (Choman)</option>
                                <option value="حاجی ئۆمەران">حاجی ئۆمەران (Haji Omeran)</option>
                                <option value="قەسەرێ">قەسەرێ (Qasre)</option>
                                <option value="سمێلان">سمێلان (Smilan)</option>
                                <option value="گەڵاڵە">گەڵاڵە (Galala)</option>
                                <option value="مێرگەسوور">مێرگەسوور (Mergasor)</option>
                                <option value="شێروان مەزن">شێروان مەزن (Sherwan Mazan)</option>
                                <option value="بارزان">بارزان (Barzan)</option>
                                <option value="سیدەکان">سیدەکان / برادۆست (Sidakan)</option>
                                <option value="وەرتێ">وەرتێ (Warte)</option>
                                <option value="پیرمام">پیرمام / مەسیف (Pirmam)</option>
                                <option value="کۆیە">کۆیە (Koya)</option>
                                <option value="تەق تەق">تەق تەق (Taq Taq)</option>
                                <option value="خەبات">خەبات (Khabat)</option>
                                <option value="کەڵەک">کەڵەک / ڕزگاری (Kalak)</option>
                                <option value="مەخموور">مەخموور (Makhmour)</option>
                                <option value="قووشتەپە">قووشتەپە (Qushtapa)</option>
                                <option value="بەحرکە">بەحرکە (Baharka)</option>
                                <option value="کەسنەزان">کەسنەزان (Kasnazan)</option>
                                <option value="دارەتوو">دارەتوو (Daratoo)</option>
                                <option value="بنەسڵاوە">بنەسڵاوە (Bnaslawa)</option>
                                <option value="گوێڕ">گوێڕ (Guwer)</option>
                                <option value="شەمامک">شەمامک (Shamamk)</option>
                                <option value="ڕانیە">ڕانیە (Ranya)</option>
                                <option value="چوارقوڕنە">چوارقوڕنە (Chwarqurna)</option>
                                <option value="حاجیاوا">حاجیاوا (Hajiawa)</option>
                                <option value="سەرکەپکان">سەرکەپکان (Sarkapkan)</option>
                                <option value="بێتواتە">بێتواتە (Betwata)</option>
                                <option value="قەڵادزێ">قەڵادزێ / پشدەر (Qaladiza)</option>
                                <option value="سەنگەسەر">سەنگەسەر (Sangasor)</option>
                                <option value="ژاراوە">ژاراوە (Zharawa)</option>
                                <option value="ئیسێوێ">ئیسێوێ (Iswei)</option>
                                <option value="هێرۆ">هێرۆ (Hero)</option>
                                <option value="هەڵشۆ">هەڵشۆ (Halsho)</option>
                                <option value="دووکان">دووکان (Dukan)</option>
                                <option value="پیرەمەگروون">پیرەمەگروون (Piramagrun)</option>
                                <option value="بازیان">بازیان (Bazyan)</option>
                                <option value="تەکیە">تەکیەی کاکەمەند (Takya)</option>
                                <option value="سەید سادق">سەید سادق (Said Sadiq)</option>
                                <option value="پێنجوێن">پێنجوێن (Penjwen)</option>
                                <option value="گەرمک">گەرمک (Garmk)</option>
                                <option value="شەهرەزوور">شەهرەزوور / هەڵەبجەی تازە (Sharazoor)</option>
                                <option value="زەڕایەن">زەڕایەن / وارماوا (Zarayen)</option>
                                <option value="ماوەت">ماوەت (Mawat)</option>
                                <option value="چوارتا">چوارتا / شارباژێڕ (Chwarta)</option>
                                <option value="سیتەک">سیتەک (Sitek)</option>
                                <option value="قەرەداغ">قەرەداغ (Qaradagh)</option>
                                <option value="عەربەت">عەربەت (Arbat)</option>
                                <option value="بەکراژۆ">بەکراژۆ (Bakrajo)</option>
                                <option value="کەلار">کەلار (Kalar)</option>
                                <option value="رزگاری">رزگاری / حەسیرە (Rzgari)</option>
                                <option value="باوەنوور">باوەنوور (Bawanur)</option>
                                <option value="شێخ تەویل">شێخ تەویل / بەمۆ (Sheikh Tawil)</option>
                                <option value="چەمچەماڵ">چەمچەماڵ (Chamchamal)</option>
                                <option value="شۆڕش">شۆڕش (Shorish)</option>
                                <option value="سەنگاو">سەنگاو (Sangaw)</option>
                                <option value="ئاغجەلەر">ئاغجەلەر (Aghjalar)</option>
                                <option value="قادرکەرەم">قادرکەرەم (Qadir Karam)</option>
                                <option value="دەربەندیخان">دەربەندیخان (Darbandikhan)</option>
                                <option value="کفری">کفری (Kifri)</option>
                                <option value="خانەقین">خانەقین (Khanaqin)</option>
                                <option value="قەرەتەپە">قەرەتەپە (Qaratapa)</option>
                                <option value="سەعدییە">سەعدییە (Saadiya)</option>
                                <option value="جەلەولا">جەلەولا (Jalawla)</option>
                                <option value="جەبارە">جەبارە (Jabara)</option>
                                <option value="مەندەلی">مەندەلی (Mandali)</option>
                                <option value="داقووق">داقووق (Daquq)</option>
                                <option value="حەویجە">حەویجە (Hawija)</option>
                                <option value="التون کۆپری">پردێ / التون کۆپری (Pirde)</option>
                                <option value="زاخۆ">زاخۆ (Zakho)</option>
                                <option value="باتێفا">باتێفا (Batifa)</option>
                                <option value="ئاکرێ">ئاکرێ (Akre)</option>
                                <option value="بجیل">بجیل (Bijil)</option>
                                <option value="گردەسێن">گردەسێن (Girdasin)</option>
                                <option value="ئامێدی">ئامێدی (Amedi)</option>
                                <option value="دێرەلووک">دێرەلووک (Deralok)</option>
                                <option value="شیلادزێ">شیلادزێ (Sheladize)</option>
                                <option value="بامەڕنێ">بامەڕنێ (Bamerne)</option>
                                <option value="سەرسەنگ">سەرسەنگ (Sarsang)</option>
                                <option value="کانی ماسێ">کانی ماسێ (Kani Mase)</option>
                                <option value="شێخان">شێخان (Shekhan)</option>
                                <option value="کەلەکچی">کەلەکچی (Kalakchi)</option>
                                <option value="بەردەڕەش">بەردەڕەش (Bardarash)</option>
                                <option value="سێمێل">سێمێل (Semel)</option>
                                <option value="زاوێتە">زاوێتە (Zawita)</option>
                                <option value="مانگێش">مانگێش (Mangesh)</option>
                                <option value="تەوێڵە">تەوێڵە / هەورامان (Tawella)</option>
                                <option value="بیارە">بیارە (Byara)</option>
                                <option value="خورماڵ">خورماڵ (Khurmal)</option>
                                <option value="سیروان">سیروان (Sirwan)</option>
                                <option value="بەمۆ">بەمۆ / گڵێجاڵ (Bamo)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="search-date">بەرواری گەشت</label>
                            <input type="date" id="search-date" class="form-input">
                        </div>
                        <div class="form-group">
                            <label for="search-seats">کەمترین جێگا</label>
                            <input type="number" id="search-seats" class="form-input" min="1" max="7" placeholder="1">
                        </div>
                    </div>
                    <button type="button" id="search-btn" class="btn btn-primary btn-full">گەڕان</button>
                </div>
                <div id="search-results" class="search-results"></div>
            </section>

            <section id="my-trips-section" class="section tab-section">
                <div class="section-header">
                    <h2>گەشتەکانی من</h2>
                    <p>گەشتەکان بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە</p>
                </div>
                <div id="my-trips-container" class="trips-grid"></div>
            </section>

            <section id="my-bookings-section" class="section tab-section">
                <div class="section-header">
                    <h2>گەشتە داواکراوەکانی من</h2>
                    <p>گەشتەکان بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە بەڕێوە</p>
                </div>
                <div id="my-bookings-container" class="trips-grid"></div>
            </section>
        </div>
    </main>

    <!-- ==========================================================================
       Footer Section
       ========================================================================== -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2026 شەریک - پلاتفۆڕمی هاوبەشکردنی گەشت لە کوردستان</p>
        </div>
    </footer>

    <!-- ==========================================================================
       JavaScript
       ========================================================================== -->
    <script>
        // User data from PHP
        const userName = '<?= $currentUserName ?>';
        const userPhone = '<?= $currentPhone ?>';

        // API endpoint
        const API_URL = 'api.php';

        // DOM elements
        const navBtns = document.querySelectorAll('.nav-btn');
        const sections = document.querySelectorAll('.tab-section');
        const authOpenBtn = document.getElementById('auth-open-btn');
        const logoutBtn = document.getElementById('logout-btn');
        const userWelcome = document.getElementById('user-welcome');

        // Tab switching
        navBtns.forEach(btn => {
            if (btn.dataset.tab) {
                btn.addEventListener('click', () => {
                    const tab = btn.dataset.tab;
                    navBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    sections.forEach(s => s.classList.remove('active'));
                    document.getElementById(tab + '-section').classList.add('active');
                });
            }
        });

        // Auth state
        function checkAuth() {
            if (userName) {
                userWelcome.style.display = 'inline';
                userWelcome.textContent = 'بەخێربێیت، ' + userName;
                authOpenBtn.style.display = 'none';
                logoutBtn.style.display = 'inline';
                document.querySelectorAll('.nav-btn-my-trips, .nav-btn-my-bookings').forEach(btn => btn.style.display = 'inline');
            }
        }

        checkAuth();

        // Logout
        logoutBtn.addEventListener('click', async () => {
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'logout', csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>' })
                });
                const json = await res.json();
                if (json.success) {
                    window.location.href = 'index.html';
                } else {
                    alert(json.message);
                }
            } catch (err) {
                alert('هەڵە: ' + err.message);
            }
        });

        // Theme toggle
        const themeToggle = document.getElementById('theme-toggle');
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('sharek-theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light');
        });

        // Trip form
        const tripForm = document.getElementById('trip-form');
        const map = L.map('map').setView([36.2, 44.0], 7);
        L.tileLayer(`https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=vRW5Z4GyqXenVG3MzVkM`, {
            attribution: '\u00a9 <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> \u00a9 <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
            maxZoom: 20,
            crossOrigin: true,
        }).addTo(map);

        let marker = null;

        map.on('click', function(e) {
            const { lat, lng } = e.latlng;
            if (marker) map.removeLayer(marker);
            marker = L.marker([lat, lng]).addTo(map);
            document.getElementById('trip-latitude').value = lat;
            document.getElementById('trip-longitude').value = lng;
            document.getElementById('trip-departure').value = getCityFromCoords(lat, lng);
        });

        document.getElementById('recenter-map-btn').addEventListener('click', () => {
            map.setView([36.2, 44.0], 7);
            if (marker) map.removeLayer(marker);
            document.getElementById('trip-latitude').value = '';
            document.getElementById('trip-longitude').value = '';
            document.getElementById('trip-departure').value = '';
        });

        function getCityFromCoords(lat, lng) {
            const cities = {
                'هەولێر': [36.19, 43.99],
                'سلێمانی': [35.55, 45.43],
                'دهۆک': [36.87, 42.99],
                'کەرکوک': [35.47, 44.39],
                'هەڵەبجە': [35.18, 45.98]
            };
            let closest = '';
            let minDist = Infinity;
            for (const [city, coords] of Object.entries(cities)) {
                const dist = Math.sqrt(Math.pow(lat - coords[0], 2) + Math.pow(lng - coords[1], 2));
                if (dist < minDist) {
                    minDist = dist;
                    closest = city;
                }
            }
            return closest;
        }

        tripForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(tripForm);
            formData.append('action', 'create_trip');
            formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');

            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();
                if (json.success) {
                    alert('گەشتەکە تۆمار کرا!');
                    tripForm.reset();
                    if (marker) map.removeLayer(marker);
                } else {
                    alert(json.message);
                }
            } catch (err) {
                alert('هەڵە: ' + err.message);
            }
        });

        // Load trips
        async function loadTrips() {
            try {
                const res = await fetch(`${API_URL}?action=get_trips`);
                const json = await res.json();
                if (json.success && json.trips) {
                    displayTrips(json.trips);
                    document.getElementById('active-trips-count').textContent = json.trips.length;
                }
            } catch (err) {
                console.error('Error loading trips:', err);
            }
        }

        function displayTrips(trips) {
            const container = document.getElementById('trips-feed-container');
            container.innerHTML = trips.map(trip => `
                <div class="trip-card">
                    <div class="trip-header">
                        <h3>${trip.departure_city} → ${trip.destination_city}</h3>
                        <span class="trip-date">${trip.trip_date}</span>
                    </div>
                    <div class="trip-body">
                        <p><strong>شۆفێر:</strong> ${trip.driver_name}</p>
                        <p><strong>تەلەفۆن:</strong> ${trip.driver_phone}</p>
                        <p><strong>ئۆتۆمبێل:</strong> ${trip.vehicle_model} - ${trip.vehicle_color}</p>
                        <p><strong>کات:</strong> ${trip.trip_time}</p>
                        <p><strong>جێگا:</strong> ${trip.available_seats}</p>
                        <p><strong>نرخ:</strong> ${trip.price} دینار</p>
                    </div>
                    <div class="trip-footer">
                        <button class="btn btn-book" onclick="bookTrip(${trip.id})">ڕێزەگرتن</button>
                    </div>
                </div>
            `).join('');
        }

        async function bookTrip(tripId) {
            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'book_seat', trip_id: tripId, csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>' })
                });
                const json = await res.json();
                if (json.success) {
                    alert('ڕێزەگرتن تەواو بوو!');
                } else {
                    alert(json.message);
                }
            } catch (err) {
                alert('هەڵە: ' + err.message);
            }
        }

        // Search trips
        document.getElementById('search-btn').addEventListener('click', async () => {
            const departure = document.getElementById('search-departure').value;
            const destination = document.getElementById('search-destination').value;
            const date = document.getElementById('search-date').value;
            const seats = document.getElementById('search-seats').value;

            try {
                const res = await fetch(`${API_URL}?action=search_trips&departure=${departure}&destination=${destination}&date=${date}&seats=${seats}`);
                const json = await res.json();
                if (json.success && json.trips) {
                    displaySearchResults(json.trips);
                } else {
                    document.getElementById('search-results').innerHTML = '<p>هیچ گەشتێک نەدۆزرایەوە</p>';
                }
            } catch (err) {
                alert('هەڵە: ' + err.message);
            }
        });

        function displaySearchResults(trips) {
            const container = document.getElementById('search-results');
            container.innerHTML = trips.map(trip => `
                <div class="trip-card">
                    <div class="trip-header">
                        <h3>${trip.departure_city} → ${trip.destination_city}</h3>
                        <span class="trip-date">${trip.trip_date}</span>
                    </div>
                    <div class="trip-body">
                        <p><strong>شۆفێر:</strong> ${trip.driver_name}</p>
                        <p><strong>تەلەفۆن:</strong> ${trip.driver_phone}</p>
                        <p><strong>ئۆتۆمبێل:</strong> ${trip.vehicle_model} - ${trip.vehicle_color}</p>
                        <p><strong>کات:</strong> ${trip.trip_time}</p>
                        <p><strong>جێگا:</strong> ${trip.available_seats}</p>
                        <p><strong>نرخ:</strong> ${trip.price} دینار</p>
                    </div>
                    <div class="trip-footer">
                        <button class="btn btn-book" onclick="bookTrip(${trip.id})">ڕێزەگرتن</button>
                    </div>
                </div>
            `).join('');
        }

        // Load trips on page load
        loadTrips();
    </script>
</body>
</html>
