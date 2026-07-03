/* ==========================================================================
   BLOCK 1: Global Variable Declarations
   ========================================================================== */
const API_URL = 'api.php';
const fetchOpts = { credentials: 'same-origin' };

// ── MapTiler API Key ──────────────────────────────────────────────────────────
// SECURITY NOTE: This key is visible to all visitors in page source.
// To prevent abuse, log in to https://cloud.maptiler.com/account/keys and add
// an "Allowed HTTP Origins" domain restriction (e.g. yourdomain.com) so the
// key only works from your site, not from a stranger's machine.
// Free tier: 100,000 map loads/month — more than enough for a growing app.
const MAPTILER_API_KEY = 'vRW5Z4GyqXenVG3MzVkM';
// ─────────────────────────────────────────────────────────────────────────────

let currentUser = null;
let pendingTripSubmit = false;
let activeBookingTrip = null;

// Toggle password visibility
window.togglePassword = function(inputId) {
    const input = document.getElementById(inputId);
    const button = input.nextElementSibling;
    
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = '🔒';
    } else {
        input.type = 'password';
        button.textContent = '👁️';
    }
};

// ── Global Service Type Toggle Function ──
window.toggleSeatsInputBasedOnService = function(value) {
    const seatsInput = document.getElementById('trip-seats');
    const helperBadge = document.getElementById('delivery-hint');
    if (!seatsInput) return;
    const p = seatsInput.parentElement;
    if (value === 'delivery') {
        seatsInput.value = '0';
        seatsInput.disabled = true;
        if (p) p.style.display = 'none';
        if (helperBadge) helperBadge.style.display = 'block';
    } else {
        seatsInput.disabled = false;
        if (p) p.style.display = 'block';
        if (helperBadge) helperBadge.style.display = 'none';
    }
};

// Geolocation validation state
let userGPSLocation = null;
let detectedGPSCity = null;
let geolocationPermissionGranted = false;
let isLocationGranted = false;

// Map-to-App City Translation Dictionary
// ============================================================================
// GLOBAL CONSTANTS: City Mapping Dictionary & Intermediate Routes Matrix
// ============================================================================
// These constants provide bidirectional mapping between raw API city names
// and Kurdish localized display names, plus a matrix of intermediate cities
// for dynamic route visualization.
// ============================================================================
// v1.2: Expanded with Kurdish-script keys so Nominatim responses in 'ku'
// locale are matched directly, not just through lowercased English strings.
const mapCityToAppCity = {
    // ── Erbil / Hawler region ──────────────────────────────────────────────
    'erbil': 'هەولێر', 'hawler': 'هەولێر', 'arbil': 'هەولێر', 'irbil': 'هەولێر',
    'هولير': 'هەولێر', 'أربيل': 'هەولێر', 'اربيل': 'هەولێر', 'هەولێر': 'هەولێر',
    'shaqlawa': 'شەقڵاوە', 'شەقلاوة': 'شەقڵاوە', 'شەقڵاوە': 'شەقڵاوە',
    'soran': 'سۆران', 'سوران': 'سۆران', 'سۆران': 'سۆران',
    'rawanduz': 'رواندز', 'rawandiz': 'رواندز', 'رواندز': 'رواندز',
    'khalifan': 'خەلیفان', 'خليفان': 'خەلیفان', 'خەلیفان': 'خەلیفان',
    'harir': 'حەریر', 'حریر': 'حەریر', 'حەریر': 'حەریر',
    'choman': 'چۆمان', 'چۆمان': 'چۆمان',
    'koya': 'کۆیە', 'كويه': 'کۆیە', 'کۆیە': 'کۆیە',
    'taq taq': 'تەق تەق', 'taqtaq': 'تەق تەق', 'تق تق': 'تەق تەق', 'تەق تەق': 'تەق تەق',
    'khabat': 'خەبات', 'خەبات': 'خەبات',
    'makhmour': 'مەخموور', 'مخمور': 'مەخموور', 'مەخموور': 'مەخموور',
    'kalak': 'کەڵەک', 'كلك': 'کەڵەک', 'کەڵەک': 'کەڵەک',
    'qushtapa': 'قووشتەپە', 'قوشتبه': 'قووشتەپە', 'قووشتەپە': 'قووشتەپە',
    'haji omeran': 'حاجی ئۆمەران', 'حاجي اومران': 'حاجی ئۆمەران', 'حاجی ئۆمەران': 'حاجی ئۆمەران',
    'qasre': 'قەسەرێ', 'قصره': 'قەسەرێ', 'قەسەرێ': 'قەسەرێ',
    'smilan': 'سمێلان', 'سميلان': 'سمێلان', 'سمێلان': 'سمێلان',
    'galala': 'گەڵاڵە', 'گلاله': 'گەڵاڵە', 'گەڵاڵە': 'گەڵاڵە',
    'mergasor': 'مێرگەسوور', 'مرگسور': 'مێرگەسوور', 'مێرگەسوور': 'مێرگەسوور',
    'sherwan mazan': 'شێروان مەزن', 'شروان مازن': 'شێروان مەزن', 'شێروان مەزن': 'شێروان مەزن',
    'barzan': 'بارزان', 'برزان': 'بارزان', 'بارزان': 'بارزان',
    'sidakan': 'سیدەکان', 'سيدكان': 'سیدەکان', 'سیدەکان': 'سیدەکان',
    'warte': 'وەرتێ', 'ورته': 'وەرتێ', 'وەرتێ': 'وەرتێ',
    'pirmam': 'پیرمام', 'پيرمام': 'پیرمام', 'پیرمام': 'پیرمام',
    'baharka': 'بەحرکە', 'بحركه': 'بەحرکە', 'بەحرکە': 'بەحرکە',
    'kasnazan': 'کەسنەزان', 'كسنزان': 'کەسنەزان', 'کەسنەزان': 'کەسنەزان',
    'daratoo': 'دارەتوو', 'داراتو': 'دارەتوو', 'دارەتوو': 'دارەتوو',
    'bnaslawa': 'بنەسڵاوە', 'بنسلاوه': 'بنەسڵاوە', 'بنەسڵاوە': 'بنەسڵاوە',
    'guwer': 'گوێڕ', 'گوير': 'گوێڕ', 'گوێڕ': 'گوێڕ',
    'shamamk': 'شەمامک', 'شمامك': 'شەمامک', 'شەمامک': 'شەمامک',
    // ── Sulaymaniyah region ────────────────────────────────────────────────
    'sulaymaniyah': 'سلێمانی', 'slemani': 'سلێمانی', 'as sulaymaniyah': 'سلێمانی',
    'السليمانية': 'سلێمانی', 'سليمانية': 'سلێمانی', 'سلێمانی': 'سلێمانی',
    'parêzgeha silêmaniyê': 'سلێمانی', 'parêzgeha silêmanî': 'سلێمانی', 'parêzgeha slemaniyê': 'سلێمانی',
    'sulaymaniyah governorate': 'سلێمانی', 'slemani governorate': 'سلێمانی',
    'ranya': 'ڕانیە', 'raniyah': 'ڕانیە', 'رانية': 'ڕانیە', 'ڕانیە': 'ڕانیە',
    'chwarqurna': 'چوارقوڕنە', 'چوارقورنه': 'چوارقوڕنە', 'چوارقوڕنە': 'چوارقوڕنە',
    'hajiawa': 'حاجیاوا', 'حجياوه': 'حاجیاوا', 'حاجیاوا': 'حاجیاوا',
    'sarkapkan': 'سەرکەپکان', 'سركپكان': 'سەرکەپکان', 'سەرکەپکان': 'سەرکەپکان',
    'betwata': 'بێتواتە', 'بتواته': 'بێتواتە', 'بێتواتە': 'بێتواتە',
    'qaladiza': 'قەڵادزێ', 'قلادزه': 'قەڵادزێ', 'قەڵادزێ': 'قەڵادزێ',
    'sangasor': 'سەنگەسەر', 'سنگاسر': 'سەنگەسەر', 'سەنگەسەر': 'سەنگەسەر',
    'zharawa': 'ژاراوە', 'زاروه': 'ژاراوە', 'ژاراوە': 'ژاراوە',
    'iswei': 'ئیسێوێ', 'اسوي': 'ئیسێوێ', 'ئیسێوێ': 'ئیسێوێ',
    'hero': 'هێرۆ', 'هيرو': 'هێرۆ', 'هێرۆ': 'هێرۆ',
    'halsho': 'هەڵشۆ', 'هلشو': 'هەڵشۆ', 'هەڵشۆ': 'هەڵشۆ',
    'dukan': 'دووکان', 'dokan': 'دووکان', 'دوكان': 'دووکان', 'دووکان': 'دووکان',
    'piramagrun': 'پیرەمەگروون', 'pira magrun': 'پیرەمەگروون', 'پیرەمەگروون': 'پیرەمەگروون',
    'bazyan': 'بازیان', 'بازيان': 'بازیان', 'بازیان': 'بازیان',
    'takya': 'تەکیە', 'تكية': 'تەکیە', 'تەکیە': 'تەکیە',
    'said sadiq': 'سەید سادق', 'saidsadiq': 'سەید سادق', 'سعيد صادق': 'سەید سادق', 'سەید سادق': 'سەید سادق',
    'penjwen': 'پێنجوێن', 'بنجوين': 'پێنجوێن', 'پێنجوێن': 'پێنجوێن',
    'sharazoor': 'شەهرەزوور', 'شهرزور': 'شەهرەزوور', 'شەهرەزوور': 'شەهرەزوور',
    'zarayen': 'زەڕایەن', 'زراين': 'زەڕایەن', 'زەڕایەن': 'زەڕایەن',
    'mawat': 'ماوەت', 'ماووت': 'ماوەت', 'ماوەت': 'ماوەت',
    'chwarta': 'چوارتا', 'چوارته': 'چوارتا', 'چوارتا': 'چوارتا',
    'qaradagh': 'قەرەداغ', 'قره داغ': 'قەرەداغ', 'قەرەداغ': 'قەرەداغ',
    'arbat': 'عەربەت', 'عربت': 'عەربەت', 'عەربەت': 'عەربەت',
    'kalar': 'کەلار', 'كلار': 'کەلار', 'کەلار': 'کەلار',
    'chamchamal': 'چەمچەماڵ', 'جمجمال': 'چەمچەماڵ', 'چەمچەماڵ': 'چەمچەماڵ',
    'shorish': 'شۆڕش', 'شورش': 'شۆڕش', 'شۆڕش': 'شۆڕش',
    'darbandikhan': 'دەربەندیخان', 'دربنديخان': 'دەربەندیخان', 'دەربەندیخان': 'دەربەندیخان',
    'sangaw': 'سەنگاو', 'سنكاو': 'سەنگاو', 'سەنگاو': 'سەنگاو',
    // ── Duhok region ──────────────────────────────────────────────────────
    'duhok': 'دهۆک', 'dahuk': 'دهۆک', 'دهوك': 'دهۆک', 'دهۆک': 'دهۆک',
    'zakho': 'زاخۆ', 'زاخو': 'زاخۆ', 'زاخۆ': 'زاخۆ',
    'akre': 'ئاکرێ', 'عقره': 'ئاکرێ', 'ئاکرێ': 'ئاکرێ',
    'amedi': 'ئامێدی', 'العمادية': 'ئامێدی', 'ئامێدی': 'ئامێدی',
    'shekhan': 'شێخان', 'الشيخان': 'شێخان', 'شێخان': 'شێخان',
    'bardarash': 'بەردەڕەش', 'بردرش': 'بەردەڕەش', 'بەردەڕەش': 'بەردەڕەش',
    'semel': 'سێمێل', 'سميل': 'سێمێل', 'سێمێل': 'سێمێل',
    'zawita': 'زاوێتە', 'زاويتة': 'زاوێتە', 'زاوێتە': 'زاوێتە',
    // ── Kirkuk & south ────────────────────────────────────────────────────
    'kirkuk': 'کەرکوک', 'kerkuk': 'کەرکوک', 'kirkuk governorate': 'کەرکوک',
    'کرکوک': 'کەرکوک', 'كركوك': 'کەرکوک', 'کەرکوک': 'کەرکوک',
    'halabja': 'هەڵەبجە', 'halabjah': 'هەڵەبجە', 'حلبجة': 'هەڵەبجە', 'هەڵەبجە': 'هەڵەبجە',
    'khanaqin': 'خانەقین', 'خانقين': 'خانەقین', 'خانەقین': 'خانەقین',
    'kifri': 'کفری', 'كفري': 'کفری', 'کفری': 'کفری'
};

// Intermediate Cities Route Matrix
const intermediateRoutes = {
    'سلێمانی-هەولێر': ['بازیان', 'تەکیە', 'چەمچەماڵ', 'شۆڕش', 'ئاغجەلەر', 'تەق تەق', 'کۆیە', 'قووشتەپە'],
    'هەولێر-سلێمانی': ['قووشتەپە', 'کۆیە', 'تەق تەق', 'ئاغجەلەر', 'شۆڕش', 'چەمچەماڵ', 'تەکیە', 'بازیان'],
    'سلێمانی-دهۆک': ['بازیان', 'چەمچەماڵ', 'التون کۆپری', 'کەڵەک', 'بەردەڕەش', 'کەلەکچی', 'شێخان', 'زاوێتە'],
    'دهۆک-سلێمانی': ['زاوێتە', 'شێخان', 'کەلەکچی', 'بەردەڕەش', 'کەڵەک', 'التون کۆپری', 'چەمچەماڵ', 'بازیان'],
    'هەولێر-دهۆک': ['خەبات', 'کەڵەک', 'بەردەڕەش', 'کەلەکچی', 'شێخان', 'سێمێل'],
    'دهۆک-هەولێر': ['سێمێل', 'شێخان', 'کەلەکچی', 'بەردەڕەش', 'کەڵەک', 'خەبات'],
    'سلێمانی-کەرکوک': ['بازیان', 'تەکیە', 'چەمچەماڵ', 'شۆڕش'],
    'کەرکوک-سلێمانی': ['شۆڕش', 'چەمچەماڵ', 'تەکیە', 'بازیان'],
    'هەولێر-کەرکوک': ['قووشتەپە', 'التون کۆپری'],
    'کەرکوک-هەولێر': ['التون کۆپری', 'قووشتەپە'],
    'سلێمانی-کەلار': ['عەربەت', 'زەڕایەن', 'دەربەندیخان', 'باوەنوور', 'رزگاری'],
    'سلێمانی-ڕانیە': ['پیرەمەگروون', 'دووکان', 'بێتواتە', 'چوارقوڕنە'],
    'ڕانیە-هەولێر': ['حاجیاوا', 'سەرکەپکان', 'خەلیفان', 'حەریر', 'شەقڵاوە', 'پیرمام'],
    'هەولێر-سۆران': ['پیرمام', 'شەقڵاوە', 'حەریر', 'خەلیفان']
};

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Custom Toast Notification System
function showToast(type, message, duration = 3000) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };

    const icon = icons[type] || icons.info;

    toast.innerHTML = `
        <span class="toast-icon">${icon}</span>
        <div class="toast-content">
            <div class="toast-message">${escapeHtml(message)}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">×</button>
    `;

    container.appendChild(toast);

    // Auto-dismiss after duration
    setTimeout(() => {
        if (toast.parentElement) {
            toast.classList.add('removing');
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }
    }, duration);
}

// Replace showAlert with custom toast
function showAlert(type, message) {
    showToast(type, message, 3000);
}

// Welcome Banner for new registrations
function showWelcomeBanner() {
    const banner = document.createElement('div');
    banner.className = 'welcome-banner';
    banner.innerHTML = `
        <div class="welcome-banner-content">
            <span class="welcome-banner-icon">🎉</span>
            <span class="welcome-banner-text">خۆشەویستانە بەخێربێیت!</span>
        </div>
    `;
    banner.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        color: white;
        padding: 1rem;
        text-align: center;
        font-size: 1.25rem;
        font-weight: 600;
        z-index: 9999;
        animation: slideDown 0.5s ease-out;
    `;
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        .welcome-banner-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }
        .welcome-banner-icon {
            font-size: 1.5rem;
        }
    `;
    document.head.appendChild(style);
    document.body.appendChild(banner);
    
    setTimeout(() => {
        banner.style.animation = 'fadeOut 0.5s ease-out forwards';
        setTimeout(() => banner.remove(), 500);
    }, 5000);
}

// Custom Car Icon for User/Driver Marker
const carIcon = L.icon({
    iconUrl: 'https://cdn-icons-png.flaticon.com/512/744/744465.png',
    iconSize: [40, 40],
    iconAnchor: [20, 20],
    popupAnchor: [0, -20]
});

// ============================================================================
// GEOLOCATION LOCKER: GPS-based Departure City Enforcement
// ============================================================================
// This function enforces GPS-based departure city selection to prevent fraud.
// It normalizes city names using the mapCityToAppCity dictionary and locks
// the departure city field to prevent manual tampering.
// ============================================================================
function autoSelectDepartureCity(detectedCity) {
    const departureCitySelect = document.getElementById('trip-departure');
    if (!departureCitySelect) return;

    // If no city detected (e.g., province name was filtered), show message and unlock
    if (!detectedCity) {
        departureCitySelect.value = '';
        departureCitySelect.style.pointerEvents = 'auto';
        departureCitySelect.classList.remove('gps-locked');
        departureCitySelect.classList.add('gps-error');
        showToast('warning', 'شوێنەکەت بە وردی نەدۆزرایەوە! تکایە ناوی قەزا یان شارەکەت بە دەستی خۆت دیاری بکە.', 5000);
        departureCitySelect.focus();
        return;
    }

    // Try to map the detected city using mapCityToAppCity dictionary
    const matchedCity = mapCityToAppCity[detectedCity.toLowerCase().trim()] || detectedCity;

    // Check if the matched city exists as a valid <option> value:
    const optionExists = Array.from(departureCitySelect.options)
        .some(opt => opt.value === matchedCity);

    if (optionExists) {
        departureCitySelect.value = matchedCity;
        departureCitySelect.style.pointerEvents = 'none';
        departureCitySelect.classList.add('gps-locked');
        departureCitySelect.classList.remove('gps-error');
        // Dispatch both events — validation listens on 'change', reactive
        // frameworks may listen on 'input'. bubbles:true propagates to form.
        departureCitySelect.dispatchEvent(new Event('input',  { bubbles: true }));
        departureCitySelect.dispatchEvent(new Event('change', { bubbles: true }));
    } else {
        // Set the detected value anyway for user reference, but unlock for manual selection
        departureCitySelect.value = matchedCity;
        departureCitySelect.style.pointerEvents = 'auto';
        departureCitySelect.classList.remove('gps-locked');
        departureCitySelect.classList.add('gps-error');
        departureCitySelect.dispatchEvent(new Event('input',  { bubbles: true }));
        departureCitySelect.dispatchEvent(new Event('change', { bubbles: true }));
        showToast('warning', 'شوێنەکەت لە لیستەکەدا نییە. تکایە ناوی شارەکەت بە دەستی خۆت دیاری بکە.', 5000);
        console.warn('[autoSelectDepartureCity] City not in select options:', matchedCity, '(raw:', detectedCity + ') — value set but select unlocked for manual selection');
    }
}

// IMMEDIATE WINDOW ONLOAD TRIGGER: Request GPS location on page load
async function initializeGeolocation() {
    if (!navigator.geolocation) {
        console.warn('Geolocation not supported');
        window.isLocationGranted = false;
        return;
    }

    navigator.geolocation.getCurrentPosition(
        async function(position) {
            // Permission granted - store GPS location in global window variables
            window.userLat = position.coords.latitude;
            window.userLng = position.coords.longitude;
            window.isLocationGranted = true;
            geolocationPermissionGranted = true;

            // Perform reverse geocoding to detect city
            try {
                window.detectedCity = await reverseGeocodeCity(position.coords.latitude, position.coords.longitude);

                // Auto-select departure city dropdown based on detected GPS city
                if (window.detectedCity) {
                    autoSelectDepartureCity(window.detectedCity);
                }
            } catch (error) {
                console.error('Reverse geocoding error:', error);
                window.detectedCity = null;
            }

            // Auto-save to hidden form fields if they exist
            const latInput = document.getElementById('trip-latitude');
            const lngInput = document.getElementById('trip-longitude');
            if (latInput && lngInput) {
                latInput.value = position.coords.latitude.toFixed(8);
                lngInput.value = position.coords.longitude.toFixed(8);
            }
        },
        function(error) {
            // Permission denied or error
            window.isLocationGranted = false;
            window.userLat = null;
            window.userLng = null;
            window.detectedCity = null;
            geolocationPermissionGranted = false;
            userGPSLocation = null;
            detectedGPSCity = null;

            console.error('Geolocation error:', error);
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        }
    );
}

async function parseJsonResponse(response) {
    const text = await response.text();
    if (!text) {
        return { success: false, message: 'وەڵامێکی بەتاڵ لە سێرڤەرەوە هات' };
    }
    try {
        return JSON.parse(text);
    } catch (e) {
        console.error('Invalid JSON:', text);
        return { success: false, message: 'وەڵامی سێرڤەر نادروستە' };
    }
}

/* ==========================================================================
   BLOCK 2: Page Load Initialization States
   ========================================================================== */
document.addEventListener('DOMContentLoaded', function () {
    // Initialize dark mode
    initDarkMode();
    
    // Run geolocation logic first
    initializeGeolocation();
    
    // Initialize dashboard map
    initializeDashboardMap();

    // Then call UI initialization routines
    initializeNavigation();
    initializePostTripForm();
    initializeSearchTripsForm();
    initializeAuth();
    initializeBookingModal();
    loadRecentTrips();
    initializeDashboardFilters();
    initializeBackToTop();
    initializeRatingSystem();
    initializeSavedRoutes();
    initializeProximitySearch();
    registerServiceWorker();

    // Check for welcome banner (new registration)
    if (sessionStorage.getItem('just_registered') === '1') {
        sessionStorage.removeItem('just_registered');
        showWelcomeBanner();
    }

    const tripDateInput = document.getElementById('trip-date');
    if (tripDateInput) {
        const now = new Date();
        tripDateInput.min = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }

    // Initialize service type toggle on page load
    const serviceTypeSelect = document.getElementById('trip-service-type');
    if (serviceTypeSelect) {
        window.toggleSeatsInputBasedOnService(serviceTypeSelect.value);
    }
});

/* ==========================================================================
   BLOCK 4: API Fetch Request Handlers
   ========================================================================== */
async function apiFetch(action, options = {}) {
    const url = `${API_URL}?action=${encodeURIComponent(action)}`;
    const config = {
        ...fetchOpts,
        ...options,
        headers: {
            'Content-Type': 'application/json',
            ...(options.headers || {}),
        },
    };
    const response = await fetch(url, config);
    const result = await parseJsonResponse(response);
    return { response, result };
}

/* ==========================================================================
   BLOCK 3: Event Listeners & UI Bindings
   ========================================================================== */
// ============================================================================
// RATING SYSTEM: Driver Review & Rating Modal
// ============================================================================
// Rating system state variables
let selectedRatingValue = 5;
let activeTripIdForRating = null;

// Initialize rating modal event handlers
function initializeRatingSystem() {
    const ratingModal = document.getElementById('rating-modal');
    const stars = document.querySelectorAll('.star-rating-item');
    const submitBtn = document.getElementById('submit-review-btn');
    const closeBtn = document.getElementById('rating-modal-close');

    if (!ratingModal) return;

    // Star click handler — sets selection and updates visuals
    stars.forEach((star) => {
        star.addEventListener('click', function() {
            const value = parseInt(this.getAttribute('data-value'));
            selectedRatingValue = value;
            updateStarVisuals(value);
        });

        // Hover preview: illuminate up to hovered star
        star.addEventListener('mouseenter', function() {
            const hoverVal = parseInt(this.getAttribute('data-value'));
            stars.forEach((s) => {
                const sv = parseInt(s.getAttribute('data-value'));
                s.style.color = sv <= hoverVal ? '#f59e0b' : '';
                s.style.textShadow = sv <= hoverVal ? '0 0 10px rgba(245,158,11,0.4)' : '';
            });
        });

        // Reset hover preview on mouse leave — restore committed selection
        star.addEventListener('mouseleave', function() {
            stars.forEach((s) => {
                s.style.color = '';
                s.style.textShadow = '';
            });
        });
    });

    // Close button handler
    if (closeBtn) {
        closeBtn.addEventListener('click', closeRatingModal);
    }

    // Backdrop click to close
    ratingModal.addEventListener('click', (e) => {
        if (e.target === ratingModal) closeRatingModal();
    });

    // Submit review handler
    if (submitBtn) {
        submitBtn.addEventListener('click', submitReview);
    }
}

// Update star visual state
function updateStarVisuals(selectedValue) {
    const stars = document.querySelectorAll('.star-rating-item');
    const labels = ['', 'زۆر خراپ', 'خراپ', 'مامناوەند', 'باش', 'زۆر باش'];
    stars.forEach((star) => {
        const starValue = parseInt(star.getAttribute('data-value'));
        if (starValue <= selectedValue) {
            star.classList.add('active');
        } else {
            star.classList.remove('active');
        }
    });
    const labelEl = document.getElementById('star-rating-label');
    if (labelEl) {
        labelEl.textContent = `⭐ نمرەی هەڵبژێردراو: ${selectedValue} / 5 — ${labels[selectedValue] || ''}`;
    }
}

// Open rating modal
function openRatingModal(tripId, driverName = 'شۆفێر') {
    if (!currentUser) {
        showAuthModal('login');
        return;
    }
    activeTripIdForRating = tripId;
    selectedRatingValue = 5;
    updateStarVisuals(5);

    const ratingModal = document.getElementById('rating-modal');
    const commentTextarea = document.getElementById('rating-comment');
    const driverNameEl = document.getElementById('rating-driver-name');

    if (driverNameEl) {
        driverNameEl.textContent = driverName;
    }

    if (ratingModal) {
        ratingModal.classList.add('active');
        if (commentTextarea) commentTextarea.value = '';
    }
}

// Close rating modal
function closeRatingModal() {
    const ratingModal = document.getElementById('rating-modal');
    if (ratingModal) {
        ratingModal.classList.remove('active');
        activeTripIdForRating = null;
    }
}

// Submit review to backend
async function submitReview() {
    if (!activeTripIdForRating) {
        showToast('error', 'هەڵە: گەشتی نەناسراوە');
        return;
    }

    const commentTextarea = document.getElementById('rating-comment');
    const comment = commentTextarea ? commentTextarea.value.trim() : '';

    const submitBtn = document.getElementById('submit-review-btn');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'چاوەڕوان بە...'; }

    try {
        const response = await fetch(`${API_URL}?action=submit_review`, {
            ...fetchOpts,
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                trip_id: activeTripIdForRating,
                rating: selectedRatingValue,
                comment: comment
            })
        });

        const result = await parseJsonResponse(response);

        if (result.success) {
            showToast('success', '✅ پێداچوونەوەکە تۆمار کرا');
            closeRatingModal();
        } else {
            showToast('error', result.message || 'هەڵە لە تۆمارکردنی پێداچوونەوە');
        }
    } catch (error) {
        console.error('Review submission error:', error);
        showToast('error', 'هەڵە لە پەیوەندی');
    } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = '⭐ تۆمارکردنی ڕەیتینگ'; }
    }
}

// ============================================================================
// DYNAMIC VIEW TOGGLER: Single Page Application Navigation
// ============================================================================
// This function handles switching between different views (dashboard, post-trip, etc.)
// It updates the active tab state and shows/hides corresponding sections.
// ============================================================================
function initializeNavigation() {
    const navButtons = document.querySelectorAll('.nav-btn[data-tab]');
    const sections = document.querySelectorAll('.section');

    navButtons.forEach((button) => {
        button.addEventListener('click', function () {
            const targetTab = this.getAttribute('data-tab');

            navButtons.forEach((btn) => btn.classList.remove('active'));
            this.classList.add('active');

            // Scroll isolation: reset to top on view change
            window.scrollTo({ top: 0, behavior: 'smooth' });

            sections.forEach((section) => section.classList.remove('active'));

            const targetSection = document.getElementById(`${targetTab}-section`);
            if (targetSection) {
                targetSection.classList.add('active');
            }

            if (targetTab === 'dashboard') {
                loadRecentTrips();
                // Force dashboard map to recalculate size when section becomes visible
                setTimeout(() => {
                    const dashboardMap = document.getElementById('dashboard-map');
                    if (dashboardMap && window.dashboardMapInstance) {
                        window.dashboardMapInstance.invalidateSize();
                    }
                }, 200);
            } else if (targetTab === 'my-trips') {
                loadMyTrips();
            } else if (targetTab === 'my-bookings') {
                loadMyBookings();
            } else if (targetTab === 'post-trip') {
                // Initialize trip form map if not already initialized
                if (typeof window.tripFormMap === 'undefined' || !window.tripFormMap) {
                    setTimeout(() => {
                        initializeTripFormMap();
                    }, 100);
                } else {
                    // Force Leaflet map to recalculate size when post-trip section becomes visible
                    setTimeout(() => {
                        window.tripFormMap.invalidateSize();
                    }, 200);
                }
            }
        });
    });
}

// ============================================================================
// LIVE CHECKBOX MAPPING: Dynamic Intermediate Cities Selection
// ============================================================================
// This function dynamically renders intermediate city checkboxes based on
// the selected departure and destination cities using the intermediateRoutes matrix.
// ============================================================================
function initializePostTripForm() {
    const form = document.getElementById('trip-form');
    if (!form) return;

    // Bind change listeners for intermediate cities
    const departureSelect = form.querySelector('#trip-departure');
    const destinationSelect = form.querySelector('#trip-destination');
    const intermediateContainer = document.getElementById('intermediate-cities-container');
    const intermediateGrid = document.getElementById('intermediate-checkboxes-grid');

    function renderIntermediateCities() {
        const departure = departureSelect.value;
        const destination = destinationSelect.value;

        if (!departure || !destination) {
            intermediateContainer.style.display = 'none';
            intermediateGrid.innerHTML = '';
            return;
        }

        const routeKey = `${departure}-${destination}`;
        const cities = intermediateRoutes[routeKey];

        if (!cities || cities.length === 0) {
            intermediateContainer.style.display = 'none';
            intermediateGrid.innerHTML = '';
            return;
        }

        intermediateContainer.style.display = 'block';
        intermediateGrid.innerHTML = '';

        cities.forEach(city => {
            const label = document.createElement('label');
            label.className = 'checkbox-label';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'via-city-checkbox';
            checkbox.value = city;

            const span = document.createElement('span');
            span.textContent = city;

            label.appendChild(checkbox);
            label.appendChild(span);
            intermediateGrid.appendChild(label);
        });
    }

    departureSelect.addEventListener('change', renderIntermediateCities);
    destinationSelect.addEventListener('change', renderIntermediateCities);

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        if (!currentUser) {
            pendingTripSubmit = true;
            showAuthModal('register');
            return;
        }

        if (!validatePostTripForm(form)) {
            return;
        }

        // STRICT SUBMIT INTERCEPTION: Validate geolocation before submission
        const departureCity = form.querySelector('#trip-departure').value;

        // Check if geolocation permission was granted at startup
        if (!window.isLocationGranted) {
            showAlert('error', '⚠️ بۆ بەکارهێنانی ئەم سیستەمە، دەبێت لۆکەیشنی مۆبایلەکەت چالاک بکەیت و لاپەڕەکە نوێ (Refresh) بکەیتەوە.');
            return;
        }

        if (!geolocationPermissionGranted || !userGPSLocation) {
            // Check for fallback GPS coordinates from DOMContentLoaded
            if (window.userLat && window.userLng && !isNaN(window.userLat) && !isNaN(window.userLng)) {
                // Use fallback coordinates
                userGPSLocation = { lat: window.userLat, lng: window.userLng };
            } else {
                // Hard stop only if both userGPSLocation and fallbacks are unavailable
                showAlert('error', '⚠️ بۆ تۆمارکردنی گەشت، دەبێت ڕێگا بە لۆکەیشنی مۆبایلەکەت بدەیت. تکایە نەخشەکە بکەرەوە بۆ دەستپێکردنی GPS.');
                return;
            }
        }

        const price = parseFloat(form.querySelector('#price-iqd').value);
        if (Number.isNaN(price) || price < 1000) {
            showAlert('error', 'نرخ دەبێت بەلایەکی کەم 1000 دینار بێت');
            return;
        }
        if (price > 30000) {
            showAlert('error', 'بۆڕە، ناتوانیت نرخی کورسی لە 30,000 دینار زیاتر دابنێیت!');
            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        const buttonText = submitButton ? submitButton.querySelector('.btn-text') : null;
        const buttonLoading = submitButton ? submitButton.querySelector('.btn-loading') : null;
        if (!submitButton || !buttonText || !buttonLoading) {
            showAlert('error', 'هەڵە لە فۆرمەکەدا');
            return;
        }

        const tripDate = form.querySelector('#trip-date').value;
        const tripTime = form.querySelector('#trip-time').value;
        const scheduledAt = new Date(`${tripDate}T${tripTime}`);
        if (Number.isNaN(scheduledAt.getTime()) || scheduledAt < new Date()) {
            showAlert('error', 'کاتی گەشت دەبێت لە ئێستا دواتر بێت');
            return;
        }

        const serviceType = form.querySelector('#trip-service-type').value;
        const seatsAvailable = serviceType === 'delivery' ? 0 : parseInt(form.querySelector('#trip-seats').value, 10);
        
        if (serviceType !== 'delivery' && (Number.isNaN(seatsAvailable) || seatsAvailable < 1 || seatsAvailable > 10)) {
            showAlert('error', 'شوێنەکان دەبێت لەنێوان 1 و 10 بێت');
            return;
        }

        const latEl = form.querySelector('#trip-latitude');
        const lngEl = form.querySelector('#trip-longitude');
        const neighborhoodEl = form.querySelector('#neighborhood_detail');
        const destDetailEl = form.querySelector('#destination_detail');
        const waypointsEl = form.querySelector('#waypoints');

        // Collect checked intermediate cities
        const checkedCities = Array.from(form.querySelectorAll('.via-city-checkbox:checked'))
            .map(cb => cb.value);

        const payload = {
            car_model: form.querySelector('#vehicle-model').value.trim(),
            car_color: form.querySelector('#vehicle-color').value.trim(),
            has_ac: form.querySelector('#has-ac').checked,
            allows_smoking: form.querySelector('#allows-smoking').checked,
            allows_pets: form.querySelector('#allows_pets').checked,
            music_allowed: form.querySelector('#music_allowed').checked,
            is_ladies_only: form.querySelector('#is_ladies_only').checked,
            departure_city: departureCity,
            destination_city: form.querySelector('#trip-destination').value,
            departure_detail: neighborhoodEl ? neighborhoodEl.value.trim() : '',
            destination_detail: destDetailEl ? destDetailEl.value.trim() : '',
            waypoints: waypointsEl ? waypointsEl.value.trim() : '',
            via_cities: checkedCities.join(','),
            latitude: userGPSLocation.lat.toFixed(8),
            longitude: userGPSLocation.lng.toFixed(8),
            date_time: `${tripDate} ${tripTime}:00`,
            price_iqd: price,
            seats_available: seatsAvailable,
            service_type: serviceType,
        };

        setLoadingState(submitButton, buttonText, buttonLoading, true);

        try {
            const { response, result } = await apiFetch('create_trip', {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (result.success) {
                showAlert('success', result.message || 'گەشتەکە بەسەرکەوتوویی تۆمارکرا');
                form.reset();
                resetTripFormMapPicker();
                prefillTripFormFromUser();
                loadRecentTrips();
                loadMyTrips();
                // Reset geolocation state
                userGPSLocation = null;
                detectedGPSCity = null;
                geolocationPermissionGranted = false;
            } else if (response.status === 401) {
                pendingTripSubmit = true;
                showAuthModal('login');
                showAlert('error', result.message || 'چوونەژوورەوە پێویستە');
            } else {
                showAlert('error', result.message || 'هەڵەیەک ڕوویدا لە تۆمارکردنی گەشتدا');
            }
        } catch (error) {
            console.error('Error:', error);
            showAlert('error', 'هەڵەیەک ڕوویدا لە پەیوەندیدا');
        } finally {
            setLoadingState(submitButton, buttonText, buttonLoading, false);
        }
    });
}

function validatePostTripForm(form) {
    const driverName = form.querySelector('#driver-name').value.trim();
    const driverPhone = form.querySelector('#driver-phone').value.trim();
    const vehicleModel = form.querySelector('#vehicle-model').value.trim();
    const vehicleColor = form.querySelector('#vehicle-color').value.trim();
    const departureCity = form.querySelector('#trip-departure').value;
    const destinationCity = form.querySelector('#trip-destination').value;
    const tripDate = form.querySelector('#trip-date').value;
    const tripTime = form.querySelector('#trip-time').value;
    const priceIqd = form.querySelector('#price-iqd').value;
    const serviceType = form.querySelector('#trip-service-type').value;
    const availableSeats = form.querySelector('#trip-seats').value;

    if (!driverName || driverName.length < 2) {
        showAlert('error', 'ناوی شۆفێر دەبێت بەلایەکی کەم 2 پیت بێت');
        return false;
    }

    if (!driverPhone || !validatePhone(driverPhone)) {
        showAlert('error', 'تکایە ژمارەی مۆبایلی ڕاست داخڵ بکە (077 / 075 / 078 و 11 ژمارە)');
        return false;
    }

    if (!vehicleModel || vehicleModel.length < 2) {
        showAlert('error', 'جۆری ئۆتۆمبێل دەبێت بەلایەکی کەم 2 پیت بێت');
        return false;
    }

    if (!vehicleColor || vehicleColor.length < 2) {
        showAlert('error', 'ڕەنگی ئۆتۆمبێل دەبێت بەلایەکی کەم 2 پیت بێت');
        return false;
    }

    // v1.2 DEBUG — log field values immediately before validation fires;
    // remove once form submission is confirmed stable.

    if (!departureCity || !destinationCity) {
        showAlert('error', 'شارەکانی دەستپێکردن و مەبەست پێویستە');
        return false;
    }

    if (departureCity === destinationCity) {
        showAlert('error', 'ناتوانیت هەمان شوێن بۆ بەڕێکەوتن و گەیشتن دەستنیشان بکەیت!');
        return false;
    }

    if (!tripDate || !tripTime) {
        showAlert('error', 'بەروار و کاتی گەشت پێویستە');
        return false;
    }

    const selectedDate = new Date(tripDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (selectedDate < today) {
        showAlert('error', 'بەرواری گەشت دەبێت لە ئێستا گەورەتر بێت');
        return false;
    }

    if (!priceIqd || parseFloat(priceIqd) < 1000) {
        showAlert('error', 'نرخ دەبێت بەلایەکی کەم 1000 دینار بێت');
        return false;
    }

    if (!serviceType) {
        showAlert('error', 'جۆری خزمەتگوزاری پێویستە');
        return false;
    }

    // Only validate seats for passenger and both service types
    if (serviceType !== 'delivery') {
        const seats = parseInt(availableSeats, 10);
        if (!seats || seats < 1 || seats > 10) {
            showAlert('error', 'شوێنەکان دەبێت لەنێوان 1 و 10 بێت');
            return false;
        }
    }

    return true;
}

function validatePhone(phone) {
    return /^(077|075|078)\d{8}$/.test(phone.replace(/\D/g, ''));
}

function initializeSearchTripsForm() {
    const form = document.getElementById('search-trips-form');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const submitButton = form.querySelector('button[type="submit"]');
        const buttonText = submitButton ? submitButton.querySelector('.btn-text') : null;
        const buttonLoading = submitButton ? submitButton.querySelector('.btn-loading') : null;
        const searchLoading = document.getElementById('search-loading');

        const departureCity = form.querySelector('#search-departure').value;
        const destinationCity = form.querySelector('#search-destination').value;
        const searchDateTime = form.querySelector('#search_date_time').value;

        if (departureCity && destinationCity && departureCity === destinationCity) {
            showAlert('error', 'ناتوانیت هەمان شوێن بۆ بەڕێکەوتن و گەیشتن دەستنیشان بکەیت!');
            return;
        }

        if (submitButton && buttonText && buttonLoading) {
            setLoadingState(submitButton, buttonText, buttonLoading, true);
        }
        if (searchLoading) searchLoading.style.display = 'block';

        try {
            const params = new URLSearchParams();
            if (departureCity) params.append('departure', departureCity);
            if (destinationCity) {
                params.append('destination', destinationCity);
                params.append('route_query', destinationCity);
            }
            if (searchDateTime) params.append('date_time', searchDateTime.replace('T', ' ') + ':00');

            let url = `${API_URL}?action=search_trips`;
            if (params.toString()) url += `&${params.toString()}`;

            const response = await fetch(url, { ...fetchOpts, method: 'GET' });
            const result = await parseJsonResponse(response);

            if (result.success) {
                const trips = Array.isArray(result.data) ? result.data : [];
                displaySearchResults(trips);
                if (!trips.length) {
                    showAlert('info', 'هیچ گەشتێک نەدۆزرایەوە بەم مەرجانەوە');
                } else {
                    showAlert('success', `${trips.length} گەشت دۆزرایەوە`);
                }
            } else {
                showAlert('error', result.message || 'هەڵەیەک ڕوویدا لە گەڕاندا');
                displayEmptyState();
            }
        } catch (error) {
            console.error('Error:', error);
            showAlert('error', 'هەڵەیەک ڕوویدا لە پەیوەندیدا');
            displayEmptyState();
        } finally {
            if (submitButton && buttonText && buttonLoading) {
                setLoadingState(submitButton, buttonText, buttonLoading, false);
            }
            if (searchLoading) searchLoading.style.display = 'none';
        }
    });

    const resetFiltersBtn = document.getElementById('reset-filters-btn');
    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', async function () {
            form.querySelector('#search-departure').value = '';
            form.querySelector('#search-destination').value = '';
            form.querySelector('#search_date_time').value = '';
            await loadAllTripsForSearch();
            showAlert('success', 'فلتەرەکان پاککرانەوە');
        });
    }

    const swapLocationsBtn = document.getElementById('swap-locations-btn');
    if (swapLocationsBtn) {
        swapLocationsBtn.addEventListener('click', function () {
            const departureSelect = form.querySelector('#search-departure');
            const destinationSelect = form.querySelector('#search-destination');
            const temp = departureSelect.value;
            departureSelect.value = destinationSelect.value;
            destinationSelect.value = temp;
            form.dispatchEvent(new Event('submit'));
        });
    }
}

async function loadAllTripsForSearch() {
    try {
        const response = await fetch(`${API_URL}?action=get_trips`, { ...fetchOpts, method: 'GET' });
        const result = await parseJsonResponse(response);
        if (result.success) {
            displaySearchResults(Array.isArray(result.data) ? result.data : []);
        }
    } catch (error) {
        console.error('Reset error:', error);
    }
}

async function loadRecentTrips() {
    const container = document.getElementById('trips-feed-container');
    if (!container) return;

    container.innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`${API_URL}?action=get_trips`, { ...fetchOpts, method: 'GET' });
        const result = await parseJsonResponse(response);

        if (result.success) {
            const trips = Array.isArray(result.data) ? result.data : [];
            window.allTrips = trips; // Store all trips for filtering
            applyTripFilters(trips, container);
            updateActiveTripsCount(trips.length);
        } else {
            showTripsEmpty(container);
            updateActiveTripsCount(0);
        }
    } catch (error) {
        console.error('Error:', error);
        container.innerHTML = '<div class="empty-state"><div class="empty-icon">❌</div><h3>هەڵە</h3><p>نەتوانرا گەشتەکان بار بکرێن</p></div>';
        updateActiveTripsCount(0);
    }
}

function initializeDashboardFilters() {
    const viaCityFilter = document.getElementById('search-via-town');
    const destinationFilter = document.getElementById('filter-destination');
    const container = document.getElementById('trips-feed-container');
    
    if (!viaCityFilter || !destinationFilter || !container) return;

    const filterTrips = () => {
        if (window.allTrips) {
            applyTripFilters(window.allTrips, container);
        }
    };

    viaCityFilter.addEventListener('input', filterTrips);
    destinationFilter.addEventListener('change', filterTrips);
}

function applyTripFilters(trips, container) {
    const viaCityFilter = document.getElementById('search-via-town');
    const destinationFilter = document.getElementById('filter-destination');
    
    const viaCityValue = viaCityFilter ? viaCityFilter.value.trim().toLowerCase() : '';
    const destinationValue = destinationFilter ? destinationFilter.value : '';

    let filteredTrips = trips;

    if (viaCityValue) {
        filteredTrips = filteredTrips.filter(trip => {
            const viaCities = trip.via_cities || '';
            const waypoints = trip.waypoints || '';
            return viaCities.toLowerCase().includes(viaCityValue) || waypoints.toLowerCase().includes(viaCityValue);
        });
    }

    if (destinationValue) {
        filteredTrips = filteredTrips.filter(trip => {
            return trip.destination_city === destinationValue;
        });
    }

    displayTrips(filteredTrips, container);
}

function showTripsEmpty(container) {
    container.innerHTML = '<div class="empty-state"><div class="empty-icon">❌</div><h3>لە ئێستادا هیچ گەشتێک تۆمار نەکراوە</h3></div>';
}

function displayTrips(trips, container) {
    if (!trips || !trips.length) {
        showTripsEmpty(container);
        return;
    }
    container.innerHTML = '';
    trips.forEach((trip) => container.appendChild(createTripCard(trip)));
}

function displaySearchResults(trips) {
    const container = document.getElementById('search-results-container');
    if (!container) return;
    if (!trips || !trips.length) {
        displayEmptyState();
        return;
    }
    container.innerHTML = '';
    trips.forEach((trip) => container.appendChild(createTripCard(trip)));
}

function displayEmptyState() {
    const container = document.getElementById('search-results-container');
    if (!container) return;
    container.innerHTML = `
        <div class="empty-state">
            <div class="empty-state-icon">🔍</div>
            <h3>هیچ گەشتێک نەدۆزرایەوە</h3>
            <p>گەڕانەکەت گۆڕ بکە یان شوێنەکان دووبارە هەوڵ بدە</p>
        </div>`;
}


function getSeatCount(trip) {
    return parseInt(trip.seats_available ?? trip.available_seats ?? 0, 10);
}

function createTripCard(trip) {
    const card = document.createElement('div');
    card.className = 'trip-card';
    card.dataset.tripId = trip.id;

    // ── Service Type Badge ──
    const serviceType = trip.service_type || 'passenger';
    let serviceBadge = '';
    let serviceLabel = '';
    
    if (serviceType === 'passenger') {
        serviceBadge = '<span class="badge-passenger">🚖 سەرنشین</span>';
        serviceLabel = 'سەرنشین';
    } else if (serviceType === 'delivery') {
        serviceBadge = '<span class="badge-delivery">📦 گەیاندن</span>';
        serviceLabel = 'گەیاندن';
    } else if (serviceType === 'both') {
        serviceBadge = '<span class="badge-both">🔄 هاوبەش</span>';
        serviceLabel = 'هاوبەش';
    }

    // ── Seats badge logic ──
    const seats       = getSeatCount(trip);
    const seatsClass  = seats > 3 ? 'available' : seats > 0 ? 'limited' : 'full';
    let seatsLabel  = seats > 3 ? `${seats} شوێن بەردەستە` : seats > 0 ? `تەنها ${seats} شوێن` : 'شوێن نەماوە';
    
    // For delivery-only trips, swap seat indicator with delivery label
    if (serviceType === 'delivery') {
        seatsLabel = '📦 خزمەتگوزاری گەیاندنی کەلوپەل';
    }

    // ── Vehicle & driver ──
    const vehicleModel  = escapeHtml(trip.vehicle_model || trip.car_model || '—');
    const vehicleColor  = escapeHtml(trip.vehicle_color || trip.car_color || '—');
    const driverName    = escapeHtml(trip.driver_name   || 'کەسێک');
    const driverInitial = (trip.driver_name || 'ک').charAt(0);

    // ── Verified badge & Rating ──
    const verifiedBadge = trip.driver_verified
        ? `<span class="badge-verified">پشتڕاستکراو</span>`
        : '';
    
    const driverRating = trip.driver_avg_rating ? parseFloat(trip.driver_avg_rating).toFixed(1) : null;
    const ratingDisplay = driverRating 
        ? `<span class="driver-rating">⭐ ${driverRating}</span>` 
        : '';

    // ── Waypoints row ──
    const waypointsHtml = trip.waypoints
        ? `<div class="trip-info-item"><span class="info-icon">🛣️</span><span>${escapeHtml(trip.waypoints)}</span></div>`
        : '';

    // ── Via Cities badges ──
    let viaCitiesHtml = '';
    if (trip.via_cities) {
        const viaCities = trip.via_cities.split(',').filter(city => city.trim());
        if (viaCities.length > 0) {
            const badges = viaCities.map(city => 
                `<span class="via-city-badge">${escapeHtml(city.trim())}</span>`
            ).join('');
            viaCitiesHtml = `<div class="trip-info-item via-cities-row">
                <span class="info-icon">📍</span>
                <span class="via-cities-label">بەم شارۆچکانەدا تێپەڕ دەبێت:</span>
                <div class="via-cities-badges">${badges}</div>
            </div>`;
        }
    }

    // ── Amenities row ──
    const amenities = [];
    if (trip.has_ac)           amenities.push('❄️ AC');
    if (trip.is_ladies_only)   amenities.push('🚺 بانووان');
    if (trip.music_allowed)    amenities.push('🎵 مۆسیقا');
    if (trip.allows_pets)      amenities.push('🐾 ئاژەڵ');
    const amenitiesHtml = amenities.length
        ? `<div class="trip-info-item"><span class="info-icon">✨</span><span>${amenities.join(' · ')}</span></div>`
        : '';

    card.innerHTML = `
        <!-- Card Header: Driver Info + Price -->
        <div class="trip-card-header">
            <div class="trip-driver-info">
                <div class="trip-driver-avatar">${escapeHtml(driverInitial)}</div>
                <div class="trip-driver-meta">
                    <span class="trip-driver-name">${driverName}</span>
                    ${verifiedBadge}
                    ${ratingDisplay}
                </div>
            </div>
            <div class="trip-price">${escapeHtml(trip.price_formatted || '—')}</div>
        </div>

        <!-- Card Body: Route + Details -->
        <div class="trip-card-body">
            <!-- Visual Route Path -->
            <div class="trip-route-visual">
                <span class="trip-route-city">${escapeHtml(trip.departure_city)}</span>
                <span class="trip-route-arrow">➔</span>
                <span class="trip-route-city destination">${escapeHtml(trip.destination_city)}</span>
            </div>

            <div class="trip-info">
                <div class="trip-info-item">
                    <span class="info-icon">📅</span>
                    <span>${escapeHtml(trip.date_formatted || '')}</span>
                </div>
                <div class="trip-info-item">
                    <span class="info-icon">🚗</span>
                    <span>${vehicleModel} · ${vehicleColor}</span>
                </div>
                <div class="trip-info-item">
                    <span class="info-icon">🏷️</span>
                    ${serviceBadge}
                </div>
                ${waypointsHtml}
                ${viaCitiesHtml}
                ${amenitiesHtml}
                <!-- Contact reveal placeholder (filled after booking) -->
                <div class="trip-contact-reveal" data-trip-id="${trip.id}" hidden></div>
            </div>
        </div>

        <!-- Card Footer: Seats Badge + Book Button -->
        <div class="trip-card-footer">
            <span class="trip-seats ${seatsClass}">${seatsLabel}</span>
            <button type="button" class="btn-book"
                    ${seats < 1 && serviceType !== 'delivery' ? 'disabled' : ''}
                    data-trip-id="${trip.id}"
                    data-seats="${seats}">
                ${seats < 1 && serviceType !== 'delivery' ? 'شوێن نەماوە' : serviceType === 'delivery' ? '📞 پەیوەندی کردن' : '📌 داواکردنی کورسی'}
            </button>
            ${currentUser && trip.driver_id === currentUser.id ? `
                <button type="button" class="btn-cancel-trip" data-trip-id="${trip.id}">
                    ❌ هەڵوەشاندنەوەی گەشت
                </button>
            ` : ''}
            ${currentUser && trip.driver_id !== currentUser.id ? `
                <button type="button" class="btn-rate" data-trip-id="${trip.id}">
                    ⭐ پێدانی نمرە
                </button>
            ` : ''}
        </div>
    `;

    const bookBtn = card.querySelector('.btn-book');
    if (bookBtn && seats > 0) {
        bookBtn.addEventListener('click', () => handleBookSeatClick(trip));
    }

    const cancelBtn = card.querySelector('.btn-cancel-trip');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => handleCancelTripClick(trip.id));
    }

    const rateBtn = card.querySelector('.btn-rate');
    if (rateBtn) {
        rateBtn.addEventListener('click', () => openRatingModal(trip.id, trip.driver_name || 'شۆفێر'));
    }

    return card;
}


function handleBookSeatClick(trip) {
    if (!currentUser) {
        showAuthModal('login');
        return;
    }
    showBookingModal(trip);
}

function handleCancelTripClick(tripId) {
    if (!currentUser) {
        showAuthModal('login');
        return;
    }

    if (!confirm('دڵنیایت لە هەڵوەشاندنەوەی گەشتەکە؟')) {
        return;
    }

    apiFetch('cancel_trip', {
        method: 'POST',
        body: JSON.stringify({ trip_id: tripId })
    }).then(({ result }) => {
        if (result.success) {
            showToast('success', result.message);
            loadRecentTrips(); // Reload trips to update the UI
        } else {
            showToast('error', result.message);
        }
    }).catch(() => {
        showToast('error', 'هەڵە لە پەیوەندی کردن');
    });
}

function initializeAuth() {
    const authModal = document.getElementById('auth-modal');
    const authClose = document.getElementById('auth-close');
    const authTabs = document.querySelectorAll('.auth-tab');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    const authOpenBtn = document.getElementById('auth-open-btn');
    const logoutBtn = document.getElementById('logout-btn');

    if (!authModal || !loginForm || !registerForm) {
        console.error('Auth modal elements missing');
        return;
    }

    if (authClose) {
        authClose.addEventListener('click', () => authModal.classList.remove('active'));
    }

    authModal.addEventListener('click', (e) => {
        if (e.target === authModal) authModal.classList.remove('active');
    });

    authTabs.forEach((tab) => {
        tab.addEventListener('click', function () {
            switchAuthTab(this.getAttribute('data-tab'));
        });
    });

    if (authOpenBtn) {
        authOpenBtn.addEventListener('click', () => showAuthModal('login'));
    }

    if (logoutBtn) {
        logoutBtn.addEventListener('click', logout);
    }

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = Object.fromEntries(new FormData(loginForm));
        try {
            const { result } = await apiFetch('login', { method: 'POST', body: JSON.stringify(data) });
            if (result.success && result.data) {
                onAuthSuccess(result.data, result.message);
                switchAuthTab('login');
            } else {
                showAlert('error', result.message);
            }
        } catch (error) {
            showAlert('error', 'هەڵە لە پەیوەندی کردن');
        }
    });

    // Note: Registration form removed - registration now uses OTP flow via register.php
    // Legacy register form event listener and mock OTP code removed as part of security cleanup

    checkSession();
}

function switchAuthTab(tabName) {
    document.querySelectorAll('.auth-tab').forEach((t) => {
        t.classList.toggle('active', t.getAttribute('data-tab') === tabName);
    });
    document.querySelectorAll('.auth-form').forEach((f) => f.classList.remove('active'));
    const form = document.getElementById(`${tabName}-form`);
    if (form) form.classList.add('active');
    const title = document.getElementById('auth-title');
    if (title) title.textContent = tabName === 'login' ? 'چوونەژوورەوە' : 'تۆمارکردن';
}

async function checkSession() {
    try {
        const response = await fetch(`${API_URL}?action=check_session`, fetchOpts);
        const result = await parseJsonResponse(response);
        if (result.success && isValidUser(result.data)) {
            currentUser = result.data;
            updateUIForLoggedInUser();
            loadMyTrips();
        } else {
            updateUIForGuest();
        }
    } catch (error) {
        updateUIForGuest();
    }
}

function isValidUser(user) {
    return user && user.user_id && user.name && user.phone;
}

function onAuthSuccess(user, message) {
    if (!isValidUser(user)) {
        showAlert('error', 'زانیاری بەکارهێنەر ناتەواوە');
        return;
    }
    currentUser = user;
    const authModal = document.getElementById('auth-modal');
    if (authModal) authModal.classList.remove('active');
    showAlert('success', message);
    updateUIForLoggedInUser();
    loadMyTrips();

    if (pendingTripSubmit) {
        pendingTripSubmit = false;
        const tripForm = document.getElementById('trip-form');
        if (tripForm) {
            if (tripForm.requestSubmit) {
                tripForm.requestSubmit();
            } else {
                tripForm.dispatchEvent(new Event('submit'));
            }
        }
    }
}

function updateUIForLoggedInUser() {
    if (!currentUser) return;

    prefillTripFormFromUser();

    const welcome = document.getElementById('user-welcome');
    const authOpen = document.getElementById('auth-open-btn');
    const logoutBtn = document.getElementById('logout-btn');
    const myTripsNav = document.querySelector('.nav-btn-my-trips');
    const myBookingsNav = document.querySelector('.nav-btn-my-bookings');

    if (welcome) {
        welcome.textContent = `بەخێربێیت، ${currentUser.name}`;
        welcome.style.display = 'inline-flex';
    }
    if (authOpen) authOpen.style.display = 'none';
    if (logoutBtn) logoutBtn.style.display = 'inline-flex';
    if (myTripsNav) myTripsNav.style.display = 'inline-flex';
    if (myBookingsNav) myBookingsNav.style.display = 'inline-flex';
}

function updateUIForGuest() {
    currentUser = null;
    const welcome = document.getElementById('user-welcome');
    const authOpen = document.getElementById('auth-open-btn');
    const logoutBtn = document.getElementById('logout-btn');
    const myTripsNav = document.querySelector('.nav-btn-my-trips');
    const myBookingsNav = document.querySelector('.nav-btn-my-bookings');

    if (welcome) welcome.style.display = 'none';
    if (authOpen) authOpen.style.display = 'inline-flex';
    if (logoutBtn) logoutBtn.style.display = 'none';
    if (myTripsNav) myTripsNav.style.display = 'none';
    if (myBookingsNav) myBookingsNav.style.display = 'none';

    const nameInput = document.getElementById('driver-name');
    const phoneInput = document.getElementById('driver-phone');
    if (nameInput) {
        nameInput.readOnly = false;
        nameInput.value = '';
    }
    if (phoneInput) {
        phoneInput.readOnly = false;
        phoneInput.value = '';
    }
}

function prefillTripFormFromUser() {
    if (!currentUser) return;
    const nameInput = document.getElementById('driver-name');
    const phoneInput = document.getElementById('driver-phone');
    if (nameInput) {
        nameInput.value = currentUser.name;
        nameInput.readOnly = true;
    }
    if (phoneInput) {
        phoneInput.value = currentUser.phone;
        phoneInput.readOnly = true;
    }
}

async function logout() {
    try {
        const { result } = await apiFetch('logout', { method: 'POST', body: '{}' });
        if (result.success) {
            showAlert('success', result.message);
            location.reload();
        }
    } catch (error) {
        showAlert('error', 'هەڵە لە پەیوەندی کردن');
    }
}

function showAuthModal(tab = 'login') {
    const authModal = document.getElementById('auth-modal');
    if (authModal) {
        switchAuthTab(tab);
        authModal.classList.add('active');
    }
}

async function loadMyTrips() {
    if (!currentUser) return;

    const container = document.getElementById('my-trips-container');
    if (!container) return;

    container.innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`${API_URL}?action=get_my_trips`, fetchOpts);
        const result = await parseJsonResponse(response);

        if (!result.success) {
            if (response.status === 401) updateUIForGuest();
            return;
        }

        const trips = Array.isArray(result.data) ? result.data : [];
        if (!trips.length) {
            container.innerHTML = '<div class="empty-state"><div class="empty-icon">🚗</div><h3>هیچ گەشتێکت نیە</h3><p>یەکەم گەشتەکەت تۆمار بکە</p></div>';
            return;
        }

        container.innerHTML = '';
        trips.forEach((trip) => container.appendChild(createMyTripCard(trip)));
    } catch (error) {
        console.error('Error loading my trips:', error);
    }
}

async function loadMyBookings() {
    if (!currentUser) return;

    const container = document.getElementById('my-bookings-container');
    if (!container) return;

    container.innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';

    try {
        const response = await fetch(`${API_URL}?action=get_my_bookings`, fetchOpts);
        const result = await parseJsonResponse(response);

        if (!result.success) {
            if (response.status === 401) updateUIForGuest();
            return;
        }

        const bookings = Array.isArray(result.data) ? result.data : [];
        if (!bookings.length) {
            container.innerHTML = '<div class="empty-state"><div class="empty-icon">🧳</div><h3>هیچ گەشتێک داگیر نەکردووە</h3><p>گەشتی ئاسایی بۆ گەشتی خۆت بدۆزەرەوە</p></div>';
            return;
        }

        container.innerHTML = '';
        bookings.forEach((booking) => container.appendChild(createBookingCard(booking)));
    } catch (error) {
        console.error('Error loading my bookings:', error);
    }
}

function createMyTripCard(trip) {
    const card = document.createElement('div');
    card.className = 'trip-card my-trip-card';
    const seats = getSeatCount(trip);

    card.innerHTML = `
        <div class="trip-card-header">
            <div class="trip-route">${escapeHtml(trip.departure_city)} ← ${escapeHtml(trip.destination_city)}</div>
            <div class="trip-price">${escapeHtml(trip.price_formatted)}</div>
        </div>
        <div class="trip-card-body">
            <div class="trip-info">
                <div class="trip-info-item"><span>📅</span><span>${escapeHtml(trip.date_formatted)}</span></div>
                <div class="trip-info-item"><span>🚗</span><span>${escapeHtml(trip.car_model)} - ${escapeHtml(trip.car_color)}</span></div>
                <div class="trip-info-item"><span>💺</span><span>${seats} شوێن</span></div>
                <div class="trip-info-item"><span>📊</span><span>${escapeHtml(trip.status)}</span></div>
            </div>
        </div>
        <div class="trip-card-footer my-trip-footer">
            <button type="button" class="btn btn-danger delete-trip-btn" data-trip-id="${trip.id}">❌ سڕینەوەی گەشت</button>
        </div>
    `;

    const deleteBtn = card.querySelector('.delete-trip-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', () => {
            if (confirm('ئایا دڵنیای کە دەتەوێت ئەم گەشتە بسڕیتەوە؟')) {
                deleteTrip(trip.id);
            }
        });
    }

    return card;
}

function createBookingCard(booking) {
    const card = document.createElement('div');
    card.className = 'trip-card my-booking-card';

    card.innerHTML = `
        <div class="trip-card-header">
            <div class="trip-route">${escapeHtml(booking.departure_city)} ← ${escapeHtml(booking.destination_city)}</div>
            <div class="trip-price">${escapeHtml(booking.price_formatted)}</div>
        </div>
        <div class="trip-card-body">
            <div class="trip-info">
                <div class="trip-info-item"><span>📅</span><span>${escapeHtml(booking.date_formatted)}</span></div>
                <div class="trip-info-item"><span>👤</span><span>${escapeHtml(booking.driver_name)}</span></div>
                <div class="trip-info-item"><span>📞</span><span>${escapeHtml(booking.driver_phone)}</span></div>
                <div class="trip-info-item"><span>💺</span><span>${booking.seats_booked} کورسی داگیرکراوە</span></div>
                <div class="trip-info-item"><span>📊</span><span>${escapeHtml(booking.status)}</span></div>
            </div>
        </div>
        <div class="trip-card-footer my-booking-footer">
            <a href="tel:${escapeHtml(booking.driver_phone)}" class="btn btn-primary">📞 پەیوەندی بکەن</a>
        </div>
    `;

    return card;
}

async function deleteTrip(tripId) {
    try {
        const { response, result } = await apiFetch('delete_trip', {
            method: 'POST',
            body: JSON.stringify({ trip_id: tripId }),
        });
        if (result.success) {
            showAlert('success', result.message);
            loadMyTrips();
            loadRecentTrips();
        } else {
            showAlert('error', result.message);
            if (response.status === 401) showAuthModal('login');
        }
    } catch (error) {
        showAlert('error', 'هەڵە لە پەیوەندی کردن');
    }
}

function initializeBookingModal() {
    const bookingModal = document.getElementById('booking-modal');
    const bookingClose = document.getElementById('booking-close');
    const bookingForm = document.getElementById('booking-form');

    if (!bookingModal || !bookingClose || !bookingForm) {
        console.error('Booking modal elements missing');
        return;
    }

    bookingClose.addEventListener('click', resetBookingModal);
    bookingModal.addEventListener('click', (e) => {
        if (e.target === bookingModal) resetBookingModal();
    });

    bookingForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!currentUser) {
            resetBookingModal();
            showAuthModal('login');
            return;
        }

        const tripIdInput = document.getElementById('booking-trip-id');
        const seatsInput = document.getElementById('seats-requested');
        const errorEl = document.getElementById('booking-error');
        const maxSeats = activeBookingTrip ? getSeatCount(activeBookingTrip) : 10;

        if (!tripIdInput || !seatsInput || !errorEl) return;

        const tripId = parseInt(tripIdInput.value, 10);
        const seatsRequested = parseInt(seatsInput.value, 10);

        errorEl.hidden = true;

        if (!tripId || Number.isNaN(tripId)) {
            errorEl.textContent = 'گەشتەکە نادروستە';
            errorEl.hidden = false;
            return;
        }

        if (!seatsRequested || seatsRequested < 1 || Number.isNaN(seatsRequested)) {
            errorEl.textContent = 'تکایە ژمارەی کورسییەکان دیاری بکە';
            errorEl.hidden = false;
            return;
        }

        if (seatsRequested > maxSeats) {
            errorEl.textContent = `تەنها ${maxSeats} کورسی بەردەستە`;
            errorEl.hidden = false;
            return;
        }

        try {
            const { response, result } = await apiFetch('book_seat', {
                method: 'POST',
                body: JSON.stringify({ trip_id: tripId, seats_requested: seatsRequested }),
            });

            if (result.success && result.data) {
                revealDriverContact(result.data);
                showAlert('success', result.message);
                updateTripCardAfterBooking(tripId, result.data);
                if (activeBookingTrip) {
                    activeBookingTrip.seats_available = result.data.seats_remaining;
                    activeBookingTrip.available_seats = result.data.seats_remaining;
                }
                loadRecentTrips();
            } else {
                if (response.status === 401) {
                    resetBookingModal();
                    showAuthModal('login');
                } else {
                    errorEl.textContent = result.message || 'هەڵە لە داگیرکردنی کورسیدا';
                    errorEl.hidden = false;
                }
            }
        } catch (error) {
            showAlert('error', 'هەڵە لە پەیوەندی کردن');
        }
    });
}

function showBookingModal(trip) {
    if (!currentUser) {
        showAuthModal('login');
        return;
    }

    const seats = getSeatCount(trip);
    if (seats < 1) {
        showAlert('error', 'ئەم گەشتە کورسی بەردەستی نیە');
        return;
    }

    const bookingModal = document.getElementById('booking-modal');
    const tripIdInput = document.getElementById('booking-trip-id');
    const seatsInput = document.getElementById('seats-requested');
    const hint = document.getElementById('booking-seats-hint');
    const bookingError = document.getElementById('booking-error');
    const bookingContact = document.getElementById('booking-contact');
    const bookingForm = document.getElementById('booking-form');

    if (!bookingModal || !tripIdInput || !seatsInput || !hint) return;

    activeBookingTrip = trip;

    tripIdInput.value = trip.id;
    seatsInput.max = Math.max(seats, 1);
    seatsInput.min = 1;
    seatsInput.value = 1;
    hint.textContent = `کورسی بەردەست: ${seats}`;

    if (bookingError) bookingError.hidden = true;
    if (bookingContact) bookingContact.hidden = true;
    if (bookingForm) bookingForm.hidden = false;

    bookingModal.classList.add('active');
}

function resetBookingModal() {
    const bookingModal = document.getElementById('booking-modal');
    const bookingForm = document.getElementById('booking-form');
    const bookingContact = document.getElementById('booking-contact');
    const bookingError = document.getElementById('booking-error');

    if (bookingModal) bookingModal.classList.remove('active');
    if (bookingForm) {
        bookingForm.reset();
        bookingForm.hidden = false;
    }
    if (bookingContact) bookingContact.hidden = true;
    if (bookingError) {
        bookingError.textContent = '';
        bookingError.hidden = true;
    }
    activeBookingTrip = null;
}

function revealDriverContact(data) {
    const contact = document.getElementById('booking-contact');
    const phone = data && data.driver_phone ? String(data.driver_phone) : '';
    const name = (data && data.driver_name) ? String(data.driver_name) : '';
    const departure = (data && data.departure_city) ? String(data.departure_city) : '';
    const destination = (data && data.destination_city) ? String(data.destination_city) : '';

    if (!phone) {
        showAlert('error', 'ژمارەی شۆفێر بەردەست نیە');
        return;
    }

    const cleanPhone = phone.replace(/^0/, '');

    const nameEl = document.getElementById('booking-driver-name');
    const phoneEl = document.getElementById('booking-driver-phone');
    const callEl = document.getElementById('booking-call-link');
    const waEl = document.getElementById('booking-wa-link');
    const copyEl = document.getElementById('booking-copy-phone');
    const formEl = document.getElementById('booking-form');

    if (nameEl) nameEl.textContent = name;
    if (phoneEl) phoneEl.textContent = phone;
    if (callEl) callEl.href = `tel:${phone.replace(/[^\d+]/g, '')}`;
    if (waEl) {
        // Custom Kurdish message template
        const message = 'سڵاو کاک شۆفێر، من گەشتەکەی تۆم بینی لە ئەپی شەریک، ئایا هێشتا کورسی بەردەستت ماوە؟';
        waEl.href = `https://wa.me/964${cleanPhone}?text=${encodeURIComponent(message)}`;
    }
    if (copyEl) {
        copyEl.onclick = () => copyPhoneToClipboard(phone, copyEl);
    }
    if (formEl) formEl.hidden = true;
    if (contact) contact.hidden = false;
}

function copyPhoneToClipboard(phone, buttonEl) {
    const fallbackCopy = () => {
        const textarea = document.createElement('textarea');
        textarea.value = phone;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    };

    const onSuccess = () => {
        if (!buttonEl) return;
        const original = buttonEl.textContent;
        buttonEl.textContent = 'کۆپی کرا! ✓';
        setTimeout(() => {
            buttonEl.textContent = original;
        }, 2000);
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(phone).then(onSuccess).catch(() => {
            fallbackCopy();
            onSuccess();
        });
    } else {
        fallbackCopy();
        onSuccess();
    }
}

function updateTripCardAfterBooking(tripId, data) {
    const safeId = String(parseInt(tripId, 10));
    const card = document.querySelector(`.trip-card[data-trip-id="${safeId}"]`);
    if (!card) return;

    const remaining = data.seats_remaining ?? 0;
    const reveal = card.querySelector('.trip-contact-reveal');
    if (reveal && data.driver_phone) {
        const displayPhone = escapeHtml(data.driver_phone);
        const telPhone = String(data.driver_phone).replace(/[^\d+]/g, '');
        reveal.hidden = false;
        reveal.innerHTML = `
            <div class="driver-contact-panel">
                <strong>📞 ${displayPhone}</strong>
                <button type="button" class="btn-copy-phone card-copy-phone" data-phone="${displayPhone}">📋 کۆپی</button>
                <a href="tel:${telPhone}" class="call-btn">پەیوەندی</a>
            </div>
        `;
        const copyBtn = reveal.querySelector('.card-copy-phone');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => copyPhoneToClipboard(data.driver_phone, copyBtn));
        }
    }

    const seatsEl = card.querySelector('.trip-seats span:last-child');
    if (seatsEl) seatsEl.textContent = `${remaining} شوێن`;

    const bookBtn = card.querySelector('.btn-book');
    if (bookBtn && remaining < 1) {
        bookBtn.disabled = true;
        bookBtn.textContent = 'بەردەست نیە';
    } else if (bookBtn) {
        bookBtn.dataset.seats = remaining;
    }
}

function showAlert(type, message) {
    const container = document.getElementById('alert-container');
    if (!container) return;

    const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.innerHTML = `
        <div class="alert-icon">${icons[type] || ''}</div>
        <div class="alert-message">${escapeHtml(message)}</div>
        <button type="button" class="alert-close" aria-label="داخستن">×</button>
    `;
    alert.querySelector('.alert-close').addEventListener('click', () => alert.remove());
    container.appendChild(alert);

    setTimeout(() => {
        alert.style.opacity = '0';
        alert.style.transform = 'translateX(100%)';
        setTimeout(() => alert.remove(), 300);
    }, 5000);
}

function setLoadingState(button, textElement, loadingElement, isLoading) {
    if (!button || !textElement || !loadingElement) return;
    if (isLoading) {
        textElement.style.display = 'none';
        loadingElement.style.display = 'inline-flex';
        button.disabled = true;
    } else {
        textElement.style.display = 'inline';
        loadingElement.style.display = 'none';
        button.disabled = false;
    }
}

function updateActiveTripsCount(count) {
    const el = document.getElementById('active-trips-count');
    if (el) el.textContent = count;
}

function displayTripOnMap(map, trip) {
    if (!map || !trip) return;

    // Use trip coordinates if available, otherwise use city center coordinates
    const startCoords = trip.start_lat && trip.start_lng
        ? [trip.start_lat, trip.start_lng]
        : [36.1905, 43.9955]; // Default to Erbil center

    const endCoords = trip.dest_lat && trip.dest_lng
        ? [trip.dest_lat, trip.dest_lng]
        : null;

    // Determine marker icon based on service type
    const serviceType = trip.service_type || 'passenger';
    let markerIcon, markerEmoji, markerColor;
    
    if (serviceType === 'passenger') {
        markerEmoji = '🚖';
        markerColor = '#22c55e';
    } else if (serviceType === 'delivery') {
        markerEmoji = '📦';
        markerColor = '#f59e0b';
    } else if (serviceType === 'both') {
        markerEmoji = '🔄';
        markerColor = '#6366f1';
    } else {
        markerEmoji = '📍';
        markerColor = '#22c55e';
    }

    // Add marker for driver's starting location with service type icon
    const driverIcon = L.divIcon({
        className: 'custom-div-icon',
        html: `<div style="background-color: ${markerColor}; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">${markerEmoji}</div>`,
        iconSize: [30, 30],
        iconAnchor: [15, 15]
    });

    // Build service type label for popup
    let serviceTypeLabel = 'سەرنشین';
    if (serviceType === 'delivery') serviceTypeLabel = 'گەیاندن';
    else if (serviceType === 'both') serviceTypeLabel = 'هاوبەش';

    const marker = L.marker(startCoords, { icon: driverIcon })
        .addTo(map)
        .bindPopup(
            `<div style="text-align:right;direction:rtl;font-family:sans-serif;">
                <strong>شۆفێر:</strong> ${escapeHtml(trip.driver_name)}<br>
                <strong>ڕێگا:</strong> ${escapeHtml(trip.departure_city)} ← ${escapeHtml(trip.destination_city)}<br>
                <strong>جۆر:</strong> ${serviceTypeLabel}<br>
                <strong>نرخ:</strong> ${escapeHtml(trip.price_formatted)}<br>
                <strong>شوێن:</strong> ${serviceType === 'delivery' ? 'گەیاندن' : getSeatCount(trip)}<br>
                <strong>بەروار:</strong> ${escapeHtml(trip.date_formatted)}
            </div>`
        );

    // If both start and end coordinates are available, draw polyline
    if (endCoords) {
        const polyline = L.polyline([startCoords, endCoords], {
            color: '#3b82f6',
            weight: 4,
            opacity: 0.7
        }).addTo(map);

        // Fit bounds to show both cities
        const bounds = L.latLngBounds([startCoords, endCoords]);
        map.fitBounds(bounds, { padding: [50, 50] });
    } else {
        // If no end coords, just center on start
        map.setView(startCoords, 10);
    }

    return marker;
}

function initializeDashboardMap() {
    const mapContainer = document.getElementById('dashboard-map');
    if (!mapContainer || typeof L === 'undefined') return;

    try {
        const map = L.map('dashboard-map').setView([36.1905, 43.9955], 7);
        L.tileLayer(`https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=${MAPTILER_API_KEY}`, {
            attribution: '\u00a9 <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> \u00a9 <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
            maxZoom: 19,
            crossOrigin: true,
        }).addTo(map);

        // Store map instance globally for later access
        window.dashboardMapInstance = map;

        fetch(`${API_URL}?action=get_trips`, fetchOpts)
            .then((r) => parseJsonResponse(r))
            .then((data) => {
                if (data.success && Array.isArray(data.data)) {
                    data.data.forEach((trip) => {
                        displayTripOnMap(map, trip);
                    });
                }
            })
            .catch((err) => console.error('Map trips error:', err));
    } catch (error) {
        console.error('Dashboard map initialization error:', error);
    }
}

// ============================================================================
// LEAFLET MAP INITIALIZER: Trip Form Map with GPS Integration
// ============================================================================
// This function initializes the interactive map for trip creation form.
// It handles map clicks, reverse geocoding, and GPS location locking.
// ============================================================================
function initializeTripFormMap() {
    const mapContainer = document.getElementById('map');
    if (!mapContainer || typeof L === 'undefined') return;

    // Use pre-fetched coordinates from page load
    if (!window.isLocationGranted || !window.userLat || !window.userLng) {
        // If location not granted, BLOCK map rendering completely
        console.warn('Location not granted - blocking trip form map rendering');
        mapContainer.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#dc2626;font-weight:bold;text-align:center;padding:20px;">⚠️ بۆ تۆمارکردنی گەشت، دەبێت لۆکەیشنی مۆبایلەکەت چالاک بکەیت و لاپەڕەکە نوێ (Refresh) بکەیتەوە.</div>';
        window.tripFormMap = null;
        return;
    }

    // Initialize map with pre-fetched GPS location
    try {
        const map = L.map('map').setView([window.userLat, window.userLng], 14);
        L.tileLayer(`https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=${MAPTILER_API_KEY}`, {
            attribution: '\u00a9 <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> \u00a9 <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
            maxZoom: 19,
            crossOrigin: true,
        }).addTo(map);

        // Add marker for user's current location with custom car icon
        window.userMarker = L.marker([window.userLat, window.userLng], { icon: carIcon })
            .addTo(map)
            .bindPopup('📍 شوێنی ئێستای تۆ')
            .openPopup();

        map.on('click', async function(e) {
            const latInput = document.getElementById('trip-latitude');
            const lngInput = document.getElementById('trip-longitude');
            const departureCityInput = document.getElementById('trip-departure');
            
            if (latInput) latInput.value = e.latlng.lat.toFixed(8);
            if (lngInput) lngInput.value = e.latlng.lng.toFixed(8);

            if (departureCityInput) {
                try {
                    const detectedCity = await reverseGeocodeCity(e.latlng.lat, e.latlng.lng);
                    
                    if (detectedCity) {
                        // v1.2: normalise against mapCityToAppCity before
                        // setting — the raw Nominatim value may not match any
                        // <option value="…"> if it slipped past the geocoder.
                        const validCities = Object.values(mapCityToAppCity);
                        const normalised = validCities.includes(detectedCity)
                            ? detectedCity
                            : (mapCityToAppCity[detectedCity.toLowerCase().trim()] || detectedCity);

                        // After getting detectedCity, force-match to a valid <option> value:
                        const optionExists = Array.from(departureCityInput.options)
                            .some(opt => opt.value === normalised);

                        if (optionExists) {
                            departureCityInput.value = normalised;
                            departureCityInput.style.pointerEvents = 'none';
                            departureCityInput.classList.add('gps-locked');
                            departureCityInput.classList.remove('gps-error');

                            // Both events: 'input' for reactive listeners, 'change' for validation
                            departureCityInput.dispatchEvent(new Event('input',  { bubbles: true }));
                            departureCityInput.dispatchEvent(new Event('change', { bubbles: true }));
                        } else {
                            // Set the detected value anyway for user reference, but unlock for manual selection
                            departureCityInput.value = normalised;
                            departureCityInput.style.pointerEvents = 'auto';
                            departureCityInput.classList.remove('gps-locked');
                            departureCityInput.classList.add('gps-error');
                            departureCityInput.dispatchEvent(new Event('input',  { bubbles: true }));
                            departureCityInput.dispatchEvent(new Event('change', { bubbles: true }));
                            showToast('warning', 'شوێنەکەت لە لیستەکەدا نییە. تکایە ناوی شارەکەت بە دەستی خۆت دیاری بکە.', 5000);
                            console.warn('[mapClick] City not in select options:', normalised, '(raw was:', detectedCity + ') — value set but select unlocked for manual selection');
                        }
                    } else {
                        // Province name was filtered, show message
                        departureCityInput.value = '';
                        departureCityInput.style.pointerEvents = 'auto';
                        departureCityInput.classList.remove('gps-locked');
                        departureCityInput.classList.add('gps-error');
                        showToast('warning', 'شوێنەکەت بە وردی نەدۆزرایەوە! تکایە ناوی قەزا یان شارەکەت بە دەستی خۆت دیاری بکە.', 5000);
                        departureCityInput.focus();
                    }
                } catch (error) {
                    console.error('Reverse geocoding error:', error);
                }
            }
        });

        window.tripFormMap = map;

        // Initialize re-center button
        initializeRecenterButton(map);
    } catch (error) {
        console.error('Trip form map initialization error:', error);
    }
}

function initializeRecenterButton(map) {
    const recenterBtn = document.getElementById('recenter-map-btn');
    if (!recenterBtn) return;

    recenterBtn.addEventListener('click', async function() {
        if (!navigator.geolocation) {
            showToast('error', 'GPS پشتگیری ناکرێت لەسەر ئەم وێبگەرەوە');
            return;
        }

        showToast('info', 'دەستنیشانکردنی شوێن...');

        navigator.geolocation.getCurrentPosition(
            async function(position) {
                const newLat = position.coords.latitude;
                const newLng = position.coords.longitude;

                // Update global variables
                window.userLat = newLat;
                window.userLng = newLng;

                // Update hidden form fields
                const latInput = document.getElementById('trip-latitude');
                const lngInput = document.getElementById('trip-longitude');
                if (latInput && lngInput) {
                    latInput.value = newLat.toFixed(8);
                    lngInput.value = newLng.toFixed(8);
                }

                // Perform reverse geocoding to detect city
                try {
                    window.detectedCity = await reverseGeocodeCity(newLat, newLng);
                    
                    // Auto-select departure city dropdown based on detected GPS city
                    if (window.detectedCity) {
                        autoSelectDepartureCity(window.detectedCity);
                    }
                } catch (error) {
                    console.error('Reverse geocoding error:', error);
                }

                // Smoothly fly map to new location
                map.flyTo([newLat, newLng], 14, {
                    duration: 1.5,
                    easeLinearity: 0.25
                });

                // Update marker position
                map.eachLayer(function(layer) {
                    if (layer instanceof L.Marker) {
                        layer.setLatLng([newLat, newLng]);
                    }
                });

                showToast('success', '📍 لۆکەیشنەکەت نوێکرایەوە');
            },
            function(error) {
                let errorMessage = 'نەتوانرا شوێن دەستنیشان بکرێت';
                if (error.code === error.PERMISSION_DENIED) {
                    errorMessage = 'ڕێگەی GPS نەدرا';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    errorMessage = 'شوێنی GPS بەردەست نییە';
                } else if (error.code === error.TIMEOUT) {
                    errorMessage = 'کاتی GPS بەسەرچوو';
                }
                showToast('error', errorMessage);
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    });
}

function resetTripFormMapPicker() {
    if (window.tripFormMap) {
        window.tripFormMap.off('click');
        const latInput = document.getElementById('trip-latitude');
        const lngInput = document.getElementById('trip-longitude');
        if (latInput) latInput.value = '';
        if (lngInput) lngInput.value = '';
    }
}

// REVERSE GEOCODING: Get city name from GPS coordinates using MapTiler Geocoding API
async function reverseGeocodeCity(lat, lng) {
    try {
        const response = await fetch(
            `https://api.maptiler.com/geocoding/${lng},${lat}.json?key=${MAPTILER_API_KEY}&language=ku,en`
        );

        if (!response.ok) {
            throw new Error('Reverse geocoding failed');
        }

        const data = await response.json();

        // MapTiler returns a GeoJSON FeatureCollection.
        // The first feature is the most specific result.
        if (!data.features || data.features.length === 0) {
            console.error('No geocoding results from MapTiler');
            return null;
        }

        const feature = data.features[0];

        // Extract place name from context array.
        // MapTiler context entries have place_type like:
        //   'place' (city/town), 'locality', 'neighbourhood', 'region', 'country'
        // We prefer 'locality' > 'place' and reject 'region'/'country'.
        const context = feature.context || [];

        // Build a map of place_type -> text from context
        const ctxMap = {};
        context.forEach(c => {
            if (c.id) {
                const type = c.id.split('.')[0];
                if (!ctxMap[type]) ctxMap[type] = c.text || '';
            }
        });

        // Also check the feature itself
        const featureType = feature.place_type ? feature.place_type[0] : '';
        if (featureType && !ctxMap[featureType]) {
            ctxMap[featureType] = feature.text || '';
        }

        // PRIORITIZE SPECIFIC LOCATION NAMES, REJECT REGION/COUNTRY
        let rawCityName = ctxMap['locality'] || ctxMap['neighbourhood'] || ctxMap['place'] || feature.text || '';

        if (!rawCityName) {
            console.error('No specific location data found in MapTiler response');
            return null;
        }

        // FILTER OUT PROVINCE/GOVERNORATE NAMES
        const cleaned = rawCityName.toLowerCase().trim();
        if (cleaned.includes('parêzgeha') || cleaned.includes('governorate') || cleaned.includes('province') || cleaned.includes('پارێزگای')) {
            console.warn('[reverseGeocodeCity] Detected province name:', rawCityName, '— rejecting for manual selection');
            return null;
        }

        // STEP 1 — direct lowercase match (English response)
        if (mapCityToAppCity[cleaned]) {
            return mapCityToAppCity[cleaned];
        }

        // STEP 2 — direct raw match for Kurdish-script responses
        if (mapCityToAppCity[rawCityName.trim()]) {
            return mapCityToAppCity[rawCityName.trim()];
        }

        // STEP 3 — value is already a valid Kurdish select option (identity match)
        const validSelectValues = Object.values(mapCityToAppCity);
        if (validSelectValues.includes(rawCityName.trim())) {
            return rawCityName.trim();
        }

        // STEP 4 — substring match: handles "Kirkuk Governorate", "As Sulaymaniyah", etc.
        for (const [key, kurdishCity] of Object.entries(mapCityToAppCity)) {
            if (cleaned.includes(key) || key.includes(cleaned)) {
                return kurdishCity;
            }
        }

        // STEP 5 — no match; return raw value (caller will log a console warning)
        console.warn('[reverseGeocodeCity] No dictionary match for:', rawCityName, '— returning raw');
        return rawCityName;
    } catch (error) {
        console.error('Reverse geocoding error:', error);
        return null;
    }
}

// CITY MATCHING: Check if detected GPS city matches selected departure city
function validateCityMatch(detectedCity, selectedCity) {
    if (!detectedCity || !selectedCity) {
        return false;
    }

    // Normalize both city names for comparison
    const normalizeCity = (city) => {
        return city.toLowerCase()
            .trim()
            .replace(/[^\u0600-\u06FF\u0750-\u077Fa-zA-Z\s]/g, '');
    };

    const normalizedDetected = normalizeCity(detectedCity);
    const normalizedSelected = normalizeCity(selectedCity);

    // Comprehensive Kurdish city name mappings for all regions
    const cityMappings = {
        "هەولێر": ["erbil", "hawler", "أربيل", "erbil governorate", "hawler governorate"],
        "سلێمانی": ["sulaymaniyah", "sulamani", "السليمانية", "sulaymaniyah governorate"],
        "دهۆک": ["dohuk", "duhok", "دهوك", "dohuk governorate"],
        "کەرکووک": ["kirkuk", "کەرکووک", "کرکوک", "كركوك", "kirkuk governorate"],
        "هەڵەبجە": ["halabja", "حلبجة", "halabja governorate"],
        "سۆران": ["soran", "diana", "دیانا"],
        "رواندز": ["rawanduz", "rewanduz", "رواندز"],
        "شەقڵاوە": ["shaqlawa", "shaqlawah", "شقلاوة"],
        "خەلیفان": ["khalifan", "xelifan"],
        "حەریر": ["harir", "harer"],
        "چۆمان": ["choman", "balakayati", "باڵەکایەتی"],
        "حاجی ئۆمەران": ["haji omeran", "haji umran"],
        "قەسەرێ": ["qasre", "qasri"],
        "سمێلان": ["smilan", "smelan"],
        "گەڵاڵە": ["galala", "galalah"],
        "مێرگەسوور": ["mergasor", "mergasur"],
        "شێروان مەزن": ["sherwan mazan", "sherwan mezin"],
        "بارزان": ["barzan"],
        "سیدەکان": ["sidakan", "bradost", "برادۆست"],
        "وەرتێ": ["warte", "warti"],
        "پیرمام": ["pirmam", "masif", "مەسیف"],
        "کۆیە": ["koya", "koy sinjaq", "کۆیسنجەق"],
        "تەق تەق": ["taq taq", "taqtaq"],
        "خەبات": ["khabat", "xebat"],
        "کەڵەک": ["kalak", "rzgari", "ڕزگاری"],
        "مەخموور": ["makhmour", "makhmur", "مخمور"],
        "قووشتەپە": ["qushtapa", "qustapah"],
        "بەحرکە": ["baharka", "baherka"],
        "کەسنەزان": ["kasnazan"],
        "دارەتوو": ["daratoo", "daratu"],
        "بنەسڵاوە": ["bnaslawa", "bnaslawah"],
        "گوێڕ": ["guwer", "guwayr"],
        "شەمامک": ["shamamk", "shamamik"],
        "ڕانیە": ["ranya", "ranyah", "ڕانیە"],
        "چوارقوڕنە": ["chwarqurna", "chwarqurnah"],
        "حاجیاوا": ["hajiawa", "hajiawah"],
        "سەرکەپکان": ["sarkapkan"],
        "بێتواتە": ["betwata", "betwatah"],
        "قەڵادزێ": ["qaladiza", "qaladizah", "pshdar", "پشدەر"],
        "سەنگەسەر": ["sangasor", "sangeser"],
        "ژاراوە": ["zharawa", "zharawah"],
        "ئیسێوێ": ["iswei", "isewi"],
        "هێرۆ": ["hero", "herow"],
        "هەڵشۆ": ["halsho", "halshow"],
        "دووکان": ["dukan", "dokan"],
        "پیرەمەگروون": ["piramagrun", "peramagrun"],
        "بازیان": ["bazyan"],
        "تەکیە": ["takya", "takiah"],
        "سەید سادق": ["said sadiq", "sayed sadeq"],
        "پێنجوێن": ["penjwen", "penjwin"],
        "گەرمک": ["garmk", "garmik"],
        "شەهرەزوور": ["sharazoor", "sharazur"],
        "زەڕایەن": ["zarayen", "warmawa", "وارماوا"],
        "ماوەت": ["mawat"],
        "چوارتا": ["chwarta", "sharbazher", "شارباژێڕ"],
        "سیتەک": ["sitek", "sitak"],
        "قەرەداغ": ["qaradagh", "qaradax"],
        "عەربەت": ["arbat"],
        "بەکراژۆ": ["bakrajo", "bakrajow"],
        "کەلار": ["kalar", "garmian", "گەرمیان"],
        "رزگاری": ["rzgari", "hasira", "حەسیرە"],
        "باوەنوور": ["bawanur", "bawanwar"],
        "شێخ تەویل": ["sheikh tawil", "bamo", "بەمۆ"],
        "چەمچەماڵ": ["chamchamal", "chemchemal"],
        "شۆڕش": ["shorish", "shorish town"],
        "سەنگاو": ["sangaw"],
        "ئاغجەلەر": ["aghjalar", "axjalar"],
        "قادرکەرەم": ["qadir karam"],
        "دەربەندیخان": ["darbandikhan", "derbendixan"],
        "کفری": ["kifri"],
        "خانەقین": ["khanaqin", "khanaqyn"],
        "قەرەتەپە": ["qaratapa", "qaratapah"],
        "سەعدییە": ["saadiya", "saadiyah"],
        "جەلەولا": ["jalawla", "jalawlah"],
        "جەبارە": ["jabara", "jabarah"],
        "مەندەلی": ["mandali"],
        "داقووق": ["daquq"],
        "حەویجە": ["hawija", "hawijah"],
        "التون کۆپری": ["pirde", "altun kopri", "altun kupri", "پردێ"],
        "زاخۆ": ["zakho", "zaxo"],
        "باتێفا": ["batifa", "batifrah"],
        "ئاکرێ": ["akre", "aqrah"],
        "بجیل": ["bijil", "bijel"],
        "گردەسێن": ["girdasin", "girdasyn"],
        "ئامێدی": ["amedi", "amadiyah"],
        "دێرەلووک": ["deralok", "deraluk"],
        "شیلادزێ": ["sheladize", "sheladizah"],
        "بامەڕنێ": ["bamerne", "bamarni"],
        "سەرسەنگ": ["sarsang", "sarsink"],
        "کانی ماسێ": ["kani mase", "kani masi"],
        "شێخان": ["shekhan", "shikhan"],
        "کەلەکچی": ["kalakchi", "kalakchy"],
        "بەردەڕەش": ["bardarash"],
        "سێمێل": ["semel", "simel"],
        "زاوێتە": ["zawita", "zawitah"],
        "مانگێش": ["mangesh", "mankesh"],
        "تەوێڵە": ["tawella", "tawela", "hawraman", "هەورامان"],
        "بیارە": ["byara", "byarah"],
        "خورماڵ": ["khurmal"],
        "سیروان": ["sirwan"],
        "بەمۆ": ["bamo", "glejal", "گڵێجاڵ"]
    };

    // Check direct match
    if (normalizedDetected === normalizedSelected) {
        return true;
    }

    // Check using city mappings - check if detected city matches any variation of selected city
    for (const [kurdishName, variations] of Object.entries(cityMappings)) {
        const normalizedKurdish = normalizeCity(kurdishName);
        if (normalizedSelected === normalizedKurdish) {
            // Check if detected city matches any variation
            for (const variation of variations) {
                if (normalizedDetected === normalizeCity(variation)) {
                    return true;
                }
            }
        }
    }

    // Also check if detected city matches any Kurdish name directly
    for (const [kurdishName, variations] of Object.entries(cityMappings)) {
        const normalizedKurdish = normalizeCity(kurdishName);
        if (normalizedDetected === normalizedKurdish) {
            // Check if selected city matches any variation of this Kurdish name
            for (const variation of variations) {
                if (normalizedSelected === normalizeCity(variation)) {
                    return true;
                }
            }
        }
    }

    return false;
}

function initializeBackToTop() {
    const btn = document.getElementById('backToTopBtn');
    if (!btn) return;

    window.addEventListener('scroll', () => {
        btn.classList.toggle('visible', window.scrollY > 300);
    });

    btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
}

function registerServiceWorker() {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('./service-worker.js')
                .then(() => {})
                .catch(() => {});
        });
    }
}

// ============================================================================
// SAVED ROUTES: Initialize Saved Routes System
// ============================================================================
function initializeSavedRoutes() {
    const savedRoutesSection = document.getElementById('saved-routes-section');
    const saveCurrentRouteBtn = document.getElementById('save-current-route-btn');

    if (!savedRoutesSection || !saveCurrentRouteBtn) return;

    // Load saved routes when user is logged in
    loadSavedRoutes();

    // Save current route button
    saveCurrentRouteBtn.addEventListener('click', () => {
        saveCurrentRoute();
    });
}

async function loadSavedRoutes() {
    try {
        const { result } = await apiFetch('get_saved_routes');
        if (result.success && result.data) {
            displaySavedRoutes(result.data);
        }
    } catch (error) {
        console.error('Error loading saved routes:', error);
    }
}

function displaySavedRoutes(routes) {
    const savedRoutesSection = document.getElementById('saved-routes-section');
    const savedRoutesList = document.getElementById('saved-routes-list');

    if (!savedRoutesSection || !savedRoutesList) return;

    if (routes.length === 0) {
        savedRoutesSection.style.display = 'none';
        return;
    }

    savedRoutesSection.style.display = 'block';
    savedRoutesList.innerHTML = '';

    routes.forEach(route => {
        const routeCard = document.createElement('div');
        routeCard.className = 'saved-route-card';
        routeCard.innerHTML = `
            <div class="route-name">${route.route_name}</div>
            <div class="route-points">
                <span>${route.start_point}</span>
                <span class="arrow">→</span>
                <span>${route.end_point}</span>
            </div>
            <div class="route-actions">
                <button class="btn btn-primary" onclick="quickRepeatRoute('${route.start_point}', '${route.end_point}')">دووبارەکردنەوە</button>
                <button class="btn btn-delete" onclick="deleteRoute(${route.id})">سڕینەوە</button>
            </div>
        `;
        savedRoutesList.appendChild(routeCard);
    });
}

async function saveCurrentRoute() {
    const startPoint = document.getElementById('search-departure')?.value || '';
    const endPoint = document.getElementById('search-destination')?.value || '';
    const routeName = prompt('ناوێک بۆ ڕێگاکە بنووسە:');

    if (!routeName || !startPoint || !endPoint) {
        showToast('error', 'تکایە ناوی ڕێگا، خاڵی دەستپێک و خاڵی گەیشتن دیاری بکە');
        return;
    }

    try {
        const { result } = await apiFetch('save_route', {
            method: 'POST',
            body: JSON.stringify({
                start_point: startPoint,
                end_point: endPoint,
                route_name: routeName
            })
        });

        if (result.success) {
            showToast('success', 'ڕێگاکە پاشەکەوترا');
            loadSavedRoutes();
        } else {
            showToast('error', result.message);
        }
    } catch (error) {
        showToast('error', 'هەڵە لە پاشەکەوتکردنی ڕێگا');
    }
}

async function deleteRoute(routeId) {
    if (!confirm('دڵنیاییت کە دەتەوێت ئەم ڕێگایە بسڕیتەوە؟')) return;

    try {
        const { result } = await apiFetch('delete_route', {
            method: 'POST',
            body: JSON.stringify({ route_id: routeId })
        });

        if (result.success) {
            showToast('success', 'ڕێگاکە سڕایەوە');
            loadSavedRoutes();
        } else {
            showToast('error', result.message);
        }
    } catch (error) {
        showToast('error', 'هەڵە لە سڕینەوەی ڕێگا');
    }
}

function quickRepeatRoute(startPoint, endPoint) {
    const departureInput = document.getElementById('search-departure');
    const destinationInput = document.getElementById('search-destination');

    if (departureInput) departureInput.value = startPoint;
    if (destinationInput) destinationInput.value = endPoint;

    // Switch to search trips tab
    const searchTripsTab = document.querySelector('[data-tab="search-trips"]');
    if (searchTripsTab) {
        searchTripsTab.click();
    }

    showToast('success', 'ڕێگاکە بارکرا');
}

// ============================================================================
// PROXIMITY SEARCH: Initialize Proximity Search
// ============================================================================
function initializeProximitySearch() {
    const searchNearbyBtn = document.getElementById('search-nearby-btn');
    const proximityEnabled = document.getElementById('proximity-enabled');
    const proximityRadius = document.getElementById('proximity-radius');

    if (!searchNearbyBtn || !proximityEnabled || !proximityRadius) return;

    searchNearbyBtn.addEventListener('click', async () => {
        if (!proximityEnabled.checked) {
            showToast('error', 'تکایە چێک بکە لە "شوفێرە نزیکەکان"');
            return;
        }

        // Get user's current location
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    const latitude = position.coords.latitude;
                    const longitude = position.coords.longitude;
                    const radiusKm = parseFloat(proximityRadius.value) || 5;

                    try {
                        const { result } = await apiFetch('search_nearby_drivers', {
                            method: 'POST',
                            body: JSON.stringify({
                                latitude: latitude,
                                longitude: longitude,
                                radius_km: radiusKm
                            })
                        });

                        if (result.success && result.data) {
                            displayNearbyTrips(result.data);
                            showToast('success', `${result.data.length} شوفێری نزیک دۆزرایەوە`);
                        } else {
                            showToast('error', result.message);
                        }
                    } catch (error) {
                        showToast('error', 'هەڵە لە گەڕان بە شوفێرە نزیکەکان');
                    }
                },
                (error) => {
                    showToast('error', 'ناتوانرا شوێنەکەت وەربگرێت');
                }
            );
        } else {
            showToast('error', 'Geolocation پشتگیری نەکراوە لە براوسەرەکەتدا');
        }
    });
}

function displayNearbyTrips(trips) {
    const tripsFeedContainer = document.getElementById('trips-feed-container');
    if (!tripsFeedContainer) return;

    tripsFeedContainer.innerHTML = '';

    if (trips.length === 0) {
        tripsFeedContainer.innerHTML = '<p style="text-align: center; color: var(--text-muted);">هیچ شوفێرێک لە نزیکیدا نەدۆزرایەوە</p>';
        return;
    }

    trips.forEach(trip => {
        const tripCard = createTripCard(trip);
        tripsFeedContainer.appendChild(tripCard);
    });
}


// ============================================================================
// DARK MODE INITIALIZATION
// ============================================================================
function initDarkMode() {
    const savedTheme = localStorage.getItem('sharek-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = savedTheme === 'dark' || (!savedTheme && prefersDark);

    document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
    document.body.classList.toggle('dark-mode', isDark);

    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.textContent = isDark ? '☀️' : '🌙';
        themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    }

    if (themeToggle && !themeToggle.__darkModeListenerAdded) {
        themeToggle.__darkModeListenerAdded = true;
        themeToggle.addEventListener('click', () => {
            const currentlyDark = document.body.classList.contains('dark-mode');
            const newTheme = currentlyDark ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            document.body.classList.toggle('dark-mode', !currentlyDark);
            themeToggle.textContent = !currentlyDark ? '☀️' : '🌙';
            themeToggle.setAttribute('aria-pressed', !currentlyDark ? 'true' : 'false');
            localStorage.setItem('sharek-theme', newTheme);
        });
    }
}

// ============================================================================
// DASHBOARD MAP INITIALIZATION
// ============================================================================
function initializeDashboardMap() {
    const mapContainer = document.getElementById('dashboard-map');
    if (!mapContainer) return;
    
    // Initialize map centered on Iraq
    const map = L.map('dashboard-map').setView([33.3152, 44.3661], 6);
    
    // Add MapTiler tiles
    L.tileLayer(`https://api.maptiler.com/maps/streets/{z}/{x}/{y}.png?key=${MAPTILER_API_KEY}`, {
        attribution: '\u00a9 <a href="https://www.maptiler.com/copyright/" target="_blank">MapTiler</a> \u00a9 <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
        maxZoom: 20,
        crossOrigin: true,
    }).addTo(map);
    
    // Custom icon for trip markers
    const tripIcon = L.icon({
        iconUrl: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjMWUzYThhIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+PHBhdGggZD0iTTEyIDJDOC4xNCAyIDUgNS4xNCA1IDlzMy4xNCA3IDcgNyA3LTMuMTQgNy03IDMuMTQtNyA3LTd6Ii8+PHBhdGggZD0iTTEyIDZhMiAyIDAgMSAwIDAgNCAyIDIgMCAwIDAgMC00eiIvPjwvc3ZnPg==',
        iconSize: [32, 32],
        iconAnchor: [16, 32],
        popupAnchor: [0, -32]
    });
    
    // Fetch available trips from API
    fetch(`${API_URL}?action=get_map_trips`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const trips = data.data;
                
                // Add markers for each trip
                trips.forEach(function(trip) {
                    if (trip.latitude && trip.longitude) {
                        const marker = L.marker([trip.latitude, trip.longitude], {
                            icon: tripIcon
                        }).addTo(map);
                        
                        // Create popup content
                        const popupContent = `
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
                            </div>
                        `;
                        
                        marker.bindPopup(popupContent);
                    }
                });
                
                // Fit map to show all markers if there are any
                if (trips.length > 0) {
                    const group = new L.featureGroup();
                    trips.forEach(function(trip) {
                        if (trip.latitude && trip.longitude) {
                            L.marker([trip.latitude, trip.longitude]).addTo(group);
                        }
                    });
                    map.fitBounds(group.getBounds().pad(0.1));
                }
            }
        })
        .catch(error => {
            console.error('Error fetching trips for map:', error);
        });
}