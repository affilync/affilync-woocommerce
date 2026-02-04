/**
 * Affilync WooCommerce Frontend Tracking
 *
 * Captures affiliate tracking parameters and manages cookies.
 * This script is output inline by the cookie handler for reliability.
 * This file serves as a backup/reference implementation.
 */

(function() {
    'use strict';

    const AffilyncTracking = {
        /**
         * Configuration (can be overridden)
         */
        config: {
            cookieName: 'affilync_tracking',
            firstClickCookie: 'affilync_first_click',
            cookieDuration: 30, // days
            trackParams: ['ref', 'aff', 'affiliate', 'affiliate_id', 'campaign', 'campaign_id', 'link', 'link_id', 'click', 'click_id', 'source', 'sub1', 'sub2', 'sub3']
        },

        /**
         * Parameter to data key mapping
         */
        paramMap: {
            'ref': 'affiliate_id',
            'aff': 'affiliate_id',
            'affiliate': 'affiliate_id',
            'affiliate_id': 'affiliate_id',
            'campaign': 'campaign_id',
            'campaign_id': 'campaign_id',
            'link': 'link_id',
            'link_id': 'link_id',
            'click': 'click_id',
            'click_id': 'click_id',
            'source': 'source',
            'sub1': 'sub1',
            'sub2': 'sub2',
            'sub3': 'sub3'
        },

        /**
         * Initialize tracking
         */
        init: function() {
            const urlData = this.getUrlParams();

            if (Object.keys(urlData).length > 0) {
                // Add metadata
                urlData.captured_at = Math.floor(Date.now() / 1000);
                urlData.landing_url = window.location.href;
                urlData.referrer = document.referrer || '';

                // Check if first click cookie should be set
                const existingFirst = this.getCookie(this.config.firstClickCookie);
                if (!existingFirst) {
                    this.setCookie(this.config.firstClickCookie, urlData);
                }

                // Always update last click
                this.setCookie(this.config.cookieName, urlData);

                // Fire tracking pixel if configured
                this.fireTrackingPixel(urlData);
            }
        },

        /**
         * Get tracking parameters from URL
         */
        getUrlParams: function() {
            const params = {};
            const urlParams = new URLSearchParams(window.location.search);

            this.config.trackParams.forEach(function(key) {
                if (urlParams.has(key)) {
                    const mappedKey = AffilyncTracking.paramMap[key] || key;
                    params[mappedKey] = urlParams.get(key);
                }
            });

            return params;
        },

        /**
         * Set a cookie
         */
        setCookie: function(name, data) {
            const encoded = btoa(JSON.stringify(data));
            const expires = new Date();
            expires.setDate(expires.getDate() + this.config.cookieDuration);

            let cookieString = name + '=' + encoded;
            cookieString += '; expires=' + expires.toUTCString();
            cookieString += '; path=/';
            cookieString += '; SameSite=Lax';

            if (location.protocol === 'https:') {
                cookieString += '; Secure';
            }

            document.cookie = cookieString;
        },

        /**
         * Get a cookie
         */
        getCookie: function(name) {
            const cookies = document.cookie.split(';');

            for (let i = 0; i < cookies.length; i++) {
                const cookie = cookies[i].trim();
                if (cookie.indexOf(name + '=') === 0) {
                    try {
                        const value = cookie.substring(name.length + 1);
                        // Handle signed cookies (value.signature format)
                        const parts = value.split('.');
                        return JSON.parse(atob(parts[0]));
                    } catch (e) {
                        return null;
                    }
                }
            }

            return null;
        },

        /**
         * Get current tracking data
         */
        getTrackingData: function() {
            // Try last click first
            let data = this.getCookie(this.config.cookieName);
            if (data) return data;

            // Fall back to first click
            return this.getCookie(this.config.firstClickCookie);
        },

        /**
         * Check if tracking is active
         */
        hasTracking: function() {
            const data = this.getTrackingData();
            return data && (data.affiliate_id || data.click_id);
        },

        /**
         * Fire tracking pixel (click registration)
         */
        fireTrackingPixel: function(data) {
            // Only fire if we have an affiliate or click ID
            if (!data.affiliate_id && !data.click_id) {
                return;
            }

            // Build pixel URL
            const pixelUrl = (window.affilyncConfig?.apiUrl || 'https://track.affilync.com') + '/api/clicks/register';
            const params = new URLSearchParams({
                aff: data.affiliate_id || '',
                campaign: data.campaign_id || '',
                link: data.link_id || '',
                click: data.click_id || '',
                url: encodeURIComponent(data.landing_url || ''),
                ref: encodeURIComponent(data.referrer || ''),
                t: Date.now()
            });

            // Fire pixel
            const img = new Image();
            img.src = pixelUrl + '?' + params.toString();
        },

        /**
         * Get affiliate ID
         */
        getAffiliateId: function() {
            const data = this.getTrackingData();
            return data ? data.affiliate_id : null;
        },

        /**
         * Get click ID
         */
        getClickId: function() {
            const data = this.getTrackingData();
            return data ? data.click_id : null;
        },

        /**
         * Get campaign ID
         */
        getCampaignId: function() {
            const data = this.getTrackingData();
            return data ? data.campaign_id : null;
        },

        /**
         * Clear tracking cookies
         */
        clearTracking: function() {
            const past = new Date(0).toUTCString();
            document.cookie = this.config.cookieName + '=; expires=' + past + '; path=/';
            document.cookie = this.config.firstClickCookie + '=; expires=' + past + '; path=/';
        }
    };

    // Auto-initialize
    AffilyncTracking.init();

    // Expose to global scope
    window.AffilyncTracking = AffilyncTracking;

})();
