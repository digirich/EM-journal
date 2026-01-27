<?php
if (!defined('ABSPATH')) exit;

/**
 * PMPro + WPBakery (VC) — Row access controls
 *
 * Adds a checkbox group of PMPro membership levels to:
 *  - vc_row
 *  - vc_row_inner
 *
 * If any levels are selected, the row will only render for users who have
 * at least one of those levels. If none selected, the row is visible to everyone.
 *
 * Works even if PMPro is deactivated (rows remain visible).
 */

/** Get PMPro levels for VC param */
function emwas_pmpro_levels_for_vc() : array {
    $out = [];
    if ( function_exists('pmpro_getAllLevels') ) {
        // Include hidden/inactive=false → pass no flags or use defaults
        $levels = pmpro_getAllLevels( true, true ); // (include_hidden = true, include_inactive = true) safer for migrations
        if ( !empty($levels) && is_array($levels) ) {
            foreach ($levels as $lvl) {
                // Label shown in VC, value saved is the level ID
                $name = isset($lvl->name) ? $lvl->name : ('Level '.$lvl->id);
                $out[ sprintf('%s (ID: %d)', $name, (int)$lvl->id) ] = (string)(int)$lvl->id;
            }
        }
    }
    return $out;
}

/** Register VC params on rows */
add_action('init', function () {
    if ( ! function_exists('vc_add_param') ) return;

    $levels = emwas_pmpro_levels_for_vc();

    $param = [
        'type'        => 'checkbox',
        'heading'     => __('PMPro Access (levels allowed to view this row)', 'emwa-journal-sections'),
        'param_name'  => 'pmpro_levels',
        'value'       => $levels, // array "Label" => "value"
        'description' => __('Select one or more membership levels that can see this row. Leave empty to show to everyone.', 'emwa-journal-sections'),
        'group'       => __('Access', 'emwa-journal-sections'),
    ];

    // Attach to outer and inner rows
    vc_add_param('vc_row', $param);
    vc_add_param('vc_row_inner', $param);
});

/**
 * Gate the output of vc_row / vc_row_inner by membership level selections.
 * Uses do_shortcode_tag filter to intercept the rendered output.
 */
add_filter('do_shortcode_tag', function ($output, $tag, $attr, $m) {

    // Only affect WPBakery rows
    if ( $tag !== 'vc_row' && $tag !== 'vc_row_inner' ) {
        return $output;
    }

    // Normalize pmpro_levels from VC (could be array or comma-separated string)
    $selected = [];
    if ( isset($attr['pmpro_levels']) && $attr['pmpro_levels'] !== '' ) {
        if ( is_array($attr['pmpro_levels']) ) {
            $selected = $attr['pmpro_levels'];
        } else {
            $selected = array_map('trim', explode(',', (string)$attr['pmpro_levels']));
        }
        $selected = array_filter(array_unique(array_map('intval', $selected)));
    }

    // If no levels selected, row is public
    if ( empty($selected) ) {
        return $output;
    }

    // If PMPro not active, don't hide rows (fail open)
    if ( ! function_exists('pmpro_hasMembershipLevel') ) {
        return $output;
    }

    // Hide for users without any of the selected levels
    if ( ! pmpro_hasMembershipLevel( $selected ) ) {
        return ''; // no output for this row
    }

    return $output;

}, 10, 4);
