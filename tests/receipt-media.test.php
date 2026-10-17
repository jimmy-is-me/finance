<?php
// Focused PHP mock for the WordPress private Media Library bridge.
define('ABSPATH', '/tmp/wordpress/');
define('ARRAY_A', 'ARRAY_A');
class MockDb {
    public $prefix = 'wp_';
    public function prepare($sql, ...$args) {
        return vsprintf(preg_replace('/%d/', '%d', $sql), $args);
    }
    public function get_row($sql, $type = null) {
        if (strpos($sql, 'attachment_id=11') !== false) {
            return array('id'=>7,'household_id'=>3,'transaction_id'=>5,
                'attachment_id'=>11,'mime'=>'image/png','stored_name'=>'foo.png','image_base64'=>'');
        }
        return null;
    }
    public function get_var($sql) {
        global $member_user;
        if (strpos($sql, 'household_id=3') !== false && strpos($sql, 'user_id=') !== false) {
            return $member_user ? 1 : null;
        }
        return null;
    }
}
$wpdb = new MockDb();
$member_user = true;
function get_current_user_id() {return 22;}
function wp_create_nonce($action) {return 'testnonce';}
function admin_url($path) {return 'https://example.invalid/wp-admin/' . $path;}
function add_query_arg($args,$url) {return $url . '?' . http_build_query($args);}
class WFF_Storage {
    public static function table($name) {return 'wp_wff_'.$name;}
}
require dirname(__DIR__) . '/includes/class-wff-receipt-media.php';
function check_it($pass,$message) {
    if(!$pass){fwrite(STDERR,$message."\n");exit(1);}
}
$url = WFF_Receipt_Media::protected_url('https://example.invalid/wp-content/uploads/receipt.png',11);
check_it(strpos($url,'action=wff_receipt_image') !== false, 'Media should use private endpoint');
check_it(strpos($url, 'uploads') === false, 'Media exposed original upload path');
check_it(strpos($url,'_wpnonce=testnonce') !== false,'Protected endpoint missing nonce');
$sample = array('url'=>'https://example.invalid/wp-content/uploads/receipt.png','sizes'=>array());
$media = WFF_Receipt_Media::prepare_media($sample,(object)array('ID'=>11),array());
check_it(strpos($media['url'],'wff_receipt_image')!==false,'WP media view missing secure image');
check_it(strpos($media['sizes']['thumbnail']['url'],'size=thumb')!==false,'WP thumbnail lacks secure URL');
$member_user = false;
$denied = WFF_Receipt_Media::protected_url('https://example.invalid/wp-content/uploads/receipt.png',11);
check_it($denied==='', 'Nonmember received an image URL');
$media = WFF_Receipt_Media::prepare_media($sample,(object)array('ID'=>11),array());
check_it($media['url']==='' && !$media['sizes'], 'Unauthorized Media view leaked picture');
echo "Private WP Media Library image URLs and household authorization: OK\n";
