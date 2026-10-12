<?php
/**
 * Licensing service abstraction.
 *
 * @package WPCalibrate\SupportDesk\Licensing
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Licensing;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class LicensingService
 */
final class LicensingService
{
    public const OPTION_KEY = 'wpcalibrate_license_key';
    public const STATUS_UNCONFIGURED = 'unconfigured';

    /**
     * Get saved license key.
     *
     * @return string
     */
    public static function getLicenseKey(): string
    {
        return (string) get_option(self::OPTION_KEY, '');
    }

    /**
     * Get masked representation of license key for secure UI display.
     *
     * @return string
     */
    public static function getMaskedKey(): string
    {
        $key = self::getLicenseKey();
        if (empty($key)) {
            return '';
        }

        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        $prefix = substr($key, 0, 4);
        $suffix = substr($key, -4);
        $maskedLength = max(4, $length - 8);

        return $prefix . '-' . str_repeat('•', $maskedLength) . '-' . $suffix;
    }

    /**
     * Get current licensing status.
     *
     * @return string
     */
    public static function getStatus(): string
    {
        // Unconfigured status: no provider API details provided in specification.
        return self::STATUS_UNCONFIGURED;
    }

    /**
     * Get human-readable status message for UI.
     *
     * @return string
     */
    public static function getStatusMessage(): string
    {
        return __('Licensing provider not configured', 'wpcalibrate-support-desk');
    }

    /**
     * Save license key locally.
     *
     * @param string $key
     * @return bool
     */
    public static function saveLicenseKey(string $key): bool
    {
        $sanitized = sanitize_text_field(trim($key));
        return update_option(self::OPTION_KEY, $sanitized);
    }

    /**
     * Attempt activation.
     * Fails safely because no provider API endpoint is configured.
     *
     * @return WP_Error
     */
    public static function activate(): WP_Error
    {
        return new WP_Error(
            'provider_not_configured',
            __('Cannot activate license: Licensing provider API has not been configured.', 'wpcalibrate-support-desk')
        );
    }

    /**
     * Attempt deactivation.
     *
     * @return WP_Error
     */
    public static function deactivate(): WP_Error
    {
        delete_option(self::OPTION_KEY);

        return new WP_Error(
            'provider_not_configured',
            __('License key removed locally. Licensing provider API is not configured.', 'wpcalibrate-support-desk')
        );
    }
}
