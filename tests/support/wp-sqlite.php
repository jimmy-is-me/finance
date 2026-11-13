<?php
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
class Database {
 public $fail_update=false;
 public $db,$prefix='wp_',$users='users',$insert_id=0,$fail_delete=false;
 function __construct(){
  $this->db=new SQLite3(':memory:');$this->db->enableExceptions(true);
  $this->db->createFunction('loan_due',function($first,$index){$d=new DateTimeImmutable(substr($first,0,7).'-01');$d=$d->modify('+'.(int)$index.' months');return $d->format('Y-m-').sprintf('%02d',min((int)substr($first,8,2),(int)$d->format('t')));},2);
  $this->db->createFunction('DATE_FORMAT',fn($date,$fmt)=>substr($date,0,7),2);
 }
 function prepare($sql,...$args){
  if(count($args)===1&&is_array($args[0]))$args=$args[0];$n=0;
  return preg_replace_callback('/%[%dsf]/',function($m)use(&$n,$args){if($m[0]==='%%')return '%';$v=$args[$n++];return $m[0]==='%d'?(string)(int)$v:"'".SQLite3::escapeString((string)$v)."'";},$sql);
 }
 function query($sql){if(strpos($sql,'START TRANSACTION')===0)$sql='BEGIN';$ok=$this->db->exec($sql);$this->insert_id=$this->db->lastInsertRowID();return $ok?$this->db->changes():false;}
 function get_results($sql,$m=null){$sql=str_replace(array('DATE_ADD(first_due_on,INTERVAL ledger_next MONTH)',' FOR UPDATE'),array('loan_due(first_due_on,ledger_next)',''),$sql);$result=$this->db->query($sql);$out=array();while($r=$result->fetchArray(SQLITE3_ASSOC))$out[]=$r;return $out;}
 function get_col($sql){return array_map(fn($r)=>array_values($r)[0],$this->get_results($sql));}
 function get_row($sql,$m=null){$rows=$this->get_results($sql);return $rows[0]??null;}
 function get_var($sql){if(strpos($sql,'GET_LOCK')!==false||strpos($sql,'RELEASE_LOCK')!==false)return 1;$row=$this->get_row($sql);return $row?array_values($row)[0]:null;}
 function esc_like($s){return addcslashes($s,'_%\\');}
 function literal($v){return $v===null?'NULL':"'".SQLite3::escapeString((string)$v)."'";}
 function where($where){return implode(' AND ',array_map(fn($k)=>$k.'='.$this->literal($where[$k]),array_keys($where)));}
 function insert($table,$data){$sql="INSERT INTO $table (".implode(',',array_keys($data)).') VALUES ('.implode(',',array_map(fn($x)=>$this->literal($x),array_values($data))).')';$this->query($sql);return 1;}
 function update($table,$data,$where){if($this->fail_update&&$table==='wp_wff_transactions')return false;return $this->query("UPDATE $table SET ".implode(',',array_map(fn($k)=>$k.'='.$this->literal($data[$k]),array_keys($data))).' WHERE '.$this->where($where));}
 function delete($table,$where){if($this->fail_delete&&$table==='wp_wff_transactions')return false;return $this->query("DELETE FROM $table WHERE ".$this->where($where));}
}
function is_user_logged_in(){return true;}
function esc_sql($s){return SQLite3::escapeString((string)$s);}
