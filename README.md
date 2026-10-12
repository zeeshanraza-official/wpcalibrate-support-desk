# WPCalibrate Support Desk

[![WordPress](https://img.shields.io/badge/WordPress-6.5%20to%207.1+-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-HPOS%20Compatible-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Release-v1.0.0-emerald.svg)](https://github.com/zeeshanraza-official/wpcalibrate-support-desk/releases)

A complete, free customer support ticketing system and helpdesk built directly inside WordPress. Provides responsive frontend customer portal submission, cryptographically secured guest access, staff triage dashboard, strictly isolated internal notes, atomic sequential numbering (`WPC-000001`), sandboxed file attachments, and optional WooCommerce order linking.

---

## Table of Contents

- [About](#about)
- [Key Features](#key-features)
- [Architecture & Security](#architecture--security)
  - [Ticket State Machine](#ticket-state-machine)
  - [Guest Cryptographic Access Workflow](#guest-cryptographic-access-workflow)
  - [Internal Notes Isolation](#internal-notes-isolation)
  - [Protected Attachment Sandboxing](#protected-attachment-sandboxing)
- [Documentation & How to Use](#documentation--how-to-use)
  - [1. Installation](#1-installation)
  - [2. Embedding the Support Portal](#2-embedding-the-support-portal)
  - [3. Configuring Settings](#3-configuring-settings)
  - [4. Managing Departments](#4-managing-departments)
  - [5. Staff Roles & Capabilities](#5-staff-roles--capabilities)
- [WordPress Dashboard Automatic Updates](#wordpress-dashboard-automatic-updates)
- [REST API Endpoints](#rest-api-endpoints)
- [Developer Hooks](#developer-hooks)
- [Changelog](#changelog)
- [License & Support](#license--support)

---

## About

Most modern helpdesk solutions force WordPress store owners into expensive external SaaS subscriptions with recurring per-seat fees, vendor lock-in, and complex third-party data pipelines that complicate GDPR compliance.

**WPCalibrate Support Desk** solves this by delivering an enterprise-grade customer support helpdesk hosted entirely within your WordPress installation:

* **100% Data Sovereignty:** All tickets, conversations, private notes, and attachments stay on your server in dedicated database tables.
* **Zero Subscription Fees:** No monthly per-agent costs or tier limitations.
* **Deep WooCommerce Synergy:** Automatically connects to WooCommerce when present, allowing customers to link their order numbers directly to support inquiries.
* **High Performance:** Uses 6 dedicated, indexed custom SQL tables rather than bloating `wp_posts` and `wp_postmeta`.

---

## Key Features

- **Frontend Customer Portal:** Modern, clean, and accessible interface for ticket submission, conversation threads, and replies. Embeddable via `[wpcalibrate_support_portal]` or Gutenberg block.
- **Registered & Guest Support:** Authenticated WordPress users view their full support history. Visitors and guests submit tickets without account creation and receive timing-safe, cryptographically hashed access links.
- **Staff Triage Dashboard:** Purpose-built admin workspace with summary metric cards, filterable status tabs, live search, and server-side pagination.
- **Strict Internal Notes Boundary:** Support agents can post yellow-tinted internal notes that are strictly isolated from customer queries, frontend views, REST API customer responses, and email dispatches.
- **Concurrency-Safe Sequence References:** Human-readable ticket references (e.g. `WPC-000001`) generated atomically using `LAST_INSERT_ID(current_val + 1)` on dedicated sequence tables.
- **Sandboxed Attachments:** File uploads are stored outside public web access in `/wp-content/uploads/wpcalibrate-support-desk/` protected by `.htaccess` deny rules and served via authenticated streaming PHP endpoints.
- **Defensive Transactional Emails:** Responsive email notifications powered by `wp_mail()` for ticket creation, replies, agent assignments, and status transitions, fortified with try-catch safeguards and request deduplication.
- **WooCommerce HPOS Integration:** Declares High-Performance Order Storage compatibility. Logged-in customers can link eligible purchases to tickets. Operates 100% independently if WooCommerce is absent.
- **GDPR & Privacy Compliance:** Native integration with WordPress Core Personal Data Exporter and Personal Data Eraser with configurable data retention rules.
- **Safe Data Retention:** Preserves all ticket data upon deactivation and uninstallation by default; destructive database cleanup requires explicit opt-in.

---

## Architecture & Security

### Ticket State Machine

Tickets progress through a well-defined lifecycle managed by the `TicketService`:

```
  [ New Ticket ]
        │
        ▼
     [ Open ] ◄──────────────────────────────┐
        │                                    │
   Agent Replies                       Customer Replies
        │                                    │
        ▼                                    │
 [ Waiting Customer ] ───────────────────────┘
        │
   Issue Solved
        │
        ▼
   [ Resolved ] ──► (Customer Reopens) ──► [ Open ]
        │
   Archived
        │
        ▼
    [ Closed ]
```

### Guest Cryptographic Access Workflow

Guest users do not require WordPress accounts to participate in support conversations:

1. When a guest submits a ticket, a 64-character hexadecimal token is generated using `random_bytes(32)`.
2. The raw token is returned once in the secure confirmation email and URL parameter (`?wpc_ticket=WPC-000001&wpc_token=...`).
3. Only the SHA-256 hash of the token (`hash('sha256', $rawToken)`) is stored in `{prefix}wpcalibrate_tickets`.
4. Subsequent requests verify the guest token using timing-attack safe `hash_equals()`.

### Internal Notes Isolation

Staff collaboration notes maintain a hard boundary:

```
[Agent Message Composer]
      │
      ├── Is Internal Note? ──► Visibility = 'internal'
      │                               │
      │                               ├── Saved to DB with is_internal = 1
      │                               ├── Excluded from public SQL queries
      │                               ├── Excluded from customer REST API
      │                               └── Zero customer email notifications
      │
      └── Public Reply?     ──► Visibility = 'public'
                                      │
                                      ├── Dispatched to Customer via email
                                      └── Visible on Customer Portal
```

### Protected Attachment Sandboxing

Attachments are shielded against direct web exposure:

* Files are stored in `wp-content/uploads/wpcalibrate-support-desk/` guarded by `.htaccess` (`Deny from all`) and an empty `index.php`.
* Disallowed extensions (PHP, executables, scripts, SVG containing scripts) are strictly rejected.
* Authorized users download files via the authenticated streaming handler: `wp-admin/admin-ajax.php?action=wpcalibrate_download_attachment&id=...`.

---

## Documentation & How to Use

### 1. Installation

1. Download `wpcalibrate-support-desk-1.0.0.zip` from the latest [GitHub Release](https://github.com/zeeshanraza-official/wpcalibrate-support-desk/releases).
2. In your WordPress admin dashboard, navigate to **Plugins &rarr; Add New &rarr; Upload Plugin**.
3. Choose the ZIP file and click **Install Now**, then click **Activate**.

### 2. Embedding the Support Portal

Create a new page in WordPress (e.g., "Support") and embed the portal using either:

* **Shortcode:** Add `[wpcalibrate_support_portal]` to any classic or block page.
* **Gutenberg Block:** Search for the **WPCalibrate Support Portal** block (`wpcalibrate/support-portal`).

### 3. Configuring Settings

Navigate to **WPCalibrate &rarr; Settings** in your WordPress admin menu:

* **General:** Set the Primary Support Portal Page so emails link customers directly to the correct page.
* **Departments:** Create ticket categories (e.g., General Support, Billing, Technical, Sales).
* **Email Notifications:** Customize sender name, from address, admin alert recipient, and toggle customer confirmations.
* **Attachments:** Configure allowed file extensions and maximum file size (default 5 MB).
* **WooCommerce:** Toggle order linkage for logged-in customers.
* **Overview:** Access the in-dashboard guide explaining features, philosophy, and cheat sheet.

### 4. Managing Departments

Organize support queues under **WPCalibrate &rarr; Departments**:

* Create custom department categories with dedicated slugs.
* Deleting a department safely reassigns historical tickets to the default category, preserving audit continuity.

### 5. Staff Roles & Capabilities

The plugin registers a dedicated **Support Agent** (`support_agent`) role and introduces granular capabilities:

| Capability | Administrator | Support Agent | Subscriber / Customer |
| :--- | :---: | :---: | :---: |
| `wpcalibrate_view_tickets` | Yes | Yes | No |
| `wpcalibrate_view_assigned_tickets` | Yes | Yes | No |
| `wpcalibrate_view_all_tickets` | Yes | Yes | No |
| `wpcalibrate_reply_tickets` | Yes | Yes | No |
| `wpcalibrate_add_internal_notes` | Yes | Yes | No |
| `wpcalibrate_assign_tickets` | Yes | Yes | No |
| `wpcalibrate_change_status` | Yes | Yes | No |
| `wpcalibrate_manage_settings` | Yes | No | No |

---

## WordPress Dashboard Automatic Updates

This repository is structured for seamless compatibility with standard WordPress update engines and GitHub release tracking:

1. **GitHub Releases:** Each version tag publishes an installable ZIP containing the standardized `wpcalibrate-support-desk/` directory.
2. **Dashboard Updates:** When paired with [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) or GitHub updater plugins, WordPress checks this repository for new releases and prompts 1-click automatic updates from the **Plugins** dashboard.

---

## REST API Endpoints

The plugin provides namespaced endpoints under `/wp-json/wpcalibrate-support-desk/v1`:

| Method | Endpoint | Description | Permission |
| :--- | :--- | :--- | :--- |
| `GET` | `/tickets` | List tickets with filters and pagination | Staff or Customer (filtered) |
| `POST` | `/tickets` | Create a new support ticket | Public or Logged in |
| `GET` | `/tickets/{id}` | Retrieve ticket details and messages | Access Policy (IDOR safe) |
| `POST` | `/tickets/{id}/reply` | Add public reply or internal note | Access Policy verified |
| `POST` | `/tickets/{id}/status` | Update ticket status | Staff or Customer (close) |
| `POST` | `/tickets/{id}/assign` | Reassign ticket agent | Staff with assign cap |
| `GET` | `/categories` | List active support departments | Public |
| `GET` | `/stats` | Dashboard metric summaries | Staff with view cap |

---

## Developer Hooks

```php
// Hook into ticket creation
add_action( 'wpcalibrate_ticket_created', function( array $ticket, ?string $rawGuestToken ) {
    // Custom workflow or webhook integration
}, 10, 2 );

// Hook into reply added event
add_action( 'wpcalibrate_reply_added', function( array $ticket, array $message, bool $isAgent ) {
    // Notify third-party CRM or chat
}, 10, 3 );

// Filter allowed attachment mime types
add_filter( 'wpcalibrate_allowed_mime_types', function( array $mimes ) {
    $mimes['dwg'] = 'application/acad';
    return $mimes;
} );

// Filter ticket sequence prefix
add_filter( 'wpcalibrate_ticket_ref_prefix', function( string $prefix, int $categoryId ) {
    return 'HELP';
}, 10, 2 );
```

---

## Changelog

### [1.0.0] - 2026-10-12
- **Initial Release:** Complete standalone helpdesk plugin for WordPress.
- 6 dedicated custom database tables with idempotent schema migrations.
- Concurrency-safe atomic sequential numbering (`WPC-000001`).
- Cryptographic guest token system with SHA-256 hashing and timing-safe verification.
- Staff triage dashboard with metric cards, filters, and conversation composer.
- Strictly isolated internal notes boundary.
- Protected attachment sandbox with `.htaccess` and streaming authorization.
- Optional WooCommerce HPOS integration with customer order association.
- Frontend Support Portal shortcode `[wpcalibrate_support_portal]` and Gutenberg block.
- Transactional email service with header injection prevention and deduplication.
- WordPress Core GDPR personal data export and erasure integration.
- Dedicated `support_agent` user role with granular capabilities.
- 100% automated test suite coverage (45/45 tests passing).

---

## License & Support

- **License:** GNU General Public License v2.0 or later ([GPL-2.0-or-later](LICENSE))
- **Author:** WPCalibrate
- **Website:** [https://wpcalibrate.com](https://wpcalibrate.com)
- **Marketplace:** [https://marketplace.wpcalibrate.com](https://marketplace.wpcalibrate.com)
- **Support:** [support@wpcalibrate.com](mailto:support@wpcalibrate.com)
