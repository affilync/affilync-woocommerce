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

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url, $protocols = null, $_context = 'display' ) {
        return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '', $scheme = null ) {
        return 'https://example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
    }
}

if ( ! function_exists( 'site_url' ) ) {
    function site_url( $path = '', $scheme = null ) {
        return 'https://example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $show = '', $filter = 'raw' ) {
        $values = array(
            'name'    => 'Test Store',
            'url'     => 'https://example.com',
            'version' => '6.4',
        );
        return isset( $values[ $show ] ) ? $values[ $show ] : '';
    }
}

if ( ! function_exists( 'is_ssl' ) ) {
    function is_ssl() {
        return true;
    }
}

if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => '{}',
        );
    }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = array() ) {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => '{}',
        );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return isset( $response['body'] ) ? $response['body'] : '';
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook_name, ...$args ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook_name, $value, ...$args ) {
        return $value;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_string( $args ) ) {
            parse_str( $args, $args );
        }
        return array_merge( $defaults, (array) $args );
    }
}

if ( ! defined( 'AFFILYNC_PLUGIN_URL' ) ) {
    define( 'AFFILYNC_PLUGIN_URL', 'https://example.com/wp-content/plugins/affilync-woocommerce/' );
}

if ( ! defined( 'AFFILYNC_PLUGIN_DIR' ) ) {
    define( 'AFFILYNC_PLUGIN_DIR', dirname( dirname( __DIR__ ) ) . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
    define( 'WEEK_IN_SECONDS', 604800 );
}

if ( ! function_exists( 'get_site_url' ) ) {
    function get_site_url( $blog_id = null, $path = '', $scheme = null ) {
        return 'https://example.com' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return 1;
    }
}

if ( ! function_exists( 'rest_get_server' ) ) {
    function rest_get_server() {
        return new class {
            public function get_routes() {
                return array();
            }
        };
    }
}

if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $path = '' ) {
        return 'https://example.com/wp-json/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
    function rest_ensure_response( $response ) {
        if ( $response instanceof WP_REST_Response ) {
            return $response;
        }
        return new WP_REST_Response( $response );
    }
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
        return true;
    }
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $location, $status = 302 ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( ...$args ) {
        if ( is_array( $args[0] ) ) {
            $params = $args[0];
            $url    = isset( $args[1] ) ? $args[1] : '';
        } else {
            $params = array( $args[0] => $args[1] );
            $url    = isset( $args[2] ) ? $args[2] : '';
        }
        $sep = strpos( $url, '?' ) !== false ? '&' : '?';
        return $url . $sep . http_build_query( $params );
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability, ...$args ) {
        return true;
    }
}

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        return null;
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct( $data = null, $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function get_data() {
            return $this->data;
        }
        public function get_status() {
            return $this->status;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private $params = array();
        private $body = '';
        public function __construct( $method = 'GET', $route = '' ) {}
        public function set_body( $body ) {
            $this->body = $body;
        }
        public function get_body() {
            return $this->body;
        }
        public function set_param( $key, $value ) {
            $this->params[ $key ] = $value;
        }
        public function get_param( $key ) {
            return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
        }
        public function get_params() {
            return $this->params;
        }
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook, $args = array() ) {
        return false;
    }
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
        return true;
    }
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
    function wp_clear_scheduled_hook( $hook, $args = array() ) {
        return 0;
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '', $scheme = 'admin' ) {
        return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        return false;
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
        return true;
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        return get_option( '_transient_' . $transient );
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        return update_option( '_transient_' . $transient, $value );
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $transient ) {
        return delete_option( '_transient_' . $transient );
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) {
        return md5( 'nonce_' . $action );
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        return $nonce === md5( 'nonce_' . $action ) ? 1 : false;
    }
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
        return 1;
    }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '', $title = '', $args = array() ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'wp_kses' ) ) {
    function wp_kses( $string, $allowed_html, $allowed_protocols = array() ) {
        return strip_tags( $string );
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $data ) {
        return $data;
    }
}

// Mock $wpdb global.
if ( ! isset( $GLOBALS['wpdb'] ) || null === $GLOBALS['wpdb'] ) {
    $GLOBALS['wpdb'] = new class {
        public $prefix = 'wp_';
        public $last_error = '';
        public $insert_id = 0;
        private $results = array();

        public function query( $query ) {
            return true;
        }

        public function prepare( $query, ...$args ) {
            return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
        }

        public function get_results( $query = null, $output = 'OBJECT' ) {
            return $this->results;
        }

        public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
            return null;
        }

        public function get_var( $query = null, $x = 0, $y = 0 ) {
            return null;
        }

        public function get_col( $query = null, $x = 0 ) {
            return array();
        }

        public function insert( $table, $data, $format = null ) {
            $this->insert_id = rand( 1, 99999 );
            return 1;
        }

        public function update( $table, $data, $where, $format = null, $where_format = null ) {
            return 1;
        }

        public function delete( $table, $where, $where_format = null ) {
            return 1;
        }

        public function get_charset_collate() {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    };
}

/**
 * Mock WP_Error class.
 */
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $errors  = array();
        private $error_data = array();

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( empty( $code ) ) {
                return;
            }
            $this->errors[ $code ][] = $message;
            if ( ! empty( $data ) ) {
                $this->error_data[ $code ] = $data;
            }
        }

        public function get_error_codes() {
            return array_keys( $this->errors );
        }

        public function get_error_code() {
            $codes = $this->get_error_codes();
            return ! empty( $codes ) ? $codes[0] : '';
        }

        public function get_error_messages( $code = '' ) {
            if ( empty( $code ) ) {
                $all = array();
                foreach ( $this->errors as $messages ) {
                    $all = array_merge( $all, $messages );
                }
                return $all;
            }
            return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
        }

        public function get_error_message( $code = '' ) {
            if ( empty( $code ) ) {
                $code = $this->get_error_code();
            }
            $messages = $this->get_error_messages( $code );
            return ! empty( $messages ) ? $messages[0] : '';
        }

        public function get_error_data( $code = '' ) {
            if ( empty( $code ) ) {
                $code = $this->get_error_code();
            }
            return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
        }

        public function add( $code, $message, $data = '' ) {
            $this->errors[ $code ][] = $message;
            if ( ! empty( $data ) ) {
                $this->error_data[ $code ] = $data;
            }
        }

        public function has_errors() {
            return ! empty( $this->errors );
        }
    }
}

/**
 * Mock WP_UnitTestCase - bridges WP-style set_up/tear_down to PHPUnit setUp/tearDown.
 */
if ( ! class_exists( 'WP_UnitTestCase' ) ) {
    class WP_UnitTestCase extends \PHPUnit\Framework\TestCase {

        protected function setUp(): void {
            parent::setUp();
            $GLOBALS['wp_options'] = array();
            $this->set_up();
        }

        protected function tearDown(): void {
            $this->tear_down();
            $GLOBALS['wp_options'] = array();
            parent::tearDown();
        }

        public function set_up() {
            // Base implementation - children override this.
        }

        public function tear_down() {
            // Base implementation - children override this.
        }
    }
}
