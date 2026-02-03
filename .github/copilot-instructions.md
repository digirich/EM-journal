# EMWA Journal Sections – Copilot Instructions

## Project Overview
**EMWA Journal Sections** is a WordPress plugin that manages hierarchical journal issues, per-issue entries (articles), and section taxonomy. It integrates with Total Theme template/layout system and WPBakery visual builder. The plugin handles admin UX for entries (repeatable meta boxes), section type configuration, import/export CSV workflows, and front-end rendering via shortcodes.

## Architecture

### Data Model
- **Journal** (CPT): Public, archive-enabled, has metadata for Volume, Issue, Month/Year, and Full Issue PDF.
  - Stored metadata: `_emwas_volume`, `_emwas_issue`, `_emwas_month_year`, `_emwas_full_issue_file_id`
- **Entries** (meta): Repeatable meta `_emwas_entries` array. Each entry contains:
  - `section` (slug), `abstract_title`, `author_ids` (references `journal_author` posts), `first_page`, `last_page`, `doi_url`, `order`, `file_id` (PDF), `content` (abstract)
- **Section Types** (option): Hierarchical structure stored in `emwas_section_types_hier` option. Each parent has optional children. Auto-defaults if missing.

### Key Integration Points
1. **Total Theme hooks** (`emwa-journal-sections.php` lines 92–102):
   - `wpex_singular_template_id`: Routes to template 5517 if URL has second segment (`/journal/{issue}/{segment}/`), else 5492
   - `wpex_post_layout_class`: Forces "No Sidebar" for all Journal singles
2. **WPBakery mapping** (`vc-sections-cards.php`): Shortcodes registered both as native shortcodes and VC elements
3. **URL routing** (`emwa-journal-sections.php` lines 70–80): Pretty URL rewrite rule handles `/journal/{issue-slug}/{segment-slug}/` for section summaries and entry single views
4. **Author references**: Plugin does NOT register `journal_author` CPT; assumes it exists elsewhere

### Critical Data Flows
- **Entry display**: Shortcodes (`journal_sections_cards`, `journal_section_detail`) resolve the URL segment to find matching entry by `abstract_title` slug OR section slug, then render accordingly
- **PDF fallback**: Flipbook uses entry PDF if present; falls back to full issue PDF
- **Cite generation** (`emwas_build_citation()`): Combines Volume, Issue, Year (from Month/Year), and page range into APA-style citation
- **Author linking**: Author IDs stored in entry meta are fetched from `journal_author` posts and rendered as front-end links

## File Map & Responsibilities

| File | Purpose |
|------|---------|
| `emwa-journal-sections.php` | Plugin bootstrap; enqueueing styles/scripts with version hashing; Total Theme hooks; URL rewrite rules; AJAX |
| `includes/cpt.php` | CPT registration (`journal`); Issue Info side meta box (Volume, Issue, Month/Year, Full Issue upload) |
| `includes/section-types.php` | Admin menu + page for managing hierarchical section types; storage in `emwas_section_types_hier` option; sanitization & unslashing |
| `includes/metabox-entries.php` | Repeatable entry meta box; save handling; author AJAX search (`emwas_search_authors`); live citation preview |
| `includes/vc-sections-cards.php` | Shortcode registration & rendering: `journal_sections_cards`, `journal_section_detail`, `journal_section_flipbook`, `journal_volume_list`; WPBakery VC mapping |
| `includes/import-export.php` | CSV import/export logic; two-step flow (upload → map headers → import) |
| `includes/pmpro-vc-rows.php` | **Optional**; adds PMPro access level checkbox to `vc_row` elements (fail-open if PMPro inactive) |

## Developer Workflows

### Extending Section Types Logic
- Section types are stored as a flat option array with nested children, NOT a taxonomy.
- Always call `emwas_get_section_types_hier()` to read (ensures defaults if missing; sanitizes + unslashes).
- Never directly access `get_option('emwas_section_types_hier')` without sanitization.
- Parent/child grouping is used for display grouping on cards and filtering.

### Adding New Shortcodes
1. Create shortcode handler function in `includes/vc-sections-cards.php` (check if `is_singular('journal')` first)
2. Register with `add_shortcode()`
3. Map to WPBakery via `vc_map()` if needed
4. Handle URL segment resolution with `get_query_var('emwas_seg')` and entry lookup via `emwas_find_entry_by_slug()`

### Modifying Entry Metadata
- Entries are stored as a single serialized array in `_emwas_entries` meta.
- On save, the repeater builds this array. Validate & sanitize each field in `includes/metabox-entries.php`.
- Author IDs must be integers; validate they reference valid `journal_author` posts.
- Always increment `order` when adding entries to maintain sort.

### Import/Export
- CSV fields map to entry fields (e.g., `abstract_title`, `section_slug`, `author_names`).
- Import is two-step: upload CSV, then map headers before import (allows flexibility without hard-coding column positions).
- Export generates one row per entry (not per journal).

## Conventions & Patterns

### Naming
- All plugin constants & functions prefixed with `emwas_` (EMWA Sections).
- Meta keys prefixed with `_emwas_` (postmeta) and option key is `emwas_section_types_hier`.
- Section slugs use lowercase, hyphens (via `sanitize_title()`).

### Security
- All option reads unslash + sanitize (see `emwas_get_section_types_hier()`).
- Nonces required for meta box saves and AJAX endpoints.
- Check `current_user_can('manage_options')` on admin pages.
- PDF file IDs validated as integers before use.

### Admin Assets
- CSS/JS only loaded on Journal edit screens, new Journal screens, and Section Types page (see `admin_enqueue_scripts` hook).
- Admin JS uses `wp_localize_script()` to pass section types hierarchy and AJAX URL/nonce to frontend.

### Frontend Rendering
- Shortcodes check `is_singular('journal')` to ensure they only render on issue pages.
- Download buttons shown only to logged-in users with PDF attachment.
- Empty sections hidden automatically (even if `show_empty="yes"`).

## Common Tasks

### Display a new field in the shortcode
1. Add field to entry meta box in `includes/metabox-entries.php`
2. Update entry save logic to include the field
3. Modify shortcode handler in `includes/vc-sections-cards.php` to read and display the field
4. Update import/export mapping if needed

### Add a new section type hierarchy level
- Current design supports only two levels (parent + children). To add a third level, refactor `emwas_get_section_types_hier()` and `emwas_types_maps()` to handle nested depth recursively.

### Adjust Total Theme template IDs
- Template IDs (5517, 5492) are hard-coded in `emwa-journal-sections.php` (line 96 & 99).
- Update or remove the `wpex_singular_template_id` filter if using a different theme or different template IDs.

### Troubleshoot "Author not found" AJAX
- Author search endpoint: `emwas_search_authors` in `includes/metabox-entries.php`.
- Ensure `journal_author` CPT exists and has posts with matching titles.
- Check nonce verification in AJAX handler.

## Dependencies & Assumptions
- **WordPress** (core: post types, meta, options, rewrite rules, AJAX, media library)
- **WPBakery (Visual Composer)**: For VC element mapping (shortcodes work without it)
- **Total Theme**: Template/layout hooks assumed (remove filters if not used)
- **Paid Memberships Pro** (optional): For row access controls via `includes/pmpro-vc-rows.php`
- **DFlip** (optional): For flipbook shortcode `journal_section_flipbook`
- **journal_author CPT**: Must exist elsewhere; referenced but not registered by this plugin
