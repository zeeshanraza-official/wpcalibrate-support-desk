<?php
/**
 * Protected attachment storage, validation, and delivery.
 *
 * @package WPCalibrate\SupportDesk\Security
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Security;

use WPCalibrate\SupportDesk\Database\SchemaManager;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AttachmentSecurity
 */
final class AttachmentSecurity
{
    /**
     * Default conservative allowlist of extensions and MIME types.
     */
    public const DEFAULT_ALLOWED_MIMES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'zip'  => 'application/zip',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /**
     * Get or create protected storage directory.
     *
     * @return string Absolute directory path.
     */
    public static function getStorageDirectory(): string
    {
        $uploadDir = wp_upload_dir();
        $baseDir = trailingslashit($uploadDir['basedir']) . 'wpcalibrate-support-desk';

        if (!file_exists($baseDir)) {
            wp_mkdir_p($baseDir);

            // Place .htaccess to deny direct web access.
            $htaccessFile = $baseDir . '/.htaccess';
            if (!file_exists($htaccessFile)) {
                $rules = "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
                file_put_contents($htaccessFile, $rules);
            }

            // Place empty index.php.
            $indexFile = $baseDir . '/index.php';
            if (!file_exists($indexFile)) {
                file_put_contents($indexFile, "<?php\n// Silence is golden.\n");
            }
        }

        return $baseDir;
    }

    /**
     * Check if attachments are globally enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool) get_option('wpcalibrate_attachments_enabled', '1');
    }

    /**
     * Get maximum allowed file size in bytes.
     *
     * @return int
     */
    public static function getMaxFileSize(): int
    {
        $maxMb = (int) get_option('wpcalibrate_max_attachment_size_mb', 5);
        $serverMax = wp_max_upload_size();

        return min($maxMb * 1024 * 1024, $serverMax);
    }

    /**
     * Get allowed extensions list.
     *
     * @return array<string, string>
     */
    public static function getAllowedMimes(): array
    {
        $mimes = self::DEFAULT_ALLOWED_MIMES;
        return (array) apply_filters('wpcalibrate_allowed_attachment_mimes', $mimes);
    }

    /**
     * Validate an uploaded file from $_FILES.
     *
     * @param array<string, mixed> $file PHP upload item.
     * @return true|WP_Error
     */
    public static function validateUpload(array $file): true|WP_Error
    {
        if (!self::isEnabled()) {
            return new WP_Error('attachments_disabled', __('Attachments are disabled on this helpdesk.', 'wpcalibrate-support-desk'));
        }

        if (empty($file['tmp_name']) || (!is_uploaded_file($file['tmp_name']) && !defined('WPCALIBRATE_TESTING'))) {
            return new WP_Error('invalid_upload', __('No valid file was uploaded.', 'wpcalibrate-support-desk'));
        }

        if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', __('Upload error code: ', 'wpcalibrate-support-desk') . (int) $file['error']);
        }

        // File size check.
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::getMaxFileSize()) {
            $maxMb = round(self::getMaxFileSize() / (1024 * 1024), 1);
            return new WP_Error(
                'file_too_large',
                sprintf(
                    /* translators: %s: maximum file size in megabytes */
                    __('File exceeds maximum allowed size of %s MB.', 'wpcalibrate-support-desk'),
                    $maxMb
                )
            );
        }

        // Extension and MIME verification.
        $filename = sanitize_file_name($file['name'] ?? '');
        if (empty($filename)) {
            return new WP_Error('invalid_filename', __('Invalid file name.', 'wpcalibrate-support-desk'));
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedMimes = self::getAllowedMimes();

        if (!array_key_exists($extension, $allowedMimes)) {
            return new WP_Error('disallowed_file_type', __('File type not allowed.', 'wpcalibrate-support-desk'));
        }

        // Real MIME type check using finfo.
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $realMime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                $expectedMime = $allowedMimes[$extension];
                if ($realMime !== $expectedMime && !self::isCompatibleMime($extension, (string) $realMime)) {
                    return new WP_Error('mime_mismatch', __('File content does not match its extension.', 'wpcalibrate-support-desk'));
                }
            }
        }

        return true;
    }

    /**
     * Check compatible MIME aliases (e.g. text/plain for CSV, image/x-png).
     *
     * @param string $extension
     * @param string $realMime
     * @return bool
     */
    private static function isCompatibleMime(string $extension, string $realMime): bool
    {
        $aliases = [
            'csv'  => ['text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values'],
            'txt'  => ['text/plain'],
            'jpg'  => ['image/jpeg', 'image/pjpeg'],
            'jpeg' => ['image/jpeg', 'image/pjpeg'],
            'png'  => ['image/png', 'image/x-png'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        ];

        return isset($aliases[$extension]) && in_array($realMime, $aliases[$extension], true);
    }

    /**
     * Save an uploaded file securely into the protected directory and record in database.
     *
     * @param array<string, mixed> $file Upload item from $_FILES.
     * @param int $ticketId Target ticket ID.
     * @param int|null $messageId Target message ID if attached to message.
     * @param int $userId Current user ID (0 for guest).
     * @return array<string, mixed>|WP_Error
     */
    public static function saveUploadedFile(array $file, int $ticketId, ?int $messageId = null, int $userId = 0): array|WP_Error
    {
        $check = self::validateUpload($file);
        if (is_wp_error($check)) {
            return $check;
        }

        $storageDir = self::getStorageDirectory();
        $originalName = sanitize_file_name($file['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Generate unpredictable unique disk filename to prevent collisions and guesses.
        $diskName = sprintf('t%d_%s.%s', $ticketId, bin2hex(random_bytes(16)), $ext);
        $destination = trailingslashit($storageDir) . $diskName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return new WP_Error('storage_error', __('Could not move uploaded file to protected storage.', 'wpcalibrate-support-desk'));
        }

        // Set safe file permissions.
        chmod($destination, 0640);

        global $wpdb;
        $tables = SchemaManager::getTableNames();
        $token = bin2hex(random_bytes(32));
        $now = current_time('mysql', true);
        $mimes = self::getAllowedMimes();
        $mime = $mimes[$ext] ?? 'application/octet-stream';
        $size = filesize($destination) ?: (int) $file['size'];

        $inserted = $wpdb->insert(
            $tables['attachments'],
            [
                'ticket_id'      => $ticketId,
                'message_id'     => $messageId,
                'file_name'      => $originalName,
                'file_path'      => $diskName, // Store relative disk name
                'file_size'      => $size,
                'mime_type'      => $mime,
                'download_token' => $token,
                'download_count' => 0,
                'uploaded_by'    => $userId > 0 ? $userId : null,
                'created_at'     => $now,
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s']
        );

        if (!$inserted) {
            @unlink($destination);
            return new WP_Error('db_error', __('Failed to record attachment in database.', 'wpcalibrate-support-desk'));
        }

        $attachmentId = (int) $wpdb->insert_id;

        return [
            'id'             => $attachmentId,
            'ticket_id'      => $ticketId,
            'message_id'     => $messageId,
            'file_name'      => $originalName,
            'file_size'      => $size,
            'mime_type'      => $mime,
            'download_token' => $token,
            'download_url'   => self::getDownloadUrl($attachmentId, $token),
        ];
    }

    /**
     * Generate secure download URL for an attachment.
     *
     * @param int $attachmentId
     * @param string $token
     * @return string
     */
    public static function getDownloadUrl(int $attachmentId, string $token): string
    {
        return rest_url("wpcalibrate-support-desk/v1/attachments/{$attachmentId}/download?token=" . rawurlencode($token));
    }

    /**
     * Stream protected file to output after authorization check.
     *
     * @param int $attachmentId
     * @param string|null $guestToken
     * @param string|null $downloadToken
     * @return void
     */
    public static function streamDownload(int $attachmentId, ?string $guestToken = null, ?string $downloadToken = null): void
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $attachment = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['attachments']} WHERE id = %d", $attachmentId),
            ARRAY_A
        );

        if (!$attachment) {
            wp_die(esc_html__('Attachment not found.', 'wpcalibrate-support-desk'), 404);
        }

        // Verify download token if passed.
        if (!empty($downloadToken) && !hash_equals((string) $attachment['download_token'], $downloadToken)) {
            wp_die(esc_html__('Invalid attachment token.', 'wpcalibrate-support-desk'), 403);
        }

        $ticket = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['tickets']} WHERE id = %d", (int) $attachment['ticket_id']),
            ARRAY_A
        );

        if (!$ticket) {
            wp_die(esc_html__('Ticket not found.', 'wpcalibrate-support-desk'), 404);
        }

        $message = null;
        if (!empty($attachment['message_id'])) {
            $message = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$tables['messages']} WHERE id = %d", (int) $attachment['message_id']),
                ARRAY_A
            );
        }

        $userId = get_current_user_id();

        if (!AccessPolicy::canDownloadAttachment($attachment, $ticket, $message, $userId, $guestToken)) {
            wp_die(esc_html__('You do not have permission to download this attachment.', 'wpcalibrate-support-desk'), 403);
        }

        $storageDir = self::getStorageDirectory();
        $filePath = trailingslashit($storageDir) . basename($attachment['file_path']);

        if (!file_exists($filePath)) {
            wp_die(esc_html__('Attachment file not found on disk.', 'wpcalibrate-support-desk'), 404);
        }

        // Increment download counter.
        $wpdb->query(
            $wpdb->prepare("UPDATE {$tables['attachments']} SET download_count = download_count + 1 WHERE id = %d", $attachmentId)
        );

        // Safe streaming headers.
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Content-Type: ' . (!empty($attachment['mime_type']) ? $attachment['mime_type'] : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($attachment['file_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Content-Transfer-Encoding: binary');

        // Clean buffer and readfile.
        while (ob_get_level()) {
            ob_end_clean();
        }

        readfile($filePath);
        exit;
    }
}
