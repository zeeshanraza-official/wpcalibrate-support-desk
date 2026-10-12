<?php
/**
 * Frontend Support Portal shortcode and views renderer.
 *
 * @package WPCalibrate\SupportDesk\Frontend
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Frontend;

use WPCalibrate\SupportDesk\Repositories\TicketRepository;
use WPCalibrate\SupportDesk\Repositories\MessageRepository;
use WPCalibrate\SupportDesk\Repositories\CategoryRepository;
use WPCalibrate\SupportDesk\Services\TicketService;
use WPCalibrate\SupportDesk\Services\ConversationService;
use WPCalibrate\SupportDesk\Security\AccessPolicy;
use WPCalibrate\SupportDesk\Security\GuestTokenService;
use WPCalibrate\SupportDesk\Integrations\WooCommerce\WooCommerceIntegration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FrontendPortal
 */
final class FrontendPortal
{
    /**
     * Pending frontend action feedback notice.
     *
     * @var array{type: string, text: string}|null
     */
    private static ?array $pendingNotice = null;

    /**
     * Register shortcode and asset hooks.
     *
     * @return void
     */
    public static function register(): void
    {
        add_shortcode('wpcalibrate_support_portal', [self::class, 'renderShortcode']);
        add_shortcode('wpcalibrate_support_desk', [self::class, 'renderShortcode']);
        add_action('template_redirect', [self::class, 'handleEarlyFrontendPost']);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets']);
    }

    /**
     * Handle state-changing frontend POST requests early before headers are sent.
     *
     * @return void
     */
    public static function handleEarlyFrontendPost(): void
    {
        if (empty($_POST['wpcalibrate_frontend_action'])) {
            return;
        }

        self::$pendingNotice = self::handleFrontendPost();
    }

    /**
     * Register frontend styles and scripts.
     *
     * @return void
     */
    public static function registerAssets(): void
    {
        wp_register_style(
            'wpcalibrate-frontend-style',
            WPCALIBRATE_SUPPORT_DESK_URL . 'assets/css/frontend.css',
            [],
            WPCALIBRATE_SUPPORT_DESK_VERSION
        );

        wp_register_script(
            'wpcalibrate-frontend-script',
            WPCALIBRATE_SUPPORT_DESK_URL . 'assets/js/frontend.js',
            [],
            WPCALIBRATE_SUPPORT_DESK_VERSION,
            true
        );

        wp_localize_script('wpcalibrate-frontend-script', 'wpcalibrateSettings', [
            'restUrl' => esc_url_raw(rest_url('wpcalibrate-support-desk/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Render the frontend portal shortcode.
     *
     * @param array<string, mixed>|string $atts
     * @return string
     */
    public static function renderShortcode($atts = []): string
    {
        wp_enqueue_style('wpcalibrate-frontend-style');
        wp_enqueue_script('wpcalibrate-frontend-script');

        $atts = shortcode_atts([
            'view' => '',
        ], (array) $atts, 'wpcalibrate_support_portal');

        // Process POST actions if not already handled early on template_redirect.
        $messageNotice = self::$pendingNotice ?? self::handleFrontendPost();

        ob_start();
        echo '<div class="wpcalibrate-portal-container">';

        if (!empty($messageNotice)) {
            echo '<div class="wpcalibrate-alert ' . esc_attr($messageNotice['type']) . '">' . esc_html($messageNotice['text']) . '</div>';
        }

        // Determine view to render.
        $reqTicketRef = sanitize_text_field($_GET['wpc_ticket'] ?? $_GET['ticket'] ?? '');
        $reqGuestToken = sanitize_text_field($_GET['wpc_token'] ?? $_GET['token'] ?? '');
        $reqView = sanitize_key($_GET['wpc_view'] ?? $_GET['view'] ?? $atts['view']);

        if (!empty($reqTicketRef)) {
            self::renderSingleTicketView($reqTicketRef, $reqGuestToken);
        } elseif ($reqView === 'submit') {
            self::renderSubmitForm();
        } else {
            if (is_user_logged_in()) {
                self::renderMyTicketsView();
            } else {
                self::renderSubmitForm();
            }
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Handle state-changing frontend POST requests.
     *
     * @return array{type: string, text: string}|null
     */
    private static function handleFrontendPost(): ?array
    {
        if (empty($_POST['wpcalibrate_frontend_action'])) {
            return null;
        }

        try {
            if (!wp_verify_nonce($_POST['wpcalibrate_portal_nonce'] ?? '', 'wpcalibrate_portal_action')) {
                return ['type' => 'alert-error', 'text' => __('Security verification failed. Please refresh and try again.', 'wpcalibrate-support-desk')];
            }

            $action = sanitize_key($_POST['wpcalibrate_frontend_action']);
            $currentUserId = get_current_user_id();

            if ($action === 'submit_ticket') {
                $ticketService = new TicketService();

                // Extract files if uploaded.
                $uploaded = self::extractFiles();

                $params = [
                    'subject'        => $_POST['ticket_subject'] ?? '',
                    'message'        => $_POST['ticket_message'] ?? '',
                    'category_id'    => (int) ($_POST['ticket_category'] ?? 1),
                    'priority'       => $_POST['ticket_priority'] ?? 'normal',
                    'customer_id'    => $currentUserId,
                    'guest_name'     => $_POST['guest_name'] ?? null,
                    'guest_email'    => $_POST['guest_email'] ?? null,
                    'woo_order_id'   => !empty($_POST['woo_order_id']) ? (int) $_POST['woo_order_id'] : null,
                    'website_url_hp' => $_POST['website_url_hp'] ?? null, // honeypot
                ];

                $result = $ticketService->createTicket($params, $uploaded);
                if (is_wp_error($result)) {
                    return ['type' => 'alert-error', 'text' => $result->get_error_message()];
                }

                // Redirect if guest with secure access URL.
                if (!empty($result['access_url'])) {
                    if (!headers_sent()) {
                        wp_safe_redirect($result['access_url']);
                        exit;
                    }
                    echo '<script>window.location.href = ' . json_encode($result['access_url']) . ';</script>';
                    return ['type' => 'alert-success', 'text' => sprintf(__('Ticket %s submitted successfully! Redirecting...', 'wpcalibrate-support-desk'), $result['ticket_ref'])];
                }

                return ['type' => 'alert-success', 'text' => sprintf(__('Ticket %s submitted successfully!', 'wpcalibrate-support-desk'), $result['ticket_ref'])];
            } elseif ($action === 'add_reply') {
                $ticketId = (int) ($_POST['ticket_id'] ?? 0);
                $message = $_POST['reply_body'] ?? '';
                $guestToken = !empty($_POST['guest_token']) ? sanitize_text_field($_POST['guest_token']) : null;

                $convService = new ConversationService();
                $uploaded = self::extractFiles();

                $result = $convService->addReply($ticketId, $message, $currentUserId, $uploaded, $guestToken);
                if (is_wp_error($result)) {
                    return ['type' => 'alert-error', 'text' => $result->get_error_message()];
                }

                return ['type' => 'alert-success', 'text' => __('Reply posted successfully.', 'wpcalibrate-support-desk')];
            } elseif ($action === 'change_status') {
                $ticketId = (int) ($_POST['ticket_id'] ?? 0);
                $newStatus = sanitize_key($_POST['new_status'] ?? '');
                $guestToken = !empty($_POST['guest_token']) ? sanitize_text_field($_POST['guest_token']) : null;

                $ticketService = new TicketService();
                $actorType = $currentUserId > 0 ? 'customer' : 'guest';
                $result = $ticketService->updateStatus($ticketId, $newStatus, $currentUserId, $actorType, $guestToken);

                if (is_wp_error($result)) {
                    return ['type' => 'alert-error', 'text' => $result->get_error_message()];
                }

                return ['type' => 'alert-success', 'text' => __('Ticket status updated.', 'wpcalibrate-support-desk')];
            }
        } catch (\Throwable $e) {
            Logger::log('error', 'Exception in handleFrontendPost: ' . $e->getMessage());
            return ['type' => 'alert-error', 'text' => __('An unexpected error occurred while processing your ticket. Please try again.', 'wpcalibrate-support-desk')];
        }

        return null;
    }

    /**
     * Render Navigation Bar.
     *
     * @param string $activeView
     * @return void
     */
    private static function renderNavBar(string $activeView = ''): void
    {
        $currentUrl = remove_query_arg(['wpc_ticket', 'wpc_token', 'wpc_view']);
        ?>
        <nav class="wpcalibrate-portal-nav" aria-label="<?php esc_attr_e('Support Navigation', 'wpcalibrate-support-desk'); ?>">
            <?php if (is_user_logged_in()) : ?>
                <a href="<?php echo esc_url($currentUrl); ?>" class="nav-item <?php echo empty($activeView) ? 'active' : ''; ?>">
                    <?php esc_html_e('My Tickets', 'wpcalibrate-support-desk'); ?>
                </a>
            <?php endif; ?>
            <a href="<?php echo esc_url(add_query_arg('wpc_view', 'submit', $currentUrl)); ?>" class="nav-item <?php echo $activeView === 'submit' ? 'active' : ''; ?>">
                <?php esc_html_e('Submit a Ticket', 'wpcalibrate-support-desk'); ?>
            </a>
        </nav>
        <?php
    }

    /**
     * Render My Tickets view for logged-in users.
     *
     * @return void
     */
    private static function renderMyTicketsView(): void
    {
        self::renderNavBar('');

        $userId = get_current_user_id();
        $ticketRepo = new TicketRepository();
        $search = sanitize_text_field($_GET['s'] ?? '');
        $status = sanitize_key($_GET['status'] ?? '');
        $page = max(1, (int) ($_GET['paged'] ?? 1));

        $result = $ticketRepo->query([
            'customer_id' => $userId,
            'search'      => $search,
            'status'      => $status,
            'page'        => $page,
            'per_page'    => 10,
        ]);

        $tickets = $result['items'];
        $totalPages = $result['pages'];
        ?>
        <div class="wpcalibrate-portal-header">
            <h2><?php esc_html_e('My Support Tickets', 'wpcalibrate-support-desk'); ?></h2>
            <a href="<?php echo esc_url(add_query_arg('wpc_view', 'submit')); ?>" class="button button-primary wpcalibrate-btn">
                <?php esc_html_e('+ Open New Ticket', 'wpcalibrate-support-desk'); ?>
            </a>
        </div>

        <?php if (empty($tickets)) : ?>
            <div class="wpcalibrate-empty-box">
                <p><?php esc_html_e('You do not have any support tickets currently.', 'wpcalibrate-support-desk'); ?></p>
            </div>
        <?php else : ?>
            <div class="wpcalibrate-tickets-table-wrap">
                <table class="wpcalibrate-frontend-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Ticket', 'wpcalibrate-support-desk'); ?></th>
                            <th><?php esc_html_e('Subject', 'wpcalibrate-support-desk'); ?></th>
                            <th><?php esc_html_e('Status', 'wpcalibrate-support-desk'); ?></th>
                            <th><?php esc_html_e('Last Activity', 'wpcalibrate-support-desk'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t) :
                            $ticketUrl = add_query_arg('wpc_ticket', rawurlencode($t['ticket_ref']));
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($ticketUrl); ?>" class="ticket-ref-badge">
                                        <?php echo esc_html($t['ticket_ref']); ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($ticketUrl); ?>">
                                        <strong><?php echo esc_html($t['subject']); ?></strong>
                                    </a>
                                    <?php if (!empty($t['woo_order_id'])) : ?>
                                        <span class="badge badge-woo">#<?php echo (int) $t['woo_order_id']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-status-<?php echo esc_attr($t['status']); ?>"><?php echo esc_html(str_replace('_', ' ', ucfirst($t['status']))); ?></span></td>
                                <td><?php echo esc_html(human_time_diff(strtotime($t['last_reply_at']), current_time('timestamp', true)) . ' ' . __('ago', 'wpcalibrate-support-desk')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1) : ?>
                <div class="wpcalibrate-pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++) : ?>
                        <a href="<?php echo esc_url(add_query_arg('paged', $i)); ?>" class="page-num <?php echo $page === $i ? 'active' : ''; ?>">
                            <?php echo (int) $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Render Submit Ticket Form.
     *
     * @return void
     */
    private static function renderSubmitForm(): void
    {
        self::renderNavBar('submit');

        $isLoggedIn = is_user_logged_in();
        $guestAllowed = (bool) get_option('wpcalibrate_guest_tickets_enabled', '1');

        if (!$isLoggedIn && !$guestAllowed) {
            ?>
            <div class="wpcalibrate-alert alert-warning">
                <p><?php esc_html_e('You must be logged in to submit a support ticket.', 'wpcalibrate-support-desk'); ?>
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>"><?php esc_html_e('Log in here', 'wpcalibrate-support-desk'); ?> &rarr;</a></p>
            </div>
            <?php
            return;
        }

        $catRepo = new CategoryRepository();
        $categories = $catRepo->getAll(true);
        if (empty($categories)) {
            $categories = [
                [
                    'id'   => 1,
                    'name' => __('General Support', 'wpcalibrate-support-desk'),
                ],
            ];
        }
        $allowPriority = (bool) get_option('wpcalibrate_customer_priority_selection', '0');
        $attEnabled = \WPCalibrate\SupportDesk\Security\AttachmentSecurity::isEnabled();
        $maxMb = (int) get_option('wpcalibrate_max_attachment_size_mb', 5);

        // Eligible WooCommerce orders if active and customer logged in.
        $customerOrders = [];
        if ($isLoggedIn && WooCommerceIntegration::isEnabled()) {
            $customerOrders = WooCommerceIntegration::getCustomerOrders(get_current_user_id());
        }
        ?>
        <div class="wpcalibrate-form-card">
            <h2><?php esc_html_e('Open a Support Ticket', 'wpcalibrate-support-desk'); ?></h2>
            <p><?php esc_html_e('Fill out the form below and our support team will respond as soon as possible.', 'wpcalibrate-support-desk'); ?></p>

            <form method="post" enctype="multipart/form-data" class="wpcalibrate-portal-form">
                <?php wp_nonce_field('wpcalibrate_portal_action', 'wpcalibrate_portal_nonce'); ?>
                <input type="hidden" name="wpcalibrate_frontend_action" value="submit_ticket" />

                <!-- Anti-spam Honeypot -->
                <div style="display:none;" aria-hidden="true">
                    <label for="website_url_hp"><?php esc_html_e('Leave this field blank', 'wpcalibrate-support-desk'); ?></label>
                    <input type="text" name="website_url_hp" id="website_url_hp" tabindex="-1" autocomplete="off" />
                </div>

                <?php if (!$isLoggedIn) : ?>
                    <div class="form-row form-row-split">
                        <div class="form-group">
                            <label for="guest_name"><?php esc_html_e('Your Name', 'wpcalibrate-support-desk'); ?> <span class="required">*</span></label>
                            <input type="text" name="guest_name" id="guest_name" required class="wpcalibrate-input" />
                        </div>
                        <div class="form-group">
                            <label for="guest_email"><?php esc_html_e('Your Email Address', 'wpcalibrate-support-desk'); ?> <span class="required">*</span></label>
                            <input type="email" name="guest_email" id="guest_email" required class="wpcalibrate-input" />
                            <small class="field-hint"><?php esc_html_e('We will email your secure ticket access link here.', 'wpcalibrate-support-desk'); ?></small>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-row form-row-split">
                    <div class="form-group">
                        <label for="ticket_category"><?php esc_html_e('Department', 'wpcalibrate-support-desk'); ?> <span class="required">*</span></label>
                        <select name="ticket_category" id="ticket_category" class="wpcalibrate-input" required>
                            <?php foreach ($categories as $cat) : ?>
                                <option value="<?php echo (int) $cat['id']; ?>">
                                    <?php echo esc_html($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($allowPriority) : ?>
                        <div class="form-group">
                            <label for="ticket_priority"><?php esc_html_e('Priority', 'wpcalibrate-support-desk'); ?></label>
                            <select name="ticket_priority" id="ticket_priority" class="wpcalibrate-input">
                                <option value="low"><?php esc_html_e('Low', 'wpcalibrate-support-desk'); ?></option>
                                <option value="normal" selected><?php esc_html_e('Normal', 'wpcalibrate-support-desk'); ?></option>
                                <option value="high"><?php esc_html_e('High', 'wpcalibrate-support-desk'); ?></option>
                                <option value="urgent"><?php esc_html_e('Urgent', 'wpcalibrate-support-desk'); ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($customerOrders)) : ?>
                    <div class="form-group">
                        <label for="woo_order_id"><?php esc_html_e('Related Order (Optional)', 'wpcalibrate-support-desk'); ?></label>
                        <select name="woo_order_id" id="woo_order_id" class="wpcalibrate-input">
                            <option value=""><?php esc_html_e('— None —', 'wpcalibrate-support-desk'); ?></option>
                            <?php foreach ($customerOrders as $ord) : ?>
                                <option value="<?php echo (int) $ord['id']; ?>">
                                    <?php printf(esc_html__('Order #%1$s (%2$s) - %3$s', 'wpcalibrate-support-desk'), esc_html($ord['order_number']), esc_html($ord['date']), wp_strip_all_tags($ord['total'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="ticket_subject"><?php esc_html_e('Subject', 'wpcalibrate-support-desk'); ?> <span class="required">*</span></label>
                    <input type="text" name="ticket_subject" id="ticket_subject" required class="wpcalibrate-input" placeholder="<?php esc_attr_e('Brief description of your issue...', 'wpcalibrate-support-desk'); ?>" />
                </div>

                <div class="form-group">
                    <label for="ticket_message"><?php esc_html_e('Message / Details', 'wpcalibrate-support-desk'); ?> <span class="required">*</span></label>
                    <textarea name="ticket_message" id="ticket_message" rows="6" required class="wpcalibrate-input" placeholder="<?php esc_attr_e('Please provide detailed information...', 'wpcalibrate-support-desk'); ?>"></textarea>
                </div>

                <?php if ($attEnabled) : ?>
                    <div class="form-group">
                        <label for="ticket_attachments"><?php esc_html_e('Attachments (Optional)', 'wpcalibrate-support-desk'); ?></label>
                        <input type="file" name="ticket_attachments[]" id="ticket_attachments" multiple class="wpcalibrate-file-input" />
                        <small class="field-hint"><?php printf(esc_html__('Max size per file: %d MB. Allowed: images, documents, PDFs, zip.', 'wpcalibrate-support-desk'), $maxMb); ?></small>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button type="submit" class="button button-primary wpcalibrate-btn-submit">
                        <?php esc_html_e('Submit Ticket', 'wpcalibrate-support-desk'); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render Single Ticket conversation view for Customer or Guest.
     *
     * @param string $ticketRef
     * @param string $guestToken
     * @return void
     */
    private static function renderSingleTicketView(string $ticketRef, string $guestToken = ''): void
    {
        $ticketRepo = new TicketRepository();
        $ticket = $ticketRepo->getByRef($ticketRef);

        if (!$ticket) {
            echo '<div class="wpcalibrate-alert alert-error">' . esc_html__('Support ticket not found.', 'wpcalibrate-support-desk') . '</div>';
            return;
        }

        $userId = get_current_user_id();

        // Security check via AccessPolicy.
        if (!AccessPolicy::canViewTicket($ticket, $userId, !empty($guestToken) ? $guestToken : null)) {
            echo '<div class="wpcalibrate-alert alert-error">';
            echo '<strong>' . esc_html__('Access Denied', 'wpcalibrate-support-desk') . '</strong><br>';
            echo esc_html__('You do not have permission to view this ticket. If you are a guest, please use the secure link sent to your email address.', 'wpcalibrate-support-desk');
            echo '</div>';
            return;
        }

        $ticketId = (int) $ticket['id'];
        $msgRepo = new MessageRepository();
        // Strictly false for frontend customer/guest: NEVER returns internal notes!
        $messages = $msgRepo->getByTicket($ticketId, false);

        $status = (string) $ticket['status'];
        $isClosed = ($status === 'closed');
        $canClose = AccessPolicy::canChangeStatus($ticket, $userId, 'closed', $guestToken);
        $canReopen = AccessPolicy::canChangeStatus($ticket, $userId, 'open', $guestToken);
        $canReply = AccessPolicy::canReplyTicket($ticket, $userId, $guestToken);
        $attEnabled = \WPCalibrate\SupportDesk\Security\AttachmentSecurity::isEnabled();
        ?>
        <div class="wpcalibrate-conversation-wrap">
            <div class="wpcalibrate-ticket-topbar">
                <a href="<?php echo esc_url(remove_query_arg(['wpc_ticket', 'wpc_token'])); ?>" class="back-link">
                    &larr; <?php esc_html_e('Back to Support Portal', 'wpcalibrate-support-desk'); ?>
                </a>

                <div class="ticket-status-actions">
                    <span class="badge badge-status-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html(str_replace('_', ' ', ucfirst($status))); ?>
                    </span>

                    <?php if (!$isClosed && $canClose) : ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('wpcalibrate_portal_action', 'wpcalibrate_portal_nonce'); ?>
                            <input type="hidden" name="wpcalibrate_frontend_action" value="change_status" />
                            <input type="hidden" name="ticket_id" value="<?php echo (int) $ticketId; ?>" />
                            <input type="hidden" name="new_status" value="closed" />
                            <?php if (!empty($guestToken)) : ?>
                                <input type="hidden" name="guest_token" value="<?php echo esc_attr($guestToken); ?>" />
                            <?php endif; ?>
                            <button type="submit" class="button button-secondary wpcalibrate-btn-sm" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to close this ticket?', 'wpcalibrate-support-desk')); ?>');">
                                <?php esc_html_e('Close Ticket', 'wpcalibrate-support-desk'); ?>
                            </button>
                        </form>
                    <?php elseif ($isClosed && $canReopen) : ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('wpcalibrate_portal_action', 'wpcalibrate_portal_nonce'); ?>
                            <input type="hidden" name="wpcalibrate_frontend_action" value="change_status" />
                            <input type="hidden" name="ticket_id" value="<?php echo (int) $ticketId; ?>" />
                            <input type="hidden" name="new_status" value="open" />
                            <?php if (!empty($guestToken)) : ?>
                                <input type="hidden" name="guest_token" value="<?php echo esc_attr($guestToken); ?>" />
                            <?php endif; ?>
                            <button type="submit" class="button button-primary wpcalibrate-btn-sm">
                                <?php esc_html_e('Reopen Ticket', 'wpcalibrate-support-desk'); ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="wpcalibrate-ticket-summary-box">
                <h2>
                    <span class="ref-badge"><?php echo esc_html($ticket['ticket_ref']); ?></span>
                    <?php echo esc_html($ticket['subject']); ?>
                </h2>
                <div class="meta-row">
                    <span><?php printf(esc_html__('Created on %s', 'wpcalibrate-support-desk'), esc_html($ticket['created_at'])); ?></span>
                    <?php if (!empty($ticket['category_name'])) : ?>
                        <span> &bull; <?php echo esc_html($ticket['category_name']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Messages Timeline -->
            <div class="wpcalibrate-messages-stream">
                <?php foreach ($messages as $msg) :
                    $isStaff = !empty($msg['author_id']) && user_can((int) $msg['author_id'], \WPCalibrate\SupportDesk\Auth\RoleManager::CAP_REPLY_TICKETS);
                ?>
                    <div class="wpcalibrate-single-msg <?php echo $isStaff ? 'msg-agent' : 'msg-customer'; ?>">
                        <div class="msg-meta">
                            <span class="author-title">
                                <strong><?php echo esc_html($msg['author_name']); ?></strong>
                                <?php if ($isStaff) : ?>
                                    <span class="badge badge-staff"><?php esc_html_e('Staff', 'wpcalibrate-support-desk'); ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="time-stamp"><?php echo esc_html($msg['created_at']); ?></span>
                        </div>
                        <div class="msg-body">
                            <?php echo wp_kses_post($msg['body']); ?>
                        </div>
                        <?php if (!empty($msg['attachments'])) : ?>
                            <div class="msg-attachments">
                                <strong><?php esc_html_e('Attachments:', 'wpcalibrate-support-desk'); ?></strong>
                                <ul>
                                    <?php foreach ($msg['attachments'] as $att) :
                                        $attUrl = $att['download_url'];
                                        if (!empty($guestToken)) {
                                            $attUrl = add_query_arg('guest_token', rawurlencode($guestToken), $attUrl);
                                        }
                                    ?>
                                        <li>
                                            <a href="<?php echo esc_url($attUrl); ?>" target="_blank" rel="noopener">
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

            <!-- Reply Box -->
            <?php if ($canReply && !$isClosed) : ?>
                <div class="wpcalibrate-reply-box">
                    <h3><?php esc_html_e('Post a Reply', 'wpcalibrate-support-desk'); ?></h3>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('wpcalibrate_portal_action', 'wpcalibrate_portal_nonce'); ?>
                        <input type="hidden" name="wpcalibrate_frontend_action" value="add_reply" />
                        <input type="hidden" name="ticket_id" value="<?php echo (int) $ticketId; ?>" />
                        <?php if (!empty($guestToken)) : ?>
                            <input type="hidden" name="guest_token" value="<?php echo esc_attr($guestToken); ?>" />
                        <?php endif; ?>

                        <div class="form-group">
                            <textarea name="reply_body" rows="5" required class="wpcalibrate-input" placeholder="<?php esc_attr_e('Write your response here...', 'wpcalibrate-support-desk'); ?>"></textarea>
                        </div>

                        <?php if ($attEnabled) : ?>
                            <div class="form-group">
                                <label for="reply_attachments"><?php esc_html_e('Add Attachments', 'wpcalibrate-support-desk'); ?></label>
                                <input type="file" name="ticket_attachments[]" id="reply_attachments" multiple class="wpcalibrate-file-input" />
                            </div>
                        <?php endif; ?>

                        <button type="submit" class="button button-primary wpcalibrate-btn-submit">
                            <?php esc_html_e('Submit Reply', 'wpcalibrate-support-desk'); ?>
                        </button>
                    </form>
                </div>
            <?php elseif ($isClosed) : ?>
                <div class="wpcalibrate-alert alert-info">
                    <?php esc_html_e('This ticket is currently closed. If you need further assistance, you may reopen it using the button above.', 'wpcalibrate-support-desk'); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Helper to extract files array from $_FILES.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function extractFiles(): array
    {
        $uploaded = [];
        if (!empty($_FILES['ticket_attachments'])) {
            $rawFiles = $_FILES['ticket_attachments'];
            if (isset($rawFiles['name']) && is_array($rawFiles['name'])) {
                $count = count($rawFiles['name']);
                for ($i = 0; $i < $count; $i++) {
                    if (!empty($rawFiles['tmp_name'][$i])) {
                        $uploaded[] = [
                            'name'     => $rawFiles['name'][$i],
                            'type'     => $rawFiles['type'][$i],
                            'tmp_name' => $rawFiles['tmp_name'][$i],
                            'error'    => $rawFiles['error'][$i],
                            'size'     => $rawFiles['size'][$i],
                        ];
                    }
                }
            } elseif (!empty($rawFiles['tmp_name'])) {
                $uploaded[] = $rawFiles;
            }
        }

        return $uploaded;
    }
}
