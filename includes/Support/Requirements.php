<?php
/**
 * Environmental and requirements checker.
 *
 * @package WPCalibrate\SupportDesk\Support
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Requirements
 */
final class Requirements
{
    public const MINIMUM_PHP_VERSION = '8.2.0';
    public const MINIMUM_WP_VERSION = '6.5.0';

    /**
     * Check if system meets minimum requirements.
     *
     * @return bool
     */
    public static function check(): bool
    {
        return self::isPhpCompatible() && self::isWpCompatible();
    }

    /**
     * Check PHP version compatibility.
     *
     * @return bool
     */
    public static function isPhpCompatible(): bool
    {
        return version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=');
    }

    /**
     * Check WordPress version compatibility.
     *
     * @return bool
     */
    public static function isWpCompatible(): bool
    {
        global $wp_version;

        if (empty($wp_version)) {
            return true;
        }

        return version_compare($wp_version, self::MINIMUM_WP_VERSION, '>=');
    }

    /**
     * Render admin notice when requirements fail.
     *
     * @return void
     */
    public static function renderAdminNotice(): void
    {
        if (self::check()) {
            return;
        }

        if (!current_user_can('activate_plugins')) {
            return;
        }

        $messages = [];

        if (!self::isPhpCompatible()) {
            $messages[] = sprintf(
                /* translators: 1: Current PHP version, 2: Required PHP version */
                esc_html__('WPCalibrate Support Desk requires PHP %2$s or higher. Your server is running PHP %1$s.', 'wpcalibrate-support-desk'),
                esc_html(PHP_VERSION),
                esc_html(self::MINIMUM_PHP_VERSION)
            );
        }

        if (!self::isWpCompatible()) {
            global $wp_version;
            $messages[] = sprintf(
                /* translators: 1: Current WP version, 2: Required WP version */
                esc_html__('WPCalibrate Support Desk requires WordPress %2$s or higher. Your site is running WordPress %1$s.', 'wpcalibrate-support-desk'),
                esc_html($wp_version ?? 'unknown'),
                esc_html(self::MINIMUM_WP_VERSION)
            );
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s:</strong><br>%s</p></div>',
            esc_html__('WPCalibrate Support Desk Requirement Warning', 'wpcalibrate-support-desk'),
            implode('<br>', $messages) // escaped above
        );
    }
}
