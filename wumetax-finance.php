<?php
/**
 * Plugin Name: WUMETAX Finance
 * Plugin URI: https://github.com/jimmy-is-me/finance
 * Description: 家庭與公司共用收支、信用卡、報表和成員管理的獨立財務 Web App。
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: WUMETAX 末特數位科技
 * Author URI: https://wumetax.com/
 * License: GPL-2.0-or-later
 * Text Domain: wumetax-finance
 */
if (!defined('ABSPATH')) { exit; }

define('WFF_VERSION', '1.0.0');
define('WFF_PATH', plugin_dir_path(__FILE__));
define('WFF_URL', plugin_dir_url(__FILE__));

require_once WFF_PATH . 'includes/class-wff-storage.php';
require_once WFF_PATH . 'includes/class-wff-api.php';

register_activation_hook(__FILE__, function () {
    WFF_Storage::install();
    $page_id = (int) get_option('wff_page_id');
    if (!$page_id || get_post_status($page_id) === false) {
        $page_id = wp_insert_post(array(
            'post_title' => '家庭財務管理',
            'post_name' => 'family-finance',
            'post_content' => '[wumetax_family_finance]',
            'post_type' => 'page',
            'post_status' => 'publish',
            'comment_status' => 'closed',
        ));
        if (!is_wp_error($page_id)) { update_option('wff_page_id', (int) $page_id); }
    }
});

add_action('plugins_loaded', function () {
    if (get_option('wff_db_version') !== WFF_VERSION) { WFF_Storage::install(); }
});
add_action('rest_api_init', array('WFF_API', 'register'));
add_shortcode('wumetax_family_finance', function () {
    return '<div id="wff-app"><p>載入家庭財務管理中…</p></div>';
});
add_action('wp_enqueue_scripts', function () {
    $page_id = (int) get_option('wff_page_id');
    if (!$page_id || !is_page($page_id)) { return; }
    wp_enqueue_style('wff-app', WFF_URL . 'assets/css/app.css', array(), WFF_VERSION);
    wp_enqueue_script('wff-app', WFF_URL . 'assets/js/app.js', array(), WFF_VERSION, true);
    wp_localize_script('wff-app', 'WFF_CONFIG', array(
        'rest' => esc_url_raw(rest_url('wff/v1/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'login' => wp_login_url(isset($_GET['wff_invite']) ? add_query_arg('wff_invite', sanitize_text_field(wp_unslash($_GET['wff_invite'])), get_permalink($page_id)) : get_permalink($page_id)),
        'logout' => wp_logout_url(get_permalink($page_id)),
        'home' => get_permalink($page_id),
        'user' => is_user_logged_in() ? array(
            'id' => get_current_user_id(),
            'name' => wp_get_current_user()->display_name,
            'email' => wp_get_current_user()->user_email,
        ) : null,
    ));
});
add_action('template_redirect', function () {
    $page_id = (int) get_option('wff_page_id');
    if ($page_id && is_page($page_id)) {
        status_header(200);
        include WFF_PATH . 'templates/app.php';
        exit;
    }
});
add_filter('show_admin_bar', function ($show) {
    return ((int) get_option('wff_page_id') && is_page((int) get_option('wff_page_id'))) ? false : $show;
});
