<?php
/**
 * Unit tests for Affilync_Billing_Subscription class.
 *
 * @package Affilync_WooCommerce
 */

/**
 * Test class for Affilync_Billing_Subscription.
 */
class Test_Billing_Subscription extends WP_UnitTestCase {

    /**
     * Billing subscription instance.
     *
     * @var Affilync_Billing_Subscription
     */
    private $subscription;

    /**
     * Mock API client.
     *
     * @var Affilync_API_Client
     */
    private $mock_api_client;

    /**
     * Mock encryption handler.
     *
     * @var Affilync_Security_Encryption
     */
    private $mock_encryption;

    /**
     * Set up test fixtures.
     */
    public function set_up() {
        parent::set_up();

        // Create mock API client.
        $this->mock_api_client = $this->createMock( Affilync_API_Client::class );

        // Create mock encryption handler.
        $this->mock_encryption = $this->createMock( Affilync_Security_Encryption::class );

        // Create subscription instance.
        $this->subscription = new Affilync_Billing_Subscription(
            $this->mock_api_client,
            $this->mock_encryption
        );

        // Clear options before each test.
        delete_option( 'affilync_subscription' );
        delete_option( 'affilync_usage' );
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down() {
        parent::tear_down();

        delete_option( 'affilync_subscription' );
        delete_option( 'affilync_usage' );
    }

    /**
     * Test get_plans returns all available plans.
     */
    public function test_get_plans_returns_all_plans() {
        $plans = $this->subscription->get_plans();

        $this->assertIsArray( $plans );
        $this->assertCount( 4, $plans );

        // Check plan IDs.
        $plan_ids = array_column( $plans, 'id' );
        $this->assertContains( 'free', $plan_ids );
        $this->assertContains( 'starter', $plan_ids );
        $this->assertContains( 'pro', $plan_ids );
        $this->assertContains( 'enterprise', $plan_ids );
    }

    /**
     * Test get_plans contains required fields.
     */
    public function test_get_plans_contains_required_fields() {
        $plans = $this->subscription->get_plans();

        foreach ( $plans as $plan ) {
            $this->assertArrayHasKey( 'id', $plan );
            $this->assertArrayHasKey( 'name', $plan );
            $this->assertArrayHasKey( 'price', $plan );
            $this->assertArrayHasKey( 'price_display', $plan );
            $this->assertArrayHasKey( 'trial_days', $plan );
            $this->assertArrayHasKey( 'conversion_limit', $plan );
            $this->assertArrayHasKey( 'product_limit', $plan );
            $this->assertArrayHasKey( 'features', $plan );
            $this->assertArrayHasKey( 'popular', $plan );
        }
    }

    /**
     * Test pro plan is marked as popular.
     */
    public function test_pro_plan_is_marked_popular() {
        $plans = $this->subscription->get_plans();

        $pro_plan = null;
        foreach ( $plans as $plan ) {
            if ( $plan['id'] === 'pro' ) {
                $pro_plan = $plan;
                break;
            }
        }

        $this->assertNotNull( $pro_plan );
        $this->assertTrue( $pro_plan['popular'] );
    }

    /**
     * Test get_subscription_status returns free plan by default.
     */
    public function test_get_subscription_status_defaults_to_free() {
        $status = $this->subscription->get_subscription_status();

        $this->assertIsArray( $status );
        $this->assertEquals( 'free', $status['plan'] );
        $this->assertEquals( 'active', $status['status'] );
        $this->assertEquals( 'Free', $status['plan_name'] );
        $this->assertEquals( 50, $status['conversion_limit'] );
        $this->assertEquals( 100, $status['product_limit'] );
    }

    /**
     * Test get_subscription_status returns stored subscription.
     */
    public function test_get_subscription_status_returns_stored_subscription() {
        update_option( 'affilync_subscription', array(
            'plan'   => 'pro',
            'status' => 'active',
        ) );

        $status = $this->subscription->get_subscription_status();

        $this->assertEquals( 'pro', $status['plan'] );
        $this->assertEquals( 'Pro', $status['plan_name'] );
        $this->assertEquals( 5000, $status['conversion_limit'] );
        $this->assertEquals( 10000, $status['product_limit'] );
    }

    /**
     * Test subscribe to free plan activates immediately.
     */
    public function test_subscribe_to_free_plan_activates_immediately() {
        $result = $this->subscription->subscribe( 'free' );

        $this->assertIsArray( $result );
        $this->assertEquals( 'active', $result['status'] );
        $this->assertEquals( 'free', $result['plan'] );

        // Check option was updated.
        $subscription = get_option( 'affilync_subscription' );
        $this->assertEquals( 'free', $subscription['plan'] );
        $this->assertEquals( 'active', $subscription['status'] );
    }

    /**
     * Test subscribe to invalid plan returns error.
     */
    public function test_subscribe_to_invalid_plan_returns_error() {
        $result = $this->subscription->subscribe( 'invalid_plan' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_plan', $result->get_error_code() );
    }

    /**
     * Test subscribe to paid plan calls API.
     */
    public function test_subscribe_to_paid_plan_calls_api() {
        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->with(
                '/api/woocommerce/billing/subscribe',
                array( 'plan' => 'pro' )
            )
            ->willReturn( array(
                'status'       => 'pending_payment',
                'checkout_url' => 'https://checkout.stripe.com/test',
            ) );

        $result = $this->subscription->subscribe( 'pro' );

        $this->assertIsArray( $result );
        $this->assertEquals( 'pending_payment', $result['status'] );
        $this->assertArrayHasKey( 'checkout_url', $result );
    }

    /**
     * Test get_usage returns default values when empty.
     */
    public function test_get_usage_returns_defaults_when_empty() {
        $usage = $this->subscription->get_usage();

        $this->assertIsArray( $usage );
        $this->assertEquals( 'free', $usage['plan'] );
        $this->assertEquals( 0, $usage['conversions']['used'] );
        $this->assertEquals( 50, $usage['conversions']['limit'] );
        $this->assertEquals( 50, $usage['conversions']['remaining'] );
        $this->assertEquals( 0, $usage['conversions']['percentage'] );
        $this->assertEquals( 0, $usage['products']['used'] );
        $this->assertEquals( 100, $usage['products']['limit'] );
    }

    /**
     * Test increment_conversion_count increments correctly.
     */
    public function test_increment_conversion_count_increments() {
        $this->subscription->increment_conversion_count();
        $this->subscription->increment_conversion_count();
        $this->subscription->increment_conversion_count();

        $usage = $this->subscription->get_usage();

        $this->assertEquals( 3, $usage['conversions']['used'] );
        $this->assertEquals( 47, $usage['conversions']['remaining'] );
        $this->assertEquals( 6, $usage['conversions']['percentage'] );
    }

    /**
     * Test increment_conversion_count returns true when within limits.
     */
    public function test_increment_conversion_count_returns_true_within_limits() {
        $result = $this->subscription->increment_conversion_count();
        $this->assertTrue( $result );
    }

    /**
     * Test increment_conversion_count returns false when over limit.
     */
    public function test_increment_conversion_count_returns_false_over_limit() {
        // Set usage to limit.
        update_option( 'affilync_usage', array(
            'conversions' => 50,
            'products'    => 0,
        ) );

        $result = $this->subscription->increment_conversion_count();
        $this->assertFalse( $result );
    }

    /**
     * Test increment_product_count increments correctly.
     */
    public function test_increment_product_count_increments() {
        $this->subscription->increment_product_count();
        $this->subscription->increment_product_count();

        $usage = $this->subscription->get_usage();

        $this->assertEquals( 2, $usage['products']['used'] );
        $this->assertEquals( 98, $usage['products']['remaining'] );
    }

    /**
     * Test is_within_conversion_limits returns true when under limit.
     */
    public function test_is_within_conversion_limits_returns_true() {
        update_option( 'affilync_usage', array(
            'conversions' => 25,
            'products'    => 0,
        ) );

        $this->assertTrue( $this->subscription->is_within_conversion_limits() );
    }

    /**
     * Test is_within_conversion_limits returns false when at limit.
     */
    public function test_is_within_conversion_limits_returns_false_at_limit() {
        update_option( 'affilync_usage', array(
            'conversions' => 50,
            'products'    => 0,
        ) );

        $this->assertFalse( $this->subscription->is_within_conversion_limits() );
    }

    /**
     * Test enterprise plan has unlimited conversions.
     */
    public function test_enterprise_plan_has_unlimited_conversions() {
        update_option( 'affilync_subscription', array(
            'plan'   => 'enterprise',
            'status' => 'active',
        ) );

        $status = $this->subscription->get_subscription_status();

        $this->assertEquals( -1, $status['conversion_limit'] );
        $this->assertEquals( -1, $status['product_limit'] );
    }

    /**
     * Test unlimited plan always returns within limits.
     */
    public function test_unlimited_plan_always_within_limits() {
        update_option( 'affilync_subscription', array(
            'plan'   => 'enterprise',
            'status' => 'active',
        ) );

        update_option( 'affilync_usage', array(
            'conversions' => 999999,
            'products'    => 999999,
        ) );

        $this->assertTrue( $this->subscription->is_within_conversion_limits() );
        $this->assertTrue( $this->subscription->is_within_product_limits() );
    }

    /**
     * Test reset_monthly_usage resets counters.
     */
    public function test_reset_monthly_usage_resets_counters() {
        update_option( 'affilync_usage', array(
            'conversions' => 45,
            'products'    => 80,
        ) );

        $this->subscription->reset_monthly_usage();

        $usage = get_option( 'affilync_usage' );

        $this->assertEquals( 0, $usage['conversions'] );
        $this->assertEquals( 0, $usage['products'] );
        $this->assertArrayHasKey( 'period_start', $usage );
        $this->assertArrayHasKey( 'period_end', $usage );
        $this->assertArrayHasKey( 'reset_at', $usage );
    }

    /**
     * Test cancel_subscription calls API.
     */
    public function test_cancel_subscription_calls_api() {
        $this->mock_api_client
            ->expects( $this->once() )
            ->method( 'post' )
            ->with( '/api/woocommerce/billing/cancel' )
            ->willReturn( array(
                'status' => 'cancelled',
            ) );

        $result = $this->subscription->cancel_subscription();

        $this->assertIsArray( $result );
        $this->assertEquals( 'cancelled', $result['status'] );

        // Check local status updated.
        $subscription = get_option( 'affilync_subscription' );
        $this->assertEquals( 'cancelled', $subscription['status'] );
    }

    /**
     * Test plan prices are correct.
     */
    public function test_plan_prices_are_correct() {
        $plans = $this->subscription->get_plans();

        $prices = array();
        foreach ( $plans as $plan ) {
            $prices[ $plan['id'] ] = $plan['price'];
        }

        $this->assertEquals( 0, $prices['free'] );
        $this->assertEquals( 29, $prices['starter'] );
        $this->assertEquals( 99, $prices['pro'] );
        $this->assertEquals( 299, $prices['enterprise'] );
    }

    /**
     * Test plan limits are correct.
     */
    public function test_plan_limits_are_correct() {
        $plans = $this->subscription->get_plans();

        $limits = array();
        foreach ( $plans as $plan ) {
            $limits[ $plan['id'] ] = array(
                'conversions' => $plan['conversion_limit'],
                'products'    => $plan['product_limit'],
            );
        }

        $this->assertEquals( 50, $limits['free']['conversions'] );
        $this->assertEquals( 100, $limits['free']['products'] );
        $this->assertEquals( 500, $limits['starter']['conversions'] );
        $this->assertEquals( 1000, $limits['starter']['products'] );
        $this->assertEquals( 5000, $limits['pro']['conversions'] );
        $this->assertEquals( 10000, $limits['pro']['products'] );
        $this->assertEquals( -1, $limits['enterprise']['conversions'] );
        $this->assertEquals( -1, $limits['enterprise']['products'] );
    }

    /**
     * Test trial days are correct for paid plans.
     */
    public function test_trial_days_are_correct() {
        $plans = $this->subscription->get_plans();

        $trials = array();
        foreach ( $plans as $plan ) {
            $trials[ $plan['id'] ] = $plan['trial_days'];
        }

        $this->assertEquals( 0, $trials['free'] );
        $this->assertEquals( 14, $trials['starter'] );
        $this->assertEquals( 14, $trials['pro'] );
        $this->assertEquals( 30, $trials['enterprise'] );
    }

    /**
     * Test usage percentage calculation.
     */
    public function test_usage_percentage_calculation() {
        update_option( 'affilync_usage', array(
            'conversions' => 25,
            'products'    => 50,
        ) );

        $usage = $this->subscription->get_usage();

        // 25/50 = 50%
        $this->assertEquals( 50, $usage['conversions']['percentage'] );
        // 50/100 = 50%
        $this->assertEquals( 50, $usage['products']['percentage'] );
    }

    /**
     * Test usage percentage is 0 for unlimited plans.
     */
    public function test_usage_percentage_zero_for_unlimited() {
        update_option( 'affilync_subscription', array(
            'plan'   => 'enterprise',
            'status' => 'active',
        ) );

        update_option( 'affilync_usage', array(
            'conversions' => 10000,
            'products'    => 50000,
        ) );

        $usage = $this->subscription->get_usage();

        $this->assertEquals( 0, $usage['conversions']['percentage'] );
        $this->assertEquals( 0, $usage['products']['percentage'] );
        $this->assertEquals( -1, $usage['conversions']['remaining'] );
        $this->assertEquals( -1, $usage['products']['remaining'] );
    }
}
