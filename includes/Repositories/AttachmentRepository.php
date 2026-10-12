<?php
/**
 * Attachment database repository.
 *
 * @package WPCalibrate\SupportDesk\Repositories
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Repositories;

use WPCalibrate\SupportDesk\Database\SchemaManager;
use WPCalibrate\SupportDesk\Security\AttachmentSecurity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AttachmentRepository
 */
final class AttachmentRepository
{
    /**
     * Get attachment by ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['attachments']} WHERE id = %d", $id),
            ARRAY_A
        );

        if ($row) {
            $row['download_url'] = AttachmentSecurity::getDownloadUrl((int) $row['id'], (string) $row['download_token']);
        }

        return $row ?: null;
    }

    /**
     * Get attachments by message ID.
     *
     * @param int $messageId
     * @return array<int, array<string, mixed>>
     */
    public function getByMessage(int $messageId): array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$tables['attachments']} WHERE message_id = %d ORDER BY id ASC", $messageId),
            ARRAY_A
        ) ?: [];

        foreach ($rows as &$row) {
            $row['download_url'] = AttachmentSecurity::getDownloadUrl((int) $row['id'], (string) $row['download_token']);
        }

        return $rows;
    }

    /**
     * Get all attachments for a ticket, optionally filtering out internal note attachments.
     *
     * @param int $ticketId
     * @param bool $includeInternal
     * @return array<int, array<string, mixed>>
     */
    public function getByTicket(int $ticketId, bool $includeInternal = false): array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        if ($includeInternal) {
            $sql = $wpdb->prepare(
                "SELECT a.* FROM {$tables['attachments']} a WHERE a.ticket_id = %d ORDER BY a.id ASC",
                $ticketId
            );
        } else {
            // Join with messages to exclude attachments tied to internal notes.
            $sql = $wpdb->prepare(
                "SELECT a.* FROM {$tables['attachments']} a
                 LEFT JOIN {$tables['messages']} m ON a.message_id = m.id
                 WHERE a.ticket_id = %d AND (m.visibility = 'public' OR a.message_id IS NULL)
                 ORDER BY a.id ASC",
                $ticketId
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        foreach ($rows as &$row) {
            $row['download_url'] = AttachmentSecurity::getDownloadUrl((int) $row['id'], (string) $row['download_token']);
        }

        return $rows;
    }

    /**
     * Delete an attachment from database and disk.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $row = $this->getById($id);
        if (!$row) {
            return false;
        }

        $storageDir = AttachmentSecurity::getStorageDirectory();
        $filePath = trailingslashit($storageDir) . basename($row['file_path']);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        return (bool) $wpdb->delete($tables['attachments'], ['id' => $id], ['%d']);
    }
}
