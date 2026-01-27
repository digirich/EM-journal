<?php
if (!defined('ABSPATH')) exit;

/**
 * Issue Sections & Entries (repeater) - meta key: _emwas_entries
 * Entry shape:
 *  section (slug), abstract_title (string), doi_url (url),
 *  author_ids (int[]), file_id (int), order (int),
 *  first_page (int|string), last_page (int|string),
 *  content (abstract, plain text)
 */

add_action('add_meta_boxes', function(){
    add_meta_box('emwas_entries','Issue Sections & Entries','emwas_entries_box','journal','normal','high');
});

/** Options for section select (parents + children) */
function emwas_section_select_options(){
    $types = function_exists('emwas_get_section_types_hier') ? emwas_get_section_types_hier() : [];
    $html = '';
    foreach ($types as $p){
        $pslug = esc_attr($p['slug']); $plab = esc_html($p['label']);
        $kids  = !empty($p['children']) ? $p['children'] : [];
        if ($kids){
            $html .= '<optgroup label="'.$plab.'">';
            $html .= '<option value="'.$pslug.'">'.$plab.' (All)</option>';
            foreach ($kids as $ch){
                $html .= '<option value="'.esc_attr($ch['slug']).'">'.esc_html($ch['label']).'</option>';
            }
            $html .= '</optgroup>';
        } else {
            $html .= '<option value="'.$pslug.'">'.$plab.'</option>';
        }
    }
    return $html;
}

/** Helper: citation builder (server-side, also mirrored in admin.js) */
function emwas_build_citation_preview( $post_id, $first, $last ){
    $vol = (int) get_post_meta($post_id,'_emwas_volume', true);
    $iss = (int) get_post_meta($post_id,'_emwas_issue', true);
    $my  = get_post_meta($post_id,'_emwas_month_year', true);
    $yr  = $my && preg_match('/^(\d{4})/', $my, $m) ? (int)$m[1] : (int) get_the_date('Y', $post_id);

    $first = is_numeric($first) ? (int)$first : trim((string)$first);
    $last  = is_numeric($last)  ? (int)$last  : trim((string)$last);

    $pages = '';
    if ($first !== '' && $first !== null) {
        if ($last === '' || $last === null || (string)$last === (string)$first) {
            $pages = $first;
        } else {
            $pages = $first . '-' . $last; // en dash
        }
    }
    if (!$yr || !$vol || !$iss || !$pages) return '';
    return sprintf('Medical Writing. %d;%d(%d):%s', $yr, $vol, $iss, $pages);
}

function emwas_entries_box($post){
    wp_nonce_field('emwas_entries_save','emwas_entries_nonce');

    $entries = get_post_meta($post->ID, '_emwas_entries', true);
    $entries = is_array($entries) ? $entries : [];

    $select_options = emwas_section_select_options();
    ?>
    <p>Add entries under a Section (parent or sub-type). Each entry supports an <strong>Abstract Title</strong>, authors, an optional file, abstract text, pages (first/last), DOI URL, and order.</p>

    <table class="widefat striped emwas-entries" data-next-index="<?php echo count($entries); ?>">
      <thead>
        <tr>
          <th style="width:14%">Section</th>
          <th style="width:18%">Abstract Title</th>
          <th style="width:18%">Authors</th>
          <th style="width:10%">First&nbsp;page</th>
          <th style="width:10%">Last&nbsp;page</th>
          <th style="width:14%">DOI URL</th>
          <th style="width:6%">Order</th>
          <th style="width:10%">Remove</th>
        </tr>
      </thead>
      <tbody id="emwas-entries-body">
      <?php foreach ($entries as $i => $e):
          $sec        = sanitize_title($e['section'] ?? '');
          $abstract   = $e['abstract_title'] ?? '';
          $author_ids = isset($e['author_ids']) && is_array($e['author_ids']) ? array_map('intval', $e['author_ids']) : [];
          $fid        = (int)($e['file_id'] ?? 0);
          $furl       = $fid ? wp_get_attachment_url($fid) : '';
          $ord        = isset($e['order']) ? (int)$e['order'] : ($i + 1);
          $doi_url    = $e['doi_url'] ?? '';
          $first      = $e['first_page'] ?? '';
          $last       = $e['last_page']  ?? '';
          $content    = $e['content'] ?? '';

          $citation   = emwas_build_citation_preview($post->ID, $first, $last);
      ?>
        <tr class="emwas-entry" data-post="<?php echo (int)$post->ID; ?>">
          <!-- Section -->
          <td>
            <select name="emwas_entries[<?php echo (int)$i; ?>][section]" class="widefat">
              <?php echo preg_replace('/(<option\s+value="'.preg_quote($sec,'/').'")/','$1 selected', $select_options, 1); ?>
            </select>
            <div class="emwas-citation small text-muted" data-citation>
              <?php echo $citation ? esc_html($citation) : ''; ?>
            </div>
          </td>

          <!-- Abstract Title -->
          <td>
            <input type="text" name="emwas_entries[<?php echo (int)$i; ?>][abstract_title]"
                   value="<?php echo esc_attr($abstract); ?>"
                   class="widefat" placeholder="Enter abstract title">
          </td>

          <!-- Authors -->
          <td>
            <div class="emwas-author-select" data-cpt="journal_author">
              <input type="text" class="widefat emwas-author-search" placeholder="Search authors… (type 2+ chars)">
              <div class="emwas-author-suggest"></div>
              <div class="emwas-author-tags">
                <?php
                foreach ($author_ids as $aid) {
                    $p = get_post($aid);
                    if ($p && $p->post_type === 'journal_author') {
                        $name = get_the_title($aid);
                        echo '<span class="emwas-tag" data-id="'.(int)$aid.'">'.esc_html($name).'<button type="button" class="emwas-tag-x" aria-label="Remove">×</button></span>';
                        echo '<input type="hidden" name="emwas_entries['.(int)$i.'][author_ids][]" value="'.(int)$aid.'">';
                    }
                }
                ?>
              </div>
            </div>
            <small>Authors are looked up from <strong>journal_author</strong>.</small>
          </td>

          <!-- First page -->
          <td>
            <input type="text" name="emwas_entries[<?php echo (int)$i; ?>][first_page]"
                   value="<?php echo esc_attr($first); ?>" class="widefat emwas-first-page" placeholder="e.g. 2">
          </td>

          <!-- Last page -->
          <td>
            <input type="text" name="emwas_entries[<?php echo (int)$i; ?>][last_page]"
                   value="<?php echo esc_attr($last); ?>" class="widefat emwas-last-page" placeholder="e.g. 4">
          </td>

          <!-- DOI URL -->
          <td>
            <input type="url" name="emwas_entries[<?php echo (int)$i; ?>][doi_url]"
                   value="<?php echo esc_url($doi_url); ?>" class="widefat" placeholder="https://doi.org/...">
          </td>

          <!-- Order -->
          <td><input type="number" name="emwas_entries[<?php echo (int)$i; ?>][order]" value="<?php echo (int)$ord; ?>" class="small-text"></td>

          <!-- Remove -->
          <td>
            <button class="button emwas-remove-row" type="button">Remove</button>
            <div style="margin-top:8px">
              <input type="hidden" name="emwas_entries[<?php echo (int)$i; ?>][file_id]" value="<?php echo (int)$fid; ?>" class="emwas-file-id">
              <input type="text" value="<?php echo esc_url($furl); ?>" class="widefat emwas-file-url" readonly placeholder="Selected file URL">
              <button type="button" class="button emwas-entry-upload">Upload/Select PDF</button>
              <button type="button" class="button emwas-entry-clear">Clear</button>
            </div>
          </td>
        </tr>

        <tr>
          <td colspan="8">
            <label><strong>Abstract</strong></label>
            <textarea name="emwas_entries[<?php echo (int)$i; ?>][content]" rows="6" class="widefat"><?php echo esc_textarea( wp_strip_all_tags( $content ) ); ?></textarea>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <p><button type="button" class="button button-primary" id="emwas-add-entry-v2">Add Entry</button></p>
    <?php
}

/** Save handler */
add_action('save_post_journal', function($post_id){
    if (!isset($_POST['emwas_entries_nonce']) || !wp_verify_nonce($_POST['emwas_entries_nonce'], 'emwas_entries_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $clean = [];

    if (!empty($_POST['emwas_entries']) && is_array($_POST['emwas_entries'])) {
        foreach ($_POST['emwas_entries'] as $row) {
            $sec       = sanitize_title($row['section'] ?? '');
            $abstract  = sanitize_text_field($row['abstract_title'] ?? '');
            $doi_url   = esc_url_raw($row['doi_url'] ?? '');
            $fid       = isset($row['file_id']) ? (int)$row['file_id'] : 0;
            $ord       = isset($row['order']) ? (int)$row['order'] : 0;
            $first     = sanitize_text_field($row['first_page'] ?? '');
            $last      = sanitize_text_field($row['last_page']  ?? '');
            $content   = sanitize_textarea_field($row['content'] ?? '');

            // Authors
            $author_ids = [];
            if (isset($row['author_ids']) && is_array($row['author_ids'])) {
                $seen = [];
                foreach ($row['author_ids'] as $aid) {
                    $aid = (int)$aid;
                    if ($aid > 0 && empty($seen[$aid])) {
                        $p = get_post($aid);
                        if ($p && $p->post_type === 'journal_author') {
                            $author_ids[] = $aid;
                            $seen[$aid] = true;
                        }
                    }
                }
            }

            if ($sec && ($abstract || $doi_url || $fid || $content || $first !== '' || $last !== '' || !empty($author_ids))) {
                $clean[] = [
                    'section'        => $sec,
                    'abstract_title' => $abstract,
                    'doi_url'        => $doi_url,
                    'author_ids'     => $author_ids,
                    'file_id'        => $fid,
                    'order'          => $ord,
                    'first_page'     => $first,
                    'last_page'      => $last,
                    'content'        => $content,
                ];
            }
        }
    }

    update_post_meta($post_id, '_emwas_entries', $clean);
});
