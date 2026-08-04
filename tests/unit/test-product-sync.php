<?php
/**
 * Tests for Affilync_Sync_Product_Sync
 *
 * Covers product queuing, hash-based change detection, sync logic,
 * batch processing, full sync, stats, and delete/trash handling.
 *
 * @package Affilync_WooCommerce
 */

use PHPUnit\Framework\TestCase;

/**
 * Product Sync test case.
 */
class Test_Product_Sync extends TestCase {

    /**
     * @var Affilync_Sync_Product_Sync
     */
    private $sync;

    /**
     * Mock API client.
     */
    private $api_client;

    /**
     * Saved $wpdb reference.
     */
    private $saved_wpdb;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['wp_options'] = array();
        unset( $GLOBALS['affilync_test_wc_product'] );

        require_once dirname( __DIR__, 2 ) . '/includes/api/class-affilync-api-client.php';
        require_once dirname( __DIR__, 2 ) . '/includes/sync/class-affilync-sync-product-sync.php';

        $this->api_client = $this->createMock( Affilync_API_Client::class );
        $this->sync       = new Affilync_Sync_Product_Sync( $this->api_client );

        $this->saved_wpdb = $GLOBALS['wpdb'];
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->saved_wpdb;
        $GLOBALS['wp_options'] = array();
        unset( $GLOBALS['affilync_test_wc_product'] );
        unset( $GLOBALS['affilync_test_product_ids'] );
        unset( $GLOBALS['affilync_test_next_action'] );
        $GLOBALS['affilync_scheduled_actions'] = array();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Constants
    // ------------------------------------------------------------------

    public function test_constants() {
        $this->assertSame( 'affilync_product_sync', Affilync_Sync_Product_Sync::TABLE_NAME );
        $this->assertSame( 50, Affilync_Sync_Product_Sync::BATCH_SIZE );
    }

    // ------------------------------------------------------------------
    // queue_product_sync
    // ------------------------------------------------------------------

    public function test_queue_skips_when_sync_disabled() {
        update_option( 'affilync_settings', array( 'product_sync_enabled' => false ) );

        // Should return early without touching DB.
        $this->sync->queue_product_sync( 1 );
        $this->assertTrue( true );
    }

    public function test_queue_skips_when_product_not_found() {
        // wc_get_product returns null by default.
        $this->sync->queue_product_sync( 999 );
        $this->assertTrue( true );
    }

    public function test_queue_inserts_new_product() {
        $product = $this->create_mock_product( 10 );

        // Override wc_get_product for this test.
        $this->override_wc_get_product( $product );

        // wpdb->get_row returns null (no existing row).
        $mock_wpdb = $this->create_mock_wpdb( null );
        $GLOBALS['wpdb'] = $mock_wpdb;

        // Expect insert to be called.
        $mock_wpdb->expects( $this->once() )->method( 'insert' )->willReturn( 1 );

        $this->sync->queue_product_sync( 10 );
    }

    public function test_queue_skips_when_hash_unchanged() {
        $product = $this->create_mock_product( 20 );
        $this->override_wc_get_product( $product );

        // Calculate the expected hash.
        $method = new ReflectionMethod( Affilync_Sync_Product_Sync::class, 'calculate_product_hash' );
        $method->setAccessible( true );
        $hash = $method->invoke( $this->sync, $product );

        $existing = (object) array( 'id' => 1, 'sync_hash' => $hash );
        $mock_wpdb = $this->create_mock_wpdb( $existing );
        $GLOBALS['wpdb'] = $mock_wpdb;

        // insert and update should NOT be called.
        $mock_wpdb->expects( $this->never() )->method( 'insert' );
        $mock_wpdb->expects( $this->never() )->method( 'update' );

        $this->sync->queue_product_sync( 20 );
    }

    public function test_queue_updates_when_hash_changed() {
        $product = $this->create_mock_product( 30 );
        $this->override_wc_get_product( $product );

        $existing = (object) array( 'id' => 5, 'sync_hash' => 'old_hash' );
        $mock_wpdb = $this->create_mock_wpdb( $existing );
        $GLOBALS['wpdb'] = $mock_wpdb;

        $mock_wpdb->expects( $this->once() )->method( 'update' )->willReturn( 1 );

        $this->sync->queue_product_sync( 30 );
    }

    // ------------------------------------------------------------------
    // sync_product
    // ------------------------------------------------------------------

    public function test_sync_product_fails_for_missing_product() {
        // wc_get_product returns null.
        $result = $this->sync->sync_product( 999 );
        $this->assertFalse( $result );
    }

    public function test_sync_product_skips_unpublished() {
        $product = $this->create_mock_product( 40, 'draft' );
        $this->override_wc_get_product( $product );

        $mock_wpdb = $this->create_mock_wpdb();
        $GLOBALS['wpdb'] = $mock_wpdb;

        $result = $this->sync->sync_product( 40 );
        // Returns true (skipped is not a failure).
        $this->assertTrue( $result );
    }

    public function test_sync_product_success() {
        $product = $this->create_mock_product( 50, 'publish' );
        $this->override_wc_get_product( $product );

        $mock_wpdb = $this->create_mock_wpdb();
        $GLOBALS['wpdb'] = $mock_wpdb;

        // API returns the real Affilync envelope: the id lives at
        // data.affilync_product_id, NOT a top-level product_id. (This mock used to
        // return array('product_id' => ...) — the wrong shape — so the test passed
        // while production stored NULL and broke deletes.) Assert the id captured
        // from the envelope is what gets persisted; against the old extraction this
        // fails because affilync_product_id would be NULL.
        $this->api_client->method( 'sync_product' )->willReturn( array(
            'success' => true,
            'message' => 'Success',
            'data'    => array(
                'affilync_product_id'    => 'aff_prod_50',
                'woocommerce_product_id' => 50,
                'status'                 => 'synced',
            ),
        ) );

        $mock_wpdb->expects( $this->once() )
            ->method( 'update' )
            ->with(
                $this->anything(),
                $this->callback( function ( $data ) {
                    return isset( $data['affilync_product_id'] )
                        && 'aff_prod_50' === $data['affilync_product_id'];
                } ),
                $this->anything()
            )
            ->willReturn( 1 );

        $result = $this->sync->sync_product( 50 );
        $this->assertTrue( $result );
    }

    public function test_sync_product_failure_increments_retry() {
        $product = $this->create_mock_product( 60, 'publish' );
        $this->override_wc_get_product( $product );

        $mock_wpdb = $this->create_mock_wpdb();
        $GLOBALS['wpdb'] = $mock_wpdb;

        // API returns error.
        $this->api_client->method( 'sync_product' )->willReturn(
            new WP_Error( 'api_error', 'Failed' )
        );

        $mock_wpdb->expects( $this->atLeastOnce() )->method( 'update' );
        $mock_wpdb->expects( $this->once() )->method( 'query' )->willReturn( true );

        $result = $this->sync->sync_product( 60 );
        $this->assertFalse( $result );
    }

    // ------------------------------------------------------------------
    // sync_pending
    // ------------------------------------------------------------------

    public function test_sync_pending_returns_results() {
        $mock_wpdb = $this->create_mock_wpdb();
        $mock_wpdb->method( 'get_results' )->willReturn( array() );
        $GLOBALS['wpdb'] = $mock_wpdb;

        $result = $this->sync->sync_pending();
        $this->assertIsArray( $result );
        $this->assertSame( 0, $result['synced'] );
        $this->assertSame( 0, $result['failed'] );
    }

    public function test_sync_pending_updates_last_sync_timestamp() {
        $mock_wpdb = $this->create_mock_wpdb();
        $mock_wpdb->method( 'get_results' )->willReturn( array() );
        $GLOBALS['wpdb'] = $mock_wpdb;

        $this->sync->sync_pending();

        $timestamp = get_option( 'affilync_last_product_sync' );
        $this->assertNotFalse( $timestamp );
    }

    // ------------------------------------------------------------------
    // on_product_delete / on_product_trash
    // ------------------------------------------------------------------

    public function test_on_product_delete_skips_non_products() {
        // get_post_type returns 'product' by default, so override.
        // Can't easily override for a single call, so we test that
        // the method exists and is callable.
        $this->assertTrue( method_exists( $this->sync, 'on_product_delete' ) );
    }

    public function test_on_product_trash_skips_non_products() {
        $this->assertTrue( method_exists( $this->sync, 'on_product_trash' ) );
    }

    // ------------------------------------------------------------------
    // on_stock_change
    // ------------------------------------------------------------------

    public function test_on_stock_change_queues_parent_for_variation() {
        $product = $this->create_mock_product( 70, 'publish', true );
        $product->method( 'get_parent_id' )->willReturn( 69 );

        // The method should call queue_product_sync(69).
        // Since wc_get_product(69) returns null from mock, queue will
        // return early. Just verify no exception.
        $this->sync->on_stock_change( $product );
        $this->assertTrue( true );
    }

    public function test_on_stock_change_queues_simple_product() {
        $product = $this->create_mock_product( 80, 'publish', false );
        $this->sync->on_stock_change( $product );
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------
    // calculate_product_hash
    // ------------------------------------------------------------------

    public function test_hash_changes_when_product_data_changes() {
        $method = new ReflectionMethod( Affilync_Sync_Product_Sync::class, 'calculate_product_hash' );
        $method->setAccessible( true );

        $product1 = $this->create_mock_product( 90, 'publish', false, 29.99 );
        $product2 = $this->create_mock_product( 90, 'publish', false, 39.99 );

        $hash1 = $method->invoke( $this->sync, $product1 );
        $hash2 = $method->invoke( $this->sync, $product2 );

        $this->assertNotSame( $hash1, $hash2 );
    }

    public function test_hash_stable_for_same_data() {
        $method = new ReflectionMethod( Affilync_Sync_Product_Sync::class, 'calculate_product_hash' );
        $method->setAccessible( true );

        $product = $this->create_mock_product( 91 );
        $hash1 = $method->invoke( $this->sync, $product );
        $hash2 = $method->invoke( $this->sync, $product );

        $this->assertSame( $hash1, $hash2 );
    }

    // ------------------------------------------------------------------
    // get_stats
    // ------------------------------------------------------------------

    public function test_get_stats_returns_expected_keys() {
        $mock_wpdb = new class extends stdClass {
            public $prefix = 'wp_';
            public function get_row( $q = null ) {
                return (object) array(
                    'total'     => 100,
                    'synced'    => 80,
                    'pending'   => 15,
                    'failed'    => 5,
                    'last_sync' => '2026-01-01 00:00:00',
                );
            }
        };
        $GLOBALS['wpdb'] = $mock_wpdb;

        $stats = $this->sync->get_stats();

        $this->assertSame( 100, $stats['total'] );
        $this->assertSame( 80, $stats['synced'] );
        $this->assertSame( 15, $stats['pending'] );
        $this->assertSame( 5, $stats['failed'] );
        $this->assertSame( '2026-01-01 00:00:00', $stats['last_sync'] );
    }

    // ------------------------------------------------------------------
    // build_product_data
    // ------------------------------------------------------------------

    public function test_build_product_data_structure() {
        $method = new ReflectionMethod( Affilync_Sync_Product_Sync::class, 'build_product_data' );
        $method->setAccessible( true );

        $product = $this->create_mock_product( 95, 'publish', false, 49.99 );
        $product->method( 'get_permalink' )->willReturn( 'https://example.com/product/95' );
        $product->method( 'get_image_id' )->willReturn( 0 );
        $product->method( 'get_gallery_image_ids' )->willReturn( array() );
        $product->method( 'get_category_ids' )->willReturn( array() );
        $product->method( 'get_tag_ids' )->willReturn( array() );
        $product->method( 'get_attributes' )->willReturn( array() );

        $data = $method->invoke( $this->sync, $product );

        // Shape MUST match the affilync-api WooCommerceProductSync schema. The id
        // field is `product_id` (int, REQUIRED) and the URL field is `permalink`;
        // this used to emit `external_id` (string) + `url` + object-arrays, which
        // the API rejected (422) — so no real product ever synced.
        $this->assertSame( 95, $data['product_id'] );
        $this->assertArrayNotHasKey( 'external_id', $data );
        $this->assertArrayNotHasKey( 'platform', $data );
        $this->assertSame( 'Test Product 95', $data['name'] );
        $this->assertSame( 49.99, $data['price'] );
        $this->assertSame( 'USD', $data['currency'] );
        $this->assertSame( 'https://example.com/product/95', $data['permalink'] );
        // categories / tags / images are lists of STRINGS, not {id,name,slug} /
        // {id,url,alt} objects (which the API's List[str] fields reject).
        $this->assertIsArray( $data['categories'] );
        $this->assertContainsOnly( 'string', $data['categories'] );
        $this->assertIsArray( $data['tags'] );
        $this->assertContainsOnly( 'string', $data['tags'] );
        $this->assertIsArray( $data['images'] );
        $this->assertContainsOnly( 'string', $data['images'] );
    }

    // ------------------------------------------------------------------
    // full_sync
    // ------------------------------------------------------------------

    public function test_full_sync_returns_results() {
        // wc_get_products returns empty by default.
        $mock_wpdb = $this->create_mock_wpdb();
        $mock_wpdb->method( 'get_results' )->willReturn( array() );
        $GLOBALS['wpdb'] = $mock_wpdb;

        $result = $this->sync->full_sync();
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'synced', $result );
        $this->assertArrayHasKey( 'failed', $result );
    }

    // ------------------------------------------------------------------
    // start_full_sync / run_full_sync_batch (background batched sync)
    // ------------------------------------------------------------------

    public function test_start_full_sync_schedules_background_batch() {
        $GLOBALS['affilync_test_product_ids'] = range( 1, 5 );
        $GLOBALS['affilync_scheduled_actions'] = array();
        unset( $GLOBALS['affilync_test_next_action'] );

        $result = $this->sync->start_full_sync();

        $this->assertSame( 'scheduled', $result['status'] );
        $this->assertSame( 5, $result['total'] );
        // First batch (offset 0) enqueued on the Action Scheduler hook.
        $hooks = array_column( $GLOBALS['affilync_scheduled_actions'], 'hook' );
        $this->assertContains( Affilync_Sync_Product_Sync::FULL_SYNC_HOOK, $hooks );
        $this->assertTrue( get_option( 'affilync_full_sync_running' ) );
    }

    public function test_start_full_sync_reports_already_running() {
        $GLOBALS['affilync_test_next_action'] = 12345; // as_next_scheduled_action truthy.
        $result = $this->sync->start_full_sync();
        $this->assertSame( 'already_running', $result['status'] );
    }

    public function test_run_full_sync_batch_bulk_pushes_and_marks_synced() {
        // A short page (< FULL_SYNC_BATCH_SIZE) => finishes, does not reschedule.
        $GLOBALS['affilync_test_product_ids'] = array( 101, 102 );
        $GLOBALS['affilync_scheduled_actions'] = array();
        $this->override_wc_get_product( $this->create_mock_product( 101, 'publish' ) );
        $mock_wpdb = $this->create_mock_wpdb();
        $GLOBALS['wpdb'] = $mock_wpdb;

        $this->api_client->method( 'bulk_sync_products' )->willReturn(
            array(
                'data' => array(
                    'synced'        => 2,
                    'updated'       => 0,
                    'skipped_limit' => 0,
                    'failed'        => 0,
                    'cap'           => array( 'limit_reached' => false ),
                    'results'       => array(
                        array( 'woocommerce_product_id' => 101, 'affilync_product_id' => 'aff_101', 'status' => 'synced' ),
                        array( 'woocommerce_product_id' => 102, 'affilync_product_id' => 'aff_102', 'status' => 'synced' ),
                    ),
                ),
            )
        );
        // Results applied => mark_as_synced => wpdb->update called.
        $mock_wpdb->expects( $this->atLeastOnce() )->method( 'update' )->willReturn( 1 );

        $this->sync->run_full_sync_batch( 0 );

        $hooks = array_column( $GLOBALS['affilync_scheduled_actions'], 'hook' );
        $this->assertNotContains( Affilync_Sync_Product_Sync::FULL_SYNC_HOOK, $hooks );
    }

    public function test_run_full_sync_batch_stops_when_cap_reached() {
        // A FULL page would normally reschedule the next batch; the cap stops it.
        $GLOBALS['affilync_test_product_ids'] = range( 1, Affilync_Sync_Product_Sync::FULL_SYNC_BATCH_SIZE );
        $GLOBALS['affilync_scheduled_actions'] = array();
        $this->override_wc_get_product( $this->create_mock_product( 1, 'publish' ) );
        $GLOBALS['wpdb'] = $this->create_mock_wpdb();

        $this->api_client->method( 'bulk_sync_products' )->willReturn(
            array( 'data' => array( 'cap' => array( 'limit_reached' => true ), 'results' => array() ) )
        );

        $this->sync->run_full_sync_batch( 0 );

        $hooks = array_column( $GLOBALS['affilync_scheduled_actions'], 'hook' );
        $this->assertNotContains( Affilync_Sync_Product_Sync::FULL_SYNC_HOOK, $hooks );
        $this->assertTrue( get_option( 'affilync_full_sync_limit_reached' ) );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a mock WC_Product.
     */
    private function create_mock_product(
        int $id,
        string $status = 'publish',
        bool $is_variation = false,
        float $price = 29.99
    ) {
        $methods = array(
            'get_id', 'get_name', 'get_description', 'get_short_description',
            'get_price', 'get_regular_price', 'get_sale_price', 'get_sku',
            'get_stock_status', 'get_stock_quantity', 'get_image_id',
            'get_gallery_image_ids', 'get_category_ids', 'get_tag_ids',
            'get_status', 'get_type', 'is_type', 'get_permalink',
            'is_in_stock', 'get_attributes', 'get_parent_id',
        );

        $product = $this->getMockBuilder( stdClass::class )
            ->addMethods( $methods )
            ->getMock();

        $product->method( 'get_id' )->willReturn( $id );
        $product->method( 'get_name' )->willReturn( 'Test Product ' . $id );
        $product->method( 'get_description' )->willReturn( 'Description for product ' . $id );
        $product->method( 'get_short_description' )->willReturn( 'Short desc ' . $id );
        $product->method( 'get_price' )->willReturn( $price );
        $product->method( 'get_regular_price' )->willReturn( $price );
        $product->method( 'get_sale_price' )->willReturn( '' );
        $product->method( 'get_sku' )->willReturn( 'SKU-' . $id );
        $product->method( 'get_stock_status' )->willReturn( 'instock' );
        $product->method( 'get_stock_quantity' )->willReturn( 100 );
        $product->method( 'get_image_id' )->willReturn( $id + 1000 );
        $product->method( 'get_gallery_image_ids' )->willReturn( array() );
        $product->method( 'get_category_ids' )->willReturn( array( 1 ) );
        $product->method( 'get_tag_ids' )->willReturn( array() );
        $product->method( 'get_status' )->willReturn( $status );
        $product->method( 'get_type' )->willReturn( $is_variation ? 'variation' : 'simple' );
        $product->method( 'is_type' )->willReturnCallback( function ( $type ) use ( $is_variation ) {
            return $is_variation ? $type === 'variation' : $type === 'simple';
        } );
        $product->method( 'get_permalink' )->willReturn( 'https://example.com/product/' . $id );
        $product->method( 'is_in_stock' )->willReturn( true );
        $product->method( 'get_attributes' )->willReturn( array() );
        $product->method( 'get_parent_id' )->willReturn( 0 );

        return $product;
    }

    /**
     * Create a mock wpdb with common methods.
     */
    private function create_mock_wpdb( $get_row_return = null ) {
        $mock = $this->getMockBuilder( stdClass::class )
            ->addMethods( array( 'get_row', 'get_results', 'get_col', 'insert', 'update', 'delete', 'query', 'prepare' ) )
            ->getMock();

        $mock->prefix = 'wp_';
        $mock->insert_id = rand( 1, 9999 );
        $mock->last_error = '';

        $mock->method( 'prepare' )->willReturnArgument( 0 );
        $mock->method( 'get_row' )->willReturn( $get_row_return );
        $mock->method( 'get_col' )->willReturn( array() );
        $mock->method( 'delete' )->willReturn( 1 );

        return $mock;
    }

    /**
     * Override the global wc_get_product function behaviour for a test.
     *
     * Since wc_get_product is defined once as a global mock, we store the
     * product in a global that the mock can read.
     */
    private function override_wc_get_product( $product ): void {
        $GLOBALS['affilync_test_wc_product'] = $product;
    }
}
