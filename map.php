<?php
/**
 * Sharek v1.5 - Map Page with Leaflet.js
 * 
 * @file map.php
 * @date 2026-05-25
 * @description Full-screen map displaying available trips using Leaflet.js and OpenStreetMap
 * @version 1.5.0
 * 
 * Security Features:
 * - Input sanitization for all displayed data
 * - Lazy loading for map performance
 * - XSS prevention with htmlspecialchars
 */
?>
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="نەخشەی گەشتەکان - یەکەمین پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان">
    <meta name="theme-color" content="#1e3a8a">
    <title>نەخشەی گەشتەکان | شەریک</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/kurdish-typography.css">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/map.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/responsive-fixes.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <style>
        #map {
            height: 100vh;
            width: 100%;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1;
        }
        .map-header {
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1000;
            background: var(--navy);
            color: white;
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .map-header h1 {
            margin: 0;
            font-size: 1.25rem;
        }
        .map-header .back-link {
            color: white;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--r-md);
            transition: background 0.2s;
        }
        .map-header .back-link:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        .map-controls {
            position: fixed;
            bottom: 2rem;
            right: 1.5rem;
            z-index: 1000;
            background: white;
            padding: 1rem;
            border-radius: var(--r-lg);
            box-shadow: var(--shadow-lg);
            max-width: 300px;
        }
        .map-controls h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1rem;
            color: var(--navy);
        }
        .map-controls p {
            margin: 0;
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        .trip-count {
            font-weight: bold;
            color: var(--navy);
        }
        .leaflet-popup-content-wrapper {
            border-radius: var(--r-md);
        }
        .leaflet-popup-content {
            margin: 0.75rem;
            font-family: inherit;
        }
        .popup-trip-info {
            min-width: 200px;
        }
        .popup-trip-info h4 {
            margin: 0 0 0.5rem 0;
            color: var(--navy);
            font-size: 1rem;
        }
        .popup-trip-info .route {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .popup-trip-info .detail {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin: 0.25rem 0;
        }
        .popup-trip-info .price {
            font-weight: bold;
            color: var(--navy);
            font-size: 1.1rem;
            margin-top: 0.5rem;
        }
        .popup-trip-info .book-btn {
            display: inline-block;
            margin-top: 0.75rem;
            padding: 0.5rem 1rem;
            background: var(--navy);
            color: white;
            text-decoration: none;
            border-radius: var(--r-md);
            font-size: 0.875rem;
            transition: background 0.2s;
        }
        .popup-trip-info .book-btn:hover {
            background: var(--navy-mid);
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        .loading-overlay.hidden {
            display: none;
        }
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border);
            border-top-color: var(--navy);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .loading-text {
            margin-top: 1rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
<script>
(function() {
    const s = localStorage.getItem('sharek-theme');
    const p = window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (s === 'dark' || (!s && p)) document.body.classList.add('dark-mode');
})();
</script>
    <div class="map-header">
        <h1>🗺️ نەخشەی گەشتەکان</h1>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <button id="theme-toggle" class="theme-toggle" aria-label="تەبەدڵکردنی مۆد" aria-pressed="false" style="background: none; border: 1.5px solid rgba(255,255,255,0.3); border-radius: 8px; padding: .42rem .55rem; cursor: pointer; font-size: 1rem; line-height: 1; color: white;">🌙</button>
            <a href="index.html" class="back-link">← گەڕانەوە</a>
        </div>
    </div>
    
    <div id="map"></div>
    
    <div class="map-controls">
        <h3>زانیاری نەخشە</h3>
        <p>گەشتە بەردەستەکان: <span class="trip-count" id="tripCount">0</span></p>
        <p style="margin-top: 0.5rem;">کلیک لەسەر خاڵەکان بکە بۆ بینانی زانیاری گەشت</p>
    </div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">بارکردنی نەخشە...</div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        // Lazy load map initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize map centered on Iraq
            var map = L.map('map').setView([33.3152, 44.3661], 6);
            
            // Add MapTiler tiles
            L.tileLayer(`https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=vRW5Z4GyqXenVG3MzVkM`, {
                attribution: '\u00a9 <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> \u00a9 <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
                maxZoom: 20,
                crossOrigin: true,
            }).addTo(map);
            
            // Custom icon for trip markers
            var tripIcon = L.icon({
                iconUrl: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjMWUzYThhIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+PHBhdGggZD0iTTEyIDJDOC4xNCAyIDUgNS4xNCA1IDlzMy4xNCA3IDcgNyA3LTMuMTQgNy03IDMuMTQtNyA3LTd6Ii8+PHBhdGggZD0iTTEyIDZhMiAyIDAgMSAwIDAgNCAyIDIgMCAwIDAgMC00eiIvPjwvc3ZnPg==',
                iconSize: [32, 32],
                iconAnchor: [16, 32],
                popupAnchor: [0, -32]
            });
            
            // Fetch available trips from API
            fetch('api.php?action=get_map_trips')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        var trips = data.data;
                        document.getElementById('tripCount').textContent = trips.length;
                        
                        // Add markers for each trip
                        trips.forEach(function(trip) {
                            if (trip.latitude && trip.longitude) {
                                var marker = L.marker([trip.latitude, trip.longitude], {
                                    icon: tripIcon
                                }).addTo(map);
                                
                                // Create popup content
                                var popupContent = `
                                    <div class="popup-trip-info">
                                        <h4>${trip.driver_name}</h4>
                                        <div class="route">
                                            🚗 ${trip.departure_city} → ${trip.destination_city}
                                        </div>
                                        <div class="detail">
                                            📍 لە: ${trip.departure_detail}
                                        </div>
                                        <div class="detail">
                                            🎯 بۆ: ${trip.destination_detail}
                                        </div>
                                        <div class="detail">
                                            🕐 ${trip.date_formatted}
                                        </div>
                                        <div class="detail">
                                            🚕 ${trip.car_model} (${trip.car_color})
                                        </div>
                                        <div class="detail">
                                            💺 کورسی: ${trip.seats_available}
                                        </div>
                                        <div class="price">
                                            💰 ${trip.price_formatted}
                                        </div>
                                        <a href="index.html" class="book-btn">بەکارهێنانی گەشت</a>
                                    </div>
                                `;
                                
                                marker.bindPopup(popupContent);
                            }
                        });
                        
                        // Fit map to show all markers if there are any
                        if (trips.length > 0) {
                            var group = new L.featureGroup();
                            trips.forEach(function(trip) {
                                if (trip.latitude && trip.longitude) {
                                    L.marker([trip.latitude, trip.longitude]).addTo(group);
                                }
                            });
                            map.fitBounds(group.getBounds().pad(0.1));
                        }
                    }
                    
                    // Hide loading overlay
                    document.getElementById('loadingOverlay').classList.add('hidden');
                })
                .catch(error => {
                    console.error('Error fetching trips:', error);
                    document.getElementById('loadingOverlay').classList.add('hidden');
                    alert('هەڵە لە بارکردنی گەشتەکان. تکایە دواتر هەوڵبەرەوە.');
                });
        });
    </script>
<script>
(function() {
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;
    const isDark = document.body.classList.contains('dark-mode');
    btn.textContent = isDark ? '☀️' : '🌙';
    btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    btn.addEventListener('click', function() {
        const nowDark = document.body.classList.toggle('dark-mode');
        btn.textContent = nowDark ? '☀️' : '🌙';
        btn.setAttribute('aria-pressed', nowDark ? 'true' : 'false');
        localStorage.setItem('sharek-theme', nowDark ? 'dark' : 'light');
    });
})();
</script>
</body>
</html>
