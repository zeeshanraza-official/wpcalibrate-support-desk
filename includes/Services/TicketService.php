<?php
/**
 * Core ticket service coordinating domain actions.
 *
 * @package WPCalibrate\SupportDesk\Services
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Services;

use WPCalibrate\SupportDesk\Database\SchemaManager;
use WPCalibrate\SupportDesk\Repositories\TicketRepository;
use WPCalibrate\SupportDesk\Repositories\MessageRepository;
use WPCalibrate\SupportDesk\Repositories\ActivityRepository;
use WPCalibrate\SupportDesk\Repositories\CategoryRepository;
use WPCalibrate\SupportDesk\Security\AccessPolicy;
use WPCalibrate\SupportDesk\Security\GuestTokenService;
use WPCalibrate\SupportDesk\Security\AttachmentSecurity;
use WPCalibrate\SupportDesk\Integrations\WooCommerce\WooCommerceIntegration;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class TicketService
 */
final class TicketService
{
    private TicketRepository $ticketRepo;
    private MessageRepository $messageRepo;
    private ActivityRepository $activityRepo;
    private CategoryRepository $categoryRepo;

    public function __construct()
    {
        $this->ticketRepo = new TicketRepository();
        $this->messageRepo = new MessageRepository();
        $this->activityRepo = new ActivityRepository();
        $this->categoryRepo = new CategoryRepository();
    }

    /**
     * Create a new ticket from frontend or admin.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $uploadedFiles
     * @return array<string, mixed>|WP_Error
     */
    public function createTicket(array $data, array $uploadedFiles = []): array|WP_Error
    {
        // 1. Anti-abuse / rate-limiting for guests.
        $userId = !empty($data['customer_id']) ? (int) $data['customer_id'] : get_current_user_id();
        $isGuest = ($userId <= 0);

        if ($isGuest) {
            // Honeypot check.
            if (!empty($data['website_url_hp'])) {
                return new WP_Error('spam_detected', __('Submission blocked.', 'wpcalibrate-support-desk'));
            }

            // Guest submission setting check.
            $guestEnabled = (bool) get_option('wpcalibrate_guest_tickets_enabled', '1');
            if (!$guestEnabled) {
                return new WP_Error('guest_disabled', __('Guest ticket submissions are disabled. Please log in.', 'wpcalibrate-support-desk'));
            }

            // Rate-limit by client IP (max 5 submissions per 10 minutes).
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
            $transientKey = 'wpc_rl_' . md5($ip);
            $submissions = (int) get_transient($transientKey);
            if ($submissions >= 5) {
                return new WP_Error('rate_limit_exceeded', __('Too many ticket submissions. Please wait a few minutes before trying again.', 'wpcalibrate-support-desk'));
            }
            set_transient($transientKey, $submissions + 1, 10 * MINUTE_IN_SECONDS);
        }

        // 2. Validate input fields.
        $subject = sanitize_text_field(trim((string) ($data['subject'] ?? '')));
        if (empty($subject)) {
            return new WP_Error('missing_subject', __('Please provide a ticket subject.', 'wpcalibrate-support-desk'));
        }

        $messageBody = wp_kses_post(trim((string) ($data['message'] ?? '')));
        if (empty($messageBody)) {
            return new WP_Error('missing_message', __('Please enter a ticket description/message.', 'wpcalibrate-support-desk'));
        }

        $guestName = null;
        $guestEmail = null;
        $rawGuestToken = null;
        $guestTokenHash = null;

        if ($isGuest) {
            $guestName = sanitize_text_field(trim((string) ($data['guest_name'] ?? '')));
            $guestEmail = sanitize_email(trim((string) ($data['guest_email'] ?? '')));

            if (empty($guestName)) {
                return new WP_Error('missing_name', __('Please enter your name.', 'wpcalibrate-support-desk'));
            }
            if (empty($guestEmail) || !is_email($guestEmail)) {
                return new WP_Error('invalid_email', __('Please enter a valid email address.', 'wpcalibrate-support-desk'));
            }

            // Generate cryptographically secure token and hash for storage.
            $rawGuestToken = GuestTokenService::generateToken();
            $guestTokenHash = GuestTokenService::hashToken($rawGuestToken);
        }

        // 3. Category & Priority resolution.
        $categoryId = (int) ($data['category_id'] ?? get_option('wpcalibrate_default_department', 1));
        $category = $this->categoryRepo->getById($categoryId);
        if (!$category) {
            $categoryId = 1;
        }

        // Customer priority selection rule.
        $priorityAllowedForCustomer = (bool) get_option('wpcalibrate_customer_priority_selection', '0');
        $priority = 'normal';
        if ($priorityAllowedForCustomer && !empty($data['priority'])) {
            $requestedPriority = sanitize_key((string) $data['priority']);
            if (in_array($requestedPriority, ['low', 'normal', 'high', 'urgent'], true)) {
                $priority = $requestedPriority;
            }
        } else {
            $priority = (string) get_option('wpcalibrate_default_priority', 'normal');
        }

        // 4. Default assigned agent from category if configured.
        $assignedAgentId = null;
        if (!empty($category['default_agent_id'])) {
            $assignedAgentId = (int) $category['default_agent_id'];
        }

        // 5. WooCommerce Order association check (if provided).
        $wooOrderId = null;
        if (!empty($data['woo_order_id'])) {
            $candidateOrderId = (int) $data['woo_order_id'];
            if ($candidateOrderId > 0 && WooCommerceIntegration::isEligibleOrderForCustomer($candidateOrderId, $userId)) {
                $wooOrderId = $candidateOrderId;
            }
        }

        // 6. Generate concurrency-safe ticket reference.
        $ticketRef = SequenceService::generateNextTicketRef();
        $ticketKey = bin2hex(random_bytes(16));

        // 7. Insert ticket into repository.
        $ticketId = $this->ticketRepo->create([
            'ticket_key'        => $ticketKey,
            'ticket_ref'        => $ticketRef,
            'customer_id'       => $userId > 0 ? $userId : null,
            'guest_name'        => $guestName,
            'guest_email'       => $guestEmail,
            'guest_token_hash'  => $guestTokenHash,
            'status'            => 'open',
            'priority'          => $priority,
            'category_id'       => $categoryId,
            'assigned_agent_id' => $assignedAgentId,
            'subject'           => $subject,
            'woo_order_id'      => $wooOrderId,
        ]);

        if (!$ticketId) {
            return new WP_Error('ticket_create_failed', __('Unable to create ticket in database.', 'wpcalibrate-support-desk'));
        }

        // 8. Determine author identity for opening message.
        $authorName = $guestName ?? '';
        $authorEmail = $guestEmail ?? '';
        if ($userId > 0) {
            $user = get_userdata($userId);
            if ($user) {
                $authorName = $user->display_name;
                $authorEmail = $user->user_email;
            }
        }

        // 9. Insert initial message.
        $messageId = $this->messageRepo->create([
            'ticket_id'    => $ticketId,
            'author_id'    => $userId > 0 ? $userId : null,
            'author_name'  => $authorName,
            'author_email' => $authorEmail,
            'message_type' => 'reply',
            'visibility'   => 'public',
            'body'         => $messageBody,
        ]);

        // 10. Process attachments if any.
        $savedAttachments = [];
        if (!empty($uploadedFiles) && AttachmentSecurity::isEnabled()) {
            foreach ($uploadedFiles as $file) {
                $saved = AttachmentSecurity::saveUploadedFile($file, $ticketId, $messageId ?: null, $userId);
                if (!is_wp_error($saved)) {
                    $savedAttachments[] = $saved;
                }
            }
        }

        // 11. Log activity event.
        $this->activityRepo->log(
            $ticketId,
            'created',
            $userId > 0 ? $userId : null,
            $isGuest ? 'guest' : 'customer',
            [
                'ticket_ref' => $ticketRef,
                'subject'    => $subject,
            ]
        );

        // Invalidate cached stats.
        StatsService::invalidateCache();

        $ticket = $this->ticketRepo->getById($ticketId);

        // 12. Trigger email notification service.
        if (is_array($ticket)) {
            do_action('wpcalibrate_ticket_created', $ticket, $rawGuestToken);
        }

        return [
            'ticket'          => $ticket,
            'ticket_id'       => $ticketId,
            'ticket_ref'      => $ticketRef,
            'guest_token'     => $rawGuestToken, // returned once to allow frontend redirect/view
            'access_url'      => $rawGuestToken ? GuestTokenService::buildGuestAccessUrl($ticketRef, $rawGuestToken) : null,
            'attachments'     => $savedAttachments,
        ];
    }

    /**
     * Update ticket status.
     *
     * @param int $ticketId
     * @param string $newStatus
     * @param int $actorId
     * @param string $actorType
     * @param string|null $guestToken
     * @return bool|WP_Error
     */
    public function updateStatus(int $ticketId, string $newStatus, int $actorId = 0, string $actorType = 'agent', ?string $guestToken = null): bool|WP_Error
    {
        $validStatuses = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];
        if (!in_array($newStatus, $validStatuses, true)) {
            return new WP_Error('invalid_status', __('Invalid ticket status.', 'wpcalibrate-support-desk'));
        }

        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return new WP_Error('not_found', __('Ticket not found.', 'wpcalibrate-support-desk'));
        }

        if (!AccessPolicy::canChangeStatus($ticket, $actorId, $newStatus, $guestToken)) {
            return new WP_Error('unauthorized', __('You do not have permission to change this ticket status.', 'wpcalibrate-support-desk'));
        }

        $oldStatus = (string) $ticket['status'];
        if ($oldStatus === $newStatus) {
            return true;
        }

        $updateData = ['status' => $newStatus];
        $now = current_time('mysql', true);

        if ($newStatus === 'resolved') {
            $updateData['resolved_at'] = $now;
        } elseif ($newStatus === 'closed') {
            $updateData['closed_at'] = $now;
        } elseif (in_array($oldStatus, ['resolved', 'closed'], true) && in_array($newStatus, ['open', 'in_progress'], true)) {
            // Reopening.
            $updateData['closed_at'] = null;
            $updateData['resolved_at'] = null;
        }

        $success = $this->ticketRepo->update($ticketId, $updateData);
        if ($success) {
            $this->activityRepo->log(
                $ticketId,
                'status_changed',
                $actorId > 0 ? $actorId : null,
                $actorType,
                ['old_status' => $oldStatus, 'new_status' => $newStatus]
            );

            StatsService::invalidateCache();

            $updatedTicket = $this->ticketRepo->getById($ticketId);
            do_action('wpcalibrate_ticket_status_changed', $updatedTicket, $oldStatus, $newStatus);
        }

        return $success;
    }

    /**
     * Update ticket priority.
     *
     * @param int $ticketId
     * @param string $newPriority
     * @param int $actorId
     * @return bool|WP_Error
     */
    public function updatePriority(int $ticketId, string $newPriority, int $actorId = 0): bool|WP_Error
    {
        if (!AccessPolicy::canChangePriority($actorId)) {
            return new WP_Error('unauthorized', __('You do not have permission to change ticket priority.', 'wpcalibrate-support-desk'));
        }

        $validPriorities = ['low', 'normal', 'high', 'urgent'];
        if (!in_array($newPriority, $validPriorities, true)) {
            return new WP_Error('invalid_priority', __('Invalid ticket priority.', 'wpcalibrate-support-desk'));
        }

        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return new WP_Error('not_found', __('Ticket not found.', 'wpcalibrate-support-desk'));
        }

        $oldPriority = (string) $ticket['priority'];
        if ($oldPriority === $newPriority) {
            return true;
        }

        $success = $this->ticketRepo->update($ticketId, ['priority' => $newPriority]);
        if ($success) {
            $this->activityRepo->log(
                $ticketId,
                'priority_changed',
                $actorId > 0 ? $actorId : null,
                'agent',
                ['old_priority' => $oldPriority, 'new_priority' => $newPriority]
            );

            StatsService::invalidateCache();
            do_action('wpcalibrate_ticket_priority_changed', $ticket, $oldPriority, $newPriority);
        }

        return $success;
    }

    /**
     * Assign an agent to a ticket.
     *
     * @param int $ticketId
     * @param int|null $agentId
     * @param int $actorId
     * @return bool|WP_Error
     */
    public function assignAgent(int $ticketId, ?int $agentId, int $actorId = 0): bool|WP_Error
    {
        if (!AccessPolicy::canAssignTicket($actorId)) {
            return new WP_Error('unauthorized', __('You do not have permission to assign tickets.', 'wpcalibrate-support-desk'));
        }

        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return new WP_Error('not_found', __('Ticket not found.', 'wpcalibrate-support-desk'));
        }

        $targetAgentId = ($agentId !== null && $agentId > 0) ? $agentId : null;
        $oldAgentId = !empty($ticket['assigned_agent_id']) ? (int) $ticket['assigned_agent_id'] : null;

        if ($targetAgentId === $oldAgentId) {
            return true;
        }

        // Verify target user has ticket capability if assigned.
        if ($targetAgentId !== null) {
            if (!user_can($targetAgentId, \WPCalibrate\SupportDesk\Auth\RoleManager::CAP_VIEW_ASSIGNED_TICKETS) &&
                !user_can($targetAgentId, \WPCalibrate\SupportDesk\Auth\RoleManager::CAP_VIEW_ALL_TICKETS)) {
                return new WP_Error('invalid_agent', __('Selected user does not have support agent permissions.', 'wpcalibrate-support-desk'));
            }
        }

        $success = $this->ticketRepo->update($ticketId, ['assigned_agent_id' => $targetAgentId]);
        if ($success) {
            $this->activityRepo->log(
                $ticketId,
                'agent_assigned',
                $actorId > 0 ? $actorId : null,
                'agent',
                ['old_agent_id' => $oldAgentId, 'new_agent_id' => $targetAgentId]
            );

            StatsService::invalidateCache();

            $updatedTicket = $this->ticketRepo->getById($ticketId);
            do_action('wpcalibrate_ticket_agent_assigned', $updatedTicket, $targetAgentId, $oldAgentId);
        }

        return $success;
    }

    /**
     * Update department/category of a ticket.
     *
     * @param int $ticketId
     * @param int $categoryId
     * @param int $actorId
     * @return bool|WP_Error
     */
    public function updateCategory(int $ticketId, int $categoryId, int $actorId = 0): bool|WP_Error
    {
        if (!AccessPolicy::canAssignTicket($actorId)) {
            return new WP_Error('unauthorized', __('Permission denied.', 'wpcalibrate-support-desk'));
        }

        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return new WP_Error('not_found', __('Ticket not found.', 'wpcalibrate-support-desk'));
        }

        $category = $this->categoryRepo->getById($categoryId);
        if (!$category) {
            return new WP_Error('invalid_category', __('Category does not exist.', 'wpcalibrate-support-desk'));
        }

        $oldCatId = (int) $ticket['category_id'];
        if ($oldCatId === $categoryId) {
            return true;
        }

        $success = $this->ticketRepo->update($ticketId, ['category_id' => $categoryId]);
        if ($success) {
            $this->activityRepo->log(
                $ticketId,
                'category_changed',
                $actorId > 0 ? $actorId : null,
                'agent',
                ['old_category_id' => $oldCatId, 'new_category_id' => $categoryId]
            );
        }

        return $success;
    }
}
