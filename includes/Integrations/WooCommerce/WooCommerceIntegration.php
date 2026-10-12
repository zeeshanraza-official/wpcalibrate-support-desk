<?php
/**
 * Safe optional WooCommerce integration.
 *
 * @package WPCalibrate\SupportDesk\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Integrations\WooCommerce;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WooCommerceIntegration
 */
final class WooCommerceIntegration
{
    /**
     * Check if WooCommerce is installed and active.
     *
     * @return bool
     */
    public static function isActive(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_order');
    }

    /**
     * Check if WooCommerce integration is enabled in settings.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::isActive() && (bool) get_option('wpcalibrate_woo_integration_enabled', '1');
    }

    /**
     * Get recent eligible orders for a logged-in customer.
     *
     * @param int $customerId
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function getCustomerOrders(int $customerId, int $limit = 20): array
    {
        if (!self::isEnabled() || $customerId <= 0) {
            return [];
        }

        if (!function_exists('wc_get_orders')) {
            return [];
        }

        // Use standard WC CRUD API (HPOS compliant).
        $orders = wc_get_orders([
            'customer' => $customerId,
            'limit'    => $limit,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'status'   => ['completed', 'processing', 'on-hold', 'pending', 'refunded'],
        ]);

        $result = [];
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $result[] = [
                'id'           => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '',
                'status'       => wc_get_order_status_name($order->get_status()),
                'total'        => $order->get_formatted_order_total(),
                'items_count'  => $order->get_item_count(),
            ];
        }

        return $result;
    }

    /**
     * Verify if a candidate order belongs to the customer.
     *
     * @param int $orderId
     * @param int $customerId
     * @return bool
     */
    public static function isEligibleOrderForCustomer(int $orderId, int $customerId): bool
    {
        if (!self::isEnabled() || $orderId <= 0 || $customerId <= 0) {
            return false;
        }

        if (!function_exists('wc_get_order')) {
            return false;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return false;
        }

        return (int) $order->get_customer_id() === $customerId;
    }

    /**
     * Get safe non-sensitive order summary card for support agents.
     *
     * @param int $orderId
     * @return array<string, mixed>|null
     */
    public static function getOrderSummary(int $orderId): ?array
    {
        if (!self::isActive() || $orderId <= 0) {
            return null;
        }

        if (!function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return null;
        }

        // Collect item names without exposing sensitive billing data.
        $itemNames = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $itemNames[] = sprintf('%s (x%d)', $item->get_name(), $item->get_quantity());
            }
        }

        $adminEditUrl = '';
        if (current_user_can('edit_shop_orders') || current_user_can('manage_woocommerce')) {
            $adminEditUrl = $order->get_edit_order_url();
        }

        return [
            'id'             => $order->get_id(),
            'order_number'   => $order->get_order_number(),
            'status'         => wc_get_order_status_name($order->get_status()),
            'status_raw'     => $order->get_status(),
            'date_created'   => $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : '',
            'total'          => $order->get_formatted_order_total(),
            'currency'       => $order->get_currency(),
            'items_summary'  => implode(', ', $itemNames),
            'items_count'    => $order->get_item_count(),
            'admin_edit_url' => $adminEditUrl,
        ];
    }
}
