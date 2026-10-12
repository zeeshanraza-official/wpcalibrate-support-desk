<?php
/**
 * WordPress Privacy Exporter and Eraser integration.
 *
 * @package WPCalibrate\SupportDesk\Privacy
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Privacy;

use WPCalibrate\SupportDesk\Database\SchemaManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class PrivacyManager
 */
final class PrivacyManager
{
    /**
     * Register privacy hooks.
     *
     * @return void
     */
    public static function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerEraser']);
    }

    /**
     * Register plugin exporter.
     *
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public static function registerExporter(array $exporters): array
    {
        $exporters['wpcalibrate-support-desk'] = [
            'exporter_friendly_name' => __('WPCalibrate Support Desk Data', 'wpcalibrate-support-desk'),
            'callback'               => [self::class, 'exportPersonalData'],
        ];

        return $exporters;
    }

    /**
     * Register plugin eraser.
     *
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public static function registerEraser(array $erasers): array
    {
        $erasers['wpcalibrate-support-desk'] = [
            'eraser_friendly_name' => __('WPCalibrate Support Desk Data', 'wpcalibrate-support-desk'),
            'callback'             => [self::class, 'erasePersonalData'],
        ];

        return $erasers;
    }

    /**
     * Export personal data for an email address.
     *
     * @param string $emailAddress
     * @param int $page
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public static function exportPersonalData(string $emailAddress, int $page = 1): array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $user = get_user_by('email', $emailAddress);
        $userId = $user ? (int) $user->ID : 0;

        $limit = 50;
        $offset = ($page - 1) * $limit;

        $tickets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['tickets']}
                 WHERE (customer_id = %d AND %d > 0) OR guest_email = %s
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $userId,
                $userId,
                $emailAddress,
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        $exportData = [];
        foreach ($tickets as $t) {
            $exportData[] = [
                'group_id'    => 'wpcalibrate_tickets',
                'group_label' => __('Support Tickets', 'wpcalibrate-support-desk'),
                'item_id'     => 'ticket-' . $t['id'],
                'data'        => [
                    [
                        'name'  => __('Ticket Reference', 'wpcalibrate-support-desk'),
                        'value' => $t['ticket_ref'],
                    ],
                    [
                        'name'  => __('Subject', 'wpcalibrate-support-desk'),
                        'value' => $t['subject'],
                    ],
                    [
                        'name'  => __('Status', 'wpcalibrate-support-desk'),
                        'value' => $t['status'],
                    ],
                    [
                        'name'  => __('Date Created', 'wpcalibrate-support-desk'),
                        'value' => $t['created_at'],
                    ],
                ],
            ];
        }

        $done = count($tickets) < $limit;

        return [
            'data' => $exportData,
            'done' => $done,
        ];
    }

    /**
     * Erase personal data for an email address.
     *
     * @param string $emailAddress
     * @param int $page
     * @return array{items_removed: int, items_retained: int, messages: array<string>, done: bool}
     */
    public static function erasePersonalData(string $emailAddress, int $page = 1): array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $user = get_user_by('email', $emailAddress);
        $userId = $user ? (int) $user->ID : 0;

        $limit = 50;
        $offset = ($page - 1) * $limit;

        $tickets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tables['tickets']}
                 WHERE (customer_id = %d AND %d > 0) OR guest_email = %s
                 ORDER BY id ASC
                 LIMIT %d OFFSET %d",
                $userId,
                $userId,
                $emailAddress,
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        $itemsRemoved = 0;
        $itemsRetained = 0;
        $messages = [];

        $erasePolicy = get_option('wpcalibrate_privacy_erase_action', 'anonymize');

        foreach ($tickets as $t) {
            if ($erasePolicy === 'delete') {
                $ticketRepo = new \WPCalibrate\SupportDesk\Repositories\TicketRepository();
                $ticketRepo->delete((int) $t['id']);
                $itemsRemoved++;
            } else {
                // Anonymize: retain ticket history without identifiable customer info.
                $wpdb->update(
                    $tables['tickets'],
                    [
                        'customer_id'            => null,
                        'guest_name'             => __('Anonymized User', 'wpcalibrate-support-desk'),
                        'guest_email'            => 'anonymized@example.invalid',
                        'guest_token_hash'       => null,
                        'guest_token_created_at' => null,
                        'woo_order_id'           => null,
                    ],
                    ['id' => (int) $t['id']],
                    [null, '%s', '%s', null, null, null],
                    ['%d']
                );

                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$tables['messages']}
                         SET author_name = %s, author_email = %s
                         WHERE ticket_id = %d AND (author_id = %d OR author_email = %s)",
                        __('Anonymized User', 'wpcalibrate-support-desk'),
                        'anonymized@example.invalid',
                        (int) $t['id'],
                        $userId,
                        $emailAddress
                    )
                );

                $itemsRetained++;
            }
        }

        if ($itemsRetained > 0) {
            $messages[] = sprintf(
                /* translators: %d: number of records */
                __('Anonymized personal details on %d support tickets while retaining records for audit.', 'wpcalibrate-support-desk'),
                $itemsRetained
            );
        }

        $done = count($tickets) < $limit;

        return [
            'items_removed'  => $itemsRemoved,
            'items_retained' => $itemsRetained,
            'messages'       => $messages,
            'done'           => $done,
        ];
    }
}
