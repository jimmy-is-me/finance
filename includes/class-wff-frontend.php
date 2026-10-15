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
        add_action('admin_post_nopriv_wff_forgot', array(__CLASS__, 'forgot_password'));
        add_action('admin_post_wff_forgot', array(__CLASS__, 'forgot_password'));
        add_action('admin_post_nopriv_wff_reset', array(__CLASS__, 'reset_password'));
        add_action('admin_post_wff_reset', array(__CLASS__, 'reset_password'));
        add_action('admin_post_nopriv_wff_register', array(__CLASS__, 'register_user'));
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
        add_option('wff_registration_enabled', 1);
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
            'forgotNonce' => wp_create_nonce('wff_front_forgot'),
            'resetNonce' => wp_create_nonce('wff_front_reset'),
            'registerNonce' => wp_create_nonce('wff_register_user'),
            'registrationEnabled' => (bool) get_option('wff_registration_enabled', 1),
            'lostPassword' => add_query_arg('wff_action', 'forgot', $redirect),
            'useAjaxLogin' => true,
            'logout' => wp_logout_url($redirect),
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

    private static function auth_target($invite = '') {
        $url = self::app_url();
        if (is_string($invite) && preg_match('/^[a-f0-9]{48}$/', $invite)) {
            $url = add_query_arg('wff_invite', $invite, $url);
        }
        return $url;
    }

    private static function limit_key($type) {
        // Only server connection IP; never trust arbitrary X-Forwarded-For.
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return 'wff_' . $type . '_' . md5($ip);
    }

    /**
     * Shared login for admin-post fallback and REST/AJAX endpoint.
     * Use WordPress authentication hooks, correctly handle HTTPS reverse proxies,
     * and explicitly set the cookie via wp_signon.
     */
    public static function authenticate($username, $password, $remember = false, $nonce = '') {
        if (!wp_verify_nonce((string) $nonce, 'wff_front_login')) {
            return new WP_Error('expired', '登入頁面已過期，請重新整理再試一次', array('status' => 403));
        }
        $username = trim((string) $username);
        $password = (string) $password;
        if ($username === '' || $password === '') {
            return new WP_Error('empty', '請輸入帳號和密碼', array('status' => 400));
        }
        $key = self::limit_key('login');
        if ((int) get_transient($key) >= 10) {
            return new WP_Error('limited', '登入嘗試次數過多，請稍後再試或重設密碼', array('status' => 429));
        }
        $secure = is_ssl() || wp_parse_url(home_url('/'), PHP_URL_SCHEME) === 'https';
        // wp_signon accepts username OR registered email on current WordPress versions.
        $user = wp_signon(array(
            'user_login' => $username,
            'user_password' => $password,
            'remember' => (bool) $remember,
        ), $secure);
        if (is_wp_error($user)) {
            set_transient($key, (int) get_transient($key) + 1, 10 * MINUTE_IN_SECONDS);
            return new WP_Error('invalid_credentials',
                '帳號或密碼不正確，請確認後重試，也可以使用忘記密碼功能',
                array('status' => 401));
        }
        delete_transient($key);
        wp_set_current_user($user->ID);
        return $user;
    }

    public static function login() {
        $invite = sanitize_text_field(wp_unslash($_POST['wff_invite'] ?? ''));
        $target = self::auth_target($invite);
        if (!is_ssl() && wp_parse_url(home_url('/'), PHP_URL_SCHEME) !== 'https') {
            wp_safe_redirect(add_query_arg('wff_login', 'https', $target), 303);
            exit;
        }
        $user = self::authenticate(
            sanitize_text_field(wp_unslash($_POST['log'] ?? '')),
            (string) wp_unslash($_POST['pwd'] ?? ''),
            !empty($_POST['rememberme']),
            sanitize_text_field(wp_unslash($_POST['wff_login_nonce'] ?? ''))
        );
        if (is_wp_error($user)) {
            $reason = $user->get_error_code();
            if (!in_array($reason, array('limited','expired','empty','invalid_credentials'), true)) {
                $reason = 'failed';
            }
            wp_safe_redirect(add_query_arg('wff_login', $reason, $target), 303);
            exit;
        }
        wp_safe_redirect($target, 303);
        exit;
    }

    public static function forgot_password() {
        $invite = sanitize_text_field(wp_unslash($_POST['wff_invite'] ?? ''));
        $back = self::auth_target($invite);
        if (!isset($_POST['wff_forgot_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wff_forgot_nonce'])), 'wff_front_forgot')) {
            wp_safe_redirect(add_query_arg(array('wff_action'=>'forgot','wff_forgot'=>'expired'), $back), 303);
            exit;
        }
        $key = self::limit_key('forgot');
        if ((int) get_transient($key) >= 6) {
            wp_safe_redirect(add_query_arg(array('wff_action'=>'forgot','wff_forgot'=>'limited'), $back), 303);
            exit;
        }
        set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
        $account = trim(sanitize_text_field(wp_unslash($_POST['account'] ?? '')));
        $user = is_email($account) ? get_user_by('email', $account) : get_user_by('login', $account);
        if ($user instanceof WP_User) {
            $token = get_password_reset_key($user);
            if (!is_wp_error($token)) {
                $url = add_query_arg(array(
                    'wff_action' => 'reset',
                    'wff_key' => $token,
                    'wff_user' => $user->user_login,
                ), $back);
                WFF_Mail::password_reset($user, $url);
            }
        }
        // Deliberately don't disclose whether an email belongs to a user.
        wp_safe_redirect(add_query_arg(array('wff_action'=>'forgot','wff_forgot'=>'sent'), $back), 303);
        exit;
    }

    public static function reset_password() {
        $invite = sanitize_text_field(wp_unslash($_POST['wff_invite'] ?? ''));
        $back = self::auth_target($invite);
        $user_login = sanitize_text_field(wp_unslash($_POST['wff_user'] ?? ''));
        $reset_key = sanitize_text_field(wp_unslash($_POST['wff_key'] ?? ''));
        $reset_url = add_query_arg(array(
            'wff_action'=>'reset','wff_user'=>$user_login,'wff_key'=>$reset_key,
        ), $back);
        if (!isset($_POST['wff_reset_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wff_reset_nonce'])), 'wff_front_reset')) {
            wp_safe_redirect(add_query_arg('wff_reset','expired',$reset_url), 303);
            exit;
        }
        $password = (string) wp_unslash($_POST['password'] ?? '');
        $confirm = (string) wp_unslash($_POST['password_confirm'] ?? '');
        if (strlen($password) < 12 || strlen($password) > 128 || $password !== $confirm) {
            wp_safe_redirect(add_query_arg('wff_reset','mismatch',$reset_url), 303);
            exit;
        }
        $user = check_password_reset_key($reset_key, $user_login);
        if (is_wp_error($user)) {
            wp_safe_redirect(add_query_arg(array('wff_action'=>'forgot','wff_reset'=>'invalid'),$back), 303);
            exit;
        }
        reset_password($user, $password);
        wp_safe_redirect(add_query_arg('wff_reset','success',$back), 303);
        exit;
    }

    /**
     * Public signup requests a one-time password setup email; no password is
     * sent via email or stored from a self-service form, and no plan/payment
     * provisioning occurs.
     */
    public static function register_user() {
        $back = self::app_url();
        if (!get_option('wff_registration_enabled', 1)) {
            wp_safe_redirect(add_query_arg('wff_signup', 'closed', $back));
            exit;
        }
        if (!isset($_POST['wff_register_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wff_register_nonce'])), 'wff_register_user')) {
            wp_safe_redirect(add_query_arg('wff_signup', 'expired', $back));
            exit;
        }
        if (!empty($_POST['fax_number'])) { // silent honeypot
            wp_safe_redirect(add_query_arg('wff_signup', 'sent', $back));
            exit;
        }
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'wff_signup_' . hash('sha256', $ip);
        $hits = (int) get_transient($key);
        if ($hits >= 5) {
            wp_safe_redirect(add_query_arg('wff_signup', 'limited', $back));
            exit;
        }
        set_transient($key, $hits + 1, HOUR_IN_SECONDS);
        $username = sanitize_user(wp_unslash($_POST['username'] ?? ''), true);
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (strlen($username) < 3 || strlen($username) > 50 || !validate_username($username)
            || !is_email($email) || username_exists($username) || email_exists($email)) {
            wp_safe_redirect(add_query_arg('wff_signup', 'invalid', $back));
            exit;
        }
        $uid = wp_insert_user(array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => wp_generate_password(32, true, true),
            'display_name' => $username,
            'role' => 'subscriber',
        ));
        if (is_wp_error($uid)) {
            wp_safe_redirect(add_query_arg('wff_signup', 'failed', $back));
            exit;
        }
        $new_user = get_userdata($uid);
        $key = $new_user ? get_password_reset_key($new_user) : new WP_Error('missing_user', '帳號不存在');
        if (is_wp_error($key)) {
            wp_safe_redirect(add_query_arg('wff_signup', 'mail_failed', $back), 303);
            exit;
        }
        $setup_url = add_query_arg(array(
            'wff_action' => 'reset', 'wff_key' => $key, 'wff_user' => $new_user->user_login,
        ), $back);
        if (!WFF_Mail::registration($new_user, $setup_url)) {
            wp_safe_redirect(add_query_arg('wff_signup', 'mail_failed', $back), 303);
            exit;
        }
        wp_safe_redirect(add_query_arg('wff_signup', 'sent', $back));
        exit;
    }

}
