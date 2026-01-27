<?php
if (!defined('ABSPATH')) exit;

function emwas_register_cpt(){
    register_post_type('journal', [
        'labels' => [
            'name'          => 'Journals',
            'singular_name' => 'Journal',
            'menu_name'     => 'Journals',
            'add_new_item'  => 'Add New Journal',
            'edit_item'     => 'Edit Journal',
        ],
        'public'       => true,
        'has_archive'  => true,
        'rewrite'      => ['slug'=>'journal','with_front'=>false],
        'menu_icon'    => 'dashicons-media-document',
        'supports'     => ['title','editor','thumbnail','excerpt'],
        'show_in_rest' => true,
        'map_meta_cap' => true,
        'menu_position'=> 21,
    ]);
}
add_action('init','emwas_register_cpt');

/** Issue meta box (Volume, Issue, Month/Year, Full Issue upload) */
add_action('add_meta_boxes', function(){
    add_meta_box('emwas_issue_meta','Issue Info','emwas_issue_meta_box','journal','side','high');
});

function emwas_issue_meta_box($post){
    wp_nonce_field('emwas_issue_save','emwas_issue_nonce');
    $volume = get_post_meta($post->ID,'_emwas_volume',true);
    $issue  = get_post_meta($post->ID,'_emwas_issue',true);
    $my     = get_post_meta($post->ID,'_emwas_month_year',true); // YYYY-MM
    $fileId = (int)get_post_meta($post->ID,'_emwas_full_issue_file_id',true);
    $fileUrl= $fileId ? wp_get_attachment_url($fileId) : '';
    ?>
    <p><label><strong>Volume</strong><br>
        <input type="number" name="emwas_volume" value="<?php echo esc_attr($volume); ?>" min="1" style="width:100%"></label></p>
    <p><label><strong>Issue</strong><br>
        <input type="number" name="emwas_issue" value="<?php echo esc_attr($issue); ?>" min="1" style="width:100%"></label></p>
    <p><label><strong>Month &amp; Year</strong><br>
        <input type="month" name="emwas_month_year" value="<?php echo esc_attr($my); ?>" style="width:100%"></label></p>
    <p><label><strong>Full Issue (PDF upload)</strong></label><br>
        <input type="hidden" id="emwas_full_issue_file_id" name="emwas_full_issue_file_id" value="<?php echo (int)$fileId; ?>">
        <input type="text" id="emwas_full_issue_file_url" value="<?php echo esc_url($fileUrl); ?>" readonly style="width:100%;margin-bottom:6px">
        <button type="button" class="button emwas-issue-upload" data-target-id="emwas_full_issue_file_id" data-target-url="emwas_full_issue_file_url">Upload/Select PDF</button>
        <button type="button" class="button emwas-issue-clear"  data-target-id="emwas_full_issue_file_id" data-target-url="emwas_full_issue_file_url">Clear</button>
    </p>
    <?php
}

add_action('save_post_journal', function($post_id){
    if (!isset($_POST['emwas_issue_nonce']) || !wp_verify_nonce($_POST['emwas_issue_nonce'],'emwas_issue_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post',$post_id)) return;

    update_post_meta($post_id,'_emwas_volume', isset($_POST['emwas_volume'])?(int)$_POST['emwas_volume']:'');
    update_post_meta($post_id,'_emwas_issue',  isset($_POST['emwas_issue'])?(int)$_POST['emwas_issue']:'');
    $my = isset($_POST['emwas_month_year']) ? preg_replace('/^(\d{4})-(\d{2}).*$/','$1-$2', $_POST['emwas_month_year']) : '';
    update_post_meta($post_id,'_emwas_month_year', $my);
    update_post_meta($post_id,'_emwas_full_issue_file_id', isset($_POST['emwas_full_issue_file_id'])?(int)$_POST['emwas_full_issue_file_id']:'');
});
