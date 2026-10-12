<?php
/**
 * Safe diagnostic logging abstraction.
 *
 * @package WPCalibrate\SupportDesk\Support
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Logger
 */
final class Logger
{
    /**
     * Log a message safely without leaking sensitive information.
     *
     * @param string $level 'debug'|'info'|'warning'|'error'
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        // Debug logs only if WP_DEBUG is enabled.
        if ($level === 'debug' && (!defined('WP_DEBUG') || !WP_DEBUG)) {
            return;
        }

        $safeContext = self::redactSensitiveData($context);
        $contextString = !empty($safeContext) ? ' | Context: ' . wp_json_encode($safeContext) : '';

        $entry = sprintf(
            '[WPCalibrate Support Desk][%s] %s%s',
            strtoupper($level),
            $message,
            $contextString
        );

        // If WooCommerce logger is available, write to WC log file.
        if (function_exists('wc_get_logger')) {
            $wcLogger = wc_get_logger();
            if ($wcLogger) {
                $wcLogger->log($level, $message, array_merge(['source' => 'wpcalibrate-support-desk'], $safeContext));
                return;
            }
        }

        // Standard WordPress debug log.
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($entry);
        }
    }

    /**
     * Redact sensitive keys from context.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function redactSensitiveData(array $context): array
    {
        $sensitiveKeys = [
            'password', 'token', 'guest_token', 'license', 'license_key',
            'secret', 'cookie', 'auth', 'card', 'cvv', 'key'
        ];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            foreach ($sensitiveKeys as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $context[$key] = '***REDACTED***';
                    break;
                }
            }

            if (is_array($value)) {
                $context[$key] = self::redactSensitiveData($value);
            }
        }

        return $context;
    }
}
