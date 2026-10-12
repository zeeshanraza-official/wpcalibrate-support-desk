<?php
/**
 * REST API controller for WPCalibrate Support Desk.
 *
 * @package WPCalibrate\SupportDesk\Api
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Api;

use WPCalibrate\SupportDesk\Auth\RoleManager;
use WPCalibrate\SupportDesk\Repositories\TicketRepository;
use WPCalibrate\SupportDesk\Repositories\MessageRepository;
use WPCalibrate\SupportDesk\Repositories\ActivityRepository;
use WPCalibrate\SupportDesk\Repositories\CategoryRepository;
use WPCalibrate\SupportDesk\Repositories\AttachmentRepository;
use WPCalibrate\SupportDesk\Services\TicketService;
use WPCalibrate\SupportDesk\Services\ConversationService;
use WPCalibrate\SupportDesk\Security\AccessPolicy;
use WPCalibrate\SupportDesk\Security\AttachmentSecurity;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class RestApiController
 */
final class RestApiController extends WP_REST_Controller
{
    public const NAMESPACE = 'wpcalibrate-support-desk/v1';

    private TicketRepository $ticketRepo;
    private MessageRepository $messageRepo;
    private ActivityRepository $activityRepo;
    private CategoryRepository $categoryRepo;
    private AttachmentRepository $attachmentRepo;
    private TicketService $ticketService;
    private ConversationService $conversationService;

    public function __construct()
    {
        $this->ticketRepo = new TicketRepository();
        $this->messageRepo = new MessageRepository();
        $this->activityRepo = new ActivityRepository();
        $this->categoryRepo = new CategoryRepository();
        $this->attachmentRepo = new AttachmentRepository();
        $this->ticketService = new TicketService();
        $this->conversationService = new ConversationService();
    }

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function registerRoutes(): void
    {
        // 1. List tickets.
        register_rest_route(self::NAMESPACE, '/tickets', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getTickets'],
                'permission_callback' => [$this, 'checkListPermissions'],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createTicket'],
                'permission_callback' => [$this, 'checkCreatePermissions'],
            ],
        ]);

        // 2. Single ticket detail.
        register_rest_route(self::NAMESPACE, '/tickets/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getTicket'],
                'permission_callback' => [$this, 'checkViewPermissions'],
            ],
        ]);

        // 3. Public reply.
        register_rest_route(self::NAMESPACE, '/tickets/(?P<id>\d+)/reply', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'addReply'],
                'permission_callback' => [$this, 'checkReplyPermissions'],
            ],
        ]);

        // 4. Internal note (Staff only).
        register_rest_route(self::NAMESPACE, '/tickets/(?P<id>\d+)/note', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'addInternalNote'],
                'permission_callback' => [$this, 'checkInternalNotePermissions'],
            ],
        ]);

        // 5. Update Status.
        register_rest_route(self::NAMESPACE, '/tickets/(?P<id>\d+)/status', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'updateStatus'],
                'permission_callback' => [$this, 'checkStatusPermissions'],
            ],
        ]);

        // 6. Update Priority.
        register_rest_route(self::NAMESPACE, '/tickets/(?P<id>\d+)/priority', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'updatePriority'],
                'permission_callback' => [$this, 'checkPriorityPermissions'],
            ],
        ]);

        // 7. Assign Agent.
        register_rest_route(self::NAMESPACE, '/tickets/(?P<id>\d+)/assign', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'assignAgent'],
                'permission_callback' => [$this, 'checkAssignPermissions'],
            ],
        ]);

        // 8. Categories list.
        register_rest_route(self::NAMESPACE, '/categories', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'getCategories'],
                'permission_callback' => '__return_true', // public categories list for frontend forms
            ],
        ]);

        // 9. Protected attachment streaming download.
        register_rest_route(self::NAMESPACE, '/attachments/(?P<id>\d+)/download', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'downloadAttachment'],
                'permission_callback' => '__return_true', // Permission verified inside streaming handler via token/cookie
            ],
        ]);
    }

    /**
     * Check permission to list tickets.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkListPermissions(WP_REST_Request $request): bool
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return false; // Guests cannot query the ticket list.
        }

        // Customer can query their own tickets, agents can query according to caps.
        return true;
    }

    /**
     * Check permission to create ticket.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkCreatePermissions(WP_REST_Request $request): bool
    {
        $userId = get_current_user_id();
        if ($userId > 0) {
            return true;
        }

        // If guest, verify guest ticket submission is enabled.
        return (bool) get_option('wpcalibrate_guest_tickets_enabled', '1');
    }

    /**
     * Check permission to view a single ticket.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkViewPermissions(WP_REST_Request $request): bool
    {
        $ticketId = (int) $request['id'];
        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return false;
        }

        $token = $request->get_param('token');
        return AccessPolicy::canViewTicket($ticket, get_current_user_id(), $token ? (string) $token : null);
    }

    /**
     * Check permission to reply.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkReplyPermissions(WP_REST_Request $request): bool
    {
        $ticketId = (int) $request['id'];
        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return false;
        }

        $token = $request->get_param('token');
        return AccessPolicy::canReplyTicket($ticket, get_current_user_id(), $token ? (string) $token : null);
    }

    /**
     * Check permission for internal notes.
     * Strictly agents with capability.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkInternalNotePermissions(WP_REST_Request $request): bool
    {
        return AccessPolicy::canAddInternalNote(get_current_user_id());
    }

    /**
     * Check permission to update status.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkStatusPermissions(WP_REST_Request $request): bool
    {
        $ticketId = (int) $request['id'];
        $ticket = $this->ticketRepo->getById($ticketId);
        if (!$ticket) {
            return false;
        }

        $newStatus = sanitize_key((string) $request->get_param('status'));
        $token = $request->get_param('token');

        return AccessPolicy::canChangeStatus($ticket, get_current_user_id(), $newStatus, $token ? (string) $token : null);
    }

    /**
     * Check permission to update priority.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkPriorityPermissions(WP_REST_Request $request): bool
    {
        return AccessPolicy::canChangePriority(get_current_user_id());
    }

    /**
     * Check permission to assign ticket.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function checkAssignPermissions(WP_REST_Request $request): bool
    {
        return AccessPolicy::canAssignTicket(get_current_user_id());
    }

    /**
     * GET /tickets handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getTickets(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $isAgentAll = user_can($userId, RoleManager::CAP_VIEW_ALL_TICKETS);
        $isAgentAssigned = user_can($userId, RoleManager::CAP_VIEW_ASSIGNED_TICKETS);

        $queryArgs = [
            'status'      => $request->get_param('status'),
            'priority'    => $request->get_param('priority'),
            'category_id' => $request->get_param('category_id'),
            'search'      => $request->get_param('search'),
            'order_by'    => $request->get_param('order_by') ?: 'last_reply_at',
            'order'       => $request->get_param('order') ?: 'DESC',
            'page'        => (int) ($request->get_param('page') ?: 1),
            'per_page'    => (int) ($request->get_param('per_page') ?: 20),
        ];

        // Scope queries strictly according to actor's capability.
        if ($isAgentAll) {
            if ($request->get_param('assigned_agent_id') !== null) {
                $queryArgs['assigned_agent_id'] = $request->get_param('assigned_agent_id');
            }
        } elseif ($isAgentAssigned) {
            $queryArgs['assigned_agent_id'] = $userId;
        } else {
            // Standard customer: strictly only their own tickets!
            $queryArgs['customer_id'] = $userId;
        }

        $result = $this->ticketRepo->query($queryArgs);
        return new WP_REST_Response($result, 200);
    }

    /**
     * GET /tickets/{id} handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getTicket(WP_REST_Request $request): WP_REST_Response
    {
        $ticketId = (int) $request['id'];
        $ticket = $this->ticketRepo->getById($ticketId);

        $userId = get_current_user_id();
        $isStaff = $userId > 0 && (
            user_can($userId, RoleManager::CAP_VIEW_ALL_TICKETS) ||
            user_can($userId, RoleManager::CAP_VIEW_ASSIGNED_TICKETS)
        );

        // Fetch messages: internal notes included ONLY for staff!
        $messages = $this->messageRepo->getByTicket($ticketId, $isStaff);

        // Fetch activity: internal events included ONLY for staff!
        $activity = $this->activityRepo->getByTicket($ticketId, $isStaff);

        // Woo order summary if enabled and available.
        $wooOrder = null;
        if (!empty($ticket['woo_order_id'])) {
            $wooOrder = \WPCalibrate\SupportDesk\Integrations\WooCommerce\WooCommerceIntegration::getOrderSummary((int) $ticket['woo_order_id']);
        }

        // Redact guest_token_hash from output.
        unset($ticket['guest_token_hash']);

        return new WP_REST_Response([
            'ticket'    => $ticket,
            'messages'  => $messages,
            'activity'  => $activity,
            'woo_order' => $wooOrder,
        ], 200);
    }

    /**
     * POST /tickets handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function createTicket(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $files = $request->get_file_params();
        $uploaded = [];
        if (!empty($files['attachments'])) {
            $rawFiles = $files['attachments'];
            if (isset($rawFiles['name']) && is_array($rawFiles['name'])) {
                // Multi-file upload normalization.
                $count = count($rawFiles['name']);
                for ($i = 0; $i < $count; $i++) {
                    $uploaded[] = [
                        'name'     => $rawFiles['name'][$i],
                        'type'     => $rawFiles['type'][$i],
                        'tmp_name' => $rawFiles['tmp_name'][$i],
                        'error'    => $rawFiles['error'][$i],
                        'size'     => $rawFiles['size'][$i],
                    ];
                }
            } elseif (isset($rawFiles['name'])) {
                $uploaded[] = $rawFiles;
            }
        }

        $params = $request->get_params();
        $result = $this->ticketService->createTicket($params, $uploaded);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 201);
    }

    /**
     * POST /tickets/{id}/reply handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function addReply(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ticketId = (int) $request['id'];
        $body = (string) $request->get_param('message');
        $token = $request->get_param('token');

        $files = $request->get_file_params();
        $uploaded = [];
        if (!empty($files['attachments'])) {
            $rawFiles = $files['attachments'];
            if (isset($rawFiles['name']) && is_array($rawFiles['name'])) {
                $count = count($rawFiles['name']);
                for ($i = 0; $i < $count; $i++) {
                    $uploaded[] = [
                        'name'     => $rawFiles['name'][$i],
                        'type'     => $rawFiles['type'][$i],
                        'tmp_name' => $rawFiles['tmp_name'][$i],
                        'error'    => $rawFiles['error'][$i],
                        'size'     => $rawFiles['size'][$i],
                    ];
                }
            } elseif (isset($rawFiles['name'])) {
                $uploaded[] = $rawFiles;
            }
        }

        $result = $this->conversationService->addReply(
            $ticketId,
            $body,
            get_current_user_id(),
            $uploaded,
            $token ? (string) $token : null
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 201);
    }

    /**
     * POST /tickets/{id}/note handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function addInternalNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ticketId = (int) $request['id'];
        $body = (string) $request->get_param('note');

        $files = $request->get_file_params();
        $uploaded = [];
        if (!empty($files['attachments'])) {
            $rawFiles = $files['attachments'];
            if (isset($rawFiles['name']) && is_array($rawFiles['name'])) {
                $count = count($rawFiles['name']);
                for ($i = 0; $i < $count; $i++) {
                    $uploaded[] = [
                        'name'     => $rawFiles['name'][$i],
                        'type'     => $rawFiles['type'][$i],
                        'tmp_name' => $rawFiles['tmp_name'][$i],
                        'error'    => $rawFiles['error'][$i],
                        'size'     => $rawFiles['size'][$i],
                    ];
                }
            } elseif (isset($rawFiles['name'])) {
                $uploaded[] = $rawFiles;
            }
        }

        $result = $this->conversationService->addInternalNote(
            $ticketId,
            $body,
            get_current_user_id(),
            $uploaded
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result, 201);
    }

    /**
     * POST /tickets/{id}/status handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function updateStatus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ticketId = (int) $request['id'];
        $newStatus = sanitize_key((string) $request->get_param('status'));
        $token = $request->get_param('token');

        $userId = get_current_user_id();
        $isAgent = ($userId > 0 && user_can($userId, RoleManager::CAP_CHANGE_STATUS));
        $actorType = $isAgent ? 'agent' : ($userId > 0 ? 'customer' : 'guest');

        $result = $this->ticketService->updateStatus(
            $ticketId,
            $newStatus,
            $userId,
            $actorType,
            $token ? (string) $token : null
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(['success' => true, 'status' => $newStatus], 200);
    }

    /**
     * POST /tickets/{id}/priority handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function updatePriority(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ticketId = (int) $request['id'];
        $newPriority = sanitize_key((string) $request->get_param('priority'));

        $result = $this->ticketService->updatePriority($ticketId, $newPriority, get_current_user_id());
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(['success' => true, 'priority' => $newPriority], 200);
    }

    /**
     * POST /tickets/{id}/assign handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function assignAgent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ticketId = (int) $request['id'];
        $agentIdParam = $request->get_param('agent_id');
        $agentId = (!empty($agentIdParam) && $agentIdParam !== 'unassigned') ? (int) $agentIdParam : null;

        $result = $this->ticketService->assignAgent($ticketId, $agentId, get_current_user_id());
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response(['success' => true, 'assigned_agent_id' => $agentId], 200);
    }

    /**
     * GET /categories handler.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function getCategories(WP_REST_Request $request): WP_REST_Response
    {
        $all = (bool) $request->get_param('all');
        $activeOnly = !$all || !current_user_can('manage_options');
        $categories = $this->categoryRepo->getAll($activeOnly);

        return new WP_REST_Response($categories, 200);
    }

    /**
     * GET /attachments/{id}/download handler.
     *
     * @param WP_REST_Request $request
     * @return void
     */
    public function downloadAttachment(WP_REST_Request $request): void
    {
        $attachmentId = (int) $request['id'];
        $token = $request->get_param('token');
        $guestToken = $request->get_param('guest_token');

        AttachmentSecurity::streamDownload(
            $attachmentId,
            $guestToken ? (string) $guestToken : null,
            $token ? (string) $token : null
        );
    }
}
