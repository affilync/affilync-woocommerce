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

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT_K' ) ) {
    define( 'OBJECT_K', 'OBJECT_K' );
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

if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( $text, $domain = 'default' ) {
        echo esc_html( $text );
    }
}

if ( ! function_exists( '_e' ) ) {
    function _e( $text, $domain = 'default' ) {
        echo $text;
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
        return isset( $GLOBALS['affilync_test_user_cap'] ) ? (bool) $GLOBALS['affilync_test_user_cap'] : true;
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
        public $status = 200;
        public $headers = array();
        public function __construct( $data = null, $status = 200, $headers = array() ) {
            $this->data    = $data;
            $this->status  = $status;
            $this->headers = $headers;
        }
        public function set_status( $code ) { $this->status = $code; }
        public function header( $key, $value ) { $this->headers[ $key ] = $value; }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private $params = array();
        private $headers = array();
        private $body = '';
        private $json_params = array();
        public function __construct( $method = 'GET', $route = '' ) {}
        public function set_body( $body ) { $this->body = $body; }
        public function get_body() { return $this->body; }
        public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
        public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
        public function get_params() { return $this->params; }
        public function set_header( $key, $value ) { $this->headers[ strtolower( $key ) ] = $value; }
        public function get_header( $key ) { return isset( $this->headers[ strtolower( $key ) ] ) ? $this->headers[ strtolower( $key ) ] : null; }
        public function set_json_params( $params ) { $this->json_params = $params; }
        public function get_json_params() { return $this->json_params; }
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
        if ( ! isset( $GLOBALS['affilync_registered_routes'] ) ) {
            $GLOBALS['affilync_registered_routes'] = array();
        }
        $GLOBALS['affilync_registered_routes'][] = array(
            'namespace' => $namespace,
            'route'     => $route,
        );
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

// Mock $wpdb global — named class so it can be serialized by PHPUnit
// when running tests in separate processes.
if ( ! class_exists( 'Affilync_Mock_Wpdb' ) ) {
    class Affilync_Mock_Wpdb {
        public $prefix = 'wp_';
        public $last_error = '';
        public $insert_id = 0;
        private $results = array();

        public function query( $query ) {
            return true;
        }

        public function prepare( $query, ...$args ) {
            // WordPress accepts both prepare($q, $a, $b) and prepare($q, array($a, $b)).
            if ( count( $args ) === 1 && is_array( $args[0] ) ) {
                $args = $args[0];
            }
            // Replace %d and %s placeholders safely.
            $i = 0;
            return preg_replace_callback( '/%[sd]/', function( $m ) use ( $args, &$i ) {
                $val = isset( $args[ $i ] ) ? $args[ $i ] : '';
                $i++;
                return ( $m[0] === '%d' ) ? intval( $val ) : "'" . $val . "'";
            }, $query );
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
    }
}

if ( ! isset( $GLOBALS['wpdb'] ) || null === $GLOBALS['wpdb'] ) {
    $GLOBALS['wpdb'] = new Affilync_Mock_Wpdb();
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

// ---------------------------------------------------------------------------
// Additional mocks required for Conversion Tracker, API Client, Cookie Handler,
// and Product Sync test suites.
// ---------------------------------------------------------------------------

if ( ! defined( 'AFFILYNC_API_URL' ) ) {
    define( 'AFFILYNC_API_URL', 'https://api.affilync.com' );
}

if ( ! defined( 'AFFILYNC_VERSION' ) ) {
    define( 'AFFILYNC_VERSION', '1.0.0-test' );
}

if ( ! defined( 'AUTH_KEY' ) ) {
    define( 'AUTH_KEY', 'test_auth_key_for_phpunit_cookie_signing' );
}

if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => '{}',
        );
    }
}

if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $post_id = null ) {
        return 'product';
    }
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $attachment_id ) {
        return 'https://example.com/wp-content/uploads/image-' . $attachment_id . '.jpg';
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key = '', $single = false ) {
        return $single ? '' : array();
    }
}

if ( ! function_exists( 'get_term' ) ) {
    function get_term( $term_id, $taxonomy = '' ) {
        return (object) array(
            'term_id' => $term_id,
            'name'    => 'Term ' . $term_id,
            'slug'    => 'term-' . $term_id,
        );
    }
}

if ( ! function_exists( 'wc_get_product' ) ) {
    function wc_get_product( $product_id ) {
        // Allow tests to inject a specific product via global.
        if ( isset( $GLOBALS['affilync_test_wc_product'] ) ) {
            $product = $GLOBALS['affilync_test_wc_product'];
            // Only return if the IDs match (or no get_id available).
            if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
                if ( $product->get_id() == $product_id ) {
                    return $product;
                }
            }
            // Fallback: return whatever was set.
            return $product;
        }
        return null;
    }
}

if ( ! function_exists( 'wc_get_products' ) ) {
    function wc_get_products( $args = array() ) {
        // Tests can seed a catalog of ids via $GLOBALS['affilync_test_product_ids'];
        // this honors limit/offset so batch paging can be exercised. Default: [].
        if ( isset( $GLOBALS['affilync_test_product_ids'] ) && is_array( $GLOBALS['affilync_test_product_ids'] ) ) {
            $ids    = $GLOBALS['affilync_test_product_ids'];
            $offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
            $limit  = isset( $args['limit'] ) ? (int) $args['limit'] : -1;
            if ( $limit < 0 ) {
                return array_slice( $ids, $offset );
            }
            return array_slice( $ids, $offset, $limit );
        }
        return array();
    }
}

if ( ! function_exists( 'as_next_scheduled_action' ) ) {
    function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
        // Tests can force "already scheduled" via $GLOBALS['affilync_test_next_action'].
        return isset( $GLOBALS['affilync_test_next_action'] ) ? $GLOBALS['affilync_test_next_action'] : false;
    }
}

if ( ! function_exists( 'wp_count_posts' ) ) {
    function wp_count_posts( $type = 'post', $perm = '' ) {
        $publish = isset( $GLOBALS['affilync_test_product_ids'] ) && is_array( $GLOBALS['affilync_test_product_ids'] )
            ? count( $GLOBALS['affilync_test_product_ids'] )
            : 0;
        return (object) array( 'publish' => $publish );
    }
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    function get_woocommerce_currency() {
        return 'USD';
    }
}

if ( ! function_exists( 'wc_get_product_terms' ) ) {
    function wc_get_product_terms( $product_id, $taxonomy, $args = array() ) {
        return array();
    }
}

if ( ! function_exists( 'wc_attribute_label' ) ) {
    function wc_attribute_label( $name ) {
        return ucfirst( str_replace( 'pa_', '', $name ) );
    }
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax() {
        return false;
    }
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
    function wp_doing_cron() {
        return false;
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url, $protocols = null ) {
        return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
    }
}

if ( ! function_exists( 'esc_js' ) ) {
    function esc_js( $text ) {
        return addslashes( $text );
    }
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
    $GLOBALS['affilync_scheduled_actions'] = array();

    function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
        $GLOBALS['affilync_scheduled_actions'][] = array(
            'timestamp' => $timestamp,
            'hook'      => $hook,
            'args'      => $args,
            'group'     => $group,
        );
        return count( $GLOBALS['affilync_scheduled_actions'] );
    }
}

if ( ! function_exists( 'affilync_log_audit_event' ) ) {
    function affilync_log_audit_event( $event, $data = array() ) {
        // No-op for testing.
    }
}

// Additional constants for integrity, license, REST, and notification tests.
if ( ! defined( 'AFFILYNC_PLUGIN_FILE' ) ) {
    define( 'AFFILYNC_PLUGIN_FILE', '/var/www/html/wp-content/plugins/affilync-woocommerce/affilync-woocommerce.php' );
}

if ( ! defined( 'AFFILYNC_APP_URL' ) ) {
    define( 'AFFILYNC_APP_URL', 'https://app.affilync.com' );
}

if ( ! defined( 'WC_VERSION' ) ) {
    define( 'WC_VERSION', '8.0.0' );
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) {
        return 'test_salt_for_phpunit_' . $scheme;
    }
}

if ( ! function_exists( 'register_setting' ) ) {
    function register_setting( $option_group, $option_name, $args = array() ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
        if ( ! isset( $GLOBALS['affilync_sent_emails'] ) ) {
            $GLOBALS['affilync_sent_emails'] = array();
        }
        $GLOBALS['affilync_sent_emails'][] = array(
            'to' => $to, 'subject' => $subject, 'message' => $message,
        );
        return true;
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $email ) {
        return filter_var( $email, FILTER_SANITIZE_EMAIL );
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( ...$args ) { /* no-op */ }
}

if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( ...$args ) { /* no-op */ }
}

if ( ! function_exists( 'wc_price' ) ) {
    function wc_price( $price ) {
        return '$' . number_format( (float) $price, 2 );
    }
}

if ( ! function_exists( 'selected' ) ) {
    function selected( $selected, $current = true, $echo = true ) {
        $result = $selected == $current ? " selected='selected'" : '';
        if ( $echo ) echo $result;
        return $result;
    }
}

if ( ! function_exists( 'human_time_diff' ) ) {
    function human_time_diff( $from, $to = 0 ) {
        $diff = abs( ( $to ?: time() ) - $from );
        if ( $diff < 3600 ) return round( $diff / 60 ) . ' mins';
        if ( $diff < 86400 ) return round( $diff / 3600 ) . ' hours';
        return round( $diff / 86400 ) . ' days';
    }
}

if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $echo = true ) {
        $result = $checked == $current ? " checked='checked'" : '';
        if ( $echo ) echo $result;
        return $result;
    }
}

if ( ! function_exists( 'add_submenu_page' ) ) {
    function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
        if ( ! isset( $GLOBALS['affilync_menu_pages'] ) ) {
            $GLOBALS['affilync_menu_pages'] = array();
        }
        $GLOBALS['affilync_menu_pages'][] = array(
            'parent_slug' => $parent_slug,
            'menu_slug'   => $menu_slug,
            'page_title'  => $page_title,
        );
        return $menu_slug;
    }
}

if ( ! function_exists( 'add_settings_section' ) ) {
    function add_settings_section( $id, $title, $callback, $page, $args = array() ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'add_settings_field' ) ) {
    function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'settings_fields' ) ) {
    function settings_fields( $option_group ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'do_settings_sections' ) ) {
    function do_settings_sections( $page ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'submit_button' ) ) {
    function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = '' ) {
        // No-op for testing.
    }
}

if ( ! function_exists( 'get_admin_page_title' ) ) {
    function get_admin_page_title() {
        return 'Affilync Settings';
    }
}

if ( ! function_exists( 'wc_get_order_statuses' ) ) {
    function wc_get_order_statuses() {
        return array(
            'wc-pending'    => 'Pending payment',
            'wc-processing' => 'Processing',
            'wc-on-hold'    => 'On hold',
            'wc-completed'  => 'Completed',
            'wc-cancelled'  => 'Cancelled',
            'wc-refunded'   => 'Refunded',
            'wc-failed'     => 'Failed',
        );
    }
}

if ( ! function_exists( 'rawurlencode' ) ) {
    // Already exists in PHP, just ensure it's available.
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( ...$args ) { /* no-op */ }
}
