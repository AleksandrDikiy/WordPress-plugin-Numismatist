# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

**Numismatist** is a WordPress plugin (v1.3.0) for managing personal coin collections. Each user's data is strictly isolated by `id_user` at the SQL level. The frontend is a single `[numismatist]` shortcode that renders a full jQuery-based CRUD interface.

- Requires: WordPress ≥ 6.0, PHP ≥ 8.0
- License: GPL v2 or later

## Development Commands

No build tools (no Composer, npm, webpack). Assets are plain CSS and jQuery JS.

**Deploy/install:** Copy the plugin directory to `wp-content/plugins/numismatist/` and activate via WP Admin. Deployment scripts are shell-based and git-ignored (`*.sh`).

**Manual testing:** Activate plugin, add `[numismatist]` to any page, log in as different users to verify data isolation.

There are no automated tests, no linting configs, and no CI setup.

## Architecture

### Entry Point

`numismatist.php` bootstraps everything:
- Defines constants (`NUM_VERSION`, `NUM_TABLE`)
- Activation hook → creates DB table + runs migrations
- `plugins_loaded` hook → auto-upgrade on version mismatch
- Registers admin menu page
- Instantiates `Num_Ajax` and `Num_Shortcode`

### Class Responsibilities (`includes/`)

| File | Class | Role |
|------|-------|------|
| `db-setup.php` | _(functions)_ | Creates `{prefix}coins` table via `dbDelta()` |
| `class-migrations.php` | `Num_Migrations` | Incremental schema migrations keyed by version string |
| `class-crud.php` | `Num_CRUD` | All DB reads/writes; constructor receives `$wpdb` and `$user_id` |
| `class-ajax.php` | `Num_Ajax` | Registers 5 `wp_ajax_num_*` handlers; owns input sanitization and auth |
| `class-shortcode.php` | `Num_Shortcode` | Renders HTML table/modal; enqueues CSS+JS conditionally |

All classes are `final` with `declare(strict_types=1)`.

### Database Table: `{prefix}coins`

Key columns: `id`, `id_user` (FK to wp_users), `name`, `year`, `material`, `circulation`, `price`, `photo`, `quantity`, `notes`, `sorting`, `created_at`, `updated_at`.

Indexes on: `id_user`, `year`, `material`, `sorting`.

**Every query in `Num_CRUD` filters by `id_user`** — this is the core multi-tenancy guarantee.

### AJAX API

All actions require a logged-in user and a valid nonce. Handlers in `Num_Ajax`:

| Action | Description |
|--------|-------------|
| `num_get_coins` | Paginated list with search/year/material filters |
| `num_get_coin` | Single coin by ID |
| `num_save_coin` | Insert or update (presence of `id` determines which) |
| `num_delete_coin` | Delete with ownership verification |
| `num_get_filters` | Returns distinct years and materials for dropdowns |

### Frontend (`js/numismatist.js`)

jQuery SPA pattern: state object (`page`, `perPage`, `search`, `year`, `material`, `total`, `pages`) → AJAX fetch → render table rows + pagination. Modal form handles both create and edit. WordPress media library integrated for `photo` field.

## Security Conventions

1. Nonce verified via `wp_verify_nonce()` before any AJAX action.
2. Login checked via `is_user_logged_in()`.
3. All SQL uses `$wpdb->prepare()` with parameterized queries.
4. Input sanitized with WP functions (`sanitize_text_field`, `esc_url_raw`, `absint`, etc.) in private methods of `Num_Ajax`.
5. Output escaped with `esc_html()`, `esc_attr()`, `esc_js()` in shortcode HTML.

Do not bypass any of these layers when adding new AJAX actions.

## Adding a New Feature

**New DB column:** Add to `db-setup.php` schema AND add a new migration method in `Num_Migrations` (increment version, register in `run()`).

**New AJAX action:** Add handler in `Num_Ajax`, register with `add_action('wp_ajax_num_*')`, sanitize all inputs with private helpers (`post_str()`, `post_int()`, `post_url()`, `post_textarea()`, `post_float()`), call `authenticate()` first.

**New frontend field:** Update the modal HTML in `Num_Shortcode::render()`, the JS form-read/write logic in `numismatist.js`, and the `prepare_data()` + `get_format()` methods in `Num_CRUD`.