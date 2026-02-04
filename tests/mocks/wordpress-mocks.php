<?php
/**
 * WordPress function mocks for unit testing
 *
 * @package Affilync_WooCommerce
 */

// Mock constants.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/var/www/html/' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'COOKIEPATH' ) ) {
    define( 'COOKIEPATH', '/' );
}

if ( ! defined( 'COOKIE_DOMAIN' ) ) {
    define( 'COOKIE_DOMAIN', '' );
}

// Mock functions.
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'get_option' ) ) {
    $GLOBALS['wp_options'] = array();

    function get_option( $option, $default = false ) {
        return isset( $GLOBALS['wp_options'][ $option ] ) ? $GLOBALS['wp_options'][ $option ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        $GLOBALS['wp_options'][ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $option ) {
        unset( $GLOBALS['wp_options'][ $option ] );
        return true;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

if ( ! function_exists( 'wp_rand' ) ) {
    function wp_rand( $min = 0, $max = 0 ) {
        return mt_rand( $min, $max );
    }
}

if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special = true, $extra_special = false ) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ( $special ) {
            $chars .= '!@#$%^&*()';
        }
        $password = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $password .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
        }
        return $password;
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) {
        if ( $type === 'mysql' ) {
            return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
        }
        return time();
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return esc_html( $text );
    }
}
