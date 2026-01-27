<?php
if (!defined('ABSPATH')) exit;

/* -----------------------------------------------------------
 * Import / Export (CSV)
 * ----------------------------------------------------------- */

const EMWAS_IMPORT_TOKEN_TTL = 3600; // 1 hour

add_action('admin_menu', function(){
    add_submenu_page(
        'edit.php?post_type=journal',
        'Journal Import / Export',
        'Import / Export',
        'manage_options',
        'emwa-journal-import-export',
        'emwas_render_import_export_page'
    );
});

add_action('admin_post_emwas_export_csv', 'emwas_handle_export_csv');
add_action('admin_post_emwas_upload_csv', 'emwas_handle_upload_csv');
add_action('admin_post_emwas_import_csv', 'emwas_handle_import_csv');

function emwas_render_import_export_page(){
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $token = isset($_GET['emwas_import_token']) ? sanitize_text_field(wp_unslash($_GET['emwas_import_token'])) : '';
    $import_data = $token ? get_transient('emwas_import_'.$token) : false;

    $result = get_transient('emwas_import_result');
    if ($result) {
        delete_transient('emwas_import_result');
        echo '<div class="notice notice-success"><p>'.esc_html($result).'</p></div>';
    }

    ?>
    <div class="wrap">
      <h1>Journal Import / Export</h1>

      <h2>Export</h2>
      <p>Download a CSV containing the current Journal data. The CSV can also be used as a template for import.</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('emwas_export_csv','emwas_export_nonce'); ?>
        <input type="hidden" name="action" value="emwas_export_csv">
        <?php submit_button('Download CSV', 'primary'); ?>
      </form>

      <hr>

      <h2>Import</h2>
      <p>Upload a CSV to import Journal entries. You will be asked to map the CSV headers before import.</p>

      <?php if ($import_data && !empty($import_data['headers'])): ?>
        <?php
          $headers = $import_data['headers'];
          $sample  = $import_data['sample'] ?? [];
          $saved_map = get_option('emwas_import_mapping', []);
          $auto_map  = emwas_guess_mapping($headers, $saved_map);
          $fields    = emwas_get_import_fields();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('emwas_import_csv','emwas_import_nonce'); ?>
          <input type="hidden" name="action" value="emwas_import_csv">
          <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

          <table class="widefat striped">
            <thead>
              <tr>
                <th style="width:30%">CSV Column</th>
                <th style="width:30%">Sample Value</th>
                <th>Map To</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($headers as $i => $h): ?>
              <?php
                $sample_val = isset($sample[$i]) ? $sample[$i] : '';
                $selected = $auto_map[$h] ?? '';
              ?>
              <tr>
                <td><strong><?php echo esc_html($h); ?></strong></td>
                <td><?php echo esc_html($sample_val); ?></td>
                <td>
                  <select name="map[<?php echo esc_attr($h); ?>]">
                    <option value="">-- Ignore --</option>
                    <?php foreach ($fields as $key => $label): ?>
                      <option value="<?php echo esc_attr($key); ?>" <?php selected($selected, $key); ?>>
                        <?php echo esc_html($label); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <p>
            <label>
              <input type="checkbox" name="save_mapping" value="1" checked>
              Save this mapping for next time
            </label>
          </p>

          <?php submit_button('Run Import', 'primary'); ?>
        </form>
      <?php else: ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
          <?php wp_nonce_field('emwas_upload_csv','emwas_upload_nonce'); ?>
          <input type="hidden" name="action" value="emwas_upload_csv">
          <input type="file" name="csv_file" accept=".csv,text/csv" required>
          <?php submit_button('Upload CSV'); ?>
        </form>
      <?php endif; ?>
    </div>
    <?php
}

function emwas_get_import_fields(){
    return [
        'journal_title'        => 'Journal Title',
        'journal_slug'         => 'Journal Slug',
        'volume'               => 'Volume',
        'issue'                => 'Issue',
        'month_year'           => 'Month/Year (YYYY-MM)',
        'full_issue_pdf_url'   => 'Full Issue PDF URL',
        'section_slug'         => 'Section Slug',
        'abstract_title'       => 'Abstract Title',
        'author_names'         => 'Author Names (semicolon separated)',
        'author_ids'           => 'Author IDs (comma/semicolon separated)',
        'first_page'           => 'First Page',
        'last_page'            => 'Last Page',
        'doi_url'              => 'DOI URL',
        'order'                => 'Order',
        'entry_pdf_url'        => 'Entry PDF URL',
        'abstract'             => 'Abstract Text',
    ];
}

function emwas_normalize_header($h){
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/', '', $h);
    return $h;
}

function emwas_guess_mapping(array $headers, array $saved_map = []){
    $aliases = [
        'journal_title'      => ['journaltitle','issuetitle','title','journal'],
        'journal_slug'       => ['journalslug','issueslug','slug'],
        'volume'             => ['volume','vol'],
        'issue'              => ['issue','iss','number'],
        'month_year'         => ['monthyear','monthyear','month','yearmonth','date'],
        'full_issue_pdf_url' => ['fullissuepdf','issuepdf','issuepdfurl','fullissuepdfurl'],
        'section_slug'       => ['section','sectionslug','sectiontype','section_slug'],
        'abstract_title'     => ['abstracttitle','articletitle','entrytitle'],
        'author_names'       => ['authornames','authors','author'],
        'author_ids'         => ['authorids','authorid'],
        'first_page'         => ['firstpage','startpage'],
        'last_page'          => ['lastpage','endpage'],
        'doi_url'            => ['doi','doiurl'],
        'order'              => ['order','sort'],
        'entry_pdf_url'      => ['entrypdf','articlepdf','entrypdfurl','articlepdfurl'],
        'abstract'           => ['abstract','summary'],
    ];

    $map = [];
    $used_fields = [];

    foreach ($headers as $h) {
        if (isset($saved_map[$h]) && $saved_map[$h] !== '') {
            $field = $saved_map[$h];
            if (!isset($used_fields[$field])) {
                $map[$h] = $field;
                $used_fields[$field] = true;
            }
            continue;
        }

        $norm = emwas_normalize_header($h);
        $best = '';
        foreach ($aliases as $field => $list) {
            if (in_array($norm, $list, true) || $norm === emwas_normalize_header($field)) {
                if (!isset($used_fields[$field])) {
                    $best = $field;
                    break;
                }
            }
        }
        if ($best) {
            $map[$h] = $best;
            $used_fields[$best] = true;
        }
    }

    return $map;
}

function emwas_handle_export_csv(){
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    if (!isset($_POST['emwas_export_nonce']) || !wp_verify_nonce($_POST['emwas_export_nonce'], 'emwas_export_csv')) {
        wp_die('Invalid nonce');
    }

    $columns = [
        'journal_title',
        'journal_slug',
        'volume',
        'issue',
        'month_year',
        'full_issue_pdf_url',
        'section_slug',
        'abstract_title',
        'author_names',
        'first_page',
        'last_page',
        'doi_url',
        'order',
        'entry_pdf_url',
        'abstract',
    ];

    $journals = get_posts([
        'post_type'      => 'journal',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=emwa-journals-export.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);

    foreach ($journals as $j) {
        $post_id = $j->ID;
        $entries = get_post_meta($post_id, '_emwas_entries', true);
        $entries = is_array($entries) ? $entries : [];

        $row_base = [
            'journal_title'      => get_the_title($post_id),
            'journal_slug'       => $j->post_name,
            'volume'             => get_post_meta($post_id, '_emwas_volume', true),
            'issue'              => get_post_meta($post_id, '_emwas_issue', true),
            'month_year'         => get_post_meta($post_id, '_emwas_month_year', true),
            'full_issue_pdf_url' => ($fid = (int)get_post_meta($post_id, '_emwas_full_issue_file_id', true)) ? wp_get_attachment_url($fid) : '',
        ];

        if (!$entries) {
            $row = $row_base + [
                'section_slug'  => '',
                'abstract_title'=> '',
                'author_names'  => '',
                'first_page'    => '',
                'last_page'     => '',
                'doi_url'       => '',
                'order'         => '',
                'entry_pdf_url' => '',
                'abstract'      => '',
            ];
            fputcsv($out, emwas_row_to_csv($columns, $row));
            continue;
        }

        foreach ($entries as $e) {
            $authors = emwas_author_names_from_ids($e['author_ids'] ?? []);
            $entry_pdf_url = '';
            if (!empty($e['file_id'])) {
                $entry_pdf_url = wp_get_attachment_url((int)$e['file_id']);
            }
            $row = $row_base + [
                'section_slug'   => $e['section'] ?? '',
                'abstract_title' => $e['abstract_title'] ?? '',
                'author_names'   => $authors,
                'first_page'     => $e['first_page'] ?? '',
                'last_page'      => $e['last_page'] ?? '',
                'doi_url'        => $e['doi_url'] ?? '',
                'order'          => $e['order'] ?? '',
                'entry_pdf_url'  => $entry_pdf_url ?: '',
                'abstract'       => $e['content'] ?? '',
            ];
            fputcsv($out, emwas_row_to_csv($columns, $row));
        }
    }

    fclose($out);
    exit;
}

function emwas_row_to_csv(array $columns, array $row){
    $out = [];
    foreach ($columns as $c) {
        $out[] = isset($row[$c]) ? $row[$c] : '';
    }
    return $out;
}

function emwas_author_names_from_ids($ids){
    if (!is_array($ids) || empty($ids)) return '';
    $names = [];
    foreach ($ids as $aid) {
        $aid = (int)$aid;
        if ($aid <= 0) continue;
        $p = get_post($aid);
        if ($p && $p->post_type === 'journal_author') {
            $names[] = get_the_title($aid);
        }
    }
    return implode('; ', $names);
}

function emwas_handle_upload_csv(){
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    if (!isset($_POST['emwas_upload_nonce']) || !wp_verify_nonce($_POST['emwas_upload_nonce'], 'emwas_upload_csv')) {
        wp_die('Invalid nonce');
    }

    if (empty($_FILES['csv_file']['tmp_name'])) {
        wp_die('No file uploaded');
    }

    $uploaded = wp_handle_upload($_FILES['csv_file'], ['test_form' => false, 'mimes' => ['csv' => 'text/csv', 'txt' => 'text/plain', 'csv2' => 'application/vnd.ms-excel']]);
    if (isset($uploaded['error'])) {
        wp_die('Upload error: '.$uploaded['error']);
    }

    $path = $uploaded['file'];
    [$headers, $sample] = emwas_read_csv_headers($path);
    if (!$headers) {
        wp_die('Invalid CSV file.');
    }

    $token = wp_generate_password(20, false);
    set_transient('emwas_import_'.$token, [
        'path'    => $path,
        'headers' => $headers,
        'sample'  => $sample,
    ], EMWAS_IMPORT_TOKEN_TTL);

    $url = add_query_arg(['post_type' => 'journal', 'page' => 'emwa-journal-import-export', 'emwas_import_token' => $token], admin_url('edit.php'));
    wp_safe_redirect($url);
    exit;
}

function emwas_read_csv_headers($path){
    $headers = [];
    $sample = [];
    if (!file_exists($path)) return [$headers, $sample];
    $fh = fopen($path, 'r');
    if (!$fh) return [$headers, $sample];

    $headers = fgetcsv($fh);
    if (!$headers) {
        fclose($fh);
        return [[], []];
    }

    // Trim UTF-8 BOM from first header if present
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    }

    $sample = fgetcsv($fh);
    fclose($fh);
    return [$headers, $sample ?: []];
}

function emwas_handle_import_csv(){
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    if (!isset($_POST['emwas_import_nonce']) || !wp_verify_nonce($_POST['emwas_import_nonce'], 'emwas_import_csv')) {
        wp_die('Invalid nonce');
    }

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    $import_data = $token ? get_transient('emwas_import_'.$token) : false;
    if (!$import_data || empty($import_data['path'])) {
        wp_die('Import session expired. Please upload the CSV again.');
    }

    $path = $import_data['path'];
    $headers = $import_data['headers'] ?? [];
    $map = isset($_POST['map']) && is_array($_POST['map']) ? array_map('sanitize_text_field', wp_unslash($_POST['map'])) : [];

    if (!empty($_POST['save_mapping'])) {
        update_option('emwas_import_mapping', $map);
    }

    $fh = fopen($path, 'r');
    if (!$fh) {
        wp_die('Could not open CSV file.');
    }

    // Read headers row
    $file_headers = fgetcsv($fh);
    if (!$file_headers) {
        fclose($fh);
        wp_die('Invalid CSV file.');
    }
    if (isset($file_headers[0])) {
        $file_headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $file_headers[0]);
    }

    $counts = [
        'rows' => 0,
        'journals_created' => 0,
        'entries_added' => 0,
        'authors_matched' => 0,
        'authors_missing' => 0,
    ];

    $journal_cache = [];

    while (($row = fgetcsv($fh)) !== false) {
        if (empty(array_filter($row, function($v){ return $v !== null && $v !== ''; }))) {
            continue;
        }
        $counts['rows']++;

        $data = [];
        foreach ($file_headers as $i => $h) {
            $data[$h] = isset($row[$i]) ? $row[$i] : '';
        }

        $vals = emwas_apply_mapping($data, $map);
        $journal_id = emwas_get_or_create_journal($vals, $journal_cache, $counts);
        if (!$journal_id) {
            continue;
        }

        emwas_update_journal_meta($journal_id, $vals);
        $entry_added = emwas_add_entry_from_row($journal_id, $vals, $counts);
        if ($entry_added) $counts['entries_added']++;
    }

    fclose($fh);
    delete_transient('emwas_import_'.$token);
    @unlink($path);

    $msg = sprintf(
        'Import complete. Rows processed: %d. Journals created: %d. Entries added: %d. Authors matched: %d. Authors missing: %d.',
        $counts['rows'],
        $counts['journals_created'],
        $counts['entries_added'],
        $counts['authors_matched'],
        $counts['authors_missing']
    );
    set_transient('emwas_import_result', $msg, EMWAS_IMPORT_TOKEN_TTL);

    $url = add_query_arg(['post_type' => 'journal', 'page' => 'emwa-journal-import-export'], admin_url('edit.php'));
    wp_safe_redirect($url);
    exit;
}

function emwas_apply_mapping(array $data, array $map){
    $out = [];
    foreach ($map as $header => $field) {
        if (!$field) continue;
        $out[$field] = isset($data[$header]) ? $data[$header] : '';
    }
    return $out;
}

function emwas_get_or_create_journal(array $vals, array &$cache, array &$counts){
    $title = isset($vals['journal_title']) ? sanitize_text_field($vals['journal_title']) : '';
    $slug  = isset($vals['journal_slug']) ? sanitize_title($vals['journal_slug']) : '';

    $key = $slug ?: $title;
    if ($key && isset($cache[$key])) return $cache[$key];

    $post_id = 0;
    if ($slug) {
        $post = get_page_by_path($slug, OBJECT, 'journal');
        if ($post) $post_id = $post->ID;
    }
    if (!$post_id && $title) {
        $post = get_page_by_title($title, OBJECT, 'journal');
        if ($post) $post_id = $post->ID;
    }

    if (!$post_id && $title) {
        $post_id = wp_insert_post([
            'post_title'  => $title,
            'post_name'   => $slug ?: '',
            'post_type'   => 'journal',
            'post_status' => 'publish',
        ]);
        if (!is_wp_error($post_id)) {
            $counts['journals_created']++;
        } else {
            return 0;
        }
    }

    if ($key && $post_id) $cache[$key] = $post_id;
    return $post_id;
}

function emwas_update_journal_meta($post_id, array $vals){
    if (isset($vals['volume']) && $vals['volume'] !== '') {
        update_post_meta($post_id, '_emwas_volume', (int)$vals['volume']);
    }
    if (isset($vals['issue']) && $vals['issue'] !== '') {
        update_post_meta($post_id, '_emwas_issue', (int)$vals['issue']);
    }
    if (isset($vals['month_year']) && $vals['month_year'] !== '') {
        $my = preg_replace('/^(\d{4})-(\d{2}).*$/','$1-$2', $vals['month_year']);
        update_post_meta($post_id, '_emwas_month_year', $my);
    }
    if (isset($vals['full_issue_pdf_url']) && $vals['full_issue_pdf_url'] !== '') {
        $fid = attachment_url_to_postid(esc_url_raw($vals['full_issue_pdf_url']));
        if ($fid) {
            update_post_meta($post_id, '_emwas_full_issue_file_id', (int)$fid);
        }
    }
}

function emwas_add_entry_from_row($post_id, array $vals, array &$counts){
    $section = isset($vals['section_slug']) ? sanitize_title($vals['section_slug']) : '';
    if (!$section) return false;

    $entries = get_post_meta($post_id, '_emwas_entries', true);
    $entries = is_array($entries) ? $entries : [];

    $max_order = 0;
    foreach ($entries as $e) {
        $o = isset($e['order']) ? (int)$e['order'] : 0;
        if ($o > $max_order) $max_order = $o;
    }

    $author_ids = [];
    if (!empty($vals['author_ids'])) {
        $ids = preg_split('/[;,|]+/', $vals['author_ids']);
        foreach ($ids as $id) {
            $id = (int)trim($id);
            if ($id > 0) {
                $p = get_post($id);
                if ($p && $p->post_type === 'journal_author') {
                    $author_ids[] = $id;
                    $counts['authors_matched']++;
                }
            }
        }
    } elseif (!empty($vals['author_names'])) {
        $names = preg_split('/[;|]+/', $vals['author_names']);
        if (count($names) === 1) {
            $names = preg_split('/\s*,\s*/', $vals['author_names']);
        }
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') continue;
            $p = get_page_by_title($name, OBJECT, 'journal_author');
            if ($p) {
                $author_ids[] = $p->ID;
                $counts['authors_matched']++;
            } else {
                $counts['authors_missing']++;
            }
        }
    }

    $entry = [
        'section'        => $section,
        'abstract_title' => isset($vals['abstract_title']) ? sanitize_text_field($vals['abstract_title']) : '',
        'doi_url'        => isset($vals['doi_url']) ? esc_url_raw($vals['doi_url']) : '',
        'author_ids'     => $author_ids,
        'file_id'        => 0,
        'order'          => isset($vals['order']) && $vals['order'] !== '' ? (int)$vals['order'] : ($max_order + 1),
        'first_page'     => isset($vals['first_page']) ? sanitize_text_field($vals['first_page']) : '',
        'last_page'      => isset($vals['last_page']) ? sanitize_text_field($vals['last_page']) : '',
        'content'        => isset($vals['abstract']) ? sanitize_textarea_field($vals['abstract']) : '',
    ];

    if (!empty($vals['entry_pdf_url'])) {
        $fid = attachment_url_to_postid(esc_url_raw($vals['entry_pdf_url']));
        if ($fid) $entry['file_id'] = (int)$fid;
    }

    $entries[] = $entry;
    update_post_meta($post_id, '_emwas_entries', $entries);
    return true;
}


