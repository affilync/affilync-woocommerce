<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Affilync_WooCommerce
 */

// Composer autoloader.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
    require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

// Define test constants.
define( 'AFFILYNC_TESTING', true );
define( 'AFFILYNC_TEST_DIR', __DIR__ );

// Mock WordPress functions if not in WP environment.
if ( ! function_exists( 'add_action' ) ) {
    require_once __DIR__ . '/mocks/wordpress-mocks.php';
}
