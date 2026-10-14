<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Finance front controller. Mirrors the pet-management "home mode" model,
 * without mutating WordPress Reading Settings or hijacking other pages.
 */
final class WFF_Frontend {
    public static function home_mode() {
        return (bool) get_option('wff_home_mode', 1);
    }

    public static function page_id() {
        return (int) get_option('wff_page_id');
    }

    public static function app_url() {
        if (self::home_mode()) { return home_url('/'); }
        $page_id = self::page_id();
        return $page_id && get_post_status($page_id) === 'publish'
            ? get_permalink($page_id) : home_url('/family-finance/');
    }

    public static function is_app_request() {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) { return false; }
        if (self::home_mode() && is_front_page()) { return true; }
        $page_id = self::page_id();
        return $page_id > 0 && is_page($page_id);
    }

    public static function initialize() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('template_redirect', array(__CLASS__, 'render'), 0);
        add_action('admin_post_nopriv_wff_login', array(__CLASS__, 'login'));
        add_action('admin_post_wff_login', array(__CLASS__, 'login'));
        add_filter('show_admin_bar', array(__CLASS__, 'hide_admin_bar'));
        add_filter('login_redirect', array(__CLASS__, 'login_redirect'), 10, 3);
        add_action('admin_init', array(__CLASS__, 'redirect_members'));
        add_filter('rest_post_dispatch', array(__CLASS__, 'rest_private_headers'), 10, 3);
    }

    public static function ensure_page() {
        $page_id = self::page_id();
        if ($page_id && get_post_status($page_id) !== false) { return $page_id; }
        $existing = get_page_by_path('family-finance');
        if ($existing && $existing->post_type === 'page') {
            $page_id = (int) $existing->ID;
        } else {
            $page_id = wp_insert_post(array(
                'post_title' => '家庭財務管理',
                'post_name' => 'family-finance',
                'post_content' => '[wumetax_family_finance]',
                'post_type' => 'page',
                'post_status' => 'publish',
                'comment_status' => 'closed',
            ));
        }
        if (!is_wp_error($page_id) && $page_id) {
            update_option('wff_page_id', (int) $page_id);
            return (int) $page_id;
        }
        return 0;
    }

    public static function activate() {
        WFF_Storage::install();
        self::ensure_page();
        add_option('wff_home_mode', 1);
        add_option('wff_redirect_members', 1);
        add_option('wff_registration_notice', 1);
    }

    public static function enqueue() {
        if (!self::is_app_request()) { return; }
        wp_enqueue_style('wff-app', WFF_URL . 'assets/css/app.css', array(), WFF_VERSION);
        wp_enqueue_script('wff-app', WFF_URL . 'assets/js/app.js', array(), WFF_VERSION, true);
        $invite = isset($_GET['wff_invite']) ? sanitize_text_field(wp_unslash($_GET['wff_invite'])) : '';
        $redirect = self::app_url();
        if ($invite && preg_match('/^[a-f0-9]{48}$/', $invite)) {
            $redirect = add_query_arg('wff_invite', $invite, $redirect);
        }
        wp_localize_script('wff-app', 'WFF_CONFIG', array(
            'rest' => esc_url_raw(rest_url('wff/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'login' => $redirect,
            'loginAction' => admin_url('admin-post.php'),
            'loginNonce' => wp_create_nonce('wff_front_login'),
            'lostPassword' => wp_lostpassword_url($redirect),
            'logout' => wp_logout_url(self::app_url()),
            'home' => self::app_url(),
            'user' => is_user_logged_in() ? array(
                'id' => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
                'email' => wp_get_current_user()->user_email,
            ) : null,
        ));
    }

    public static function render() {
        if (!self::is_app_request()) { return; }
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (!defined('DONOTCACHEDB')) { define('DONOTCACHEDB', true); }
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate', true);
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        status_header(200);
        include WFF_PATH . 'templates/app.php';
        exit;
    }

    public static function hide_admin_bar($show) {
        return self::is_app_request() ? false : $show;
    }

    public static function login_redirect($redirect, $requested, $user) {
        if ($user instanceof WP_User && !user_can($user, 'edit_posts') && get_option('wff_redirect_members', 1)) {
            return self::app_url();
        }
        return $redirect;
    }

    public static function redirect_members() {
        if (!get_option('wff_redirect_members', 1) || !is_user_logged_in()
            || current_user_can('edit_posts') || wp_doing_ajax()
            || (defined('DOING_CRON') && DOING_CRON)) { return; }
        $pagenow = $GLOBALS['pagenow'] ?? '';
        if (in_array($pagenow, array('admin-post.php', 'admin-ajax.php', 'async-upload.php'), true)) { return; }
        wp_safe_redirect(self::app_url());
        exit;
    }

    public static function rest_private_headers($response, $server, $request) {
        if (strpos($request->get_route(), '/wff/v1/') === 0) {
            $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate');
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        return $response;
    }

    public static function login() {
        if (!isset($_POST['wff_login_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wff_login_nonce'])), 'wff_front_login')) {
            wp_safe_redirect(add_query_arg('wff_login', 'expired', self::app_url()));
            exit;
        }
        if (!is_ssl() && wp_parse_url(home_url('/'), PHP_URL_SCHEME) !== 'https') {
            wp_die('登入需要 HTTPS，請先啟用 SSL 安全連線。', '安全性檢查', array('response' => 403));
        }
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'wff_login_limit_' . hash('sha256', $ip);
        $failures = (int) get_transient($key);
        if ($failures >= 10) {
            wp_safe_redirect(add_query_arg('wff_login', 'limited', self::app_url()));
            exit;
        }
        $username = sanitize_text_field(wp_unslash($_POST['log'] ?? ''));
        $password = (string) wp_unslash($_POST['pwd'] ?? '');
        $user = wp_signon(array(
            'user_login' => $username,
            'user_password' => $password,
            'remember' => !empty($_POST['rememberme']),
        ), is_ssl());
        $invite = sanitize_text_field(wp_unslash($_POST['wff_invite'] ?? ''));
        $return = self::app_url();
        if (preg_match('/^[a-f0-9]{48}$/', $invite)) {
            $return = add_query_arg('wff_invite', $invite, $return);
        }
        if (is_wp_error($user)) {
            set_transient($key, $failures + 1, 10 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('wff_login', 'failed', $return));
            exit;
        }
        delete_transient($key);
        wp_set_current_user($user->ID);
        wp_safe_redirect($return);
        exit;
    }
}
