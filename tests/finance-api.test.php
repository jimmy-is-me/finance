<?php
/** Database-backed API regression using SQLite. Run with php-sqlite3 + mbstring. */
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');
function wp_timezone(){return new DateTimeZone('Asia/Taipei');}
function wp_date($f){return (new DateTimeImmutable('2026-11-13',wp_timezone()))->format($f);}
function current_time($f,$utc=false){return '2026-11-13 07:00:00';}
function get_current_user_id(){return 1;}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function is_wp_error($v){return $v instanceof WP_Error;}
function get_date_from_gmt($s,$f){return (new DateTimeImmutable($s,new DateTimeZone('UTC')))->setTimezone(wp_timezone())->format($f);}
class WP_Error{public $message;public function __construct($c,$m,$d=null){$this->message=$m;}}
class Request extends ArrayObject{function get_param($k){return $this[$k]??null;}}
class WFF_Subscriptions{static function generate($hid){}}
class WFF_Loans{static function generate($hid){}}
class WFF_Insights{static function dashboard(...$a){return array();}}
class Database {
 public $db,$prefix='wp_',$users='users',$insert_id=0,$fail_delete=false;
 function __construct(){
  $this->db=new SQLite3(':memory:');$this->db->enableExceptions(true);
  $this->db->createFunction('DATE_FORMAT',fn($date,$fmt)=>substr($date,0,7),2);
 }
 function prepare($sql,...$args){
  if(count($args)===1&&is_array($args[0]))$args=$args[0];$n=0;
  return preg_replace_callback('/%[%dsf]/',function($m)use(&$n,$args){if($m[0]==='%%')return '%';$v=$args[$n++];return $m[0]==='%d'?(string)(int)$v:"'".SQLite3::escapeString((string)$v)."'";},$sql);
 }
 function query($sql){if(strpos($sql,'START TRANSACTION')===0)$sql='BEGIN';$ok=$this->db->exec($sql);$this->insert_id=$this->db->lastInsertRowID();return $ok?$this->db->changes():false;}
 function get_results($sql,$m=null){$result=$this->db->query($sql);$out=array();while($r=$result->fetchArray(SQLITE3_ASSOC))$out[]=$r;return $out;}
 function get_row($sql,$m=null){$rows=$this->get_results($sql);return $rows[0]??null;}
 function get_var($sql){if(strpos($sql,'GET_LOCK')!==false||strpos($sql,'RELEASE_LOCK')!==false)return 1;$row=$this->get_row($sql);return $row?array_values($row)[0]:null;}
 function esc_like($s){return addcslashes($s,'_%\\');}
 function literal($v){return $v===null?'NULL':"'".SQLite3::escapeString((string)$v)."'";}
 function where($where){return implode(' AND ',array_map(fn($k)=>$k.'='.$this->literal($where[$k]),array_keys($where)));}
 function insert($table,$data){$sql="INSERT INTO $table (".implode(',',array_keys($data)).') VALUES ('.implode(',',array_map(fn($x)=>$this->literal($x),array_values($data))).')';$this->query($sql);return 1;}
 function update($table,$data,$where){return $this->query("UPDATE $table SET ".implode(',',array_map(fn($k)=>$k.'='.$this->literal($data[$k]),array_keys($data))).' WHERE '.$this->where($where));}
 function delete($table,$where){if($this->fail_delete&&$table==='wp_wff_transactions')return false;return $this->query("DELETE FROM $table WHERE ".$this->where($where));}
}
require __DIR__.'/../includes/class-wff-storage.php';
require __DIR__.'/../includes/class-wff-card-cycles.php';
require __DIR__.'/../includes/class-wff-api.php';
function check($ok,$msg){if(!$ok)throw new Exception($msg);}
function req($data=array()){return new Request(array_merge(array('hid'=>1),$data));}
$wpdb=new Database();
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
echo "Finance API: manual/fixed card payments, reference synchronization, rollback, legacy backfill, roles, household code totals, day summaries, audit labels and CSV passed\n";
