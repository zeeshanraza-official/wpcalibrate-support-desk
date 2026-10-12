<?php
/**
 * WPCalibrate Support Desk Uninstall Handler.
 *
 * @package WPCalibrate\SupportDesk
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 1. Check if destructive deletion was explicitly enabled by the administrator.
$deleteData = (bool) get_option('wpcalibrate_uninstall_delete_data', false);

if (!$deleteData) {
    // Default safe behavior: preserve all tickets, settings, and tables.
    return;
}

// 2. Load autoloader to access schema and security managers.
require_once __DIR__ . '/includes/Autoloader.php';
\WPCalibrate\SupportDesk\Autoloader::register(__DIR__ . '/includes');

// 3. Drop custom database tables.
\WPCalibrate\SupportDesk\Database\SchemaManager::dropTables();

// 4. Remove plugin options.
$optionsToDelete = [
    'wpcalibrate_support_desk_db_version',
    'wpcalibrate_ticket_prefix',
    'wpcalibrate_default_priority',
    'wpcalibrate_customer_priority_selection',
    'wpcalibrate_guest_tickets_enabled',
    'wpcalibrate_guest_token_expiry_days',
    'wpcalibrate_allow_customer_close',
    'wpcalibrate_allow_customer_reopen',
    'wpcalibrate_customer_reopen_days',
    'wpcalibrate_records_per_page',
    'wpcalibrate_support_portal_page_id',
    'wpcalibrate_default_department',
    'wpcalibrate_email_from_name',
    'wpcalibrate_email_from_address',
    'wpcalibrate_admin_notification_email',
    'wpcalibrate_email_customer_ticket_created',
    'wpcalibrate_email_admin_new_ticket',
    'wpcalibrate_email_customer_agent_reply',
    'wpcalibrate_email_agent_customer_reply',
    'wpcalibrate_email_agent_assigned',
    'wpcalibrate_email_status_change',
    'wpcalibrate_attachments_enabled',
    'wpcalibrate_max_attachment_size_mb',
    'wpcalibrate_woo_integration_enabled',
    'wpcalibrate_privacy_erase_action',
    'wpcalibrate_uninstall_delete_data',
    'wpcalibrate_license_key',
];

foreach ($optionsToDelete as $option) {
    delete_option($option);
}

// 5. Remove transients.
delete_transient('wpcalibrate_stats_summary');

// 6. Remove plugin roles & capabilities safely.
\WPCalibrate\SupportDesk\Auth\RoleManager::removeRolesAndCapabilities();

// 7. Remove protected attachments directory and files (only plugin-owned files).
$uploadDir = wp_upload_dir();
$storageDir = trailingslashit($uploadDir['basedir']) . 'wpcalibrate-support-desk';

if (is_dir($storageDir)) {
    $files = glob($storageDir . '/*');
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    $htaccess = $storageDir . '/.htaccess';
    if (file_exists($htaccess)) {
        @unlink($htaccess);
    }
    @rmdir($storageDir);
}
