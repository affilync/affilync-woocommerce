<?php
/**
 * Subscription Billing Manager for Affilync WooCommerce Plugin.
 *
 * Handles subscription management, plan upgrades/downgrades,
 * usage tracking, and integration with Stripe for payments.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Subscription Billing Manager.
 *
 * @since 1.0.0
 */
class Affilync_Billing_Subscription {

    /**
     * API client.
     *
     * @var Affilync_API_Client
     */
    private $api_client;

    /**
     * Encryption handler.
     *
     * @var Affilync_Security_Encryption
     */
    private $encryption;

    /**
     * Available subscription plans.
     *
     * @var array
     */
    const PLANS = array(
        'free' => array(
            'name'             => 'Free',
            'price'            => 0,
            'price_display'    => 'Free',
            'trial_days'       => 0,
            'conversion_limit' => 50,
            'product_limit'    => 100,
            'features'         => array(
                'Up to 50 conversions/month',
                'Basic conversion tracking',
                'Manual product sync',
                'Email support',
            ),
        ),
        'starter' => array(
            'name'             => 'Starter',
            'price'            => 29,
            'price_display'    => '$29/month',
            'trial_days'       => 14,
            'conversion_limit' => 500,
            'product_limit'    => 1000,
            'features'         => array(
                'Up to 500 conversions/month',
                'Advanced attribution',
                'Automatic product sync',
                'Webhook notifications',
                'Priority support',
            ),
        ),
        'pro' => array(
            'name'             => 'Pro',
            'price'            => 99,
            'price_display'    => '$99/month',
            'trial_days'       => 14,
            'conversion_limit' => 5000,
            'product_limit'    => 10000,
            'features'         => array(
                'Up to 5,000 conversions/month',
                'Multi-touch attribution',
                'Real-time sync',
                'Custom webhooks',
                'API access',
                'Dedicated support',
            ),
        ),
        'enterprise' => array(
            'name'             => 'Enterprise',
            'price'            => 299,
            'price_display'    => '$299/month',
            'trial_days'       => 30,
            'conversion_limit' => -1, // Unlimited
            'product_limit'    => -1, // Unlimited
            'features'         => array(
                'Unlimited conversions',
                'Unlimited products',
                'Custom integration',
                'SLA guarantee',
                'Dedicated account manager',
                'Priority API access',
            ),
        ),
    );

    /**
     * Option name for storing subscription data.
     *
     * @var string
     */
    const OPTION_SUBSCRIPTION = 'affilync_subscription';

    /**
     * Option name for storing usage data.
     *
     * @var string
     */
    const OPTION_USAGE = 'affilync_usage';

    /**
     * Constructor.
     *
     * @param Affilync_API_Client          $api_client API client.
     * @param Affilync_Security_Encryption $encryption Encryption handler.
     */
    public function __construct( $api_client, $encryption ) {
        $this->api_client = $api_client;
        $this->encryption = $encryption;

        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // AJAX handlers for subscription management.
        add_action( 'wp_ajax_affilync_get_plans', array( $this, 'ajax_get_plans' ) );
        add_action( 'wp_ajax_affilync_get_subscription_status', array( $this, 'ajax_get_subscription_status' ) );
        add_action( 'wp_ajax_affilync_subscribe', array( $this, 'ajax_subscribe' ) );
        add_action( 'wp_ajax_affilync_cancel_subscription', array( $this, 'ajax_cancel_subscription' ) );
        add_action( 'wp_ajax_affilync_get_usage', array( $this, 'ajax_get_usage' ) );

        // Cron for usage reset.
        add_action( 'affilync_reset_monthly_usage', array( $this, 'reset_monthly_usage' ) );

        // PAY-IX-3: Cron for retrying failed usage sync.
        add_action( 'affilync_retry_usage_sync', array( $this, 'retry_usage_sync' ) );

        // Schedule monthly usage reset if not scheduled.
        if ( ! wp_next_scheduled( 'affilync_reset_monthly_usage' ) ) {
            wp_schedule_event( strtotime( 'first day of next month midnight' ), 'monthly', 'affilync_reset_monthly_usage' );
        }
    }

    /**
     * Get all available plans.
     *
     * @return array Plans data.
     */
    public function get_plans() {
        $plans = array();

        foreach ( self::PLANS as $plan_id => $plan_data ) {
            $plans[] = array_merge(
                array( 'id' => $plan_id ),
                $plan_data,
                array( 'popular' => $plan_id === 'pro' )
            );
        }

        return $plans;
    }

    /**
     * Get current subscription status.
     *
     * @return array Subscription status.
     */
    public function get_subscription_status() {
        $subscription = get_option( self::OPTION_SUBSCRIPTION, array() );

        // Default to free plan if no subscription.
        if ( empty( $subscription ) || empty( $subscription['plan'] ) ) {
            $subscription = array(
                'plan'               => 'free',
                'status'             => 'active',
                'current_period_end' => null,
                'trial_ends_at'      => null,
            );
        }

        $plan_id = $subscription['plan'];
        $plan    = isset( self::PLANS[ $plan_id ] ) ? self::PLANS[ $plan_id ] : self::PLANS['free'];

        return array(
            'plan'               => $plan_id,
            'plan_name'          => $plan['name'],
            'status'             => isset( $subscription['status'] ) ? $subscription['status'] : 'active',
            'features'           => $plan['features'],
            'conversion_limit'   => $plan['conversion_limit'],
            'product_limit'      => $plan['product_limit'],
            'current_period_end' => isset( $subscription['current_period_end'] ) ? $subscription['current_period_end'] : null,
            'trial_ends_at'      => isset( $subscription['trial_ends_at'] ) ? $subscription['trial_ends_at'] : null,
        );
    }

    /**
     * Subscribe to a plan.
     *
     * @param string $plan_id Plan ID.
     * @return array|WP_Error Result or error.
     */
    public function subscribe( $plan_id ) {
        // Validate plan.
        if ( ! isset( self::PLANS[ $plan_id ] ) ) {
            return new WP_Error(
                'invalid_plan',
                __( 'Invalid subscription plan.', 'affilync-woocommerce' )
            );
        }

        $plan = self::PLANS[ $plan_id ];

        // Free plan - activate immediately.
        if ( $plan_id === 'free' ) {
            $subscription = array(
                'plan'               => 'free',
                'status'             => 'active',
                'current_period_end' => null,
                'subscribed_at'      => current_time( 'mysql' ),
            );

            update_option( self::OPTION_SUBSCRIPTION, $subscription );

            return array(
                'status'  => 'active',
                'plan'    => 'free',
                'message' => __( 'Subscribed to free plan.', 'affilync-woocommerce' ),
            );
        }

        // Paid plan - call API to get checkout URL.
        $result = $this->api_client->post(
            '/api/woocommerce/billing/subscribe',
            array( 'plan' => $plan_id )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // If checkout URL returned, redirect merchant.
        if ( ! empty( $result['checkout_url'] ) ) {
            return array(
                'status'       => 'pending_payment',
                'checkout_url' => $result['checkout_url'],
                'trial_days'   => $plan['trial_days'],
                'message'      => __( 'Redirect to checkout to complete subscription.', 'affilync-woocommerce' ),
            );
        }

        // If already subscribed.
        if ( isset( $result['status'] ) && $result['status'] === 'active' ) {
            $subscription = array(
                'plan'               => $plan_id,
                'status'             => 'active',
                'current_period_end' => isset( $result['current_period_end'] ) ? $result['current_period_end'] : null,
                'subscribed_at'      => current_time( 'mysql' ),
            );

            update_option( self::OPTION_SUBSCRIPTION, $subscription );
        }

        return $result;
    }

    /**
     * Confirm subscription after payment.
     *
     * @param string $session_id Stripe session ID.
     * @return array|WP_Error Result or error.
     */
    public function confirm_subscription( $session_id ) {
        $result = $this->api_client->get(
            '/api/woocommerce/billing/confirm',
            array( 'session_id' => $session_id )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! empty( $result['plan'] ) && ! empty( $result['status'] ) ) {
            $subscription = array(
                'plan'               => $result['plan'],
                'status'             => $result['status'],
                'current_period_end' => isset( $result['current_period_end'] ) ? $result['current_period_end'] : null,
                'stripe_customer_id' => isset( $result['customer_id'] ) ? $result['customer_id'] : null,
                'confirmed_at'       => current_time( 'mysql' ),
            );

            update_option( self::OPTION_SUBSCRIPTION, $subscription );
        }

        return $result;
    }

    /**
     * Cancel subscription.
     *
     * @return array|WP_Error Result or error.
     */
    public function cancel_subscription() {
        $result = $this->api_client->post( '/api/woocommerce/billing/cancel' );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Update local subscription status.
        $subscription = get_option( self::OPTION_SUBSCRIPTION, array() );
        $subscription['status'] = 'cancelled';
        $subscription['cancelled_at'] = current_time( 'mysql' );

        update_option( self::OPTION_SUBSCRIPTION, $subscription );

        return array(
            'status'  => 'cancelled',
            'message' => __( 'Subscription cancelled. You will be downgraded to free plan at the end of the billing period.', 'affilync-woocommerce' ),
        );
    }

    /**
     * Get current usage statistics.
     *
     * @return array Usage data.
     */
    public function get_usage() {
        $usage        = get_option( self::OPTION_USAGE, array() );
        $subscription = $this->get_subscription_status();

        $conversions_used = isset( $usage['conversions'] ) ? intval( $usage['conversions'] ) : 0;
        $products_used    = isset( $usage['products'] ) ? intval( $usage['products'] ) : 0;

        $conversion_limit = $subscription['conversion_limit'];
        $product_limit    = $subscription['product_limit'];

        // Calculate remaining (-1 means unlimited).
        $conversions_remaining = $conversion_limit === -1 ? -1 : max( 0, $conversion_limit - $conversions_used );
        $products_remaining    = $product_limit === -1 ? -1 : max( 0, $product_limit - $products_used );

        // Calculate percentage.
        $conversions_percentage = $conversion_limit <= 0 ? 0 : intval( ( $conversions_used / $conversion_limit ) * 100 );
        $products_percentage    = $product_limit <= 0 ? 0 : intval( ( $products_used / $product_limit ) * 100 );

        return array(
            'plan'        => $subscription['plan'],
            'period_start' => isset( $usage['period_start'] ) ? $usage['period_start'] : null,
            'period_end'   => isset( $usage['period_end'] ) ? $usage['period_end'] : null,
            'conversions' => array(
                'used'       => $conversions_used,
                'limit'      => $conversion_limit,
                'remaining'  => $conversions_remaining,
                'percentage' => $conversions_percentage,
            ),
            'products'    => array(
                'used'       => $products_used,
                'limit'      => $product_limit,
                'remaining'  => $products_remaining,
                'percentage' => $products_percentage,
            ),
        );
    }

    /**
     * Increment conversion count.
     *
     * @return bool True if within limits.
     */
    public function increment_conversion_count() {
        $usage = get_option( self::OPTION_USAGE, array() );

        $conversions = isset( $usage['conversions'] ) ? intval( $usage['conversions'] ) : 0;

        // Check limits BEFORE incrementing (PAY-XI-10).
        $subscription = $this->get_subscription_status();
        $limit        = $subscription['conversion_limit'];

        if ( $limit !== -1 && $conversions >= $limit ) {
            return false;
        }

        $conversions++;
        $usage['conversions'] = $conversions;

        // Set period if not set.
        if ( ! isset( $usage['period_start'] ) ) {
            $usage['period_start'] = gmdate( 'Y-m-01' );
            $usage['period_end']   = gmdate( 'Y-m-t' );
        }

        update_option( self::OPTION_USAGE, $usage );

        return true;
    }

    /**
     * Increment product count.
     *
     * @return bool True if within limits.
     */
    public function increment_product_count() {
        $usage = get_option( self::OPTION_USAGE, array() );

        $products = isset( $usage['products'] ) ? intval( $usage['products'] ) : 0;

        // Check limits BEFORE incrementing (PAY-XI-10).
        $subscription = $this->get_subscription_status();
        $limit        = $subscription['product_limit'];

        if ( $limit !== -1 && $products >= $limit ) {
            return false;
        }

        $products++;
        $usage['products'] = $products;

        update_option( self::OPTION_USAGE, $usage );

        return true;
    }

    /**
     * Check if within conversion limits.
     *
     * @return bool True if within limits.
     */
    public function is_within_conversion_limits() {
        $usage        = $this->get_usage();
        $conversions  = $usage['conversions'];

        return $conversions['limit'] === -1 || $conversions['used'] < $conversions['limit'];
    }

    /**
     * Check if within product limits.
     *
     * @return bool True if within limits.
     */
    public function is_within_product_limits() {
        $usage    = $this->get_usage();
        $products = $usage['products'];

        return $products['limit'] === -1 || $products['used'] < $products['limit'];
    }

    /**
     * Reset monthly usage (cron job).
     *
     * PAY-IX-3: After resetting local counters, syncs the reset with the
     * Affilync API to prevent usage tracking drift. If the API call fails,
     * schedules a retry via wp_cron.
     */
    public function reset_monthly_usage() {
        $previous_usage = get_option( self::OPTION_USAGE, array() );

        $usage = array(
            'conversions'  => 0,
            'products'     => 0,
            'period_start' => gmdate( 'Y-m-01' ),
            'period_end'   => gmdate( 'Y-m-t' ),
            'reset_at'     => current_time( 'mysql' ),
        );

        update_option( self::OPTION_USAGE, $usage );

        // Log the reset.
        if ( function_exists( 'affilync' ) && affilync()->audit_logger ) {
            affilync()->audit_logger->info(
                'usage_reset',
                array( 'message' => 'Monthly usage counters reset' )
            );
        }

        // PAY-IX-3: Sync usage reset with Affilync API.
        $sync_result = $this->sync_usage_reset( $previous_usage );

        if ( is_wp_error( $sync_result ) ) {
            // Log the failure and schedule a retry.
            if ( function_exists( 'affilync' ) && affilync()->audit_logger ) {
                affilync()->audit_logger->warning(
                    'usage_reset_sync_failed',
                    array(
                        'message' => 'Failed to sync usage reset with API, scheduling retry',
                        'error'   => $sync_result->get_error_message(),
                    )
                );
            }

            // Store the previous usage data for the retry attempt.
            update_option( 'affilync_pending_usage_sync', $previous_usage, false );

            // Schedule retry in 5 minutes if not already scheduled.
            if ( ! wp_next_scheduled( 'affilync_retry_usage_sync' ) ) {
                wp_schedule_single_event( time() + 300, 'affilync_retry_usage_sync' );
            }
        }
    }

    /**
     * Sync usage reset with the Affilync API.
     *
     * PAY-IX-3: Calls the Affilync API to notify that the local billing
     * usage counters have been reset. This keeps the platform's usage
     * records in sync with the WooCommerce plugin's local state.
     *
     * @param array $previous_usage The usage data before the reset.
     * @return array|WP_Error API response or error.
     */
    public function sync_usage_reset( $previous_usage = array() ) {
        $subscription = $this->get_subscription_status();
        $site_url     = get_site_url();

        $payload = array(
            'site_url'       => $site_url,
            'plan'           => $subscription['plan'],
            'previous_usage' => array(
                'conversions' => isset( $previous_usage['conversions'] ) ? intval( $previous_usage['conversions'] ) : 0,
                'products'    => isset( $previous_usage['products'] ) ? intval( $previous_usage['products'] ) : 0,
                'period_start' => isset( $previous_usage['period_start'] ) ? $previous_usage['period_start'] : null,
                'period_end'   => isset( $previous_usage['period_end'] ) ? $previous_usage['period_end'] : null,
            ),
            'new_period'     => array(
                'period_start' => gmdate( 'Y-m-01' ),
                'period_end'   => gmdate( 'Y-m-t' ),
            ),
            'reset_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );

        $result = $this->api_client->post( '/api/woocommerce/billing/usage-reset', $payload );

        if ( ! is_wp_error( $result ) ) {
            // Sync successful -- clear any pending sync data.
            delete_option( 'affilync_pending_usage_sync' );

            if ( function_exists( 'affilync' ) && affilync()->audit_logger ) {
                affilync()->audit_logger->info(
                    'usage_reset_synced',
                    array( 'message' => 'Usage reset successfully synced with Affilync API' )
                );
            }
        }

        return $result;
    }

    /**
     * Retry syncing usage reset with the API (wp_cron callback).
     *
     * PAY-IX-3: Called by wp_cron when the initial sync attempt failed.
     * Retries up to 3 times with increasing delays (5 min, 15 min, 60 min).
     */
    public function retry_usage_sync() {
        $previous_usage = get_option( 'affilync_pending_usage_sync', array() );

        // Nothing to sync.
        if ( empty( $previous_usage ) ) {
            return;
        }

        $retry_count = isset( $previous_usage['_retry_count'] ) ? intval( $previous_usage['_retry_count'] ) : 0;

        $result = $this->sync_usage_reset( $previous_usage );

        if ( is_wp_error( $result ) ) {
            $retry_count++;

            if ( $retry_count >= 3 ) {
                // Max retries reached -- log and give up.
                if ( function_exists( 'affilync' ) && affilync()->audit_logger ) {
                    affilync()->audit_logger->error(
                        'usage_reset_sync_max_retries',
                        array(
                            'message'     => 'Usage reset sync failed after 3 retries',
                            'error'       => $result->get_error_message(),
                            'retry_count' => $retry_count,
                        )
                    );
                }
                delete_option( 'affilync_pending_usage_sync' );
                return;
            }

            // Update retry count and schedule next attempt.
            $previous_usage['_retry_count'] = $retry_count;
            update_option( 'affilync_pending_usage_sync', $previous_usage, false );

            // Increasing delay: 15 min for 2nd attempt, 60 min for 3rd.
            $delays = array( 900, 3600 );
            $delay  = isset( $delays[ $retry_count - 1 ] ) ? $delays[ $retry_count - 1 ] : 3600;

            if ( ! wp_next_scheduled( 'affilync_retry_usage_sync' ) ) {
                wp_schedule_single_event( time() + $delay, 'affilync_retry_usage_sync' );
            }
        }
        // If sync succeeded, sync_usage_reset() already cleaned up the option.
    }

    /**
     * AJAX handler: Get plans.
     */
    public function ajax_get_plans() {
        check_ajax_referer( 'affilync_billing_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'affilync-woocommerce' ) ), 403 );
        }

        wp_send_json_success( array( 'plans' => $this->get_plans() ) );
    }

    /**
     * AJAX handler: Get subscription status.
     */
    public function ajax_get_subscription_status() {
        check_ajax_referer( 'affilync_billing_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'affilync-woocommerce' ) ), 403 );
        }

        wp_send_json_success( $this->get_subscription_status() );
    }

    /**
     * AJAX handler: Subscribe.
     */
    public function ajax_subscribe() {
        check_ajax_referer( 'affilync_billing_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'affilync-woocommerce' ) ), 403 );
        }

        $plan_id = isset( $_POST['plan'] ) ? sanitize_text_field( wp_unslash( $_POST['plan'] ) ) : '';

        if ( empty( $plan_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Plan is required', 'affilync-woocommerce' ) ), 400 );
        }

        $result = $this->subscribe( $plan_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler: Cancel subscription.
     */
    public function ajax_cancel_subscription() {
        check_ajax_referer( 'affilync_billing_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'affilync-woocommerce' ) ), 403 );
        }

        $result = $this->cancel_subscription();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler: Get usage.
     */
    public function ajax_get_usage() {
        check_ajax_referer( 'affilync_billing_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'affilync-woocommerce' ) ), 403 );
        }

        wp_send_json_success( $this->get_usage() );
    }
}
