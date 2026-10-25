<?php
if (!defined('ABSPATH')) { exit; }

/** Monthly loan reminders with linked, unpaid ledger occurrences. */
final class WFF_Loans {
    const NS = 'wff/v1';
    private static function error($message, $status = 400) {
        return new WP_Error('wff_loan_error', $message, array('status'=>$status));
    }
    public static function register() {
        $base = '/households/(?P<hid>\d+)/loans';
        $permission = function($request) { return WFF_Loans::authorize((int)$request['hid']); };
        register_rest_route(self::NS, $base, array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'list_loans'),'permission_callback'=>$permission),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_loan'),'permission_callback'=>$permission),
        ));
        register_rest_route(self::NS, $base.'/(?P<id>\d+)', array(
            'methods'=>'PUT','callback'=>array(__CLASS__,'save_loan'),'permission_callback'=>$permission
        ));
        register_rest_route(self::NS, $base.'/(?P<id>\d+)/payments', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'save_payment'),'permission_callback'=>$permission
        ));
        register_rest_route(self::NS, $base.'/(?P<id>\d+)/payments/(?P<number>\d+)', array(
            'methods'=>'DELETE','callback'=>array(__CLASS__,'delete_payment'),'permission_callback'=>$permission
        ));
    }
    public static function authorize($hid, $require_editor = false) {
        if (!is_user_logged_in()) {
            return self::error('請先登入 Finance',401);
        }
        global $wpdb;
        $m=WFF_Storage::table('members');
        $role=$wpdb->get_var($wpdb->prepare(
            "SELECT role FROM $m WHERE household_id=%d AND user_id=%d", $hid, get_current_user_id()
        ));
        if (!$role) { return self::error('無權存取此家庭的貸款紀錄',403); }
        if ($require_editor && !in_array($role,array('owner','editor'),true)) {
            return self::error('只有可編輯成員能管理貸款',403);
        }
        return true;
    }
    private static function date($value) {
        $s=(string)$value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)) { return false; }
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$s,wp_timezone());
        return $d && $d->format('Y-m-d')===$s ? $s : false;
    }
    private static function amount($value, $allow_zero = false) {
        $s=trim((string)$value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/',$s)) { return false; }
        $v=(float)$s;
        if ($v > 10000000000 || (!$allow_zero && $v<=0)) { return false; }
        return round($v,2);
    }
    private static function loan($hid,$id) {
        global $wpdb;
        $table=WFF_Storage::table('loans');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id=%d AND household_id=%d",$id,$hid
        ),ARRAY_A);
    }
    private static function due($loan,$zero_based_index) {
        $first=DateTimeImmutable::createFromFormat(
            '!Y-m-d',substr($loan['first_due_on'],0,7).'-01',wp_timezone()
        );
        $period=$first->modify('+'.(int)$zero_based_index.' months');
        $day=min((int)$loan['due_day'],(int)$period->format('t'));
        return $period->format('Y-m-').sprintf('%02d',$day);
    }
    private static function month_delta($from, $to) {
        $a=substr((string)$from,0,7);
        $b=substr((string)$to,0,7);
        return ((int)substr($b,0,4)-(int)substr($a,0,4))*12
            + ((int)substr($b,5,2)-(int)substr($a,5,2));
    }
    // An unbounded monthly reminder uses a bounded 18-month viewing window.
    // Its installment number stays stable when browsing other months.
    // Snapshot each revision of the expected monthly amount. The real paid
    // amount is always stored separately in loan_payments.
    private static function amount_changes($hid, $id) {
        global $wpdb;
        $table=WFF_Storage::table('loan_amount_changes');
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT installment_no,monthly_payment FROM $table WHERE household_id=%d AND loan_id=%d ORDER BY installment_no",
            $hid,$id
        ),ARRAY_A);
        $changes=array();
        foreach($rows ?: array() as $row){
            if(!isset($row['installment_no'],$row['monthly_payment']))continue;
            $no=(int)$row['installment_no'];
            if($no>=1&&$no<=2400)$changes[]=array('installment_no'=>$no,'monthly_payment'=>(float)$row['monthly_payment']);
        }
        usort($changes,function($a,$b){return $a['installment_no']<=>$b['installment_no'];});
        return $changes;
    }
    private static function amount_for_installment($loan, $number) {
        $amount=(float)$loan['monthly_payment'];
        $changes=$loan['amount_changes']??array();
        if(!$changes)return round($amount,2);
        // An initial snapshot is always inserted at installment one when a
        // previous amount is replaced. Never extrapolate the newest rate back.
        $amount=(float)$changes[0]['monthly_payment'];
        foreach($changes as $change){
            if((int)$change['installment_no']>(int)$number)break;
            $amount=(float)$change['monthly_payment'];
        }
        return round($amount,2);
    }
    private static function simple_projection($loan, $payments, $window_offset = 0) {
        $paid_by_number=array();
        foreach ($payments as $p) { $paid_by_number[(int)$p['installment_no']]=$p; }
        $today=wp_date('Y-m-d');
        $first=$loan['first_due_on'];
        $selected=(new DateTimeImmutable('first day of this month',wp_timezone()))
            ->modify(($window_offset>=0?'+':'').(int)$window_offset.' months')->format('Y-m-d');
        $window_index=self::month_delta($first,$selected);
        $start=max(0,$window_index-5);
        $end=max(0,$window_index+12);
        // No more than 18 entries per loan regardless of years elapsed.
        $schedule=array();
        $monthly=(float)$loan['monthly_payment'];
        for($index=$start;$index<=$end;$index++) {
            $no=$index+1;
            $due=self::due($loan,$index);
            $p=$paid_by_number[$no]??null;
            $paid=$p && !empty($p['paid_on']);
            $schedule[]=array(
                'number'=>$no,'due_on'=>$due,'scheduled_amount'=>self::amount_for_installment($loan,$no),
                'interest_estimate'=>0,'principal_estimate'=>0,
                'status'=>$paid?'paid':($due<$today?'overdue':'upcoming'),
                'paid_amount'=>$p?(float)$p['paid_amount']:0,
                'extra_principal'=>0,
                'paid_on'=>$p['paid_on']??null,'note'=>$p['note']??'',
                'remaining_after_estimate'=>0
            );
        }
        // Find oldest unchecked due, including prior months outside the window.
        // Do not create any stored rows when showing a reminder.
        $max_scan=max(1,min(2400,self::month_delta($first,$today)+14));
        $next=null;$overdue=0;
        for($index=0;$index<$max_scan;$index++) {
            $due=self::due($loan,$index);
            $p=$paid_by_number[$index+1]??null;
            if($p && !empty($p['paid_on']))continue;
            if($next===null)$next=array(
                'number'=>$index+1,'due_on'=>$due,'scheduled_amount'=>self::amount_for_installment($loan,$index+1),
                'status'=>$due<$today?'overdue':'upcoming'
            );
            if($due<$today)$overdue++;
        }
        $total=0.0;$count=0;
        foreach($payments as $p) {
            if(!empty($p['paid_on'])) { $count++;$total+=(float)$p['paid_amount']; }
        }
        return array(
            'schedule'=>$schedule,'next_due'=>$loan['status']==='active'?$next:null,
            'overdue_count'=>$overdue,'paid_count'=>$count,'partial_count'=>0,
            'recorded_total'=>round($total,2),
            'remaining_principal_estimate'=>0,'regular_payment'=>$next?self::amount_for_installment($loan,$next['number']):$monthly,
            'simple'=>true,
            'window_offset'=>$window_offset
        );
    }
    private static function regular_amount($loan) {
        $configured=(float)$loan['monthly_payment'];
        if($configured>0) { return round($configured,2); }
        $p=(float)$loan['opening_principal'];
        $months=(int)$loan['installments'];
        $r=(float)$loan['annual_rate']/1200;
        if($months<1)return 0;
        if($r<=0)return round($p/$months,2);
        $factor=pow(1+$r,$months);
        return round($p*$r*$factor/($factor-1),2);
    }
    private static function projection($loan,$payments,$offset=0) {
        if (($loan['tracking_mode']??'amortized')==='simple') {
            return self::simple_projection($loan,$payments,$offset);
        }
        $by_number=array();
        foreach($payments as $payment){$by_number[(int)$payment['installment_no']]=$payment;}
        $balance=(float)$loan['opening_principal'];
        $rate=(float)$loan['annual_rate']/1200;
        $regular=self::regular_amount($loan);
        $now=wp_date('Y-m-d');
        $results=array();$total_paid=0.;$paid_count=0;$next=null;$overdue=0;$partial_count=0;
        $max=(int)$loan['installments'];
        for($index=0;$index<$max;$index++){
            if($balance<=0.005)break;
            $num=$index+1;
            $due=self::due($loan,$index);
            $interest=round($balance*$rate,2);
            $expected=min($regular,round($balance+$interest,2));
            $principal_est=round(max(0,$expected-$interest),2);
            $payment=$by_number[$num]??null;
            $paid=$payment?(float)$payment['paid_amount']:0.;
            $extra=$payment?(float)$payment['extra_principal']:0.;
            $fully_paid=$payment&&$paid+0.005 >= $expected;
            $status=$fully_paid?'paid':($payment?'partial':($due<$now?'overdue':'upcoming'));
            if($payment){
                $total_paid+=$paid+$extra;
                if($fully_paid)$paid_count++;else $partial_count++;
                $balance=max(0,round($balance-max(0,$paid-$interest)-$extra,2));
            }
            $row=array(
                'number'=>$num,'due_on'=>$due,'scheduled_amount'=>$expected,
                'interest_estimate'=>$interest,'principal_estimate'=>$principal_est,
                'status'=>$status,'paid_amount'=>$paid,'extra_principal'=>$extra,
                'paid_on'=>$payment['paid_on']??null,'note'=>$payment['note']??'',
                'remaining_after_estimate'=>$balance
            );
            if(!$fully_paid){
                if($next===null)$next=$row;
                if($due<$now)$overdue++;
            }
            $results[]=$row;
        }
        return array(
            'schedule'=>$results,'next_due'=>$loan['status']==='active'?$next:null,
            'overdue_count'=>$overdue,'paid_count'=>$paid_count,
            'partial_count'=>$partial_count,'recorded_total'=>round($total_paid,2),
            'remaining_principal_estimate'=>round($balance,2),
            'regular_payment'=>$regular,
        );
    }
    public static function generate($hid=0) {
        global $wpdb;
        $table=WFF_Storage::table('loans');$tx=WFF_Storage::table('transactions');$cats=WFF_Storage::table('categories');$pay=WFF_Storage::table('loan_payments');$members=WFF_Storage::table('members');
        $where=$hid?$wpdb->prepare(' AND household_id=%d',$hid):'';
        $rows=$wpdb->get_results("SELECT * FROM $table WHERE status='active' AND ledger_next<2400 AND (tracking_mode='simple' OR ledger_next<installments) AND DATE_ADD(first_due_on,INTERVAL ledger_next MONTH)<='".esc_sql(wp_date('Y-m-d'))."' $where ORDER BY updated_at,id LIMIT 100",ARRAY_A);
        foreach($rows as $loan){
            $lock='wff_loan_'.md5($wpdb->prefix.$loan['id']);
            if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)continue;
            try{
                $loan=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND status='active'",$loan['id']),ARRAY_A);
                if(!$loan)continue;
                if(($loan['tracking_mode']??'')==='simple') {
                    $loan['amount_changes']=self::amount_changes($loan['household_id'],$loan['id']);
                }
                $cat=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $cats WHERE household_id=%d AND scope=%s AND kind='expense' AND name='貸款繳款'",$loan['household_id'],$loan['scope']));
                if(!$cat){
                    if(!$wpdb->insert($cats,array('household_id'=>$loan['household_id'],'scope'=>$loan['scope'],'kind'=>'expense','name'=>'貸款繳款','color'=>'#2457c5','created_at'=>current_time('mysql',true))))continue;
                    $cat=(int)$wpdb->insert_id;
                }
                $owner=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM $members WHERE household_id=%d AND role='owner' ORDER BY user_id LIMIT 1",$loan['household_id']));
                for($batch=0;$batch<24;$batch++){
                    $index=(int)$loan['ledger_next'];$num=$index+1;$due=self::due($loan,$index);
                    if($index>=2400||$due>wp_date('Y-m-d')||($loan['tracking_mode']!=='simple'&&$num>(int)$loan['installments']))break;
                    $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM $tx WHERE loan_id=%d AND loan_installment=%d",$loan['id'],$num));
                    if(!$existing){
                        $p=$wpdb->get_row($wpdb->prepare("SELECT * FROM $pay WHERE loan_id=%d AND installment_no=%d",$loan['id'],$num),ARRAY_A);
                        $now=current_time('mysql',true);
                        if(!$wpdb->insert($tx,array('household_id'=>$loan['household_id'],'occurred_on'=>$due,'scope'=>$loan['scope'],'kind'=>'expense','category_id'=>$cat,'amount'=>$p?(float)$p['paid_amount']+(float)$p['extra_principal']:($loan['tracking_mode']==='simple'?self::amount_for_installment($loan,$num):self::regular_amount($loan)),'note'=>mb_substr($loan['name'].' · '.($p?$p['note']:$loan['notes']),0,255),'payment_status'=>$p&&!empty($p['paid_on'])?'paid':'unpaid','loan_id'=>$loan['id'],'loan_installment'=>$num,'payment_method'=>'other','created_by'=>$owner,'created_at'=>$now,'updated_at'=>$now)))break;
                    }
                    $loan['ledger_next']++;
                    if($wpdb->update($table,array('ledger_next'=>$loan['ledger_next'],'updated_at'=>current_time('mysql',true)),array('id'=>$loan['id']))===false)break;
                }
                $wpdb->update($table,array('updated_at'=>current_time('mysql',true)),array('id'=>$loan['id']));
            }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        }
    }
    public static function list_loans($request) {
        global $wpdb;
        $hid=(int)$request['hid'];
        self::generate($hid);
        $table=WFF_Storage::table('loans');
        $payments=WFF_Storage::table('loan_payments');
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE household_id=%d ORDER BY
             CASE WHEN status='active' THEN 0 ELSE 1 END,id DESC LIMIT 100",$hid
        ),ARRAY_A);
        $offset=max(-120,min(120,(int)$request->get_param('offset')));
        $all=array();
        foreach($rows as $loan){
            $pay=$wpdb->get_results($wpdb->prepare(
                "SELECT installment_no,paid_on,paid_amount,extra_principal,note
                 FROM $payments WHERE household_id=%d AND loan_id=%d ORDER BY installment_no",
                $hid,$loan['id']
            ),ARRAY_A);
            if(($loan['tracking_mode']??'')==='simple'){
                $loan['amount_changes']=self::amount_changes($hid,$loan['id']);
                $last_paid=0;
                foreach($pay as $p)if(!empty($p['paid_on']))$last_paid=max($last_paid,(int)$p['installment_no']);
                $latest_change=0;
                foreach($loan['amount_changes'] as $change)$latest_change=max($latest_change,(int)$change['installment_no']);
                $this_month=self::month_delta($loan['first_due_on'],wp_date('Y-m-d'))+1;
                $default_no=max(1,$last_paid+1,$this_month,$latest_change);
                $loan['amount_change_from']=substr(self::due($loan,min(2399,$default_no-1)),0,7);
            }
            $state=self::projection($loan,$pay,$offset);
            $all[]=array_merge($loan,$state);
        }
        return array('items'=>$all,'today'=>wp_date('Y-m-d'),'window_offset'=>$offset);
    }
    public static function save_loan($request) {
        $hid=(int)$request['hid'];
        $auth=self::authorize($hid,true);
        if(is_wp_error($auth))return $auth;
        global $wpdb;
        $id=(int)$request->get_param('id');
        $old=$id?self::loan($hid,$id):null;
        if($id && !$old)return self::error('找不到貸款',404);
        $name=trim(sanitize_text_field($request->get_param('name')));
        $lender=trim(sanitize_text_field($request->get_param('lender')));
        $scope=sanitize_key($request->get_param('scope'));
        $first=self::date($request->get_param('first_due_on'));
        $status=sanitize_key($request->get_param('status')?:'active');
        $raw_payment=$request->get_param('monthly_payment');
        $monthly=($raw_payment===null||$raw_payment==='')?0:self::amount($raw_payment,true);
        if(!$name||mb_strlen($name)>120||mb_strlen($lender)>120
            ||!in_array($scope,array('family','business'),true)
            ||!$first||$monthly===false
            ||!in_array($status,array('active','closed'),true)){
            return self::error('請確認名稱、每月預計金額與固定繳款日期');
        }
        $paytable=WFF_Storage::table('loan_payments');
        $count=$old?(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $paytable WHERE household_id=%d AND loan_id=%d",
            $hid,$id
        )):0;
        $mode=$old?($old['tracking_mode']?:'amortized'):'simple';
        // Existing advanced loan records retain their original principal,
        // APR and installment count. New records never require that metadata.
        $principal=$old?(float)$old['opening_principal']:0;
        $rate=$old?(float)$old['annual_rate']:0;
        $months=$old?(int)$old['installments']:0;
        if(($count || (int)($old['ledger_next']??0)>0) && $first!==$old['first_due_on']){
            return self::error('已有繳款紀錄，不能改動最初扣款日期，避免歷史月份對應錯誤');
        }
        if($mode==='amortized' && $old && $count &&
           abs((float)$old['monthly_payment']-$monthly)>0.005){
            return self::error('舊版貸款已有繳款紀錄，不能改動月繳額以免影響原本金估算');
        }
        if($mode==='amortized'){
            if($principal<=0 || $months<1) {
                return self::error('舊版貸款資料不完整，請聯絡網站管理員');
            }
            $monthly_rate=$rate/1200;
            if($monthly>0&&$monthly<=round($principal*$monthly_rate,2)){
                return self::error('每月應繳金額須大於舊貸款的估算利息');
            }
        }
        $now=current_time('mysql',true);
        $data=array(
            'scope'=>$scope,'name'=>mb_substr($name,0,120),
            'lender'=>mb_substr($lender,0,120),
            'tracking_mode'=>$mode,
            'opening_principal'=>$principal,
            'annual_rate'=>round($rate,4),
            'installments'=>$months,
            'monthly_payment'=>$monthly,
            'first_due_on'=>$first,
            'due_day'=>(int)substr($first,8,2),
            'status'=>$status,
            'notes'=>mb_substr(sanitize_text_field($request->get_param('notes')),0,255),
            'updated_at'=>$now
        );
        $table=WFF_Storage::table('loans');
        if($id){
            $ok=$wpdb->update($table,$data,array('id'=>$id,'household_id'=>$hid));
        }else{
            $data['household_id']=$hid;
            $data['created_at']=$now;
            $ok=$wpdb->insert($table,$data);
            $id=(int)$wpdb->insert_id;
        }
        if($ok===false)return self::error('儲存每月繳款追蹤失敗',500);
        WFF_Storage::activity($hid,$old?'update_loan':'create_loan',$id);
        self::generate($hid);
        return array('saved'=>true,'id'=>$id);
    }
    public static function save_payment($request) {
        global $wpdb;
        $members=WFF_Storage::table('members');
        if($wpdb->get_var($wpdb->prepare("SELECT role FROM $members WHERE household_id=%d AND user_id=%d",(int)$request['hid'],get_current_user_id()))!=='owner')return self::error('只有家庭管理員可確認繳費',403);
        $hid=(int)$request['hid'];
        $auth=self::authorize($hid,true);
        if(is_wp_error($auth))return $auth;
        $id=(int)$request['id'];
        $loan=self::loan($hid,$id);
        if(!$loan)return self::error('找不到貸款紀錄',404);
        if(($loan['tracking_mode']??'')==='simple')$loan['amount_changes']=self::amount_changes($hid,$id);
        $num=$request->get_param('installment_no');
        $simple=($loan['tracking_mode']??'amortized')==='simple';
        $max_no=$simple?2400:(int)$loan['installments'];
        if(!ctype_digit((string)$num)||(int)$num<1||(int)$num>$max_no){
            return self::error('繳款月份不正確');
        }
        $paid_on=self::date($request->get_param('paid_on'));
        $raw_amount=$request->get_param('paid_amount');
        $amount=$simple && ($raw_amount===null||$raw_amount==='') ? 0
            : self::amount($raw_amount, $simple);
        $extra_raw=$request->get_param('extra_principal');
        $extra=$simple?0:($extra_raw===null||$extra_raw===''?0:self::amount($extra_raw,true));
        if(!$paid_on||$amount===false||$amount<=0||$extra===false)return self::error('請輸入正確的繳款日期與金額');
        $note=mb_substr(sanitize_text_field($request->get_param('note')),0,255);
        self::generate($hid);
        $wpdb->query('START TRANSACTION');
        $table=WFF_Storage::table('loan_payments');
        $existing=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE household_id=%d AND loan_id=%d AND installment_no=%d",
            $hid,$id,$num
        ));
        $now=current_time('mysql',true);
        $data=array(
            'due_on'=>self::due($loan,(int)$num-1),'paid_on'=>$paid_on,
            'paid_amount'=>$amount,'extra_principal'=>$extra,'note'=>$note,
            'updated_at'=>$now
        );
        if($existing){
            $ok=$wpdb->update($table,$data,array('id'=>$existing,'household_id'=>$hid,'loan_id'=>$id));
        }else{
            $data['household_id']=$hid;$data['loan_id']=$id;
            $data['installment_no']=(int)$num;
            $data['created_by']=get_current_user_id();$data['created_at']=$now;
            $ok=$wpdb->insert($table,$data);
        }
        if($ok===false){$wpdb->query('ROLLBACK');return self::error('儲存繳款紀錄失敗',500);}
        $tx=WFF_Storage::table('transactions');
        if($wpdb->update($tx,array('payment_status'=>'paid','amount'=>$amount+$extra,'note'=>$loan['name'].($note?' · '.$note:''),'updated_at'=>$now),array('household_id'=>$hid,'loan_id'=>$id,'loan_installment'=>(int)$num))===false){$wpdb->query('ROLLBACK');return self::error('收支同步失敗，請重試',500);}
        $wpdb->query('COMMIT');
        WFF_Storage::activity($hid,'save_loan_payment',$id,'第'.(int)$num.'期');
        return array('saved'=>true,'installment_no'=>(int)$num);
    }
    public static function delete_payment($request) {
        global $wpdb;
        $members=WFF_Storage::table('members');
        if($wpdb->get_var($wpdb->prepare("SELECT role FROM $members WHERE household_id=%d AND user_id=%d",(int)$request['hid'],get_current_user_id()))!=='owner')return self::error('只有家庭管理員可確認繳費',403);
        $hid=(int)$request['hid'];
        $auth=self::authorize($hid,true);
        if(is_wp_error($auth))return $auth;
        $loan=self::loan($hid,(int)$request['id']);
        if(!$loan)return self::error('找不到貸款紀錄',404);
        if(($loan['tracking_mode']??'')==='simple')$loan['amount_changes']=self::amount_changes($hid,(int)$request['id']);
        $wpdb->query('START TRANSACTION');
        $table=WFF_Storage::table('loan_payments');
        $ok=$wpdb->delete($table,array(
            'household_id'=>$hid,'loan_id'=>(int)$request['id'],
            'installment_no'=>(int)$request['number']
        ));
        if($ok===false){$wpdb->query('ROLLBACK');return self::error('取消繳款紀錄失敗',500);}
        $tx=WFF_Storage::table('transactions');
        if($wpdb->update($tx,array('payment_status'=>'unpaid','amount'=>($loan['tracking_mode']==='simple'?self::amount_for_installment($loan,(int)$request['number']):self::regular_amount($loan)),'updated_at'=>current_time('mysql',true)),array('household_id'=>$hid,'loan_id'=>(int)$request['id'],'loan_installment'=>(int)$request['number']))===false){$wpdb->query('ROLLBACK');return self::error('收支同步失敗，請重試',500);}
        $wpdb->query('COMMIT');
        WFF_Storage::activity($hid,'undo_loan_payment',(int)$request['id'],'第'.(int)$request['number'].'期');
        return array('deleted'=>(bool)$ok);
    }
}
