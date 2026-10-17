<?php
if (!defined('ABSPATH')) { exit; }

/**
 * WP attachment records are only the catalogue for Finance receipts.
 * Image bytes remain outside public web roots. No physical file is registered
 * by WordPress public upload handlers; _wp_attached_file is intentionally absent.
 */
final class WFF_Receipt_Media {
    public static function init() {
        add_filter('wp_get_attachment_url', array(__CLASS__, 'protected_url'), 10, 2);
        add_filter('wp_prepare_attachment_for_js', array(__CLASS__, 'prepare_media'), 10, 3);
        add_action('wp_ajax_wff_receipt_image', array(__CLASS__, 'image'));
        add_action('delete_attachment', array(__CLASS__, 'attachment_deleted'));
        add_filter('map_meta_cap', array(__CLASS__, 'attachment_caps'), 10, 4);
    }

    public static function member($hid, $user = 0) {
        global $wpdb;
        $user = $user ?: get_current_user_id();
        if (!$user || !$hid) { return false; }
        $m = WFF_Storage::table('members');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $m WHERE household_id=%d AND user_id=%d",
            $hid, $user
        ));
    }

    private static function receipt_row($attachment_id) {
        global $wpdb;
        $table = WFF_Storage::table('receipts');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id,household_id,transaction_id,attachment_id,mime,stored_name,image_base64 FROM $table WHERE attachment_id=%d",
            $attachment_id
        ), ARRAY_A);
    }

    public static function attachment_caps($caps, $cap, $user_id, $args) {
        if (!in_array($cap, array('read_post','edit_post','delete_post'), true) ||
            empty($args[0])) { return $caps; }
        $row = self::receipt_row((int)$args[0]);
        if (!$row) { return $caps; }
        if (!self::member((int)$row['household_id'], (int)$user_id)) {
            return array('do_not_allow');
        }
        if ($cap !== 'read_post') {
            global $wpdb;
            $members = WFF_Storage::table('members');
            $role = $wpdb->get_var($wpdb->prepare(
                "SELECT role FROM $members WHERE household_id=%d AND user_id=%d",
                $row['household_id'], $user_id
            ));
            if (!in_array($role, array('owner', 'editor'), true)) {
                return array('do_not_allow');
            }
        }
        return $caps;
    }

    public static function protected_url($url, $attachment_id) {
        $row = self::receipt_row((int)$attachment_id);
        if (!$row) { return $url; }
        return self::member((int)$row['household_id']) ? self::url((int)$attachment_id) : '';
    }

    public static function url($attachment_id, $size = 'full') {
        return add_query_arg(array(
            'action' => 'wff_receipt_image',
            'attachment_id' => (int)$attachment_id,
            'size' => $size === 'thumb' ? 'thumb' : 'full',
            '_wpnonce' => wp_create_nonce('wff_receipt_media_' . (int)$attachment_id),
        ), admin_url('admin-ajax.php'));
    }

    public static function prepare_media($response, $attachment, $meta) {
        $id = (int)$attachment->ID;
        $row = self::receipt_row($id);
        if (!$row) { return $response; }
        if (!self::member((int)$row['household_id'])) {
            $response['url']='';
            $response['sizes']=array();
            $response['icon']='';
            $response['description']='私人收據，無權預覽';
            return $response;
        }
        $url = self::url($id);
        $thumb = self::url($id, 'thumb');
        $response['url']=$url;
        $response['sizes']=array(
            'full'=>array('url'=>$url,'width'=>0,'height'=>0,'orientation'=>'portrait'),
            'thumbnail'=>array('url'=>$thumb,'width'=>180,'height'=>180,'orientation'=>'portrait'),
        );
        $response['icon']=$thumb;
        $response['filename']='Finance 私人收據';
        $response['description']='此圖片僅能透過具家庭權限驗證的存取網址查看，沒有公開媒體庫原圖連結。';
        return $response;
    }

    /** Link an existing secure receipt row with a normal WP Media attachment. */
    public static function ensure($receipt_id) {
        global $wpdb;
        $table = WFF_Storage::table('receipts');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,household_id,transaction_id,attachment_id,mime,uploaded_by,stored_name
             FROM $table WHERE id=%d", $receipt_id
        ), ARRAY_A);
        if (!$row || !$row['stored_name']) { return false; }
        if ($row['attachment_id'] && get_post((int)$row['attachment_id'])) {
            return (int)$row['attachment_id'];
        }
        // File bytes are already saved privately by WFF_Private_Receipts.
        // Deliberately omit _wp_attached_file, GUID URL and WP image metadata.
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => $row['mime'],
            'post_title' => 'Finance 私人收據 #' . (int)$row['transaction_id'],
            'post_content' => '',
            'post_excerpt' => '',
            'post_status' => 'inherit',
            'post_author' => (int)$row['uploaded_by'],
            'guid' => 'urn:wumetax-finance:receipt:' . (int)$row['id'],
        ), '', 0, true);
        if (is_wp_error($attachment_id)) { return false; }
        $changed = $wpdb->update($table, array('attachment_id'=>(int)$attachment_id),
            array('id'=>(int)$row['id'], 'household_id'=>(int)$row['household_id']),
            array('%d'), array('%d','%d'));
        if ($changed === false) {
            wp_delete_attachment((int)$attachment_id, true);
            return false;
        }
        update_post_meta((int)$attachment_id, '_wff_receipt_household', (int)$row['household_id']);
        update_post_meta((int)$attachment_id, '_wff_receipt_transaction', (int)$row['transaction_id']);
        // Actual filename and storage directory must not enter attachment metadata.
        return (int)$attachment_id;
    }

    public static function image() {
        $id = absint($_GET['attachment_id'] ?? 0);
        if (!$id || !is_user_logged_in() ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'wff_receipt_media_' . $id)) {
            status_header(403);
            header('Cache-Control: private, no-store, max-age=0');
            exit;
        }
        $row = self::receipt_row($id);
        if (!$row || !self::member((int)$row['household_id'])) {
            status_header(403);
            header('Cache-Control: private, no-store, max-age=0');
            exit;
        }
        $image = WFF_Private_Receipts::bytes((int)$row['household_id'], (int)$row['transaction_id']);
        if (is_wp_error($image)) {
            status_header(404);
            header('Cache-Control: private, no-store, max-age=0');
            exit;
        }
        // No direct CDN cache, redirect or public file path. Media Library
        // thumbnails use constrained browser rendering of the authenticated image.
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Content-Security-Policy: default-src ' . "'none'; sandbox");
        header('Content-Type: ' . $image['mime']);
        header('Content-Length: ' . strlen($image['bytes']));
        echo $image['bytes'];
        exit;
    }

    /** Deleting via WP Media Library removes the associated private receipt. */
    public static function attachment_deleted($attachment_id) {
        global $wpdb;
        $row = self::receipt_row((int)$attachment_id);
        if (!$row) { return; }
        WFF_Private_Receipts::remove((int)$row['household_id'], (int)$row['transaction_id'], false);
    }
}
