/* Sharek Website - Stats JavaScript */
/* Kurdish Sorani (سۆرانی) - Animated Counters */

async function initStats() {
    const trustBar = document.querySelector('.trust-bar');
    if (!trustBar) return;

    // Use IntersectionObserver to animate only when trust-bar enters viewport
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                fetchAndAnimateStats();
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.2 });

    observer.observe(trustBar);
}

async function fetchAndAnimateStats() {
    const counters = document.querySelectorAll('.trust-number');
    let stats = {
        active_trips: 50,
        drivers: 35,
        rating: 4.9,
        cities: 10
    };

    try {
        const response = await fetch('api.php?action=get_stats');
        const data = await response.json();
        
        if (data.success && data.data) {
            stats = data.data;
        }
    } catch (error) {
        // Silently use fallback values on error
    }

    // Animate counters
    counters.forEach((counter, index) => {
        const label = counter.nextElementSibling?.textContent || '';
        let target;

        if (label.includes('گەشت')) {
            target = stats.active_trips;
        } else if (label.includes('شۆفێر')) {
            target = stats.drivers;
        } else if (label.includes('ڕیتینگ') || label.includes('ڕێژەی ڕەزامەندی')) {
            target = stats.rating;
        } else if (label.includes('شار')) {
            target = stats.cities;
        } else {
            return;
        }

        animateCounter(counter, target, label);
    });
}

function animateCounter(element, target, label) {
    const duration = 1800; // 1.8 seconds
    const start = 0;
    const startTime = performance.now();
    
    function update(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // EaseOutQuart easing function
        const easeOutQuart = 1 - Math.pow(1 - progress, 4);
        const current = start + (target - start) * easeOutQuart;
        
        // Format based on whether it's a decimal or integer
        if (target % 1 !== 0) {
            element.textContent = current.toFixed(1);
        } else {
            element.textContent = Math.floor(current);
        }
        
        if (progress < 1) {
            requestAnimationFrame(update);
        } else {
            // Ensure final value is exact
            if (target % 1 !== 0) {
                element.textContent = target.toFixed(1);
            } else {
                element.textContent = target;
                // Append '+' to trips and drivers counts
                if (label.includes('گەشت') || label.includes('شۆفێر')) {
                    element.textContent += '+';
                }
            }
        }
    }
    
    requestAnimationFrame(update);
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStats);
} else {
    initStats();
}
