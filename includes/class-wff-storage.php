<?php
if (!defined('ABSPATH')) { exit; }

final class WFF_Storage {
    public static function table($name) {
        global $wpdb;
        $allowed = array('households','members','invites','categories','accounts','transactions','activity');
        if (!in_array($name, $allowed, true)) { return ''; }
        return $wpdb->prefix . 'wff_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $h = self::table('households');
        $m = self::table('members');
        $i = self::table('invites');
        $cat = self::table('categories');
        $a = self::table('accounts');
        $t = self::table('transactions');
        $log = self::table('activity');

        dbDelta("CREATE TABLE $h (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL,
            owner_user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY owner_user_id (owner_user_id)
        ) $c;");
        dbDelta("CREATE TABLE $m (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            household_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(16) NOT NULL DEFAULT 'viewer',
            joined_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY household_user (household_id,user_id),
            KEY user_id (user_id)
        ) $c;");
        dbDelta("CREATE TABLE $i (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            household_id bigint(20) unsigned NOT NULL,
            email varchar(190) NOT NULL,
            role varchar(16) NOT NULL DEFAULT 'editor',
            token_hash char(64) NOT NULL,
            expires_at datetime NOT NULL,
            accepted_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY household_id (household_id)
        ) $c;");
        dbDelta("CREATE TABLE $cat (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            household_id bigint(20) unsigned NOT NULL,
            scope varchar(16) NOT NULL,
            kind varchar(16) NOT NULL,
            name varchar(80) NOT NULL,
            color varchar(10) NOT NULL DEFAULT '#FFD93D',
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY category_unique (household_id,scope,kind,name),
            KEY category_order (household_id,scope,kind,sort_order),
            KEY household_id (household_id)
        ) $c;");
        dbDelta("CREATE TABLE $a (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            household_id bigint(20) unsigned NOT NULL,
            name varchar(80) NOT NULL,
            type varchar(16) NOT NULL,
            brand varchar(100) NOT NULL DEFAULT '',
            notes varchar(255) NOT NULL DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 1,
            sort_order int(11) NOT NULL DEFAULT 0,
            statement_day tinyint(3) unsigned DEFAULT NULL,
            due_day tinyint(3) unsigned DEFAULT NULL,
            funding_account_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY household_id (household_id)
        ) $c;");
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            household_id bigint(20) unsigned NOT NULL,
            occurred_on date NOT NULL,
            scope varchar(16) NOT NULL,
            kind varchar(16) NOT NULL,
            category_id bigint(20) unsigned DEFAULT NULL,
            amount decimal(14,2) NOT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            payment_method varchar(24) NOT NULL DEFAULT 'cash',
            payment_channel_id bigint(20) unsigned DEFAULT NULL,
            account_id bigint(20) unsigned DEFAULT NULL,
            destination_account_id bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY household_date (household_id,occurred_on),
            KEY household_scope (household_id,scope,kind,occurred_on),
            KEY account_id (account_id),
            KEY household_channel (household_id,payment_channel_id),
            KEY category_id (category_id)
        ) $c;");
        dbDelta("CREATE TABLE $log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            household_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            action varchar(60) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            description varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY household_id (household_id)
        ) $c;");
        // dbDelta adds missing columns/indexes without dropping historical data.
        // Legacy account IDs, transaction funding links and categories remain unchanged.
        update_option('wff_db_version', WFF_VERSION);
    }

    public static function seed_categories($household_id) {
        global $wpdb;
        $definitions = array(
            'family' => array(
                'expense' => array('早餐','午餐','下午茶','晚餐','消夜','飲料','咖啡','買菜','好市多','生活用品','交通','醫療','保健品','娛樂','旅遊','管理費','其他'),
                'income' => array('薪資','獎金','退款','其他收入'),
            ),
            'business' => array(
                'income' => array('網頁設計費','主機費','網站修改費','維護費','其他收入'),
                'expense' => array('主機費','網域費','面板費','外掛費','稅費','借址登記','薪資','營運費用','其他支出'),
            ),
        );
        $colors = array('#FFD93D','#FF6B6B','#C4B5FD','#99D9EA','#B5EAD7','#FFDAC1');
        foreach ($definitions as $scope => $kinds) {
            foreach ($kinds as $kind => $names) {
                foreach ($names as $index => $name) {
                    $wpdb->insert(self::table('categories'), array(
                        'household_id' => $household_id, 'scope' => $scope, 'kind' => $kind,
                        'name' => $name, 'color' => $colors[$index % count($colors)],
                        'sort_order' => ($index + 1) * 10,
                        'created_at' => current_time('mysql', true),
                    ));
                }
            }
        }
    }

    public static function activity($household_id, $action, $object_id = 0, $description = '') {
        global $wpdb;
        $wpdb->insert(self::table('activity'), array(
            'household_id' => (int) $household_id,
            'user_id' => get_current_user_id(),
            'action' => sanitize_key($action),
            'object_id' => (int) $object_id,
            'description' => sanitize_text_field($description),
            'created_at' => current_time('mysql', true),
        ));
    }
}
