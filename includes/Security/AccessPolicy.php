<?php
/**
 * Authorization and access policy rules.
 *
 * @package WPCalibrate\SupportDesk\Security
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Security;

use WPCalibrate\SupportDesk\Auth\RoleManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AccessPolicy
 */
final class AccessPolicy
{
    /**
     * Check if user/guest can view the given ticket.
     *
     * @param array<string, mixed> $ticket Ticket record array.
     * @param int $userId Current user ID (0 for guest).
     * @param string|null $guestToken Plain guest token if provided.
     * @return bool
     */
    public static function canViewTicket(array $ticket, int $userId = 0, ?string $guestToken = null): bool
    {
        if ($userId <= 0) {
            $userId = get_current_user_id();
        }

        // 1. Check Agent / Admin capabilities.
        if ($userId > 0) {
            if (user_can($userId, RoleManager::CAP_VIEW_ALL_TICKETS)) {
                return true;
            }

            if (user_can($userId, RoleManager::CAP_VIEW_ASSIGNED_TICKETS)) {
                $assignedId = isset($ticket['assigned_agent_id']) ? (int) $ticket['assigned_agent_id'] : 0;
                if ($assignedId === $userId) {
                    return true;
                }
            }

            // 2. Check Customer ownership (logged-in user matches customer_id).
            $customerId = isset($ticket['customer_id']) ? (int) $ticket['customer_id'] : 0;
            if ($customerId > 0 && $customerId === $userId) {
                return true;
            }
        }

        // 3. Check Guest token authorization.
        if (!empty($guestToken) && !empty($ticket['guest_token_hash'])) {
            if (GuestTokenService::verifyToken($guestToken, (string) $ticket['guest_token_hash'])) {
                // Check token expiration if configured.
                if (self::isGuestTokenExpired($ticket)) {
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user/guest can reply to the ticket.
     *
     * @param array<string, mixed> $ticket Ticket record array.
     * @param int $userId Current user ID.
     * @param string|null $guestToken Plain guest token.
     * @return bool
     */
    public static function canReplyTicket(array $ticket, int $userId = 0, ?string $guestToken = null): bool
    {
        if (!self::canViewTicket($ticket, $userId, $guestToken)) {
            return false;
        }

        if ($userId <= 0) {
            $userId = get_current_user_id();
        }

        // Agents can reply if they have capability.
        if ($userId > 0 && user_can($userId, RoleManager::CAP_REPLY_TICKETS)) {
            return true;
        }

        // For customers/guests, closed tickets cannot receive replies unless reopen is permitted.
        $status = (string) ($ticket['status'] ?? 'open');
        if ($status === 'closed') {
            return self::canCustomerReopen($ticket, $userId);
        }

        return true;
    }

    /**
     * Check if user can add internal notes.
     * Strictly agents/admins only; never customers or guests.
     *
     * @param int $userId Current user ID.
     * @return bool
     */
    public static function canAddInternalNote(int $userId = 0): bool
    {
        if ($userId <= 0) {
            $userId = get_current_user_id();
        }

        return $userId > 0 && user_can($userId, RoleManager::CAP_ADD_INTERNAL_NOTES);
    }

    /**
     * Check if user can assign tickets.
     *
     * @param int $userId Current user ID.
     * @return bool
     */
    public static function canAssignTicket(int $userId = 0): bool
    {
        if ($userId <= 0) {
            $userId = get_current_user_id();
        }

        return $userId > 0 && user_can($userId, RoleManager::CAP_ASSIGN_TICKETS);
    }

    /**
     * Check if user can change ticket priority.
     *
     * @param int $userId Current user ID.
     * @return bool
     */
    public static function canChangePriority(int $userId = 0): bool
    {
        if ($userId <= 0) {
            $userId = get_current_user_id();
        }

        return $userId > 0 && user_can($userId, RoleManager::CAP_CHANGE_PRIORITY);
    }

    /**
     * Check if user can change ticket status.
     *
     * @param array<string, mixed> $ticket Ticket record array.
     * @param int $userId Current user ID.
     * @param string $newStatus Target status.
     * @param string|null $guestToken Plain guest token.
     * @return bool
     */
    public static function canChangeStatus(array $ticket, int $userId = 0, string $newStatus = '', ?string $guestToken = null): bool
    {
        if ($userId <= 0) {
            $userId = get_current_user_id();
        }

        // Staff agents with capability can change to any valid status.
        if ($userId > 0 && user_can($userId, RoleManager::CAP_CHANGE_STATUS)) {
            return true;
        }

        // Check customer permissions for closing/reopening.
        $isOwner = false;
        $customerId = isset($ticket['customer_id']) ? (int) $ticket['customer_id'] : 0;
        if ($userId > 0 && $customerId === $userId) {
            $isOwner = true;
        } elseif (!empty($guestToken) && !empty($ticket['guest_token_hash'])) {
            $isOwner = GuestTokenService::verifyToken($guestToken, (string) $ticket['guest_token_hash']);
        }

        if (!$isOwner) {
            return false;
        }

        // Customer closing.
        if ($newStatus === 'closed') {
            return self::canCustomerClose();
        }

        // Customer reopening.
        if ($newStatus === 'open' || $newStatus === 'in_progress') {
            $currentStatus = (string) ($ticket['status'] ?? 'open');
            if (in_array($currentStatus, ['resolved', 'closed'], true)) {
                return self::canCustomerReopen($ticket, $userId);
            }
        }

        return false;
    }

    /**
     * Check if customer close option is enabled in settings.
     *
     * @return bool
     */
    public static function canCustomerClose(): bool
    {
        return (bool) get_option('wpcalibrate_allow_customer_close', '1');
    }

    /**
     * Check if customer reopening is enabled and valid under policy.
     *
     * @param array<string, mixed> $ticket Ticket record array.
     * @param int $userId Current user ID.
     * @return bool
     */
    public static function canCustomerReopen(array $ticket, int $userId = 0): bool
    {
        $allowReopen = (bool) get_option('wpcalibrate_allow_customer_reopen', '1');
        if (!$allowReopen) {
            return false;
        }

        $reopenDays = (int) get_option('wpcalibrate_customer_reopen_days', 14);
        if ($reopenDays > 0) {
            $closedAt = !empty($ticket['closed_at']) ? strtotime((string) $ticket['closed_at']) : 0;
            $resolvedAt = !empty($ticket['resolved_at']) ? strtotime((string) $ticket['resolved_at']) : 0;
            $cutoffTimestamp = max($closedAt, $resolvedAt);

            if ($cutoffTimestamp > 0 && (time() - $cutoffTimestamp) > ($reopenDays * DAY_IN_SECONDS)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user can delete tickets.
     *
     * @param int $userId Current user ID.
     * @return bool
     */
    public static function canDeleteTicket(int $userId = 0): bool
    {
        if ($userId <= 0) {
            $userId = get_current_user_id();
        }

        return $userId > 0 && user_can($userId, RoleManager::CAP_DELETE_TICKETS);
    }

    /**
     * Check if user/guest can download an attachment.
     *
     * @param array<string, mixed> $attachment Attachment array.
     * @param array<string, mixed> $ticket Ticket array.
     * @param array<string, mixed>|null $message Message array if attached to message.
     * @param int $userId Current user ID.
     * @param string|null $guestToken Plain guest token.
     * @return bool
     */
    public static function canDownloadAttachment(
        array $attachment,
        array $ticket,
        ?array $message = null,
        int $userId = 0,
        ?string $guestToken = null
    ): bool {
        // If message is internal note: only agents with CAP_ADD_INTERNAL_NOTES can download.
        if ($message !== null && ($message['visibility'] ?? 'public') === 'internal') {
            return self::canAddInternalNote($userId);
        }

        // Otherwise, access follows ticket viewing permission.
        return self::canViewTicket($ticket, $userId, $guestToken);
    }

    /**
     * Check if a guest token has expired based on settings.
     *
     * @param array<string, mixed> $ticket Ticket array.
     * @return bool True if expired.
     */
    private static function isGuestTokenExpired(array $ticket): bool
    {
        $expirationDays = (int) get_option('wpcalibrate_guest_token_expiry_days', 90);
        if ($expirationDays <= 0) {
            return false; // 0 means no expiration.
        }

        $createdAt = !empty($ticket['guest_token_created_at'])
            ? strtotime((string) $ticket['guest_token_created_at'])
            : (!empty($ticket['created_at']) ? strtotime((string) $ticket['created_at']) : 0);

        if ($createdAt <= 0) {
            return false;
        }

        return (time() - $createdAt) > ($expirationDays * DAY_IN_SECONDS);
    }
}
