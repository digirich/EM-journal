// EMWAS admin.js v4 – pages + live citation preview
jQuery(function($){
  const ajaxUrl = (window.EMWAS && EMWAS.ajaxUrl) || '';
  const nonce   = (window.EMWAS && EMWAS.nonce)   || '';
  const types   = (window.EMWAS && Array.isArray(EMWAS.typesHier)) ? EMWAS.typesHier : [];

  function esc(s){ return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

  /* ---------- Section Types (unchanged parent/child add/remove) ---------- */
  $('#emwas-add-type').on('click', function(){
    const $tbody = $('#emwas-types-table tbody');
    const i = $tbody.find('> tr.emwas-parent-row').length;
    $tbody.append(
      '<tr class="emwas-parent-row">'
        + '<td><input type="text" name="types['+i+'][slug]" class="regular-text"></td>'
        + '<td><input type="text" name="types['+i+'][label]" class="regular-text"></td>'
        + '<td>'
          + '<table class="widefat emwas-children"><thead><tr><th style="width:30%">Slug</th><th>Label</th><th style="width:80px">Remove</th></tr></thead><tbody></tbody></table>'
          + '<p><button type="button" class="button emwas-add-child" data-parent="'+i+'">Add Sub-type</button></p>'
        + '</td>'
        + '<td><button type="button" class="button emwas-remove-row">Remove</button></td>'
      + '</tr>'
    );
  });
  $(document).on('click','.emwas-add-child', function(){
    const idx = parseInt($(this).data('parent'),10);
    const $tb = $(this).closest('td').find('.emwas-children tbody');
    const k = $tb.find('> tr').length;
    $tb.append(
      '<tr>'
        + '<td><input type="text" name="types['+idx+'][children]['+k+'][slug]" class="regular-text"></td>'
        + '<td><input type="text" name="types['+idx+'][children]['+k+'][label]" class="regular-text"></td>'
        + '<td><button type="button" class="button emwas-remove-row">Remove</button></td>'
      + '</tr>'
    );
  });
  $(document).on('click','.emwas-remove-row', function(e){ e.preventDefault(); $(this).closest('tr').remove(); });

  /* ---------- Build section select with optgroups ---------- */
  function buildSectionOptions() {
    let html = '';
    types.forEach(p => {
      const pslug = esc(p.slug||''); const plab = esc(p.label||'');
      const kids = Array.isArray(p.children) ? p.children : [];
      if (kids.length){
        html += '<optgroup label="'+plab+'">';
        html += '<option value="'+pslug+'">'+plab+' (All)</option>';
        kids.forEach(ch=>{
          html += '<option value="'+esc(ch.slug||'')+'">'+esc(ch.label||'')+'</option>';
        });
        html += '</optgroup>';
      } else {
        html += '<option value="'+pslug+'">'+plab+'</option>';
      }
    });
    return html;
  }

  /* ---------- Add entry row ---------- */
  function nameAt(i, field){ return 'emwas_entries['+i+']['+field+']'; }
  function buildEntryRowHtml(i) {
    const opts = buildSectionOptions();
    return ''
    + '<tr class="emwas-entry">'
      + '<td>'
        + '<select name="'+nameAt(i,'section')+'" class="widefat">'+opts+'</select>'
        + '<div class="emwas-citation small text-muted" data-citation></div>'
      + '</td>'
      + '<td>'
        + '<input type="text" name="'+nameAt(i,'abstract_title')+'" class="widefat" placeholder="Enter abstract title">'
      + '</td>'
      + '<td>'
        + '<div class="emwas-author-select" data-cpt="journal_author">'
          + '<input type="text" class="widefat emwas-author-search" placeholder="Search authors (type 2+ chars)">'
          + '<div class="emwas-author-suggest"></div>'
          + '<div class="emwas-author-tags"></div>'
        + '</div>'
        + '<small>Authors are looked up from <strong>journal_author</strong>.</small>'
      + '</td>'
      + '<td><input type="text" name="'+nameAt(i,'first_page')+'" class="widefat emwas-first-page" placeholder="e.g. 2"></td>'
      + '<td><input type="text" name="'+nameAt(i,'last_page')+'" class="widefat emwas-last-page" placeholder="e.g. 4 (optional)"></td>'
      + '<td><input type="url"  name="'+nameAt(i,'doi_url')+'" class="widefat" placeholder="https://doi.org/... (optional)"></td>'
      + '<td><input type="number" name="'+nameAt(i,'order')+'" class="small-text" value="'+(i+1)+'"></td>'
      + '<td>'
        + '<button class="button emwas-remove-row" type="button">Remove</button>'
        + '<div style="margin-top:8px">'
          + '<input type="hidden" name="'+nameAt(i,'file_id')+'" value="" class="emwas-file-id">'
          + '<input type="text" value="" class="widefat emwas-file-url" readonly placeholder="Selected file URL">'
          + '<button type="button" class="button emwas-entry-upload">Upload/Select PDF</button> '
          + '<button type="button" class="button emwas-entry-clear">Clear</button>'
        + '</div>'
      + '</td>'
    + '</tr>'
    + '<tr>'
      + '<td colspan="8">'
        + '<label><strong>Abstract</strong></label>'
        + '<textarea name="'+nameAt(i,'content')+'" rows="6" class="widefat"></textarea>'
      + '</td>'
    + '</tr>';
  }


  $('#emwas-add-entry-v2').off('click').on('click', function(){
    const $tbl  = $('.emwas-entries');
    const $body = $('#emwas-entries-body');
    let i = parseInt($tbl.data('next-index') || 0, 10);
    if (Number.isNaN(i)) i = 0;
    $body.append( buildEntryRowHtml(i) );
    $tbl.data('next-index', i+1);
  });

  /* ---------- Media pickers ---------- */
  $(document).on('click','.emwas-entry-upload', function(e){
    e.preventDefault();
    const $td = $(this).closest('td');
    const $idInput  = $td.find('.emwas-file-id');
    const $urlInput = $td.find('.emwas-file-url');
    const frame = wp.media({ title:'Select PDF', button:{ text:'Use this file' }, multiple:false });
    frame.on('select', function(){
      const att = frame.state().get('selection').first().toJSON();
      $idInput.val(att.id); $urlInput.val(att.url);
    });
    frame.open();
  });
  $(document).on('click','.emwas-entry-clear', function(e){
    e.preventDefault();
    const $td = $(this).closest('td');
    $td.find('.emwas-file-id').val(''); $td.find('.emwas-file-url').val('');
  });

  /* ---------- Author lookup (ACF-like) ---------- */
  function debounce(fn, wait){ let t; return function(){ const ctx=this,args=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx,args); }, wait||250); }; }
  $(document).on('input', '.emwas-author-search', debounce(function(){
    const $wrap = $(this).closest('.emwas-author-select');
    const q = $(this).val().trim();
    const $box = $wrap.find('.emwas-author-suggest');
    if (q.length < 2 || !ajaxUrl || !nonce) { $box.hide().empty(); return; }
    $.getJSON(ajaxUrl, { action:'emwas_search_authors', _ajax_nonce:nonce, q:q }, function(resp){
      if (!resp || !resp.success || !resp.data || !Array.isArray(resp.data.items)) { $box.hide().empty(); return; }
      const items = resp.data.items;
      if (!items.length) { $box.hide().empty(); return; }
      const html = items.map(it => '<div class="item" data-id="'+esc(it.id)+'" data-text="'+esc(it.text)+'">'+esc(it.text)+'</div>').join('');
      $box.html(html).show();
    });
  }, 300));
  $(document).on('click', '.emwas-author-suggest .item', function(){
    const $it   = $(this);
    const id    = parseInt($it.data('id'),10);
    const text  = $it.data('text')+'';
    const $wrap = $it.closest('.emwas-author-select');
    const $tags = $wrap.find('.emwas-author-tags');
    // derive index from closest tr name
    const $rowFirstInput = $wrap.closest('tr').find('input[name*="[first_page]"]').first();
    const match = $rowFirstInput.attr('name').match(/^emwas_entries\[(\d+)\]/);
    const i = match ? parseInt(match[1],10) : 0;
    if ($tags.find('.emwas-tag[data-id="'+id+'"]').length) return;
    $tags.append(
      '<span class="emwas-tag" data-id="'+id+'">'+esc(text)+'<button type="button" class="emwas-tag-x" aria-label="Remove">×</button>'
      + '<input type="hidden" name="emwas_entries['+i+'][author_ids][]" value="'+id+'"></span>'
    );
    $wrap.find('.emwas-author-suggest').hide().empty();
    $wrap.find('.emwas-author-search').val('');
  });
  $(document).on('click', '.emwas-tag .emwas-tag-x', function(){ $(this).closest('.emwas-tag').remove(); });
  $(document).on('click', function(e){ if (!$(e.target).closest('.emwas-author-select').length) $('.emwas-author-suggest').hide().empty(); });

  /* ---------- Live citation preview ---------- */
  function computeYear(){
    const val = $('input[name="emwas_month_year"]').val() || '';
    const m = val.match(/^(\d{4})/);
    return m ? parseInt(m[1],10) : (function(){
      const alt = $('#timestampdiv').length ? $('#aa').val() : ''; // WP publish box inputs
      return alt ? parseInt(alt,10) : (new Date()).getFullYear();
    })();
  }
  function computeCitation(first, last){
    const vol = parseInt($('input[name="emwas_volume"]').val() || 0, 10);
    const iss = parseInt($('input[name="emwas_issue"]').val()  || 0, 10);
    const yr  = computeYear();
    if (!yr || !vol || !iss || !first) return '';
    const pages = (!last || String(last)===String(first)) ? String(first) : (String(first) + '-' + String(last));
    return 'Medical Writing. ' + yr + ';' + vol + '(' + iss + '):' + pages;
  }
  function updateRowCitation($row){
    const first = $row.find('.emwas-first-page').val().trim();
    const last  = $row.find('.emwas-last-page').val().trim();
    const s = computeCitation(first, last);
    $row.find('[data-citation]').text(s);
  }
  $(document).on('input change', '.emwas-first-page, .emwas-last-page, input[name="emwas_volume"], input[name="emwas_issue"], input[name="emwas_month_year"]', function(){
    $('.emwas-entry').each(function(){ updateRowCitation($(this)); });
  });
  // initial
  $('.emwas-entry').each(function(){ updateRowCitation($(this)); });

  /* ---------- CSV Import (AJAX progress) ---------- */
  const $importForm = $('#emwas-import-form');
  if ($importForm.length && ajaxUrl) {
    let submitMode = 'import';
    const $progress = $('#emwas-import-progress');
    const $bar = $progress.find('.emwas-progress-bar span');
    const $status = $progress.find('.emwas-import-status');
    const $log = $progress.find('.emwas-import-log');
    const importBatch = (window.EMWAS && parseInt(EMWAS.importBatch || 0, 10)) || 50;
    const importLogMax = (window.EMWAS && parseInt(EMWAS.importLogMax || 0, 10)) || 200;

    function addLogLine(text){
      if (!$log.length) return;
      const line = $('<div class="line"></div>').text(text);
      $log.append(line);
      const lines = $log.find('.line');
      if (lines.length > importLogMax) {
        lines.slice(0, lines.length - importLogMax).remove();
      }
      $log.scrollTop($log[0].scrollHeight);
    }

    function setStatusFromData(data){
      const c = data.counts || {};
      const parts = [
        'Rows: ' + (c.rows || 0),
        'Journals: ' + (c.journals_created || 0),
        'Entries: ' + (c.entries_added || 0),
        'Authors matched: ' + (c.authors_matched || 0),
        'Authors created: ' + (c.authors_created || 0),
        'Missing: ' + (c.authors_missing || 0)
      ];
      if (typeof data.percent === 'number') {
        parts.push('Progress: ' + data.percent + '%');
      }
      $status.text(parts.join(' | '));
    }

    function updateProgressBar(percent){
      if (!$bar.length) return;
      const pct = Math.max(0, Math.min(100, parseFloat(percent || 0)));
      $bar.css('width', pct + '%');
    }

    $importForm.on('click', 'button', function(){
      const name = $(this).attr('name') || '';
      submitMode = (name === 'emwas_preview') ? 'preview' : 'import';
    });

    $importForm.on('submit', function(e){
      if (submitMode === 'preview') return;
      if (!ajaxUrl) return;
      e.preventDefault();

      const importNonce = $importForm.find('input[name="emwas_import_nonce"]').val() || '';
      const token = $importForm.find('input[name="token"]').val() || '';
      if (!importNonce || !token) {
        $status.text('Missing import token or nonce. Please reload the page.');
        return;
      }

      $progress.show();
      $status.text('Starting import…');
      $log.empty();

      const formData = $importForm.serialize();

      // Disable form to prevent double-submit
      $importForm.find('select, input, button').prop('disabled', true);

      $.post(ajaxUrl, formData + '&action=emwas_import_init', function(resp){
        if (!resp || !resp.success) {
          const msg = resp && resp.data && resp.data.message ? resp.data.message : 'Import init failed.';
          $status.text(msg);
          addLogLine(msg);
          $importForm.find('select, input, button').prop('disabled', false);
          return;
        }

        setStatusFromData(resp.data || {});
        updateProgressBar(resp.data && resp.data.percent ? resp.data.percent : 0);

        function step(){
          $.post(ajaxUrl, {
            action: 'emwas_import_step',
            token: token,
            batch: importBatch,
            emwas_import_nonce: importNonce
          }, function(stepResp){
            if (!stepResp || !stepResp.success) {
              const msg = stepResp && stepResp.data && stepResp.data.message ? stepResp.data.message : 'Import step failed.';
              $status.text(msg);
              addLogLine(msg);
              $importForm.find('select, input, button').prop('disabled', false);
              return;
            }

            const data = stepResp.data || {};
            setStatusFromData(data);
            updateProgressBar(data.percent || 0);

            if (Array.isArray(data.messages)) {
              data.messages.forEach(function(m){ addLogLine(m); });
            }

            if (data.last && (data.last.journal || data.last.entry)) {
              const last = data.last;
              let line = 'Last: ';
              if (last.journal) line += last.journal;
              if (last.entry) line += (last.journal ? ' | ' : '') + last.entry;
              if (last.section) line += ' | ' + last.section;
              addLogLine(line);
            }

            if (data.done) {
              if (data.message) addLogLine(data.message);
              $status.text('Import complete.');
              updateProgressBar(100);
              $importForm.find('select, input, button').prop('disabled', false);
              return;
            }

            setTimeout(step, 100);
          }, 'json').fail(function(){
            $status.text('Import step failed (network error).');
            $importForm.find('select, input, button').prop('disabled', false);
          });
        }

        step();
      }, 'json').fail(function(){
        $status.text('Import init failed (network error).');
        $importForm.find('select, input, button').prop('disabled', false);
      });
    });
  }

  /* ---------- Issue meta (unchanged) ---------- */
  $(document).on('click','.emwas-issue-upload', function(e){
    e.preventDefault();
    const id  = $(this).data('target-id');
    const url = $(this).data('target-url');
    if (!id || !url) return;
    const frame = wp.media({ title:'Select PDF', button:{ text:'Use this file' }, multiple:false });
    frame.on('select', function(){
      const att = frame.state().get('selection').first().toJSON();
      $('#'+id).val(att.id); $('#'+url).val(att.url);
    });
    frame.open();
  });
  $(document).on('click','.emwas-issue-clear', function(e){
    e.preventDefault();
    const id  = $(this).data('target-id');
    const url = $(this).data('target-url');
    if (!id || !url) return;
    $('#'+id).val(''); $('#'+url).val('');
  });
});
