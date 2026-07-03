/* Sharek Website - Offers JavaScript */
/* Kurdish Sorani (سۆرانی) - Partner Offers */

async function initOffers() {
    const container = document.getElementById('offers-container');
    
    if (!container) return;
    
    try {
        const response = await fetch('api.php?action=get_offers');
        const data = await response.json();
        
        if (data.success && data.data && data.data.length > 0) {
            renderOffers(data.data.slice(0, 3)); // Max 3 offers
        } else {
            // Empty state in Kurdish
            container.innerHTML = `
                <div style="text-align: center; padding: 2rem; grid-column: 1 / -1;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">🎁</div>
                    <p style="color: var(--text-secondary);">بەزوودی پێشنیارەی نوێمان هەیە 🎁</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error fetching offers:', error);
        
        // Empty state in Kurdish
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; grid-column: 1 / -1;">
                <div style="font-size: 3rem; margin-bottom: 1rem;">🎁</div>
                <p style="color: var(--text-secondary);">بەزوودی پێشنیارەی نوێمان هەیە 🎁</p>
            </div>
        `;
    }
}

function renderOffers(offers) {
    const container = document.getElementById('offers-container');
    
    if (!container) return;
    
    // Fields match the actual getOffers() API/schema shape — company_name,
    // offer_details, link — not icon/title/description/discount, which
    // don't exist on the backend (audit finding #32). Values are already
    // htmlspecialchars()-escaped server-side in api.php::getOffers().
    const offersHTML = offers.map(offer => `
        <div class="offer-card">
            <div style="font-size: 2rem; margin-bottom: 1rem;">🎁</div>
            <h3>${offer.company_name || 'پێشنیارێکی تایبەت'}</h3>
            <p>${offer.offer_details || 'پێشنیارێکی تایبەت لەگەڵ هاوپەیمانەکانمان'}</p>
            ${offer.link ? `<a href="${offer.link}" target="_blank" rel="noopener noreferrer" style="color: var(--green); font-weight: 600; margin-top: 0.5rem; display: inline-block;">زیاتر بزانە ←</a>` : ''}
        </div>
    `).join('');
    
    container.innerHTML = offersHTML;
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOffers);
} else {
    initOffers();
}
