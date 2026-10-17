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
    const MAX_BYTES = 5242880;
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
    private static function private_directory() {
        $app = realpath(ABSPATH);
        $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $content = realpath(WP_CONTENT_DIR);
        if (!$app || !$doc || !$content) {
            return self::issue('無法驗證 WordPress 與網站公開根目錄，請設定私人收據目錄並檢查伺服器 DOCUMENT_ROOT。');
        }
        $explicit = defined('WFF_PRIVATE_RECEIPTS_DIR') && WFF_PRIVATE_RECEIPTS_DIR;
        // Past documentation used placeholder /home/account and /home/example.
        // Ignore only these *exact examples*, so a copied demonstration path
        // does not permanently block zero-configuration private storage.
        $sample_paths = array(
            '/home/account/private/finance-receipts',
            '/home/example/private/finance-receipts',
        );
        if ($explicit && in_array(self::normalized(WFF_PRIVATE_RECEIPTS_DIR), $sample_paths, true)) {
            $explicit = false;
        }
        $legacy = self::normalized(dirname($app) . '/.wff-receipts-' . substr(hash('sha256', $app), 0, 16));
        $forbidden = array($app, $doc, $content);
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['basedir'])) {
            $uploads_dir = realpath($uploads['basedir']);
            if ($uploads_dir) { $forbidden[] = $uploads_dir; }
        }
        if ($explicit) {
            $raw = WFF_PRIVATE_RECEIPTS_DIR;
        } elseif (is_dir($legacy)) {
            // Prefer pre-existing v1.2.9 private files to avoid losing receipts.
            $raw = $legacy;
        } else {
            // Walk upward past WordPress and DOCUMENT_ROOT, e.g. xCloud's
            // sites/domain/public directory. Never guess inside uploads.
            $parent = dirname($app);
            $raw = null;
            for ($i = 0; $i < 9; $i++) {
                $root = realpath($parent);
                if (!$root || $root === '/' || $root === '.') { break; }
                $blocked = false;
                foreach ($forbidden as $forbidden_root) {
                    if (self::inside($root, $forbidden_root)) { $blocked = true; break; }
                }
                if (!$blocked && is_writable($root)) {
                    $raw = $root . '/.wff-receipts-' . substr(hash('sha256', $app), 0, 16);
                    break;
                }
                $parent = dirname($root);
            }
            if (!$raw) {
                return self::issue('找不到可寫入的網站根目錄外私人資料夾，為安全起見未將收據放進公開媒體庫。可由主機管理員建立站外私人目錄。');
            }
        }
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
        $candidate = self::normalized($parent . '/' . basename($path));
        foreach ($forbidden as $root) {
            if (self::inside($candidate, $root)) {
                return self::issue('收據儲存目錄位於網站公開區域，已拒絕上傳。請設定公開根目錄外的私人收據目錄。');
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

    /**
     * When a host cannot create a persistent directory outside its public
     * document root (common with managed Nginx), fall back to *encrypted*
     * image containers. Never store image plaintext beneath uploads.
     *
     * Browsers may download opaque .enc bytes if the web server exposes the
     * directory, but these are AES-256-GCM ciphertext, not JPEG/PNG/WebP data.
     * Only the authenticated Finance endpoint decrypts them.
     */
    private static function encrypted_directory() {
        if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            return self::issue('主機沒有 AES-256-GCM 加密功能，無法安全啟用加密收據儲存。');
        }
        $upload = wp_upload_dir(null, false);
        $basedir = !empty($upload['basedir']) ? realpath($upload['basedir']) : false;
        if (!$basedir || !is_dir($basedir)) {
            return self::issue('無法找到 WordPress 媒體目錄，收據沒有安全的檔案儲存空間。');
        }
        $dir = $basedir . '/.wff-encrypted-' . substr(hash('sha256', realpath(ABSPATH)), 0, 16);
        if (is_link($dir) || (!is_dir($dir) && !wp_mkdir_p($dir))) {
            return self::issue('無法建立 Finance 加密收據目錄，請檢查主機磁碟權限。');
        }
        $real = realpath($dir);
        if (!$real || $real !== $dir || !is_dir($real) || !is_writable($real)) {
            return self::issue('加密收據目錄無法寫入，已拒絕將照片放入公開媒體庫。');
        }
        @chmod($real, 0700);
        // Defence in depth only; Nginx does not consume .htaccess.
        if (!file_exists($real . '/index.php')) {
            @file_put_contents($real . '/index.php', "<?php http_response_code(403); exit;\n", LOCK_EX);
        }
        if (!file_exists($real . '/.htaccess')) {
            @file_put_contents($real . '/.htaccess', "Require all denied\nDeny from all\n", LOCK_EX);
        }
        $key = self::encryption_key();
        return is_wp_error($key) ? $key : $real;
    }

    private static function encryption_key() {
        $encoded = get_option('wff_receipt_aes256_key', '');
        if (!$encoded) {
            // If a key went missing, generating a new one would make already
            // saved ciphertext permanently unreadable. Fail closed.
            $upload = wp_upload_dir(null, false);
            $base = !empty($upload['basedir']) ? realpath($upload['basedir']) : false;
            $dir = $base ? $base . '/.wff-encrypted-' . substr(hash('sha256', realpath(ABSPATH)), 0, 16) : '';
            if ($dir && glob($dir . '/*.enc')) {
                return self::issue('找不到加密金鑰，但加密收據仍存在；請還原原本的 WordPress 資料庫備份，切勿產生新金鑰。');
            }
            $generated = base64_encode(random_bytes(32));
            add_option('wff_receipt_aes256_key', $generated, '', false);
            $encoded = get_option('wff_receipt_aes256_key', '');
        }
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            return self::issue('Finance 收據加密金鑰不存在或已損壞，請檢查資料庫備份。');
        }
        return $key;
    }

    private static function encrypt_image($bytes) {
        $key=self::encryption_key();
        if (is_wp_error($key)) { return $key; }
        $iv=random_bytes(12); $tag='';
        $encrypted=openssl_encrypt($bytes, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'WFF-RECEIPT-v1', 16);
        if ($encrypted === false || strlen($tag) !== 16) {
            return self::issue('收據加密失敗，未寫入照片。');
        }
        return 'WFFENC1' . $iv . $tag . $encrypted;
    }

    private static function decrypt_image($bytes) {
        if (!is_string($bytes) || strlen($bytes)<35 || substr($bytes,0,7)!=='WFFENC1') {
            return self::issue('私人收據加密檔格式不正確。');
        }
        $key=self::encryption_key();
        if (is_wp_error($key)) { return $key; }
        $clear=openssl_decrypt(substr($bytes,35), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($bytes,7,12), substr($bytes,19,16), 'WFF-RECEIPT-v1');
        return $clear===false ? self::issue('圖片解密或完整性驗證失敗。') : $clear;
    }

    private static function directory_for_file($name) {
        if (substr((string)$name, -4)==='.enc') {
            return self::encrypted_directory();
        }
        return self::private_directory();
    }

    public static function directory() {
        $private=self::private_directory();
        return !is_wp_error($private) ? $private : self::encrypted_directory();
    }

    public static function status() {
        global $wpdb;
        $table = WFF_Storage::table('receipts');
        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE stored_name='' AND LENGTH(image_base64)>0"
        );
        $dir = self::directory();
        $linked = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE attachment_id>0");
        $unlinked = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE attachment_id=0 AND stored_name<>''");
        return array(
            'linked'=>$linked, 'unlinked'=>$unlinked,
            'available' => !is_wp_error($dir),
            'directory' => is_wp_error($dir) ? '' : $dir,
            'message' => is_wp_error($dir) ? $dir->get_error_message() : '私人目錄可用（位於已知網站公開根目錄外）',
            'pending' => $pending,
            'last_error' => (string) get_option('wff_receipts_last_migration_error', ''),
        );
    }

    private static function real_file($dir, $name) {
        if (!preg_match('/^[a-f0-9]{48}\.(jpg|png|webp|enc)$/D', (string) $name)) {
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
            return self::issue('收據圖片必須小於 5 MB');
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
        $encrypted_mode = (strpos(basename($dir), '.wff-encrypted-') === 0);
        $payload = $encrypted_mode ? self::encrypt_image($bytes) : $bytes;
        if (is_wp_error($payload)) { return $payload; }
        for ($attempt=0; $attempt<3; $attempt++) {
            $name = bin2hex(random_bytes(24)) . ($encrypted_mode ? '.enc' : '.'.$verified['extension']);
            $path = self::real_file($dir, $name);
            if (is_wp_error($path)) { return $path; }
            $handle = @fopen($path, 'x+b');
            if (!$handle) { continue; }
            @chmod($path, 0600);
            $length = strlen($payload);
            $written = 0;
            while ($written < $length) {
                $n = fwrite($handle, substr($payload, $written));
                if ($n === false || $n === 0) { break; }
                $written += $n;
            }
            fflush($handle);
            fclose($handle);
            if ($written !== $length || @filesize($path) !== $length ||
                !hash_equals(hash('sha256', $payload), (string) @hash_file('sha256', $path))) {
                @unlink($path);
                return self::issue('圖片寫入或完整性驗證失敗，資料庫未更動');
            }
            return array('stored_name'=>$name, 'file_size'=>strlen($bytes), 'sha256'=>hash('sha256', $bytes));
        }
                return self::issue('無法建立私人收據檔案，請確認磁碟空間及權限');
    }

    private static function erase_file($name) {
        if (!$name) { return true; }
        $dir = self::directory_for_file($name);
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
            return self::issue('請上傳 5 MB 以內的有效收據圖片');
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
            $current = self::row($hid, $transaction);
            if ($current && !WFF_Receipt_Media::ensure((int)$current['id'])) {
                update_option('wff_receipts_last_migration_error',
                    '收據照片已安全保存，但無法建立 WordPress 媒體庫索引，請檢查 WordPress 附件權限', false);
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
            WFF_Receipt_Media::ensure((int)$row['id']);
            return true;
        } finally {
            self::unlock($lock);
        }
    }

    /** Called only after family membership has been verified by the caller. */
    public static function bytes($hid, $transaction) {
        $row = self::row($hid, $transaction);
        if (!$row) {
            return new WP_Error('wff_receipt_missing', '尚未附加收據', array('status'=>404));
        }
        if (!empty($row['stored_name'])) {
            $encrypted = (substr($row['stored_name'],-4)==='.enc');
            $dir = self::directory_for_file($row['stored_name']);
            if (is_wp_error($dir)) { return $dir; }
            $path = self::real_file($dir, $row['stored_name']);
            if (is_wp_error($path)) { return $path; }
            if (!is_readable($path) || filesize($path) > self::MAX_BYTES + 64) {
                return self::issue('收據暫時無法讀取，請聯絡管理員檢查備份');
            }
            $payload = @file_get_contents($path);
            if ($payload === false) { return self::issue('收據檔案讀取失敗'); }
            $bytes = $encrypted ? self::decrypt_image($payload) : $payload;
            if (is_wp_error($bytes)) { return $bytes; }
            if (!hash_equals((string)$row['sha256'],hash('sha256',$bytes))) {
                return self::issue('收據檔案完整性驗證失敗，請聯絡管理員');
            }
        } elseif (!empty($row['image_base64'])) {
            $bytes = base64_decode($row['image_base64'], true);
            if ($bytes === false) { return self::issue('舊版收據資料無法解碼'); }
        } else {
            return self::issue('收據索引存在但圖片內容遺失，請聯絡管理員');
        }
        if (is_wp_error(self::validate_bytes($bytes, $row['mime']))) {
            return self::issue('收據圖片已損毀或內容格式與記錄不符');
        }
        return array('mime'=>$row['mime'],'bytes'=>$bytes,'name'=>$row['filename']);
    }

    public static function fetch($hid, $transaction) {
        $image = self::bytes($hid, $transaction);
        if (is_wp_error($image)) { return $image; }
        $response = new WP_REST_Response(array(
            'mime'=>$image['mime'],
            'name'=>$image['name'],
            'data_url'=>'data:'.$image['mime'].';base64,'.base64_encode($image['bytes'])
        ), 200);
        $response->header('Cache-Control', 'private, no-store, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    public static function remove($hid, $transaction, $delete_attachment = true) {
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
            if ($delete_attachment && !empty($old['attachment_id'])) {
                wp_delete_attachment((int)$old['attachment_id'], true);
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
        // Older v1.2.9 file receipts may have no Media Library attachment.
        $file_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE stored_name<>%s AND attachment_id=0 ORDER BY id ASC LIMIT %d",
            '', self::BATCH_SIZE
        ));
        foreach ($file_ids as $file_id) {
            WFF_Receipt_Media::ensure((int)$file_id);
        }

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
