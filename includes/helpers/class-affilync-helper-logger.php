<?php
/**
 * Logger Helper for debug logging.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Logger Helper.
 *
 * Provides debug logging using WooCommerce logger or fallback to error_log.
 *
 * @since 1.0.0
 */
class Affilync_Helper_Logger {

    /**
     * Log source identifier.
     *
     * @var string
     */
    const SOURCE = 'affilync';

    /**
     * WooCommerce logger instance.
     *
     * @var WC_Logger|null
     */
    private static $wc_logger = null;

    /**
     * Log levels.
     */
    const LEVEL_DEBUG     = 'debug';
    const LEVEL_INFO      = 'info';
    const LEVEL_NOTICE    = 'notice';
    const LEVEL_WARNING   = 'warning';
    const LEVEL_ERROR     = 'error';
    const LEVEL_CRITICAL  = 'critical';
    const LEVEL_ALERT     = 'alert';
    const LEVEL_EMERGENCY = 'emergency';

    /**
     * Get WooCommerce logger instance.
     *
     * @return WC_Logger|null Logger instance.
     */
    private static function get_wc_logger() {
        if ( self::$wc_logger === null && function_exists( 'wc_get_logger' ) ) {
            self::$wc_logger = wc_get_logger();
        }
        return self::$wc_logger;
    }

    /**
     * Log a message.
     *
     * @param string $message Log message.
     * @param string $level   Log level.
     * @param array  $context Additional context.
     */
    public static function log( $message, $level = self::LEVEL_INFO, $context = array() ) {
        // Check if debug mode is enabled.
        if ( ! self::is_debug_enabled() && in_array( $level, array( self::LEVEL_DEBUG, self::LEVEL_INFO ), true ) ) {
            return;
        }

        $logger = self::get_wc_logger();

        if ( $logger ) {
            // Use WooCommerce logger.
            $context['source'] = self::SOURCE;
            $logger->log( $level, $message, $context );
        } else {
            // Fallback to error_log.
            $formatted = sprintf(
                '[Affilync %s] %s %s',
                strtoupper( $level ),
                $message,
                ! empty( $context ) ? wp_json_encode( $context ) : ''
            );
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( $formatted );
        }
    }

    /**
     * Log debug message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public static function debug( $message, $context = array() ) {
        self::log( $message, self::LEVEL_DEBUG, $context );
    }

    /**
     * Log info message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public static function info( $message, $context = array() ) {
        self::log( $message, self::LEVEL_INFO, $context );
    }

    /**
     * Log notice message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public static function notice( $message, $context = array() ) {
        self::log( $message, self::LEVEL_NOTICE, $context );
    }

    /**
     * Log warning message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public static function warning( $message, $context = array() ) {
        self::log( $message, self::LEVEL_WARNING, $context );
    }

    /**
     * Log error message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public static function error( $message, $context = array() ) {
        self::log( $message, self::LEVEL_ERROR, $context );
    }

    /**
     * Log critical message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public static function critical( $message, $context = array() ) {
        self::log( $message, self::LEVEL_CRITICAL, $context );
    }

    /**
     * Log an exception.
     *
     * @param Exception $exception Exception to log.
     * @param string    $context   Additional context message.
     */
    public static function exception( $exception, $context = '' ) {
        $message = sprintf(
            '%s: %s in %s on line %d',
            get_class( $exception ),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );

        if ( $context ) {
            $message = $context . ' - ' . $message;
        }

        self::error( $message, array(
            'trace' => $exception->getTraceAsString(),
        ) );
    }

    /**
     * Check if debug mode is enabled.
     *
     * @return bool True if debug enabled.
     */
    public static function is_debug_enabled() {
        if ( defined( 'AFFILYNC_DEBUG' ) && AFFILYNC_DEBUG ) {
            return true;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            return true;
        }

        return false;
    }

    /**
     * Get log file path.
     *
     * @return string|null Log file path or null.
     */
    public static function get_log_file() {
        if ( ! function_exists( 'wc_get_log_file_path' ) ) {
            return null;
        }

        return wc_get_log_file_path( self::SOURCE );
    }

    /**
     * Clear log file.
     *
     * @return bool True on success.
     */
    public static function clear_log() {
        $log_file = self::get_log_file();

        if ( $log_file && file_exists( $log_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            return unlink( $log_file );
        }

        return false;
    }

    /**
     * Log API request.
     *
     * @param string $method   HTTP method.
     * @param string $endpoint API endpoint.
     * @param array  $data     Request data.
     */
    public static function log_api_request( $method, $endpoint, $data = array() ) {
        if ( ! self::is_debug_enabled() ) {
            return;
        }

        self::debug( sprintf( 'API Request: %s %s', $method, $endpoint ), array(
            'data' => self::sanitize_log_data( $data ),
        ) );
    }

    /**
     * Log API response.
     *
     * @param string $method      HTTP method.
     * @param string $endpoint    API endpoint.
     * @param int    $status_code Response status code.
     * @param mixed  $response    Response data.
     */
    public static function log_api_response( $method, $endpoint, $status_code, $response ) {
        if ( ! self::is_debug_enabled() ) {
            return;
        }

        self::debug( sprintf( 'API Response: %s %s [%d]', $method, $endpoint, $status_code ), array(
            'response' => self::sanitize_log_data( $response ),
        ) );
    }

    /**
     * Sanitize data for logging (remove sensitive info).
     *
     * @param mixed $data Data to sanitize.
     * @return mixed Sanitized data.
     */
    private static function sanitize_log_data( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }

        $sensitive_keys = array(
            'access_token',
            'refresh_token',
            'api_key',
            'secret',
            'password',
            'authorization',
            'cookie',
        );

        foreach ( $data as $key => $value ) {
            $key_lower = strtolower( $key );

            foreach ( $sensitive_keys as $sensitive ) {
                if ( strpos( $key_lower, $sensitive ) !== false ) {
                    $data[ $key ] = '***REDACTED***';
                    break;
                }
            }

            if ( is_array( $value ) ) {
                $data[ $key ] = self::sanitize_log_data( $value );
            }
        }

        return $data;
    }
}
