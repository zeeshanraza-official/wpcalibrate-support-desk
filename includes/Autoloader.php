<?php
/**
 * PSR-4 compatible autoloader for WPCalibrate Support Desk.
 *
 * @package WPCalibrate\SupportDesk
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Autoloader
 */
final class Autoloader
{
    /**
     * Prefix namespace.
     */
    private const PREFIX = 'WPCalibrate\\SupportDesk\\';

    /**
     * Base directory for classes.
     *
     * @var string
     */
    private static string $baseDir = '';

    /**
     * Register autoloader.
     *
     * @param string $baseDir Base directory path.
     * @return void
     */
    public static function register(string $baseDir): void
    {
        self::$baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        spl_autoload_register([self::class, 'loadClass']);
    }

    /**
     * Autoload callback.
     *
     * @param string $class Fully-qualified class name.
     * @return void
     */
    public static function loadClass(string $class): void
    {
        $prefixLength = strlen(self::PREFIX);

        if (strncmp(self::PREFIX, $class, $prefixLength) !== 0) {
            return;
        }

        $relativeClass = substr($class, $prefixLength);
        $file = self::$baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
