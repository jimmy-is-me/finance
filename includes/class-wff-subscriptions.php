<?php
if (!defined('ABSPATH')) { exit; }

/** Recurring bills: one anchored occurrence per subscription and date. */
final class WFF_Subscriptions {
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register'));
        add_action('wff_subscription_tick', array(__CLASS__, 'tick'));
        add_action('init', function () {
            if (!wp_next_scheduled('wff_subscription_tick')) {
                wp_schedule_event(time() + 60, 'hourly', 'wff_subscription_tick');
            }
        });
    }
    public static function deactivate() { wp_clear_scheduled_hook('wff_subscription_tick'); }
    public static function register() {
        $base='/households/(?P<hid>\d+)/subscriptions';
        $read=function($r){return WFF_Loans::authorize((int)$r['hid']);};
        $write=function($r){return WFF_Loans::authorize((int)$r['hid'],true);};
        register_rest_route(WFF_API::NS,$base,array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'listing'),'permission_callback'=>$read),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save'),'permission_callback'=>$write),
        ));
        register_rest_route(WFF_API::NS,$base.'/(?P<id>\d+)',array('methods'=>'PUT','callback'=>array(__CLASS__,'save'),'permission_callback'=>$write));
        register_rest_route(WFF_API::NS,$base.'/payments/(?P<id>\d+)',array('methods'=>'PUT','callback'=>array(__CLASS__,'payment'),'permission_callback'=>$write));
    }
    private static function error($text) { return new WP_Error('wff_subscription',$text,array('status'=>400)); }
    private static function date($value) {
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value,wp_timezone());
        return $d && $d->format('Y-m-d')===$value ? $value : false;
    }
    public static function due($c,$index) {
        $start=new DateTimeImmutable($c['start_on'],wp_timezone());
        $n=(int)$c['interval']*(int)$index;
        if ($c['unit']==='week') return $start->modify('+'.($n*7).' days')->format('Y-m-d');
        if ($c['unit']==='day') return $start->modify('+'.$n.' days')->format('Y-m-d');
        $month=$start->modify('first day of this month')->modify('+'.($c['unit']==='year'?$n*12:$n).' months');
        return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$start->format('d'),(int)$month->format('t')))->format('Y-m-d');
    }
    public static function save($r) {
        global $wpdb;
        $hid=(int)$r['hid'];$id=(int)$r['id'];$table=WFF_Storage::table('subscriptions');
        $old=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND household_id=%d",$id,$hid),ARRAY_A):null;
        if($id&&!$old)return self::error('找不到訂閱');
        $c=array();
        foreach(array('name','scope','start_on','end_on','unit','note','payment_method') as $key)$c[$key]=sanitize_text_field($r->get_param($key)??'');
        foreach(array('category_id','account_id','payment_channel_id','interval') as $key)$c[$key]=absint($r->get_param($key));
        $c['amount']=(float)$r->get_param('amount');
        if(!$c['name']||mb_strlen($c['name'])>120||!self::date($c['start_on'])||($c['end_on']&&(!self::date($c['end_on'])||$c['end_on']<$c['start_on']))||!in_array($c['scope'],array('family','business'),true)||!in_array($c['unit'],array('day','week','month','year'),true)||$c['interval']<1||$c['interval']>120||$c['amount']<=0||$c['amount']>9999999999||floor($c['amount'])!==$c['amount'])return self::error('請確認名稱、整數金額、日期與週期（1–120）');
        $cat=WFF_Storage::table('categories');$accounts=WFF_Storage::table('accounts');
        if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $cat WHERE id=%d AND household_id=%d AND scope=%s AND kind='expense'",$c['category_id'],$hid,$c['scope'])))return self::error('請選擇同帳本的支出分類');
        $expected=array('cash'=>'cash','bank'=>'bank','card'=>'credit','mobile'=>'mobile','other'=>'other');
        if(!isset($expected[$c['payment_method']]))return self::error('付款方式不正確');
        foreach(array('account_id','payment_channel_id') as $key){
            if(!$c[$key])continue;
            $type=$wpdb->get_var($wpdb->prepare("SELECT type FROM $accounts WHERE id=%d AND household_id=%d AND enabled=1",$c[$key],$hid));
            if(!$type||($key==='payment_channel_id'&&$type!=='mobile')||($key==='account_id'&&!in_array($type,array('cash','bank','credit'),true)))return self::error('付款工具不屬於此家庭或已停用');
            if($key==='account_id'&&in_array($c['payment_method'],array('cash','bank','card'),true)&&$expected[$c['payment_method']]!==$type)return self::error('付款工具與方式不符');
        }
        if($c['payment_method']==='mobile'&&!$c['payment_channel_id'])return self::error('請選擇行動支付工具');
        if($c['payment_method']!=='mobile')$c['payment_channel_id']=0;
        if($old){$previous=json_decode($old['config'],true);foreach(array('start_on','interval','unit') as $key)if($c[$key]!=$previous[$key])return self::error('既有訂閱起日與週期不能修改，請停止後建立新訂閱，避免歷史重複');}
        $data=array('config'=>wp_json_encode($c),'active'=>$r->get_param('active')==='0'?0:1);
        if($id)$ok=$wpdb->update($table,$data,array('id'=>$id,'household_id'=>$hid));
        else{$data+=array('household_id'=>$hid,'created_by'=>get_current_user_id(),'next_due'=>$c['start_on']);$ok=$wpdb->insert($table,$data);$id=(int)$wpdb->insert_id;}
        if($ok===false)return self::error('訂閱儲存失敗');
        WFF_Storage::activity($hid,'save_subscription',$id);
        self::generate($hid);
        return array('id'=>$id);
    }
    public static function tick(){ self::generate(); }
    public static function generate($hid=0) {
        global $wpdb;
        $table=WFF_Storage::table('subscriptions');$tx=WFF_Storage::table('transactions');
        $where=$hid?$wpdb->prepare(' AND household_id=%d',$hid):'';
        // Bound work; oldest unprocessed rows first so large backlogs do not starve others.
        $rows=$wpdb->get_results("SELECT * FROM $table WHERE active=1 AND next_due <= '".esc_sql(wp_date('Y-m-d'))."' $where ORDER BY next_due,id LIMIT 100",ARRAY_A);
        foreach($rows as $row){
            $lock='wff_sub_'.md5($wpdb->prefix.$row['id']);
            if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)continue;
            try{
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND active=1",$row['id']),ARRAY_A);
                if(!$row)continue;
                $c=json_decode($row['config'],true);$index=(int)$row['next_index'];
                for($batch=0;$batch<24;$batch++){
                    $due=self::due($c,$index);
                    if($c['end_on']&&$due>$c['end_on']){$wpdb->update($table,array('active'=>0),array('id'=>$row['id']));break;}
                    if($due>wp_date('Y-m-d'))break;
                    $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $tx WHERE subscription_id=%d AND subscription_due=%s",$row['id'],$due));
                    if(!$existing){
                        $now=current_time('mysql',true);
                        $ok=$wpdb->insert($tx,array('household_id'=>$row['household_id'],'occurred_on'=>$due,'scope'=>$c['scope'],'kind'=>'expense','category_id'=>$c['category_id'],'amount'=>$c['amount'],'note'=>mb_substr($c['name'].($c['note']?' · '.$c['note']:''),0,255),'payment_method'=>$c['payment_method'],'account_id'=>$c['account_id']?:null,'payment_channel_id'=>$c['payment_channel_id']?:null,'payment_status'=>'unpaid','subscription_id'=>$row['id'],'subscription_due'=>$due,'created_by'=>$row['created_by'],'created_at'=>$now,'updated_at'=>$now));
                        if($ok===false)break;
                    }
                    $index++;
                    if($wpdb->update($table,array('next_index'=>$index,'next_due'=>self::due($c,$index)),array('id'=>$row['id']))===false)break;
                }
            }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        }
    }
    public static function listing($r){
        global $wpdb;$hid=(int)$r['hid'];self::generate($hid);$table=WFF_Storage::table('subscriptions');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE household_id=%d ORDER BY active DESC,id DESC LIMIT 500",$hid),ARRAY_A);
        foreach($rows as &$row){$row['settings']=json_decode($row['config'],true);unset($row['config']);$row['next_due']=self::due($row['settings'],$row['next_index']);}
        return array('items'=>$rows);
    }
    public static function payment($r){
        global $wpdb;$tx=WFF_Storage::table('transactions');$hid=(int)$r['hid'];$id=(int)$r['id'];
        $members=WFF_Storage::table('members');
        if($wpdb->get_var($wpdb->prepare("SELECT role FROM $members WHERE household_id=%d AND user_id=%d",$hid,get_current_user_id()))!=='owner')return self::error('只有家庭管理員可確認繳費');
        if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $tx WHERE id=%d AND household_id=%d",$id,$hid)))return self::error('找不到訂閱收支');
        $status=$r->get_param('paid')?'paid':'unpaid';
        $ok=$wpdb->update($tx,array('payment_status'=>$status,'updated_at'=>current_time('mysql',true)),array('id'=>$id,'household_id'=>$hid));
        if($ok===false)return self::error('更新失敗');
        WFF_Storage::activity($hid,'subscription_'.$status,$id);return array('saved'=>true);
    }
}
