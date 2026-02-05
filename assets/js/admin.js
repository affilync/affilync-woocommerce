/**
 * Affilync WooCommerce Admin JavaScript
 */

(function($) {
    'use strict';

    const AffilyncAdmin = {
        /**
         * Initialize admin functionality
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Connect button
            $(document).on('click', '.affilync-connect', this.handleConnect.bind(this));

            // Disconnect button
            $(document).on('click', '.affilync-disconnect', this.handleDisconnect.bind(this));

            // Sync products button
            $(document).on('click', '.affilync-sync-products', this.handleSyncProducts.bind(this));

            // Full sync button
            $(document).on('click', '.affilync-full-sync', this.handleFullSync.bind(this));

            // Sync conversions button
            $(document).on('click', '.affilync-sync-conversions', this.handleSyncConversions.bind(this));

            // License management buttons
            $(document).on('click', '.affilync-activate-license', this.handleActivateLicense.bind(this));
            $(document).on('click', '.affilync-deactivate-license', this.handleDeactivateLicense.bind(this));
            $(document).on('click', '.affilync-check-license', this.handleCheckLicense.bind(this));
        },

        /**
         * Handle connect button click
         */
        handleConnect: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const originalText = $button.text();

            $button.text(affilyncAdmin.i18n.connecting).prop('disabled', true);

            this.apiRequest('oauth/initiate', 'POST')
                .done(function(response) {
                    if (response.success && response.auth_url) {
                        // Redirect to OAuth authorization
                        window.location.href = response.auth_url;
                    } else {
                        AffilyncAdmin.showNotice('error', response.message || 'Connection failed');
                        $button.text(originalText).prop('disabled', false);
                    }
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON?.message || 'Connection failed';
                    AffilyncAdmin.showNotice('error', message);
                    $button.text(originalText).prop('disabled', false);
                });
        },

        /**
         * Handle disconnect button click
         */
        handleDisconnect: function(e) {
            e.preventDefault();

            if (!confirm(affilyncAdmin.i18n.confirmDisconnect)) {
                return;
            }

            const $button = $(e.currentTarget);
            const originalText = $button.text();

            $button.text(affilyncAdmin.i18n.disconnecting).prop('disabled', true);

            this.apiRequest('oauth/disconnect', 'POST')
                .done(function(response) {
                    if (response.success) {
                        // Reload page to show disconnected state
                        window.location.reload();
                    } else {
                        AffilyncAdmin.showNotice('error', response.message || 'Disconnect failed');
                        $button.text(originalText).prop('disabled', false);
                    }
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON?.message || 'Disconnect failed';
                    AffilyncAdmin.showNotice('error', message);
                    $button.text(originalText).prop('disabled', false);
                });
        },

        /**
         * Handle sync products button click
         */
        handleSyncProducts: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const originalText = $button.text();

            $button.text(affilyncAdmin.i18n.syncing).prop('disabled', true);

            this.apiRequest('products/sync', 'POST')
                .done(function(response) {
                    if (response.success) {
                        AffilyncAdmin.showNotice('success', response.message);
                        // Reload to update stats
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        AffilyncAdmin.showNotice('error', response.message || 'Sync failed');
                    }
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON?.message || 'Sync failed';
                    AffilyncAdmin.showNotice('error', message);
                })
                .always(function() {
                    $button.text(originalText).prop('disabled', false);
                });
        },

        /**
         * Handle full sync button click
         */
        handleFullSync: function(e) {
            e.preventDefault();

            if (!confirm('This will sync all products. Continue?')) {
                return;
            }

            const $button = $(e.currentTarget);
            const originalText = $button.text();

            $button.text(affilyncAdmin.i18n.syncing).prop('disabled', true);

            this.apiRequest('products/sync', 'POST', { full: true })
                .done(function(response) {
                    if (response.success) {
                        AffilyncAdmin.showNotice('success', response.message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        AffilyncAdmin.showNotice('error', response.message || 'Sync failed');
                    }
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON?.message || 'Sync failed';
                    AffilyncAdmin.showNotice('error', message);
                })
                .always(function() {
                    $button.text(originalText).prop('disabled', false);
                });
        },

        /**
         * Handle sync conversions button click
         */
        handleSyncConversions: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const originalText = $button.text();

            $button.text(affilyncAdmin.i18n.syncing).prop('disabled', true);

            this.apiRequest('conversions/sync', 'POST')
                .done(function(response) {
                    if (response.success) {
                        const message = `Synced ${response.synced} conversions, ${response.failed} failed`;
                        AffilyncAdmin.showNotice('success', message);
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        AffilyncAdmin.showNotice('error', response.message || 'Sync failed');
                    }
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON?.message || 'Sync failed';
                    AffilyncAdmin.showNotice('error', message);
                })
                .always(function() {
                    $button.text(originalText).prop('disabled', false);
                });
        },

        /**
         * Handle activate license button click
         */
        handleActivateLicense: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const $input = $('#affilync-license-key');
            const licenseKey = $input.val().trim();

            if (!licenseKey) {
                AffilyncAdmin.showNotice('error', affilyncAdmin.i18n.enterLicenseKey || 'Please enter a license key');
                $input.focus();
                return;
            }

            const originalText = $button.text();
            $button.text(affilyncAdmin.i18n.activating || 'Activating...').prop('disabled', true);

            this.ajaxRequest('affilync_activate_license', { license_key: licenseKey })
                .done(function(response) {
                    if (response.success) {
                        AffilyncAdmin.showNotice('success', response.data.message || 'License activated successfully');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        AffilyncAdmin.showNotice('error', response.data.message || 'License activation failed');
                        $button.text(originalText).prop('disabled', false);
                    }
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON?.data?.message || 'License activation failed';
                    AffilyncAdmin.showNotice('error', message);
                    $button.text(originalText).prop('disabled', false);
                });
        },

        /**
         * Handle deactivate license button click
         */
        handleDeactivateLicense: function(e) {
            e.preventDefault();

            if (!confirm(affilyncAdmin.i18n.confirmDeactivate || 'Are you sure you want to deactivate this license?')) {
                return;
            }

            const $button = $(e.currentTarget);
            const originalText = $button.text();

            $button.text(affilyncAdmin.i18n.deactivating || 'Deactivating...').prop('disabled', true);

            this.ajaxRequest('affilync_deactivate_license')
                .done(function(response) {
                    if (response.success) {
                        AffilyncAdmin.showNotice('success', response.data.message || 'License deactivated');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        AffilyncAdmin.showNotice('error', response.data.message || 'Deactivation failed');
                        $button.text(originalText).prop('disabled', false);
                    }
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON?.data?.message || 'Deactivation failed';
                    AffilyncAdmin.showNotice('error', message);
                    $button.text(originalText).prop('disabled', false);
                });
        },

        /**
         * Handle check license button click
         */
        handleCheckLicense: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const originalText = $button.text();

            $button.text(affilyncAdmin.i18n.checking || 'Checking...').prop('disabled', true);

            this.ajaxRequest('affilync_check_license')
                .done(function(response) {
                    if (response.success) {
                        AffilyncAdmin.showNotice('success', response.data.message || 'License verified');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        AffilyncAdmin.showNotice('error', response.data.message || 'License verification failed');
                        $button.text(originalText).prop('disabled', false);
                    }
                })
                .fail(function(xhr) {
                    const message = xhr.responseJSON?.data?.message || 'License check failed';
                    AffilyncAdmin.showNotice('error', message);
                    $button.text(originalText).prop('disabled', false);
                });
        },

        /**
         * Make WordPress AJAX request
         */
        ajaxRequest: function(action, data) {
            const requestData = $.extend({
                action: action,
                nonce: affilyncAdmin.nonce
            }, data || {});

            return $.ajax({
                url: affilyncAdmin.ajaxUrl,
                method: 'POST',
                data: requestData
            });
        },

        /**
         * Make API request
         */
        apiRequest: function(endpoint, method, data) {
            return $.ajax({
                url: affilyncAdmin.restUrl + endpoint,
                method: method || 'GET',
                data: data ? JSON.stringify(data) : null,
                contentType: 'application/json',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', affilyncAdmin.nonce);
                }
            });
        },

        /**
         * Show admin notice
         */
        showNotice: function(type, message) {
            const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
            const $notice = $(`
                <div class="notice ${noticeClass} is-dismissible">
                    <p>${this.escapeHtml(message)}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);

            // Remove existing notices
            $('.affilync-settings .notice').remove();

            // Add new notice
            $('.affilync-settings h1').after($notice);

            // Setup dismiss handler
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            });

            // Auto-dismiss success notices
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut(function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        },

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        AffilyncAdmin.init();
    });

})(jQuery);
