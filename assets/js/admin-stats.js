/**
 * Affilync Admin Stats JavaScript.
 *
 * Handles chart rendering and stats dashboard functionality.
 *
 * @package Affilync_WooCommerce
 */

(function($) {
    'use strict';

    /**
     * Stats dashboard controller.
     */
    const AffilyncStats = {
        /**
         * Chart instances.
         */
        charts: {
            conversions: null,
            revenue: null
        },

        /**
         * Current period.
         */
        currentPeriod: '30days',

        /**
         * Chart.js default configuration.
         */
        chartDefaults: {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 2,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#1a1a1a',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 6,
                    displayColors: false
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#646970',
                        font: {
                            size: 11
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#f0f0f1'
                    },
                    ticks: {
                        color: '#646970',
                        font: {
                            size: 11
                        }
                    }
                }
            }
        },

        /**
         * Initialize the stats dashboard.
         */
        init: function() {
            this.bindEvents();
            this.initCharts();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            const self = this;

            // Period selector change.
            $('#affilync-period').on('change', function() {
                self.currentPeriod = $(this).val();
                self.updateCharts();
                self.updateStats();
            });

            // Export button click.
            $('.affilync-export-btn').on('click', function() {
                const format = $(this).data('format') || 'csv';
                self.exportStats(format);
            });
        },

        /**
         * Initialize charts.
         */
        initCharts: function() {
            const conversionsCanvas = document.getElementById('affilync-conversions-chart');
            const revenueCanvas = document.getElementById('affilync-revenue-chart');

            if (!conversionsCanvas || !revenueCanvas) {
                return;
            }

            // Initialize conversions chart.
            this.charts.conversions = new Chart(conversionsCanvas, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: affilyncStats.i18n.conversions,
                        data: [],
                        borderColor: '#0066e6',
                        backgroundColor: 'rgba(0, 102, 230, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#0066e6',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    ...this.chartDefaults,
                    plugins: {
                        ...this.chartDefaults.plugins,
                        tooltip: {
                            ...this.chartDefaults.plugins.tooltip,
                            callbacks: {
                                label: function(context) {
                                    return affilyncStats.i18n.conversions + ': ' + context.raw;
                                }
                            }
                        }
                    }
                }
            });

            // Initialize revenue chart.
            this.charts.revenue = new Chart(revenueCanvas, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: affilyncStats.i18n.revenue,
                        data: [],
                        backgroundColor: 'rgba(0, 179, 89, 0.8)',
                        borderColor: '#00b359',
                        borderWidth: 1,
                        borderRadius: 4,
                        hoverBackgroundColor: '#00b359'
                    }]
                },
                options: {
                    ...this.chartDefaults,
                    plugins: {
                        ...this.chartDefaults.plugins,
                        tooltip: {
                            ...this.chartDefaults.plugins.tooltip,
                            callbacks: {
                                label: function(context) {
                                    return affilyncStats.i18n.revenue + ': $' + context.raw.toFixed(2);
                                }
                            }
                        }
                    },
                    scales: {
                        ...this.chartDefaults.scales,
                        y: {
                            ...this.chartDefaults.scales.y,
                            ticks: {
                                ...this.chartDefaults.scales.y.ticks,
                                callback: function(value) {
                                    return '$' + value;
                                }
                            }
                        }
                    }
                }
            });

            // Load initial data.
            this.updateCharts();
        },

        /**
         * Update charts with new data.
         */
        updateCharts: function() {
            const self = this;

            // Load conversions data.
            this.loadChartData('conversions', function(data) {
                if (self.charts.conversions) {
                    self.charts.conversions.data.labels = data.labels;
                    self.charts.conversions.data.datasets[0].data = data.values;
                    self.charts.conversions.update();
                }
            });

            // Load revenue data.
            this.loadChartData('revenue', function(data) {
                if (self.charts.revenue) {
                    self.charts.revenue.data.labels = data.labels;
                    self.charts.revenue.data.datasets[0].data = data.values;
                    self.charts.revenue.update();
                }
            });
        },

        /**
         * Load chart data via AJAX.
         *
         * @param {string} type Data type (conversions, revenue, commission).
         * @param {Function} callback Callback function.
         */
        loadChartData: function(type, callback) {
            $.ajax({
                url: affilyncStats.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'affilync_get_chart_data',
                    nonce: affilyncStats.nonce,
                    period: this.currentPeriod,
                    type: type
                },
                success: function(response) {
                    if (response.success) {
                        callback(response.data);
                    }
                },
                error: function() {
                    console.error('Failed to load chart data for: ' + type);
                }
            });
        },

        /**
         * Update stats summary.
         */
        updateStats: function() {
            // Reload page with new period (simpler approach for stats cards).
            const url = new URL(window.location.href);
            url.searchParams.set('period', this.currentPeriod);
            window.location.href = url.toString();
        },

        /**
         * Export stats data.
         *
         * @param {string} format Export format (csv, json).
         */
        exportStats: function(format) {
            const self = this;
            const $button = $('.affilync-export-btn');
            const originalText = $button.html();

            $button.html('<span class="dashicons dashicons-update spin"></span> ' + affilyncStats.i18n.loading);
            $button.prop('disabled', true);

            $.ajax({
                url: affilyncStats.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'affilync_export_stats',
                    nonce: affilyncStats.nonce,
                    period: this.currentPeriod,
                    format: format
                },
                success: function(response) {
                    if (response.success) {
                        self.downloadFile(response.data.filename, response.data.data, format);
                    }
                },
                error: function() {
                    alert('Export failed. Please try again.');
                },
                complete: function() {
                    $button.html(originalText);
                    $button.prop('disabled', false);
                }
            });
        },

        /**
         * Trigger file download.
         *
         * @param {string} filename File name.
         * @param {string} content File content.
         * @param {string} format File format.
         */
        downloadFile: function(filename, content, format) {
            const mimeTypes = {
                csv: 'text/csv',
                json: 'application/json'
            };

            const blob = new Blob([content], { type: mimeTypes[format] || 'text/plain' });
            const url = URL.createObjectURL(blob);

            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            URL.revokeObjectURL(url);
        }
    };

    /**
     * Initialize on document ready.
     */
    $(document).ready(function() {
        if (typeof affilyncStats !== 'undefined') {
            AffilyncStats.init();
        }
    });

    // Add CSS for spinning icon.
    $('<style>')
        .prop('type', 'text/css')
        .html('.dashicons.spin { animation: spin 1s linear infinite; } @keyframes spin { 100% { transform: rotate(360deg); } }')
        .appendTo('head');

})(jQuery);
