<?php
/**
 * Plugin Name: WUMETAX Finance
 * Plugin URI: https://github.com/jimmy-is-me/finance
 * Description: 家庭與公司共同財務管理：獨立首頁、前台登入、家庭共用記帳、信用卡、分析報表及完整後台管理。
 * Version: 1.2.9
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: WUMETAX 末特數位科技
 * Author URI: https://wumetax.com/
 * License: GPL-2.0-or-later
 * Text Domain: wumetax-finance
 */
if (!defined('ABSPATH')) { exit; }

define('WFF_VERSION', '1.2.9');
define('WFF_PATH', plugin_dir_path(__FILE__));
define('WFF_URL', plugin_dir_url(__FILE__));

require_once WFF_PATH . 'includes/class-wff-storage.php';
require_once WFF_PATH . 'includes/class-wff-private-receipts.php';
require_once WFF_PATH . 'includes/class-wff-api.php';
require_once WFF_PATH . 'includes/class-wff-insights.php';
require_once WFF_PATH . 'includes/class-wff-loans.php';
require_once WFF_PATH . 'includes/class-wff-mail.php';
require_once WFF_PATH . 'includes/class-wff-frontend.php';
require_once WFF_PATH . 'includes/class-wff-admin.php';

register_activation_hook(__FILE__, array('WFF_Frontend', 'activate'));

add_action('plugins_loaded', function () {
    if (get_option('wff_db_version') !== WFF_VERSION) {
        WFF_Storage::install();
    }
    // Upgrading an existing installation preserves its records and page ID.
    // WordPress handles the real static/blog front-page setting separately.
    add_option('wff_home_mode', 1);
    add_option('wff_redirect_members', 1);
});

WFF_Private_Receipts::init();
WFF_Frontend::initialize();
WFF_Admin::init();

add_action('rest_api_init', array('WFF_API', 'register'));
add_action('rest_api_init', array('WFF_Loans', 'register'));
add_shortcode('wumetax_family_finance', function () {
    return '<div id="wff-app"><p>載入家庭財務管理中…</p></div>';
});
