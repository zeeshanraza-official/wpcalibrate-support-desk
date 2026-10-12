<?php
/**
 * Versioned schema migrations runner.
 *
 * @package WPCalibrate\SupportDesk\Database
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Migrations
 */
final class Migrations
{
    /**
     * Check and run any pending migrations.
     *
     * @return void
     */
    public static function checkAndMigrate(): void
    {
        $currentDbVersion = get_option(SchemaManager::DB_VERSION_OPTION, '0.0.0');

        if (version_compare((string) $currentDbVersion, WPCALIBRATE_SUPPORT_DESK_DB_VERSION, '<')) {
            self::runMigrations((string) $currentDbVersion);
        }
    }

    /**
     * Run sequential migrations.
     *
     * @param string $installedVersion Currently installed schema version.
     * @return void
     */
    private static function runMigrations(string $installedVersion): void
    {
        // For version 1.0.0, running SchemaManager::install() ensures dbDelta applies any schema changes idempotently.
        SchemaManager::install();

        update_option(SchemaManager::DB_VERSION_OPTION, WPCALIBRATE_SUPPORT_DESK_DB_VERSION);
    }
}
