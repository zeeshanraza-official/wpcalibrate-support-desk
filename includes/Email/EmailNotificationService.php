<?php
/**
 * Email notification service using wp_mail and template rendering.
 *
 * @package WPCalibrate\SupportDesk\Email
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Email;

use WPCalibrate\SupportDesk\Security\GuestTokenService;
use WPCalibrate\SupportDesk\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EmailNotificationService
 */
final class EmailNotificationService
{
    /**
     * In-memory deduplication cache for current request.
     *
     * @var array<string, bool>
     */
    private static array $sentDeduplication = [];

    /**
     * Initialize email hooks.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wpcalibrate_ticket_created', [self::class, 'handleTicketCreated'], 10, 2);
        add_action('wpcalibrate_reply_added', [self::class, 'handleReplyAdded'], 10, 3);
        add_action('wpcalibrate_ticket_agent_assigned', [self::class, 'handleAgentAssigned'], 10, 3);
        add_action('wpcalibrate_ticket_status_changed', [self::class, 'handleStatusChanged'], 10, 3);
    }

    /**
     * Handle new ticket submission notifications.
     *
     * @param array<string, mixed> $ticket
     * @param string|null $rawGuestToken
     * @return void
     */
    public static function handleTicketCreated(array $ticket, ?string $rawGuestToken = null): void
    {
        try {
            $ticketId = (int) $ticket['id'];
            $ticketRef = (string) $ticket['ticket_ref'];
            $subject = (string) $ticket['subject'];

            // 1. Customer confirmation / Guest access URL email.
            $customerEmail = self::getTicketCustomerEmail($ticket);
            if (!empty($customerEmail) && (bool) get_option('wpcalibrate_email_customer_ticket_created', '1')) {
                $accessUrl = !empty($rawGuestToken)
                    ? GuestTokenService::buildGuestAccessUrl($ticketRef, $rawGuestToken)
                    : self::getCustomerPortalUrl();

                $emailSubject = sprintf(
                    /* translators: 1: Ticket ref, 2: Ticket subject */
                    __('[%1$s] Ticket Received: %2$s', 'wpcalibrate-support-desk'),
                    $ticketRef,
                    $subject
                );

                $message = sprintf(
                    /* translators: 1: Ticket ref, 2: Ticket subject, 3: Access URL */
                    __("Hello,\n\nThank you for contacting support. We have received your ticket.\n\nTicket Reference: %1\$s\nSubject: %2\$s\n\nYou can track and reply to your ticket at:\n%3\$s\n\nBest regards,\nSupport Team", 'wpcalibrate-support-desk'),
                    $ticketRef,
                    $subject,
                    $accessUrl
                );

                self::sendMail($customerEmail, $emailSubject, $message, "ticket_created_customer_{$ticketId}");
            }

            // 2. Admin notification for new ticket.
            if ((bool) get_option('wpcalibrate_email_admin_new_ticket', '1')) {
                $adminEmail = trim((string) get_option('wpcalibrate_admin_notification_email'));
                if ($adminEmail === '') {
                    $adminEmail = (string) get_option('admin_email');
                }
                if (!empty($adminEmail)) {
                    $adminUrl = admin_url("admin.php?page=wpcalibrate-support-desk&action=view&id={$ticketId}");
                    $adminSubject = sprintf(
                        /* translators: 1: Ticket ref, 2: Ticket subject */
                        __('[%1$s] New Support Ticket: %2$s', 'wpcalibrate-support-desk'),
                        $ticketRef,
                        $subject
                    );

                    $adminMsg = sprintf(
                        /* translators: 1: Ticket ref, 2: Subject, 3: Admin URL */
                        __("A new ticket has been opened.\n\nReference: %1\$s\nSubject: %2\$s\n\nView Ticket in Admin:\n%3\$s\n", 'wpcalibrate-support-desk'),
                        $ticketRef,
                        $subject,
                        $adminUrl
                    );

                    self::sendMail($adminEmail, $adminSubject, $adminMsg, "ticket_created_admin_{$ticketId}");
                }
            }
        } catch (\Throwable $e) {
            Logger::log('error', 'Error in handleTicketCreated notification: ' . $e->getMessage());
        }
    }

    /**
     * Handle public reply notification.
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $message
     * @param bool $isAgent True if agent posted reply.
     * @return void
     */
    public static function handleReplyAdded(array $ticket, array $message, bool $isAgent): void
    {
        try {
            $ticketId = (int) $ticket['id'];
            $ticketRef = (string) $ticket['ticket_ref'];
            $subject = (string) $ticket['subject'];
            $messageBody = wp_strip_all_tags((string) $message['body']);

            if ($isAgent) {
                // Agent replied -> Notify customer.
                if (!(bool) get_option('wpcalibrate_email_customer_agent_reply', '1')) {
                    return;
                }

                $customerEmail = self::getTicketCustomerEmail($ticket);
                if (empty($customerEmail)) {
                    return;
                }

                $accessUrl = !empty($ticket['guest_email'])
                    ? self::getCustomerPortalUrl()
                    : self::getCustomerPortalUrl();

                $emailSubject = sprintf(
                    /* translators: 1: Ticket ref, 2: Ticket subject */
                    __('[%1$s] New Reply: %2$s', 'wpcalibrate-support-desk'),
                    $ticketRef,
                    $subject
                );

                $body = sprintf(
                    /* translators: 1: Author name, 2: Reply content, 3: Access URL */
                    __("Hello,\n\nA new response has been posted by %1\$s:\n\n---\n%2\$s\n---\n\nYou can view and reply to this ticket online:\n%3\$s\n\nBest regards,\nSupport Team", 'wpcalibrate-support-desk'),
                    (string) $message['author_name'],
                    $messageBody,
                    $accessUrl
                );

                self::sendMail($customerEmail, $emailSubject, $body, "agent_reply_{$ticketId}_{$message['id']}");
            } else {
                // Customer replied -> Notify assigned agent or admin.
                if (!(bool) get_option('wpcalibrate_email_agent_customer_reply', '1')) {
                    return;
                }

                $recipientEmail = '';
                if (!empty($ticket['assigned_agent_id'])) {
                    $agent = get_userdata((int) $ticket['assigned_agent_id']);
                    if ($agent) {
                        $recipientEmail = $agent->user_email;
                    }
                }

                if (empty($recipientEmail)) {
                    $recipientEmail = trim((string) get_option('wpcalibrate_admin_notification_email'));
                    if ($recipientEmail === '') {
                        $recipientEmail = (string) get_option('admin_email');
                    }
                }

                if (!empty($recipientEmail)) {
                    $adminUrl = admin_url("admin.php?page=wpcalibrate-support-desk&action=view&id={$ticketId}");
                    $emailSubject = sprintf(
                        /* translators: 1: Ticket ref, 2: Ticket subject */
                        __('[%1$s] Customer Replied: %2$s', 'wpcalibrate-support-desk'),
                        $ticketRef,
                        $subject
                    );

                    $body = sprintf(
                        /* translators: 1: Ticket ref, 2: Author name, 3: Reply content, 4: Admin URL */
                        __("Customer %2\$s replied to ticket %1\$s:\n\n---\n%3\$s\n---\n\nView Ticket in Admin:\n%4\$s\n", 'wpcalibrate-support-desk'),
                        $ticketRef,
                        (string) $message['author_name'],
                        $messageBody,
                        $adminUrl
                    );

                    self::sendMail($recipientEmail, $emailSubject, $body, "customer_reply_{$ticketId}_{$message['id']}");
                }
            }
        } catch (\Throwable $e) {
            Logger::log('error', 'Error in handleReplyAdded notification: ' . $e->getMessage());
        }
    }

    /**
     * Handle assigned agent notification.
     *
     * @param array<string, mixed> $ticket
     * @param int|null $newAgentId
     * @param int|null $oldAgentId
     * @return void
     */
    public static function handleAgentAssigned(array $ticket, ?int $newAgentId, ?int $oldAgentId): void
    {
        try {
            if (empty($newAgentId) || !(bool) get_option('wpcalibrate_email_agent_assigned', '1')) {
                return;
            }

            $agent = get_userdata($newAgentId);
            if (!$agent) {
                return;
            }

            $ticketId = (int) $ticket['id'];
            $ticketRef = (string) $ticket['ticket_ref'];
            $subject = (string) $ticket['subject'];
            $adminUrl = admin_url("admin.php?page=wpcalibrate-support-desk&action=view&id={$ticketId}");

            $emailSubject = sprintf(
                /* translators: 1: Ticket ref, 2: Ticket subject */
                __('[%1$s] Ticket Assigned to You: %2$s', 'wpcalibrate-support-desk'),
                $ticketRef,
                $subject
            );

            $body = sprintf(
                /* translators: 1: Agent name, 2: Ticket ref, 3: Subject, 4: Admin URL */
                __("Hello %1\$s,\n\nYou have been assigned to ticket %2\$s: \"%3\$s\".\n\nOpen Ticket:\n%4\$s\n", 'wpcalibrate-support-desk'),
                $agent->display_name,
                $ticketRef,
                $subject,
                $adminUrl
            );

            self::sendMail($agent->user_email, $emailSubject, $body, "agent_assigned_{$ticketId}_{$newAgentId}");
        } catch (\Throwable $e) {
            Logger::log('error', 'Error in handleAgentAssigned notification: ' . $e->getMessage());
        }
    }

    /**
     * Handle status changed notification (e.g. resolved/closed).
     *
     * @param array<string, mixed> $ticket
     * @param string $oldStatus
     * @param string $newStatus
     * @return void
     */
    public static function handleStatusChanged(array $ticket, string $oldStatus, string $newStatus): void
    {
        try {
            if (!in_array($newStatus, ['resolved', 'closed'], true)) {
                return;
            }

            if (!(bool) get_option('wpcalibrate_email_status_change', '1')) {
                return;
            }

            $customerEmail = self::getTicketCustomerEmail($ticket);
            if (empty($customerEmail)) {
                return;
            }

            $ticketId = (int) $ticket['id'];
            $ticketRef = (string) $ticket['ticket_ref'];
            $subject = (string) $ticket['subject'];
            $statusLabel = ($newStatus === 'resolved')
                ? __('Resolved', 'wpcalibrate-support-desk')
                : __('Closed', 'wpcalibrate-support-desk');

            $emailSubject = sprintf(
                /* translators: 1: Ticket ref, 2: Status label */
                __('[%1$s] Ticket %2$s', 'wpcalibrate-support-desk'),
                $ticketRef,
                $statusLabel
            );

            $body = sprintf(
                /* translators: 1: Ticket ref, 2: Subject, 3: Status label */
                __("Hello,\n\nYour support ticket %1\$s (\"%2\$s\") has been marked as %3\$s.\n\nThank you for reaching out to us.\n\nBest regards,\nSupport Team", 'wpcalibrate-support-desk'),
                $ticketRef,
                $subject,
                $statusLabel
            );

            self::sendMail($customerEmail, $emailSubject, $body, "status_{$newStatus}_{$ticketId}");
        } catch (\Throwable $e) {
            Logger::log('error', 'Error in handleStatusChanged notification: ' . $e->getMessage());
        }
    }

    /**
     * Send email with header sanitization and request deduplication.
     *
     * @param string $to
     * @param string $subject
     * @param string $message
     * @param string $dedupKey
     * @return bool
     */
    public static function sendMail(string $to, string $subject, string $message, string $dedupKey = ''): bool
    {
        // 1. Check deduplication cache.
        if (!empty($dedupKey) && isset(self::$sentDeduplication[$dedupKey])) {
            return true; // Already sent in this request cycle.
        }

        // 2. Prevent header injection.
        $cleanTo = sanitize_email(str_replace(["\r", "\n"], '', $to));
        $cleanSubject = sanitize_text_field(str_replace(["\r", "\n"], '', $subject));

        if (empty($cleanTo) || !is_email($cleanTo)) {
            return false;
        }

        $fromName = sanitize_text_field((string) get_option('wpcalibrate_email_from_name', get_bloginfo('name')));
        $fromEmail = sanitize_email((string) get_option('wpcalibrate_email_from_address', (string) get_option('admin_email', 'admin@example.com')));

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if (!empty($fromName) && !empty($fromEmail)) {
            $cleanFromName = str_replace(["\r", "\n"], '', $fromName);
            $cleanFromEmail = str_replace(["\r", "\n"], '', $fromEmail);
            $headers[] = "From: {$cleanFromName} <{$cleanFromEmail}>";
        }

        try {
            $sent = wp_mail($cleanTo, $cleanSubject, $message, $headers);
        } catch (\Throwable $e) {
            Logger::log('error', 'wp_mail exception caught when sending to ' . $cleanTo . ': ' . $e->getMessage());
            return false;
        }

        if ($sent) {
            if (!empty($dedupKey)) {
                self::$sentDeduplication[$dedupKey] = true;
            }
        } else {
            Logger::log('warning', 'wp_mail reported false when delivering notification to: ' . $cleanTo);
        }

        return (bool) $sent;
    }

    /**
     * Retrieve customer email from ticket.
     *
     * @param array<string, mixed> $ticket
     * @return string
     */
    private static function getTicketCustomerEmail(array $ticket): string
    {
        if (!empty($ticket['guest_email'])) {
            return (string) $ticket['guest_email'];
        }

        if (!empty($ticket['customer_id'])) {
            $user = get_userdata((int) $ticket['customer_id']);
            if ($user) {
                return (string) $user->user_email;
            }
        }

        return '';
    }

    /**
     * Get frontend support portal URL.
     *
     * @return string
     */
    private static function getCustomerPortalUrl(): string
    {
        $pageId = (int) get_option('wpcalibrate_support_portal_page_id', 0);
        if ($pageId > 0) {
            $link = get_permalink($pageId);
            if ($link && !is_wp_error($link)) {
                return $link;
            }
        }

        return home_url('/');
    }

    /**
     * Reset deduplication cache (useful in tests or daemon runners).
     *
     * @return void
     */
    public static function resetDeduplication(): void
    {
        self::$sentDeduplication = [];
    }
}
