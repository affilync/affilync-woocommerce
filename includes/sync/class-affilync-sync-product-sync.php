<?php
/**
 * Product Sync for WooCommerce products.
 *
 * Synchronizes products to Affilync marketplace.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Product Sync.
 *
 * @since 1.0.0
 */
class Affilync_Sync_Product_Sync {

    /**
     * API client.
     *
     * @var Affilync_API_Client
     */
    private $api_client;

    /**
     * Table name.
     *
     * @var string
     */
    const TABLE_NAME = 'affilync_product_sync';

    /**
     * Batch size for bulk operations.
     *
     * @var int
     */
    const BATCH_SIZE = 50;

    /**
     * Products pushed per background full-sync batch. Smaller than BATCH_SIZE:
     * each item is a live API round-trip, and one Action Scheduler action must
     * finish well inside PHP's max_execution_time.
     *
     * @var int
     */
    const FULL_SYNC_BATCH_SIZE = 20;

    /**
     * Action Scheduler hook for a single full-sync batch (arg: integer offset).
     *
     * @var string
     */
    const FULL_SYNC_HOOK = 'affilync_full_sync_batch';

    /**
     * Constructor.
     *
     * @param Affilync_API_Client $api_client API client.
     */
    public function __construct( $api_client ) {
        $this->api_client = $api_client;

        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Product save hook.
        add_action( 'woocommerce_process_product_meta', array( $this, 'on_product_save' ), 20 );
        add_action( 'woocommerce_update_product', array( $this, 'on_product_update' ), 20 );

        // Product delete hook.
        add_action( 'before_delete_post', array( $this, 'on_product_delete' ) );
        add_action( 'wp_trash_post', array( $this, 'on_product_trash' ) );

        // Stock change hook.
        add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change' ) );
        add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_change' ) );

        // Cron hook for batch sync.
        add_action( 'affilync_sync_products', array( $this, 'sync_pending' ) );

        // Action Scheduler hook: one batch of the background full-catalog sync.
        add_action( self::FULL_SYNC_HOOK, array( $this, 'run_full_sync_batch' ), 10, 1 );
    }

    /**
     * Handle product save.
     *
     * @param int $product_id Product ID.
     */
    public function on_product_save( $product_id ) {
        $this->queue_product_sync( $product_id );
    }

    /**
     * Handle product update.
     *
     * @param int $product_id Product ID.
     */
    public function on_product_update( $product_id ) {
        $this->queue_product_sync( $product_id );
    }

    /**
     * Handle product delete.
     *
     * @param int $post_id Post ID.
     */
    public function on_product_delete( $post_id ) {
        if ( get_post_type( $post_id ) !== 'product' ) {
            return;
        }

        $this->delete_synced_product( $post_id );
    }

    /**
     * Handle product trash.
     *
     * @param int $post_id Post ID.
     */
    public function on_product_trash( $post_id ) {
        if ( get_post_type( $post_id ) !== 'product' ) {
            return;
        }

        $this->update_sync_status( $post_id, 'deleted' );
    }

    /**
     * Handle stock change.
     *
     * @param WC_Product $product Product object.
     */
    public function on_stock_change( $product ) {
        $product_id = $product->get_id();

        // If it's a variation, sync the parent.
        if ( $product->is_type( 'variation' ) ) {
            $product_id = $product->get_parent_id();
        }

        $this->queue_product_sync( $product_id );
    }

    /**
     * Queue a product for sync.
     *
     * @param int $product_id Product ID.
     */
    public function queue_product_sync( $product_id ) {
        // Check if sync is enabled.
        $settings = get_option( 'affilync_settings', array() );
        if ( isset( $settings['product_sync_enabled'] ) && ! $settings['product_sync_enabled'] ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        // Calculate product hash.
        $hash = $this->calculate_product_hash( $product );

        // Check if already synced with same hash.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, sync_hash FROM {$table} WHERE product_id = %d",
                $product_id
            )
        );

        if ( $existing && $existing->sync_hash === $hash ) {
            // No changes - skip.
            return;
        }

        if ( $existing ) {
            // Update existing record.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table,
                array(
                    'sync_status' => 'pending',
                    'sync_hash'   => $hash,
                ),
                array( 'id' => $existing->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } else {
            // Insert new record.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $table,
                array(
                    'product_id'  => $product_id,
                    'sync_status' => 'pending',
                    'sync_hash'   => $hash,
                ),
                array( '%d', '%s', '%s' )
            );
        }
    }

    /**
     * Calculate hash for product data.
     *
     * @param WC_Product $product Product object.
     * @return string Hash.
     */
    private function calculate_product_hash( $product ) {
        $data = array(
            'name'        => $product->get_name(),
            'description' => $product->get_description(),
            'short_desc'  => $product->get_short_description(),
            'price'       => $product->get_price(),
            'regular'     => $product->get_regular_price(),
            'sale'        => $product->get_sale_price(),
            'sku'         => $product->get_sku(),
            'stock'       => $product->get_stock_status(),
            'stock_qty'   => $product->get_stock_quantity(),
            'image'       => $product->get_image_id(),
            'gallery'     => $product->get_gallery_image_ids(),
            'categories'  => $product->get_category_ids(),
            'tags'        => $product->get_tag_ids(),
            'status'      => $product->get_status(),
        );

        return md5( wp_json_encode( $data ) );
    }

    /**
     * Sync pending products.
     *
     * @return array Results.
     */
    public function sync_pending() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Get pending products.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pending = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id FROM {$table}
                WHERE sync_status = 'pending' AND retry_count < 5
                ORDER BY updated_at ASC LIMIT %d",
                self::BATCH_SIZE
            )
        );

        $synced = 0;
        $failed = 0;

        foreach ( $pending as $row ) {
            if ( $this->sync_product( $row->product_id ) ) {
                $synced++;
            } else {
                $failed++;
            }
        }

        // Update last sync timestamp.
        update_option( 'affilync_last_product_sync', current_time( 'mysql' ) );

        return array(
            'synced' => $synced,
            'failed' => $failed,
        );
    }

    /**
     * Sync a single product.
     *
     * @param int $product_id Product ID.
     * @return bool True on success.
     */
    public function sync_product( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            $this->update_sync_status( $product_id, 'failed', 'Product not found' );
            return false;
        }

        // Skip drafts and private products.
        if ( $product->get_status() !== 'publish' ) {
            $this->update_sync_status( $product_id, 'skipped', 'Not published' );
            return true;
        }

        // Build product data.
        $data = $this->build_product_data( $product );

        // Send to API.
        $result = $this->api_client->sync_product( $data );

        if ( is_wp_error( $result ) ) {
            $this->update_sync_status( $product_id, 'failed', $result->get_error_message() );
            $this->increment_retry_count( $product_id );
            return false;
        }

        // Update sync status.
        //
        // The API returns the standard Affilync envelope:
        //   { "success": true, "message": "Success",
        //     "data": { "affilync_product_id": "<uuid>", "woocommerce_product_id": .. } }
        // (see affilync-api app/routes/integrations/woocommerce.py::sync_product ->
        //  wrap_response). This previously read a top-level $result['product_id'],
        // which is NEVER present, so every product was marked synced with a NULL
        // affilync id — and delete_product() only calls the API when that id is
        // truthy, so deletes silently no-opped. Read data.affilync_product_id, with
        // fallbacks so a renamed/un-enveloped response still resolves.
        $affilync_id = $result['data']['affilync_product_id']
            ?? $result['data']['product_id']
            ?? $result['affilync_product_id']
            ?? $result['product_id']
            ?? null;
        $this->mark_as_synced( $product_id, $affilync_id );

        return true;
    }

    /**
     * Build product data for API.
     *
     * @param WC_Product $product Product object.
     * @return array Product data.
     */
    private function build_product_data( $product ) {
        // The shape MUST match the affilync-api WooCommerceProductSync schema
        // (app/routes/integrations/woocommerce.py). Getting this wrong is a
        // silent 422 on every product. Specifically:
        //   - the id field is `product_id` (int), REQUIRED — not `external_id`
        //     (the API builds external_id = "woocommerce_{product_id}" itself);
        //   - the affiliate URL field is `permalink`, not `url`;
        //   - `categories`, `tags`, `images` are lists of STRINGS (names / URLs),
        //     not the {id,name,slug} / {id,url,alt} objects the helpers return.
        // Unknown extra fields the API ignores, but the required/typed ones above
        // must be exact. (Historically build_product_data emitted external_id +
        // object arrays, so real syncs 422'd — the live smoke test used a
        // hand-built payload and never caught it.)
        $regular = $product->get_regular_price();
        $sale    = $product->get_sale_price();

        return array(
            'product_id'        => (int) $product->get_id(),
            'name'              => $product->get_name(),
            'description'       => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'sku'               => $product->get_sku(),
            'price'             => floatval( $product->get_price() ),
            'regular_price'     => ( '' !== $regular && null !== $regular ) ? floatval( $regular ) : null,
            'sale_price'        => ( '' !== $sale && null !== $sale ) ? floatval( $sale ) : null,
            'currency'          => get_woocommerce_currency(),
            'stock_status'      => $product->get_stock_status(),
            'stock_quantity'    => $product->get_stock_quantity(),
            'categories'        => $this->term_names( $this->get_product_categories( $product ) ),
            'tags'              => $this->term_names( $this->get_product_tags( $product ) ),
            'images'            => $this->image_urls( $this->get_product_images( $product ) ),
            'permalink'         => $product->get_permalink(),
        );
    }

    /**
     * Reduce {id,name,slug} term rows to a list of name strings (API shape).
     *
     * @param array $terms Term rows.
     * @return string[] Names.
     */
    private function term_names( $terms ) {
        $names = array();
        foreach ( (array) $terms as $t ) {
            if ( isset( $t['name'] ) && '' !== $t['name'] ) {
                $names[] = $t['name'];
            }
        }
        return $names;
    }

    /**
     * Reduce {id,url,alt} image rows to a list of URL strings (API shape).
     *
     * @param array $images Image rows.
     * @return string[] URLs.
     */
    private function image_urls( $images ) {
        $urls = array();
        foreach ( (array) $images as $img ) {
            if ( isset( $img['url'] ) && '' !== $img['url'] ) {
                $urls[] = $img['url'];
            }
        }
        return $urls;
    }

    /**
     * Get product categories.
     *
     * @param WC_Product $product Product object.
     * @return array Categories.
     */
    private function get_product_categories( $product ) {
        $categories = array();
        $term_ids = $product->get_category_ids();

        foreach ( $term_ids as $term_id ) {
            $term = get_term( $term_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $categories[] = array(
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }

        return $categories;
    }

    /**
     * Get product tags.
     *
     * @param WC_Product $product Product object.
     * @return array Tags.
     */
    private function get_product_tags( $product ) {
        $tags = array();
        $term_ids = $product->get_tag_ids();

        foreach ( $term_ids as $term_id ) {
            $term = get_term( $term_id, 'product_tag' );
            if ( $term && ! is_wp_error( $term ) ) {
                $tags[] = array(
                    'id'   => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                );
            }
        }

        return $tags;
    }

    /**
     * Get product images.
     *
     * @param WC_Product $product Product object.
     * @return array Images.
     */
    private function get_product_images( $product ) {
        $images = array();

        // Main image.
        $main_image_id = $product->get_image_id();
        if ( $main_image_id ) {
            $images[] = array(
                'id'  => $main_image_id,
                'url' => wp_get_attachment_url( $main_image_id ),
                'alt' => get_post_meta( $main_image_id, '_wp_attachment_image_alt', true ),
                'main' => true,
            );
        }

        // Gallery images.
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ( $gallery_ids as $image_id ) {
            $images[] = array(
                'id'  => $image_id,
                'url' => wp_get_attachment_url( $image_id ),
                'alt' => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
                'main' => false,
            );
        }

        return $images;
    }

    /**
     * Get product attributes.
     *
     * @param WC_Product $product Product object.
     * @return array Attributes.
     */
    private function get_product_attributes( $product ) {
        $attributes = array();

        foreach ( $product->get_attributes() as $attribute ) {
            if ( $attribute->is_taxonomy() ) {
                $values = wc_get_product_terms(
                    $product->get_id(),
                    $attribute->get_name(),
                    array( 'fields' => 'names' )
                );
            } else {
                $values = $attribute->get_options();
            }

            $attributes[] = array(
                'name'    => wc_attribute_label( $attribute->get_name() ),
                'values'  => $values,
                'visible' => $attribute->get_visible(),
            );
        }

        return $attributes;
    }

    /**
     * Get product variations.
     *
     * @param WC_Product_Variable $product Variable product.
     * @return array Variations.
     */
    private function get_product_variations( $product ) {
        $variations = array();

        foreach ( $product->get_available_variations() as $variation_data ) {
            $variation = wc_get_product( $variation_data['variation_id'] );
            if ( ! $variation ) {
                continue;
            }

            $variations[] = array(
                'id'             => $variation->get_id(),
                'sku'            => $variation->get_sku(),
                'price'          => floatval( $variation->get_price() ),
                'regular_price'  => floatval( $variation->get_regular_price() ),
                'sale_price'     => $variation->get_sale_price() ? floatval( $variation->get_sale_price() ) : null,
                'stock_status'   => $variation->get_stock_status(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'attributes'     => $variation->get_attributes(),
                'image'          => $variation->get_image_id() ? wp_get_attachment_url( $variation->get_image_id() ) : null,
            );
        }

        return $variations;
    }

    /**
     * Update sync status.
     *
     * @param int    $product_id Product ID.
     * @param string $status     Status.
     * @param string $error      Error message (optional).
     */
    private function update_sync_status( $product_id, $status, $error = null ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $data = array( 'sync_status' => $status );
        $format = array( '%s' );

        if ( $error ) {
            $data['error_message'] = $error;
            $format[] = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            $data,
            array( 'product_id' => $product_id ),
            $format,
            array( '%d' )
        );
    }

    /**
     * Mark product as synced.
     *
     * @param int         $product_id   Product ID.
     * @param string|null $affilync_id  Affilync product ID.
     */
    private function mark_as_synced( $product_id, $affilync_id = null ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table,
            array(
                'sync_status'        => 'synced',
                'affilync_product_id' => $affilync_id,
                'last_synced_at'     => current_time( 'mysql' ),
                'error_message'      => null,
                'retry_count'        => 0,
            ),
            array( 'product_id' => $product_id ),
            array( '%s', '%s', '%s', '%s', '%d' ),
            array( '%d' )
        );
    }

    /**
     * Increment retry count.
     *
     * @param int $product_id Product ID.
     */
    private function increment_retry_count( $product_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET retry_count = retry_count + 1 WHERE product_id = %d",
                $product_id
            )
        );
    }

    /**
     * Delete synced product from Affilync.
     *
     * @param int $product_id Product ID.
     */
    private function delete_synced_product( $product_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Get Affilync product ID.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT affilync_product_id FROM {$table} WHERE product_id = %d",
                $product_id
            )
        );

        if ( $row && $row->affilync_product_id ) {
            // Delete from Affilync.
            $this->api_client->delete_product( $row->affilync_product_id );
        }

        // Delete local record.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $table,
            array( 'product_id' => $product_id ),
            array( '%d' )
        );
    }

    /**
     * Full sync of all products.
     *
     * @return array Results.
     */
    /**
     * Start a full-catalog sync as a background, batched Action Scheduler job.
     *
     * The old full_sync() loaded EVERY product id (limit=-1) and pushed them all
     * inline in the request that triggered it — fine for a demo store, a memory
     * and max_execution_time hazard for a real catalog, and it blocks the caller
     * until the last product is pushed. This instead enqueues the first batch and
     * returns immediately; each batch pages the catalog and self-schedules the
     * next until done (run_full_sync_batch). Falls back to the inline full_sync()
     * only when Action Scheduler is unavailable (it ships with WooCommerce, so
     * that path is rare).
     *
     * @return array Status payload.
     */
    public function start_full_sync() {
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            // No Action Scheduler — do it inline as a last resort.
            return $this->full_sync();
        }

        // Don't stack concurrent full syncs.
        if ( function_exists( 'as_next_scheduled_action' )
            && false !== as_next_scheduled_action( self::FULL_SYNC_HOOK ) ) {
            return array(
                'status'  => 'already_running',
                'message' => __( 'A full product sync is already in progress.', 'affilync-woocommerce' ),
            );
        }

        // Cheap count (no id load) for the "N products" message.
        $total  = 0;
        $counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( 'product' ) : null;
        if ( $counts && isset( $counts->publish ) ) {
            $total = (int) $counts->publish;
        }

        update_option( 'affilync_full_sync_running', true );
        update_option( 'affilync_full_sync_started_at', current_time( 'mysql', true ) );

        as_schedule_single_action( time(), self::FULL_SYNC_HOOK, array( 0 ), 'affilync' );

        return array(
            'status'  => 'scheduled',
            'total'   => $total,
            'message' => sprintf(
                /* translators: %d: number of products */
                __( 'Full product sync started in the background for %d products.', 'affilync-woocommerce' ),
                $total
            ),
        );
    }

    /**
     * Process one batch of the background full-catalog sync, then self-schedule
     * the next batch if more products remain. Registered on FULL_SYNC_HOOK.
     *
     * @param int $offset Catalog offset for this batch.
     */
    public function run_full_sync_batch( $offset = 0 ) {
        $offset = max( 0, (int) $offset );

        $ids = wc_get_products(
            array(
                'status'  => 'publish',
                'limit'   => self::FULL_SYNC_BATCH_SIZE,
                'offset'  => $offset,
                'orderby' => 'ID',
                'order'   => 'ASC',
                'return'  => 'ids',
            )
        );

        if ( empty( $ids ) ) {
            $this->finish_full_sync();
            return;
        }

        // Build the page payload once and push it in a SINGLE bulk request,
        // rather than one HTTP round-trip per product (the whole point of the
        // batched design — it scales to large catalogs). Each product still gets
        // a queue row for status/retry bookkeeping.
        $products = array();
        foreach ( $ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || 'publish' !== $product->get_status() ) {
                continue;
            }
            $this->queue_product_sync( $product_id );
            $products[] = $this->build_product_data( $product );
        }

        if ( ! empty( $products ) ) {
            $result = $this->api_client->bulk_sync_products( $products );

            if ( is_wp_error( $result ) ) {
                // Whole page failed to reach the API — mark for retry.
                foreach ( $ids as $product_id ) {
                    $this->update_sync_status( $product_id, 'failed', $result->get_error_message() );
                    $this->increment_retry_count( $product_id );
                }
            } else {
                $data = isset( $result['data'] ) ? $result['data'] : $result;
                $this->apply_bulk_results( $data );

                // Plan product cap reached — stop paging; the merchant must
                // upgrade to sync more. Record it so the UI can surface it.
                if ( ! empty( $data['cap']['limit_reached'] ) ) {
                    update_option( 'affilync_full_sync_limit_reached', true );
                    $this->finish_full_sync();
                    return;
                }
            }
        }

        // A full page means there is probably more; a short page is the last one.
        if ( count( $ids ) === self::FULL_SYNC_BATCH_SIZE && function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + 5,
                self::FULL_SYNC_HOOK,
                array( $offset + self::FULL_SYNC_BATCH_SIZE ),
                'affilync'
            );
        } else {
            $this->finish_full_sync();
        }
    }

    /**
     * Apply a bulk-sync response's per-item results to the local queue rows.
     *
     * @param array $data The response envelope's `data` (synced/updated/
     *                     skipped_limit/failed/cap/results).
     */
    private function apply_bulk_results( $data ) {
        if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
            return;
        }
        foreach ( $data['results'] as $row ) {
            $woo_id = isset( $row['woocommerce_product_id'] ) ? (int) $row['woocommerce_product_id'] : 0;
            if ( ! $woo_id ) {
                continue;
            }
            $status = isset( $row['status'] ) ? $row['status'] : '';
            if ( 'synced' === $status || 'updated' === $status ) {
                $aff_id = isset( $row['affilync_product_id'] ) ? $row['affilync_product_id'] : null;
                $this->mark_as_synced( $woo_id, $aff_id );
            } elseif ( 'skipped_limit' === $status ) {
                $this->update_sync_status( $woo_id, 'skipped', 'Plan product limit reached' );
            } else {
                $this->update_sync_status( $woo_id, 'failed', 'Bulk sync failed' );
                $this->increment_retry_count( $woo_id );
            }
        }
    }

    /**
     * Mark the background full sync complete.
     */
    private function finish_full_sync() {
        update_option( 'affilync_full_sync_running', false );
        update_option( 'affilync_full_sync_completed_at', current_time( 'mysql', true ) );
    }

    /**
     * Inline full-catalog sync (fallback when Action Scheduler is unavailable).
     *
     * Prefer start_full_sync(), which runs this work as a batched background job.
     */
    public function full_sync() {
        // Get all published products.
        $product_ids = wc_get_products(
            array(
                'status' => 'publish',
                'limit'  => -1,
                'return' => 'ids',
            )
        );

        // Queue all for sync.
        foreach ( $product_ids as $product_id ) {
            $this->queue_product_sync( $product_id );
        }

        // Run sync.
        return $this->sync_pending();
    }

    /**
     * Get sync statistics.
     *
     * @return array Statistics.
     */
    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
                SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
                MAX(last_synced_at) as last_sync
            FROM {$table}"
        );

        return array(
            'total'     => intval( $stats->total ),
            'synced'    => intval( $stats->synced ),
            'pending'   => intval( $stats->pending ),
            'failed'    => intval( $stats->failed ),
            'last_sync' => $stats->last_sync,
        );
    }
}
