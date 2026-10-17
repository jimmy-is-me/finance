<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Receipt files are stored outside web-accessible roots; only the authenticated
 * household REST endpoint may read them. If a secure path cannot be proven,
 * refuse new uploads instead of falling back to public wp-content/uploads.
 *
 * v1.2.8 image_base64 stays readable until a private file has been written,
 * verified, and its location committed to the database.
 */
final class WFF_Private_Receipts {
    const MAX_BYTES = 3145728;
    const BATCH_SIZE = 3;

    public static function init() {
        add_action('wff_receipts_migrate_batch', array(__CLASS__, 'migrate_batch'));
        add_action('init', array(__CLASS__, 'schedule_migration'), 20);
    }

    private static function issue($message) {
        return new WP_Error('wff_private_receipts', $message, array('status' => 503));
    }

    private static function normalized($path) {
        return rtrim(str_replace('\\', '/', (string) $path), '/');
    }

    private static function inside($path, $root) {
        $a = self::normalized($path);
        $b = self::normalized($root);
        return $a === $b || strpos($a . '/', $b . '/') === 0;
    }

    /**
     * A storage directory is only accepted when the real path and its parent
     * are outside WordPress, the document root, and wp-content. Nginx ignores
     * .htaccess, so no webserver-specific deny rules are relied upon.
     *
     * For nonstandard hosting, set WFF_PRIVATE_RECEIPTS_DIR in wp-config.php
     * to an absolute directory outside every configured webserver document root.
     */
    public static function directory() {
        $app = realpath(ABSPATH);
        $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $content = realpath(WP_CONTENT_DIR);
        if (!$app || !$doc || !$content) {
            return self::issue('無法驗證 WordPress 與網站公開根目錄，請設定私人收據目錄並檢查伺服器 DOCUMENT_ROOT。');
        }
        $explicit = defined('WFF_PRIVATE_RECEIPTS_DIR') && WFF_PRIVATE_RECEIPTS_DIR;
        $raw = $explicit ? WFF_PRIVATE_RECEIPTS_DIR :
            dirname($app) . '/.wff-receipts-' . substr(hash('sha256', $app), 0, 16);
        if (!is_string($raw) || strpos($raw, '://') !== false ||
            !preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $raw)) {
            return self::issue('收據目錄必須是主機上的絕對實體路徑，不可使用公開網址或相對路徑。');
        }
        $path = self::normalized($raw);
        if (in_array(basename($path), array('.', '..'), true) || strpos($path, '/../') !== false
            || strpos($path, '/./') !== false) {
            return self::issue('私人收據目錄包含不安全的路徑片段。');
        }
        $parent = realpath(dirname($path));
        if (!$parent || is_link($path) || !$path || $path === '/') {
            return self::issue('私人收據目錄的上層路徑不存在，或目標是不安全的符號連結。');
        }
        $forbidden = array($app, $doc, $content);
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['basedir'])) {
            $uploads_dir = realpath($uploads['basedir']);
            if ($uploads_dir) { $forbidden[] = $uploads_dir; }
        }
        $candidate = self::normalized($parent . '/' . basename($path));
        foreach ($forbidden as $root) {
            if (self::inside($candidate, $root)) {
                return self::issue('收據儲存目錄位於網站公開區域或 WordPress 目錄內，已拒絕上傳。請在 wp-config.php 設定安全的 WFF_PRIVATE_RECEIPTS_DIR。');
            }
        }
        if (!is_dir($candidate)) {
            if (!wp_mkdir_p($candidate)) {
                return self::issue('無法建立私人收據目錄，請檢查目錄權限或設定 WFF_PRIVATE_RECEIPTS_DIR。');
            }
        }
        $actual = realpath($candidate);
        if (!$actual || !is_dir($actual) || is_link($candidate) ||
            self::normalized($actual) !== self::normalized($candidate)) {
            return self::issue('私人收據目錄驗證失敗，已停止收據上傳。');
        }
        foreach ($forbidden as $root) {
            if (self::inside($actual, $root)) {
                return self::issue('私人收據目錄位於可公開存取的網站路徑，已停止收據上傳。');
            }
        }
        @chmod($actual, 0700);
        if (!is_writable($actual)) {
            return self::issue('私人收據目錄不可寫入，請調整主機權限。');
        }
        return $actual;
    }

    public static function status() {
        global $wpdb;
        $table = WFF_Storage::table('receipts');
        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE stored_name='' AND LENGTH(image_base64)>0"
        );
        $dir = self::directory();
        return array(
            'available' => !is_wp_error($dir),
            'directory' => is_wp_error($dir) ? '' : $dir,
            'message' => is_wp_error($dir) ? $dir->get_error_message() : '私人目錄可用（位於已知網站公開根目錄外）',
            'pending' => $pending,
            'last_error' => (string) get_option('wff_receipts_last_migration_error', ''),
        );
    }

    private static function real_file($dir, $name) {
        if (!preg_match('/^[a-f0-9]{48}\.(jpg|png|webp)$/D', (string) $name)) {
            return self::issue('收據檔案索引不正確');
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_link($path)) { return self::issue('偵測到不安全的收據檔案連結'); }
        if (file_exists($path)) {
            $real = realpath($path);
            if (!$real || dirname($real) !== $dir || !is_file($real)) {
                return self::issue('收據檔案不在私人目錄內');
            }
        }
        return $path;
    }

    private static function validate_bytes($bytes, $expected_mime = null) {
        if (!is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > self::MAX_BYTES) {
            return self::issue('收據圖片必須小於 3 MB');
        }
        $image = @getimagesizefromstring($bytes);
        $allow = array('image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp');
        if (!$image || !isset($allow[$image['mime'] ?? '']) ||
            $image[0] < 1 || $image[1] < 1 || $image[0] > 6000 || $image[1] > 6000 ||
            ($expected_mime && $expected_mime !== $image['mime'])) {
            return self::issue('收據格式或尺寸不正確，僅支援 JPG、PNG、WebP（最大 6000px）');
        }
        return array('mime'=>$image['mime'], 'extension'=>$allow[$image['mime']]);
    }

    private static function write_bytes($bytes, $mime) {
        $dir = self::directory();
        if (is_wp_error($dir)) { return $dir; }
        $verified = self::validate_bytes($bytes, $mime);
        if (is_wp_error($verified)) { return $verified; }
        for ($attempt=0; $attempt<3; $attempt++) {
            $name = bin2hex(random_bytes(24)) . '.' . $verified['extension'];
            $path = self::real_file($dir, $name);
            if (is_wp_error($path)) { return $path; }
            $handle = @fopen($path, 'x+b');
            if (!$handle) { continue; }
            @chmod($path, 0600);
            $length = strlen($bytes);
            $written = 0;
            while ($written < $length) {
                $n = fwrite($handle, substr($bytes, $written));
                if ($n === false || $n === 0) { break; }
                $written += $n;
            }
            fflush($handle);
            fclose($handle);
            if ($written !== $length || @filesize($path) !== $length ||
                !hash_equals(hash('sha256', $bytes), (string) @hash_file('sha256', $path))) {
                @unlink($path);
                return self::issue('私人圖片寫入或完整性驗證失敗，資料庫未更動');
            }
            return array('stored_name'=>$name, 'file_size'=>$length, 'sha256'=>hash('sha256', $bytes));
        }
        return self::issue('無法建立私人收據檔案，請確認磁碟空間及權限');
    }

    private static function erase_file($name) {
        if (!$name) { return true; }
        $dir = self::directory();
        if (is_wp_error($dir)) { return false; }
        $path = self::real_file($dir, $name);
        if (is_wp_error($path)) { return false; }
        return !file_exists($path) || @unlink($path);
    }

    private static function lock($id) {
        global $wpdb;
        $key = 'wff_r_' . md5($wpdb->prefix . ':' . $id);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)', $key));
        return (int)$got === 1 ? $key : self::issue('收據正在更新，請稍後再試');
    }

    private static function unlock($key) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
    }

    private static function row($hid, $transaction) {
        global $wpdb;
        $t = WFF_Storage::table('receipts');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE household_id=%d AND transaction_id=%d",
            $hid, $transaction
        ), ARRAY_A);
    }

    /**
     * Store image bytes in an exclusive 0600 file, then commit only metadata
     * and an empty legacy BLOB column. Failed DB writes remove the new file.
     */
    public static function save($hid, $transaction, $uploaded, $user) {
        if (!$uploaded || !isset($uploaded['tmp_name'], $uploaded['size']) ||
            (int)$uploaded['size'] < 1 || (int)$uploaded['size'] > self::MAX_BYTES ||
            !empty($uploaded['error']) || !is_uploaded_file($uploaded['tmp_name'])) {
            return self::issue('請上傳 3 MB 以內的有效收據圖片');
        }
        $dir = self::directory();
        if (is_wp_error($dir)) { return $dir; } // fail closed
        $bytes = @file_get_contents($uploaded['tmp_name']);
        $image = self::validate_bytes($bytes);
        if (is_wp_error($image)) { return $image; }
        $lock = self::lock($transaction);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $stored = self::write_bytes($bytes, $image['mime']);
            if (is_wp_error($stored)) { return $stored; }
            global $wpdb;
            $old = self::row($hid, $transaction);
            $table = WFF_Storage::table('receipts');
            $data = array_merge($stored, array(
                'household_id'=>$hid, 'transaction_id'=>$transaction,
                'mime'=>$image['mime'],
                'image_base64'=>'',
                'filename'=>mb_substr(sanitize_file_name($uploaded['name'] ?? 'receipt.jpg'),0,190),
                'uploaded_by'=>$user,
                'created_at'=>current_time('mysql',true)
            ));
            if ($old) {
                $result = $wpdb->update($table, $data, array('id'=>(int)$old['id'], 'household_id'=>$hid));
            } else {
                $result = $wpdb->insert($table, $data);
            }
            if ($result === false) {
                self::erase_file($stored['stored_name']);
                return self::issue('收據檔案已寫入，但儲存索引失敗，已清除新檔案');
            }
            if ($old && !empty($old['stored_name']) && $old['stored_name'] !== $stored['stored_name']) {
                self::erase_file($old['stored_name']);
            }
            return array('saved'=>true, 'transaction_id'=>$transaction);
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Migrate one legacy Base64 row with a lock. Never delete the Base64
     * value before the durable file passes a read-back checksum and DB commit.
     */
    public static function migrate_one($id) {
        global $wpdb;
        $lock = self::lock($id);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $table = WFF_Storage::table('receipts');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE id=%d", (int)$id
            ), ARRAY_A);
            if (!$row || !empty($row['stored_name']) || empty($row['image_base64'])) {
                return true;
            }
            $bytes = base64_decode($row['image_base64'], true);
            $image = self::validate_bytes($bytes, $row['mime']);
            if (is_wp_error($image)) { return $image; }
            $stored = self::write_bytes($bytes, $image['mime']);
            if (is_wp_error($stored)) { return $stored; }
            $updated = $wpdb->update($table,
                array_merge($stored, array('image_base64'=>'')),
                array('id'=>(int)$row['id'], 'household_id'=>(int)$row['household_id'])
            );
            if ($updated !== 1) {
                self::erase_file($stored['stored_name']);
                return self::issue('舊收據搬移後更新資料庫失敗，原始 Base64 已保留');
            }
            return true;
        } finally {
            self::unlock($lock);
        }
    }

    public static function fetch($hid, $transaction) {
        $row = self::row($hid, $transaction);
        if (!$row) {
            return new WP_Error('wff_receipt_missing', '尚未附加收據', array('status'=>404));
        }
        if (!empty($row['stored_name'])) {
            $dir = self::directory();
            if (is_wp_error($dir)) { return $dir; }
            $path = self::real_file($dir, $row['stored_name']);
            if (is_wp_error($path)) { return $path; }
            if (!is_readable($path) || filesize($path) > self::MAX_BYTES) {
                return self::issue('私人收據暫時無法讀取，請聯絡管理員檢查備份');
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false || !hash_equals((string)$row['sha256'],hash('sha256',$bytes))) {
                return self::issue('收據檔案完整性驗證失敗，請聯絡管理員');
            }
            $base64 = base64_encode($bytes);
        } elseif (!empty($row['image_base64'])) {
            // Safe legacy fallback if file migration has not completed or
            // host storage is not configured; still household-authenticated.
            $base64 = $row['image_base64'];
        } else {
            return self::issue('收據索引存在但圖片內容遺失，請聯絡管理員');
        }
        $response = new WP_REST_Response(array(
            'mime'=>$row['mime'],
            'name'=>$row['filename'],
            'data_url'=>'data:'.$row['mime'].';base64,'.$base64
        ), 200);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    public static function remove($hid, $transaction) {
        global $wpdb;
        $lock = self::lock($transaction);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $old = self::row($hid, $transaction);
            if (!$old) { return array('deleted'=>false); }
            $table = WFF_Storage::table('receipts');
            $deleted = $wpdb->delete($table, array('id'=>(int)$old['id'], 'household_id'=>$hid));
            if ($deleted === false) { return self::issue('刪除收據索引失敗'); }
            if (!empty($old['stored_name']) && !self::erase_file($old['stored_name'])) {
                update_option('wff_receipts_last_migration_error', '已刪除收據索引，但磁碟檔案清理失敗：請檢查私人目錄權限', false);
            }
            return array('deleted'=>(bool)$deleted);
        } finally {
            self::unlock($lock);
        }
    }

    public static function schedule_migration() {
        if (!wp_next_scheduled('wff_receipts_migrate_batch')) {
            wp_schedule_event(time() + 120, 'hourly', 'wff_receipts_migrate_batch');
        }
    }

    public static function migrate_batch() {
        global $wpdb;
        $dir = self::directory();
        if (is_wp_error($dir)) {
            update_option('wff_receipts_last_migration_error', $dir->get_error_message(), false);
            return;
        }
        $t = WFF_Storage::table('receipts');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE stored_name=%s AND LENGTH(image_base64)>0 ORDER BY id ASC LIMIT %d",
            '', self::BATCH_SIZE
        ));
        $last_error = '';
        foreach ($ids as $id) {
            $result = self::migrate_one((int)$id);
            if (is_wp_error($result)) {
                $last_error = $result->get_error_message();
                break;
            }
        }
        update_option('wff_receipts_last_migration_error', $last_error, false);
    }
}
