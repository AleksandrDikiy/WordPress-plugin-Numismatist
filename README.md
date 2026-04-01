# Numismatist – Coin Collection Manager

A secure, multi-user, AJAX-driven WordPress plugin for managing personal coin (numismatics) collections. Each registered user manages their own completely isolated collection. Use the shortcode `[numismatist]` to display the collection on any page.

## Quick Start

1. Install and activate the plugin.
2. Create a new page, insert `[numismatist]`, publish it.
3. Any registered user who opens that page gets their own private collection with full CRUD controls.

## Shortcode

```
[numismatist]
```

| Who | What they see |
|---|---|
| Logged-in user | Their own coins + Add button + ✏ Edit / 🗑 Delete icon buttons + modal form |
| Guest (not logged in) | Read-only table (empty — guests have no coins) |

The shortcode can be placed on multiple pages simultaneously.

## Features

- **Multi-user isolation** — every user sees and manages only their own coins. The `user_id` column in the DB table enforces this at the query level; no user can read or modify another user's records.
- **AJAX-driven** — search, filters, pagination, and all CRUD operations without page reloads.
- **Any logged-in user** can use the plugin (not just admins). The `manage_options` restriction was removed from CRUD operations.
- **Modal editor** — click a coin name or the ✏ icon to open a full edit form.
- **Media Library** — native WordPress media uploader for coin photos.
- **Compact icon buttons** — SVG pencil / trash icons instead of text buttons in table rows.
- **Yellow Cancel button** — distinct visual for the Скасувати action in the modal footer.
- **Security** — nonce verification, `is_user_logged_in()` check, all input sanitized, `$wpdb->prepare()` on every query, `user_id` scoping prevents cross-user data access.
- **Migrations** — extendable `Num_Migrations` class; v1.2.0 migration adds `user_id` to existing installs automatically.

## Requirements

| Dependency | Version |
|---|---|
| WordPress | ≥ 6.0 |
| PHP | ≥ 8.0 |
| MySQL / MariaDB | ≥ 5.7 |

## Installation

1. Copy the `Numismatist/` folder to `wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Open **Монети** in the WordPress admin sidebar for the setup guide and shortcode.

> **Upgrading from v1.0.0 or v1.1.0?** Simply activate the new version. The migration runs automatically and adds the `user_id` column to the existing table without data loss.

## Admin Panel

The **Монети** admin menu item (visible only to `manage_options` users) shows:
- The shortcode to copy.
- Step-by-step setup instructions.
- Multi-user feature explanation.

All coin data management happens on the **frontend page** where `[numismatist]` is placed.

## File Structure

```
Numismatist/
├── numismatist.php              # Entry point: constants, activation, menu, admin info page
├── includes/
│   ├── db-setup.php             # Table creation via dbDelta (includes user_id column)
│   ├── class-migrations.php     # Incremental DB migrations (v1.2.0 adds user_id)
│   ├── class-crud.php           # All DB operations — scoped to current user_id
│   ├── class-ajax.php           # AJAX handlers — auth via nonce + is_user_logged_in()
│   └── class-shortcode.php      # [numismatist] shortcode — renders full UI
├── js/
│   └── numismatist.js           # Table, pagination, modal, icon buttons, AJAX
├── css/
│   └── numismatist.css          # Styles: table, toolbar, icons, modal, yellow cancel button
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
| **user_id** | **BIGINT UNSIGNED** | **WordPress user ID — enforces per-user isolation** |
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

See `deploy-vps.sh` (rsync to VPS + permission fix) and `deploy-local.sh` (rsync to local Docker).

## Changelog

### 1.2.0
- **Fix:** ДОДАТИ button now works for all logged-in users, not just `manage_options` admins.
- **New:** Multi-user support — `user_id` column added to the table; every query is scoped to the current user.
- **New:** Migration `1.2.0` automatically adds `user_id` to existing installs without data loss.
- **New:** Yellow СКАСУВАТИ button in the modal footer for distinct visual identity.
- **Fix:** AJAX capability check changed from `manage_options` to `is_user_logged_in()`.
- **Fix:** Filter dropdowns (year, material) now show only the current user's values.
- Updated README with multi-user documentation.

### 1.1.0
- Added `[numismatist]` shortcode for frontend display.
- Admin panel replaced with setup instructions + shortcode reference.
- Edit / Delete text buttons replaced with compact SVG icon buttons.
- Responsive styles for mobile screens.

### 1.0.0
- Initial release.

## License

GPL v2 or later. See `LICENSE`.
