# EMWA Journal Sections

WordPress plugin that powers EMWA journal issues, their section taxonomy (parents + sub-types), per-issue entries, and front-end display via shortcodes/WPBakery elements. It also wires in Total Theme template/layout overrides and provides admin UX for managing entries, files, and citations.

## What this plugin does

### Registers content types and metadata
- Creates a `journal` custom post type (public, archive enabled, REST-enabled).
- Adds "Issue Info" meta box on Journal posts:
  - Volume (number)
  - Issue (number)
  - Month/Year (YYYY-MM)
  - Full Issue PDF (media attachment ID)
- Adds "Issue Sections & Entries" meta box stored in `_emwas_entries`:
  - `section` (slug)
  - `abstract_title`
  - `author_ids` (array of `journal_author` post IDs)
  - `first_page`, `last_page`
  - `doi_url`
  - `order`
  - `file_id` (PDF attachment)
  - `content` (abstract text)

### Section Types admin (hierarchical)
- Adds a top-level admin menu: "Section Types".
- Stores hierarchical section types in the `emwas_section_types_hier` option.
- Parent and child slugs/labels are sanitized on save and on read.
- These types drive the section dropdown in the entries meta box and front-end grouping.

### URL routing and templates
- Adds pretty URLs for entries/sections:
  - `/journal/{issue-slug}/{segment}/`
  - `{segment}` is either a section slug (section summary) or an entry slug (abstract title).
- Flushes rewrite rules on activation and deactivation.
- Total Theme integration:
  - Uses template ID `5517` when a second URL segment exists.
  - Uses template ID `5492` for the base issue page.
  - Forces "No Sidebar" layout for all Journal single posts.

### Front-end shortcodes (also mapped to WPBakery)

#### `journal_sections_cards`
Card grid of entries grouped by section/child section.
- Only renders on `journal` single posts.
- Hides empty sections (even if `show_empty="yes"` is set; the attribute is kept for VC UI).
- Uses the Journal post thumbnail for cards.
- Builds entry links using the abstract title slug if present; otherwise links to the section summary URL.
- Shows "Download" button only for logged-in users and when a PDF is attached.
- Attributes:
  - `columns` (1-6)
  - `sections` (comma-separated slugs)
  - `show_empty` (retained, ignored)

#### `journal_section_detail`
Shows either a single entry or a section summary.
- Only renders on `journal` single posts.
- Resolves the section/entry slug from the URL segment or the `section` attribute.
- If the slug matches an entry's abstract title, renders the entry detail (title, authors, abstract, citation, DOI).
- Otherwise renders a section summary listing entries under that section or its children.
- Shows "Download Article" if logged in and a PDF is attached; shows a login-required message if not.
- Attribute:
  - `section` (slug, optional)

#### `journal_section_flipbook`
Embeds a DFlip flipbook.
- Only renders on `journal` single posts.
- Can use either entry PDF or full issue PDF (auto-fallback).
- Attribute:
  - `section` (slug, optional)
  - `source` (`auto`, `entry`, `issue`)
  - `entry_index` (1-based index when a section has multiple entries)
  - `extra` (passed through to DFlip shortcode)

#### `journal_volume_list`
Lists all journals grouped by year with client-side filtering.
- Sorts by `_emwas_month_year` (descending).
- Filters by year, volume, issue, or title.
- Attribute:
  - `cols` (1-4)

### Membership access controls for WPBakery rows (optional)
- `includes/pmpro-vc-rows.php` adds a "PMPro Access" checkbox group to `vc_row` and `vc_row_inner`.
- If levels are selected, the row renders only for users with at least one of those levels.
- If PMPro is inactive, rows remain visible (fail-open).
- Note: this file is **not** currently required by `emwa-journal-sections.php`.

## Admin UX details
- Admin assets only load on Journal edit screens and the Section Types screen.
- Author search uses AJAX (`emwas_search_authors`) to look up `journal_author` posts.
- Live citation preview updates as you edit pages, volume/issue, and month/year.
- Issue and Entry PDFs use the WordPress media picker.

## Import / Export
- Adds a Journal > Import / Export admin screen.
- Export downloads a CSV of all journals and entries (one row per entry).
- Import is a two-step flow: upload CSV, then map headers to fields before running the import.
- Mapping auto-suggests the best match and can be saved for future uploads.
- CSV fields supported: journal_title, journal_slug, volume, issue, month_year, full_issue_pdf_url, section_slug, abstract_title, author_names, author_ids, first_page, last_page, doi_url, order, entry_pdf_url, abstract.

## Assets
- `assets/style.css` styles cards, section layouts, and the volume list filters.
- `assets/admin.css` and `assets/admin.js` power the admin UI, entry repeaters, author search, and citation preview.

## File map
- `emwa-journal-sections.php` plugin bootstrap, enqueueing, rewrite rules, theme hooks, AJAX.
- `includes/cpt.php` CPT registration + Issue Info meta box.
- `includes/section-types.php` Section Types admin page + storage.
- `includes/metabox-entries.php` entry repeater meta box + save handling.
- `includes/vc-sections-cards.php` shortcodes + VC mapping + front-end rendering.
- `includes/pmpro-vc-rows.php` optional VC row access control (PMPro integration).

## Dependencies / assumptions
- WordPress.
- WPBakery (Visual Composer) for VC elements (shortcodes still work without VC).
- Paid Memberships Pro for row access controls (optional).
- DFlip for flipbook rendering (optional).
- A `journal_author` CPT exists elsewhere (this plugin references it).
- Total Theme is assumed for template/layout hooks (remove the filters if not using Total).

