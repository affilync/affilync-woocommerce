<?php
/**
 * Call Tracking Statistics.
 *
 * Provides aggregated call statistics queries
 * with date-range filtering.
 *
 * @package Affilync_WooCommerce
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Affilync Call Tracking Stats.
 *
 * @since 1.5.0
 */
class Affilync_CallTracking_Stats {

    /**
     * Minimum call duration in seconds to count as "qualified".
     *
     * @var int
     */
    const QUALIFIED_DURATION = 90;

    /**
     * Get call statistics for a given period.
     *
     * @param string $period Time period: today, week, month, all.
     * @return array Stats array.
     */
    public function get_stats( $period = 'month' ) {
        global $wpdb;

        $table = $wpdb->prefix . Affilync_CallTracking_Call_Log::TABLE_NAME;

        // Check if table exists.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );

        if ( ! $table_exists ) {
            return $this->empty_stats();
        }

        $date_clause = $this->get_date_clause( $period );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_calls,
                COALESCE(SUM(duration), 0) as total_duration,
                COALESCE(AVG(duration), 0) as avg_duration,
                COUNT(CASE WHEN duration >= " . self::QUALIFIED_DURATION . " THEN 1 END) as qualified_calls,
                COUNT(DISTINCT caller_number) as unique_callers
            FROM {$table}
            WHERE status = 'completed'
            {$date_clause}"
        );

        if ( ! $row ) {
            return $this->empty_stats();
        }

        return array(
            'total_calls'    => (int) $row->total_calls,
            'total_duration' => (int) $row->total_duration,
            'avg_duration'   => round( (float) $row->avg_duration ),
            'qualified_calls' => (int) $row->qualified_calls,
            'unique_callers' => (int) $row->unique_callers,
        );
    }

    /**
     * Build a date WHERE clause for the given period.
     *
     * @param string $period Time period.
     * @return string SQL clause (includes leading AND).
     */
    private function get_date_clause( $period ) {
        switch ( $period ) {
            case 'today':
                return "AND DATE(created_at) = CURDATE()";

            case 'week':
                return "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

            case 'month':
                return "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

            case 'all':
            default:
                return '';
        }
    }

    /**
     * Return an empty stats array.
     *
     * @return array Default stats.
     */
    private function empty_stats() {
        return array(
            'total_calls'     => 0,
            'total_duration'  => 0,
            'avg_duration'    => 0,
            'qualified_calls' => 0,
            'unique_callers'  => 0,
        );
    }
}
