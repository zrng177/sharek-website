/* Sharek Website - Map Preview JavaScript */
/* Kurdish Sorani (سۆرانی) - Lazy-loaded Leaflet Map */

// ── MapTiler API Key ──────────────────────────────────────────────────────────
// Replace the value below with your key from https://cloud.maptiler.com/account/keys
const MAPTILER_API_KEY = 'vRW5Z4GyqXenVG3MzVkM';
// ─────────────────────────────────────────────────────────────────────────────

let mapInitialized = false;
let map = null;

function initMap() {
    if (mapInitialized) return;
    
    // Initialize Leaflet map centered on Kurdistan
    map = L.map('live-map').setView([36.5, 44.0], 7);
    
    L.tileLayer(`https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=${MAPTILER_API_KEY}`, {
        attribution: '\u00a9 <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> \u00a9 <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
        maxZoom: 20,
        crossOrigin: true,
    }).addTo(map);
    
    // Fetch trips from API
    fetchTrips();
    
    mapInitialized = true;
}

async function fetchTrips() {
    try {
        const response = await fetch('api.php?action=get_map_trips');
        const data = await response.json();
        
        if (data.success && data.data && data.data.length > 0) {
            // Wrap each marker individually (audit finding #33) so a single
            // bad record can't throw inside the loop and abort every
            // remaining marker — Leaflet throws if it gets undefined coords.
            data.data.forEach(trip => {
                try {
                    addTripMarker(trip);
                } catch (error) {
                    console.error('Error adding trip marker:', error);
                }
            });
        } else {
            // Fallback: show static markers
            addFallbackMarkers();
        }
    } catch (error) {
        console.error('Error fetching map trips:', error);
        // Fallback: show static markers
        addFallbackMarkers();
    }
}

function addTripMarker(trip) {
    // getMapTrips() returns latitude/longitude, not departure_lat/
    // departure_lng (audit finding #33) — using the wrong field names
    // meant every marker got undefined coordinates and Leaflet threw.
    const marker = L.circleMarker([trip.latitude, trip.longitude], {
        color: '#1e3a8a',
        fillColor: '#3b82f6',
        fillOpacity: 0.7,
        radius: 8
    }).addTo(map);
    
    // Create popup in Kurdish
    const popupContent = `
        <div style="direction: rtl; text-align: right; font-family: Vazirmatn, sans-serif;">
            <strong>شارکردن:</strong> ${trip.departure_city}<br>
            <strong>بەروار:</strong> ${trip.date_time}<br>
            <strong>نرخ:</strong> ${trip.price_iqd} دینار<br>
            <strong>کورسی خاوەنداری:</strong> ${trip.seats_available}
        </div>
    `;
    
    marker.bindPopup(popupContent);
}

function addFallbackMarkers() {
    // Static markers for major Kurdish cities
    const cities = [
        { lat: 36.1904, lng: 43.9932, name: 'هەولێر' },
        { lat: 35.5553, lng: 45.4750, name: 'سلێمانی' }
    ];
    
    cities.forEach(city => {
        const marker = L.circleMarker([city.lat, city.lng], {
            color: '#1e3a8a',
            fillColor: '#3b82f6',
            fillOpacity: 0.7,
            radius: 8
        }).addTo(map);
        
        marker.bindPopup(`<strong>${city.name}</strong>`);
    });
}

// Lazy-load map when it enters viewport
function setupLazyMap() {
    const mapElement = document.getElementById('live-map');
    
    if (!mapElement) return;
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                // Load Leaflet CSS and JS dynamically
                loadLeaflet();
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1
    });
    
    observer.observe(mapElement);
}

function loadLeaflet() {
    // Load Leaflet CSS
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(link);
    
    // Load Leaflet JS
    const script = document.createElement('script');
    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    script.onload = () => {
        initMap();
    };
    document.head.appendChild(script);
}

// Initialize lazy loading when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupLazyMap);
} else {
    setupLazyMap();
}
