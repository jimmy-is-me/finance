<?php
// Execute the recurring ledger and payment handlers against a stateful database double.
define('ABSPATH',__DIR__);define('ARRAY_A','ARRAY_A');
function esc_sql($s){return $s;}
function wp_timezone(){return new DateTimeZone('Asia/Taipei');}
function wp_date($f){return (new DateTimeImmutable('2026-10-18',wp_timezone()))->format($f);}
function current_time($f,$utc=false){return '2026-10-18 12:00:00';}
function get_current_user_id(){return 1;}
function is_user_logged_in(){return true;}
function sanitize_text_field($s){return trim((string)$s);}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Error{public function __construct(...$args){}}
class WFF_Storage{static function table($t){return $t;}static function activity(...$a){}}
class Request extends ArrayObject{function get_param($k){return $this[$k]??null;}}
class DB {
 public $prefix='wp_',$insert_id=0,$role='owner',$fail=false,$snapshot;
 public $data=array('loans'=>array(),'transactions'=>array(),'categories'=>array(),'loan_payments'=>array());
 function prepare($sql,...$args){if(strpos($sql,' AND household_id=')===0)return ' AND household_id='.(int)$args[0];return array($sql,$args);}
 function query($sql){if($sql==='START TRANSACTION')$this->snapshot=$this->data;if($sql==='ROLLBACK')$this->data=$this->snapshot;return 1;}
 function matches($row,$where){foreach($where as $k=>$v)if(($row[$k]??null)!=$v)return false;return true;}
 function get_results($q,$mode=null){return array_values(array_filter($this->data['loans'],fn($l)=>$l['status']==='active'));}
 function get_row($q,$mode=null){[$sql,$args]=$q;if(strpos($sql,'FROM loans')!==false){$l=$this->data['loans'][$args[0]]??null;return $l&&(!isset($args[1])||$l['household_id']==$args[1])?$l:null;}if(strpos($sql,'FROM loan_payments')!==false){foreach($this->data['loan_payments'] as $p)if($p['loan_id']==$args[0]&&$p['installment_no']==$args[1])return $p;}return null;}
 function get_var($q){[$sql,$args]=$q;if(strpos($sql,'GET_LOCK')!==false||strpos($sql,'RELEASE_LOCK')!==false)return 1;if(strpos($sql,'SELECT role')!==false)return $this->role;if(strpos($sql,'SELECT user_id')!==false)return 1;
 if(strpos($sql,'FROM categories')!==false){foreach($this->data['categories'] as $c)if($c['household_id']==$args[0]&&$c['scope']==$args[1])return $c['id'];return null;}
 if(strpos($sql,'FROM transactions')!==false){foreach($this->data['transactions'] as $t)if($t['loan_id']==$args[0]&&$t['loan_installment']==$args[1])return $t['id'];return null;}
 if(strpos($sql,'FROM loan_payments')!==false){foreach($this->data['loan_payments'] as $p)if($p['household_id']==$args[0]&&$p['loan_id']==$args[1]&&$p['installment_no']==$args[2])return $p['id'];return null;}throw new Exception($sql);}
 function insert($table,$row){$id=++$this->insert_id;$row['id']=$id;$this->data[$table][$id]=$row;return 1;}
 function update($table,$data,$where){if($table==='transactions'&&$this->fail)return false;$n=0;foreach($this->data[$table] as &$r)if($this->matches($r,$where)){$r=array_merge($r,$data);$n++;}return $n;}
 function delete($table,$where){$n=0;foreach($this->data[$table] as $id=>$r)if($this->matches($r,$where)){unset($this->data[$table][$id]);$n++;}return $n;}
}
function check($ok,$msg){if(!$ok)throw new Exception($msg);}
require __DIR__.'/../includes/class-wff-loans.php';
$wpdb=new DB();$wpdb->data['loans'][1]=array('id'=>1,'household_id'=>1,'name'=>'Car','scope'=>'family','notes'=>'','ledger_next'=>0,'tracking_mode'=>'simple','first_due_on'=>'2026-08-31','due_day'=>31,'monthly_payment'=>5000,'status'=>'active');
WFF_Loans::generate(1);check(count($wpdb->data['transactions'])===2,'Only elapsed August and September are generated');
check(array_column(array_values($wpdb->data['transactions']),'occurred_on')===array('2026-08-31','2026-09-30'),'Month-end clipping');
foreach($wpdb->data['transactions'] as $t)check($t['payment_status']==='unpaid','Default unpaid');
WFF_Loans::generate(1);check(count($wpdb->data['transactions'])===2,'No duplicates');
$r=new Request(array('hid'=>1,'id'=>1,'installment_no'=>'1','number'=>'1','paid_on'=>'2026-10-18','paid_amount'=>'5100','note'=>'Paid'));
check(!is_wp_error(WFF_Loans::save_payment($r)),'Owner payment accepted');
$t=array_values($wpdb->data['transactions'])[0];check($t['payment_status']==='paid'&&$t['amount']===5100.0,'Ledger follows loan payment');
$wpdb->fail=true;check(is_wp_error(WFF_Loans::delete_payment($r)),'Surface synchronization failure');check(count($wpdb->data['loan_payments'])===1,'Failure rolls back payment deletion');$wpdb->fail=false;
check(!is_wp_error(WFF_Loans::delete_payment($r)),'Undo succeeds');check(array_values($wpdb->data['transactions'])[0]['payment_status']==='unpaid','Undo resets ledger');
$wpdb->role='editor';check(is_wp_error(WFF_Loans::save_payment($r)),'Editor cannot mark paid');
$wpdb->data['loans'][1]['status']='closed';$before=count($wpdb->data['transactions']);WFF_Loans::generate(1);check(count($wpdb->data['transactions'])===$before,'Closed loan preserves history');
echo "Loan ledger generation, synchronization, permissions and rollback passed\n";
