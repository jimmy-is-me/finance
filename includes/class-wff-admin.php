<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WordPress administrator-only Finance console, modeled after
 * the management dashboard and settings used by pet-management.
 * Subscription, payment gateways, pricing and quotas are intentionally absent.
 */
final class WFF_Admin {
    private static function allow() {
        if (!current_user_can('manage_options')) { wp_die('沒有管理財務網站的權限。'); }
    }

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_wff_settings_save', array(__CLASS__, 'save_settings'));
        add_action('admin_post_wff_household_limit', array(__CLASS__, 'save_household_limit'));
        add_action('admin_post_wff_receipts_migrate', array(__CLASS__, 'manual_migrate_receipts'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('admin_notices', array(__CLASS__, 'notices'));
        add_filter('plugin_action_links_' . plugin_basename(WFF_PATH . 'wumetax-finance.php'), array(__CLASS__, 'action_links'));
    }

    public static function action_links($links) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=wff-settings')) . '">設定</a>');
        return $links;
    }

    public static function menu() {
        add_menu_page('家庭財務管理', '家庭財務', 'manage_options', 'wff-dashboard',
            array(__CLASS__, 'dashboard'), 'dashicons-chart-line', 3);
        add_submenu_page('wff-dashboard', '財務控制台', '控制台', 'manage_options', 'wff-dashboard', array(__CLASS__, 'dashboard'));
        add_submenu_page('wff-dashboard', '網站與登入設定', '網站與登入設定', 'manage_options', 'wff-settings', array(__CLASS__, 'settings'));
        add_submenu_page('wff-dashboard', '家庭與成員', '家庭與成員', 'manage_options', 'wff-households', array(__CLASS__, 'households'));
        add_submenu_page('wff-dashboard', '收支紀錄查詢', '收支紀錄查詢', 'manage_options', 'wff-transactions', array(__CLASS__, 'transactions'));
        add_submenu_page('wff-dashboard', '最近操作紀錄', '最近操作紀錄', 'manage_options', 'wff-activity', array(__CLASS__, 'activity'));
    }

    public static function enqueue($hook) {
        if (strpos((string) $hook, 'wff-') === false) { return; }
        wp_enqueue_style('wff-admin', WFF_URL . 'assets/css/admin.css', array(), WFF_VERSION);
    }

    public static function notices() {
        if (current_user_can('manage_options') && isset($_GET['wff_saved']) && $_GET['wff_saved'] === '1') {
            echo '<div class="notice notice-success is-dismissible"><p>家庭財務設定已儲存。</p></div>';
        }
    }

    private static function intro($heading, $description = '') {
        echo '<div class="wrap wff-admin"><div class="wff-admin-hero"><div><small>WUMETAX / FINANCE</small><h1>'
            . esc_html($heading) . '</h1><p>' . esc_html($description)
            . '</p></div><a class="button button-primary" target="_blank" rel="noopener" href="'
            . esc_url(WFF_Frontend::app_url()) . '">開啟前台 Finance ↗</a></div>';
    }

    private static function finish() {
        echo '<p class="wff-admin-foot">WUMETAX FINANCE ' . esc_html(WFF_VERSION)
            . ' · 財務資料只儲存在本站 WordPress 資料庫 · 本外掛無訂閱功能</p></div>';
    }

    public static function dashboard() {
        self::allow();
        global $wpdb;
        $h = WFF_Storage::table('households');
        $m = WFF_Storage::table('members');
        $t = WFF_Storage::table('transactions');
        $family_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $h");
        $member_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $m");
        $transaction_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        $month = wp_date('Y-m');
        $monthly = $wpdb->get_results($wpdb->prepare(
            "SELECT scope,kind,SUM(amount) AS sum_amount FROM $t WHERE occurred_on LIKE %s
                AND kind IN ('income','expense') GROUP BY scope,kind", $month . '%'), ARRAY_A);
        $totals = array('family_expense' => 0, 'family_income' => 0, 'business_expense' => 0, 'business_income' => 0);
        foreach ($monthly as $x) {
            $key = $x['scope'] . '_' . $x['kind'];
            if (isset($totals[$key])) { $totals[$key] = (float) $x['sum_amount']; }
        }
        self::intro('財務控制台', '檢視整體營運狀態與管理入口。後台資料供網站管理員稽核，家庭成員日常操作請至前台 Finance。');
        echo '<div class="wff-admin-metrics">';
        foreach (array(
            '家庭空間' => number_format_i18n($family_count),
            '家庭成員' => number_format_i18n($member_count),
            '累計記帳筆數' => number_format_i18n($transaction_count),
            '本月家庭支出' => 'NT$ ' . number_format_i18n($totals['family_expense']),
            '本月公司收入' => 'NT$ ' . number_format_i18n($totals['business_income']),
            '本月公司支出' => 'NT$ ' . number_format_i18n($totals['business_expense']),
        ) as $label => $value) {
            echo '<div class="wff-admin-stat"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
        }
        echo '</div><div class="wff-admin-grid"><section class="wff-admin-panel"><h2>管理入口</h2>'
            . '<p>可從後台調整首頁接管、登入與前台頁面等設定。</p>'
            . '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=wff-settings')) . '">網站與登入設定</a> '
            . '<a class="button" href="' . esc_url(admin_url('admin.php?page=wff-households')) . '">家庭與成員</a> '
            . '<a class="button" href="' . esc_url(admin_url('admin.php?page=wff-transactions')) . '">收支紀錄查詢</a></p></section>'
            . '<section class="wff-admin-panel"><h2>上線檢查</h2><ul class="wff-admin-checklist">'
            . '<li>首頁模式：<strong>' . (WFF_Frontend::home_mode() ? '首頁直接進入 Finance' : '使用指定頁面') . '</strong></li>'
            . '<li>SSL 安全連線：<strong>' . (is_ssl() ? '已啟用' : '請確認 HTTPS') . '</strong></li>'
            . '<li>成員權限：家庭擁有者、可編輯、唯讀</li>'
            . '<li>訂閱與金流：<strong>未安裝／無須設定</strong></li>'
            . '<li>快取：請排除前台頁面和 <code>/wp-json/wff/v1/*</code></li>'
            . '<li>備份：定期完整備份 WordPress 資料庫</li></ul></section></div>';
        self::finish();
    }

    public static function settings() {
        self::allow();
        self::intro('網站與登入設定', '和寵物管理一樣，預設將 Finance 顯示於網站首頁；如要保留原有首頁，可在這裡切換。');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wff-admin-panel">'
            . '<input type="hidden" name="action" value="wff_settings_save">';
        wp_nonce_field('wff_settings_save');
        echo '<h2>前台網站</h2><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">首頁呈現</th><td><label><input name="home_mode" type="checkbox" value="1" '
            . checked(WFF_Frontend::home_mode(), true, false)
            . '> 網站首頁直接顯示 Finance</label><p class="description">預設開啟，不改動 WordPress「閱讀」裡的首頁設定。只在啟用時覆蓋網站首頁輸出；不影響文章與其他頁面。</p></td></tr>';
        echo '<tr><th scope="row">獨立前台頁面</th><td>';
        wp_dropdown_pages(array(
            'name' => 'page_id', 'selected' => WFF_Frontend::page_id(),
            'show_option_none' => '— 請選擇 —', 'option_none_value' => '0',
        ));
        echo '<p class="description">首頁模式關閉時，Finance 會使用這個頁面的專屬介面。原頁面預設為 /family-finance/，目前連結：<a href="'
            . esc_url(WFF_Frontend::app_url()) . '" target="_blank" rel="noopener">'
            . esc_html(WFF_Frontend::app_url()) . '</a></p></td></tr>';
        echo '<tr><th scope="row">成員登入</th><td><label><input name="redirect_members" type="checkbox" value="1" '
            . checked((bool) get_option('wff_redirect_members', 1), true, false)
            . '> 一般家庭成員登入後前往 Finance，避免進入 WordPress 後台</label>'
            . '<p class="description">管理員仍可正常使用 WordPress 後台。使用原生 WP 登入的使用者也會導向 Finance。</p></td></tr>';
        echo '<tr><th scope="row">開放免費註冊</th><td><label><input name="registration_enabled" type="checkbox" value="1" '
            . checked((bool) get_option('wff_registration_enabled', 1), true, false)
            . '> 允許訪客在 Finance 首頁申請帳號</label>'
            . '<p class="description">系統透過 WordPress 寄送設定密碼的郵件，首次登入後可建立自己的家庭空間；不需要付款或訂閱。</p></td></tr>';
        echo '</tbody></table><h2>家庭與資料</h2><p>家庭及公司收支對同一家庭所有成員共用，成員的查看／編輯權限由家庭擁有者在前台管理。'
            . '登入後可以建立自己的家庭，或透過邀請加入其他家庭。管理員可在此開啟或關閉免費自助註冊。</p>'
            . '<p>交易及設定儲存在 WordPress 資料庫；收據圖片則儲存在網站公開根目錄外的主機私人目錄，不透過公開媒體庫網址提供。</p>';
        submit_button('儲存設定');
        echo '</form><div class="wff-admin-panel"><h2>環境與安全</h2>'
            . '<p>REST API：<code>' . esc_html(rest_url('wff/v1/')) . '</code></p>'
            . '<p>在 Cloudflare、LiteSpeed Cache、WP Rocket 等快取工具中，請排除首頁（首頁模式開啟時）、指定 Finance 頁面與 Finance REST API，並避免快取登入後的私人資料。</p>'
            . '<p>停用或更新外掛不會清除任何家庭、成員或收支紀錄。如要移轉主機，請備份 WordPress 資料庫。</p></div>';
        $receipt_state = WFF_Private_Receipts::status();
        echo '<div class="wff-admin-panel"><h2>收據圖片私人儲存</h2>'
            . '<p><strong>' . ($receipt_state['available'] ? '私人目錄正常' : '收據上傳已安全停用') . '</strong>：'
            . esc_html($receipt_state['message']) . '</p>'
            . ($receipt_state['available'] ? '<p>伺服器私人路徑：<code>'
                . esc_html($receipt_state['directory']) . '</code></p>' : '')
            . '<p>等待搬移的舊版資料庫收據：<strong>' . (int)$receipt_state['pending'] . '</strong> 張</p>'
            . ($receipt_state['last_error'] ? '<p>上次搬移提示：' . esc_html($receipt_state['last_error']) . '</p>' : '')
            . '<p>自動背景程序每批最多處理 3 張，只有在檔案寫入及驗證成功後才清空資料庫的舊圖片內容。</p>'
            . '<p>若主機目錄結構特殊，請在 <code>wp-config.php</code> 中設定：'
            . '<code>define(&#39;WFF_PRIVATE_RECEIPTS_DIR&#39;, &#39;/home/account/private/finance-receipts&#39;);</code>'
            . '請務必確保此路徑不在任何 Nginx／Apache 虛擬主機的公開根目錄下。</p>'
            . '<p>備份請同時包含 WordPress 資料庫及此私人圖片目錄；只有備份資料庫將無法還原新收據。</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="wff_receipts_migrate">';
        wp_nonce_field('wff_receipts_migrate');
        submit_button('手動搬移下一批（最多 3 張）', 'secondary', 'submit', false);
        echo '</form></div>';
        self::finish();
    }

    public static function manual_migrate_receipts() {
        self::allow();
        check_admin_referer('wff_receipts_migrate');
        WFF_Private_Receipts::migrate_batch();
        wp_safe_redirect(admin_url('admin.php?page=wff-settings'));
        exit;
    }

    public static function save_settings() {
        self::allow();
        check_admin_referer('wff_settings_save');
        update_option('wff_home_mode', !empty($_POST['home_mode']) ? 1 : 0);
        update_option('wff_redirect_members', !empty($_POST['redirect_members']) ? 1 : 0);
        update_option('wff_registration_enabled', !empty($_POST['registration_enabled']) ? 1 : 0);
        $page_id = absint($_POST['page_id'] ?? 0);
        if ($page_id && get_post_type($page_id) === 'page') {
            update_option('wff_page_id', $page_id);
        }
        wp_safe_redirect(admin_url('admin.php?page=wff-settings&wff_saved=1'));
        exit;
    }

    public static function households() {
        self::allow();
        global $wpdb;
        $h = WFF_Storage::table('households');
        $m = WFF_Storage::table('members');
        $t = WFF_Storage::table('transactions');
        $u = $wpdb->users;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $h");
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 25;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT h.id,h.name,h.owner_user_id,h.member_limit,h.created_at,u.display_name,u.user_email,
               (SELECT COUNT(*) FROM $m m WHERE m.household_id=h.id) AS member_count,
               (SELECT COUNT(*) FROM $t t WHERE t.household_id=h.id) AS tx_count
             FROM $h h LEFT JOIN $u u ON u.ID=h.owner_user_id
             ORDER BY h.id DESC LIMIT %d OFFSET %d",
            $per_page, ($page - 1) * $per_page
        ), ARRAY_A);
        self::intro('家庭與成員', '前台的家庭資料獨立隔離，網站管理員可從這裡查看所有家庭及基本營運狀況。');
        echo '<div class="wff-admin-panel"><table class="widefat striped"><thead><tr><th>ID</th><th>家庭空間</th><th>擁有者</th><th>成員</th><th>成員上限</th><th>交易</th><th>建立時間（UTC）</th><th>檢視</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $link = add_query_arg(array('page'=>'wff-transactions', 'household_id'=>(int)$row['id']), admin_url('admin.php'));
            echo '<tr><td>' . (int) $row['id'] . '</td><td><strong>' . esc_html($row['name'])
                . '</strong></td><td>' . esc_html($row['display_name'] ?: '帳號已不存在')
                . '<br><small>' . esc_html($row['user_email']) . '</small></td><td>'
                . (int) $row['member_count'] . '</td><td><form class="wff-admin-limit-form" action="' . esc_url(admin_url('admin-post.php')) . '" method="post">'
                . '<input type="hidden" name="action" value="wff_household_limit">'
                . '<input type="hidden" name="household_id" value="' . (int) $row['id'] . '">'
                . wp_nonce_field('wff_household_limit_' . (int)$row['id'], '_wpnonce', true, false)
                . '<label class="screen-reader-text" for="wff-limit-' . (int)$row['id'] . '">家庭人數上限</label>'
                . '<input id="wff-limit-' . (int)$row['id'] . '" type="number" min="1" max="10000" name="member_limit" value="' . (int)$row['member_limit'] . '" style="width:80px">'
                . '<button type="submit" class="button button-small">儲存</button></form></td><td>' . (int) $row['tx_count']
                . '</td><td>' . esc_html($row['created_at']) . '</td><td><a class="button button-small" href="'
                . esc_url($link) . '">查看收支</a></td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="8">尚未建立家庭空間。</td></tr>'; }
        echo '</tbody></table>';
        self::pagination('wff-households', $page, (int) ceil($total / $per_page));
        echo '</div>';
        self::finish();
    }

    public static function save_household_limit() {
        self::allow();
        $hid = absint($_POST['household_id'] ?? 0);
        check_admin_referer('wff_household_limit_' . $hid);
        $raw = isset($_POST['member_limit']) ? sanitize_text_field(wp_unslash($_POST['member_limit'])) : '';
        if (!$hid || !ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 10000) {
            wp_die('家庭人數上限須介於 1 至 10000 位。', '無效設定', array('response'=>400));
        }
        global $wpdb;
        $h = WFF_Storage::table('households');
        $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM $h WHERE id=%d", $hid));
        if (!$found) { wp_die('找不到家庭空間', '資料不存在', array('response'=>404)); }
        $ok = $wpdb->update($h, array('member_limit'=>(int)$raw), array('id'=>$hid),array('%d'),array('%d'));
        if ($ok === false) { wp_die('儲存失敗', '資料庫錯誤',array('response'=>500)); }
        wp_safe_redirect(add_query_arg(array('page'=>'wff-households','wff_saved'=>1), admin_url('admin.php')));
        exit;
    }

    public static function transactions() {
        self::allow();
        global $wpdb;
        $h = WFF_Storage::table('households');
        $t = WFF_Storage::table('transactions');
        $c = WFF_Storage::table('categories');
        $user_table = $wpdb->users;
        $hid = absint($_GET['household_id'] ?? 0);
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 50;
        $where = '1=1';
        $args = array();
        if ($hid) { $where .= ' AND t.household_id=%d'; $args[]=$hid; }
        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : '';
        if ($month && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $where .= ' AND t.occurred_on LIKE %s';
            $args[] = $month . '%';
        } else { $month=''; }
        $count_query = "SELECT COUNT(*) FROM $t t WHERE $where";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_query, $args)) : $wpdb->get_var($count_query));
        $args[] = $per_page; $args[] = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.occurred_on,t.household_id,t.scope,t.kind,t.amount,t.note,
             c.name AS category_name,u.display_name AS created_by_name,h.name AS household_name
             FROM $t t
             LEFT JOIN $c c ON c.id=t.category_id AND c.household_id=t.household_id
             LEFT JOIN $h h ON h.id=t.household_id
             LEFT JOIN $user_table u ON u.ID=t.created_by
             WHERE $where ORDER BY t.occurred_on DESC,t.id DESC LIMIT %d OFFSET %d", $args
        ), ARRAY_A);
        self::intro('收支紀錄查詢', '供網站管理員處理資料問題與稽核。這裡只提供唯讀查詢，日常新增與修改請在前台操作。');
        echo '<div class="wff-admin-panel"><form method="get" class="wff-filter">'
            . '<input type="hidden" name="page" value="wff-transactions"><label>家庭空間 <select name="household_id">'
            . '<option value="0">全部家庭</option>';
        $households = $wpdb->get_results("SELECT id,name FROM $h ORDER BY id DESC LIMIT 300", ARRAY_A);
        foreach ($households as $household) {
            echo '<option value="' . (int) $household['id'] . '" '
                . selected($hid, $household['id'], false) . '>' . esc_html($household['name'])
                . ' (#' . (int) $household['id'] . ')</option>';
        }
        echo '</select></label> <label>月份 <input type="month" name="month" value="' . esc_attr($month)
            . '"></label> <button class="button">查詢</button></form>'
            . '<p>共 ' . number_format_i18n($total) . ' 筆（每頁 50 筆）</p>'
            . '<table class="widefat striped"><thead><tr><th>ID</th><th>日期</th><th>家庭</th><th>帳本</th><th>類型</th><th>分類</th><th>金額</th><th>備註</th><th>建立者</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html($row['occurred_on'])
                . '</td><td>' . esc_html($row['household_name']) . '</td><td>'
                . ($row['scope']==='business'?'公司':'家庭') . '</td><td>'
                . (array('income'=>'收入','expense'=>'支出','transfer'=>'轉帳')[$row['kind']] ?? esc_html($row['kind']))
                . '</td><td>' . esc_html($row['category_name'] ?: '—') . '</td><td>NT$ '
                . esc_html(number_format_i18n((float)$row['amount'],2)) . '</td><td>'
                . esc_html($row['note']) . '</td><td>' . esc_html($row['created_by_name'] ?: '—') . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="9">尚無符合條件的交易。</td></tr>'; }
        echo '</tbody></table>';
        self::pagination('wff-transactions', $page, (int) ceil($total/$per_page),
            array('household_id'=>$hid, 'month'=>$month));
        echo '</div>';
        self::finish();
    }

    public static function activity() {
        self::allow();
        global $wpdb;
        $a=WFF_Storage::table('activity');
        $h=WFF_Storage::table('households');
        $u=$wpdb->users;
        $page=max(1,absint($_GET['paged']??1)); $per_page=50;
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $a");
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT a.*,h.name AS household_name,u.display_name FROM $a a
             LEFT JOIN $h h ON h.id=a.household_id LEFT JOIN $u u ON u.ID=a.user_id
             ORDER BY a.id DESC LIMIT %d OFFSET %d",$per_page,($page-1)*$per_page
        ),ARRAY_A);
        self::intro('最近操作紀錄','可追蹤成員新增、編輯、刪除資料等操作，依最新時間排序。');
        echo '<div class="wff-admin-panel"><table class="widefat striped"><thead><tr><th>時間（UTC）</th><th>家庭</th><th>帳號</th><th>操作</th><th>物件 ID</th><th>說明</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>'.esc_html($row['created_at']).'</td><td>'.esc_html($row['household_name'])
                .'</td><td>'.esc_html($row['display_name']).'</td><td>'.esc_html($row['action'])
                .'</td><td>'.(int)$row['object_id'].'</td><td>'.esc_html($row['description']).'</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="6">尚無操作紀錄。</td></tr>'; }
        echo '</tbody></table>';
        self::pagination('wff-activity',$page,(int)ceil($total/$per_page));
        echo '</div>';
        self::finish();
    }

    private static function pagination($slug,$page,$pages,$extra=array()) {
        if ($pages < 2) { return; }
        $base = add_query_arg(array_merge(array('page'=>$slug),$extra),admin_url('admin.php'));
        echo '<nav class="wff-admin-pages" aria-label="查詢分頁">';
        if ($page>1) {
            echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page-1,$base)).'">上一頁</a>';
        }
        echo ' <span>'.(int)$page.' / '.(int)$pages.'</span> ';
        if ($page<$pages) {
            echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page+1,$base)).'">下一頁</a>';
        }
        echo '</nav>';
    }
}
