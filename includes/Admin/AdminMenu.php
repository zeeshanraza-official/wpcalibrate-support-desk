<?php
/**
 * WPCalibrate shared admin parent menu and submenu registration.
 *
 * @package WPCalibrate\SupportDesk\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Admin;

use WPCalibrate\SupportDesk\Auth\RoleManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AdminMenu
 */
final class AdminMenu
{
    public const PARENT_SLUG = 'wpcalibrate';
    public const PAGE_SLUG_DASHBOARD = 'wpcalibrate-support-desk';
    public const PAGE_SLUG_SETTINGS  = 'wpcalibrate-support-desk-settings';

    /**
     * Register admin hooks.
     *
     * @return void
     */
    public static function register(): void
    {
        // Priority 9 allows predictable shared parent registration across WPCalibrate plugins.
        add_action('admin_menu', [self::class, 'registerMenus'], 9);
        add_action('admin_head', [self::class, 'renderMenuIconStyles']);
    }

    /**
     * Register shared parent and plugin submenus.
     *
     * @return void
     */
    public static function registerMenus(): void
    {
        global $admin_page_hooks;

        // Check if top-level WPCalibrate parent menu already exists.
        $parentExists = isset($admin_page_hooks[self::PARENT_SLUG]);

        if (!$parentExists) {
            $iconUrl = WPCALIBRATE_SUPPORT_DESK_URL . 'branding/icon-white.png';

            add_menu_page(
                'WPCalibrate',
                'WPCalibrate',
                RoleManager::CAP_VIEW_TICKETS,
                self::PARENT_SLUG,
                [TicketDashboard::class, 'render'],
                $iconUrl,
                '58.6'
            );
        }

        // Add Support Desk submenu.
        add_submenu_page(
            self::PARENT_SLUG,
            __('Support Desk', 'wpcalibrate-support-desk'),
            __('Support Desk', 'wpcalibrate-support-desk'),
            RoleManager::CAP_VIEW_TICKETS,
            self::PAGE_SLUG_DASHBOARD,
            [TicketDashboard::class, 'render']
        );

        // Add Settings submenu.
        add_submenu_page(
            self::PARENT_SLUG,
            __('Support Desk Settings', 'wpcalibrate-support-desk'),
            __('Settings', 'wpcalibrate-support-desk'),
            RoleManager::CAP_MANAGE_SETTINGS,
            self::PAGE_SLUG_SETTINGS,
            [SettingsPage::class, 'render']
        );

        // If we registered the parent and the default first item matches parent slug, normalize link.
        if (!$parentExists) {
            remove_submenu_page(self::PARENT_SLUG, self::PARENT_SLUG);
        }
    }

    /**
     * CSS to scale and format WPCalibrate menu icon cleanly without distortion.
     *
     * @return void
     */
    public static function renderMenuIconStyles(): void
    {
        $iconUrl = esc_url(WPCALIBRATE_SUPPORT_DESK_URL . 'branding/icon-white.png');
        echo '<style>
            #adminmenu li#toplevel_page_wpcalibrate div.wp-menu-image img {
                display: none !important;
            }
            #adminmenu li#toplevel_page_wpcalibrate div.wp-menu-image:before {
                content: "" !important;
                display: inline-block !important;
                width: 20px !important;
                height: 20px !important;
                padding: 0 !important;
                margin-top: 7px !important;
                background-color: #a7aaad;
                background-color: currentColor;
                -webkit-mask: url("' . $iconUrl . '") no-repeat center / contain !important;
                mask: url("' . $iconUrl . '") no-repeat center / contain !important;
                transition: background-color .15s ease-in-out;
            }
            #adminmenu li#toplevel_page_wpcalibrate:hover div.wp-menu-image:before,
            #adminmenu li#toplevel_page_wpcalibrate.opensub div.wp-menu-image:before,
            #adminmenu li#toplevel_page_wpcalibrate > a:hover div.wp-menu-image:before {
                background-color: #72aee6;
                background-color: currentColor;
            }
            #adminmenu li#toplevel_page_wpcalibrate.wp-has-current-submenu div.wp-menu-image:before,
            #adminmenu li#toplevel_page_wpcalibrate.current div.wp-menu-image:before,
            #adminmenu li#toplevel_page_wpcalibrate.wp-menu-open div.wp-menu-image:before {
                background-color: #ffffff !important;
            }
            @supports not (mask-image: url("")) and not (-webkit-mask-image: url("")) {
                #adminmenu li#toplevel_page_wpcalibrate div.wp-menu-image img {
                    display: inline-block !important;
                    width: 20px !important;
                    height: 20px !important;
                    padding: 0 !important;
                    margin-top: 7px !important;
                    object-fit: contain;
                }
            }
        </style>';
    }
}
