<?php
/**
 * Plugin Name: EMWA Journal – Sections & Cards
 * Description: Journal CPT with Issue meta, Section Types (admin), per-issue repeatable entries, and a WPBakery “Journal Sections Cards” element. Includes Total Theme dynamic template & layout hooks.
 * Version:     1.0.7
 * Author:      Rich Barrett
 * License:     GPL-2.0+
 * Text Domain: emwa-journal-sections
 */

if (!defined('ABSPATH')) exit;

define('EMWAS_VERSION', '1.0.7');

define('EMWAS_FILE', __FILE__);
define('EMWAS_DIR',  plugin_dir_path(__FILE__));
define('EMWAS_URL',  plugin_dir_url(__FILE__));

require_once EMWAS_DIR.'includes/cpt.php';
require_once EMWAS_DIR.'includes/section-types.php';
require_once EMWAS_DIR.'includes/metabox-entries.php';
require_once EMWAS_DIR.'includes/import-export.php';
require_once EMWAS_DIR.'includes/vc-sections-cards.php';

/** Front styles */
add_action('wp_enqueue_scripts', function(){
    wp_enqueue_style('emwas-style', EMWAS_URL.'assets/style.css', [], EMWAS_VERSION);
});

/** Admin assets */
add_action('admin_enqueue_scripts', function($hook){
    $screens = ['toplevel_page_emwa-section-types','journal_page_emwa-journal-import-export','post.php','post-new.php'];
    if (!in_array($hook, $screens, true)) return;

    wp_enqueue_style('emwas-admin', EMWAS_URL.'assets/admin.css', [], EMWAS_VERSION);
    wp_enqueue_media();

    $admin_js_path = EMWAS_DIR.'assets/admin.js';
    $admin_js_ver  = EMWAS_VERSION . '-' . (file_exists($admin_js_path) ? filemtime($admin_js_path) : time());

    wp_dequeue_script('emwas-admin');
    wp_deregister_script('emwas-admin');

    wp_register_script('emwas-admin', EMWAS_URL.'assets/admin.js', ['jquery'], $admin_js_ver, true);
    wp_enqueue_script('emwas-admin');

    // Pass section types to JS
    $types = function_exists('emwas_get_section_types_hier')
        ? emwas_get_section_types_hier()
        : (function_exists('emwas_get_section_types') ? emwas_get_section_types() : []);
    wp_localize_script('emwas-admin', 'EMWAS', [
        'typesHier' => $types,
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('emwas_admin'),
    ]);
});

/** Activation/Deactivation */
register_activation_hook(__FILE__, function(){
    emwas_register_cpt();
    // ensure our rewrite tags/rules are registered before flush
    emwas_add_entry_rewrite_rules();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function(){
    flush_rewrite_rules();
});

/* -----------------------------------------------------------
 * Pretty URLs for Section Summaries & Single Entries
 * ----------------------------------------------------------- */
/**
 * Support /journal/{issue-post}/{segment}/
 * Where {segment} can be:
 *  - section slug (parent or child)  -> section summary
 *  - entry slug (abstract_title slug) -> single entry view
 */
function emwas_add_entry_rewrite_rules(){
    add_rewrite_tag('%emwas_seg%', '([^/]+)');
    // Force this to resolve as a singular journal with post_name and expose the second segment
    add_rewrite_rule(
        '^journal/([^/]+)/([^/]+)/?$',
        'index.php?post_type=journal&name=$matches[1]&emwas_seg=$matches[2]',
        'top'
    );
}
add_action('init', 'emwas_add_entry_rewrite_rules');

/* -----------------------------------------------------------
 * Total Theme integration (Dynamic Template + Layout)
 * ----------------------------------------------------------- */
/**
 * Use template 5517 when a second URL segment is present (entry or section summary),
 * otherwise fall back to your issue template (5492).
 * Adjust IDs if yours differ.
 */
add_filter( 'wpex_singular_template_id', function( $template_id ) {
	if ( is_singular( 'journal' ) ) {
        $seg = get_query_var('emwas_seg');
        if (!empty($seg)) {
            return 5517; // section/entry detail template
        }
        return 5492;    // issue landing template
	}
	return $template_id;
} );

/** Force "No Sidebar" layout for all Journal single posts. */
add_filter( 'wpex_post_layout_class', function( $layout ) {
	if ( is_singular( 'journal' ) ) {
		return 'full-width';
	}
	return $layout;
} );






/** AJAX: author search for the Entries metabox */
add_action('wp_ajax_emwas_search_authors', function(){
    check_ajax_referer('emwas_admin');
    if ( ! current_user_can('edit_posts') ) {
        wp_send_json_error(['message' => 'forbidden'], 403);
    }

    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    if (mb_strlen($q) < 2) {
        wp_send_json_success(['items' => []]);
    }

    $args = [
        'post_type'      => 'journal_author',
        'post_status'    => 'publish',
        's'              => $q,
        'posts_per_page' => 15,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ];

    $ids = get_posts($args);
    $items = [];
    foreach ($ids as $id) {
        $items[] = [
            'id'   => (int) $id,
            'text' => get_the_title($id),
        ];
    }

    wp_send_json_success(['items' => $items]);
});




