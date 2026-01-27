<?php
if (!defined('ABSPATH')) exit;

/* ========================
   Utilities / Mappings
   ======================== */

function emwas_types_maps(){
    $types = function_exists('emwas_get_section_types_hier') ? emwas_get_section_types_hier() : [];
    $labels = []; $childToParent = []; $parentOrder = []; $childOrder = [];
    $seenP = []; $seenC = [];
    foreach ($types as $p){
        $ps = isset($p['slug']) ? sanitize_title($p['slug']) : '';
        if (!$ps || isset($seenP[$ps])) continue;
        $seenP[$ps]=1;
        $parentOrder[] = $ps;
        $labels[$ps] = isset($p['label']) ? wp_strip_all_tags($p['label']) : $ps;
        $childOrder[$ps] = [];
        if (!empty($p['children']) && is_array($p['children'])){
            foreach ($p['children'] as $ch){
                $cs = isset($ch['slug']) ? sanitize_title($ch['slug']) : '';
                if (!$cs || isset($seenC[$cs])) continue;
                $seenC[$cs]=1;
                $childToParent[$cs] = $ps;
                $labels[$cs] = isset($ch['label']) ? wp_strip_all_tags($ch['label']) : $cs;
                $childOrder[$ps][] = $cs;
            }
        }
    }
    return [$labels,$childToParent,$parentOrder,$childOrder];
}

/** Render authors list as links */
function emwas_render_authors($ids) {
    if (!is_array($ids) || empty($ids)) return '';
    $bits = [];
    foreach ($ids as $aid) {
        $aid = (int)$aid;
        if ($aid <= 0) continue;
        $p = get_post($aid);
        if ($p && $p->post_type === 'journal_author') {
            $name = get_the_title($aid);
            $url  = get_permalink($aid);
            $bits[] = $url ? '<a href="'.esc_url($url).'">'.esc_html($name).'</a>' : esc_html($name);
        }
    }
    return implode(', ', $bits);
}

/** Build citation string */
function emwas_build_citation($post_id, $first, $last){
    $vol = (int) get_post_meta($post_id,'_emwas_volume', true);
    $iss = (int) get_post_meta($post_id,'_emwas_issue', true);
    $my  = get_post_meta($post_id,'_emwas_month_year', true);
    $yr  = $my && preg_match('/^(\d{4})/', $my, $m) ? (int)$m[1] : (int) get_the_date('Y', $post_id);

    $first = is_numeric($first) ? (int)$first : trim((string)$first);
    $last  = is_numeric($last)  ? (int)$last  : trim((string)$last);

    if (!$yr || !$vol || !$iss || $first==='') return '';
    $pages = ($last==='' || (string)$last===(string)$first) ? (string)$first : ($first.'–'.$last);
    return sprintf('Medical Writing. %d;%d(%d):%s', $yr, $vol, $iss, $pages);
}

/* ========================
   Sections Cards (listing)
   ======================== */

add_shortcode('journal_sections_cards', function($atts){
    if (!is_singular('journal')) return '';

    $a = shortcode_atts([
        'columns'    => '3',
        'sections'   => '',
        'show_empty' => 'no', // kept for VC UI, but ignored (we always hide empty sections now)
    ], $atts, 'journal_sections_cards');

    $cols   = max(1, min(6, (int)$a['columns']));
    $filter = array_filter(array_map('sanitize_title', array_map('trim', explode(',', $a['sections']))));

    $post_id = get_the_ID();
    $entries = get_post_meta($post_id, '_emwas_entries', true);
    $entries = is_array($entries) ? $entries : [];

    [$labels,$childToParent,$parentOrder,$childOrder] = emwas_types_maps();
    $thumb = get_the_post_thumbnail_url($post_id, 'medium');

    // Group by parent->child
    $grouped = [];
    foreach ($entries as $e) {
        $slug = isset($e['section']) ? sanitize_title($e['section']) : '';
        if (!$slug) continue;
        $parent = isset($childToParent[$slug]) ? $childToParent[$slug] : $slug;
        $child  = isset($childToParent[$slug]) ? $slug : '_root';
        if ($filter && !in_array($slug,$filter,true) && !in_array($parent,$filter,true)) continue;
        $grouped[$parent][$child][] = $e;
    }

    ob_start();
    foreach ($parentOrder as $pSlug) {
        $buckets = $grouped[$pSlug] ?? [];

        // NEW: always skip parents with no entries at all (hide empty sections)
        if (!$buckets) continue;

        $pLabel = $labels[$pSlug] ?? ucwords(str_replace('-', ' ', $pSlug));
        echo '<section class="emwas-section my-10"><h2 class="emwas-h2">'.esc_html($pLabel).'</h2>';

        // Order children but only render non-empty child buckets
        $orderedChildSlugs = array_merge(['_root'], array_values(array_filter($childOrder[$pSlug] ?? [], function($c) use($buckets){
            return !empty($buckets[$c]);
        })));

        $printedSomething = false;

        foreach ($orderedChildSlugs as $cSlug) {
            $cards = $buckets[$cSlug] ?? [];
            if (!$cards) continue;

            usort($cards, function($x,$y){
                return ((int)($x['order']??0)) <=> ((int)($y['order']??0));
            });

            $printedSomething = true;
            echo '<div class="grid gap-6 grid-cols-1 sm:grid-cols-1 lg:grid-cols-'.intval($cols).'">';
            foreach ($cards as $c) {
                $slug     = sanitize_title($c['section'] ?? '');
                $fid      = (int)($c['file_id'] ?? 0);
                $fileUrl  = $fid ? wp_get_attachment_url($fid) : '';
                $authors  = isset($c['author_ids']) ? array_map('intval', (array)$c['author_ids']) : [];
                $first    = $c['first_page'] ?? '';
                $last     = $c['last_page']  ?? '';
                $abstract_title = trim($c['abstract_title'] ?? '');

                $isChild   = isset($childToParent[$slug]);
                $cardTitle = $labels[$isChild ? $slug : $pSlug] ?? ($isChild ? $slug : $pSlug);
                $citation  = emwas_build_citation($post_id, $first, $last);
                $authors_html = emwas_render_authors($authors);

                // ENTRY URL using Abstract Title if present
                $entry_slug = $abstract_title ? sanitize_title($abstract_title) : '';
                $section_url = trailingslashit(get_permalink($post_id)) . $slug . '/';
                $entry_url   = $entry_slug ? trailingslashit(get_permalink($post_id)) . $entry_slug . '/' : $section_url;

                echo '<article class="card card--row emwas-card">';
                echo '<a class="emwas-card-link" href="'.esc_url($entry_url).'"></a>';
                if ($thumb) echo '<div class="card-media"><img src="'.esc_url($thumb).'" alt="" loading="lazy"></div>';
                echo '<div class="emwas-card-body">';
                echo '<h3 class="text-lg font-semibold mb-1"><a href="'.esc_url($entry_url).'">'.esc_html($abstract_title ?: $cardTitle).'</a></h3>';
                if ($citation) echo '<p class="text-sm mb-1">'.esc_html($citation).'</p>';
                if ($authors_html) echo '<p class="text-sm text-muted mb-2">'.$authors_html.'</p>';

                // Buttons row
                echo '<div class="flex gap-3 mt-4">';
                echo '<a class="btn" href="'.esc_url($entry_url).'">Open</a>';
                if ( is_user_logged_in() && $fileUrl ) {
                    echo '<a class="btn-outline" href="'.esc_url($fileUrl).'" target="_blank" rel="noopener">Download</a>';
                }
                echo '</div>';

                echo '</div></article>';
            }
            echo '</div>';
        }

        // If somehow nothing printed (shouldn’t happen since !$buckets continued), skip outputting section wrapper
        if (!$printedSomething) {
            // Remove the section we started: easiest is to not print anything more and continue
        }

        echo '</section>';
    }
    return ob_get_clean();
});

/* ========================
   Section Detail (template 5517)
   ======================== */

add_shortcode('journal_section_detail', function($atts){
    if (!is_singular('journal')) return '';

    $a   = shortcode_atts(['section' => ''], $atts, 'journal_section_detail');
    $seg = sanitize_title(get_query_var('emwas_seg'));
    $sec = sanitize_title($a['section'] ?: $seg);
    if (!$sec) return '<p class="text-muted">No section specified.</p>';

    $post_id = get_the_ID();
    $entries = get_post_meta($post_id, '_emwas_entries', true) ?: [];
    [$labels,$childToParent] = emwas_types_maps();

    // Try to match an abstract_title slug first (single entry pages)
    $match_entry = null;
    foreach ($entries as $e){
        $abs = trim($e['abstract_title'] ?? '');
        if ($abs && sanitize_title($abs) === $sec) { $match_entry = $e; break; }
    }

    if ($match_entry){
        $e = $match_entry;
        $label = $labels[$e['section']] ?? ucwords(str_replace('-', ' ', $e['section']));
        $authorsHtml = emwas_render_authors($e['author_ids'] ?? []);
        $abstract_title = trim($e['abstract_title'] ?? '');
        $abstract = trim($e['content'] ?? '');
        $doiUrl = $e['doi_url'] ?? '';
        $citation = emwas_build_citation($post_id,$e['first_page']??'',$e['last_page']??'');
        $fileUrl = !empty($e['file_id']) ? wp_get_attachment_url($e['file_id']) : '';

        ob_start();
        echo '<section class="emwas-section my-10"><article class="my-6">';
        echo '<h2 class="emwas-h2 mb-1">'.esc_html($label).'</h2>';
        if ($abstract_title) echo '<h3 class="text-lg font-semibold mb-2">'.esc_html($abstract_title).'</h3>';
        if ($authorsHtml) echo '<p class="text-sm text-muted mb-2">Authors: '.$authorsHtml.'</p>';
        if ($abstract) echo '<div class="typography mb-2"><p>'.nl2br(esc_html($abstract)).'</p></div>';
        if ($citation || $doiUrl){
            echo '<p class="mb-2">';
            if ($citation) echo esc_html($citation);
            if ($citation && $doiUrl) echo ' | ';
            if ($doiUrl) echo '<a href="'.esc_url($doiUrl).'" target="_blank" rel="noopener">'.esc_html($doiUrl).'</a>';
            echo '</p>';
        }
        if ( is_user_logged_in() && $fileUrl ) {
            echo '<div class="mt-4"><a class="btn" href="'.esc_url($fileUrl).'" target="_blank" rel="noopener">Download Article</a></div>';
        } elseif ( !is_user_logged_in() && $fileUrl ) {
            $login_url = wp_login_url( home_url( add_query_arg([], $_SERVER['REQUEST_URI']) ) );
            echo '<div class="mt-4"><p class="text-sm text-muted"><em><a href="'.esc_url($login_url).'">Login</a> required to download full article.</em></p></div>';
        }
        echo '</article></section>';
        return ob_get_clean();
    }

    // Otherwise, section summary (if nothing matched, we still show the section’s entries)
    $filtered = array_filter($entries, function($e) use($sec,$childToParent){
        $slug = sanitize_title($e['section'] ?? '');
        if (!$slug) return false;
        return $slug === $sec || ($childToParent[$slug] ?? '') === $sec;
    });
    if (!$filtered) return '<p class="text-muted">No articles in this section yet.</p>';
    usort($filtered, fn($x,$y)=>((int)($x['order']??0)) <=> ((int)($y['order']??0)));

    ob_start();
    echo '<section class="emwas-section my-10">';
    foreach ($filtered as $e){
        $slug = sanitize_title($e['section'] ?? '');
        $label = $labels[$slug] ?? ucwords(str_replace('-', ' ', $slug));
        $authorsHtml = emwas_render_authors($e['author_ids'] ?? []);
        $abstract_title = trim($e['abstract_title'] ?? '');
        $abstract = trim($e['content'] ?? '');
        $doiUrl = $e['doi_url'] ?? '';
        $citation = emwas_build_citation($post_id,$e['first_page']??'',$e['last_page']??'');
        $fileUrl = !empty($e['file_id']) ? wp_get_attachment_url($e['file_id']) : '';

        echo '<article class="my-6">';
        echo '<h2 class="emwas-h2 mb-1">'.esc_html($label).'</h2>';
        if ($abstract_title) echo '<h3 class="text-lg font-semibold mb-2">'.esc_html($abstract_title).'</h3>';
        if ($authorsHtml) echo '<p class="text-sm text-muted mb-2">Authors: '.$authorsHtml.'</p>';
        if ($abstract) echo '<div class="typography mb-2"><p>'.nl2br(esc_html($abstract)).'</p></div>';
        if ($citation || $doiUrl){
            echo '<p class="mb-2">';
            if ($citation) echo esc_html($citation);
            if ($citation && $doiUrl) echo ' | ';
            if ($doiUrl) echo '<a href="'.esc_url($doiUrl).'" target="_blank" rel="noopener">'.esc_html($doiUrl).'</a>';
            echo '</p>';
        }
        if ( is_user_logged_in() && $fileUrl ) {
            echo '<div class="mt-4"><a class="btn" href="'.esc_url($fileUrl).'" target="_blank" rel="noopener">Download Article</a></div>';
        } elseif ( !is_user_logged_in() && $fileUrl ) {
            $login_url = wp_login_url( home_url( add_query_arg([], $_SERVER['REQUEST_URI']) ) );
            echo '<div class="mt-4"><p class="text-sm text-muted"><em><a href="'.esc_url($login_url).'">Login</a> required to download full article.</em></p></div>';
        }
        echo '</article>';
    }
    echo '</section>';
    return ob_get_clean();
});

/* ========================
   Flipbook (DFlip)
   ======================== */

add_shortcode('journal_section_flipbook', function($atts){
    if (!is_singular('journal')) return '';
    $a = shortcode_atts([
        'section'     => '',
        'source'      => 'auto',
        'entry_index' => '1',
        'extra'       => '',
    ], $atts);

    $post_id = get_the_ID();
    $sec = sanitize_title($a['section'] ?: get_query_var('emwas_seg'));
    $entries = get_post_meta($post_id,'_emwas_entries',true) ?: [];
    $issue_file_id  = (int)get_post_meta($post_id,'_emwas_full_issue_file_id',true);
    $issue_file_url = $issue_file_id ? wp_get_attachment_url($issue_file_id) : '';

    [$labels,$childToParent] = emwas_types_maps();
    $entry_pdf_url = '';
    if ($sec){
        $filtered = array_filter($entries, function($e) use($sec,$childToParent){
            $slug = sanitize_title($e['section'] ?? '');
            return $slug === $sec || ($childToParent[$slug] ?? '') === $sec;
        });
        if ($filtered){
            usort($filtered, fn($x,$y)=>((int)($x['order']??0))<=>((int)($y['order']??0)));
            $filtered = array_values($filtered);
            $idx = max(0, ((int)$a['entry_index'])-1);
            if (isset($filtered[$idx]['file_id'])){
                $fid = (int)$filtered[$idx]['file_id'];
                if ($fid) $entry_pdf_url = wp_get_attachment_url($fid);
            }
        }
    }

    $srcOpt = strtolower($a['source']);
    $pdf = ($srcOpt==='entry') ? $entry_pdf_url : (($srcOpt==='issue')?$issue_file_url:($entry_pdf_url?:$issue_file_url));
    if (!$pdf) return '<p class="text-muted">No PDF available for flipbook.</p>';

    $extra = trim($a['extra']);
    return do_shortcode('[dflip source="'.esc_url($pdf).'"'.($extra?' '.$extra:'').'][/dflip]');
});

/* ========================
   JOURNAL VOLUME LIST SHORTCODE + VC ELEMENT
   ======================== */

add_shortcode('journal_volume_list', function($atts){
    $a = shortcode_atts(['cols' => '1'], $atts, 'journal_volume_list');
    $cols = max(1, min(4, (int)$a['cols']));

    $journals = get_posts([
        'post_type'      => 'journal',
        'posts_per_page' => -1,
        'orderby'        => 'meta_value',
        'meta_key'       => '_emwas_month_year',
        'order'          => 'DESC',
    ]);

    if (!$journals) return '<p>No journals found.</p>';

    $grouped = [];
    foreach ($journals as $j) {
        $my     = get_post_meta($j->ID, '_emwas_month_year', true); // YYYY-MM
        $year   = $my ? substr($my,0,4) : 'Unknown';
        $volume = get_post_meta($j->ID, '_emwas_volume', true);
        $issue  = get_post_meta($j->ID, '_emwas_issue', true);

        $grouped[$year][] = [
            'id'     => $j->ID,
            'title'  => get_the_title($j),
            'url'    => get_permalink($j),
            'thumb'  => get_the_post_thumbnail_url($j,'thumbnail'),
            'year'   => $year,
            'month'  => $my ? date_i18n('F', strtotime($my.'-01')) : '',
            'volume' => $volume,
            'issue'  => $issue,
        ];
    }
    krsort($grouped); // newest year first

    ob_start(); ?>
<div class="journal-volume-filters mb-4">
  <input type="text" id="jvf-search" placeholder="Search titles…" class="widefat" style="max-width:300px;display:inline-block;margin-right:10px;">
  <select id="jvf-year"><option value="">All Years</option></select>
  <select id="jvf-volume"><option value="">All Volumes</option></select>
  <select id="jvf-issue"><option value="">All Issues</option></select>
  <button type="button" id="jvf-reset" class="btn-outline" style="margin-left:10px;">Reset</button>
</div>

<div class="journal-volume-list grid gap-6 lg:grid-cols-<?php echo (int)$cols; ?>">
  <?php foreach ($grouped as $year=>$items): ?>
    <div class="jvf-year-group" data-year="<?php echo esc_attr($year); ?>">
      <h2 class="emwas-h2 mb-2"><?php echo esc_html($year); ?></h2>
      <?php foreach ($items as $it): ?>
        <div class="jvf-item mb-4"
             data-year="<?php echo esc_attr($it['year']); ?>"
             data-volume="<?php echo esc_attr($it['volume']); ?>"
             data-issue="<?php echo esc_attr($it['issue']); ?>"
             data-title="<?php echo esc_attr($it['title']); ?>">
          <div class="flex gap-3 items-start">
            <?php if ($it['thumb']): ?>
              <img src="<?php echo esc_url($it['thumb']); ?>" alt="" class="jvf-thumb" style="width:80px;height:auto;border-radius:6px;">
            <?php endif; ?>
            <div>
              <div class="font-semibold">
                <a href="<?php echo esc_url($it['url']); ?>">
                  Volume <?php echo esc_html($it['volume']); ?>, Issue <?php echo esc_html($it['issue']); ?> – <?php echo esc_html($it['title']); ?>
                </a>
              </div>
              <div class="text-sm text-muted">
                <?php echo esc_html($it['month'].' '.$it['year']); ?>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<script>
jQuery(function($){
  var years=[], vols=[], issues=[];
  $('.jvf-item').each(function(){
    var y=$(this).data('year'), v=$(this).data('volume'), i=$(this).data('issue');
    if(y && years.indexOf(y)<0) years.push(y);
    if(v && vols.indexOf(v)<0) vols.push(v);
    if(i && issues.indexOf(i)<0) issues.push(i);
  });
  years.sort().reverse();
  vols.sort(function(a,b){return a-b;});
  issues.sort(function(a,b){return a-b;});
  years.forEach(function(y){ $('#jvf-year').append('<option>'+y+'</option>'); });
  vols.forEach(function(v){ $('#jvf-volume').append('<option>'+v+'</option>'); });
  issues.forEach(function(i){ $('#jvf-issue').append('<option>'+i+'</option>'); });

  function itemMatches($it, fy, fv, fi, q) {
    var y = ($it.data('year')   + '').trim();
    var v = ($it.data('volume') + '').trim();
    var i = ($it.data('issue')  + '').trim();
    var t = (($it.attr('data-title') || $it.text()) + '').toLowerCase();
    if (fy && fy!==y) return false;
    if (fv && fv!==v) return false;
    if (fi && fi!==i) return false;
    if (q && t.indexOf(q)===-1) return false;
    return true;
  }

  function applyFilters(){
    var fy=($('#jvf-year').val()||'').trim();
    var fv=($('#jvf-volume').val()||'').trim();
    var fi=($('#jvf-issue').val()||'').trim();
    var q =($('#jvf-search').val()||'').toLowerCase().trim();

    $('.jvf-item').each(function(){
      var $it=$(this);
      var match=itemMatches($it,fy,fv,fi,q);
      $it.toggleClass('is-match',match).toggle(match);
    });

    $('.jvf-year-group').each(function(){
      var any = $(this).find('.jvf-item.is-match').length>0;
      $(this).toggle(any);
    });
  }

  $('#jvf-year,#jvf-volume,#jvf-issue').on('change', applyFilters);
  $('#jvf-search').on('input', applyFilters);
  $('#jvf-reset').on('click', function(){
    $('#jvf-year').val('');
    $('#jvf-volume').val('');
    $('#jvf-issue').val('');
    $('#jvf-search').val('');
    applyFilters();
  });

  applyFilters();
});
</script>
<?php
    return ob_get_clean();
});

/** VC mapping */
add_action('init', function(){
    if (!function_exists('vc_map')) return;

    // Cards
    vc_map([
        'name'        => 'Journal Sections Cards',
        'base'        => 'journal_sections_cards',
        'description' => 'Hierarchical sections with abstract title, citations, and entry URLs.',
        'category'    => 'Content',
        'icon'        => 'dashicons-screenoptions',
        'params'      => [
            ['type'=>'dropdown','heading'=>'Columns','param_name'=>'columns','value'=>['1','2','3','4','5','6'],'std'=>'3'],
            ['type'=>'textfield','heading'=>'Sections (slugs)','param_name'=>'sections','value'=>''],
            ['type'=>'dropdown','heading'=>'Show empty','param_name'=>'show_empty','value'=>['No'=>'no','Yes'=>'yes'],'std'=>'no'], // retained, but ignored
        ],
    ]);

    // Detail
    vc_map([
        'name'        => 'Journal Section Detail',
        'base'        => 'journal_section_detail',
        'description' => 'Shows a single entry (by abstract title slug) or a section summary (by section slug).',
        'category'    => 'Content',
        'icon'        => 'dashicons-media-text',
        'params'      => [
            ['type'=>'textfield','heading'=>'Section or Entry slug (optional)','param_name'=>'section','value'=>''],
        ],
    ]);

    // Flipbook
    vc_map([
        'name'        => 'Journal Flipbook (DFlip)',
        'base'        => 'journal_section_flipbook',
        'description' => 'Embed flipbook for entry or full issue.',
        'category'    => 'Content',
        'icon'        => 'dashicons-book',
        'params'      => [
            ['type'=>'textfield','heading'=>'Section slug (optional)','param_name'=>'section','value'=>''],
            ['type'=>'dropdown','heading'=>'Source','param_name'=>'source','value'=>[
                'Auto (Entry then Issue)'=>'auto',
                'Entry PDF'=>'entry',
                'Full Issue PDF'=>'issue'
            ],'std'=>'auto'],
            ['type'=>'textfield','heading'=>'Entry index','param_name'=>'entry_index','value'=>'1'],
            ['type'=>'textfield','heading'=>'Extra attributes','param_name'=>'extra','value'=>''],
        ],
    ]);

    // Volume list
    vc_map([
        'name'        => 'Journal Volume List',
        'base'        => 'journal_volume_list',
        'description' => 'List journals grouped by Year/Volume with live filters + Reset.',
        'category'    => 'Content',
        'icon'        => 'dashicons-archive',
        'params'      => [
            ['type'=>'dropdown','heading'=>'Columns','param_name'=>'cols','value'=>['1','2','3','4'],'std'=>'1'],
        ],
    ]);
});
