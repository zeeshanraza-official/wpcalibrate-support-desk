<?php
/**
 * Cryptographically secure guest token generator and verifier.
 *
 * @package WPCalibrate\SupportDesk\Security
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class GuestTokenService
 */
final class GuestTokenService
{
    /**
     * Generate a cryptographically secure random raw token.
     *
     * @return string 64-character hexadecimal token.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash the raw token for secure storage.
     *
     * @param string $rawToken Plain token.
     * @return string SHA-256 hash.
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', trim($rawToken));
    }

    /**
     * Verify a plain token against the stored SHA-256 hash using timing-safe comparison.
     *
     * @param string $rawToken Plain token to test.
     * @param string $storedHash SHA-256 hash in database.
     * @return bool
     */
    public static function verifyToken(string $rawToken, string $storedHash): bool
    {
        if (empty($rawToken) || empty($storedHash)) {
            return false;
        }

        $calculatedHash = self::hashToken($rawToken);
        return hash_equals($storedHash, $calculatedHash);
    }

    /**
     * Build the frontend guest access URL.
     *
     * @param string $ticketRef Ticket human reference (e.g. WPC-000001).
     * @param string $rawToken Plain guest access token.
     * @param string|null $portalUrl Base portal URL (or null to use home/configured portal).
     * @return string
     */
    public static function buildGuestAccessUrl(string $ticketRef, string $rawToken, ?string $portalUrl = null): string
    {
        $base = $portalUrl;
        if (empty($base)) {
            $portalPageId = (int) get_option('wpcalibrate_support_portal_page_id', 0);
            if ($portalPageId > 0) {
                $base = get_permalink($portalPageId);
            }
        }

        if (empty($base) || is_wp_error($base)) {
            $base = home_url('/');
        }

        return add_query_arg(
            [
                'wpc_ticket' => rawurlencode($ticketRef),
                'wpc_token'  => rawurlencode($rawToken),
            ],
            $base
        );
    }
}
