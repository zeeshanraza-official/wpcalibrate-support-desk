<?php
/**
 * Main plugin orchestrator singleton.
 *
 * @package WPCalibrate\SupportDesk
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk;

use WPCalibrate\SupportDesk\Database\SchemaManager;
use WPCalibrate\SupportDesk\Database\Migrations;
use WPCalibrate\SupportDesk\Auth\RoleManager;
use WPCalibrate\SupportDesk\Admin\AdminMenu;
use WPCalibrate\SupportDesk\Api\RestApiController;
use WPCalibrate\SupportDesk\Frontend\FrontendPortal;
use WPCalibrate\SupportDesk\Blocks\BlockRegistrar;
use WPCalibrate\SupportDesk\Email\EmailNotificationService;
use WPCalibrate\SupportDesk\Privacy\PrivacyManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Plugin
 */
final class Plugin
{
    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Activation routine.
     *
     * @return void
     */
    public static function activate(): void
    {
        SchemaManager::install();
        RoleManager::setupRoles();

        // Seed default options if not present.
        if (get_option('wpcalibrate_ticket_prefix') === false) {
            update_option('wpcalibrate_ticket_prefix', 'WPC');
        }
        if (get_option('wpcalibrate_default_priority') === false) {
            update_option('wpcalibrate_default_priority', 'normal');
        }
        if (get_option('wpcalibrate_guest_tickets_enabled') === false) {
            update_option('wpcalibrate_guest_tickets_enabled', '1');
        }
        if (get_option('wpcalibrate_allow_customer_close') === false) {
            update_option('wpcalibrate_allow_customer_close', '1');
        }
        if (get_option('wpcalibrate_allow_customer_reopen') === false) {
            update_option('wpcalibrate_allow_customer_reopen', '1');
        }
        if (get_option('wpcalibrate_attachments_enabled') === false) {
            update_option('wpcalibrate_attachments_enabled', '1');
        }
        if (get_option('wpcalibrate_uninstall_delete_data') === false) {
            update_option('wpcalibrate_uninstall_delete_data', '0'); // Default safe: preserve data on uninstall
        }
    }

    /**
     * Deactivation routine.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        // Data is preserved by default.
        delete_transient('wpcalibrate_stats_summary');
    }

    /**
     * Main plugin initialization on plugins_loaded.
     *
     * @return void
     */
    public function init(): void
    {
        // 1. Safe migrations check.
        Migrations::checkAndMigrate();

        // 2. Admin menus & assets.
        AdminMenu::register();
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // 3. REST API.
        add_action('rest_api_init', static function (): void {
            $controller = new RestApiController();
            $controller->registerRoutes();
        });

        // 4. Frontend portal & blocks.
        FrontendPortal::register();
        BlockRegistrar::register();

        // 5. Email notifications.
        EmailNotificationService::register();

        // 6. Privacy tools (GDPR exporter/eraser).
        PrivacyManager::register();

        // 7. Load text domain for localization.
        load_plugin_textdomain(
            'wpcalibrate-support-desk',
            false,
            dirname(WPCALIBRATE_SUPPORT_DESK_BASENAME) . '/languages'
        );
    }

    /**
     * Enqueue admin assets only on this plugin's administrative screens.
     *
     * @param string $hookSuffix
     * @return void
     */
    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if (!str_contains($hookSuffix, 'wpcalibrate-support-desk')) {
            return;
        }

        wp_enqueue_style(
            'wpcalibrate-admin-style',
            WPCALIBRATE_SUPPORT_DESK_URL . 'assets/css/admin.css',
            [],
            WPCALIBRATE_SUPPORT_DESK_VERSION
        );

        wp_enqueue_script(
            'wpcalibrate-admin-script',
            WPCALIBRATE_SUPPORT_DESK_URL . 'assets/js/admin.js',
            ['jquery'],
            WPCALIBRATE_SUPPORT_DESK_VERSION,
            true
        );
    }
}
