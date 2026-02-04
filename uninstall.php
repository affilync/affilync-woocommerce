<?php
/**
 * Affilync WooCommerce Uninstall
 *
 * Fired when the plugin is uninstalled.
 *
 * @package Affilync_WooCommerce
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Check if user wants to remove all data.
$remove_data = get_option( 'affilync_remove_data_on_uninstall', false );

if ( $remove_data ) {
    // Delete plugin options.
    $options = array(
        'affilync_db_version',
        'affilync_api_credentials',
        'affilync_settings',
        'affilync_brand_id',
        'affilync_connection_status',
        'affilync_last_sync',
        'affilync_webhook_secret',
        'affilync_remove_data_on_uninstall',
    );

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Delete all transients.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_affilync_%',
            '_transient_timeout_affilync_%'
        )
    );

    // Drop custom tables.
    $tables = array(
        $wpdb->prefix . 'affilync_conversions',
        $wpdb->prefix . 'affilync_product_sync',
        $wpdb->prefix . 'affilync_webhooks',
        $wpdb->prefix . 'affilync_audit_log',
        $wpdb->prefix . 'affilync_rate_limits',
        $wpdb->prefix . 'affilync_nonces',
    );

    foreach ( $tables as $table ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }

    // Delete order meta.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
            '_affilync_%'
        )
    );

    // For HPOS compatibility, also check order meta table if it exists.
    $hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $hpos_meta_table
        )
    );

    if ( $table_exists ) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$hpos_meta_table} WHERE meta_key LIKE %s",
                '_affilync_%'
            )
        );
    }

    // Clear any scheduled cron events.
    wp_clear_scheduled_hook( 'affilync_sync_products' );
    wp_clear_scheduled_hook( 'affilync_sync_conversions' );
    wp_clear_scheduled_hook( 'affilync_cleanup_expired' );
}

// Always clean up transients even if not removing all data.
delete_transient( 'affilync_activation_redirect' );
