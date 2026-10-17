<?php
// Isolated CLI test for private receipt directory confinement.
// Run: php tests/private-receipts.test.php safe|unsafe
error_reporting(E_ALL);
$mode = isset($argv[1]) ? $argv[1] : 'safe';
$tmp = sys_get_temp_dir() . '/wff-receipts-test-' . bin2hex(random_bytes(5));
if (!mkdir($tmp . '/public/wp-content/uploads', 0770, true)) { exit(1); }
define('ABSPATH', $tmp . '/public/');
define('WP_CONTENT_DIR', $tmp . '/public/wp-content');
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
if ($mode === 'unsafe') {
    define('WFF_PRIVATE_RECEIPTS_DIR', $tmp . '/public/wp-content/receipts');
}
class WP_Error {
    private $reason;
    public function __construct($code, $message, $data = null) { $this->reason=$message; }
    public function get_error_message(){return $this->reason;}
}
function is_wp_error($value){ return $value instanceof WP_Error; }
function wp_upload_dir($date = null, $create = true) {
    return array('basedir'=>ABSPATH . 'wp-content/uploads');
}
function wp_mkdir_p($path) {return @mkdir($path, 0700, true) || is_dir($path);}
$GLOBALS['wff_test_options']=array();
function get_option($key, $default='') {return $GLOBALS['wff_test_options'][$key] ?? $default;}
function add_option($key,$value,$unused='',$autoload=false) {
    if(array_key_exists($key,$GLOBALS['wff_test_options']))return false;
    $GLOBALS['wff_test_options'][$key]=$value;return true;
}
require dirname(__DIR__) . '/includes/class-wff-private-receipts.php';
function delete_tree($dir) {
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $file) {
        if ($file->isDot()) continue;
        if ($file->isDir() && !$file->isLink()) delete_tree($file->getPathname());
        else @unlink($file->getPathname());
    }
    @rmdir($dir);
}
try {
    $result = WFF_Private_Receipts::directory();
    if ($mode === 'unsafe') {
        // A PUBLIC path must never contain JPEG/PNG plaintext. It is only
        // usable as an authenticated, encrypted fallback.
        $private = new ReflectionMethod('WFF_Private_Receipts', 'private_directory');
        $private->setAccessible(true);
        if (!is_wp_error($private->invoke(null))) {
            throw new Exception('An unsafe explicit private path was accepted');
        }
        if (is_wp_error($result) || strpos($result, '.wff-encrypted-') === false) {
            throw new Exception('Encryption fallback not available');
        }
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==');
        $writer = new ReflectionMethod('WFF_Private_Receipts','write_bytes');
        $writer->setAccessible(true);
        $saved = $writer->invoke(null,$png,'image/png');
        if (is_wp_error($saved)) throw new Exception($saved->get_error_message());
        if (substr($saved['stored_name'],-4)!=='.enc') throw new Exception('Fallback wrote unencrypted photo');
        $bytes=file_get_contents($result.'/'.$saved['stored_name']);
        if (strpos($bytes,"\x89PNG")!==false || substr($bytes,0,7)!=='WFFENC1') {
            throw new Exception('Public disk contents include plaintext image data');
        }
        $decrypt = new ReflectionMethod('WFF_Private_Receipts','decrypt_image');
        $decrypt->setAccessible(true);
        $clear=$decrypt->invoke(null,$bytes);
        if ($clear!==$png) throw new Exception('Encrypted image roundtrip mismatch');
        $bytes[35]=chr(ord($bytes[35]) ^ 1);
        if (!is_wp_error($decrypt->invoke(null,$bytes))) {
            throw new Exception('Tampered ciphertext was not rejected');
        }
        echo "Unsafe path rejected; encrypted disk fallback / tamper protection: OK\n";
    } else {
        if (is_wp_error($result)) throw new Exception($result->get_error_message());
        $public = realpath(ABSPATH);
        if (strpos($result . '/', $public . '/') === 0) throw new Exception('Storage inside webroot');
        // A valid 1x1 pixel GIF is intentionally rejected; receipts accept JPG/PNG/WebP only.
        $validate = new ReflectionMethod('WFF_Private_Receipts', 'validate_bytes');
        $validate->setAccessible(true);
        $bad = $validate->invoke(null, base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs='));
        if (!is_wp_error($bad)) throw new Exception('Unsafe image format was accepted');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==');
        $write = new ReflectionMethod('WFF_Private_Receipts', 'write_bytes');
        $write->setAccessible(true);
        $saved = $write->invoke(null, $png, 'image/png');
        if (is_wp_error($saved)) throw new Exception($saved->get_error_message());
        $file = $result . '/' . $saved['stored_name'];
        if (!is_file($file) || hash_file('sha256', $file) !== hash('sha256', $png)) {
            throw new Exception('Private file bytes/checksum mismatch');
        }
        if (realpath(dirname($file)) !== $result) throw new Exception('File escaped private directory');
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($file) & 0007) !== 0) {
            throw new Exception('Receipt is readable by other users');
        }
        echo "Private receipt storage, file permissions, integrity, and MIME: OK\n";
    }
} finally {
    delete_tree($tmp);
}
