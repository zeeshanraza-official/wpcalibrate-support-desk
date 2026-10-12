<?php
/**
 * Role and capability management.
 *
 * @package WPCalibrate\SupportDesk\Auth
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Auth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RoleManager
 */
final class RoleManager
{
    public const ROLE_AGENT = 'support_agent';

    public const CAP_VIEW_TICKETS          = 'wpcalibrate_view_tickets';
    public const CAP_VIEW_ASSIGNED_TICKETS = 'wpcalibrate_view_assigned_tickets';
    public const CAP_VIEW_ALL_TICKETS      = 'wpcalibrate_view_all_tickets';
    public const CAP_REPLY_TICKETS         = 'wpcalibrate_reply_tickets';
    public const CAP_ADD_INTERNAL_NOTES    = 'wpcalibrate_add_internal_notes';
    public const CAP_ASSIGN_TICKETS        = 'wpcalibrate_assign_tickets';
    public const CAP_CHANGE_STATUS         = 'wpcalibrate_change_status';
    public const CAP_CHANGE_PRIORITY       = 'wpcalibrate_change_priority';
    public const CAP_MANAGE_CATEGORIES     = 'wpcalibrate_manage_categories';
    public const CAP_MANAGE_AGENTS         = 'wpcalibrate_manage_agents';
    public const CAP_MANAGE_SETTINGS       = 'wpcalibrate_manage_settings';
    public const CAP_MANAGE_LICENSES       = 'wpcalibrate_manage_licenses';
    public const CAP_DELETE_TICKETS        = 'wpcalibrate_delete_tickets';
    public const CAP_VIEW_WOO_ORDER        = 'wpcalibrate_view_woo_order';

    /**
     * Get all plugin-defined capabilities.
     *
     * @return array<string>
     */
    public static function getAllCapabilities(): array
    {
        return [
            self::CAP_VIEW_TICKETS,
            self::CAP_VIEW_ASSIGNED_TICKETS,
            self::CAP_VIEW_ALL_TICKETS,
            self::CAP_REPLY_TICKETS,
            self::CAP_ADD_INTERNAL_NOTES,
            self::CAP_ASSIGN_TICKETS,
            self::CAP_CHANGE_STATUS,
            self::CAP_CHANGE_PRIORITY,
            self::CAP_MANAGE_CATEGORIES,
            self::CAP_MANAGE_AGENTS,
            self::CAP_MANAGE_SETTINGS,
            self::CAP_MANAGE_LICENSES,
            self::CAP_DELETE_TICKETS,
            self::CAP_VIEW_WOO_ORDER,
        ];
    }

    /**
     * Get default capabilities for Support Agent.
     *
     * @return array<string, bool>
     */
    public static function getAgentCapabilities(): array
    {
        return [
            'read'                         => true,
            self::CAP_VIEW_TICKETS          => true,
            self::CAP_VIEW_ASSIGNED_TICKETS => true,
            self::CAP_VIEW_ALL_TICKETS      => true,
            self::CAP_REPLY_TICKETS         => true,
            self::CAP_ADD_INTERNAL_NOTES    => true,
            self::CAP_ASSIGN_TICKETS        => true,
            self::CAP_CHANGE_STATUS         => true,
            self::CAP_CHANGE_PRIORITY       => true,
            self::CAP_VIEW_WOO_ORDER        => true,
        ];
    }

    /**
     * Register or update roles and capabilities on activation.
     *
     * @return void
     */
    public static function setupRoles(): void
    {
        // 1. Create or update Support Agent role.
        $agentRole = get_role(self::ROLE_AGENT);
        if (!$agentRole) {
            add_role(
                self::ROLE_AGENT,
                __('Support Agent', 'wpcalibrate-support-desk'),
                self::getAgentCapabilities()
            );
        } else {
            foreach (self::getAgentCapabilities() as $cap => $grant) {
                if ($grant) {
                    $agentRole->add_cap($cap);
                }
            }
        }

        // 2. Grant all capabilities to Administrator.
        $adminRole = get_role('administrator');
        if ($adminRole) {
            foreach (self::getAllCapabilities() as $cap) {
                $adminRole->add_cap($cap);
            }
        }
    }

    /**
     * Remove custom capabilities on uninstall (if requested).
     *
     * @return void
     */
    public static function removeRolesAndCapabilities(): void
    {
        // Remove caps from administrator.
        $adminRole = get_role('administrator');
        if ($adminRole) {
            foreach (self::getAllCapabilities() as $cap) {
                $adminRole->remove_cap($cap);
            }
        }

        // Remove Support Agent role.
        remove_role(self::ROLE_AGENT);
    }
}
