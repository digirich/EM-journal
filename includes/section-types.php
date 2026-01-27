<?php
if (!defined('ABSPATH')) exit;

/**
 * Section Types (hierarchical) stored in option 'emwas_section_types_hier'
 * Each parent: ['slug'=>'feature-articles','label'=>'Feature Articles','children'=>[ ['slug'=>'incoming-presidents-message','label'=>"Incoming President's Message"], ... ]]
 */
add_action('admin_menu', function(){
    add_menu_page(
        'Section Types',
        'Section Types',
        'manage_options',
        'emwa-section-types',
        'emwas_render_section_types_page',
        'dashicons-index-card',
        58
    );
});

/** Defaults */
function emwas_default_section_types_hier(){
    return [
        ['slug'=>'editorial','label'=>'Editorial','children'=>[]],
        ['slug'=>'from-the-editor','label'=>'From the Editor','children'=>[]],
        [
            'slug'  => 'presidents-message',
            'label' => "President's Message",
            'children' => [
                ['slug'=>'incoming-presidents-message','label'=>"Incoming President’s Message"],
                ['slug'=>'outgoing-presidents-message','label'=>"Outgoing President’s Message"],
            ]
        ],
        ['slug'=>'emwa-news','label'=>'EMWA News','children'=>[]],
        ['slug'=>'suggested-reading','label'=>'Suggested Reading','children'=>[]],
        ['slug'=>'feature-articles','label'=>'Feature Articles','children'=>[]],
        ['slug'=>'regular-sections','label'=>'Regular Sections','children'=>[]],
    ];
}

/**
 * Get hierarchical types
 * - UNSLASH values read from DB (handles any previously slashed data)
 * - Then sanitize
 */
function emwas_get_section_types_hier(){
    $opt = get_option('emwas_section_types_hier');
    if (!is_array($opt) || empty($opt)) {
        $opt = emwas_default_section_types_hier();
    }

    $seen = [];
    $out  = [];

    foreach ($opt as $row){
        // Unslash first, then sanitize
        $raw_slug  = isset($row['slug'])  ? wp_unslash($row['slug'])  : '';
        $raw_label = isset($row['label']) ? wp_unslash($row['label']) : '';

        $slug  = sanitize_title($raw_slug);
        $label = wp_strip_all_tags($raw_label);

        if (!$slug || !$label || isset($seen[$slug])) continue;
        $seen[$slug]=1;

        // Children
        $kids = [];
        if (!empty($row['children']) && is_array($row['children'])){
            $kidSeen = [];
            foreach ($row['children'] as $ch){
                $c_raw_slug  = isset($ch['slug'])  ? wp_unslash($ch['slug'])  : '';
                $c_raw_label = isset($ch['label']) ? wp_unslash($ch['label']) : '';

                $cslug = sanitize_title($c_raw_slug);
                $clab  = wp_strip_all_tags($c_raw_label);

                if ($cslug && $clab && empty($kidSeen[$cslug])){
                    $kidSeen[$cslug]=1;
                    $kids[] = ['slug'=>$cslug,'label'=>$clab];
                }
            }
        }

        $out[] = ['slug'=>$slug,'label'=>$label,'children'=>$kids];
    }

    return $out;
}

/** Back-compat: flat parents only (legacy callers) */
function emwas_get_section_types(){
    $hier = emwas_get_section_types_hier();
    $flat = [];
    foreach ($hier as $p) {
        $flat[] = ['slug'=>$p['slug'],'label'=>$p['label']];
        // (intentionally not returning children in flat version)
    }
    return $flat;
}

function emwas_save_section_types_hier($rows){
    update_option('emwas_section_types_hier', $rows);
}

/**
 * Admin page renderer
 * - UNSLASH incoming POST before sanitizing/saving
 * - Outputs sanitized values (parents + children)
 */
function emwas_render_section_types_page(){
    if (
        isset($_POST['emwas_types_nonce'])
        && wp_verify_nonce($_POST['emwas_types_nonce'],'emwas_save_types')
        && current_user_can('manage_options')
    ) {
        $rows = [];
        if (!empty($_POST['types']) && is_array($_POST['types'])){
            // UNSLASH the entire posted structure FIRST
            $posted = wp_unslash($_POST['types']);

            foreach ($posted as $i => $r){
                $raw_slug  = isset($r['slug'])  ? $r['slug']  : '';
                $raw_label = isset($r['label']) ? $r['label'] : '';

                $slug  = sanitize_title($raw_slug);
                $label = wp_strip_all_tags($raw_label);
                if (!$slug || !$label) continue;

                // Children
                $children = [];
                if (!empty($r['children']) && is_array($r['children'])){
                    $kidSeen = [];
                    foreach ($r['children'] as $ch){
                        $c_raw_slug  = isset($ch['slug'])  ? $ch['slug']  : '';
                        $c_raw_label = isset($ch['label']) ? $ch['label'] : '';

                        $cslug = sanitize_title($c_raw_slug);
                        $clab  = wp_strip_all_tags($c_raw_label);

                        if ($cslug && $clab && empty($kidSeen[$cslug])){
                            $kidSeen[$cslug]=1;
                            $children[] = ['slug'=>$cslug,'label'=>$clab];
                        }
                    }
                }

                $rows[] = compact('slug','label','children');
            }
        }
        emwas_save_section_types_hier($rows);
        echo '<div class="updated"><p>Saved.</p></div>';
    }

    $types = emwas_get_section_types_hier();
    ?>
    <div class="wrap">
      <h1>Section Types (Parents &amp; Sub-types)</h1>
      <form method="post">
        <?php wp_nonce_field('emwas_save_types','emwas_types_nonce'); ?>

        <table class="widefat striped" id="emwas-types-table">
            <thead>
              <tr>
                <th style="width:22%">Parent Slug</th>
                <th style="width:28%">Parent Label</th>
                <th>Sub-types (Slug &amp; Label)</th>
                <th style="width:90px">Remove</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($types as $i=>$t): ?>
              <tr class="emwas-parent-row">
                <td><input type="text" name="types[<?php echo (int)$i; ?>][slug]" value="<?php echo esc_attr($t['slug']); ?>" class="regular-text"></td>
                <td><input type="text" name="types[<?php echo (int)$i; ?>][label]" value="<?php echo esc_attr($t['label']); ?>" class="regular-text"></td>
                <td>
                  <table class="widefat emwas-children">
                    <thead><tr><th style="width:30%">Slug</th><th>Label</th><th style="width:80px">Remove</th></tr></thead>
                    <tbody>
                      <?php
                      $kids = !empty($t['children']) && is_array($t['children']) ? $t['children'] : [];
                      foreach ($kids as $k=>$ch): ?>
                        <tr>
                          <td><input type="text" name="types[<?php echo (int)$i; ?>][children][<?php echo (int)$k; ?>][slug]" value="<?php echo esc_attr($ch['slug']); ?>" class="regular-text"></td>
                          <td><input type="text" name="types[<?php echo (int)$i; ?>][children][<?php echo (int)$k; ?>][label]" value="<?php echo esc_attr($ch['label']); ?>" class="regular-text"></td>
                          <td><button type="button" class="button emwas-remove-row">Remove</button></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <p><button type="button" class="button emwas-add-child" data-parent="<?php echo (int)$i; ?>">Add Sub-type</button></p>
                </td>
                <td><button type="button" class="button emwas-remove-row">Remove</button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p><button type="button" class="button" id="emwas-add-type">Add Parent Type</button></p>
        <?php submit_button('Save Section Types'); ?>
      </form>
    </div>
    <?php
}
