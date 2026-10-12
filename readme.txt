=== WPCalibrate Support Desk ===
Contributors: wpcalibrate
Donate link: https://wpcalibrate.com/
Tags: support, helpdesk, ticketing, customer support, woocommerce support
Requires at least: 6.5
Tested up to: 7.1.3
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete, free customer support ticketing system for WordPress. Operates a professional helpdesk directly inside WordPress with guest access, internal notes, and optional WooCommerce integration.

== Description ==

**WPCalibrate Support Desk** is a standalone, professional customer support ticketing system built from the ground up for WordPress. It allows businesses, digital agencies, e-commerce stores, and service providers to operate a fully functional helpdesk directly inside WordPress without requiring third-party SaaS subscriptions.

### Key Features

* **Frontend Support Portal:** Responsive, modern portal where customers can submit tickets, track existing conversations, and reply.
* **Registered Customer & Guest Support:** Customers can log in to view their ticket history, while guest users can submit tickets and access them via cryptographically secure, hashed access tokens delivered via email.
* **Staff Admin Interface:** Purpose-built admin workspace with summary metric cards, filterable ticket tables, and conversation timelines.
* **Internal Agent Notes:** Staff members can collaborate with private internal notes that are strictly isolated from customers, customer emails, and public queries.
* **Support Agent Role & Granular Capabilities:** Dedicated `support_agent` WordPress role with granular permissions.
* **Concurrency-Safe Sequential Numbering:** Generates human-readable ticket references (e.g., `WPC-000001`) with atomic locking.
* **Protected File Attachments:** Attachments are stored in a protected directory outside public web browsing, with strict MIME/extension verification and authenticated streaming downloads.
* **Email Notifications:** Clean email notifications via standard `wp_mail()` for new tickets, replies, status transitions, and agent assignments with anti-loop deduplication.
* **Optional WooCommerce Integration:** Automatically detects WooCommerce and allows logged-in customers to link their own orders. Declares HPOS (High-Performance Order Storage) compatibility. Operates completely independently if WooCommerce is absent.
* **Gutenberg Block & Shortcode:** Embed the portal with the Gutenberg block `wpcalibrate/support-portal` or shortcode `[wpcalibrate_support_portal]` (also supports `[wpcalibrate_support_desk]`).
* **Privacy & GDPR Compliant:** Full integration with WordPress personal data export and erasure tools, with options to anonymize customer records while preserving business history.
* **Data Retention Safety:** Preserves all ticket data upon deactivation and uninstall by default; destructive deletion is optional and requires explicit administrator opt-in.

== Installation ==

1. Upload the `wpcalibrate-support-desk` folder to the `/wp-content/plugins/` directory, or upload the ZIP file directly via Plugins &rarr; Add New &rarr; Upload Plugin.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Create a new WordPress page (e.g., "Support") and add the **WPCalibrate Support Portal** block or the `[wpcalibrate_support_portal]` shortcode.
4. Navigate to **WPCalibrate &rarr; Settings &rarr; General** and select your Support Portal page.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =
No. WooCommerce is entirely optional. Ticketing works completely without WooCommerce. If WooCommerce is installed, customers can optionally link their eligible orders to tickets.

= Can visitors submit tickets without creating an account? =
Yes. Guest ticket submission can be enabled in settings. When a guest submits a ticket, the system generates a cryptographically secure 64-character token, hashes it in the database with SHA-256, and emails the guest a private access link.

= Are internal notes visible to customers? =
Never. Internal notes are restricted to users with the `wpcalibrate_add_internal_notes` capability. They are strictly excluded from frontend HTML, REST API responses, customer emails, and attachment endpoints.

= Will my data be lost if I deactivate the plugin? =
No. Deactivation preserves all tickets, conversations, categories, and settings. Data is also preserved on uninstall unless you explicitly enable destructive data deletion in Settings &rarr; Data & Privacy.

== Screenshots ==

1. Admin Ticket Dashboard with metric cards and filterable ticket table.
2. Ticket conversation timeline with staff reply composer and internal note switcher.
3. Frontend Support Portal ticket submission form.
4. Frontend conversation thread for logged-in customers and guests.

== Changelog ==

= 1.0.0 =
* Initial public release of WPCalibrate Support Desk.
* Complete frontend portal with shortcode `[wpcalibrate_support_desk]` and block `wpcalibrate/support-portal`.
* Purpose-built admin interface with status summary cards and server-side pagination.
* Secure guest access workflow with SHA-256 token hashing and timing-safe verification.
* Support Agent role and granular capabilities.
* Protected attachment storage and authenticated streaming handler.
* Optional WooCommerce integration with HPOS compatibility.
* WordPress personal data exporter and eraser integration.
