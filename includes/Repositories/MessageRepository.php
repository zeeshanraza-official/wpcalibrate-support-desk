<?php
/**
 * Message and internal note repository.
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
 * Class MessageRepository
 */
final class MessageRepository
{
    /**
     * Insert a message or note.
     *
     * @param array<string, mixed> $data
     * @return int|false
     */
    public function create(array $data): int|false
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();
        $now = current_time('mysql', true);

        $isInternal = ($data['visibility'] ?? 'public') === 'internal' || ($data['message_type'] ?? 'reply') === 'note';

        $record = [
            'ticket_id'    => (int) $data['ticket_id'],
            'author_id'    => !empty($data['author_id']) ? (int) $data['author_id'] : null,
            'author_name'  => sanitize_text_field((string) ($data['author_name'] ?? 'User')),
            'author_email' => sanitize_email((string) ($data['author_email'] ?? '')),
            'message_type' => $isInternal ? 'note' : 'reply',
            'visibility'   => $isInternal ? 'internal' : 'public',
            'body'         => wp_kses_post((string) $data['body']),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        $formats = ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];
        $success = $wpdb->insert($tables['messages'], $record, $formats);

        return $success ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get message by ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['messages']} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Get all messages for a ticket.
     *
     * @param int $ticketId
     * @param bool $includeInternal Whether to include internal notes (agents only).
     * @return array<int, array<string, mixed>>
     */
    public function getByTicket(int $ticketId, bool $includeInternal = false): array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        if ($includeInternal) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$tables['messages']} WHERE ticket_id = %d ORDER BY created_at ASC, id ASC",
                $ticketId
            );
        } else {
            // Strictly exclude any internal notes or internal visibility.
            $sql = $wpdb->prepare(
                "SELECT * FROM {$tables['messages']} WHERE ticket_id = %d AND visibility = 'public' AND message_type != 'note' ORDER BY created_at ASC, id ASC",
                $ticketId
            );
        }

        $results = $wpdb->get_results($sql, ARRAY_A) ?: [];

        // Attach attachments to each message.
        $attachmentRepo = new AttachmentRepository();
        foreach ($results as &$msg) {
            $msg['attachments'] = $attachmentRepo->getByMessage((int) $msg['id']);
        }

        return $results;
    }

    /**
     * Delete a single message.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        return (bool) $wpdb->delete($tables['messages'], ['id' => $id], ['%d']);
    }
}
