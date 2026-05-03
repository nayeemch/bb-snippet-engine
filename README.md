# BB Snippet Engine (BBSE)

A modular snippet engine for BuddyBoss that lets you add, manage, and activate custom CSS, JavaScript, and PHP code blocks — without touching theme files or core code.

---

## Overview

BBSE gives you a card-based admin UI to store and run code snippets scoped to your BuddyBoss site. Each snippet lives in its own block that can be switched on or off independently, validated before activation, and synced from a remote GitHub Gist Knowledgebase.

It ships with 8 ready-to-use BuddyBoss customization snippets, and 530+ additional blocks can be imported instantly from the remote Knowledgebase with one click.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8+ |
| PHP | 7.4+ |
| BuddyBoss Platform | Any active version |

BuddyBoss Platform must be installed and active. BBSE will refuse to activate and show an admin notice if it is missing.

---

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```
   wp-content/plugins/bb-snippet-engine/
   ```
2. In the WordPress admin go to **Plugins → Installed Plugins** and activate **BBSE - BuddyBoss Snippet Engine**.
3. The plugin creates its database table on first activation and loads 8 sample blocks.
4. Navigate to **BuddyBoss → BBSE** (or **BBSE** in the top-level menu if BuddyBoss is not loaded) to start managing blocks.

---

## Features

### Block Management
- **Create** blocks via an inline modal — name your block and paste in code before it is ever saved to the database.
- **Edit** blocks with syntax-highlighted CodeMirror editors for CSS, JavaScript, and PHP, each on its own tab.
- **Delete** individual blocks or wipe everything with the **Delete All** button (useful during testing).
- **Toggle** blocks on or off with a single switch — inactive blocks are never injected into the page.

### Code Injection
Active blocks are injected automatically on every front-end page load:

| Code type | Where it is output |
|---|---|
| CSS | `<style>` tag in `<head>` via `wp_head` |
| JavaScript | `<script>` tag in footer via `wp_footer` |
| PHP | Executed server-side via `init` hook |

### PHP Safety Validation
Before a PHP block is saved or activated, BBSE runs a syntax check using PHP's `token_get_all()` with the `TOKEN_PARSE` flag. This catches parse errors without executing the code, so a broken snippet can never cause a white screen.

### Smart Code Normalization
Code pasted from web pages often contains invisible Unicode characters that break PHP and JavaScript. BBSE automatically strips these on save:
- Non-breaking spaces (` `) used as indentation
- Curly / smart quotes (`'` `'` `"` `"`) replacing straight quotes
- Prime (`′`), en dash (`–`), em dash (`—`), and angle quotation marks (`‹` `›`)

### Admin UI
- **Server-side search** — debounced (350 ms) AJAX search — only the matching page of results is fetched.
- **Server-side pagination** — the admin page renders 12 blocks at a time; navigating pages or changing the sort fires a lightweight AJAX request instead of reloading the page.
- **Sort** — Active first (default), Inactive first, Name A–Z, Name Z–A, By type.
- **Stats pill** — shows `X / Y blocks active` in the header; the active counter updates live via delta on toggle.
- **Pagination** — smart ellipsis navigation and a "Showing X–Y of Z" counter.
- **New block highlight** — after creating a block it is highlighted with an orange **NEW** badge and a pulsing glow animation for 5 seconds.
- **Glassmorphic card design** — frosted-glass cards and header controls styled with the BuddyBoss brand orange.

### Remote Sync (Knowledgebase)
Click **Sync from Knowledgebase** to pull a JSON block library from the configured GitHub Gist. BBSE will:
- Create blocks that do not exist locally (`remote_id` matching).
- Update existing remote blocks when the version changes.
- Deactivate remote blocks that were removed from the Gist.
- Leave locally-created blocks untouched.

The Gist URL is set in `bbse.php` via the `BBSE_GIST_URL` constant.

#### Gist JSON format
```json
{
  "version": "2024-01-01",
  "blocks": [
    {
      "remote_id": "unique-slug",
      "version": "1.0.0",
      "name": "My Snippet",
      "default_active": false,
      "css_code": "/* ... */",
      "js_code": "// ...",
      "php_code": "// ..."
    }
  ]
}
```

---

## Sample Blocks

The plugin ships with 8 inactive sample blocks that cover common BuddyBoss customizations:

1. **BuddyBoss Full Width Layout** — wide-screen CSS for stretching the container at 1201 px+.
2. **Activity Image Filter** — glass blur effect behind activity feed images.
3. **Back to Top Button** — floating scroll-to-top button injected via JavaScript.
4. **Center Login Logo** — centers the logo on the BuddyBoss split login page.
5. **Remove WordPress from Site Title** — clean login page title using a PHP filter.
6. **Auto-approve Pending Registrations** — bypasses activation key and approves signups automatically.
7. **Disable Enter Key in Private Messages** — prevents accidental message sends on Enter.
8. **Change "Sign In" Button Text** — renames the header sign-in button via JavaScript.

All sample blocks are inactive by default.

530+ additional blocks are available for import from the remote Knowledgebase via the **Sync from Knowledgebase** button.

---

## Performance

BBSE is optimised to stay fast even with hundreds of imported blocks:

| Area | Technique |
|---|---|
| Admin page load | Lightweight listing query — only metadata columns fetched, never longtext code content |
| Admin pagination/search | Server-side AJAX — browser renders 12 cards at a time regardless of total block count |
| Frontend injection | Active blocks cached in a 5-minute WordPress transient — reduces 3 DB queries per page load to 1 |
| DB query speed | `is_active` column indexed — `WHERE is_active = 1` no longer does a full table scan |
| Toggle AJAX | Pre-fetched block passed to update query — eliminates redundant SELECT after UPDATE |
| Cache invalidation | Transient is deleted automatically on any write (toggle, save, delete, sync) |

---

## File Structure

```
bb-snippet-engine/
├── bbse.php                          # Plugin entry point, constants, bootstrap (v1.1.0)
├── admin/
│   └── class-bbse-admin.php          # Admin page rendering, asset enqueue
├── assets/
│   ├── css/admin.css                 # Admin UI styles
│   └── js/admin.js                   # Admin UI interactions (jQuery)
├── docs/
│   └── index.html                    # Public landing page (live Knowledgebase browser)
└── includes/
    ├── class-bbse-ajax.php           # AJAX handlers (create, save, delete, toggle, sync, list)
    ├── class-bbse-database.php       # Database abstraction (wpdb wrapper)
    ├── class-bbse-injector.php       # Front-end CSS / JS / PHP injection with transient cache
    └── class-bbse-remote-sync.php    # GitHub Gist sync service
```

---

## Database

BBSE creates a single table: `{prefix}bbse_blocks`

| Column | Type | Description |
|---|---|---|
| `id` | bigint | Auto-increment primary key |
| `name` | varchar(255) | Block display name |
| `css_code` | longtext | CSS snippet |
| `js_code` | longtext | JavaScript snippet |
| `php_code` | longtext | PHP snippet |
| `is_active` | tinyint(1) | 1 = injected on front end |
| `sort_order` | int | Reserved for manual ordering |
| `source_type` | varchar(20) | `local` or `remote` |
| `remote_id` | varchar(191) | Unique ID for remote-synced blocks |
| `remote_version` | varchar(100) | Version string from the remote source |
| `is_locked` | tinyint(1) | Reserved for future use |
| `last_synced_at` | datetime | Timestamp of last remote sync for this block |
| `created_at` | datetime | Auto-set on insert |
| `updated_at` | datetime | Auto-updated on change |

**Indexes:** `PRIMARY KEY (id)`, `KEY remote_id_idx (remote_id)`, `KEY source_type_idx (source_type)`, `KEY is_active_idx (is_active)` *(added in v1.1.0)*.

---

## Development Notes

- The `BBSE_GIST_URL` constant in `bbse.php` controls which Gist is used for remote sync. Change it to point to your own Gist.
- The **Delete All** button in the toolbar is intended for development use only.
- PHP code is validated with `token_get_all( '<?php ' . $code, TOKEN_PARSE )` — syntax-only, no execution.
- The active blocks transient key is `bbse_active_blocks` (TTL 5 minutes). Clear it with `delete_transient('bbse_active_blocks')` or via any block write operation.

---

## License

GPL-2.0-or-later. See [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
