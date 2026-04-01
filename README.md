# Numismatist – Coin Collection Manager

A secure, AJAX-driven WordPress plugin for managing a personal coin (numismatics) collection. The collection is displayed on any page of your site using the shortcode `[numismatist]`. All CRUD operations are available directly on the page for logged-in administrators.

## Quick Start

1. Install and activate the plugin.
2. Create a new page, insert the shortcode `[numismatist]` and publish it.
3. Open the page as an administrator — you will see the Add / Edit / Delete controls.

## Shortcode

```
[numismatist]
```

| Visitors (not logged in) | Administrators (`manage_options`) |
|---|---|
| Table with search, year & material filters, pagination | Everything above + Add button, edit ✏ and delete 🗑 icon buttons, modal form with Media Library |

The shortcode can be placed on multiple pages simultaneously.

## Features

- **AJAX-driven** — search, filters, pagination, and all CRUD operations work without page reloads.
- **Modal editor** — click a coin's name (or the pencil icon) to open a full-featured edit form.
- **Media Library** — native WordPress media uploader for coin photos.
- **Security** — nonce verification, `manage_options` capability check, full input sanitization (`sanitize_text_field`, `absint`, `esc_url_raw`, `sanitize_textarea_field`), `$wpdb->prepare()` on every query.
- **Migrations** — extendable `Num_Migrations` class for future schema changes (zero downtime, idempotent).
- **Performance** — CSS and JS are enqueued only on pages that contain the shortcode (frontend) or on the plugin's own admin info page.

## Requirements

| Dependency | Version |
|---|---|
| WordPress | ≥ 6.0 |
| PHP | ≥ 8.0 |
| MySQL / MariaDB | ≥ 5.7 |

## Installation

1. Copy the `Numismatist/` folder to `wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Go to **Монети** in the admin sidebar to see the setup guide and shortcode.

## Admin Panel

The **Монети** menu item in the WordPress admin does **not** contain the table. It shows:

- The shortcode to copy (`[numismatist]`).
- Step-by-step instructions on how to add it to a page.
- Tips about search, filters, and photo uploads.

All data management happens on the **frontend page** where the shortcode is placed.

## File Structure

```
Numismatist/
├── numismatist.php              # Entry point: constants, menu, activation hook, admin info page
├── includes/
│   ├── db-setup.php             # Table creation via dbDelta (safe to re-run)
│   ├── class-migrations.php     # Incremental DB migrations
│   ├── class-crud.php           # All database operations (read, insert, update, delete)
│   ├── class-ajax.php           # AJAX endpoint registration and secure handling
│   └── class-shortcode.php      # [numismatist] shortcode — renders the full UI
├── js/
│   └── numismatist.js           # Table rendering, pagination, modal, icon buttons, AJAX
├── css/
│   └── numismatist.css          # Styles for table, toolbar, icon buttons, modal (admin + frontend)
├── deploy-vps.sh                # rsync deploy to remote VPS
├── deploy-local.sh              # Copy to local Docker environment
├── README.md
└── LICENSE
```

## Database Table

`{prefix}coins`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED | Primary key, auto-increment |
| name | VARCHAR(255) | Required |
| url | VARCHAR(2048) | External reference link |
| year | SMALLINT | Coin issue year |
| material | VARCHAR(100) | e.g. "Срібло" |
| circulation | VARCHAR(100) | Mintage / тираж |
| price | DECIMAL(10,2) | Estimated value |
| photo | VARCHAR(2048) | Media Library URL |
| quantity | INT UNSIGNED | Owned copies |
| notes | TEXT | Free-form notes |
| sorting | INT | Manual sort order (default 0) |
| created_at | DATETIME | Set automatically on insert |
| updated_at | DATETIME | Updated automatically on change |

## Deployment

See `deploy-vps.sh` (rsync to remote VPS with permission fix) and `deploy-local.sh` (rsync to local Docker environment).

## Changelog

### 1.1.0
- Added `[numismatist]` shortcode for frontend display.
- Admin panel page replaced with setup instructions + shortcode reference.
- Edit / Delete text buttons replaced with compact SVG icon buttons (✏ / 🗑).
- Visitors see the table without action controls; admins see full CRUD.
- CSS now works both in the admin area and on frontend pages.
- Added responsive styles for mobile screens.

### 1.0.0
- Initial release.

## License

GPL v2 or later. See `LICENSE`.
