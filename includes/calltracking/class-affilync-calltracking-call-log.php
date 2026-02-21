<?php
/**
 * Call Log database operations.
 *
 * Handles storage, retrieval, and management of call records
 * in the local WordPress database.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Call Tracking Call Log.
 *
 * @since 1.5.0
 */
class Affilync_CallTracking_Call_Log {

    /**
     * Table name (without prefix).
     *
     * @var string
     */
    const TABLE_NAME = 'affilync_call_log';

    /**
     * Store a call record.
     *
     * @param array $data Call data.
     * @return int|false Insert ID or false on failure.
     */
    public function store_call( $data ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $defaults = array(
            'call_id'          => '',
            'tracking_number'  => '',
            'caller_number'    => '',
            'affiliate_id'     => null,
            'campaign_id'      => null,
            'duration'         => 0,
            'billable_seconds' => 0,
            'status'           => 'completed',
            'recording_url'    => null,
            'call_data'        => null,
            'synced_to_api'    => 0,
            'api_call_id'      => null,
        );

        $data = wp_parse_args( $data, $defaults );

        // Encode call_data if it's an array.
        if ( is_array( $data['call_data'] ) ) {
            $data['call_data'] = wp_json_encode( $data['call_data'] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            array(
                'call_id'          => sanitize_text_field( $data['call_id'] ),
                'tracking_number'  => sanitize_text_field( $data['tracking_number'] ),
                'caller_number'    => sanitize_text_field( $data['caller_number'] ),
                'affiliate_id'     => $data['affiliate_id'] ? sanitize_text_field( $data['affiliate_id'] ) : null,
                'campaign_id'      => $data['campaign_id'] ? sanitize_text_field( $data['campaign_id'] ) : null,
                'duration'         => absint( $data['duration'] ),
                'billable_seconds' => absint( $data['billable_seconds'] ),
                'status'           => sanitize_key( $data['status'] ),
                'recording_url'    => $data['recording_url'] ? esc_url_raw( $data['recording_url'] ) : null,
                'call_data'        => $data['call_data'],
                'synced_to_api'    => absint( $data['synced_to_api'] ),
                'api_call_id'      => $data['api_call_id'] ? sanitize_text_field( $data['api_call_id'] ) : null,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get a single call by ID.
     *
     * @param int $id Call log ID.
     * @return object|null Call record or null.
     */
    public function get_call( $id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
        );
    }

    /**
     * Get a call by its external call_id.
     *
     * @param string $call_id External call ID.
     * @return object|null Call record or null.
     */
    public function get_call_by_call_id( $call_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE call_id = %s", $call_id )
        );
    }

    /**
     * Get calls with pagination and optional filters.
     *
     * @param array $args Query arguments.
     * @return array { 'calls' => array, 'total' => int }
     */
    public function get_calls( $args = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $defaults = array(
            'per_page'     => 20,
            'page'         => 1,
            'affiliate_id' => '',
            'campaign_id'  => '',
            'status'       => '',
            'date_from'    => '',
            'date_to'      => '',
            'orderby'      => 'created_at',
            'order'        => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where_clauses = array( '1=1' );
        $prepare_args  = array();

        if ( ! empty( $args['affiliate_id'] ) ) {
            $where_clauses[] = 'affiliate_id = %s';
            $prepare_args[]  = sanitize_text_field( $args['affiliate_id'] );
        }

        if ( ! empty( $args['campaign_id'] ) ) {
            $where_clauses[] = 'campaign_id = %s';
            $prepare_args[]  = sanitize_text_field( $args['campaign_id'] );
        }

        if ( ! empty( $args['status'] ) ) {
            $where_clauses[] = 'status = %s';
            $prepare_args[]  = sanitize_key( $args['status'] );
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where_clauses[] = 'created_at >= %s';
            $prepare_args[]  = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where_clauses[] = 'created_at <= %s';
            $prepare_args[]  = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where_clauses );

        // Whitelist orderby and order.
        $allowed_orderby = array( 'created_at', 'duration', 'status', 'affiliate_id' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = absint( $args['per_page'] );
        $offset   = ( absint( $args['page'] ) - 1 ) * $per_page;

        // Get total count.
        if ( ! empty( $prepare_args ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$prepare_args )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
        }

        // Get results.
        $query_args   = $prepare_args;
        $query_args[] = $per_page;
        $query_args[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $calls = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                ...$query_args
            )
        );

        return array(
            'calls' => $calls,
            'total' => $total,
        );
    }

    /**
     * Update a call record.
     *
     * @param int   $id   Call log ID.
     * @param array $data Fields to update.
     * @return int|false Number of rows updated or false on error.
     */
    public function update_call( $id, $data ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        $update_data   = array();
        $update_format = array();

        $string_fields = array( 'status', 'recording_url', 'api_call_id', 'call_id' );
        $int_fields    = array( 'duration', 'billable_seconds', 'synced_to_api' );

        foreach ( $string_fields as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $update_data[ $field ] = $data[ $field ];
                $update_format[]       = '%s';
            }
        }

        foreach ( $int_fields as $field ) {
            if ( isset( $data[ $field ] ) ) {
                $update_data[ $field ] = absint( $data[ $field ] );
                $update_format[]       = '%d';
            }
        }

        if ( isset( $data['call_data'] ) ) {
            $update_data['call_data'] = is_array( $data['call_data'] )
                ? wp_json_encode( $data['call_data'] )
                : $data['call_data'];
            $update_format[] = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $id ),
            $update_format,
            array( '%d' )
        );
    }

    /**
     * Check if a call is a duplicate by call_id.
     *
     * @param string $call_id External call ID.
     * @return bool True if duplicate.
     */
    public function is_duplicate( $call_id ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE call_id = %s", $call_id )
        );

        return intval( $count ) > 0;
    }

    /**
     * Get unsynced call records.
     *
     * @param int $limit Max records to return.
     * @return array Call records.
     */
    public function get_unsynced( $limit = 50 ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_NAME;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE synced_to_api = 0 ORDER BY created_at ASC LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Mark a call as synced to API.
     *
     * @param int    $id          Call log ID.
     * @param string $api_call_id API call ID.
     * @return int|false Number of rows updated or false.
     */
    public function mark_synced( $id, $api_call_id = '' ) {
        return $this->update_call( $id, array(
            'synced_to_api' => 1,
            'api_call_id'   => $api_call_id,
        ) );
    }
}
