<?php
/**
 * Ticket database repository.
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
 * Class TicketRepository
 */
final class TicketRepository
{
    /**
     * Allowed columns for ordering to prevent SQL injection.
     */
    private const ALLOWED_ORDER_BY = [
        'id'            => 't.id',
        'ticket_ref'    => 't.ticket_ref',
        'created_at'    => 't.created_at',
        'updated_at'    => 't.updated_at',
        'last_reply_at' => 't.last_reply_at',
        'status'        => 't.status',
        'priority'      => 't.priority',
        'subject'       => 't.subject',
    ];

    /**
     * Get ticket by internal ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['tickets']} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get ticket by public ticket reference (e.g. WPC-000001).
     *
     * @param string $ref
     * @return array<string, mixed>|null
     */
    public function getByRef(string $ref): ?array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['tickets']} WHERE ticket_ref = %s", trim($ref)),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get ticket by unique internal ticket key.
     *
     * @param string $key
     * @return array<string, mixed>|null
     */
    public function getByKey(string $key): ?array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['tickets']} WHERE ticket_key = %s", trim($key)),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Insert a new ticket record.
     *
     * @param array<string, mixed> $data
     * @return int|false
     */
    public function create(array $data): int|false
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();
        $now = current_time('mysql', true);

        $record = [
            'ticket_key'             => $data['ticket_key'] ?? bin2hex(random_bytes(16)),
            'ticket_ref'             => $data['ticket_ref'],
            'customer_id'            => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'guest_name'             => !empty($data['guest_name']) ? sanitize_text_field((string) $data['guest_name']) : null,
            'guest_email'            => !empty($data['guest_email']) ? sanitize_email((string) $data['guest_email']) : null,
            'guest_token_hash'       => $data['guest_token_hash'] ?? null,
            'guest_token_created_at' => !empty($data['guest_token_hash']) ? $now : null,
            'status'                 => $data['status'] ?? 'open',
            'priority'               => $data['priority'] ?? 'normal',
            'category_id'            => (int) ($data['category_id'] ?? 1),
            'assigned_agent_id'      => !empty($data['assigned_agent_id']) ? (int) $data['assigned_agent_id'] : null,
            'subject'                => sanitize_text_field((string) $data['subject']),
            'woo_order_id'           => !empty($data['woo_order_id']) ? (int) $data['woo_order_id'] : null,
            'created_at'             => $now,
            'updated_at'             => $now,
            'last_reply_at'          => $now,
            'resolved_at'            => null,
            'closed_at'              => null,
        ];

        $formats = [
            '%s', '%s', '%d', '%s', '%s', '%s', '%s',
            '%s', '%s', '%d', '%d', '%s', '%d',
            '%s', '%s', '%s', '%s', '%s'
        ];

        $success = $wpdb->insert($tables['tickets'], $record, $formats);
        return $success ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing ticket record.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $data['updated_at'] = current_time('mysql', true);
        $formats = [];

        foreach ($data as $key => $val) {
            if ($val === null) {
                $formats[] = null;
            } elseif (is_int($val)) {
                $formats[] = '%d';
            } elseif (is_float($val)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        $result = $wpdb->update($tables['tickets'], $data, ['id' => $id], $formats, ['%d']);
        return $result !== false;
    }

    /**
     * Delete a ticket record and its associated messages, activity, and attachments.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        // Remove attachments from disk first.
        $attachments = $wpdb->get_results(
            $wpdb->prepare("SELECT file_path FROM {$tables['attachments']} WHERE ticket_id = %d", $id),
            ARRAY_A
        );

        $storageDir = \WPCalibrate\SupportDesk\Security\AttachmentSecurity::getStorageDirectory();
        foreach ($attachments as $att) {
            $filePath = trailingslashit($storageDir) . basename($att['file_path']);
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        $wpdb->delete($tables['attachments'], ['ticket_id' => $id], ['%d']);
        $wpdb->delete($tables['activity'], ['ticket_id' => $id], ['%d']);
        $wpdb->delete($tables['messages'], ['ticket_id' => $id], ['%d']);
        $deleted = $wpdb->delete($tables['tickets'], ['id' => $id], ['%d']);

        return (bool) $deleted;
    }

    /**
     * Query tickets with pagination, filters, and sorting.
     *
     * @param array<string, mixed> $args
     * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
     */
    public function query(array $args): array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $where = ['1=1'];
        $params = [];

        // Customer ID filter.
        if (isset($args['customer_id'])) {
            $where[] = 't.customer_id = %d';
            $params[] = (int) $args['customer_id'];
        }

        // Status filter.
        if (!empty($args['status'])) {
            $where[] = 't.status = %s';
            $params[] = (string) $args['status'];
        }

        // Priority filter.
        if (!empty($args['priority'])) {
            $where[] = 't.priority = %s';
            $params[] = (string) $args['priority'];
        }

        // Category filter.
        if (!empty($args['category_id'])) {
            $where[] = 't.category_id = %d';
            $params[] = (int) $args['category_id'];
        }

        // Assigned Agent filter.
        if (isset($args['assigned_agent_id'])) {
            if ($args['assigned_agent_id'] === 'unassigned' || $args['assigned_agent_id'] === 0) {
                $where[] = 't.assigned_agent_id IS NULL';
            } else {
                $where[] = 't.assigned_agent_id = %d';
                $params[] = (int) $args['assigned_agent_id'];
            }
        }

        // WooCommerce Order ID filter.
        if (!empty($args['woo_order_id'])) {
            $where[] = 't.woo_order_id = %d';
            $params[] = (int) $args['woo_order_id'];
        }

        // Search query (ticket_ref, subject, guest_name, guest_email).
        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where[] = '(t.ticket_ref LIKE %s OR t.subject LIKE %s OR t.guest_name LIKE %s OR t.guest_email LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $whereSql = implode(' AND ', $where);

        // Count total matching.
        $countQuery = "SELECT COUNT(*) FROM {$tables['tickets']} t WHERE {$whereSql}";
        if (!empty($params)) {
            $countQuery = $wpdb->prepare($countQuery, $params);
        }
        $total = (int) $wpdb->get_var($countQuery);

        // Sorting allowlist.
        $orderByKey = $args['order_by'] ?? 'last_reply_at';
        $orderBy = self::ALLOWED_ORDER_BY[$orderByKey] ?? 't.last_reply_at';
        $order = (isset($args['order']) && strtoupper((string) $args['order']) === 'ASC') ? 'ASC' : 'DESC';

        // Pagination.
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $itemsQuery = "
            SELECT t.*, c.name AS category_name
            FROM {$tables['tickets']} t
            LEFT JOIN {$tables['categories']} c ON t.category_id = c.id
            WHERE {$whereSql}
            ORDER BY {$orderBy} {$order}
            LIMIT %d OFFSET %d
        ";

        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;

        $preparedItemsQuery = $wpdb->prepare($itemsQuery, $queryParams);
        $items = $wpdb->get_results($preparedItemsQuery, ARRAY_A) ?: [];

        $pages = (int) ceil($total / $perPage);

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages > 0 ? $pages : 1,
        ];
    }
}
