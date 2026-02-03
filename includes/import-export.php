<?php
if (!defined('ABSPATH')) exit;

/* -----------------------------------------------------------
 * Import / Export (CSV)
 * ----------------------------------------------------------- */

const EMWAS_IMPORT_TOKEN_TTL = 3600; // 1 hour
const EMWAS_IMPORT_PREVIEW_ROWS = 20;
const EMWAS_IMPORT_LOG = true;
const EMWAS_IMPORT_LOG_STORE = true;
const EMWAS_IMPORT_LOG_MAX = 500;
const EMWAS_IMPORT_BATCH = 20;
const EMWAS_IMPORT_PDF_COVER = true;
const EMWAS_IMPORT_PDF_COVER_FORCE = false;
const EMWAS_IMPORT_PDF_COVER_DPI = 200;

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
add_action('admin_post_emwas_clear_import_log', 'emwas_handle_clear_import_log');
add_action('wp_ajax_emwas_import_init', 'emwas_ajax_import_init');
add_action('wp_ajax_emwas_import_step', 'emwas_ajax_import_step');
add_action('wp_ajax_emwas_import_cancel', 'emwas_ajax_import_cancel');

function emwas_render_import_export_page(){
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $token = isset($_GET['emwas_import_token']) ? sanitize_text_field(wp_unslash($_GET['emwas_import_token'])) : '';
    $import_data = $token ? get_transient('emwas_import_'.$token) : false;
    $preview = $token ? get_transient('emwas_import_preview_'.$token) : false;
    $preview_map = $token ? get_transient('emwas_import_preview_map_'.$token) : false;

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
          $auto_map  = ($preview_map !== false) ? $preview_map : emwas_guess_mapping($headers, $saved_map);
          $fields    = emwas_get_import_fields();
        ?>
        <form id="emwas-import-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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

          <?php if ($preview !== false): ?>
            <?php
              $preview_rows = (is_array($preview) && isset($preview['rows'])) ? $preview['rows'] : [];
              $preview_limit = (is_array($preview) && isset($preview['limit'])) ? (int)$preview['limit'] : EMWAS_IMPORT_PREVIEW_ROWS;
              $mapped_fields = [];
              foreach ($fields as $key => $label) {
                  if (in_array($key, $auto_map, true)) {
                      $mapped_fields[$key] = $label;
                  }
              }
            ?>
            <h3>Preview</h3>
            <p>Showing first <?php echo esc_html($preview_limit); ?> row(s) after mapping. Nothing has been imported yet.</p>

            <?php if (empty($mapped_fields)): ?>
              <p><em>No mapped fields selected.</em></p>
            <?php elseif (empty($preview_rows)): ?>
              <p><em>No preview rows found.</em></p>
            <?php else: ?>
              <table class="widefat striped">
                <thead>
                  <tr>
                    <?php foreach ($mapped_fields as $key => $label): ?>
                      <th><?php echo esc_html($label); ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($preview_rows as $prow): ?>
                    <tr>
                      <?php foreach ($mapped_fields as $key => $label): ?>
                        <?php $cell = emwas_preview_value($prow[$key] ?? ''); ?>
                        <td><?php echo esc_html($cell); ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          <?php endif; ?>

          <div id="emwas-import-progress" class="emwas-import-progress" style="display:none">
            <div class="emwas-progress-bar"><span style="width:0%"></span></div>
            <div class="emwas-import-status">Preparing import…</div>
            <div class="emwas-import-log" aria-live="polite"></div>
          </div>

          <p>
            <?php submit_button('Preview', 'secondary', 'emwas_preview', false); ?>
            <?php submit_button('Run Import', 'primary', 'emwas_run_import', false); ?>
          </p>
        </form>
      <?php else: ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
          <?php wp_nonce_field('emwas_upload_csv','emwas_upload_nonce'); ?>
          <input type="hidden" name="action" value="emwas_upload_csv">
          <input type="file" name="csv_file" accept=".csv,text/csv" required>
          <?php submit_button('Upload CSV'); ?>
        </form>
      <?php endif; ?>

      <hr>

      <h2>Import Log</h2>
      <p>Recent import activity (newest first).</p>
      <?php
        $log = get_option('emwas_import_log', []);
        if (!is_array($log)) $log = [];
        $log = array_reverse($log);
        $log = array_slice($log, 0, 100);
      ?>
      <?php if (empty($log)): ?>
        <p><em>No log entries yet.</em></p>
      <?php else: ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th style="width:20%">Time</th>
              <th style="width:30%">Message</th>
              <th>Context</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($log as $entry): ?>
              <?php
                $ts = isset($entry['time']) ? (int)$entry['time'] : 0;
                $msg = $entry['message'] ?? '';
                $ctx = $entry['context'] ?? [];
                if (!is_array($ctx)) $ctx = [];
              ?>
              <tr>
                <td><?php echo esc_html($ts ? wp_date('Y-m-d H:i:s', $ts) : ''); ?></td>
                <td><?php echo esc_html($msg); ?></td>
                <td><code><?php echo esc_html(wp_json_encode($ctx)); ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('emwas_clear_import_log','emwas_clear_log_nonce'); ?>
        <input type="hidden" name="action" value="emwas_clear_import_log">
        <?php submit_button('Clear Log', 'secondary', 'submit', false); ?>
      </form>
    </div>
    <?php
}

function emwas_get_import_fields(){
    return [
        'journal_title'        => 'Journal Title',
        'journal_slug'         => 'Journal Slug',
        'volume'               => 'Volume',
        'issue'                => 'Issue',
        'journal_month'        => 'Journal Month (1-12 or legacy 110/113/116/119)',
        'journal_year'         => 'Journal Year (YYYY)',
        'month_year'           => 'Month/Year (YYYY-MM)',
        'full_issue_pdf_url'   => 'Full Issue PDF URL',
        'section_slug'         => 'Section Slug',
        'section_label'        => 'Section Label',
        'abstract_title'       => 'Abstract Title',
        'author_names'         => 'Author Names (semicolon separated)',
        'author_ids'           => 'Author IDs (comma/semicolon separated)',
        'author_slugs'         => 'Author Slugs (semicolon separated)',
        'author_titles'        => 'Author Titles (semicolon separated)',
        'author_image_urls'    => 'Author Image URLs (semicolon separated)',
        'first_page'           => 'First Page',
        'last_page'            => 'Last Page',
        'doi_url'              => 'DOI URL',
        'order'                => 'Order',
        'entry_pdf_url'        => 'Entry PDF URL',
        'abstract'             => 'Abstract Text',
        'full_text'            => 'Full Text (HTML)',
        'keywords'             => 'Keywords',
        'references'           => 'References',
        'source_journal_id'    => 'Source Journal ID',
        'source_article_id'    => 'Source Article ID',
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
        'volume'             => ['volume','vol','journalvolume'],
        'issue'              => ['issue','iss','number','journalissue'],
        'journal_month'      => ['journalmonth','monthnum','monthnumber'],
        'journal_year'       => ['journalyear','year'],
        'month_year'         => ['monthyear','monthyear','month','yearmonth','date'],
        'full_issue_pdf_url' => ['fullissuepdf','issuepdf','issuepdfurl','fullissuepdfurl','issuepdffile'],
        'section_slug'       => ['section','sectionslug','sectiontype','section_slug'],
        'section_label'      => ['sectionlabel','sectionname','articletype'],
        'abstract_title'     => ['abstracttitle','articletitle','entrytitle'],
        'author_names'       => ['authornames','authors','author'],
        'author_ids'         => ['authorids','authorid'],
        'author_slugs'       => ['authorslugs','author_slug','authorslug'],
        'author_titles'      => ['authortitles','author_title','authortitle','jobtitle','position'],
        'author_image_urls'  => ['authorimage','authorimageurl','authorimageurls','author_photo','authorphoto'],
        'first_page'         => ['firstpage','startpage'],
        'last_page'          => ['lastpage','endpage'],
        'doi_url'            => ['doi','doiurl'],
        'order'              => ['order','sort'],
        'entry_pdf_url'      => ['entrypdf','articlepdf','entrypdfurl','articlepdfurl','articlepdffile'],
        'abstract'           => ['abstract','summary','abstractclean'],
        'full_text'          => ['fulltext','fulltextclean'],
        'keywords'           => ['keywords'],
        'references'         => ['references','refs'],
        'source_journal_id'  => ['journalid','sourcejournalid'],
        'source_article_id'  => ['articleid','sourcearticleid'],
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
        'section_label',
        'section_parent_slug',
        'section_parent_label',
        'abstract_title',
        'author_names',
        'author_ids',
        'author_slugs',
        'author_titles',
        'author_image_urls',
        'first_page',
        'last_page',
        'doi_url',
        'order',
        'entry_pdf_url',
        'abstract',
        'full_text',
        'keywords',
        'references',
        'source_journal_id',
        'source_article_id',
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
            'source_journal_id'  => get_post_meta($post_id, '_emwas_source_journal_id', true),
        ];

        if (!$entries) {
            $row = $row_base + [
                'section_slug'         => '',
                'section_label'        => '',
                'section_parent_slug'  => '',
                'section_parent_label' => '',
                'abstract_title'       => '',
                'author_names'         => '',
                'author_ids'           => '',
                'author_slugs'         => '',
                'author_titles'        => '',
                'author_image_urls'    => '',
                'first_page'           => '',
                'last_page'            => '',
                'doi_url'              => '',
                'order'                => '',
                'entry_pdf_url'        => '',
                'abstract'             => '',
                'full_text'            => '',
                'keywords'             => '',
                'references'           => '',
                'source_article_id'    => '',
            ];
            fputcsv($out, emwas_row_to_csv($columns, $row));
            continue;
        }

        foreach ($entries as $e) {
            $authors = emwas_author_names_from_ids($e['author_ids'] ?? []);
            $author_data = emwas_get_author_export_data($e['author_ids'] ?? []);
            $entry_pdf_url = '';
            if (!empty($e['file_id'])) {
                $entry_pdf_url = wp_get_attachment_url((int)$e['file_id']);
            }
            $section_meta = emwas_get_section_meta_from_slug($e['section'] ?? '');
            $row = $row_base + [
                'section_slug'         => $e['section'] ?? '',
                'section_label'        => $section_meta['label'],
                'section_parent_slug'  => $section_meta['parent_slug'],
                'section_parent_label' => $section_meta['parent_label'],
                'abstract_title'       => $e['abstract_title'] ?? '',
                'author_names'         => $authors,
                'author_ids'           => $author_data['ids'],
                'author_slugs'         => $author_data['slugs'],
                'author_titles'        => $author_data['titles'],
                'author_image_urls'    => $author_data['image_urls'],
                'first_page'           => $e['first_page'] ?? '',
                'last_page'            => $e['last_page'] ?? '',
                'doi_url'              => $e['doi_url'] ?? '',
                'order'                => $e['order'] ?? '',
                'entry_pdf_url'        => $entry_pdf_url ?: '',
                'abstract'             => $e['content'] ?? '',
                'full_text'            => $e['full_text'] ?? '',
                'keywords'             => $e['keywords'] ?? '',
                'references'           => $e['references'] ?? '',
                'source_article_id'    => $e['source_article_id'] ?? '',
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

function emwas_get_author_export_data($ids){
    if (!is_array($ids) || empty($ids)) {
        return [
            'ids' => '',
            'slugs' => '',
            'titles' => '',
            'image_urls' => '',
        ];
    }

    $ids_out = [];
    $slugs = [];
    $titles = [];
    $images = [];

    foreach ($ids as $aid) {
        $aid = (int)$aid;
        if ($aid <= 0) continue;
        $p = get_post($aid);
        if (!$p || $p->post_type !== 'journal_author') continue;

        $ids_out[] = (string)$aid;
        $slugs[] = $p->post_name;
        $titles[] = emwas_get_author_title_meta($aid);
        $thumb_id = get_post_thumbnail_id($aid);
        $images[] = $thumb_id ? wp_get_attachment_url($thumb_id) : '';
    }

    return [
        'ids' => implode('; ', $ids_out),
        'slugs' => implode('; ', $slugs),
        'titles' => implode('; ', $titles),
        'image_urls' => implode('; ', $images),
    ];
}

function emwas_get_author_title_meta($author_id){
    $keys = ['author_title', 'job_title', 'position', 'title'];
    foreach ($keys as $key) {
        $val = get_post_meta($author_id, $key, true);
        if (is_string($val) && $val !== '') {
            return $val;
        }
    }
    return '';
}

function emwas_normalize_media_url($raw){
    $raw = trim((string)$raw);
    if ($raw === '') return '';

    if (preg_match('#^https?://#i', $raw)) {
        return $raw;
    }

    // If CSV uses "/media/..." paths, map them to uploads.
    // Current FTP layout: wp-content/uploads/Media/media/...
    if (strpos($raw, '/media/') === 0) {
        $uploads = wp_upload_dir();
        $baseurl = rtrim((string)$uploads['baseurl'], '/');
        if ($baseurl !== '') {
            return $baseurl.'/Media'.$raw;
        }
    }

    if ($raw[0] === '/') {
        return home_url($raw);
    }

    return home_url('/'.ltrim($raw, '/'));
}

function emwas_import_log($message, array $context = []){
    if (!EMWAS_IMPORT_LOG) return;
    $line = '[EMWAS Import] '.$message;
    if (!empty($context)) {
        $line .= ' | '.wp_json_encode($context);
    }
    error_log($line);

    if (EMWAS_IMPORT_LOG_STORE) {
        $log = get_option('emwas_import_log', []);
        if (!is_array($log)) $log = [];
        $log[] = [
            'time' => time(),
            'message' => $message,
            'context' => $context,
        ];
        if (count($log) > EMWAS_IMPORT_LOG_MAX) {
            $log = array_slice($log, -EMWAS_IMPORT_LOG_MAX);
        }
        update_option('emwas_import_log', $log, false);
    }
}

function emwas_import_default_counts(){
    return [
        'rows' => 0,
        'journals_created' => 0,
        'entries_added' => 0,
        'authors_matched' => 0,
        'authors_missing' => 0,
        'authors_created' => 0,
    ];
}

function emwas_import_session_key($token){
    return 'emwas_import_session_'.$token;
}

function emwas_import_session_get($token){
    $session = $token ? get_transient(emwas_import_session_key($token)) : false;
    return is_array($session) ? $session : false;
}

function emwas_import_session_set($token, array $session){
    if ($token) {
        set_transient(emwas_import_session_key($token), $session, EMWAS_IMPORT_TOKEN_TTL);
    }
}

function emwas_import_session_clear($token){
    if ($token) {
        delete_transient(emwas_import_session_key($token));
    }
}

function emwas_local_file_from_url($url){
    $uploads = wp_upload_dir();
    $baseurl = rtrim((string)$uploads['baseurl'], '/');
    $basedir = rtrim((string)$uploads['basedir'], DIRECTORY_SEPARATOR);

    if ($baseurl === '' || $basedir === '' || strpos($url, $baseurl) !== 0) {
        return '';
    }

    $rel = ltrim(substr($url, strlen($baseurl)), '/');
    if ($rel === '') return '';
    $file = $basedir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
    return file_exists($file) ? $file : '';
}

function emwas_file_to_url($file_path){
    $uploads = wp_upload_dir();
    $basedir = rtrim((string)$uploads['basedir'], DIRECTORY_SEPARATOR);
    $baseurl = rtrim((string)$uploads['baseurl'], '/');
    if ($basedir === '' || $baseurl === '') return '';

    $path = str_replace('\\', '/', $file_path);
    $base = str_replace('\\', '/', $basedir);
    if (strpos($path, $base) !== 0) return '';
    $rel = ltrim(substr($path, strlen($base)), '/');
    if ($rel === '') return '';
    return $baseurl.'/'.$rel;
}

function emwas_best_pdf_preview_path($pdf_attachment_id){
    $meta = wp_get_attachment_metadata($pdf_attachment_id);
    if (!is_array($meta) || empty($meta['sizes'])) return '';
    $sizes = $meta['sizes'];
    $best = null;
    foreach ($sizes as $size) {
        if (empty($size['file'])) continue;
        if (!$best) {
            $best = $size;
            continue;
        }
        $w = isset($size['width']) ? (int)$size['width'] : 0;
        $bw = isset($best['width']) ? (int)$best['width'] : 0;
        if ($w > $bw) {
            $best = $size;
        }
    }
    if (!$best || empty($best['file'])) return '';

    $pdf_path = get_attached_file($pdf_attachment_id);
    if (!$pdf_path || !file_exists($pdf_path)) return '';
    $preview = trailingslashit(dirname($pdf_path)).$best['file'];
    return file_exists($preview) ? $preview : '';
}

function emwas_create_cover_from_pdf($pdf_attachment_id, $post_id){
    $pdf_path = get_attached_file($pdf_attachment_id);
    if (!$pdf_path || !file_exists($pdf_path)) {
        emwas_import_log('PDF not found for cover', ['pdf_id' => $pdf_attachment_id]);
        return 0;
    }

    // If WordPress already generated a PDF preview image, use it.
    $preview = emwas_best_pdf_preview_path($pdf_attachment_id);
    if ($preview) {
        $preview_url = emwas_file_to_url($preview);
        $existing_id = $preview_url ? attachment_url_to_postid($preview_url) : 0;
        if ($existing_id) return (int)$existing_id;

        $new_id = emwas_create_attachment_from_file($preview, $post_id, 'Journal cover');
        if ($new_id) return (int)$new_id;
    }

    // Fallback: render first page with Imagick, if available.
    if (!class_exists('Imagick')) {
        emwas_import_log('Imagick unavailable; cannot render PDF cover', ['pdf_id' => $pdf_attachment_id]);
        return 0;
    }

    try {
        $imagick = new Imagick();
        $imagick->setResolution(EMWAS_IMPORT_PDF_COVER_DPI, EMWAS_IMPORT_PDF_COVER_DPI);
        $imagick->readImage($pdf_path.'[0]');
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(85);
        $imagick->setImageBackgroundColor('white');
        if (method_exists($imagick, 'mergeImageLayers') && defined('Imagick::LAYERMETHOD_FLATTEN')) {
            $imagick = $imagick->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        }

        $dir = rtrim((string)dirname($pdf_path), DIRECTORY_SEPARATOR);
        if ($dir === '') return 0;
        $basename = pathinfo($pdf_path, PATHINFO_FILENAME);
        $filename = wp_unique_filename($dir, $basename.'-cover.jpg');
        $dest = $dir.DIRECTORY_SEPARATOR.$filename;

        $imagick->writeImage($dest);
        $imagick->clear();
        $imagick->destroy();

        if (!file_exists($dest)) {
            emwas_import_log('Failed to write PDF cover image', ['pdf_id' => $pdf_attachment_id]);
            return 0;
        }

        $new_id = emwas_create_attachment_from_file($dest, $post_id, 'Journal cover');
        if ($new_id) return (int)$new_id;
    } catch (Exception $e) {
        emwas_import_log('PDF cover render failed', ['pdf_id' => $pdf_attachment_id, 'error' => $e->getMessage()]);
        return 0;
    }

    return 0;
}

function emwas_set_journal_cover_from_pdf($post_id, $pdf_attachment_id){
    if (!EMWAS_IMPORT_PDF_COVER) return 0;
    if (!$post_id || !$pdf_attachment_id) return 0;

    static $attempted = [];
    if (isset($attempted[$post_id])) return 0;
    $attempted[$post_id] = true;

    $existing = get_post_thumbnail_id($post_id);
    if ($existing && !EMWAS_IMPORT_PDF_COVER_FORCE) {
        return (int)$existing;
    }

    $cover_id = emwas_create_cover_from_pdf($pdf_attachment_id, $post_id);
    if ($cover_id) {
        set_post_thumbnail($post_id, $cover_id);
        update_post_meta($post_id, '_emwas_cover_image_id', (int)$cover_id);
        emwas_import_log('Set journal cover from PDF', [
            'post_id' => $post_id,
            'cover_id' => $cover_id,
            'pdf_id' => $pdf_attachment_id,
        ]);
        return (int)$cover_id;
    }

    return 0;
}

function emwas_create_attachment_from_file($file_path, $post_id, $desc = ''){
    if ($file_path === '' || !file_exists($file_path)) return 0;

    require_once ABSPATH.'wp-admin/includes/image.php';

    $filetype = wp_check_filetype(basename($file_path), null);
    $attachment = [
        'post_mime_type' => $filetype['type'] ?? '',
        'post_title'     => $desc !== '' ? $desc : sanitize_file_name(pathinfo($file_path, PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
    if (is_wp_error($attach_id) || !$attach_id) {
        return 0;
    }

    $metadata = wp_generate_attachment_metadata($attach_id, $file_path);
    if (!is_wp_error($metadata)) {
        wp_update_attachment_metadata($attach_id, $metadata);
    }
    return (int)$attach_id;
}

function emwas_sideload_media($url, $post_id, $desc = ''){
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/image.php';

    $tmp = download_url($url, 60);
    if (is_wp_error($tmp)) {
        emwas_import_log('Media download failed', ['url' => $url, 'error' => $tmp->get_error_message()]);
        return 0;
    }

    $name = basename(parse_url($url, PHP_URL_PATH) ?: 'media-file');
    $file_array = [
        'name'     => $name,
        'tmp_name' => $tmp,
    ];

    $sideload = wp_handle_sideload($file_array, ['test_form' => false]);
    if (!empty($sideload['error'])) {
        @unlink($tmp);
        emwas_import_log('Media sideload failed', ['url' => $url, 'error' => $sideload['error']]);
        return 0;
    }

    $attachment = [
        'post_mime_type' => $sideload['type'],
        'post_title'     => $desc !== '' ? $desc : sanitize_file_name(pathinfo($sideload['file'], PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $sideload['file'], $post_id);
    if (is_wp_error($attach_id) || !$attach_id) {
        emwas_import_log('Attachment insert failed', ['url' => $url]);
        return 0;
    }

    $metadata = wp_generate_attachment_metadata($attach_id, $sideload['file']);
    if (!is_wp_error($metadata)) {
        wp_update_attachment_metadata($attach_id, $metadata);
    }

    return (int)$attach_id;
}

function emwas_ensure_attachment_id($raw_url, $post_id, $desc = ''){
    $url = emwas_normalize_media_url($raw_url);
    if ($url === '') return 0;
    $url = esc_url_raw($url);

    $existing = attachment_url_to_postid($url);
    if ($existing) return (int)$existing;

    $local = emwas_local_file_from_url($url);
    if ($local) {
        $id = emwas_create_attachment_from_file($local, $post_id, $desc);
        if ($id) {
            emwas_import_log('Attached local media', ['url' => $url, 'attachment_id' => $id]);
            return $id;
        }
    }

    $id = emwas_sideload_media($url, $post_id, $desc);
    if ($id) {
        emwas_import_log('Sideloaded media', ['url' => $url, 'attachment_id' => $id]);
    }
    return $id;
}

function emwas_set_author_thumbnail_from_url($author_id, $image_url){
    if ($image_url === '') return false;
    $fid = emwas_ensure_attachment_id($image_url, $author_id, 'Author image');
    if ($fid) {
        set_post_thumbnail($author_id, $fid);
        return true;
    }
    emwas_import_log('Author image not found', ['author_id' => $author_id, 'url' => $image_url]);
    return false;
}

function emwas_get_section_maps(){
    static $cache = null;
    if (is_array($cache)) return $cache;

    $types = function_exists('emwas_get_section_types_hier') ? emwas_get_section_types_hier() : [];
    $slug_to_label = [];
    $label_to_slug = [];
    $slug_to_parent = [];
    $parent_label = [];

    foreach ($types as $p) {
        $pslug = $p['slug'] ?? '';
        $plabel = $p['label'] ?? '';
        if ($pslug && $plabel) {
            $slug_to_label[$pslug] = $plabel;
            $label_to_slug[strtolower($plabel)] = $pslug;
            $parent_label[$pslug] = $plabel;
        }
        if (!empty($p['children']) && is_array($p['children'])) {
            foreach ($p['children'] as $ch) {
                $cslug = $ch['slug'] ?? '';
                $clabel = $ch['label'] ?? '';
                if ($cslug && $clabel) {
                    $slug_to_label[$cslug] = $clabel;
                    $label_to_slug[strtolower($clabel)] = $cslug;
                    $slug_to_parent[$cslug] = $pslug;
                }
            }
        }
    }

    $cache = [
        'slug_to_label' => $slug_to_label,
        'label_to_slug' => $label_to_slug,
        'slug_to_parent' => $slug_to_parent,
        'parent_label' => $parent_label,
    ];

    return $cache;
}

function emwas_get_section_meta_from_slug($slug){
    $slug = sanitize_title((string)$slug);
    if ($slug === '') {
        return [
            'label' => '',
            'parent_slug' => '',
            'parent_label' => '',
        ];
    }

    $maps = emwas_get_section_maps();
    $label = $maps['slug_to_label'][$slug] ?? '';
    $parent_slug = $maps['slug_to_parent'][$slug] ?? '';
    $parent_label = $parent_slug ? ($maps['parent_label'][$parent_slug] ?? '') : '';

    return [
        'label' => $label,
        'parent_slug' => $parent_slug,
        'parent_label' => $parent_label,
    ];
}

function emwas_section_slug_from_label($label){
    $label = trim((string)$label);
    if ($label === '') return '';

    $maps = emwas_get_section_maps();
    $key = strtolower($label);
    if (isset($maps['label_to_slug'][$key])) {
        return $maps['label_to_slug'][$key];
    }

    return sanitize_title($label);
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

function emwas_preview_value($val){
    $val = trim((string)$val);
    if ($val === '') return '';
    $val = preg_replace('/\s+/', ' ', $val);
    if (mb_strlen($val) > 120) {
        $val = mb_substr($val, 0, 120).'...';
    }
    return $val;
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

    emwas_import_log('Import started', [
        'path' => $path,
        'headers' => count($headers),
        'mapped_fields' => array_values(array_filter($map)),
    ]);

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

    $counts = emwas_import_default_counts();

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

    emwas_import_log('Import finished', $counts);

    $msg = sprintf(
        'Import complete. Rows processed: %d. Journals created: %d. Entries added: %d. Authors matched: %d. Authors missing: %d. Authors created: %d.',
        $counts['rows'],
        $counts['journals_created'],
        $counts['entries_added'],
        $counts['authors_matched'],
        $counts['authors_missing'],
        $counts['authors_created']
    );
    set_transient('emwas_import_result', $msg, EMWAS_IMPORT_TOKEN_TTL);

    $url = add_query_arg(['post_type' => 'journal', 'page' => 'emwa-journal-import-export'], admin_url('edit.php'));
    wp_safe_redirect($url);
    exit;
}

function emwas_handle_clear_import_log(){
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    if (!isset($_POST['emwas_clear_log_nonce']) || !wp_verify_nonce($_POST['emwas_clear_log_nonce'], 'emwas_clear_import_log')) {
        wp_die('Invalid nonce');
    }
    update_option('emwas_import_log', [], false);
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
    $my = emwas_resolve_month_year($vals);
    if ($my !== '') {
        update_post_meta($post_id, '_emwas_month_year', $my);
    }
    if (isset($vals['full_issue_pdf_url']) && $vals['full_issue_pdf_url'] !== '') {
        $fid = emwas_ensure_attachment_id($vals['full_issue_pdf_url'], $post_id, 'Full issue PDF');
        if ($fid) {
            update_post_meta($post_id, '_emwas_full_issue_file_id', (int)$fid);
            emwas_set_journal_cover_from_pdf($post_id, $fid);
        }
    }
    if (isset($vals['source_journal_id']) && $vals['source_journal_id'] !== '') {
        update_post_meta($post_id, '_emwas_source_journal_id', sanitize_text_field($vals['source_journal_id']));
    }
}

function emwas_add_entry_from_row($post_id, array $vals, array &$counts){
    $section = '';
    if (!empty($vals['section_slug'])) {
        $section = sanitize_title($vals['section_slug']);
    } elseif (!empty($vals['section_label'])) {
        $section = emwas_section_slug_from_label($vals['section_label']);
    }
    if (!$section) return false;

    $entries = get_post_meta($post_id, '_emwas_entries', true);
    $entries = is_array($entries) ? $entries : [];

    $max_order = 0;
    foreach ($entries as $e) {
        $o = isset($e['order']) ? (int)$e['order'] : 0;
        if ($o > $max_order) $max_order = $o;
    }

    $author_ids = [];
    $author_titles = emwas_split_list($vals['author_titles'] ?? '');
    $author_images = emwas_split_list($vals['author_image_urls'] ?? '');
    $author_slugs = emwas_split_list($vals['author_slugs'] ?? '');

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
    } elseif (!empty($vals['author_names']) || !empty($vals['author_slugs'])) {
        $names = emwas_split_author_names($vals['author_names'] ?? '');
        $max = max(count($names), count($author_slugs));

        for ($i = 0; $i < $max; $i++) {
            $name = isset($names[$i]) ? trim($names[$i]) : '';
            $slug = isset($author_slugs[$i]) ? sanitize_title($author_slugs[$i]) : '';
            if ($name === '' && $slug === '') continue;

            $p = null;
            if ($slug) {
                $p = get_page_by_path($slug, OBJECT, 'journal_author');
            }
            if (!$p && $name !== '') {
                $p = get_page_by_title($name, OBJECT, 'journal_author');
            }

            if ($p) {
                $author_ids[] = $p->ID;
                $counts['authors_matched']++;
                continue;
            }

            $new_id = emwas_create_author_from_row($name, $slug, $author_titles[$i] ?? '', $author_images[$i] ?? '');
            if ($new_id) {
                $author_ids[] = $new_id;
                $counts['authors_created']++;
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
        'full_text'      => isset($vals['full_text']) ? wp_kses_post($vals['full_text']) : '',
        'keywords'       => isset($vals['keywords']) ? sanitize_text_field($vals['keywords']) : '',
        'references'     => isset($vals['references']) ? wp_kses_post($vals['references']) : '',
        'source_article_id' => isset($vals['source_article_id']) ? sanitize_text_field($vals['source_article_id']) : '',
    ];

    if ($entry['content'] === '' && !empty($entry['full_text'])) {
        $entry['content'] = wp_strip_all_tags($entry['full_text']);
    }

    if (!empty($vals['entry_pdf_url'])) {
        $fid = emwas_ensure_attachment_id($vals['entry_pdf_url'], $post_id, 'Entry PDF');
        if ($fid) $entry['file_id'] = (int)$fid;
    }

    $entries[] = $entry;
    update_post_meta($post_id, '_emwas_entries', $entries);
    return true;
}

function emwas_resolve_month_year(array $vals){
    if (isset($vals['month_year']) && $vals['month_year'] !== '') {
        return preg_replace('/^(\d{4})-(\d{2}).*$/', '$1-$2', $vals['month_year']);
    }

    $year = isset($vals['journal_year']) ? (int)$vals['journal_year'] : 0;
    $month_raw = isset($vals['journal_month']) ? (string)$vals['journal_month'] : '';
    $month = (int)preg_replace('/[^0-9]/', '', $month_raw);
    if ($month <= 0 || $year <= 0) return '';

    $legacy_map = [
        110 => 1,
        113 => 4,
        116 => 7,
        119 => 10,
    ];
    if (isset($legacy_map[$month])) {
        $month = $legacy_map[$month];
    } elseif ($month > 12 && $month < 100) {
        return '';
    } elseif ($month > 99) {
        $mod = $month % 100;
        if ($mod >= 1 && $mod <= 12) {
            $month = $mod;
        }
    }

    if ($month < 1 || $month > 12) return '';
    return sprintf('%04d-%02d', $year, $month);
}

function emwas_split_list($val){
    $val = trim((string)$val);
    if ($val === '') return [];
    $parts = preg_split('/\s*;\s*/', $val);
    return array_values(array_filter(array_map('trim', $parts), function($v){ return $v !== ''; }));
}

function emwas_split_author_names($val){
    $val = trim((string)$val);
    if ($val === '') return [];
    $names = preg_split('/\s*;\s*/', $val);
    if (count($names) === 1) {
        $names = preg_split('/\s*,\s*/', $val);
    }
    return array_values(array_filter(array_map('trim', $names), function($v){ return $v !== ''; }));
}

function emwas_create_author_from_row($name, $slug, $title, $image_url){
    static $cache = [];
    $cache_key = $slug ?: $name;
    if ($cache_key && isset($cache[$cache_key])) return $cache[$cache_key];

    $post_data = [
        'post_title'  => $name ?: $slug,
        'post_name'   => $slug ?: '',
        'post_type'   => 'journal_author',
        'post_status' => 'publish',
    ];
    $new_id = wp_insert_post($post_data);
    if (is_wp_error($new_id) || !$new_id) return 0;

    if ($title !== '') {
        update_post_meta($new_id, 'author_title', sanitize_text_field($title));
    }

    if ($image_url !== '') {
        emwas_set_author_thumbnail_from_url($new_id, $image_url);
    }

    if ($cache_key) $cache[$cache_key] = $new_id;
    return $new_id;
}

function emwas_ajax_import_init(){
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!isset($_POST['emwas_import_nonce']) || !wp_verify_nonce($_POST['emwas_import_nonce'], 'emwas_import_csv')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    if ($token === '') {
        wp_send_json_error(['message' => 'Missing import token.'], 400);
    }

    $import_data = get_transient('emwas_import_'.$token);
    if (!$import_data || empty($import_data['path'])) {
        wp_send_json_error(['message' => 'Import session expired. Please upload the CSV again.'], 400);
    }

    $path = $import_data['path'];
    if (!file_exists($path)) {
        wp_send_json_error(['message' => 'CSV file not found on server.'], 400);
    }

    $map = isset($_POST['map']) && is_array($_POST['map'])
        ? array_map('sanitize_text_field', wp_unslash($_POST['map']))
        : [];
    $allowed = array_keys(emwas_get_import_fields());
    foreach ($map as $header => $field) {
        if (!$field || !in_array($field, $allowed, true)) {
            unset($map[$header]);
        }
    }

    if (!empty($_POST['save_mapping'])) {
        update_option('emwas_import_mapping', $map);
    }

    $fh = fopen($path, 'r');
    if (!$fh) {
        wp_send_json_error(['message' => 'Could not open CSV file.'], 400);
    }

    $file_headers = fgetcsv($fh);
    if (!$file_headers) {
        fclose($fh);
        wp_send_json_error(['message' => 'Invalid CSV file.'], 400);
    }
    if (isset($file_headers[0])) {
        $file_headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $file_headers[0]);
    }

    $offset = ftell($fh);
    fclose($fh);

    $bytes_total = @filesize($path);
    if ($bytes_total === false) $bytes_total = 0;

    $session = [
        'path' => $path,
        'file_headers' => $file_headers,
        'map' => $map,
        'offset' => $offset,
        'counts' => emwas_import_default_counts(),
        'bytes_total' => (int)$bytes_total,
        'started' => time(),
    ];
    emwas_import_session_set($token, $session);

    emwas_import_log('Import session initialized', [
        'token' => $token,
        'headers' => count($file_headers),
        'mapped_fields' => array_values($map),
    ]);

    $percent = $bytes_total > 0 ? min(100, round(($offset / $bytes_total) * 100, 1)) : 0;

    wp_send_json_success([
        'token' => $token,
        'counts' => $session['counts'],
        'offset' => $offset,
        'bytes_total' => (int)$bytes_total,
        'percent' => $percent,
    ]);
}

function emwas_ajax_import_step(){
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!isset($_POST['emwas_import_nonce']) || !wp_verify_nonce($_POST['emwas_import_nonce'], 'emwas_import_csv')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    if ($token === '') {
        wp_send_json_error(['message' => 'Missing import token.'], 400);
    }

    $session = emwas_import_session_get($token);
    if (!$session || empty($session['path'])) {
        wp_send_json_error(['message' => 'Import session expired. Please start again.'], 400);
    }

    $path = $session['path'];
    if (!file_exists($path)) {
        wp_send_json_error(['message' => 'CSV file not found on server.'], 400);
    }

    $map = isset($session['map']) && is_array($session['map']) ? $session['map'] : [];
    $file_headers = isset($session['file_headers']) && is_array($session['file_headers']) ? $session['file_headers'] : [];
    if (!$file_headers) {
        wp_send_json_error(['message' => 'Missing CSV headers in session.'], 400);
    }

    $offset = isset($session['offset']) ? (int)$session['offset'] : 0;
    $counts = isset($session['counts']) && is_array($session['counts']) ? $session['counts'] : emwas_import_default_counts();

    $limit = isset($_POST['batch']) ? (int)$_POST['batch'] : EMWAS_IMPORT_BATCH;
    if ($limit < 1) $limit = EMWAS_IMPORT_BATCH;
    if ($limit > 200) $limit = 200;

    $fh = fopen($path, 'r');
    if (!$fh) {
        wp_send_json_error(['message' => 'Could not open CSV file.'], 400);
    }

    if ($offset > 0) {
        fseek($fh, $offset);
    } else {
        $file_headers = fgetcsv($fh);
        if (!$file_headers) {
            fclose($fh);
            wp_send_json_error(['message' => 'Invalid CSV file.'], 400);
        }
        if (isset($file_headers[0])) {
            $file_headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $file_headers[0]);
        }
        $offset = ftell($fh);
    }

    $processed = 0;
    $messages = [];
    $last = [];
    $journal_cache = [];
    $eof = false;

    while ($processed < $limit) {
        $row = fgetcsv($fh);
        if ($row === false) {
            $eof = true;
            break;
        }

        $offset = ftell($fh);
        if (empty(array_filter($row, function($v){ return $v !== null && $v !== ''; }))) {
            continue;
        }

        $processed++;
        $counts['rows']++;

        $data = [];
        foreach ($file_headers as $i => $h) {
            $data[$h] = isset($row[$i]) ? $row[$i] : '';
        }

        $vals = emwas_apply_mapping($data, $map);
        $journal_id = emwas_get_or_create_journal($vals, $journal_cache, $counts);
        $entry_added = false;

        if ($journal_id) {
            emwas_update_journal_meta($journal_id, $vals);
            $entry_added = emwas_add_entry_from_row($journal_id, $vals, $counts);
            if ($entry_added) $counts['entries_added']++;
        }

        $journal_title = isset($vals['journal_title']) ? $vals['journal_title'] : '';
        $entry_title = isset($vals['abstract_title']) ? $vals['abstract_title'] : '';
        $section = '';
        if (!empty($vals['section_slug'])) {
            $section = $vals['section_slug'];
        } elseif (!empty($vals['section_label'])) {
            $section = $vals['section_label'];
        }

        $msg = 'Row '.$counts['rows'].': ';
        $msg .= $journal_title !== '' ? $journal_title : '(no journal title)';
        if ($entry_title !== '') $msg .= ' | '.$entry_title;
        if ($section !== '') $msg .= ' | '.$section;
        $messages[] = $msg;
        if (count($messages) > 8) {
            array_shift($messages);
        }

        $last = [
            'row' => $counts['rows'],
            'journal' => $journal_title,
            'entry' => $entry_title,
            'section' => $section,
            'entry_added' => $entry_added ? 1 : 0,
        ];
    }

    fclose($fh);

    $bytes_total = isset($session['bytes_total']) ? (int)$session['bytes_total'] : 0;
    if ($bytes_total <= 0) {
        $bytes_total = @filesize($path);
        if ($bytes_total === false) $bytes_total = 0;
    }

    $percent = $bytes_total > 0 ? min(100, round(($offset / $bytes_total) * 100, 1)) : 0;

    $session['offset'] = $offset;
    $session['counts'] = $counts;
    $session['bytes_total'] = (int)$bytes_total;
    $session['file_headers'] = $file_headers;
    emwas_import_session_set($token, $session);

    $done = $eof;
    $message = '';

    if ($done) {
        emwas_import_session_clear($token);
        delete_transient('emwas_import_'.$token);
        @unlink($path);

        $message = sprintf(
            'Import complete. Rows processed: %d. Journals created: %d. Entries added: %d. Authors matched: %d. Authors missing: %d. Authors created: %d.',
            $counts['rows'],
            $counts['journals_created'],
            $counts['entries_added'],
            $counts['authors_matched'],
            $counts['authors_missing'],
            $counts['authors_created']
        );
        set_transient('emwas_import_result', $message, EMWAS_IMPORT_TOKEN_TTL);
        emwas_import_log('Import finished', $counts);
    }

    wp_send_json_success([
        'done' => $done,
        'counts' => $counts,
        'processed' => $processed,
        'offset' => $offset,
        'bytes_total' => (int)$bytes_total,
        'percent' => $percent,
        'messages' => $messages,
        'last' => $last,
        'message' => $message,
    ]);
}

function emwas_ajax_import_cancel(){
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    if (!isset($_POST['emwas_import_nonce']) || !wp_verify_nonce($_POST['emwas_import_nonce'], 'emwas_import_csv')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 400);
    }

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    if ($token === '') {
        wp_send_json_error(['message' => 'Missing import token.'], 400);
    }

    emwas_import_session_clear($token);
    wp_send_json_success(['message' => 'Import session cleared.']);
}


