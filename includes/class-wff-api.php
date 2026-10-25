<?php
if (!defined('ABSPATH')) { exit; }

final class WFF_API {
    const NS = 'wff/v1';

    public static function register() {
        $a = array(__CLASS__, 'auth');
        self::route('/auth/login', 'POST', 'login', '__return_true');
        self::route('/households', 'GET', 'households', $a);
        self::route('/households', 'POST', 'create_household', $a);
        self::route('/households/(?P<hid>\d+)/meta', 'GET', 'meta', $a);
        self::route('/households/(?P<hid>\d+)/dashboard', 'GET', 'dashboard', $a);
        self::route('/households/(?P<hid>\d+)/transactions', 'GET', 'transactions', $a);
        self::route('/households/(?P<hid>\d+)/transactions', 'POST', 'save_transaction', $a);
        self::route('/households/(?P<hid>\d+)/transactions/(?P<id>\d+)', 'PUT', 'save_transaction', $a);
        self::route('/households/(?P<hid>\d+)/transactions/(?P<id>\d+)', 'DELETE', 'delete_transaction', $a);
        self::route('/households/(?P<hid>\d+)/transactions/(?P<id>\d+)/receipt', 'GET', 'get_receipt', $a);
        self::route('/households/(?P<hid>\d+)/transactions/(?P<id>\d+)/receipt', 'POST', 'save_receipt', $a);
        self::route('/households/(?P<hid>\d+)/transactions/(?P<id>\d+)/receipt', 'DELETE', 'delete_receipt', $a);
        self::route('/households/(?P<hid>\d+)/categories', 'POST', 'save_category', $a);
        self::route('/households/(?P<hid>\d+)/categories/reorder', 'PUT', 'reorder_categories', $a);
        self::route('/households/(?P<hid>\d+)/categories/(?P<id>\d+)', 'PUT', 'save_category', $a);
        self::route('/households/(?P<hid>\d+)/categories/(?P<id>\d+)', 'DELETE', 'delete_category', $a);
        self::route('/households/(?P<hid>\d+)/accounts', 'POST', 'save_account', $a);
        self::route('/households/(?P<hid>\d+)/accounts/(?P<id>\d+)', 'PUT', 'save_account', $a);
        self::route('/households/(?P<hid>\d+)/card-payments', 'GET', 'card_payments', $a);
        self::route('/households/(?P<hid>\d+)/card-payments', 'POST', 'save_card_payment', $a);
        self::route('/households/(?P<hid>\d+)/card-payments/(?P<id>\d+)', 'DELETE', 'delete_card_payment', $a);
        self::route('/households/(?P<hid>\d+)/members/(?P<id>\d+)', 'PATCH', 'change_member', $a);
        self::route('/households/(?P<hid>\d+)/members/(?P<id>\d+)', 'DELETE', 'change_member', $a);
        self::route('/households/(?P<hid>\d+)/invite', 'POST', 'invite', $a);
        self::route('/invite/preview', 'GET', 'invite_preview', '__return_true');
        self::route('/invite/accept', 'POST', 'invite_accept', $a);
        self::route('/invite/register', 'POST', 'invite_register', '__return_true');
        self::route('/households/(?P<hid>\d+)/import', 'POST', 'import_csv', $a);
        self::route('/households/(?P<hid>\d+)/export', 'GET', 'export_csv', $a);
        self::route('/households/(?P<hid>\d+)/activity', 'GET', 'activity', $a);
    }

    private static function route($path, $method, $callback, $permission) {
        register_rest_route(self::NS, $path, array(
            'methods' => $method,
            'callback' => array(__CLASS__, $callback),
            'permission_callback' => $permission,
        ));
    }

    public static function auth() {
        return is_user_logged_in() ? true : new WP_Error('wff_login', '請先登入 Finance 帳號', array('status' => 401));
    }

    public static function login($r) {
        $user = WFF_Frontend::authenticate(
            sanitize_text_field($r->get_param('username')),
            (string) $r->get_param('password'),
            (bool) $r->get_param('remember'),
            sanitize_text_field($r->get_param('nonce'))
        );
        if (is_wp_error($user)) { return $user; }
        return array('authenticated' => true, 'redirect' => WFF_Frontend::app_url());
    }

    private static function error($message, $status = 400) {
        return new WP_Error('wff_error', $message, array('status' => $status));
    }

    private static function role($hid, $needed = 'viewer') {
        global $wpdb;
        $m = WFF_Storage::table('members');
        $role = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM $m WHERE household_id=%d AND user_id=%d", (int) $hid, get_current_user_id()
        ));
        if (!$role) { return self::error('無權查看此家庭資料', 403); }
        $ranks = array('viewer' => 1, 'editor' => 2, 'owner' => 3);
        if (($ranks[$role] ?? 0) < ($ranks[$needed] ?? 3)) {
            return self::error('目前帳號沒有此操作權限', 403);
        }
        return $role;
    }

    private static function month($value) {
        $m = (string) $value;
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : wp_date('Y-m');
    }

    private static function date($value, $year = 0) {
        $v = trim((string) $value);
        if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $v, $matches) && $year >= 2000 && $year <= 2100) {
            $v = sprintf('%04d-%02d-%02d', $year, $matches[1], $matches[2]);
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $parts)) { return false; }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $v : false;
    }

    private static function amount($v) {
        $v = preg_replace('/[,\sNT$＄元]/u', '', (string) $v);
        if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 9999999999) { return false; }
        return number_format(round((float) $v, 2), 2, '.', '');
    }

    private static function clean_scope($v) {
        return in_array($v, array('family', 'business'), true) ? $v : null;
    }

    private static function account_in_household($id, $hid) {
        if (!$id) { return true; }
        global $wpdb;
        $a = WFF_Storage::table('accounts');
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $a WHERE household_id=%d AND id=%d", $hid, $id));
    }

    public static function households() {
        global $wpdb;
        $h = WFF_Storage::table('households');
        $m = WFF_Storage::table('members');
        return $wpdb->get_results($wpdb->prepare(
            "SELECT h.id,h.name,h.owner_user_id,m.role FROM $h h INNER JOIN $m m ON m.household_id=h.id WHERE m.user_id=%d ORDER BY h.id",
            get_current_user_id()
        ), ARRAY_A);
    }

    public static function create_household($r) {
        global $wpdb;
        $name = sanitize_text_field($r->get_param('name'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) { return self::error('家庭名稱需為 2–80 字'); }
        $h = WFF_Storage::table('households');
        $m = WFF_Storage::table('members');
        if (!$wpdb->insert($h, array('name' => $name, 'owner_user_id' => get_current_user_id(), 'created_at' => current_time('mysql', true)))) {
            return self::error('無法建立家庭', 500);
        }
        $hid = (int) $wpdb->insert_id;
        if (!$wpdb->insert($m, array('household_id' => $hid, 'user_id' => get_current_user_id(), 'role' => 'owner', 'joined_at' => current_time('mysql', true)))) {
            $wpdb->delete($h, array('id' => $hid));
            return self::error('無法建立家庭成員', 500);
        }
        WFF_Storage::seed_categories($hid);
        $wpdb->insert(WFF_Storage::table('accounts'), array('household_id' => $hid, 'name' => '現金', 'type' => 'cash', 'created_at' => current_time('mysql', true)));
        $wpdb->insert(WFF_Storage::table('accounts'), array('household_id' => $hid, 'name' => '銀行帳戶', 'type' => 'bank', 'created_at' => current_time('mysql', true)));
        WFF_Storage::activity($hid, 'create_household', $hid);
        return array('id' => $hid, 'name' => $name, 'role' => 'owner');
    }

    public static function meta($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid);
        if (is_wp_error($role)) { return $role; }
        $c = WFF_Storage::table('categories');
        $a = WFF_Storage::table('accounts');
        $m = WFF_Storage::table('members');
        $users = $wpdb->users;
        $h = WFF_Storage::table('households');
        $member_limit = (int) $wpdb->get_var($wpdb->prepare("SELECT member_limit FROM $h WHERE id=%d", $hid));
        $member_limit = max(1, $member_limit);
        return array(
            'member_limit' => $member_limit,
            'role' => $role,
            'categories' => $wpdb->get_results($wpdb->prepare("SELECT * FROM $c WHERE household_id=%d ORDER BY scope,kind,sort_order ASC,id ASC", $hid), ARRAY_A),
            'accounts' => $wpdb->get_results($wpdb->prepare("SELECT * FROM $a WHERE household_id=%d ORDER BY sort_order,id", $hid), ARRAY_A),
            'members' => $wpdb->get_results($wpdb->prepare(
                "SELECT m.user_id,m.role,m.joined_at,u.display_name,u.user_email FROM $m m INNER JOIN $users u ON u.ID=m.user_id WHERE m.household_id=%d ORDER BY FIELD(m.role,'owner','editor','viewer'),u.display_name", $hid
            ), ARRAY_A),
        );
    }

    private static function entries($hid, $filters, $limit, $offset) {
        global $wpdb;
        $t = WFF_Storage::table('transactions');
        $cat = WFF_Storage::table('categories');
        $a = WFF_Storage::table('accounts');
        $u = $wpdb->users;
        $receipt_table = WFF_Storage::table('receipts');
        $where = "t.household_id=%d";
        $params = array($hid);
        if (!empty($filters['month'])) {
            $where .= " AND t.occurred_on LIKE %s";
            $params[] = self::month($filters['month']) . '%';
        }
        if (!empty($filters['date'])) {
            $day = self::date($filters['date']);
            if ($day) {
                $where .= " AND t.occurred_on=%s";
                $params[] = $day;
            }
        }
        if (in_array($filters['scope'] ?? '', array('family','business'), true)) {
            $where .= " AND t.scope=%s";
            $params[] = $filters['scope'];
        }
        if (in_array($filters['kind'] ?? '', array('income','expense','transfer'), true)) {
            $where .= " AND t.kind=%s";
            $params[] = $filters['kind'];
        }
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t t WHERE $where", $params));
        $params[] = $limit;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,(SELECT COUNT(*) FROM $receipt_table rr WHERE rr.household_id=t.household_id AND rr.transaction_id=t.id) AS has_receipt,c.name AS category_name,c.color AS category_color,a.name AS account_name,d.name AS destination_account_name,w.name AS payment_channel_name,u.display_name AS creator_name
             FROM $t t
             LEFT JOIN $cat c ON c.id=t.category_id AND c.household_id=t.household_id
             LEFT JOIN $a a ON a.id=t.account_id AND a.household_id=t.household_id
             LEFT JOIN $a d ON d.id=t.destination_account_id AND d.household_id=t.household_id
             LEFT JOIN $a w ON w.id=t.payment_channel_id AND w.household_id=t.household_id
             LEFT JOIN $u u ON u.ID=t.created_by
             WHERE $where ORDER BY t.occurred_on DESC,t.id DESC LIMIT %d OFFSET %d", $params
        ), ARRAY_A);
        return array('items' => $rows, 'total' => $count);
    }

    public static function transactions($r) {
        $hid = (int) $r['hid'];
        $role = self::role($hid);
        if (is_wp_error($role)) { return $role; }
        $page = max(1, min(100000, (int) $r->get_param('page') ?: 1));
        $per = max(5, min(100, (int) $r->get_param('per_page') ?: 30));
        $filters = array('month' => $r->get_param('month'), 'scope' => $r->get_param('scope'), 'kind' => $r->get_param('kind'));
        WFF_Subscriptions::generate($hid);
        WFF_Loans::generate($hid);
        $rows = self::entries($hid, $filters, $per, ($page - 1) * $per);
        $rows['page'] = $page;
        $rows['pages'] = max(1, (int) ceil($rows['total'] / $per));
        return $rows;
    }

    public static function save_transaction($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid, 'editor');
        if (is_wp_error($role)) { return $role; }
        $t = WFF_Storage::table('transactions');
        $id = (int) $r->get_param('id');
        $original_tx = $id ? $wpdb->get_row($wpdb->prepare(
            "SELECT id,payment_method,account_id,destination_account_id,payment_channel_id,kind,loan_id,scope,payment_status,amount
             FROM $t WHERE id=%d AND household_id=%d", $id, $hid
        ), ARRAY_A) : null;
        if ($id && !$original_tx) { return self::error('找不到此交易', 404); }
        $date = self::date($r->get_param('occurred_on'));
        $scope = self::clean_scope($r->get_param('scope'));
        $kind = sanitize_key($r->get_param('kind'));
        $amount = self::amount($r->get_param('amount'));
        if (!$date || !$scope || !in_array($kind, array('income','expense','transfer'), true) || $amount === false) {
            return self::error('請確認日期、收支範圍、類型及正數金額');
        }
        $cat_id = (int) $r->get_param('category_id');
        if ($kind !== 'transfer') {
            if (!$cat_id) { return self::error('請選擇分類'); }
            $c = WFF_Storage::table('categories');
            $ok = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $c WHERE household_id=%d AND id=%d AND scope=%s AND kind=%s", $hid, $cat_id, $scope, $kind
            ));
            if (!$ok) { return self::error('分類不屬於此家庭或收支類型'); }
        } else { $cat_id = null; }
        $account = (int) $r->get_param('account_id') ?: null;
        $dest = (int) $r->get_param('destination_account_id') ?: null;
        if (!self::account_in_household($account, $hid) || !self::account_in_household($dest, $hid)) {
            return self::error('帳戶不屬於此家庭');
        }
        if ($kind === 'transfer' && (!$account || !$dest || $account === $dest)) {
            // Old Google Sheet credit-bill rows were imported as transfers
            // with no selected accounts. Preserve them for note/date edits.
            $grandfathered = $original_tx && $original_tx['kind']==='transfer'
                && !$original_tx['account_id'] && !$original_tx['destination_account_id']
                && !$account && !$dest;
            if (!$grandfathered) { return self::error('轉帳請選擇不同的來源帳戶與轉入帳戶'); }
        }
        $pay = sanitize_key($r->get_param('payment_method') ?: 'cash');
        if (!in_array($pay, array('cash','bank','card','mobile','other'), true)) {
            return self::error('不支援的付款方式');
        }
        $channel = (int) $r->get_param('payment_channel_id') ?: null;
        $acct_table = WFF_Storage::table('accounts');
        if ($channel) {
            $wallet = $wpdb->get_row($wpdb->prepare(
                "SELECT id,type FROM $acct_table WHERE household_id=%d AND id=%d", $hid, $channel
            ), ARRAY_A);
            if (!$wallet || $wallet['type'] !== 'mobile' || $kind === 'transfer') {
                return self::error('選擇的行動支付工具不正確');
            }
        }
        if ($kind !== 'transfer') {
            if ($pay === 'mobile') {
                if (!$channel) { return self::error('請先選擇行動支付工具'); }
                if ($account) {
                    $funding_type = $wpdb->get_var($wpdb->prepare(
                        "SELECT type FROM $acct_table WHERE household_id=%d AND id=%d", $hid, $account
                    ));
                    if (!in_array($funding_type, array('credit','cash','bank'), true)) {
                        return self::error('行動支付扣款來源須為現金、銀行或信用卡');
                    }
                }
            } else {
                if ($channel) { return self::error('非行動支付交易不可指定支付通路'); }
                if ($account) {
                    $source_type = $wpdb->get_var($wpdb->prepare(
                        "SELECT type FROM $acct_table WHERE household_id=%d AND id=%d", $hid, $account
                    ));
                    $expected = array('card'=>'credit','cash'=>'cash','bank'=>'bank');
                    if (isset($expected[$pay]) && $source_type !== $expected[$pay]) {
                        $is_legacy_unchanged = $original_tx
                            && $original_tx['payment_method'] === $pay
                            && (int)$original_tx['account_id'] === (int)$account
                            && !$original_tx['payment_channel_id'];
                        if (!$is_legacy_unchanged) {
                            return self::error('選擇的付款工具與付款類型不符');
                        }
                    }
                }
            }
        } else { $channel = null; $pay = 'bank'; }
        if(!empty($original_tx['loan_id'])){
            if($kind!=='expense'||$scope!==$original_tx['scope'])return self::error('貸款收支請保留原帳本與支出類型');
            if($original_tx['payment_status']==='paid'&&abs($amount-(float)$original_tx['amount'])>0.005)return self::error('已繳貸款金額請從貸款紀錄的「修改紀錄」調整，系統會同步收支');
        }
        $statement=self::date($r->get_param('statement_on'));
        $payment_due=self::date($r->get_param('payment_due_on'));
        foreach(array('statement_on'=>$statement,'payment_due_on'=>$payment_due) as $key=>$valid){if($r->get_param($key)&&!$valid)return self::error('帳單日期格式不正確');}
        if($statement&&$payment_due&&$payment_due<$statement)return self::error('繳款期限不能早於出帳日期');
        $now = current_time('mysql', true);
        $data = array(
            'statement_on'=>$kind==='transfer'?$statement:null,'payment_due_on'=>$kind==='transfer'?$payment_due:null,
            'household_id' => $hid, 'occurred_on' => $date, 'scope' => $scope,
            'kind' => $kind, 'category_id' => $cat_id, 'amount' => $amount,
            'note' => mb_substr(sanitize_text_field($r->get_param('note')), 0, 255),
            'payment_method' => $pay, 'account_id' => $account,
            'payment_channel_id' => $channel,
            'destination_account_id' => $kind === 'transfer' ? $dest : null,
            'updated_at' => $now,
        );
        if ($id) {
            $ok = $wpdb->update($t, $data, array('id' => $id, 'household_id' => $hid));
        } else {
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = $now;
            $ok = $wpdb->insert($t, $data);
            $id = (int) $wpdb->insert_id;
        }
        if ($ok === false) { return self::error('儲存失敗', 500); }
        WFF_Storage::activity($hid, $r->get_param('id') ? 'update_transaction' : 'create_transaction', $id);
        return array('id' => $id, 'saved' => true);
    }

    public static function delete_transaction($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid, 'editor');
        if (is_wp_error($role)) { return $role; }
        $id = (int) $r['id'];
        $deleted = $wpdb->delete(WFF_Storage::table('transactions'), array('id' => $id, 'household_id' => $hid));
        if (!$deleted) { return self::error('找不到此交易', 404); }
        $cleanup=WFF_Private_Receipts::remove($hid,$id);
        if(is_wp_error($cleanup)){ return $cleanup; }
        WFF_Storage::activity($hid, 'delete_transaction', $id);
        return array('deleted' => true);
    }

    private static function receipt_tx($hid,$id) {
        global $wpdb;
        $t=WFF_Storage::table('transactions');
        return (bool)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE household_id=%d AND id=%d", $hid,$id
        ));
    }
    public static function get_receipt($r) {
        $hid=(int)$r['hid']; $id=(int)$r['id'];
        $role=self::role($hid);
        if(is_wp_error($role))return $role;
        if(!self::receipt_tx($hid,$id))return self::error('找不到記帳',404);
        return WFF_Private_Receipts::fetch($hid,$id);
    }
    public static function save_receipt($r) {
        $hid=(int)$r['hid']; $id=(int)$r['id'];
        $role=self::role($hid,'editor');
        if(is_wp_error($role))return $role;
        if(!self::receipt_tx($hid,$id))return self::error('找不到記帳',404);
        $files=$r->get_file_params();
        $saved=WFF_Private_Receipts::save($hid,$id,$files['receipt']??null,get_current_user_id());
        if(!is_wp_error($saved))WFF_Storage::activity($hid,'save_receipt',$id);
        return $saved;
    }
    public static function delete_receipt($r) {
        $hid=(int)$r['hid']; $id=(int)$r['id'];
        $role=self::role($hid,'editor');
        if(is_wp_error($role))return $role;
        if(!self::receipt_tx($hid,$id))return self::error('找不到記帳',404);
        $deleted=WFF_Private_Receipts::remove($hid,$id);
        if(!is_wp_error($deleted))WFF_Storage::activity($hid,'delete_receipt',$id);
        return $deleted;
    }

    public static function save_category($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid, 'editor');
        if (is_wp_error($role)) { return $role; }
        $name = trim(sanitize_text_field($r->get_param('name')));
        $scope = self::clean_scope($r->get_param('scope'));
        $kind = sanitize_key($r->get_param('kind'));
        $color = (string) $r->get_param('color');
        if (!$scope || !in_array($kind, array('income','expense'), true) || !$name || mb_strlen($name) > 80) {
            return self::error('分類資料不正確');
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) { $color = '#FFD93D'; }
        $c = WFF_Storage::table('categories');
        $id = (int) $r->get_param('id');
        $data = array('name' => $name, 'scope' => $scope, 'kind' => $kind, 'color' => $color);
        $current = null;
        if ($id) {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT id,scope,kind,sort_order FROM $c WHERE id=%d AND household_id=%d", $id, $hid
            ), ARRAY_A);
            if (!$current) { return self::error('找不到分類',404); }
        }
        // Position is server-owned; new and moved categories appear at the end of their group.
        if (!$current || $current['scope'] !== $scope || $current['kind'] !== $kind) {
            $last = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(sort_order) FROM $c WHERE household_id=%d AND scope=%s AND kind=%s",
                $hid, $scope, $kind
            ));
            $data['sort_order'] = $last + 10;
        }
        if ($id) {
            $ok = $wpdb->update($c, $data, array('id' => $id, 'household_id' => $hid));
        } else {
            $data['household_id'] = $hid;
            $data['created_at'] = current_time('mysql', true);
            $ok = $wpdb->insert($c, $data);
            $id = (int) $wpdb->insert_id;
        }
        if ($ok === false) { return self::error('儲存分類失敗，名稱可能重複'); }
        WFF_Storage::activity($hid, 'save_category', $id);
        return array('saved' => true, 'id' => $id);
    }


    /**
     * Atomic, scoped ordering. Only an editor may reorder the full membership
     * of one family/company income/expense group. Never accept sort_order
     * offsets from the browser or change IDs belonging to other households.
     */
    public static function reorder_categories($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid, 'editor');
        if (is_wp_error($role)) { return $role; }
        $scope = self::clean_scope($r->get_param('scope'));
        $kind = sanitize_key($r->get_param('kind'));
        if (!$scope || !in_array($kind, array('income','expense'), true)) {
            return self::error('排序分類群組不正確');
        }
        $ids = $r->get_param('ids');
        if (!is_array($ids) || count($ids) > 500) {
            return self::error('排序資料格式錯誤或分類數量過多');
        }
        // Reject negative, zero, duplicate, or non-integer IDs.
        $ordered = array();
        foreach ($ids as $raw) {
            if (!is_scalar($raw) || !ctype_digit((string)$raw) || (int)$raw <= 0) {
                return self::error('排序 ID 不正確');
            }
            $ordered[] = (int)$raw;
        }
        if (count($ordered) !== count(array_unique($ordered))) {
            return self::error('排序 ID 不可重複');
        }
        $c = WFF_Storage::table('categories');
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $c WHERE household_id=%d AND scope=%s AND kind=%s ORDER BY sort_order,id",
            $hid, $scope, $kind
        ));
        // Require a complete permutation. Avoid dropping newly-added categories
        // when two members edit the same group at the same time.
        $expect = array_map('intval', $existing);
        $test = $ordered;
        sort($expect, SORT_NUMERIC);
        sort($test, SORT_NUMERIC);
        if ($test !== $expect) {
            return self::error('分類資料已變更，請重新整理後再排序', 409);
        }
        $wpdb->query('START TRANSACTION');
        foreach ($ordered as $i => $id) {
            $result = $wpdb->update($c, array('sort_order'=>($i+1)*10), array(
                'id'=>$id,'household_id'=>$hid,'scope'=>$scope,'kind'=>$kind
            ), array('%d'), array('%d','%d','%s','%s'));
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return self::error('排序儲存失敗，請稍後再試', 500);
            }
        }
        $wpdb->query('COMMIT');
        WFF_Storage::activity($hid, 'reorder_categories', 0, $scope . '/' . $kind);
        return array('saved'=>true,'ids'=>$ordered);
    }

    public static function delete_category($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid, 'editor');
        if (is_wp_error($role)) { return $role; }
        $id = (int) $r['id'];
        $t = WFF_Storage::table('transactions');
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE household_id=%d AND category_id=%d", $hid, $id)) > 0) {
            return self::error('此分類已有交易，無法刪除，請先修改交易分類');
        }
        $deleted = $wpdb->delete(WFF_Storage::table('categories'), array('id' => $id, 'household_id' => $hid));
        if (!$deleted) { return self::error('找不到分類',404); }
        WFF_Storage::activity($hid, 'delete_category', $id);
        return array('deleted' => true);
    }

    public static function save_account($r) {
        global $wpdb;
        $hid = (int)$r['hid'];
        $role = self::role($hid, 'editor');
        if (is_wp_error($role)) { return $role; }
        $id = (int)$r->get_param('id');
        $a = WFF_Storage::table('accounts');
        $t = WFF_Storage::table('transactions');
        $name = trim(sanitize_text_field($r->get_param('name')));
        $type = sanitize_key($r->get_param('type'));
        if ($name === '' || mb_strlen($name)>80 || !in_array($type,array('cash','bank','credit','mobile'),true)) {
            return self::error('付款工具名稱或類型不正確');
        }
        $original=null;
        if ($id) {
            $original=$wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $a WHERE household_id=%d AND id=%d",$hid,$id
            ),ARRAY_A);
            if (!$original) { return self::error('找不到付款工具',404); }
            if ($type !== $original['type']) {
                $used=(int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $t WHERE household_id=%d
                     AND (account_id=%d OR payment_channel_id=%d OR destination_account_id=%d)",
                    $hid,$id,$id,$id
                ));
                if ($used) { return self::error('已有交易使用此付款工具，無法改變類型；可將它停用並另建新工具'); }
            }
        }
        $brand=mb_substr(sanitize_text_field($r->get_param('brand')),0,100);
        $notes=mb_substr(sanitize_text_field($r->get_param('notes')),0,255);
        $enabled=($r->get_param('enabled') === null || filter_var($r->get_param('enabled'),FILTER_VALIDATE_BOOLEAN)) ? 1 : 0;
        $order=max(0,min(100000,(int)$r->get_param('sort_order')));
        $statement=null; $due=null;
        if ($type==='credit') {
            foreach (array('statement_day','due_day') as $col) {
                $raw=$r->get_param($col);
                if ($raw!==null && $raw!=='') {
                    if (!ctype_digit((string)$raw) || (int)$raw<1 || (int)$raw>31) {
                        return self::error('結帳日與繳款日請輸入 1–31');
                    }
                    if ($col==='statement_day') { $statement=(int)$raw; }
                    else { $due=(int)$raw; }
                }
            }
        }
        if ($type==='credit' && (!$statement || !$due)) {
            return self::error('信用卡請設定固定結帳日與固定繳款日');
        }
        if ($original && $original['type']==='credit') {
            $payments = WFF_Storage::table('card_payments');
            $has_history = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $payments WHERE household_id=%d AND account_id=%d", $hid, $id
            ));
            if ($has_history && ($type!=='credit' ||
                (int)$original['statement_day'] !== (int)$statement ||
                (int)$original['due_day'] !== (int)$due)) {
                return self::error('已有歷史繳費紀錄，固定結帳日／繳款日或工具類型不可直接變更，以免帳期錯置；如確需更動，請先確認或取消既有繳費紀錄');
            }
        }
        $funding = null;
        if ($type==='mobile') {
            $funding = (int)$r->get_param('funding_account_id') ?: null;
            if ($funding) {
                if ($funding === $id) { return self::error('行動支付扣款來源不可指向自己'); }
                $funding_type=$wpdb->get_var($wpdb->prepare(
                    "SELECT type FROM $a WHERE household_id=%d AND id=%d",$hid,$funding
                ));
                if (!in_array($funding_type,array('cash','bank','credit'),true)) {
                    return self::error('預設扣款來源必須是同家庭的現金、銀行或信用卡');
                }
            }
        }
        $data=array(
            'name'=>$name,'type'=>$type,'brand'=>$brand,'notes'=>$notes,
            'enabled'=>$enabled,'sort_order'=>$order,'statement_day'=>$statement,
            'due_day'=>$due,'funding_account_id'=>$funding
        );
        if ($id) {
            $ok=$wpdb->update($a,$data,array('id'=>$id,'household_id'=>$hid));
        } else {
            $data['household_id']=$hid;
            $data['created_at']=current_time('mysql',true);
            $ok=$wpdb->insert($a,$data);
            $id=(int)$wpdb->insert_id;
        }
        if ($ok===false) { return self::error('儲存付款工具失敗',500); }
        WFF_Storage::activity($hid,'save_payment_tool',$id);
        return array('saved'=>true,'id'=>$id);
    }

    /**
     * Status belongs to a particular statement cycle, never the estimated
     * card balance. Read access is shared with other household resources.
     */
    public static function card_payments($r) {
        global $wpdb;
        $hid = (int)$r['hid'];
        $role = self::role($hid);
        if (is_wp_error($role)) { return $role; }
        $accounts = WFF_Storage::table('accounts');
        $payments = WFF_Storage::table('card_payments');
        $cards = $wpdb->get_results($wpdb->prepare(
            "SELECT id, statement_day, due_day FROM $accounts WHERE household_id=%d AND type='credit'",
            $hid
        ), ARRAY_A);
        $today = wp_date('Y-m-d');
        $earliest = (new DateTimeImmutable($today))->modify('-26 months')->format('Y-m-d');
        $saved = $wpdb->get_results($wpdb->prepare(
            "SELECT id,account_id,statement_on,due_on,paid_on,amount FROM $payments
             WHERE household_id=%d AND statement_on >= %s ORDER BY statement_on DESC",
            $hid, $earliest
        ), ARRAY_A);
        $by_card = array();
        foreach ($saved ?: array() as $item) {
            $by_card[(int)$item['account_id']][$item['statement_on']] = array(
                'id'=>(int)$item['id'],
                'paid_on'=>$item['paid_on'],
                'amount'=>(float)$item['amount'],
            );
        }
        $out = array();
        foreach ($cards ?: array() as $card) {
            $id = (int)$card['id'];
            $configured = !empty($card['statement_day']) && !empty($card['due_day']);
            $periods = array();
            if ($configured) {
                foreach (WFF_Card_Cycles::periods((int)$card['statement_day'], (int)$card['due_day'], $today, 12) as $period) {
                    $payment = $by_card[$id][$period['statement_on']] ?? null;
                    $period['payment'] = $payment;
                    $period['status'] = $payment ? 'paid' : ($today > $period['due_on'] ? 'overdue' : 'pending');
                    $periods[] = $period;
                }
            }
            $out[$id] = array('configured'=>$configured, 'periods'=>$periods);
        }
        return array('cards'=>$out, 'today'=>$today);
    }

    /** The only user inputs are the cycle, actual paid date and paid amount. */
    public static function save_card_payment($r) {
        global $wpdb;
        $hid = (int)$r['hid'];
        $role = self::role($hid, 'editor');
        if (is_wp_error($role)) { return $role; }
        $card_id = (int)$r->get_param('account_id');
        $accounts = WFF_Storage::table('accounts');
        $card = $wpdb->get_row($wpdb->prepare(
            "SELECT id, statement_day, due_day FROM $accounts
             WHERE household_id=%d AND id=%d AND type='credit'",
            $hid, $card_id
        ), ARRAY_A);
        if (!$card) { return self::error('找不到這張家庭信用卡', 404); }
        if (empty($card['statement_day']) || empty($card['due_day'])) {
            return self::error('請先設定信用卡每月固定結帳日與繳款日');
        }
        $statement = self::date($r->get_param('statement_on'));
        $paid_on = self::date($r->get_param('paid_on'));
        $amount = self::amount($r->get_param('amount'));
        $today = wp_date('Y-m-d');
        if (!$statement || !$paid_on || !$amount) {
            return self::error('請填寫正確的帳期、實際繳費日期與金額');
        }
        if ($paid_on > $today) { return self::error('實際繳費日期不能晚於今天'); }
        $cycle = null;
        foreach (WFF_Card_Cycles::periods((int)$card['statement_day'], (int)$card['due_day'], $today, 24) as $item) {
            if ($item['statement_on'] === $statement) { $cycle = $item; break; }
        }
        if (!$cycle) { return self::error('帳期與固定結帳日不符，或超過可登記的歷史範圍'); }
        $payments = WFF_Storage::table('card_payments');
        $now = current_time('mysql', true);
        // Atomic UPSERT: one paid marker per card + statement close date.
        // This deliberately never inserts transfer/expense records.
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO $payments (household_id,account_id,statement_on,due_on,paid_on,amount,created_by,created_at,updated_at)
             VALUES (%d,%d,%s,%s,%s,%s,%d,%s,%s)
             ON DUPLICATE KEY UPDATE due_on=VALUES(due_on),paid_on=VALUES(paid_on),
             amount=VALUES(amount),updated_at=VALUES(updated_at)",
            $hid, $card_id, $statement, $cycle['due_on'], $paid_on,
            $amount, get_current_user_id(), $now, $now
        ));
        if ($ok === false) { return self::error('信用卡繳費紀錄儲存失敗',500); }
        WFF_Storage::activity($hid, 'save_card_payment', $card_id, $statement);
        return array('saved'=>true);
    }

    public static function delete_card_payment($r) {
        global $wpdb;
        $hid = (int)$r['hid'];
        $role = self::role($hid, 'editor');
        if (is_wp_error($role)) { return $role; }
        $id = (int)$r['id'];
        $table = WFF_Storage::table('card_payments');
        $deleted = $wpdb->delete($table, array('id'=>$id, 'household_id'=>$hid));
        if (!$deleted) { return self::error('找不到信用卡繳費紀錄',404); }
        WFF_Storage::activity($hid, 'delete_card_payment', $id);
        return array('deleted'=>true);
    }

    public static function dashboard($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid);
        if (is_wp_error($role)) { return $role; }
        $month = self::month($r->get_param('month'));
        $t = WFF_Storage::table('transactions');
        $c = WFF_Storage::table('categories');
        $a = WFF_Storage::table('accounts');
        $totals = array('family' => array('income'=>0,'expense'=>0), 'business'=>array('income'=>0,'expense'=>0));
        $aggregate = $wpdb->get_results($wpdb->prepare(
            "SELECT scope,kind,SUM(amount) total FROM $t WHERE payment_status='paid' AND household_id=%d AND occurred_on LIKE %s AND kind IN ('income','expense') GROUP BY scope,kind",
            $hid, $month . '%'
        ), ARRAY_A);
        foreach ($aggregate as $row) {
            if (isset($totals[$row['scope']][$row['kind']])) { $totals[$row['scope']][$row['kind']] = (float) $row['total']; }
        }
        $categories = $wpdb->get_results($wpdb->prepare(
            "SELECT t.scope,COALESCE(c.name,'未分類') label,COALESCE(c.color,'#FFD93D') color,SUM(t.amount) total
             FROM $t t LEFT JOIN $c c ON c.id=t.category_id AND c.household_id=t.household_id
             WHERE t.payment_status='paid' AND t.household_id=%d AND t.occurred_on LIKE %s AND t.kind='expense'
             GROUP BY t.scope,t.category_id,c.name,c.color ORDER BY total DESC", $hid, $month.'%'
        ), ARRAY_A);
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT t.occurred_on,
             SUM(CASE WHEN c.name IN ('早餐','午餐','下午茶','晚餐','消夜','飲料','咖啡') THEN t.amount ELSE 0 END) food,
             SUM(CASE WHEN c.name IN ('早餐','午餐','下午茶','晚餐','消夜','飲料','咖啡') THEN 0 ELSE t.amount END) other,
             SUM(t.amount) total
             FROM $t t LEFT JOIN $c c ON c.id=t.category_id AND c.household_id=t.household_id
             WHERE t.payment_status='paid' AND t.household_id=%d AND t.scope='family' AND t.kind='expense' AND t.occurred_on LIKE %s
             GROUP BY t.occurred_on ORDER BY t.occurred_on DESC", $hid, $month.'%'
        ), ARRAY_A);
        $recent = self::entries($hid, array('month'=>$month), 7, 0);
        // Always use the site's local current day, independent of the selected
        // dashboard month. Transfers count as records but not income/expense.
        $today = wp_date('Y-m-d');
        $today_rows = self::entries($hid, array('date'=>$today), 20, 0);
        $today_totals = array('income'=>0.0, 'expense'=>0.0, 'transfer'=>0.0);
        $today_aggregate = $wpdb->get_results($wpdb->prepare(
            "SELECT kind,COALESCE(SUM(amount),0) amount FROM $t WHERE payment_status='paid' AND household_id=%d AND occurred_on=%s GROUP BY kind",
            $hid, $today
        ), ARRAY_A);
        foreach ($today_aggregate as $entry) {
            if (isset($today_totals[$entry['kind']])) {
                $today_totals[$entry['kind']] = (float)$entry['amount'];
            }
        }
        $dates = array();
        $from = DateTimeImmutable::createFromFormat('!Y-m', $month);
        for ($i=5; $i>=0; $i--) {
            $key = $from->modify("-$i months")->format('Y-m');
            $dates[$key] = array('month'=>$key,'income'=>0,'expense'=>0);
        }
        $trend = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(occurred_on,'%%Y-%%m') ym,kind,SUM(amount) total FROM $t
             WHERE payment_status='paid' AND household_id=%d AND occurred_on >= %s AND occurred_on < %s AND kind IN ('income','expense')
             GROUP BY ym,kind ORDER BY ym",
            $hid, array_key_first($dates).'-01', $from->modify('+1 month')->format('Y-m').'-01'
        ), ARRAY_A);
        foreach ($trend as $row) {
            if (isset($dates[$row['ym']][$row['kind']])) { $dates[$row['ym']][$row['kind']] = (float) $row['total']; }
        }
        $cards = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id,a.name,a.type,
             COALESCE(SUM(CASE WHEN t.kind='expense' AND t.account_id=a.id THEN t.amount
                  WHEN t.kind='transfer' AND t.destination_account_id=a.id THEN -t.amount
                  WHEN t.kind='transfer' AND t.account_id=a.id THEN t.amount
                  WHEN t.kind='income' AND t.account_id=a.id THEN -t.amount ELSE 0 END),0) AS outstanding
             FROM $a a LEFT JOIN $t t ON t.payment_status='paid' AND t.household_id=a.household_id AND (t.account_id=a.id OR t.destination_account_id=a.id)
             WHERE a.household_id=%d AND a.type='credit' GROUP BY a.id,a.name,a.type ORDER BY a.id", $hid
        ), ARRAY_A);
        return array(
            'month'=>$month,'totals'=>$totals,'categories'=>$categories,'daily'=>$daily,
            'recent'=>$recent['items'],'today'=>array(
                'date'=>$today,'total'=>$today_rows['total'],
                'items'=>$today_rows['items'],'totals'=>$today_totals
            ),'trend'=>array_values($dates),'cards'=>$cards,
            'insights'=>WFF_Insights::dashboard($hid,$month,$cards)
        );
    }

    public static function change_member($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid, 'owner');
        if (is_wp_error($role)) { return $role; }
        $target = (int) $r['id'];
        $m = WFF_Storage::table('members');
        $current = $wpdb->get_var($wpdb->prepare("SELECT role FROM $m WHERE household_id=%d AND user_id=%d", $hid,$target));
        if (!$current) { return self::error('找不到成員',404); }
        if ($current === 'owner') { return self::error('不可移除家庭擁有者或變更其身分'); }
        if ($r->get_method() === 'DELETE') {
            $wpdb->delete($m,array('household_id'=>$hid,'user_id'=>$target));
            WFF_Storage::activity($hid,'remove_member',$target);
            return array('deleted'=>true);
        }
        $new = sanitize_key($r->get_param('role'));
        if (!in_array($new,array('editor','viewer'),true)) { return self::error('成員角色不正確'); }
        $wpdb->update($m,array('role'=>$new),array('household_id'=>$hid,'user_id'=>$target));
        WFF_Storage::activity($hid,'change_member',$target);
        return array('saved'=>true);
    }

    public static function invite($r) {
        global $wpdb;
        $hid = (int) $r['hid'];
        $role = self::role($hid, 'owner');
        if (is_wp_error($role)) { return $role; }
        $email = sanitize_email($r->get_param('email'));
        $member_role = sanitize_key($r->get_param('role'));
        if (!is_email($email) || !in_array($member_role,array('editor','viewer'),true)) { return self::error('請輸入正確 Email 和角色'); }
        $capacity = self::household_capacity($hid, true);
        if (is_wp_error($capacity)) { return $capacity; }
        if ($capacity['available'] <= 0) {
            return self::error('家庭成員（含待接受邀請）已達 '.$capacity['limit'].' 位上限，請聯絡網站管理員開通更多名額',403);
        }
        $existing = get_user_by('email',$email);
        if ($existing) {
            $m = WFF_Storage::table('members');
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $m WHERE household_id=%d AND user_id=%d",$hid,$existing->ID))) {
                return self::error('該帳號已是家庭成員');
            }
        }
        $token = bin2hex(random_bytes(24));
        $expires = gmdate('Y-m-d H:i:s',time()+7*DAY_IN_SECONDS);
        $wpdb->insert(WFF_Storage::table('invites'),array(
            'household_id'=>$hid,'email'=>$email,'role'=>$member_role,
            'token_hash'=>hash('sha256',$token),'expires_at'=>$expires,
            'created_by'=>get_current_user_id()
        ));
        if (!$wpdb->insert_id) { return self::error('建立邀請失敗',500); }
        $link = add_query_arg('wff_invite', rawurlencode($token), WFF_Frontend::app_url());
        $h = WFF_Storage::table('households');
        $household_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $h WHERE id=%d", $hid));
        $sent = WFF_Mail::invitation($email, $household_name ?: '家庭財務', $member_role, $link);
        WFF_Storage::activity($hid,'create_invite',0,$email);
        return array('link'=>$link,'email_sent'=>(bool)$sent,'expires_at'=>$expires);
    }

    private static function household_capacity($hid, $reserve_pending = false) {
        global $wpdb;
        $h = WFF_Storage::table('households');
        $m = WFF_Storage::table('members');
        $i = WFF_Storage::table('invites');
        $limit = (int) $wpdb->get_var($wpdb->prepare("SELECT member_limit FROM $h WHERE id=%d", $hid));
        if ($limit < 1) { return self::error('找不到此家庭',404); }
        $joined = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $m WHERE household_id=%d", $hid));
        $reserved = $reserve_pending ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $i WHERE household_id=%d AND accepted_at IS NULL AND expires_at>%s",
            $hid, current_time('mysql',true)
        )) : 0;
        return array('limit'=>$limit,'joined'=>$joined,'reserved'=>$reserved,
            'available'=>$limit-$joined-$reserved);
    }

    private static function capacity_lock($hid) {
        global $wpdb;
        $name = 'wff_member_' . substr(md5($wpdb->prefix . ':' . $hid), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) !== 1) {
            return self::error('家庭成員名額正在更新，請稍後重試', 409);
        }
        return $name;
    }

    private static function valid_invite($token) {
        global $wpdb;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/',$token)) { return self::error('邀請連結無效',404); }
        $i = WFF_Storage::table('invites');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $i WHERE token_hash=%s AND accepted_at IS NULL AND expires_at>%s",
            hash('sha256',$token), current_time('mysql',true)
        ), ARRAY_A);
        return $row ?: self::error('邀請不存在、已使用或已過期',410);
    }

    public static function invite_preview($r) {
        $invite = self::valid_invite($r->get_param('token'));
        if (is_wp_error($invite)) { return $invite; }
        global $wpdb;
        $h = WFF_Storage::table('households');
        $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM $h WHERE id=%d",$invite['household_id']));
        return array('household_name'=>$name,'email'=>$invite['email'],'role'=>$invite['role'],'expires_at'=>$invite['expires_at']);
    }

    private static function finish_invite($invite,$uid) {
        global $wpdb;
        $hid = (int) $invite['household_id'];
        $lock = self::capacity_lock($hid);
        if (is_wp_error($lock)) { return $lock; }
        try {
            // Recheck invitation state under the same household lock.
            $i = WFF_Storage::table('invites');
            $m = WFF_Storage::table('members');
            $fresh = $wpdb->get_row($wpdb->prepare(
                "SELECT id,accepted_at,expires_at,role FROM $i WHERE id=%d AND household_id=%d",
                $invite['id'], $hid
            ), ARRAY_A);
            if (!$fresh || $fresh['accepted_at'] !== null ||
                strtotime($fresh['expires_at'].' UTC') <= time()) {
                return self::error('邀請已使用或已過期',410);
            }
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $m WHERE household_id=%d AND user_id=%d", $hid, $uid
            ));
            if (!$exists) {
                $capacity = self::household_capacity($hid);
                if (is_wp_error($capacity)) { return $capacity; }
                if ($capacity['available'] <= 0) {
                    return self::error('家庭成員已達 '.$capacity['limit'].' 位上限，請聯絡網站管理員開通更多名額',403);
                }
            }
            $wpdb->query('START TRANSACTION');
            if (!$exists) {
                $ok = $wpdb->insert($m, array(
                    'household_id'=>$hid,'user_id'=>$uid,
                    'role'=>$fresh['role'],'joined_at'=>current_time('mysql',true)
                ));
                if (!$ok) { $wpdb->query('ROLLBACK'); return self::error('加入家庭失敗',500); }
            }
            $used = $wpdb->query($wpdb->prepare(
                "UPDATE $i SET accepted_at=%s WHERE id=%d AND accepted_at IS NULL",
                current_time('mysql',true), $invite['id']
            ));
            if ($used !== 1) { $wpdb->query('ROLLBACK'); return self::error('邀請已使用',409); }
            $wpdb->query('COMMIT');
            WFF_Storage::activity($hid,'accept_invite',$uid);
            return array('joined'=>true,'household_id'=>$hid);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function invite_accept($r) {
        $invite = self::valid_invite($r->get_param('token'));
        if (is_wp_error($invite)) { return $invite; }
        $user = wp_get_current_user();
        if (strcasecmp($user->user_email,$invite['email']) !== 0) { return self::error('請使用邀請指定的 Email 帳號登入',403); }
        return self::finish_invite($invite,$user->ID);
    }

    public static function invite_register($r) {
        $token = $r->get_param('token');
        $invite = self::valid_invite($token);
        if (is_wp_error($invite)) { return $invite; }
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'wff_register_' . md5($ip);
        if ((int)get_transient($key) >= 8) { return self::error('嘗試次數過多，請稍後再試',429); }
        set_transient($key,(int)get_transient($key)+1,HOUR_IN_SECONDS);
        $password = (string)$r->get_param('password');
        $username = sanitize_user($r->get_param('username'),true);
        if (strlen($password)<12 || strlen($password)>128 || strlen($username)<3 || strlen($username)>50) {
            return self::error('帳號至少 3 字，密碼至少 12 字');
        }
        if (username_exists($username) || email_exists($invite['email'])) { return self::error('帳號已存在，請登入後接受邀請',409); }
        $cap = self::household_capacity((int)$invite['household_id']);
        if (is_wp_error($cap)) { return $cap; }
        if ($cap['available'] <= 0) {
            return self::error('家庭成員已達 '.$cap['limit'].' 位上限，請聯絡網站管理員開通',403);
        }
        $uid = wp_create_user($username,$password,$invite['email']);
        if (is_wp_error($uid)) { return self::error($uid->get_error_message()); }
        wp_update_user(array('ID'=>$uid,'display_name'=>$username,'role'=>'subscriber'));
        $joined = self::finish_invite($invite,$uid);
        if (is_wp_error($joined)) { return $joined; }
        return array('registered'=>true,'login'=>add_query_arg('wff_signup','invited',WFF_Frontend::app_url()));
    }

    public static function activity($r) {
        global $wpdb;
        $hid=(int)$r['hid'];
        $role=self::role($hid);
        if (is_wp_error($role)) { return $role; }
        $a=WFF_Storage::table('activity');
        $u=$wpdb->users;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.action,a.object_id,a.description,a.created_at,u.display_name FROM $a a LEFT JOIN $u u ON u.ID=a.user_id WHERE a.household_id=%d ORDER BY a.id DESC LIMIT 50", $hid
        ),ARRAY_A);
    }

    private static function decode_csv($csv) {
        $fp=fopen('php://temp','w+');
        fwrite($fp,preg_replace('/^\xEF\xBB\xBF/','',$csv));
        rewind($fp);
        $rows=array();
        while (($row=fgetcsv($fp,0,','))!==false) {
            if (count($row)===1 && trim($row[0])==='') { continue; }
            $rows[]=$row;
            if (count($rows)>2002) { break; }
        }
        fclose($fp);
        return $rows;
    }

    private static function parse_import($rows,$year) {
        if (!$rows) { return self::error('CSV 沒有資料'); }
        $head=array_map('trim',$rows[0]);
        $sheet=isset($head[0],$head[3]) && $head[0]==='日期' && $head[3]==='支出';
        if (!$sheet && count(array_intersect(array('日期','範圍','類型','金額'), $head)) !== 4) {
            return self::error('不支援的 CSV 欄位，請使用本系統匯出的 CSV 或指定 Google Sheet A–M 欄位格式');
        }
        $out=array(); $errors=array(); $carry=''; $section='income';
        foreach (array_slice($rows,1) as $n=>$r) {
            $line=$n+2;
            if ($sheet) {
                $r=array_pad($r,13,'');
                if (trim($r[0])!=='') { $carry=trim($r[0]); }
                if (trim($r[1])!=='' && self::amount($r[3])!==false) {
                    $date=self::date($carry,$year);
                    if ($date) {
                        $out[]=array('occurred_on'=>$date,'scope'=>'family','kind'=>'expense',
                            'category'=>trim($r[1]),'amount'=>self::amount($r[3]),'note'=>trim($r[2]),'payment_method'=>'cash','account'=>'');
                    } else { $errors[]="第 $line 行：家庭日期無法判讀"; }
                }
                $header=trim($r[8]);
                if ($header==='公司收入') { $section='income'; }
                elseif ($header==='公司支出') { $section='expense'; }
                elseif ($header==='信用卡費') { $section='transfer'; }
                if (trim($r[9])!=='' && trim($r[12])!=='' && self::amount($r[12])!==false) {
                    $date=self::date(trim($r[9]),$year);
                    if ($date) {
                        $category=trim($r[10]) ?: trim($r[8]);
                        $out[]=array('occurred_on'=>$date,'scope'=>$section==='transfer'?'family':'business',
                            'kind'=>$section,'category'=>$category ?: '其他支出','amount'=>self::amount($r[12]),
                            'note'=>trim($r[11]),'payment_method'=>'bank','account'=>'');
                    } else { $errors[]="第 $line 行：右側日期無法判讀"; }
                }
            } else {
                $lookup=array();
                foreach ($head as $k=>$v) { $lookup[$v]=$r[$k] ?? ''; }
                $date=self::date($lookup['日期'] ?? '', $year);
                $scope=trim($lookup['範圍'] ?? '');
                $scope=in_array($scope,array('公司','business'),true)?'business':'family';
                $k=trim($lookup['類型'] ?? '');
                $kind=in_array($k,array('收入','income'),true)?'income':(in_array($k,array('轉帳','transfer'),true)?'transfer':'expense');
                $amount=self::amount($lookup['金額'] ?? '');
                if (!$date || $amount===false) { $errors[]="第 $line 行：日期或金額錯誤"; continue; }
                $out[]=array('occurred_on'=>$date,'scope'=>$scope,'kind'=>$kind,
                    'category'=>trim($lookup['分類']??''),'amount'=>$amount,
                    'note'=>trim($lookup['備註']??''),'payment_method'=>trim($lookup['付款方式']??'cash'),
                    'account'=>trim($lookup['帳戶']??''),'destination_account'=>trim($lookup['轉入帳戶']??''),
                    'payment_channel'=>trim($lookup['付款通路']??''),'payment_status'=>($lookup['繳費狀態']??'paid')==='unpaid'?'unpaid':'paid');
            }
            if (count($errors)>25) { break; }
        }
        return array('items'=>$out,'errors'=>$errors,'mode'=>$sheet?'google_sheet':'standard');
    }

    private static function find_category($hid,$row) {
        global $wpdb;
        $c=WFF_Storage::table('categories');
        $name=mb_substr(sanitize_text_field($row['category'] ?: ($row['kind']==='income'?'其他收入':'其他支出')),0,80);
        $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM $c WHERE household_id=%d AND scope=%s AND kind=%s AND name=%s",
            $hid,$row['scope'],$row['kind'],$name));
        if ($id) { return (int)$id; }
        $ok=$wpdb->insert($c,array('household_id'=>$hid,'scope'=>$row['scope'],'kind'=>$row['kind'],
            'name'=>$name,'color'=>'#FFD93D','created_at'=>current_time('mysql',true)));
        return $ok ? (int)$wpdb->insert_id : 0;
    }

    public static function import_csv($r) {
        global $wpdb;
        $hid=(int)$r['hid'];
        $role=self::role($hid,'editor');
        if (is_wp_error($role)) { return $role; }
        $csv=(string)$r->get_param('csv');
        if (strlen($csv)>1048576) { return self::error('CSV 超過 1MB，請分批匯入'); }
        $year=(int)$r->get_param('year');
        if ($year<2000 || $year>2100) { return self::error('請選擇正確的西元年份'); }
        $rows=self::decode_csv($csv);
        if (count($rows)>2001) { return self::error('單次最多匯入 2,000 筆，請分批'); }
        $parsed=self::parse_import($rows,$year);
        if (is_wp_error($parsed)) { return $parsed; }
        if (!empty($parsed['errors'])) { return self::error(implode('；',$parsed['errors'])); }
        if (empty($parsed['items'])) { return self::error('沒有可匯入的交易；請確認 CSV 格式'); }
        if (!$r->get_param('confirm')) {
            return array('preview'=>true,'mode'=>$parsed['mode'],'count'=>count($parsed['items']),
                'samples'=>array_slice($parsed['items'],0,8),
                'warning'=>'匯入不會自動去重；請勿重複匯入同一份檔案。信用卡費會記為不影響收支總額的轉帳。');
        }
        $a=WFF_Storage::table('accounts');
        $t=WFF_Storage::table('transactions');
        $wpdb->query('START TRANSACTION');
        $count=0;
        foreach ($parsed['items'] as $item) {
            $account_id=null; $destination_id=null;
            foreach (array('account'=>'account_id','destination_account'=>'destination_id') as $key=>$target) {
                if (!empty($item[$key])) {
                    $found=$wpdb->get_var($wpdb->prepare("SELECT id FROM $a WHERE household_id=%d AND name=%s",$hid,$item[$key]));
                    if (!$found) { $wpdb->query('ROLLBACK'); return self::error('找不到帳戶：'.$item[$key]); }
                    if ($target==='account_id') { $account_id=(int)$found; } else { $destination_id=(int)$found; }
                }
            }
            $channel_id=null;
            if (!empty($item['payment_channel'])) {
                $wallet=$wpdb->get_row($wpdb->prepare(
                    "SELECT id,type FROM $a WHERE household_id=%d AND name=%s",
                    $hid,$item['payment_channel']
                ),ARRAY_A);
                if (!$wallet || $wallet['type']!=='mobile') {
                    $wpdb->query('ROLLBACK');
                    return self::error('找不到行動支付工具：'.$item['payment_channel']);
                }
                $channel_id=(int)$wallet['id'];
            }
            $cat=$item['kind']==='transfer'?null:self::find_category($hid,$item);
            if ($item['kind']!=='transfer' && !$cat) { $wpdb->query('ROLLBACK'); return self::error('分類寫入失敗',500); }
            $ok=$wpdb->insert($t,array(
                'household_id'=>$hid,'occurred_on'=>$item['occurred_on'],'scope'=>$item['scope'],'kind'=>$item['kind'],
                'category_id'=>$cat,'amount'=>$item['amount'],'note'=>mb_substr(sanitize_text_field($item['note']),0,255),
                'payment_method'=>in_array($item['payment_method'],array('cash','bank','card','mobile','other'),true)?$item['payment_method']:'other',
                'account_id'=>$account_id,'destination_account_id'=>$destination_id,
                'payment_channel_id'=>$channel_id,'payment_status'=>$item['payment_status']??'paid',
                'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true)
            ));
            if (!$ok) { $wpdb->query('ROLLBACK'); return self::error('交易匯入失敗，已取消本次匯入',500); }
            $count++;
        }
        $wpdb->query('COMMIT');
        WFF_Storage::activity($hid,'import_csv',0,'Imported '.$count.' rows');
        return array('imported'=>$count);
    }

    public static function export_csv($r) {
        $hid=(int)$r['hid'];
        $role=self::role($hid);
        if (is_wp_error($role)) { return $role; }
        $rows=self::entries($hid,array('scope'=>$r->get_param('scope'),'kind'=>$r->get_param('kind')),10001,0);
        if ($rows['total']>10000) { return self::error('超過 10,000 筆，請分批匯出',413); }
        $out=array();
        foreach ($rows['items'] as $row) {
            $out[]=array(
                '日期'=>$row['occurred_on'],'範圍'=>$row['scope'],'類型'=>$row['kind'],
                '分類'=>$row['category_name']??'','金額'=>$row['amount'],
                '備註'=>$row['note'],'付款方式'=>$row['payment_method'],
                '帳戶'=>$row['account_name']??'','轉入帳戶'=>$row['destination_account_name']??'',
                '付款通路'=>$row['payment_channel_name']??'','繳費狀態'=>$row['payment_status']
            );
        }
        return array('items'=>$out,'total'=>count($out));
    }
}
