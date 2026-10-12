<?php
/**
 * Ticket activity and audit repository.
 *
 * @package WPCalibrate\SupportDesk\Repositories
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Repositories;

use WPCalibrate\SupportDesk\Database\SchemaManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ActivityRepository
 */
final class ActivityRepository
{
    /**
     * Log a ticket event.
     *
     * @param int $ticketId
     * @param string $action
     * @param int|null $actorId
     * @param string $actorType 'agent'|'customer'|'guest'|'system'
     * @param array<string, mixed> $meta
     * @return int|false
     */
    public function log(int $ticketId, string $action, ?int $actorId, string $actorType = 'system', array $meta = []): int|false
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();
        $now = current_time('mysql', true);

        $record = [
            'ticket_id'  => $ticketId,
            'actor_id'   => $actorId > 0 ? $actorId : null,
            'actor_type' => $actorType,
            'action'     => sanitize_key($action),
            'meta_data'  => !empty($meta) ? wp_json_encode($meta) : null,
            'created_at' => $now,
        ];

        $formats = ['%d', '%d', '%s', '%s', '%s', '%s'];
        $success = $wpdb->insert($tables['activity'], $record, $formats);

        return $success ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get activity history for a ticket.
     *
     * @param int $ticketId
     * @param bool $includeInternal Whether to include internal-only events (agents only).
     * @return array<int, array<string, mixed>>
     */
    public function getByTicket(int $ticketId, bool $includeInternal = false): array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        if ($includeInternal) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$tables['activity']} WHERE ticket_id = %d ORDER BY created_at ASC, id ASC",
                $ticketId
            );
        } else {
            // Exclude internal notes from customer activity view.
            $sql = $wpdb->prepare(
                "SELECT * FROM {$tables['activity']} WHERE ticket_id = %d AND action != %s ORDER BY created_at ASC, id ASC",
                $ticketId,
                'internal_note'
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $row['meta_data'] = !empty($row['meta_data']) ? json_decode((string) $row['meta_data'], true) : [];
        }

        return $rows;
    }
}
