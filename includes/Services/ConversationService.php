<?php
/**
 * Conversation service for customer replies, agent replies, and internal notes.
 *
 * @package WPCalibrate\SupportDesk\Services
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Services;

use WPCalibrate\SupportDesk\Repositories\TicketRepository;
use WPCalibrate\SupportDesk\Repositories\MessageRepository;
use WPCalibrate\SupportDesk\Repositories\ActivityRepository;
use WPCalibrate\SupportDesk\Security\AccessPolicy;
use WPCalibrate\SupportDesk\Security\AttachmentSecurity;
use WPCalibrate\SupportDesk\Auth\RoleManager;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ConversationService
 */
final class ConversationService
{
    private TicketRepository $ticketRepo;
    private MessageRepository $messageRepo;
    private ActivityRepository $activityRepo;

    public function __construct()
    {
        $this->ticketRepo = new TicketRepository();
        $this->messageRepo = new MessageRepository();
        $this->activityRepo = new ActivityRepository();
    }

    /**
     * Add a public reply from customer, guest, or agent.
     *
     * @param int $ticketId
     * @param string $body
     * @param int $userId
     * @param array<int, array<string, mixed>> $uploadedFiles
     * @param string|null $guestToken
     * @return array<string, mixed>|WP_Error
     */
    public function addReply(int $ticketId, string $body, int $userId = 0, array $uploadedFiles = [], ?string $guestToken = null): array|WP_Error
    {
        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return new WP_Error('not_found', __('Ticket not found.', 'wpcalibrate-support-desk'));
        }

        if ($userId <= 0) {
            $userId = get_current_user_id();
        }

        if (!AccessPolicy::canReplyTicket($ticket, $userId, $guestToken)) {
            return new WP_Error('unauthorized', __('You do not have permission to reply to this ticket.', 'wpcalibrate-support-desk'));
        }

        $sanitizedBody = wp_kses_post(trim($body));
        if (empty($sanitizedBody)) {
            return new WP_Error('empty_message', __('Reply message cannot be empty.', 'wpcalibrate-support-desk'));
        }

        $isAgent = ($userId > 0 && user_can($userId, RoleManager::CAP_REPLY_TICKETS));
        $authorName = __('Guest', 'wpcalibrate-support-desk');
        $authorEmail = (string) ($ticket['guest_email'] ?? '');

        if ($userId > 0) {
            $user = get_userdata($userId);
            if ($user) {
                $authorName = $user->display_name;
                $authorEmail = $user->user_email;
            }
        } elseif (!empty($ticket['guest_name'])) {
            $authorName = (string) $ticket['guest_name'];
        }

        // Insert public reply message.
        $messageId = $this->messageRepo->create([
            'ticket_id'    => $ticketId,
            'author_id'    => $userId > 0 ? $userId : null,
            'author_name'  => $authorName,
            'author_email' => $authorEmail,
            'message_type' => 'reply',
            'visibility'   => 'public',
            'body'         => $sanitizedBody,
        ]);

        if (!$messageId) {
            return new WP_Error('message_create_failed', __('Failed to save reply message.', 'wpcalibrate-support-desk'));
        }

        // Save attachments if any.
        $savedAttachments = [];
        if (!empty($uploadedFiles) && AttachmentSecurity::isEnabled()) {
            foreach ($uploadedFiles as $file) {
                $saved = AttachmentSecurity::saveUploadedFile($file, $ticketId, $messageId, $userId);
                if (!is_wp_error($saved)) {
                    $savedAttachments[] = $saved;
                }
            }
        }

        // Update ticket last_reply_at and status progression.
        $now = current_time('mysql', true);
        $updateTicket = ['last_reply_at' => $now];

        if ($isAgent) {
            // When agent replies, status becomes 'waiting_customer' unless closed/resolved.
            if (!in_array((string) $ticket['status'], ['resolved', 'closed'], true)) {
                $updateTicket['status'] = 'waiting_customer';
            }
        } else {
            // Customer or guest replied.
            if (in_array((string) $ticket['status'], ['resolved', 'closed', 'waiting_customer'], true)) {
                $updateTicket['status'] = 'in_progress';
                $updateTicket['closed_at'] = null;
                $updateTicket['resolved_at'] = null;
            }
        }

        $this->ticketRepo->update($ticketId, $updateTicket);

        // Log activity.
        $action = $isAgent ? 'agent_reply' : 'customer_reply';
        $actorType = $isAgent ? 'agent' : ($userId > 0 ? 'customer' : 'guest');
        $this->activityRepo->log($ticketId, $action, $userId > 0 ? $userId : null, $actorType, [
            'author_name' => $authorName,
        ]);

        StatsService::invalidateCache();

        $message = $this->messageRepo->getById($messageId);
        $updatedTicket = $this->ticketRepo->getById($ticketId);

        // Trigger notification action.
        do_action('wpcalibrate_reply_added', $updatedTicket, $message, $isAgent);

        return [
            'message'     => $message,
            'attachments' => $savedAttachments,
            'ticket'      => $updatedTicket,
        ];
    }

    /**
     * Add an internal note (Staff only - strictly hidden from customers).
     *
     * @param int $ticketId
     * @param string $body
     * @param int $agentId
     * @param array<int, array<string, mixed>> $uploadedFiles
     * @return array<string, mixed>|WP_Error
     */
    public function addInternalNote(int $ticketId, string $body, int $agentId = 0, array $uploadedFiles = []): array|WP_Error
    {
        if ($agentId <= 0) {
            $agentId = get_current_user_id();
        }

        if (!AccessPolicy::canAddInternalNote($agentId)) {
            return new WP_Error('unauthorized', __('You do not have permission to add internal notes.', 'wpcalibrate-support-desk'));
        }

        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return new WP_Error('not_found', __('Ticket not found.', 'wpcalibrate-support-desk'));
        }

        $sanitizedBody = wp_kses_post(trim($body));
        if (empty($sanitizedBody)) {
            return new WP_Error('empty_note', __('Internal note cannot be empty.', 'wpcalibrate-support-desk'));
        }

        $user = get_userdata($agentId);
        $authorName = $user ? $user->display_name : __('Support Agent', 'wpcalibrate-support-desk');
        $authorEmail = $user ? $user->user_email : '';

        // Save note with internal visibility.
        $messageId = $this->messageRepo->create([
            'ticket_id'    => $ticketId,
            'author_id'    => $agentId,
            'author_name'  => $authorName,
            'author_email' => $authorEmail,
            'message_type' => 'note',
            'visibility'   => 'internal',
            'body'         => $sanitizedBody,
        ]);

        if (!$messageId) {
            return new WP_Error('note_create_failed', __('Failed to create internal note.', 'wpcalibrate-support-desk'));
        }

        // Save internal attachments.
        $savedAttachments = [];
        if (!empty($uploadedFiles) && AttachmentSecurity::isEnabled()) {
            foreach ($uploadedFiles as $file) {
                $saved = AttachmentSecurity::saveUploadedFile($file, $ticketId, $messageId, $agentId);
                if (!is_wp_error($saved)) {
                    $savedAttachments[] = $saved;
                }
            }
        }

        // Log internal activity (agents only).
        $this->activityRepo->log($ticketId, 'internal_note', $agentId, 'agent', [
            'author_name' => $authorName,
        ]);

        $message = $this->messageRepo->getById($messageId);

        // Action hook for internal note (strictly never triggers customer emails).
        do_action('wpcalibrate_internal_note_added', $ticket, $message);

        return [
            'message'     => $message,
            'attachments' => $savedAttachments,
        ];
    }
}
