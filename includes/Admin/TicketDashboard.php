<?php
/**
 * Admin Ticket Dashboard and Ticket Detail View.
 *
 * @package WPCalibrate\SupportDesk\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Admin;

use WPCalibrate\SupportDesk\Auth\RoleManager;
use WPCalibrate\SupportDesk\Repositories\TicketRepository;
use WPCalibrate\SupportDesk\Repositories\MessageRepository;
use WPCalibrate\SupportDesk\Repositories\ActivityRepository;
use WPCalibrate\SupportDesk\Repositories\CategoryRepository;
use WPCalibrate\SupportDesk\Services\TicketService;
use WPCalibrate\SupportDesk\Services\ConversationService;
use WPCalibrate\SupportDesk\Services\StatsService;
use WPCalibrate\SupportDesk\Security\AccessPolicy;
use WPCalibrate\SupportDesk\Integrations\WooCommerce\WooCommerceIntegration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class TicketDashboard
 */
final class TicketDashboard
{
    /**
     * Render the admin page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can(RoleManager::CAP_VIEW_TICKETS)) {
            wp_die(esc_html__('You do not have permission to access WPCalibrate Support Desk.', 'wpcalibrate-support-desk'), 403);
        }

        $action = sanitize_key($_GET['action'] ?? 'list');
        $ticketId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        // Process POST actions if any.
        self::handlePostActions();

        echo '<div class="wrap wpcalibrate-admin-wrap">';
        self::renderHeader();

        if ($action === 'view' && $ticketId > 0) {
            self::renderTicketDetail($ticketId);
        } else {
            self::renderTicketList();
        }

        echo '</div>';
    }

    /**
     * Render branding header.
     *
     * @return void
     */
    private static function renderHeader(): void
    {
        $iconDarkUrl = WPCALIBRATE_SUPPORT_DESK_URL . 'branding/icon-dark.png';
        ?>
        <div class="wpcalibrate-header">
            <div class="wpcalibrate-header-left">
                <img src="<?php echo esc_url($iconDarkUrl); ?>" alt="WPCalibrate" class="wpcalibrate-logo" />
                <h1><?php esc_html_e('Support Desk', 'wpcalibrate-support-desk'); ?></h1>
            </div>
            <div class="wpcalibrate-header-right">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_SETTINGS . '&tab=overview')); ?>" class="button button-secondary" style="margin-right: 6px;">
                    <span class="dashicons dashicons-book" style="vertical-align: text-top; margin-right: 4px; font-size: 17px; width: 17px; height: 17px;"></span><?php esc_html_e('Overview & Guide', 'wpcalibrate-support-desk'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_SETTINGS)); ?>" class="button">
                    <?php esc_html_e('Settings', 'wpcalibrate-support-desk'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Handle state-changing POST actions with nonces and capabilities.
     *
     * @return void
     */
    private static function handlePostActions(): void
    {
        if (empty($_POST['wpcalibrate_admin_action'])) {
            return;
        }

        check_admin_referer('wpcalibrate_admin_action_nonce', 'wpcalibrate_nonce');

        $action = sanitize_key($_POST['wpcalibrate_admin_action']);
        $ticketId = isset($_POST['ticket_id']) ? (int) $_POST['ticket_id'] : 0;
        $currentUserId = get_current_user_id();

        $ticketService = new TicketService();
        $convService = new ConversationService();

        if ($action === 'add_reply' && $ticketId > 0) {
            $message = wp_unslash($_POST['reply_message'] ?? '');
            $result = $convService->addReply($ticketId, $message, $currentUserId);
            if (is_wp_error($result)) {
                add_settings_error('wpcalibrate_messages', 'reply_error', $result->get_error_message(), 'error');
            } else {
                add_settings_error('wpcalibrate_messages', 'reply_success', __('Reply posted successfully.', 'wpcalibrate-support-desk'), 'success');
            }
        } elseif ($action === 'add_note' && $ticketId > 0) {
            $note = wp_unslash($_POST['note_message'] ?? '');
            $result = $convService->addInternalNote($ticketId, $note, $currentUserId);
            if (is_wp_error($result)) {
                add_settings_error('wpcalibrate_messages', 'note_error', $result->get_error_message(), 'error');
            } else {
                add_settings_error('wpcalibrate_messages', 'note_success', __('Internal note added.', 'wpcalibrate-support-desk'), 'success');
            }
        } elseif ($action === 'update_meta' && $ticketId > 0) {
            if (!empty($_POST['ticket_status'])) {
                $status = sanitize_key($_POST['ticket_status']);
                $ticketService->updateStatus($ticketId, $status, $currentUserId, 'agent');
            }
            if (!empty($_POST['ticket_priority'])) {
                $priority = sanitize_key($_POST['ticket_priority']);
                $ticketService->updatePriority($ticketId, $priority, $currentUserId);
            }
            if (isset($_POST['assigned_agent_id'])) {
                $agentId = $_POST['assigned_agent_id'] !== '' ? (int) $_POST['assigned_agent_id'] : null;
                $ticketService->assignAgent($ticketId, $agentId, $currentUserId);
            }
            if (!empty($_POST['category_id'])) {
                $catId = (int) $_POST['category_id'];
                $ticketService->updateCategory($ticketId, $catId, $currentUserId);
            }
            add_settings_error('wpcalibrate_messages', 'meta_success', __('Ticket updated.', 'wpcalibrate-support-desk'), 'success');
        } elseif ($action === 'delete_ticket' && $ticketId > 0) {
            if (AccessPolicy::canDeleteTicket($currentUserId)) {
                $repo = new TicketRepository();
                $repo->delete($ticketId);
                wp_safe_redirect(admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_DASHBOARD));
                exit;
            }
        }
    }

    /**
     * Render Ticket List screen with summary cards, filters, and table.
     *
     * @return void
     */
    private static function renderTicketList(): void
    {
        settings_errors('wpcalibrate_messages');

        $stats = StatsService::getSummary();
        $catRepo = new CategoryRepository();
        $categories = $catRepo->getAll(false);

        // Agents list for filtering.
        $agents = get_users(['role__in' => [RoleManager::ROLE_AGENT, 'administrator']]);

        // Current filter values.
        $statusFilter   = sanitize_key($_GET['status'] ?? '');
        $priorityFilter = sanitize_key($_GET['priority'] ?? '');
        $catFilter      = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : null;
        $agentFilter    = sanitize_text_field($_GET['agent_id'] ?? '');
        $searchQuery    = sanitize_text_field($_GET['s'] ?? '');
        $currentPage    = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage        = (int) get_option('wpcalibrate_records_per_page', 20);

        $repo = new TicketRepository();
        $userId = get_current_user_id();
        $isAgentAll = user_can($userId, RoleManager::CAP_VIEW_ALL_TICKETS);

        $queryArgs = [
            'status'      => $statusFilter,
            'priority'    => $priorityFilter,
            'category_id' => $catFilter,
            'search'      => $searchQuery,
            'page'        => $currentPage,
            'per_page'    => $perPage,
        ];

        if ($isAgentAll) {
            if ($agentFilter !== '') {
                $queryArgs['assigned_agent_id'] = ($agentFilter === 'unassigned') ? 'unassigned' : (int) $agentFilter;
            }
        } else {
            $queryArgs['assigned_agent_id'] = $userId;
        }

        $result = $repo->query($queryArgs);
        $tickets = $result['items'];
        $totalPages = $result['pages'];
        $totalItems = $result['total'];

        ?>
        <!-- Summary Stats Cards -->
        <div class="wpcalibrate-stats-grid">
            <a href="<?php echo esc_url(add_query_arg('status', 'open')); ?>" class="wpcalibrate-card <?php echo $statusFilter === 'open' ? 'active' : ''; ?>">
                <div class="stat-number"><?php echo (int) $stats['open']; ?></div>
                <div class="stat-label"><?php esc_html_e('Open', 'wpcalibrate-support-desk'); ?></div>
            </a>
            <a href="<?php echo esc_url(add_query_arg('status', 'in_progress')); ?>" class="wpcalibrate-card <?php echo $statusFilter === 'in_progress' ? 'active' : ''; ?>">
                <div class="stat-number"><?php echo (int) $stats['in_progress']; ?></div>
                <div class="stat-label"><?php esc_html_e('In Progress', 'wpcalibrate-support-desk'); ?></div>
            </a>
            <a href="<?php echo esc_url(add_query_arg('status', 'waiting_customer')); ?>" class="wpcalibrate-card <?php echo $statusFilter === 'waiting_customer' ? 'active' : ''; ?>">
                <div class="stat-number"><?php echo (int) $stats['waiting_customer']; ?></div>
                <div class="stat-label"><?php esc_html_e('Waiting for Customer', 'wpcalibrate-support-desk'); ?></div>
            </a>
            <a href="<?php echo esc_url(add_query_arg('status', 'resolved')); ?>" class="wpcalibrate-card <?php echo $statusFilter === 'resolved' ? 'active' : ''; ?>">
                <div class="stat-number"><?php echo (int) $stats['resolved']; ?></div>
                <div class="stat-label"><?php esc_html_e('Resolved', 'wpcalibrate-support-desk'); ?></div>
            </a>
            <a href="<?php echo esc_url(add_query_arg('status', 'closed')); ?>" class="wpcalibrate-card <?php echo $statusFilter === 'closed' ? 'active' : ''; ?>">
                <div class="stat-number"><?php echo (int) $stats['closed']; ?></div>
                <div class="stat-label"><?php esc_html_e('Closed', 'wpcalibrate-support-desk'); ?></div>
            </a>
            <a href="<?php echo esc_url(add_query_arg('agent_id', 'unassigned')); ?>" class="wpcalibrate-card <?php echo $agentFilter === 'unassigned' ? 'active' : ''; ?>">
                <div class="stat-number"><?php echo (int) $stats['unassigned']; ?></div>
                <div class="stat-label"><?php esc_html_e('Unassigned', 'wpcalibrate-support-desk'); ?></div>
            </a>
        </div>

        <!-- Filter Bar -->
        <form method="get" class="wpcalibrate-filter-bar">
            <input type="hidden" name="page" value="<?php echo esc_attr(AdminMenu::PAGE_SLUG_DASHBOARD); ?>" />

            <div class="filter-controls">
                <select name="status">
                    <option value=""><?php esc_html_e('All Statuses', 'wpcalibrate-support-desk'); ?></option>
                    <option value="open" <?php selected($statusFilter, 'open'); ?>><?php esc_html_e('Open', 'wpcalibrate-support-desk'); ?></option>
                    <option value="in_progress" <?php selected($statusFilter, 'in_progress'); ?>><?php esc_html_e('In Progress', 'wpcalibrate-support-desk'); ?></option>
                    <option value="waiting_customer" <?php selected($statusFilter, 'waiting_customer'); ?>><?php esc_html_e('Waiting for Customer', 'wpcalibrate-support-desk'); ?></option>
                    <option value="resolved" <?php selected($statusFilter, 'resolved'); ?>><?php esc_html_e('Resolved', 'wpcalibrate-support-desk'); ?></option>
                    <option value="closed" <?php selected($statusFilter, 'closed'); ?>><?php esc_html_e('Closed', 'wpcalibrate-support-desk'); ?></option>
                </select>

                <select name="priority">
                    <option value=""><?php esc_html_e('All Priorities', 'wpcalibrate-support-desk'); ?></option>
                    <option value="low" <?php selected($priorityFilter, 'low'); ?>><?php esc_html_e('Low', 'wpcalibrate-support-desk'); ?></option>
                    <option value="normal" <?php selected($priorityFilter, 'normal'); ?>><?php esc_html_e('Normal', 'wpcalibrate-support-desk'); ?></option>
                    <option value="high" <?php selected($priorityFilter, 'high'); ?>><?php esc_html_e('High', 'wpcalibrate-support-desk'); ?></option>
                    <option value="urgent" <?php selected($priorityFilter, 'urgent'); ?>><?php esc_html_e('Urgent', 'wpcalibrate-support-desk'); ?></option>
                </select>

                <select name="category_id">
                    <option value=""><?php esc_html_e('All Departments', 'wpcalibrate-support-desk'); ?></option>
                    <?php foreach ($categories as $cat) : ?>
                        <option value="<?php echo (int) $cat['id']; ?>" <?php selected($catFilter, (int) $cat['id']); ?>>
                            <?php echo esc_html($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($isAgentAll) : ?>
                <select name="agent_id">
                    <option value=""><?php esc_html_e('All Agents', 'wpcalibrate-support-desk'); ?></option>
                    <option value="unassigned" <?php selected($agentFilter, 'unassigned'); ?>><?php esc_html_e('Unassigned', 'wpcalibrate-support-desk'); ?></option>
                    <?php foreach ($agents as $agent) : ?>
                        <option value="<?php echo (int) $agent->ID; ?>" <?php selected($agentFilter, (string) $agent->ID); ?>>
                            <?php echo esc_html($agent->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <input type="search" name="s" value="<?php echo esc_attr($searchQuery); ?>" placeholder="<?php esc_attr_e('Search reference, subject, customer...', 'wpcalibrate-support-desk'); ?>" class="search-input" />

                <button type="submit" class="button"><?php esc_html_e('Filter', 'wpcalibrate-support-desk'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_DASHBOARD)); ?>" class="button"><?php esc_html_e('Reset', 'wpcalibrate-support-desk'); ?></a>
            </div>
        </form>

        <!-- Tickets Table -->
        <table class="wp-list-table widefat fixed striped wpcalibrate-table">
            <thead>
                <tr>
                    <th scope="col" style="width: 120px;"><?php esc_html_e('Reference', 'wpcalibrate-support-desk'); ?></th>
                    <th scope="col"><?php esc_html_e('Subject', 'wpcalibrate-support-desk'); ?></th>
                    <th scope="col"><?php esc_html_e('Customer', 'wpcalibrate-support-desk'); ?></th>
                    <th scope="col"><?php esc_html_e('Department', 'wpcalibrate-support-desk'); ?></th>
                    <th scope="col" style="width: 90px;"><?php esc_html_e('Priority', 'wpcalibrate-support-desk'); ?></th>
                    <th scope="col" style="width: 120px;"><?php esc_html_e('Status', 'wpcalibrate-support-desk'); ?></th>
                    <th scope="col"><?php esc_html_e('Assigned', 'wpcalibrate-support-desk'); ?></th>
                    <th scope="col"><?php esc_html_e('Last Activity', 'wpcalibrate-support-desk'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)) : ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <p><?php esc_html_e('No support tickets found matching criteria.', 'wpcalibrate-support-desk'); ?></p>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($tickets as $t) :
                        $viewUrl = admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_DASHBOARD . '&action=view&id=' . (int) $t['id']);
                        $customerName = !empty($t['customer_id']) ? (get_userdata((int) $t['customer_id'])->display_name ?? 'User') : ($t['guest_name'] ?? 'Guest');
                        $agentName = !empty($t['assigned_agent_id']) ? (get_userdata((int) $t['assigned_agent_id'])->display_name ?? 'Agent') : __('Unassigned', 'wpcalibrate-support-desk');
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url($viewUrl); ?>" class="ticket-ref-link">
                                    <strong><?php echo esc_html($t['ticket_ref']); ?></strong>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($viewUrl); ?>">
                                    <strong><?php echo esc_html($t['subject']); ?></strong>
                                </a>
                                <?php if (!empty($t['woo_order_id'])) : ?>
                                    <span class="badge badge-woo">#<?php echo (int) $t['woo_order_id']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($customerName); ?></td>
                            <td><?php echo esc_html($t['category_name'] ?? 'General'); ?></td>
                            <td><span class="badge badge-priority-<?php echo esc_attr($t['priority']); ?>"><?php echo esc_html(ucfirst($t['priority'])); ?></span></td>
                            <td><span class="badge badge-status-<?php echo esc_attr($t['status']); ?>"><?php echo esc_html(str_replace('_', ' ', ucfirst($t['status']))); ?></span></td>
                            <td><?php echo esc_html($agentName); ?></td>
                            <td><?php echo esc_html(human_time_diff(strtotime($t['last_reply_at']), current_time('timestamp', true)) . ' ' . __('ago', 'wpcalibrate-support-desk')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Server-side Pagination -->
        <?php if ($totalPages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf(esc_html__('%d items', 'wpcalibrate-support-desk'), $totalItems); ?></span>
                    <span class="pagination-links">
                        <?php if ($currentPage > 1) : ?>
                            <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $currentPage - 1)); ?>">&laquo; <?php esc_html_e('Prev', 'wpcalibrate-support-desk'); ?></a>
                        <?php endif; ?>
                        <span class="paging-input">
                            <span class="current-page"><?php echo (int) $currentPage; ?></span> <?php esc_html_e('of', 'wpcalibrate-support-desk'); ?> <span class="total-pages"><?php echo (int) $totalPages; ?></span>
                        </span>
                        <?php if ($currentPage < $totalPages) : ?>
                            <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $currentPage + 1)); ?>"><?php esc_html_e('Next', 'wpcalibrate-support-desk'); ?> &raquo;</a>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render Ticket Detail view with conversation, internal notes, and sidebar controls.
     *
     * @param int $ticketId
     * @return void
     */
    private static function renderTicketDetail(int $ticketId): void
    {
        settings_errors('wpcalibrate_messages');

        $ticketRepo = new TicketRepository();
        $ticket = $ticketRepo->getById($ticketId);
        if (!$ticket) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Ticket not found.', 'wpcalibrate-support-desk') . '</p></div>';
            return;
        }

        $userId = get_current_user_id();
        if (!AccessPolicy::canViewTicket($ticket, $userId)) {
            echo '<div class="notice notice-error"><p>' . esc_html__('You do not have permission to view this ticket.', 'wpcalibrate-support-desk') . '</p></div>';
            return;
        }

        $msgRepo = new MessageRepository();
        $activityRepo = new ActivityRepository();
        $catRepo = new CategoryRepository();

        $messages = $msgRepo->getByTicket($ticketId, true); // true includes internal notes for staff
        $activity = $activityRepo->getByTicket($ticketId, true);
        $categories = $catRepo->getAll(false);
        $agents = get_users(['role__in' => [RoleManager::ROLE_AGENT, 'administrator']]);

        // Customer details.
        $customerName = !empty($ticket['customer_id']) ? (get_userdata((int) $ticket['customer_id'])->display_name ?? 'User') : ($ticket['guest_name'] ?? 'Guest');
        $customerEmail = !empty($ticket['customer_id']) ? (get_userdata((int) $ticket['customer_id'])->user_email ?? '') : ($ticket['guest_email'] ?? '');

        // Woo order summary if available.
        $wooOrder = null;
        if (!empty($ticket['woo_order_id'])) {
            $wooOrder = WooCommerceIntegration::getOrderSummary((int) $ticket['woo_order_id']);
        }
        ?>
        <div class="wpcalibrate-detail-layout">
            <!-- Left Main Column: Conversation & Reply Forms -->
            <div class="wpcalibrate-detail-main">
                <div class="wpcalibrate-detail-header">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_DASHBOARD)); ?>" class="back-link">&larr; <?php esc_html_e('Back to Tickets', 'wpcalibrate-support-desk'); ?></a>
                    <h2>
                        <span class="ticket-ref-tag"><?php echo esc_html($ticket['ticket_ref']); ?></span>
                        <?php echo esc_html($ticket['subject']); ?>
                    </h2>
                    <div class="ticket-meta-subtitle">
                        <?php printf(esc_html__('Opened by %1$s on %2$s', 'wpcalibrate-support-desk'), '<strong>' . esc_html($customerName) . '</strong>', esc_html($ticket['created_at'])); ?>
                    </div>
                </div>

                <!-- Conversation Thread -->
                <div class="wpcalibrate-thread">
                    <?php foreach ($messages as $msg) :
                        $isInternal = ($msg['visibility'] === 'internal' || $msg['message_type'] === 'note');
                    ?>
                        <div class="wpcalibrate-message <?php echo $isInternal ? 'message-internal-note' : 'message-public-reply'; ?>">
                            <div class="message-header">
                                <span class="message-author">
                                    <strong><?php echo esc_html($msg['author_name']); ?></strong>
                                    <?php if ($isInternal) : ?>
                                        <span class="badge badge-internal-note"><?php esc_html_e('INTERNAL NOTE - STAFF ONLY', 'wpcalibrate-support-desk'); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="message-date"><?php echo esc_html($msg['created_at']); ?></span>
                            </div>
                            <div class="message-body">
                                <?php echo wp_kses_post($msg['body']); ?>
                            </div>
                            <?php if (!empty($msg['attachments'])) : ?>
                                <div class="message-attachments">
                                    <strong><?php esc_html_e('Attachments:', 'wpcalibrate-support-desk'); ?></strong>
                                    <ul>
                                        <?php foreach ($msg['attachments'] as $att) : ?>
                                            <li>
                                                <a href="<?php echo esc_url($att['download_url']); ?>" target="_blank" rel="noopener">
                                                    📎 <?php echo esc_html($att['file_name']); ?>
                                                </a>
                                                <span class="file-size">(<?php echo esc_html(size_format((int) $att['file_size'])); ?>)</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Response Editor with Tabs: Public Reply vs Internal Note -->
                <div class="wpcalibrate-composer">
                    <div class="composer-tabs">
                        <button type="button" class="composer-tab-btn active" data-tab="tab-reply"><?php esc_html_e('Public Reply', 'wpcalibrate-support-desk'); ?></button>
                        <button type="button" class="composer-tab-btn" data-tab="tab-note"><?php esc_html_e('Internal Note', 'wpcalibrate-support-desk'); ?></button>
                    </div>

                    <!-- Public Reply Form -->
                    <form method="post" id="tab-reply" class="composer-pane active">
                        <?php wp_nonce_field('wpcalibrate_admin_action_nonce', 'wpcalibrate_nonce'); ?>
                        <input type="hidden" name="wpcalibrate_admin_action" value="add_reply" />
                        <input type="hidden" name="ticket_id" value="<?php echo (int) $ticketId; ?>" />

                        <textarea name="reply_message" rows="5" required placeholder="<?php esc_attr_e('Write a reply visible to the customer...', 'wpcalibrate-support-desk'); ?>" class="large-text"></textarea>
                        <div class="composer-actions">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Send Customer Reply', 'wpcalibrate-support-desk'); ?></button>
                        </div>
                    </form>

                    <!-- Internal Note Form -->
                    <form method="post" id="tab-note" class="composer-pane" style="display: none;">
                        <?php wp_nonce_field('wpcalibrate_admin_action_nonce', 'wpcalibrate_nonce'); ?>
                        <input type="hidden" name="wpcalibrate_admin_action" value="add_note" />
                        <input type="hidden" name="ticket_id" value="<?php echo (int) $ticketId; ?>" />

                        <div class="note-warning-alert">
                            <?php esc_html_e('Internal notes are completely hidden from customers and never sent in emails.', 'wpcalibrate-support-desk'); ?>
                        </div>
                        <textarea name="note_message" rows="5" required placeholder="<?php esc_attr_e('Write an internal note for staff...', 'wpcalibrate-support-desk'); ?>" class="large-text"></textarea>
                        <div class="composer-actions">
                            <button type="submit" class="button button-secondary"><?php esc_html_e('Save Internal Note', 'wpcalibrate-support-desk'); ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Sidebar: Ticket Properties & Actions -->
            <div class="wpcalibrate-detail-sidebar">
                <form method="post" class="wpcalibrate-sidebar-card">
                    <?php wp_nonce_field('wpcalibrate_admin_action_nonce', 'wpcalibrate_nonce'); ?>
                    <input type="hidden" name="wpcalibrate_admin_action" value="update_meta" />
                    <input type="hidden" name="ticket_id" value="<?php echo (int) $ticketId; ?>" />

                    <h3><?php esc_html_e('Ticket Properties', 'wpcalibrate-support-desk'); ?></h3>

                    <div class="sidebar-field">
                        <label for="ticket_status"><strong><?php esc_html_e('Status', 'wpcalibrate-support-desk'); ?>:</strong></label>
                        <select name="ticket_status" id="ticket_status" class="widefat">
                            <option value="open" <?php selected($ticket['status'], 'open'); ?>><?php esc_html_e('Open', 'wpcalibrate-support-desk'); ?></option>
                            <option value="in_progress" <?php selected($ticket['status'], 'in_progress'); ?>><?php esc_html_e('In Progress', 'wpcalibrate-support-desk'); ?></option>
                            <option value="waiting_customer" <?php selected($ticket['status'], 'waiting_customer'); ?>><?php esc_html_e('Waiting for Customer', 'wpcalibrate-support-desk'); ?></option>
                            <option value="resolved" <?php selected($ticket['status'], 'resolved'); ?>><?php esc_html_e('Resolved', 'wpcalibrate-support-desk'); ?></option>
                            <option value="closed" <?php selected($ticket['status'], 'closed'); ?>><?php esc_html_e('Closed', 'wpcalibrate-support-desk'); ?></option>
                        </select>
                    </div>

                    <div class="sidebar-field">
                        <label for="ticket_priority"><strong><?php esc_html_e('Priority', 'wpcalibrate-support-desk'); ?>:</strong></label>
                        <select name="ticket_priority" id="ticket_priority" class="widefat">
                            <option value="low" <?php selected($ticket['priority'], 'low'); ?>><?php esc_html_e('Low', 'wpcalibrate-support-desk'); ?></option>
                            <option value="normal" <?php selected($ticket['priority'], 'normal'); ?>><?php esc_html_e('Normal', 'wpcalibrate-support-desk'); ?></option>
                            <option value="high" <?php selected($ticket['priority'], 'high'); ?>><?php esc_html_e('High', 'wpcalibrate-support-desk'); ?></option>
                            <option value="urgent" <?php selected($ticket['priority'], 'urgent'); ?>><?php esc_html_e('Urgent', 'wpcalibrate-support-desk'); ?></option>
                        </select>
                    </div>

                    <div class="sidebar-field">
                        <label for="assigned_agent_id"><strong><?php esc_html_e('Assigned Agent', 'wpcalibrate-support-desk'); ?>:</strong></label>
                        <select name="assigned_agent_id" id="assigned_agent_id" class="widefat">
                            <option value=""><?php esc_html_e('Unassigned', 'wpcalibrate-support-desk'); ?></option>
                            <?php foreach ($agents as $agent) : ?>
                                <option value="<?php echo (int) $agent->ID; ?>" <?php selected((int) $ticket['assigned_agent_id'], (int) $agent->ID); ?>>
                                    <?php echo esc_html($agent->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sidebar-field">
                        <label for="category_id"><strong><?php esc_html_e('Department', 'wpcalibrate-support-desk'); ?>:</strong></label>
                        <select name="category_id" id="category_id" class="widefat">
                            <?php foreach ($categories as $cat) : ?>
                                <option value="<?php echo (int) $cat['id']; ?>" <?php selected((int) $ticket['category_id'], (int) $cat['id']); ?>>
                                    <?php echo esc_html($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="sidebar-actions">
                        <button type="submit" class="button button-primary widefat"><?php esc_html_e('Update Properties', 'wpcalibrate-support-desk'); ?></button>
                    </div>
                </form>

                <!-- Customer Info Card -->
                <div class="wpcalibrate-sidebar-card">
                    <h3><?php esc_html_e('Customer Information', 'wpcalibrate-support-desk'); ?></h3>
                    <p><strong><?php esc_html_e('Name', 'wpcalibrate-support-desk'); ?>:</strong> <?php echo esc_html($customerName); ?></p>
                    <p><strong><?php esc_html_e('Email', 'wpcalibrate-support-desk'); ?>:</strong> <a href="mailto:<?php echo esc_attr($customerEmail); ?>"><?php echo esc_html($customerEmail); ?></a></p>
                    <p><strong><?php esc_html_e('Account', 'wpcalibrate-support-desk'); ?>:</strong> <?php echo !empty($ticket['customer_id']) ? esc_html__('Registered User', 'wpcalibrate-support-desk') : esc_html__('Guest Submitter', 'wpcalibrate-support-desk'); ?></p>
                </div>

                <!-- WooCommerce Order Card (if present) -->
                <?php if ($wooOrder) : ?>
                    <div class="wpcalibrate-sidebar-card card-woo">
                        <h3><?php esc_html_e('Associated Order', 'wpcalibrate-support-desk'); ?></h3>
                        <p><strong><?php esc_html_e('Order', 'wpcalibrate-support-desk'); ?>:</strong> #<?php echo esc_html($wooOrder['order_number']); ?></p>
                        <p><strong><?php esc_html_e('Status', 'wpcalibrate-support-desk'); ?>:</strong> <?php echo esc_html($wooOrder['status']); ?></p>
                        <p><strong><?php esc_html_e('Total', 'wpcalibrate-support-desk'); ?>:</strong> <?php echo wp_kses_post($wooOrder['total']); ?></p>
                        <p><strong><?php esc_html_e('Items', 'wpcalibrate-support-desk'); ?>:</strong> <?php echo esc_html($wooOrder['items_summary']); ?></p>
                        <?php if (!empty($wooOrder['admin_edit_url'])) : ?>
                            <p><a href="<?php echo esc_url($wooOrder['admin_edit_url']); ?>" class="button button-secondary" target="_blank"><?php esc_html_e('View Order in WooCommerce', 'wpcalibrate-support-desk'); ?> &rarr;</a></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Activity Log Card -->
                <div class="wpcalibrate-sidebar-card">
                    <h3><?php esc_html_e('Activity History', 'wpcalibrate-support-desk'); ?></h3>
                    <ul class="activity-timeline">
                        <?php foreach (array_reverse($activity) as $act) : ?>
                            <li>
                                <span class="act-action"><?php echo esc_html(str_replace('_', ' ', ucfirst($act['action']))); ?></span>
                                <span class="act-date"><?php echo esc_html($act['created_at']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var tabs = document.querySelectorAll('.composer-tab-btn');
            tabs.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    tabs.forEach(function(t) { t.classList.remove('active'); });
                    document.querySelectorAll('.composer-pane').forEach(function(p) { p.style.display = 'none'; });
                    btn.classList.add('active');
                    var target = document.getElementById(btn.getAttribute('data-tab'));
                    if (target) { target.style.display = 'block'; }
                });
            });
        });
        </script>
        <?php
    }
}
