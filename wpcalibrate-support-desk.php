<?php
/**
 * Plugin Name: WPCalibrate Support Desk
 * Plugin URI: https://marketplace.wpcalibrate.com/
 * Description: Complete free customer support ticketing system for WordPress. Professional helpdesk directly inside WordPress with guest access, internal notes, notifications, and optional WooCommerce integration.
 * Version: 1.0.0
 * Author: WPCalibrate
 * Author URI: https://wpcalibrate.com/
 * Text Domain: wpcalibrate-support-desk
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WPCalibrate\SupportDesk
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Define Plugin Constants.
define('WPCALIBRATE_SUPPORT_DESK_VERSION', '1.0.0');
define('WPCALIBRATE_SUPPORT_DESK_DB_VERSION', '1.0.0');
define('WPCALIBRATE_SUPPORT_DESK_FILE', __FILE__);
define('WPCALIBRATE_SUPPORT_DESK_PATH', plugin_dir_path(__FILE__));
define('WPCALIBRATE_SUPPORT_DESK_URL', plugin_dir_url(__FILE__));
define('WPCALIBRATE_SUPPORT_DESK_BASENAME', plugin_basename(__FILE__));

// Autoloader.
require_once WPCALIBRATE_SUPPORT_DESK_PATH . 'includes/Autoloader.php';
\WPCalibrate\SupportDesk\Autoloader::register(WPCALIBRATE_SUPPORT_DESK_PATH . 'includes');

// Declare WooCommerce HPOS Compatibility early.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// Activation & Deactivation Hooks.
register_activation_hook(__FILE__, static function (): void {
    if (!\WPCalibrate\SupportDesk\Support\Requirements::check()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('WPCalibrate Support Desk cannot be activated because server requirements are not met (PHP 8.2+ and WordPress 6.5+ required).', 'wpcalibrate-support-desk'),
            esc_html__('Plugin Activation Error', 'wpcalibrate-support-desk'),
            ['back_link' => true]
        );
    }
    \WPCalibrate\SupportDesk\Plugin::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    \WPCalibrate\SupportDesk\Plugin::deactivate();
});

// Environmental requirements check before main initialization.
if (!\WPCalibrate\SupportDesk\Support\Requirements::check()) {
    add_action('admin_notices', [\WPCalibrate\SupportDesk\Support\Requirements::class, 'renderAdminNotice']);
    return;
}

// Bootstrap plugin instance.
add_action('plugins_loaded', static function (): void {
    \WPCalibrate\SupportDesk\Plugin::getInstance()->init();
});
