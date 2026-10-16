<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Manual loan register and recurring monthly repayment schedule.
 *
 * Scheduled installments are projections, not actual financial transactions.
 * Posting a repayment here NEVER inserts a ledger expense/transfer, avoiding
 * duplicates with bank-imported and manually entered transactions.
 */
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
                'number'=>$no,'due_on'=>$due,'scheduled_amount'=>$monthly,
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
                'number'=>$index+1,'due_on'=>$due,'scheduled_amount'=>$monthly,
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
            'remaining_principal_estimate'=>0,'regular_payment'=>$monthly,
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
    public static function list_loans($request) {
        global $wpdb;
        $hid=(int)$request['hid'];
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
        if($id&&!$old)return self::error('找不到貸款',404);
        $name=trim(sanitize_text_field($request->get_param('name')));
        $lender=trim(sanitize_text_field($request->get_param('lender')));
        $scope=sanitize_key($request->get_param('scope'));
        $first=self::date($request->get_param('first_due_on'));
        $principal=self::amount($request->get_param('opening_principal'));
        $months=$request->get_param('installments');
        $rate=$request->get_param('annual_rate');
        $monthly_raw=$request->get_param('monthly_payment');
        $monthly=($monthly_raw===null||$monthly_raw==='')?0:self::amount($monthly_raw,true);
        $status=sanitize_key($request->get_param('status')?:'active');
        if(!$name||mb_strlen($name)>120||mb_strlen($lender)>120
           ||!in_array($scope,array('family','business'),true)||!$first||$principal===false
           ||!ctype_digit((string)$months)||(int)$months<1||(int)$months>600
           ||!is_numeric($rate)||(float)$rate<0||(float)$rate>80||$monthly===false
           ||!in_array($status,array('active','closed'),true)){
            return self::error('請檢查貸款名稱、金額、年利率、期數及首次繳款日期');
        }
        $monthly_rate=(float)$rate/1200;
        if($monthly>0&&$monthly<=round($principal*$monthly_rate,2)){
            return self::error('每月應繳金額需高於當期估算利息，否則本金不會下降');
        }
        $paytable=WFF_Storage::table('loan_payments');
        if($old){
            $count=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $paytable WHERE household_id=%d AND loan_id=%d",$hid,$id
            ));
            // Cannot silently change schedule meaning for existing repayments.
            if($count && (
                (string)$old['first_due_on']!==$first ||
                (int)$old['installments']!==(int)$months ||
                (float)$old['opening_principal']!==(float)$principal ||
                abs((float)$old['annual_rate']-(float)$rate)>0.00005 ||
                abs((float)$old['monthly_payment']-(float)$monthly)>0.005
            )){
                return self::error('已有繳款紀錄，無法直接更改首次繳款日、本金、期數、利率或月繳額，以免重新計算既有紀錄');
            }
        }
        $now=current_time('mysql',true);
        $data=array(
            'scope'=>$scope,'name'=>mb_substr($name,0,120),
            'lender'=>mb_substr($lender,0,120),
            'opening_principal'=>$principal,
            'annual_rate'=>round((float)$rate,4),
            'installments'=>(int)$months,
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
        if($ok===false)return self::error('儲存貸款失敗',500);
        WFF_Storage::activity($hid,$old?'update_loan':'create_loan',$id);
        return array('saved'=>true,'id'=>$id);
    }
    public static function save_payment($request) {
        global $wpdb;
        $hid=(int)$request['hid'];
        $auth=self::authorize($hid,true);
        if(is_wp_error($auth))return $auth;
        $id=(int)$request['id'];
        $loan=self::loan($hid,$id);
        if(!$loan)return self::error('找不到貸款紀錄',404);
        $num=$request->get_param('installment_no');
        if(!ctype_digit((string)$num)||(int)$num<1||(int)$num>(int)$loan['installments']){
            return self::error('還款期數不正確');
        }
        $paid_on=self::date($request->get_param('paid_on'));
        $amount=self::amount($request->get_param('paid_amount'));
        $extra_raw=$request->get_param('extra_principal');
        $extra=$extra_raw===null||$extra_raw===''?0:self::amount($extra_raw,true);
        if(!$paid_on||$amount===false||$extra===false)return self::error('請輸入正確的繳款日期與金額');
        $note=mb_substr(sanitize_text_field($request->get_param('note')),0,255);
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
        if($ok===false)return self::error('儲存繳款紀錄失敗',500);
        WFF_Storage::activity($hid,'save_loan_payment',$id,'第'.(int)$num.'期');
        return array('saved'=>true,'installment_no'=>(int)$num);
    }
    public static function delete_payment($request) {
        global $wpdb;
        $hid=(int)$request['hid'];
        $auth=self::authorize($hid,true);
        if(is_wp_error($auth))return $auth;
        $loan=self::loan($hid,(int)$request['id']);
        if(!$loan)return self::error('找不到貸款紀錄',404);
        $table=WFF_Storage::table('loan_payments');
        $ok=$wpdb->delete($table,array(
            'household_id'=>$hid,'loan_id'=>(int)$request['id'],
            'installment_no'=>(int)$request['number']
        ));
        if($ok===false)return self::error('取消繳款紀錄失敗',500);
        WFF_Storage::activity($hid,'undo_loan_payment',(int)$request['id'],'第'.(int)$request['number'].'期');
        return array('deleted'=>(bool)$ok);
    }
}
