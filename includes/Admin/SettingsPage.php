<?php
/**
 * Settings page with tabbed organization and secure option saving.
 *
 * @package WPCalibrate\SupportDesk\Admin
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Admin;

use WPCalibrate\SupportDesk\Auth\RoleManager;
use WPCalibrate\SupportDesk\Licensing\LicensingService;
use WPCalibrate\SupportDesk\Repositories\CategoryRepository;
use WPCalibrate\SupportDesk\Integrations\WooCommerce\WooCommerceIntegration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class SettingsPage
 */
final class SettingsPage
{
    /**
     * Render the settings page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can(RoleManager::CAP_MANAGE_SETTINGS)) {
            wp_die(esc_html__('You do not have permission to manage Support Desk settings.', 'wpcalibrate-support-desk'), 403);
        }

        self::handleSave();

        $activeTab = sanitize_key($_GET['tab'] ?? 'overview');
        $iconDarkUrl = WPCALIBRATE_SUPPORT_DESK_URL . 'branding/icon-dark.png';

        echo '<div class="wrap wpcalibrate-admin-wrap">';
        ?>
        <div class="wpcalibrate-header">
            <div class="wpcalibrate-header-left">
                <img src="<?php echo esc_url($iconDarkUrl); ?>" alt="WPCalibrate" class="wpcalibrate-logo" />
                <h1><?php esc_html_e('Support Desk Settings', 'wpcalibrate-support-desk'); ?></h1>
            </div>
            <div class="wpcalibrate-header-right">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_DASHBOARD)); ?>" class="button">
                    &larr; <?php esc_html_e('Back to Tickets', 'wpcalibrate-support-desk'); ?>
                </a>
            </div>
        </div>

        <?php settings_errors('wpcalibrate_settings_messages'); ?>

        <nav class="nav-tab-wrapper wpcalibrate-nav-tabs">
            <a href="<?php echo esc_url(add_query_arg('tab', 'overview')); ?>" class="nav-tab <?php echo $activeTab === 'overview' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Overview', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'general')); ?>" class="nav-tab <?php echo $activeTab === 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'departments')); ?>" class="nav-tab <?php echo $activeTab === 'departments' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Departments', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'email')); ?>" class="nav-tab <?php echo $activeTab === 'email' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Email Notifications', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'attachments')); ?>" class="nav-tab <?php echo $activeTab === 'attachments' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Attachments', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'woocommerce')); ?>" class="nav-tab <?php echo $activeTab === 'woocommerce' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('WooCommerce', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'agents')); ?>" class="nav-tab <?php echo $activeTab === 'agents' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Support Agents', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'privacy')); ?>" class="nav-tab <?php echo $activeTab === 'privacy' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Data & Privacy', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'license')); ?>" class="nav-tab <?php echo $activeTab === 'license' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('License', 'wpcalibrate-support-desk'); ?></a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'support')); ?>" class="nav-tab <?php echo $activeTab === 'support' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('WPCalibrate Support', 'wpcalibrate-support-desk'); ?></a>
        </nav>

        <div class="wpcalibrate-tab-content">
            <?php
            switch ($activeTab) {
                case 'overview':
                    self::renderOverviewTab();
                    break;
                case 'departments':
                    self::renderDepartmentsTab();
                    break;
                case 'email':
                    self::renderEmailTab();
                    break;
                case 'attachments':
                    self::renderAttachmentsTab();
                    break;
                case 'woocommerce':
                    self::renderWooCommerceTab();
                    break;
                case 'agents':
                    self::renderAgentsTab();
                    break;
                case 'privacy':
                    self::renderPrivacyTab();
                    break;
                case 'license':
                    self::renderLicenseTab();
                    break;
                case 'support':
                    self::renderSupportTab();
                    break;
                case 'general':
                    self::renderGeneralTab();
                    break;
                default:
                    self::renderOverviewTab();
                    break;
            }
            ?>
        </div>
        <?php
        echo '</div>';
    }

    /**
     * Handle saving settings with nonces and validation.
     *
     * @return void
     */
    private static function handleSave(): void
    {
        if (empty($_POST['wpcalibrate_settings_action'])) {
            return;
        }

        check_admin_referer('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce');

        $tab = sanitize_key($_POST['wpcalibrate_settings_action']);

        if ($tab === 'save_general') {
            $prefix = strtoupper(trim(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_POST['wpcalibrate_ticket_prefix']) ?: 'WPC'));
            update_option('wpcalibrate_ticket_prefix', $prefix);
            update_option('wpcalibrate_default_priority', sanitize_key($_POST['wpcalibrate_default_priority'] ?? 'normal'));
            update_option('wpcalibrate_customer_priority_selection', !empty($_POST['wpcalibrate_customer_priority_selection']) ? '1' : '0');
            update_option('wpcalibrate_guest_tickets_enabled', !empty($_POST['wpcalibrate_guest_tickets_enabled']) ? '1' : '0');
            update_option('wpcalibrate_allow_customer_close', !empty($_POST['wpcalibrate_allow_customer_close']) ? '1' : '0');
            update_option('wpcalibrate_allow_customer_reopen', !empty($_POST['wpcalibrate_allow_customer_reopen']) ? '1' : '0');
            update_option('wpcalibrate_customer_reopen_days', max(1, (int) ($_POST['wpcalibrate_customer_reopen_days'] ?? 14)));
            update_option('wpcalibrate_records_per_page', max(5, min(100, (int) ($_POST['wpcalibrate_records_per_page'] ?? 20))));
            update_option('wpcalibrate_support_portal_page_id', (int) ($_POST['wpcalibrate_support_portal_page_id'] ?? 0));

            add_settings_error('wpcalibrate_settings_messages', 'settings_saved', __('General settings saved.', 'wpcalibrate-support-desk'), 'success');
        } elseif ($tab === 'save_department') {
            $catRepo = new CategoryRepository();
            $catName = sanitize_text_field($_POST['cat_name'] ?? '');
            if (!empty($catName)) {
                $catRepo->create([
                    'name'        => $catName,
                    'slug'        => sanitize_title($catName),
                    'description' => sanitize_textarea_field($_POST['cat_desc'] ?? ''),
                    'is_active'   => 1,
                ]);
                add_settings_error('wpcalibrate_settings_messages', 'cat_saved', __('Department added.', 'wpcalibrate-support-desk'), 'success');
            }
        } elseif ($tab === 'delete_department') {
            $catId = (int) ($_POST['cat_id'] ?? 0);
            if ($catId > 0) {
                $catRepo = new CategoryRepository();
                $catRepo->delete($catId);
                add_settings_error('wpcalibrate_settings_messages', 'cat_deleted', __('Department removed safely.', 'wpcalibrate-support-desk'), 'success');
            }
        } elseif ($tab === 'save_email') {
            update_option('wpcalibrate_email_from_name', sanitize_text_field($_POST['wpcalibrate_email_from_name'] ?? ''));
            update_option('wpcalibrate_email_from_address', sanitize_email($_POST['wpcalibrate_email_from_address'] ?? ''));
            update_option('wpcalibrate_admin_notification_email', sanitize_email($_POST['wpcalibrate_admin_notification_email'] ?? ''));
            update_option('wpcalibrate_email_customer_ticket_created', !empty($_POST['wpcalibrate_email_customer_ticket_created']) ? '1' : '0');
            update_option('wpcalibrate_email_admin_new_ticket', !empty($_POST['wpcalibrate_email_admin_new_ticket']) ? '1' : '0');
            update_option('wpcalibrate_email_customer_agent_reply', !empty($_POST['wpcalibrate_email_customer_agent_reply']) ? '1' : '0');
            update_option('wpcalibrate_email_agent_customer_reply', !empty($_POST['wpcalibrate_email_agent_customer_reply']) ? '1' : '0');
            update_option('wpcalibrate_email_agent_assigned', !empty($_POST['wpcalibrate_email_agent_assigned']) ? '1' : '0');
            update_option('wpcalibrate_email_status_change', !empty($_POST['wpcalibrate_email_status_change']) ? '1' : '0');

            add_settings_error('wpcalibrate_settings_messages', 'email_saved', __('Email settings saved.', 'wpcalibrate-support-desk'), 'success');
        } elseif ($tab === 'save_attachments') {
            update_option('wpcalibrate_attachments_enabled', !empty($_POST['wpcalibrate_attachments_enabled']) ? '1' : '0');
            update_option('wpcalibrate_max_attachment_size_mb', max(1, min(50, (int) ($_POST['wpcalibrate_max_attachment_size_mb'] ?? 5))));

            add_settings_error('wpcalibrate_settings_messages', 'att_saved', __('Attachment settings saved.', 'wpcalibrate-support-desk'), 'success');
        } elseif ($tab === 'save_woocommerce') {
            update_option('wpcalibrate_woo_integration_enabled', !empty($_POST['wpcalibrate_woo_integration_enabled']) ? '1' : '0');
            add_settings_error('wpcalibrate_settings_messages', 'woo_saved', __('WooCommerce integration settings saved.', 'wpcalibrate-support-desk'), 'success');
        } elseif ($tab === 'save_privacy') {
            update_option('wpcalibrate_privacy_erase_action', sanitize_key($_POST['wpcalibrate_privacy_erase_action'] ?? 'anonymize'));
            update_option('wpcalibrate_uninstall_delete_data', !empty($_POST['wpcalibrate_uninstall_delete_data']) ? '1' : '0');
            add_settings_error('wpcalibrate_settings_messages', 'privacy_saved', __('Privacy & retention settings saved.', 'wpcalibrate-support-desk'), 'success');
        } elseif ($tab === 'save_license') {
            $key = sanitize_text_field($_POST['wpcalibrate_license_key'] ?? '');
            LicensingService::saveLicenseKey($key);
            add_settings_error('wpcalibrate_settings_messages', 'license_saved', __('License key updated locally.', 'wpcalibrate-support-desk'), 'info');
        }
    }

    /**
     * Render General Settings tab.
     */
    private static function renderGeneralTab(): void
    {
        $prefix = (string) get_option('wpcalibrate_ticket_prefix', 'WPC');
        $priority = (string) get_option('wpcalibrate_default_priority', 'normal');
        $allowCustomerPriority = (bool) get_option('wpcalibrate_customer_priority_selection', '0');
        $guestEnabled = (bool) get_option('wpcalibrate_guest_tickets_enabled', '1');
        $customerClose = (bool) get_option('wpcalibrate_allow_customer_close', '1');
        $customerReopen = (bool) get_option('wpcalibrate_allow_customer_reopen', '1');
        $reopenDays = (int) get_option('wpcalibrate_customer_reopen_days', 14);
        $perPage = (int) get_option('wpcalibrate_records_per_page', 20);
        $portalPageId = (int) get_option('wpcalibrate_support_portal_page_id', 0);
        ?>
        <form method="post" class="wpcalibrate-settings-form">
            <?php wp_nonce_field('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce'); ?>
            <input type="hidden" name="wpcalibrate_settings_action" value="save_general" />

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wpcalibrate_ticket_prefix"><?php esc_html_e('Ticket Reference Prefix', 'wpcalibrate-support-desk'); ?></label></th>
                    <td>
                        <input name="wpcalibrate_ticket_prefix" type="text" id="wpcalibrate_ticket_prefix" value="<?php echo esc_attr($prefix); ?>" class="regular-text" maxlength="10" />
                        <p class="description"><?php esc_html_e('Prefix used for sequential ticket references (e.g., WPC generates WPC-000001). Changing this does not alter existing tickets.', 'wpcalibrate-support-desk'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpcalibrate_default_priority"><?php esc_html_e('Default Priority', 'wpcalibrate-support-desk'); ?></label></th>
                    <td>
                        <select name="wpcalibrate_default_priority" id="wpcalibrate_default_priority">
                            <option value="low" <?php selected($priority, 'low'); ?>><?php esc_html_e('Low', 'wpcalibrate-support-desk'); ?></option>
                            <option value="normal" <?php selected($priority, 'normal'); ?>><?php esc_html_e('Normal', 'wpcalibrate-support-desk'); ?></option>
                            <option value="high" <?php selected($priority, 'high'); ?>><?php esc_html_e('High', 'wpcalibrate-support-desk'); ?></option>
                            <option value="urgent" <?php selected($priority, 'urgent'); ?>><?php esc_html_e('Urgent', 'wpcalibrate-support-desk'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Customer Priority Selection', 'wpcalibrate-support-desk'); ?></th>
                    <td>
                        <label>
                            <input name="wpcalibrate_customer_priority_selection" type="checkbox" value="1" <?php checked($allowCustomerPriority); ?> />
                            <?php esc_html_e('Allow customers to select priority when submitting tickets', 'wpcalibrate-support-desk'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Guest Ticket Submissions', 'wpcalibrate-support-desk'); ?></th>
                    <td>
                        <label>
                            <input name="wpcalibrate_guest_tickets_enabled" type="checkbox" value="1" <?php checked($guestEnabled); ?> />
                            <?php esc_html_e('Allow guest users (unauthenticated visitors) to submit tickets', 'wpcalibrate-support-desk'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Guests receive a cryptographically secure access link via email to view and reply to their ticket.', 'wpcalibrate-support-desk'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Customer Ticket Closing', 'wpcalibrate-support-desk'); ?></th>
                    <td>
                        <label>
                            <input name="wpcalibrate_allow_customer_close" type="checkbox" value="1" <?php checked($customerClose); ?> />
                            <?php esc_html_e('Allow customers to mark their tickets as closed', 'wpcalibrate-support-desk'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Customer Ticket Reopening', 'wpcalibrate-support-desk'); ?></th>
                    <td>
                        <label>
                            <input name="wpcalibrate_allow_customer_reopen" type="checkbox" value="1" <?php checked($customerReopen); ?> />
                            <?php esc_html_e('Allow customers to reopen resolved or closed tickets', 'wpcalibrate-support-desk'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpcalibrate_customer_reopen_days"><?php esc_html_e('Reopening Window (Days)', 'wpcalibrate-support-desk'); ?></label></th>
                    <td>
                        <input name="wpcalibrate_customer_reopen_days" type="number" id="wpcalibrate_customer_reopen_days" value="<?php echo esc_attr((string) $reopenDays); ?>" min="1" max="365" class="small-text" />
                        <p class="description"><?php esc_html_e('Number of days after closing within which a ticket can be reopened by customer.', 'wpcalibrate-support-desk'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpcalibrate_support_portal_page_id"><?php esc_html_e('Frontend Support Portal Page', 'wpcalibrate-support-desk'); ?></label></th>
                    <td>
                        <?php
                        wp_dropdown_pages([
                            'name'              => 'wpcalibrate_support_portal_page_id',
                            'id'                => 'wpcalibrate_support_portal_page_id',
                            'selected'          => $portalPageId,
                            'show_option_none'  => __('— Select Support Portal Page —', 'wpcalibrate-support-desk'),
                            'option_none_value' => '0',
                        ]);
                        ?>
                        <p class="description"><?php esc_html_e('Select the page containing the [wpcalibrate_support_desk] shortcode or Gutenberg block.', 'wpcalibrate-support-desk'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpcalibrate_records_per_page"><?php esc_html_e('Tickets Per Page', 'wpcalibrate-support-desk'); ?></label></th>
                    <td>
                        <input name="wpcalibrate_records_per_page" type="number" id="wpcalibrate_records_per_page" value="<?php echo esc_attr((string) $perPage); ?>" min="5" max="100" class="small-text" />
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save General Settings', 'wpcalibrate-support-desk'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Render Departments tab.
     */
    private static function renderDepartmentsTab(): void
    {
        $catRepo = new CategoryRepository();
        $categories = $catRepo->getAll(false);
        ?>
        <div class="departments-wrapper">
            <h3><?php esc_html_e('Existing Departments / Categories', 'wpcalibrate-support-desk'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'wpcalibrate-support-desk'); ?></th>
                        <th><?php esc_html_e('Slug', 'wpcalibrate-support-desk'); ?></th>
                        <th><?php esc_html_e('Description', 'wpcalibrate-support-desk'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Actions', 'wpcalibrate-support-desk'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($cat['name']); ?></strong></td>
                            <td><code><?php echo esc_html($cat['slug']); ?></code></td>
                            <td><?php echo esc_html($cat['description'] ?? ''); ?></td>
                            <td>
                                <?php if (count($categories) > 1) : ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete department? Existing tickets will be safely reassigned.', 'wpcalibrate-support-desk')); ?>');">
                                        <?php wp_nonce_field('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce'); ?>
                                        <input type="hidden" name="wpcalibrate_settings_action" value="delete_department" />
                                        <input type="hidden" name="cat_id" value="<?php echo (int) $cat['id']; ?>" />
                                        <button type="submit" class="button button-link-delete"><?php esc_html_e('Delete', 'wpcalibrate-support-desk'); ?></button>
                                    </form>
                                <?php else : ?>
                                    <span class="description"><?php esc_html_e('Default', 'wpcalibrate-support-desk'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 style="margin-top: 30px;"><?php esc_html_e('Add New Department', 'wpcalibrate-support-desk'); ?></h3>
            <form method="post" class="wpcalibrate-settings-form">
                <?php wp_nonce_field('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce'); ?>
                <input type="hidden" name="wpcalibrate_settings_action" value="save_department" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="cat_name"><?php esc_html_e('Department Name', 'wpcalibrate-support-desk'); ?></label></th>
                        <td><input name="cat_name" type="text" id="cat_name" required class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cat_desc"><?php esc_html_e('Description', 'wpcalibrate-support-desk'); ?></label></th>
                        <td><textarea name="cat_desc" id="cat_desc" rows="3" class="regular-text"></textarea></td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Add Department', 'wpcalibrate-support-desk'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render Email Notifications tab.
     */
    private static function renderEmailTab(): void
    {
        $fromName = (string) get_option('wpcalibrate_email_from_name', get_bloginfo('name'));
        $fromAddress = (string) get_option('wpcalibrate_email_from_address', get_option('admin_email'));
        $adminEmail = (string) get_option('wpcalibrate_admin_notification_email', get_option('admin_email'));

        $customerTicketCreated = (bool) get_option('wpcalibrate_email_customer_ticket_created', '1');
        $adminNewTicket = (bool) get_option('wpcalibrate_email_admin_new_ticket', '1');
        $customerAgentReply = (bool) get_option('wpcalibrate_email_customer_agent_reply', '1');
        $agentCustomerReply = (bool) get_option('wpcalibrate_email_agent_customer_reply', '1');
        $agentAssigned = (bool) get_option('wpcalibrate_email_agent_assigned', '1');
        $statusChange = (bool) get_option('wpcalibrate_email_status_change', '1');
        ?>
        <form method="post" class="wpcalibrate-settings-form">
            <?php wp_nonce_field('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce'); ?>
            <input type="hidden" name="wpcalibrate_settings_action" value="save_email" />

            <div class="notice notice-info inline" style="margin-bottom: 20px;">
                <p><?php esc_html_e('Notifications are sent via standard wp_mail() and are fully compatible with SMTP and transactional email plugins.', 'wpcalibrate-support-desk'); ?></p>
            </div>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wpcalibrate_email_from_name"><?php esc_html_e('From Name', 'wpcalibrate-support-desk'); ?></label></th>
                    <td><input name="wpcalibrate_email_from_name" type="text" id="wpcalibrate_email_from_name" value="<?php echo esc_attr($fromName); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpcalibrate_email_from_address"><?php esc_html_e('From Email Address', 'wpcalibrate-support-desk'); ?></label></th>
                    <td><input name="wpcalibrate_email_from_address" type="email" id="wpcalibrate_email_from_address" value="<?php echo esc_attr($fromAddress); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpcalibrate_admin_notification_email"><?php esc_html_e('Admin Notification Recipient', 'wpcalibrate-support-desk'); ?></label></th>
                    <td><input name="wpcalibrate_admin_notification_email" type="email" id="wpcalibrate_admin_notification_email" value="<?php echo esc_attr($adminEmail); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enabled Notifications', 'wpcalibrate-support-desk'); ?></th>
                    <td>
                        <fieldset>
                            <label><input type="checkbox" name="wpcalibrate_email_customer_ticket_created" value="1" <?php checked($customerTicketCreated); ?> /> <?php esc_html_e('Customer Confirmation & Guest Access Link (on ticket creation)', 'wpcalibrate-support-desk'); ?></label><br><br>
                            <label><input type="checkbox" name="wpcalibrate_email_admin_new_ticket" value="1" <?php checked($adminNewTicket); ?> /> <?php esc_html_e('Admin Notification on New Ticket Submitted', 'wpcalibrate-support-desk'); ?></label><br><br>
                            <label><input type="checkbox" name="wpcalibrate_email_customer_agent_reply" value="1" <?php checked($customerAgentReply); ?> /> <?php esc_html_e('Customer Notification on Agent Reply', 'wpcalibrate-support-desk'); ?></label><br><br>
                            <label><input type="checkbox" name="wpcalibrate_email_agent_customer_reply" value="1" <?php checked($agentCustomerReply); ?> /> <?php esc_html_e('Agent / Admin Notification on Customer Reply', 'wpcalibrate-support-desk'); ?></label><br><br>
                            <label><input type="checkbox" name="wpcalibrate_email_agent_assigned" value="1" <?php checked($agentAssigned); ?> /> <?php esc_html_e('Agent Notification when Ticket is Assigned', 'wpcalibrate-support-desk'); ?></label><br><br>
                            <label><input type="checkbox" name="wpcalibrate_email_status_change" value="1" <?php checked($statusChange); ?> /> <?php esc_html_e('Customer Notification when Ticket is Resolved or Closed', 'wpcalibrate-support-desk'); ?></label>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save Email Settings', 'wpcalibrate-support-desk'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Render Attachments tab.
     */
    private static function renderAttachmentsTab(): void
    {
        $enabled = (bool) get_option('wpcalibrate_attachments_enabled', '1');
        $maxMb = (int) get_option('wpcalibrate_max_attachment_size_mb', 5);
        $mimes = \WPCalibrate\SupportDesk\Security\AttachmentSecurity::getAllowedMimes();
        ?>
        <form method="post" class="wpcalibrate-settings-form">
            <?php wp_nonce_field('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce'); ?>
            <input type="hidden" name="wpcalibrate_settings_action" value="save_attachments" />

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Attachments', 'wpcalibrate-support-desk'); ?></th>
                    <td>
                        <label>
                            <input name="wpcalibrate_attachments_enabled" type="checkbox" value="1" <?php checked($enabled); ?> />
                            <?php esc_html_e('Allow file attachments on support tickets and replies', 'wpcalibrate-support-desk'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="wpcalibrate_max_attachment_size_mb"><?php esc_html_e('Max File Size (MB)', 'wpcalibrate-support-desk'); ?></label></th>
                    <td>
                        <input name="wpcalibrate_max_attachment_size_mb" type="number" id="wpcalibrate_max_attachment_size_mb" value="<?php echo esc_attr((string) $maxMb); ?>" min="1" max="50" class="small-text" />
                        <p class="description"><?php printf(esc_html__('Server maximum upload limit: %s', 'wpcalibrate-support-desk'), esc_html(size_format(wp_max_upload_size()))); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Allowed File Types', 'wpcalibrate-support-desk'); ?></th>
                    <td>
                        <code><?php echo esc_html(implode(', ', array_keys($mimes))); ?></code>
                        <p class="description"><?php esc_html_e('Stored in a protected directory outside direct web access with real MIME verification and authenticated streaming.', 'wpcalibrate-support-desk'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save Attachment Settings', 'wpcalibrate-support-desk'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Render WooCommerce integration tab.
     */
    private static function renderWooCommerceTab(): void
    {
        $isWcActive = WooCommerceIntegration::isActive();
        $isEnabled = WooCommerceIntegration::isEnabled();
        ?>
        <form method="post" class="wpcalibrate-settings-form">
            <?php wp_nonce_field('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce'); ?>
            <input type="hidden" name="wpcalibrate_settings_action" value="save_woocommerce" />

            <?php if (!$isWcActive) : ?>
                <div class="notice notice-warning inline" style="margin-bottom: 20px;">
                    <p><strong><?php esc_html_e('WooCommerce is not currently active on this WordPress installation.', 'wpcalibrate-support-desk'); ?></strong><br>
                    <?php esc_html_e('WPCalibrate Support Desk operates completely independently without requiring WooCommerce. Order association controls will become available automatically whenever WooCommerce is activated.', 'wpcalibrate-support-desk'); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-success inline" style="margin-bottom: 20px;">
                    <p><?php esc_html_e('WooCommerce detected and active with High-Performance Order Storage (HPOS) compatibility declared.', 'wpcalibrate-support-desk'); ?></p>
                </div>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable WooCommerce Integration', 'wpcalibrate-support-desk'); ?></th>
                        <td>
                            <label>
                                <input name="wpcalibrate_woo_integration_enabled" type="checkbox" value="1" <?php checked($isEnabled); ?> />
                                <?php esc_html_e('Allow logged-in customers to link their eligible orders to support tickets', 'wpcalibrate-support-desk'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Support agents will see an order summary card with status and purchased item summary.', 'wpcalibrate-support-desk'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save WooCommerce Settings', 'wpcalibrate-support-desk'); ?></button>
                </p>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Render Support Agents tab.
     */
    private static function renderAgentsTab(): void
    {
        $agents = get_users(['role__in' => [RoleManager::ROLE_AGENT, 'administrator']]);
        ?>
        <div class="agents-wrapper">
            <h3><?php esc_html_e('Active Support Agents and Administrators', 'wpcalibrate-support-desk'); ?></h3>
            <p class="description"><?php esc_html_e('Users with the Support Agent role or Administrator role can access the Support Desk and work on assigned tickets.', 'wpcalibrate-support-desk'); ?></p>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Display Name', 'wpcalibrate-support-desk'); ?></th>
                        <th><?php esc_html_e('Username', 'wpcalibrate-support-desk'); ?></th>
                        <th><?php esc_html_e('Email', 'wpcalibrate-support-desk'); ?></th>
                        <th><?php esc_html_e('Role', 'wpcalibrate-support-desk'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $agent) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($agent->display_name); ?></strong></td>
                            <td><?php echo esc_html($agent->user_login); ?></td>
                            <td><?php echo esc_html($agent->user_email); ?></td>
                            <td><span class="badge"><?php echo esc_html(implode(', ', $agent->roles)); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url(admin_url('user-new.php')); ?>" class="button">
                    <?php esc_html_e('Add New User / Assign Support Agent Role', 'wpcalibrate-support-desk'); ?> &rarr;
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render Data & Privacy tab.
     */
    private static function renderPrivacyTab(): void
    {
        $eraseAction = (string) get_option('wpcalibrate_privacy_erase_action', 'anonymize');
        $deleteOnUninstall = (bool) get_option('wpcalibrate_uninstall_delete_data', '0');
        ?>
        <form method="post" class="wpcalibrate-settings-form">
            <?php wp_nonce_field('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce'); ?>
            <input type="hidden" name="wpcalibrate_settings_action" value="save_privacy" />

            <h3><?php esc_html_e('WordPress Privacy Tools & Data Erasure', 'wpcalibrate-support-desk'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wpcalibrate_privacy_erase_action"><?php esc_html_e('When Personal Data is Erased', 'wpcalibrate-support-desk'); ?></label></th>
                    <td>
                        <select name="wpcalibrate_privacy_erase_action" id="wpcalibrate_privacy_erase_action">
                            <option value="anonymize" <?php selected($eraseAction, 'anonymize'); ?>><?php esc_html_e('Anonymize customer records (Retain conversation history for auditing)', 'wpcalibrate-support-desk'); ?></option>
                            <option value="delete" <?php selected($eraseAction, 'delete'); ?>><?php esc_html_e('Delete entire ticket records permanently', 'wpcalibrate-support-desk'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Controls behavior when an administrator processes a request via Tools &rarr; Erase Personal Data.', 'wpcalibrate-support-desk'); ?></p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top: 30px; color: #d63638;"><?php esc_html_e('Plugin Uninstall Behavior', 'wpcalibrate-support-desk'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Delete Data on Uninstall', 'wpcalibrate-support-desk'); ?></th>
                    <td>
                        <label>
                            <input name="wpcalibrate_uninstall_delete_data" type="checkbox" value="1" <?php checked($deleteOnUninstall); ?> />
                            <strong><?php esc_html_e('Completely remove custom database tables, options, and attachments upon plugin uninstall', 'wpcalibrate-support-desk'); ?></strong>
                        </label>
                        <p class="description" style="color: #d63638;">
                            <?php esc_html_e('WARNING: By default, WPCalibrate Support Desk preserves all support tickets and data when deactivated or uninstalled. Checking this box means all tickets, conversations, and attachments will be permanently deleted if the plugin is deleted via the WordPress Plugins screen.', 'wpcalibrate-support-desk'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save Privacy Settings', 'wpcalibrate-support-desk'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Render License tab with masked display and unconfigured provider notice.
     */
    private static function renderLicenseTab(): void
    {
        $maskedKey = LicensingService::getMaskedKey();
        $statusMsg = LicensingService::getStatusMessage();
        ?>
        <form method="post" class="wpcalibrate-settings-form">
            <?php wp_nonce_field('wpcalibrate_save_settings_nonce', 'wpcalibrate_nonce'); ?>
            <input type="hidden" name="wpcalibrate_settings_action" value="save_license" />

            <div class="notice notice-info inline" style="margin-bottom: 20px;">
                <p><strong><?php esc_html_e('License Status:', 'wpcalibrate-support-desk'); ?></strong> <?php echo esc_html($statusMsg); ?></p>
                <p><?php esc_html_e('WPCalibrate Support Desk 1.0 is a 100% free, complete customer ticketing system. No remote licensing provider is configured in this version.', 'wpcalibrate-support-desk'); ?></p>
            </div>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="wpcalibrate_license_key"><?php esc_html_e('License Key', 'wpcalibrate-support-desk'); ?></label></th>
                    <td>
                        <input name="wpcalibrate_license_key" type="text" id="wpcalibrate_license_key" value="" placeholder="<?php echo esc_attr($maskedKey ?: __('Enter license key...', 'wpcalibrate-support-desk')); ?>" class="regular-text" />
                        <?php if (!empty($maskedKey)) : ?>
                            <p class="description"><?php printf(esc_html__('Stored key: %s', 'wpcalibrate-support-desk'), '<code>' . esc_html($maskedKey) . '</code>'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save License Key', 'wpcalibrate-support-desk'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Render the comprehensive Overview, How to Use, Purpose, and Architecture tab.
     *
     * @return void
     */
    private static function renderOverviewTab(): void
    {
        $portalPageId = (int) get_option('wpcalibrate_support_portal_page_id', 0);
        $portalPageUrl = $portalPageId > 0 ? get_permalink($portalPageId) : '';
        ?>
        <div class="wpcalibrate-overview-container">

            <!-- Hero Banner -->
            <div class="wpcalibrate-overview-hero">
                <div class="wpcalibrate-hero-badge">
                    <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Enterprise Self-Hosted Edition v1.0.0', 'wpcalibrate-support-desk'); ?>
                </div>
                <h2><?php esc_html_e('Welcome to WPCalibrate Support Desk', 'wpcalibrate-support-desk'); ?></h2>
                <p class="wpcalibrate-hero-desc">
                    <?php esc_html_e('A high-performance, privacy-first helpdesk and ticketing system engineered natively for WordPress and WooCommerce. Built for complete data sovereignty, zero external cloud dependencies, and zero per-seat subscription fees.', 'wpcalibrate-support-desk'); ?>
                </p>
                <div class="wpcalibrate-hero-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_DASHBOARD)); ?>" class="button button-primary button-hero">
                        <span class="dashicons dashicons-tickets-alt" style="vertical-align: text-top; margin-right: 4px;"></span><?php esc_html_e('View Ticket Dashboard', 'wpcalibrate-support-desk'); ?>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'general')); ?>" class="button button-secondary button-hero">
                        <span class="dashicons dashicons-admin-settings" style="vertical-align: text-top; margin-right: 4px;"></span><?php esc_html_e('Configure General Settings', 'wpcalibrate-support-desk'); ?>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'departments')); ?>" class="button button-secondary button-hero">
                        <span class="dashicons dashicons-category" style="vertical-align: text-top; margin-right: 4px;"></span><?php esc_html_e('Manage Departments', 'wpcalibrate-support-desk'); ?>
                    </a>
                </div>
            </div>

            <!-- Section 1: Purpose & Core Philosophy -->
            <div class="wpcalibrate-overview-section">
                <div class="wpcalibrate-section-header">
                    <span class="wpcalibrate-section-tag"><?php esc_html_e('Core Philosophy', 'wpcalibrate-support-desk'); ?></span>
                    <h3><?php esc_html_e('1. Purpose of the Plugin', 'wpcalibrate-support-desk'); ?></h3>
                    <p class="description"><?php esc_html_e('Why WPCalibrate Support Desk was built and what advantages it provides over SaaS helpdesks.', 'wpcalibrate-support-desk'); ?></p>
                </div>

                <div class="wpcalibrate-features-grid">
                    <div class="wpcalibrate-feature-card">
                        <div class="wpcalibrate-feature-icon" style="background: #eff6ff; color: #2563eb;">
                            <span class="dashicons dashicons-shield"></span>
                        </div>
                        <h4><?php esc_html_e('100% Data Sovereignty & Privacy', 'wpcalibrate-support-desk'); ?></h4>
                        <p><?php esc_html_e('All customer conversations, private staff notes, email logs, and file attachments stay strictly inside your own WordPress database and web server. No third-party cloud data harvesting, no external API latency, and zero GDPR compliance exposure to third-party sub-processors.', 'wpcalibrate-support-desk'); ?></p>
                    </div>

                    <div class="wpcalibrate-feature-card">
                        <div class="wpcalibrate-feature-icon" style="background: #fdf2f8; color: #db2777;">
                            <span class="dashicons dashicons-cart"></span>
                        </div>
                        <h4><?php esc_html_e('Deep WooCommerce Synergy', 'wpcalibrate-support-desk'); ?></h4>
                        <p><?php esc_html_e('Instantly correlates customer support tickets with WooCommerce purchase history. Support agents immediately see recent orders, order statuses, item listings, and customer lifetime value directly in the ticket sidebar without switching tabs.', 'wpcalibrate-support-desk'); ?></p>
                    </div>

                    <div class="wpcalibrate-feature-card">
                        <div class="wpcalibrate-feature-icon" style="background: #f0fdf4; color: #16a34a;">
                            <span class="dashicons dashicons-money-alt"></span>
                        </div>
                        <h4><?php esc_html_e('Zero Recurring Per-Seat Fees', 'wpcalibrate-support-desk'); ?></h4>
                        <p><?php esc_html_e('Scale your support operation without increasing software expenses. Add unlimited support agents, handle unlimited tickets, create unlimited departments, and attach unlimited files with no monthly subscription lock-in or tier limits.', 'wpcalibrate-support-desk'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Section 2: How to Use (Step-by-Step Guide) -->
            <div class="wpcalibrate-overview-section">
                <div class="wpcalibrate-section-header">
                    <span class="wpcalibrate-section-tag"><?php esc_html_e('Step-by-Step Guide', 'wpcalibrate-support-desk'); ?></span>
                    <h3><?php esc_html_e('2. How to Use the Plugin', 'wpcalibrate-support-desk'); ?></h3>
                    <p class="description"><?php esc_html_e('Follow these practical steps to deploy and manage your customer support workflow.', 'wpcalibrate-support-desk'); ?></p>
                </div>

                <div class="wpcalibrate-steps-list">
                    <!-- Step 1 -->
                    <div class="wpcalibrate-step-item">
                        <div class="wpcalibrate-step-num">1</div>
                        <div class="wpcalibrate-step-content">
                            <h4><?php esc_html_e('Publish the Front-End Support Portal', 'wpcalibrate-support-desk'); ?></h4>
                            <p><?php esc_html_e('Create a standard WordPress page (for example, named "Support", "Help Center", or "Submit Ticket") and place the following shortcode on it:', 'wpcalibrate-support-desk'); ?></p>
                            <div class="wpcalibrate-shortcode-box">
                                <code>[wpcalibrate_support_portal]</code>
                            </div>
                            <p class="wpcalibrate-step-subtext">
                                <?php esc_html_e('Next, navigate to', 'wpcalibrate-support-desk'); ?>
                                <a href="<?php echo esc_url(add_query_arg('tab', 'general')); ?>"><strong><?php esc_html_e('Settings &rarr; General', 'wpcalibrate-support-desk'); ?></strong></a>
                                <?php esc_html_e('and select your created page in the "Support Portal Page" dropdown. This automatically links all outgoing customer emails and dashboard breadcrumbs directly to this portal.', 'wpcalibrate-support-desk'); ?>
                                <?php if (!empty($portalPageUrl)) : ?>
                                    <br /><span style="color: #10b981; font-weight: 600;">&check; <?php esc_html_e('Current linked portal:', 'wpcalibrate-support-desk'); ?></span> <a href="<?php echo esc_url($portalPageUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($portalPageUrl); ?></a>
                                <?php else : ?>
                                    <br /><span style="color: #f59e0b; font-weight: 600;">! <?php esc_html_e('Notice: No portal page is currently assigned. Please configure one in General Settings.', 'wpcalibrate-support-desk'); ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="wpcalibrate-step-item">
                        <div class="wpcalibrate-step-num">2</div>
                        <div class="wpcalibrate-step-content">
                            <h4><?php esc_html_e('Create Department Categories', 'wpcalibrate-support-desk'); ?></h4>
                            <p><?php esc_html_e('Organize incoming inquiries by team or topic. Visit', 'wpcalibrate-support-desk'); ?>
                                <a href="<?php echo esc_url(add_query_arg('tab', 'departments')); ?>"><strong><?php esc_html_e('Settings &rarr; Departments', 'wpcalibrate-support-desk'); ?></strong></a>
                                <?php esc_html_e('to define categories such as "Technical Support", "Billing & Orders", "Pre-Sales", and "Bug Reports". Designate a fallback default department for tickets submitted without category selection.', 'wpcalibrate-support-desk'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="wpcalibrate-step-item">
                        <div class="wpcalibrate-step-num">3</div>
                        <div class="wpcalibrate-step-content">
                            <h4><?php esc_html_e('Assign Staff Roles & Agent Permissions', 'wpcalibrate-support-desk'); ?></h4>
                            <p><?php esc_html_e('WPCalibrate Support Desk introduces two native, isolated WordPress roles:', 'wpcalibrate-support-desk'); ?></p>
                            <ul class="wpcalibrate-bullet-list">
                                <li><strong><?php esc_html_e('Support Agent:', 'wpcalibrate-support-desk'); ?></strong> <?php esc_html_e('Can view and reply to assigned tickets, post private internal notes, and update statuses. Blocked from administrative plugin settings.', 'wpcalibrate-support-desk'); ?></li>
                                <li><strong><?php esc_html_e('Support Manager:', 'wpcalibrate-support-desk'); ?></strong> <?php esc_html_e('Full managerial authority over all department queues, agent reassignments, and ticket lifecycle controls.', 'wpcalibrate-support-desk'); ?></li>
                                <li><strong><?php esc_html_e('Administrator:', 'wpcalibrate-support-desk'); ?></strong> <?php esc_html_e('Full global control over all tickets, settings, email templates, and data privacy tools.', 'wpcalibrate-support-desk'); ?></li>
                            </ul>
                            <p class="wpcalibrate-step-subtext">
                                <?php esc_html_e('Assign users in', 'wpcalibrate-support-desk'); ?> <a href="<?php echo esc_url(admin_url('users.php')); ?>"><strong><?php esc_html_e('WordPress Users &rarr; All Users', 'wpcalibrate-support-desk'); ?></strong></a> <?php esc_html_e('or review active agents under the', 'wpcalibrate-support-desk'); ?> <a href="<?php echo esc_url(add_query_arg('tab', 'agents')); ?>"><strong><?php esc_html_e('Support Agents', 'wpcalibrate-support-desk'); ?></strong></a> <?php esc_html_e('tab.', 'wpcalibrate-support-desk'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div class="wpcalibrate-step-item">
                        <div class="wpcalibrate-step-num">4</div>
                        <div class="wpcalibrate-step-content">
                            <h4><?php esc_html_e('Set Up Outbound Email Notifications', 'wpcalibrate-support-desk'); ?></h4>
                            <p><?php esc_html_e('Under', 'wpcalibrate-support-desk'); ?>
                                <a href="<?php echo esc_url(add_query_arg('tab', 'email')); ?>"><strong><?php esc_html_e('Settings &rarr; Email Notifications', 'wpcalibrate-support-desk'); ?></strong></a>,
                                <?php esc_html_e('configure automated transactional emails for both customers and staff. Every outgoing notification automatically embeds the unique reference code (e.g., #WPC-000001) in the email subject for seamless thread tracking.', 'wpcalibrate-support-desk'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Step 5 -->
                    <div class="wpcalibrate-step-item">
                        <div class="wpcalibrate-step-num">5</div>
                        <div class="wpcalibrate-step-content">
                            <h4><?php esc_html_e('Triage, Respond & Collaborate on Tickets', 'wpcalibrate-support-desk'); ?></h4>
                            <p><?php esc_html_e('From', 'wpcalibrate-support-desk'); ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::PAGE_SLUG_DASHBOARD)); ?>"><strong><?php esc_html_e('WPCalibrate &rarr; Support Desk', 'wpcalibrate-support-desk'); ?></strong></a>:
                            </p>
                            <ul class="wpcalibrate-bullet-list">
                                <li><strong><?php esc_html_e('Dashboard Filters:', 'wpcalibrate-support-desk'); ?></strong> <?php esc_html_e('Filter tickets instantly by status, priority, department, or assigned agent, or search by customer name, email, or ticket reference.', 'wpcalibrate-support-desk'); ?></li>
                                <li><strong><?php esc_html_e('Public Customer Reply:', 'wpcalibrate-support-desk'); ?></strong> <?php esc_html_e('Post responses that are immediately emailed to the customer and displayed on their portal thread.', 'wpcalibrate-support-desk'); ?></li>
                                <li><strong><?php esc_html_e('Staff Internal Notes:', 'wpcalibrate-support-desk'); ?></strong> <?php esc_html_e('Check the "Internal Note" toggle to leave private, yellow-tinted comments visible strictly to your team and completely hidden from customers.', 'wpcalibrate-support-desk'); ?></li>
                                <li><strong><?php esc_html_e('Closing Tickets:', 'wpcalibrate-support-desk'); ?></strong> <?php esc_html_e('Once solved, change the ticket status to "Resolved" or "Closed". Customers can optionally close or reopen tickets if allowed in General Settings.', 'wpcalibrate-support-desk'); ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Method & Architecture (Under the Hood) -->
            <div class="wpcalibrate-overview-section">
                <div class="wpcalibrate-section-header">
                    <span class="wpcalibrate-section-tag"><?php esc_html_e('Under the Hood', 'wpcalibrate-support-desk'); ?></span>
                    <h3><?php esc_html_e('3. Method & Architecture', 'wpcalibrate-support-desk'); ?></h3>
                    <p class="description"><?php esc_html_e('A technical breakdown of security mechanisms, state machines, and data isolation guarantees.', 'wpcalibrate-support-desk'); ?></p>
                </div>

                <div class="wpcalibrate-method-grid">
                    <div class="wpcalibrate-method-box">
                        <div class="wpcalibrate-method-title">
                            <span class="dashicons dashicons-randomize"></span>
                            <h4><?php esc_html_e('Ticket State Transition Machine', 'wpcalibrate-support-desk'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Tickets follow a rigorous lifecycle model with automatic state management:', 'wpcalibrate-support-desk'); ?></p>
                        <div class="wpcalibrate-lifecycle-flow">
                            <span class="badge badge-status-open"><?php esc_html_e('Open', 'wpcalibrate-support-desk'); ?></span> &rarr;
                            <span class="badge badge-status-in_progress"><?php esc_html_e('In Progress', 'wpcalibrate-support-desk'); ?></span> &rarr;
                            <span class="badge badge-status-waiting_customer"><?php esc_html_e('Waiting for Customer', 'wpcalibrate-support-desk'); ?></span> &rarr;
                            <span class="badge badge-status-resolved"><?php esc_html_e('Resolved', 'wpcalibrate-support-desk'); ?></span> &rarr;
                            <span class="badge badge-status-closed"><?php esc_html_e('Closed', 'wpcalibrate-support-desk'); ?></span>
                        </div>
                        <p class="wpcalibrate-subnote"><?php esc_html_e('When a staff agent replies, the system automatically transitions the status to "Waiting for Customer" and timestamps first response SLA metrics.', 'wpcalibrate-support-desk'); ?></p>
                    </div>

                    <div class="wpcalibrate-method-box">
                        <div class="wpcalibrate-method-title">
                            <span class="dashicons dashicons-lock"></span>
                            <h4><?php esc_html_e('Cryptographic Guest Access Token Flow', 'wpcalibrate-support-desk'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Guest customers can submit tickets without registering an account. Security is maintained through a zero-knowledge token mechanism:', 'wpcalibrate-support-desk'); ?></p>
                        <ul class="wpcalibrate-bullet-list">
                            <li><?php esc_html_e('On guest ticket submission, a 256-bit cryptographically secure random token is generated via PHP random_bytes(32).', 'wpcalibrate-support-desk'); ?></li>
                            <li><?php esc_html_e('Only the SHA-256 hash (hash("sha256", $token)) is persisted in the database table.', 'wpcalibrate-support-desk'); ?></li>
                            <li><?php esc_html_e('The guest receives a signed magic link (?ticket=WPC-000001&token=...). Verification uses hash_equals() in constant time, preventing timing attacks.', 'wpcalibrate-support-desk'); ?></li>
                        </ul>
                    </div>

                    <div class="wpcalibrate-method-box">
                        <div class="wpcalibrate-method-title">
                            <span class="dashicons dashicons-media-default"></span>
                            <h4><?php esc_html_e('Attachment Sandboxing & Streaming Delivery', 'wpcalibrate-support-desk'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Uploaded files are strictly validated against a conservative MIME and extension allowlist (JPG, PNG, PDF, ZIP, TXT, LOG). Dangerous script extensions (.php, .exe, .sh, .phtml) are blocked.', 'wpcalibrate-support-desk'); ?></p>
                        <p class="wpcalibrate-subnote"><?php esc_html_e('Files are stored in /uploads/wpcalibrate-support-desk/ secured with .htaccess and index.php silencer files that prohibit direct HTTP script execution. Downloads are served exclusively through authenticated PHP streaming endpoints.', 'wpcalibrate-support-desk'); ?></p>
                    </div>

                    <div class="wpcalibrate-method-box">
                        <div class="wpcalibrate-method-title">
                            <span class="dashicons dashicons-hidden"></span>
                            <h4><?php esc_html_e('Strict Staff Internal Notes Isolation', 'wpcalibrate-support-desk'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Internal staff notes are tagged with is_internal = 1 at the database layer. The front-end portal repository queries strictly enforce is_internal = 0, ensuring staff conversations are never rendered to customers, exposed in portal markup, or sent in customer emails.', 'wpcalibrate-support-desk'); ?></p>
                    </div>

                    <div class="wpcalibrate-method-box">
                        <div class="wpcalibrate-method-title">
                            <span class="dashicons dashicons-privacy"></span>
                            <h4><?php esc_html_e('GDPR & WordPress Core Privacy Integration', 'wpcalibrate-support-desk'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Fully hooked into WordPress Core Tools &rarr; Export Personal Data and Tools &rarr; Erase Personal Data. Customer emails can be exported in one click or permanently anonymized upon verified request while preserving ticket sequence integrity.', 'wpcalibrate-support-desk'); ?></p>
                    </div>

                    <div class="wpcalibrate-method-box">
                        <div class="wpcalibrate-method-title">
                            <span class="dashicons dashicons-database"></span>
                            <h4><?php esc_html_e('Atomic Concurrency & Sequence Engine', 'wpcalibrate-support-desk'); ?></h4>
                        </div>
                        <p><?php esc_html_e('Ticket references use an isolated sequence table with atomic increments. This guarantees sequential reference numbering (e.g., #WPC-000001, #WPC-000002) without gaps, duplicate numbers, or database deadlocks during high-traffic surges.', 'wpcalibrate-support-desk'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Section 4: Quick Reference & Shortcodes -->
            <div class="wpcalibrate-overview-section">
                <div class="wpcalibrate-section-header">
                    <span class="wpcalibrate-section-tag"><?php esc_html_e('Cheat Sheet', 'wpcalibrate-support-desk'); ?></span>
                    <h3><?php esc_html_e('4. Shortcode & Configuration Cheat Sheet', 'wpcalibrate-support-desk'); ?></h3>
                </div>

                <table class="wp-list-table widefat fixed striped" style="margin-top: 14px;">
                    <thead>
                        <tr>
                            <th style="width: 280px;"><strong><?php esc_html_e('Element / Shortcode', 'wpcalibrate-support-desk'); ?></strong></th>
                            <th><strong><?php esc_html_e('Description & Usage', 'wpcalibrate-support-desk'); ?></strong></th>
                            <th style="width: 180px;"><strong><?php esc_html_e('Location', 'wpcalibrate-support-desk'); ?></strong></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>[wpcalibrate_support_portal]</code></td>
                            <td><?php esc_html_e('Main customer support interface: ticket creation form, customer ticket history list, and threaded ticket discussion viewer.', 'wpcalibrate-support-desk'); ?></td>
                            <td><?php esc_html_e('Any WordPress Page', 'wpcalibrate-support-desk'); ?></td>
                        </tr>
                        <tr>
                            <td><code>#WPC-XXXXXX</code></td>
                            <td><?php esc_html_e('Unique sequential ticket reference number generated for every ticket. Configurable prefix in General Settings.', 'wpcalibrate-support-desk'); ?></td>
                            <td><?php esc_html_e('Emails & Portal', 'wpcalibrate-support-desk'); ?></td>
                        </tr>
                        <tr>
                            <td><code>?ticket=REF&token=KEY</code></td>
                            <td><?php esc_html_e('Signed guest magic link allowing guest customers to view their ticket thread securely without an account.', 'wpcalibrate-support-desk'); ?></td>
                            <td><?php esc_html_e('Guest Email Alerts', 'wpcalibrate-support-desk'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
        <?php
    }

    /**
     * Render WPCalibrate Support contact tab.
     *
     * @return void
     */
    private static function renderSupportTab(): void
    {
        ?>
        <div class="wpcalibrate-contact-section">
            <h3><?php esc_html_e('WPCalibrate Support & Inquiries', 'wpcalibrate-support-desk'); ?></h3>
            <p><?php esc_html_e('For plugin assistance, custom extensions, or feedback, reach out through any of our official channels:', 'wpcalibrate-support-desk'); ?></p>

            <table class="form-table wpcalibrate-contact-table">
                <tr>
                    <th><strong><?php esc_html_e('Email', 'wpcalibrate-support-desk'); ?>:</strong></th>
                    <td><a href="mailto:support@wpcalibrate.com">support@wpcalibrate.com</a></td>
                </tr>
                <tr>
                    <th><strong><?php esc_html_e('Website', 'wpcalibrate-support-desk'); ?>:</strong></th>
                    <td><a href="https://wpcalibrate.com/" target="_blank" rel="noopener noreferrer">https://wpcalibrate.com</a></td>
                </tr>
                <tr>
                    <th><strong><?php esc_html_e('Marketplace', 'wpcalibrate-support-desk'); ?>:</strong></th>
                    <td><a href="https://marketplace.wpcalibrate.com/" target="_blank" rel="noopener noreferrer">https://marketplace.wpcalibrate.com/</a></td>
                </tr>
                <tr>
                    <th><strong><?php esc_html_e('Phone / WhatsApp', 'wpcalibrate-support-desk'); ?>:</strong></th>
                    <td><a href="tel:+447474795976">+447474795976</a></td>
                </tr>
            </table>
        </div>
        <?php
    }
}
