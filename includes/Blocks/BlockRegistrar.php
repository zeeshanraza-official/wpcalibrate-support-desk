<?php
/**
 * Gutenberg Block Registrar.
 *
 * @package WPCalibrate\SupportDesk\Blocks
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Blocks;

use WPCalibrate\SupportDesk\Frontend\FrontendPortal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BlockRegistrar
 */
final class BlockRegistrar
{
    /**
     * Register block types on init.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('init', [self::class, 'registerBlocks']);
    }

    /**
     * Register support portal block.
     *
     * @return void
     */
    public static function registerBlocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        // Register editor script.
        wp_register_script(
            'wpcalibrate-block-editor',
            WPCALIBRATE_SUPPORT_DESK_URL . 'blocks/support-portal/index.js',
            ['wp-blocks', 'wp-element', 'wp-i18n'],
            WPCALIBRATE_SUPPORT_DESK_VERSION,
            true
        );

        $blockJson = WPCALIBRATE_SUPPORT_DESK_PATH . 'blocks/support-portal/block.json';

        if (file_exists($blockJson)) {
            register_block_type($blockJson, [
                'render_callback' => [self::class, 'renderBlock'],
            ]);
        }
    }

    /**
     * Server-side render callback for block.
     *
     * @param array<string, mixed> $attributes
     * @param string $content
     * @return string
     */
    public static function renderBlock(array $attributes = [], string $content = ''): string
    {
        return FrontendPortal::renderShortcode($attributes);
    }
}
