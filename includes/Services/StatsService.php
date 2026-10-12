<?php
/**
 * Lightweight cached dashboard metrics service.
 *
 * @package WPCalibrate\SupportDesk\Services
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Services;

use WPCalibrate\SupportDesk\Database\SchemaManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class StatsService
 */
final class StatsService
{
    private const CACHE_KEY = 'wpcalibrate_stats_summary';

    /**
     * Get aggregate ticket counts with transient caching.
     *
     * @return array<string, int>
     */
    public static function getSummary(): array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $tables = SchemaManager::getTableNames();
        $ticketTable = $tables['tickets'];

        $counts = [
            'total'            => 0,
            'open'             => 0,
            'in_progress'      => 0,
            'waiting_customer' => 0,
            'resolved'         => 0,
            'closed'           => 0,
            'unassigned'       => 0,
        ];

        // Query status aggregates in one fast query.
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM {$ticketTable} GROUP BY status",
            ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            $counts['total'] += $cnt;
            if (isset($counts[$status])) {
                $counts[$status] = $cnt;
            }
        }

        // Unassigned count.
        $counts['unassigned'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$ticketTable} WHERE assigned_agent_id IS NULL AND status NOT IN ('closed', 'resolved')"
        );

        // Cache for 10 minutes.
        set_transient(self::CACHE_KEY, $counts, 10 * MINUTE_IN_SECONDS);

        return $counts;
    }

    /**
     * Invalidate cached metrics when tickets change.
     *
     * @return void
     */
    public static function invalidateCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }
}
