<?php
/**
 * Concurrency-safe ticket sequence and reference generator.
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
 * Class SequenceService
 */
final class SequenceService
{
    /**
     * Generate the next unique human-readable ticket reference.
     *
     * @param string|null $customPrefix Optional prefix override.
     * @return string e.g. "WPC-000001"
     */
    public static function generateNextTicketRef(?string $customPrefix = null): string
    {
        global $wpdb;

        $tables = SchemaManager::getTableNames();
        $sequenceTable = $tables['sequences'];
        $prefix = $customPrefix ?? (string) get_option('wpcalibrate_ticket_prefix', 'WPC');
        $prefix = strtoupper(trim(preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?: 'WPC'));

        // Atomic increment using LAST_INSERT_ID(current_val + 1).
        $updateResult = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$sequenceTable} SET current_val = LAST_INSERT_ID(current_val + 1) WHERE sequence_name = %s",
                'tickets'
            )
        );

        $nextSeq = 0;
        if ($updateResult !== false) {
            $nextSeq = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        }

        // Fallback if sequence row was missing or 0.
        if ($nextSeq <= 0) {
            $ticketsTable = $tables['tickets'];
            $maxId = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$ticketsTable}");
            $nextSeq = $maxId + 1;

            $wpdb->replace(
                $sequenceTable,
                [
                    'sequence_name' => 'tickets',
                    'current_val'   => $nextSeq,
                ],
                ['%s', '%d']
            );
        }

        $formatted = sprintf('%s-%06d', $prefix, $nextSeq);

        /**
         * Filter generated ticket reference.
         *
         * @param string $formatted Formatted ticket reference.
         * @param int $nextSeq Raw integer sequence.
         * @param string $prefix Prefix used.
         */
        return apply_filters('wpcalibrate_generated_ticket_ref', $formatted, $nextSeq, $prefix);
    }
}
