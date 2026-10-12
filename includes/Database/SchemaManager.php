<?php
/**
 * Database schema and table definitions.
 *
 * @package WPCalibrate\SupportDesk\Database
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SchemaManager
 */
final class SchemaManager
{
    public const DB_VERSION_OPTION = 'wpcalibrate_support_desk_db_version';

    /**
     * Get table names with prefix.
     *
     * @return array<string, string>
     */
    public static function getTableNames(): array
    {
        global $wpdb;
        $prefix = $wpdb->prefix;

        return [
            'tickets'     => $prefix . 'wpcalibrate_tickets',
            'messages'    => $prefix . 'wpcalibrate_ticket_messages',
            'activity'    => $prefix . 'wpcalibrate_ticket_activity',
            'attachments' => $prefix . 'wpcalibrate_ticket_attachments',
            'categories'  => $prefix . 'wpcalibrate_ticket_categories',
            'sequences'   => $prefix . 'wpcalibrate_ticket_sequences',
        ];
    }

    /**
     * Create or update database tables via dbDelta.
     *
     * @return void
     */
    public static function install(): void
    {
        global $wpdb;

        $tables = self::getTableNames();
        $charsetCollate = $wpdb->get_charset_collate();

        if (!function_exists('dbDelta')) {
            if (file_exists(ABSPATH . 'wp-admin/includes/upgrade.php')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
        }

        $sql = "
CREATE TABLE {$tables['tickets']} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_key VARCHAR(64) NOT NULL,
    ticket_ref VARCHAR(32) NOT NULL,
    customer_id BIGINT UNSIGNED NULL DEFAULT NULL,
    guest_name VARCHAR(191) NULL DEFAULT NULL,
    guest_email VARCHAR(191) NULL DEFAULT NULL,
    guest_token_hash VARCHAR(128) NULL DEFAULT NULL,
    guest_token_created_at DATETIME NULL DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    priority VARCHAR(32) NOT NULL DEFAULT 'normal',
    category_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
    assigned_agent_id BIGINT UNSIGNED NULL DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    woo_order_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_reply_at DATETIME NOT NULL,
    resolved_at DATETIME NULL DEFAULT NULL,
    closed_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_ticket_ref (ticket_ref),
    KEY idx_ticket_key (ticket_key),
    KEY idx_customer_id (customer_id),
    KEY idx_guest_email (guest_email),
    KEY idx_guest_token_hash (guest_token_hash),
    KEY idx_status (status),
    KEY idx_priority (priority),
    KEY idx_category_id (category_id),
    KEY idx_assigned_agent (assigned_agent_id),
    KEY idx_last_reply_at (last_reply_at),
    KEY idx_created_at (created_at),
    KEY idx_woo_order_id (woo_order_id)
) $charsetCollate;

CREATE TABLE {$tables['messages']} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL DEFAULT NULL,
    author_name VARCHAR(191) NOT NULL,
    author_email VARCHAR(191) NOT NULL,
    message_type VARCHAR(32) NOT NULL DEFAULT 'reply',
    visibility VARCHAR(32) NOT NULL DEFAULT 'public',
    body LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ticket_id (ticket_id),
    KEY idx_message_type (message_type),
    KEY idx_visibility (visibility),
    KEY idx_author_id (author_id),
    KEY idx_created_at (created_at)
) $charsetCollate;

CREATE TABLE {$tables['activity']} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL DEFAULT NULL,
    actor_type VARCHAR(32) NOT NULL DEFAULT 'system',
    action VARCHAR(64) NOT NULL,
    meta_data LONGTEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ticket_id (ticket_id),
    KEY idx_actor_id (actor_id),
    KEY idx_action (action),
    KEY idx_created_at (created_at)
) $charsetCollate;

CREATE TABLE {$tables['attachments']} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    message_id BIGINT UNSIGNED NULL DEFAULT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) NOT NULL,
    download_token VARCHAR(64) NOT NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ticket_id (ticket_id),
    KEY idx_message_id (message_id),
    KEY idx_download_token (download_token),
    KEY idx_created_at (created_at)
) $charsetCollate;

CREATE TABLE {$tables['categories']} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    description TEXT NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    default_agent_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug),
    KEY idx_is_active (is_active),
    KEY idx_sort_order (sort_order)
) $charsetCollate;

CREATE TABLE {$tables['sequences']} (
    sequence_name VARCHAR(64) NOT NULL,
    current_val BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (sequence_name)
) $charsetCollate;
";

        dbDelta($sql);

        self::seedDefaults();

        update_option(self::DB_VERSION_OPTION, WPCALIBRATE_SUPPORT_DESK_DB_VERSION);
    }

    /**
     * Seed initial categories and sequences if not present.
     *
     * @return void
     */
    public static function seedDefaults(): void
    {
        global $wpdb;
        $tables = self::getTableNames();
        $now = current_time('mysql', true);

        // Seed sequence row.
        $existingSequence = $wpdb->get_var(
            $wpdb->prepare("SELECT current_val FROM {$tables['sequences']} WHERE sequence_name = %s", 'tickets')
        );

        if ($existingSequence === null) {
            $wpdb->insert(
                $tables['sequences'],
                [
                    'sequence_name' => 'tickets',
                    'current_val'   => 0,
                ],
                ['%s', '%d']
            );
        }

        // Seed default categories if none exist.
        $categoryCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['categories']}");
        if ($categoryCount === 0) {
            $defaults = [
                [
                    'name'        => __('General Support', 'wpcalibrate-support-desk'),
                    'slug'        => 'general-support',
                    'description' => __('General assistance and inquiry questions.', 'wpcalibrate-support-desk'),
                    'is_active'   => 1,
                    'sort_order'  => 0,
                ],
                [
                    'name'        => __('Technical Support', 'wpcalibrate-support-desk'),
                    'slug'        => 'technical-support',
                    'description' => __('Technical issues, bug reports, and troubleshooting.', 'wpcalibrate-support-desk'),
                    'is_active'   => 1,
                    'sort_order'  => 1,
                ],
                [
                    'name'        => __('Billing & Orders', 'wpcalibrate-support-desk'),
                    'slug'        => 'billing-orders',
                    'description' => __('Invoices, payment questions, and order inquiries.', 'wpcalibrate-support-desk'),
                    'is_active'   => 1,
                    'sort_order'  => 2,
                ],
            ];

            foreach ($defaults as $cat) {
                $wpdb->insert(
                    $tables['categories'],
                    [
                        'name'             => $cat['name'],
                        'slug'             => $cat['slug'],
                        'description'      => $cat['description'],
                        'is_active'        => $cat['is_active'],
                        'sort_order'       => $cat['sort_order'],
                        'default_agent_id' => null,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ],
                    ['%s', '%s', '%s', '%d', '%d', null, '%s', '%s']
                );
            }
        }
    }

    /**
     * Drop plugin custom tables during explicit destructive uninstall.
     *
     * @return void
     */
    public static function dropTables(): void
    {
        global $wpdb;
        $tables = self::getTableNames();

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::DB_VERSION_OPTION);
    }
}
