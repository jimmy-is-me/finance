<?php
require __DIR__.'/support/wp-sqlite.php';
require __DIR__.'/../includes/class-wff-storage.php';
require __DIR__.'/../includes/class-wff-api.php';
function check($ok,$msg){if(!$ok)throw new Exception($msg);}
function req($data=array()){return new Request(array_merge(array('hid'=>1),$data));}
$wpdb=new Database();
$wpdb->query('CREATE TABLE wp_wff_note_templates(id INTEGER PRIMARY KEY AUTOINCREMENT,household_id INTEGER,category_id INTEGER,note TEXT)');
$wpdb->query('CREATE TABLE wp_wff_members(household_id INTEGER,user_id INTEGER,role TEXT)');
$wpdb->query("INSERT INTO wp_wff_members VALUES(1,1,'owner'),(2,1,'viewer')");
$wpdb->query('CREATE TABLE wp_wff_tracking_codes(id INTEGER PRIMARY KEY AUTOINCREMENT,household_id INTEGER,name TEXT,UNIQUE(household_id,name))');
$wpdb->query('CREATE TABLE wp_wff_categories(id INTEGER PRIMARY KEY AUTOINCREMENT,household_id INTEGER,name TEXT,scope TEXT,kind TEXT,color TEXT,sort_order INTEGER,quick_notes TEXT,created_at TEXT)');
$wpdb->query('CREATE TABLE wp_wff_activity(household_id INTEGER,user_id INTEGER,action TEXT,object_id INTEGER,description TEXT,created_at TEXT)');
$wpdb->query('CREATE TABLE wp_wff_transactions(id INTEGER,household_id INTEGER,tracking_code TEXT,note TEXT,amount NUMERIC)');
$wpdb->query("INSERT INTO wp_wff_transactions VALUES(1,1,'仙台旅遊','手動輸入的備註',123)");
$r=WFF_API::save_tracking_code(req(array('name'=>'仙台旅遊')));check(!is_wp_error($r),'create code');$id=$r['id'];
check(is_wp_error(WFF_API::save_tracking_code(req(array('name'=>'仙台旅遊')))),'duplicate denied');
check(is_wp_error(WFF_API::save_tracking_code(req(array('name'=>str_repeat('字',61))))),'length validated');
check(is_wp_error(WFF_API::save_tracking_code(req(array('hid'=>2,'name'=>'不可寫入')))),'viewer denied');
check(is_wp_error(WFF_API::delete_tracking_code(req(array('hid'=>2,'id'=>$id)))),'other household denied');
$r=WFF_API::save_category(req(array('name'=>'早餐','scope'=>'family','kind'=>'expense','color'=>'#FFD93D','quick_notes'=>"元氣\r\n麥當勞\n元氣\n\n")));
check(!is_wp_error($r),'save category options');$cat=$r['id'];
check($wpdb->get_var('SELECT quick_notes FROM wp_wff_categories WHERE id='.$cat)==="元氣\n麥當勞",'normalize and deduplicate options');
$r=WFF_API::save_category(req(array('id'=>$cat,'name'=>'早餐','scope'=>'family','kind'=>'expense','color'=>'#FFD93D')));
check(!is_wp_error($r),'legacy edit');check($wpdb->get_var('SELECT quick_notes FROM wp_wff_categories WHERE id='.$cat)==="元氣\n麥當勞",'omitted notes preserved');
$r=WFF_API::save_category(req(array('id'=>$cat,'name'=>'早餐','scope'=>'family','kind'=>'expense','color'=>'#FFD93D','quick_notes'=>str_repeat('x',256))));check(is_wp_error($r),'oversized option denied');
check(!is_wp_error(WFF_API::delete_tracking_code(req(array('id'=>$id)))),'delete code');
$ledger=$wpdb->get_row('SELECT * FROM wp_wff_transactions WHERE id=1');check($ledger['tracking_code']==='仙台旅遊'&&$ledger['note']==='手動輸入的備註'&&$ledger['amount']==123,'deletion preserves ledger');
check($wpdb->get_var('SELECT COUNT(*) FROM wp_wff_tracking_codes')==0,'code removed');
$wpdb->query("INSERT INTO wp_wff_note_templates(household_id,category_id,note) VALUES(1,$cat,'Auto remembered'),(2,$cat,'Other household')");
$r=WFF_API::save_category(req(array('id'=>$cat,'name'=>'早餐','scope'=>'family','kind'=>'expense','color'=>'#FFD93D','quick_notes'=>"元氣\n麥當勞")));
check(!is_wp_error($r),'Managing notes succeeds');check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_wff_note_templates WHERE household_id=1')===0,'Removing learned note removes its saved default');check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_wff_note_templates WHERE household_id=2')===1,'Removing notes never affects other household');
echo "Category options and tracking code API tests passed\n";
