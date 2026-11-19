<?php
/** Database-backed API regression using SQLite. Run with php-sqlite3 + mbstring. */
require __DIR__.'/support/wp-sqlite.php';
class WFF_Subscriptions{static function generate($hid){}}
class WFF_Loans{static function generate($hid){}}
class WFF_Insights{static function dashboard(...$a){return array();}}
require __DIR__.'/../includes/class-wff-storage.php';
require __DIR__.'/../includes/class-wff-card-cycles.php';
require __DIR__.'/../includes/class-wff-api.php';
function check($ok,$msg){if(!$ok)throw new Exception($msg);}
function req($data=array()){return new Request(array_merge(array('hid'=>1),$data));}
$wpdb=new Database();
$wpdb->query('CREATE TABLE wp_wff_note_templates(id INTEGER PRIMARY KEY AUTOINCREMENT,household_id INTEGER,category_id INTEGER,note TEXT,note_hash TEXT,payment_method TEXT,account_id INTEGER,payment_channel_id INTEGER,updated_at TEXT,UNIQUE(household_id,category_id,note_hash))');
$wpdb->query('CREATE TABLE users (ID INTEGER,display_name TEXT)');$wpdb->query("INSERT INTO users VALUES (1,'Owner')");
$wpdb->query('CREATE TABLE wp_wff_members (household_id INTEGER,user_id INTEGER,role TEXT)');$wpdb->query("INSERT INTO wp_wff_members VALUES(1,1,'owner')");
$wpdb->query('CREATE TABLE wp_wff_accounts(id INTEGER PRIMARY KEY,household_id INTEGER,name TEXT,type TEXT,statement_day INTEGER,due_day INTEGER)');
$wpdb->query("INSERT INTO wp_wff_accounts VALUES(4,1,'Undated Card','credit',NULL,NULL),(5,1,'Dated Card','credit',20,9),(6,2,'Other Family Card','credit',20,9)");
$wpdb->query("CREATE TABLE wp_wff_card_payments (id INTEGER PRIMARY KEY AUTOINCREMENT,household_id INTEGER,account_id INTEGER,statement_on TEXT,due_on TEXT,paid_on TEXT,amount NUMERIC,is_manual INTEGER DEFAULT 0,scope TEXT DEFAULT 'family',note TEXT DEFAULT '',tracking_code TEXT DEFAULT '',created_by INTEGER,created_at TEXT,updated_at TEXT,UNIQUE(household_id,account_id,statement_on))");
$wpdb->query("CREATE TABLE wp_wff_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT,household_id INTEGER,occurred_on TEXT,scope TEXT,kind TEXT,category_id INTEGER,amount NUMERIC,note TEXT DEFAULT '',tracking_code TEXT DEFAULT '',payment_status TEXT DEFAULT 'paid',card_payment_id INTEGER UNIQUE,statement_on TEXT,payment_due_on TEXT,payment_method TEXT,account_id INTEGER,destination_account_id INTEGER,payment_channel_id INTEGER,created_by INTEGER,created_at TEXT,updated_at TEXT,loan_id INTEGER,subscription_id INTEGER)");
$wpdb->query('CREATE TABLE wp_wff_categories(id INTEGER,household_id INTEGER,name TEXT,color TEXT)');
$wpdb->query('CREATE TABLE wp_wff_receipts(household_id INTEGER,transaction_id INTEGER)');
$wpdb->query('CREATE TABLE wp_wff_activity(id INTEGER PRIMARY KEY AUTOINCREMENT,household_id INTEGER,user_id INTEGER,action TEXT,object_id INTEGER,description TEXT,created_at TEXT)');
$record=req(array('account_id'=>4,'billing_month'=>'2026-11','paid_on'=>'2026-11-13','amount'=>'6900','note'=>'Bank paid','tracking_code'=>'TRIP','scope'=>'family'));
$result=WFF_API::save_card_payment($record);check(!is_wp_error($result),'Undated card allows a monthly payment');$pid=$result['id'];
check((int)$wpdb->get_var('SELECT count(*) FROM wp_wff_card_payments')===1,'One paid marker');
$ref=$wpdb->get_row('SELECT * FROM wp_wff_transactions');check($ref['kind']==='card_payment'&&(int)$ref['card_payment_id']===$pid,'Reference linked');check($ref['payment_due_on']===null,'No fictional due date');check($ref['tracking_code']==='TRIP','Reference code saved');
$record['amount']='7000';check(!is_wp_error(WFF_API::save_card_payment($record)),'Repeated same month succeeds');check((int)$wpdb->get_var('SELECT count(*) FROM wp_wff_transactions')===1,'No duplicate reference');check((float)$wpdb->get_var('SELECT amount FROM wp_wff_transactions')===7000.0,'Reference amount updates');
check((int)$wpdb->get_var("SELECT count(*) FROM wp_wff_transactions WHERE kind IN ('income','expense','transfer')")===0,'Reference does not affect expense or transfer balances');
$cards=WFF_API::card_payments(req());check(count($cards['cards'][4]['periods'])===12,'Undated monthly periods available');check($cards['cards'][4]['periods'][0]['payment']['id']===$pid,'Current manual month is paid');
$wpdb->fail_delete=true;check(is_wp_error(WFF_API::delete_card_payment(req(array('id'=>$pid)))),'Mirror deletion failure surfaces');check((int)$wpdb->get_var('SELECT count(*) FROM wp_wff_card_payments')===1,'Failed cancellation rolls back paid marker');$wpdb->fail_delete=false;
check(!is_wp_error(WFF_API::delete_card_payment(req(array('id'=>$pid)))),'Cancellation succeeds');check((int)$wpdb->get_var('SELECT count(*) FROM wp_wff_transactions')===0,'Reference removed with paid marker');
$dated=req(array('account_id'=>5,'statement_on'=>'2026-10-20','paid_on'=>'2026-11-13','amount'=>'2500'));
check(!is_wp_error(WFF_API::save_card_payment($dated)),'Cross-month fixed statement accepted');$dated['statement_on']='2026-10-19';check(is_wp_error(WFF_API::save_card_payment($dated)),'Mismatched statement rejected');
$dated['account_id']=6;check(is_wp_error(WFF_API::save_card_payment($dated)),'Other household card rejected');
$wpdb->query("UPDATE wp_wff_members SET role='viewer'");check(is_wp_error(WFF_API::save_card_payment($record)),'Viewer cannot mark card paid');$wpdb->query("UPDATE wp_wff_members SET role='owner'");
// Existing paid markers from previous versions gain reference rows once, in bounded batches.
$wpdb->query("DELETE FROM wp_wff_transactions");WFF_API::sync_card_payment_ledger(1);WFF_API::sync_card_payment_ledger(1);check((int)$wpdb->get_var('SELECT count(*) FROM wp_wff_transactions')===1,'Legacy paid marker backfills exactly once');
for($i=0;$i<45;$i++)$wpdb->insert('wp_wff_transactions',array('household_id'=>1,'occurred_on'=>$i<30?'2026-11-12':'2026-10-03','scope'=>$i%2?'family':'business','kind'=>'expense','amount'=>10,'tracking_code'=>'TRIP','note'=>'Meal','payment_status'=>$i===44?'unpaid':'paid'));
$wpdb->insert('wp_wff_transactions',array('household_id'=>2,'occurred_on'=>'2026-11-12','scope'=>'family','kind'=>'expense','amount'=>99999,'tracking_code'=>'TRIP'));
$all=WFF_API::transactions(req(array('month'=>'all','code'=>'TRIP','per_page'=>5,'page'=>2)));check($all['total']===45&&count($all['items'])===5,'Code filtering applies before count and pagination, across months');
$code_sum=array_sum(array_column($all['code_totals'],'amount'));check((float)$code_sum===450.0,'Code sums include all pages and exclude other households');
$family=WFF_API::transactions(req(array('month'=>'all','code'=>'TRIP','scope'=>'family')));check($family['total']===22,'Family code filter remains separate');
check(WFF_API::transactions(req(array('month'=>'all','code'=>"TRIP' OR 1=1 --")))['total']===0,'Code is safely bound');
$dash=WFF_API::dashboard(req(array('month'=>'2026-10')));check($dash['today']['date']==='2026-11-13'&&$dash['yesterday']['date']==='2026-11-12','Today and yesterday use local current day independently of selected month');check((float)$dash['yesterday']['totals']['expense']===300.0,'Yesterday aggregates all thirty entries, not twenty visible rows');
$log=WFF_API::activity(req());check($log[0]['action_label']!=='save_card_payment','Audit action translated');check(substr($log[0]['local_time'],11)==='15:00:00','Audit date uses site timezone');
$parser=new ReflectionMethod('WFF_API','parse_import');$parser->setAccessible(true);$parsed=$parser->invoke(null,array(array('日期','範圍','類型','金額','備註','代號'),array('2026-11-12','family','expense','100','A','TRIP'),array('2026-11-13','family','card_payment','7000','Paid','TRIP')),2026);
check(count($parsed['items'])===1&&$parsed['items'][0]['tracking_code']==='TRIP'&&$parsed['skipped_references']===1,'Import preserves code and cannot turn reference into another expense');
// Learned notes are scoped to the selected category and only updated on successful saves.
$wpdb->query("ALTER TABLE wp_wff_categories ADD COLUMN scope TEXT");$wpdb->query("ALTER TABLE wp_wff_categories ADD COLUMN kind TEXT");
$wpdb->query("INSERT INTO wp_wff_categories(id,household_id,name,color,scope,kind) VALUES(40,1,'Lunch','#ffffff','family','expense'),(41,1,'Business lunch','#ffffff','business','expense'),(42,2,'Other home','#ffffff','family','expense')");
$wpdb->query("INSERT INTO wp_wff_accounts(id,household_id,name,type) VALUES(10,1,'LINE Pay','mobile')");
$entry=array('category_id'=>40,'scope'=>'family','kind'=>'expense','occurred_on'=>'2026-11-13','amount'=>270,'note'=>'八方雲集','payment_method'=>'mobile','payment_channel_id'=>10,'account_id'=>4);
$saved=WFF_API::save_transaction(req($entry));check(!is_wp_error($saved),'Normal transaction saves with learned note');$txid=$saved['id'];
$template=$wpdb->get_row('SELECT * FROM wp_wff_note_templates WHERE category_id=40');check($template['note']==='八方雲集'&&$template['payment_method']==='mobile'&&$template['account_id']==4&&$template['payment_channel_id']==10,'Note retains wallet and funding source');
$entry['payment_method']='cash';$entry['payment_channel_id']=null;$entry['account_id']=null;
check(!is_wp_error(WFF_API::save_transaction(req($entry))),'Resaving same note succeeds');check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_wff_note_templates WHERE category_id=40')===1,'Identical note is deduplicated');check($wpdb->get_var('SELECT payment_method FROM wp_wff_note_templates WHERE category_id=40')==='cash','Latest successful payment preference wins');
$entry['amount']=-1;$entry['note']='Must not learn';check(is_wp_error(WFF_API::save_transaction(req($entry))),'Invalid save rejected');check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_wff_note_templates')===1,'Failed save never learns notes');
$entry['amount']=270;$entry['category_id']=42;check(is_wp_error(WFF_API::save_transaction(req($entry))),'Cannot learn another household category');
$entry['category_id']=41;$entry['scope']='business';$entry['note']='八方雲集';check(!is_wp_error(WFF_API::save_transaction(req($entry))),'Same note in another valid category learns separately');check((int)$wpdb->get_var('SELECT COUNT(*) FROM wp_wff_note_templates')===2,'Category scope remains separate');
$filtered=WFF_API::transactions(req(array('month'=>'2026-11','scope'=>'family','kind'=>'expense','category_id'=>40,'paid_only'=>1)));check($filtered['total']===2,'Overview category drill filters before pagination');
$exact=WFF_API::transactions(req(array('month'=>'all','id'=>$txid)));check($exact['total']===1&&$exact['items'][0]['note']==='八方雲集','Overview exact entry drill returns one matching ledger row');
check(WFF_API::transactions(req(array('month'=>'all','category_ids'=>'40,41','paid_only'=>1)))['total']===3,'Radar group filters include only selected categories');
check(WFF_API::transactions(req(array('month'=>'all','category_ids'=>'-1')))['total']===0,'Empty radar groups do not expand to all entries');
check(WFF_API::transactions(req(array('month'=>'all','category_id'=>42)))['total']===0,'Drilldown remains household scoped');
echo "Learned category notes, payment defaults and overview drilldown tests passed\n";
echo "Finance API: manual/fixed card payments, reference synchronization, rollback, legacy backfill, roles, household code totals, day summaries, audit labels and CSV passed\n";
