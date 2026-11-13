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
            array('methods'=>'PUT','callback'=>array(__CLASS__,'save_loan'),'permission_callback'=>$permission),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_loan'),'permission_callback'=>$permission)
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
                $terms=(int)($loan['installments']??0);
        $full=!empty($loan['_full_schedule']);
        $last_recorded=$paid_by_number?max(array_keys($paid_by_number)):0;
        $last_index=$terms>0?$terms-1:min(2399,max(0,$window_index+12,self::month_delta($first,$today)+12,$last_recorded-1));
        $start=$full?0:min(max(0,$window_index-5),max(0,$last_index-17));
        $end=$full?$last_index:min($last_index,max(0,$window_index+12));
        // Default view stays small; a requested full schedule begins with installment one.
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
                'extra_principal'=>0,'principal_amount'=>$p['principal_amount']??null,'interest_amount'=>$p['interest_amount']??null,
                'paid_on'=>$p['paid_on']??null,'note'=>$p['note']??'',
                'remaining_after_estimate'=>0
            );
        }
        // Find oldest unchecked due, including prior months outside the window.
        // Do not create any stored rows when showing a reminder.
        $max_scan=$terms>0?$terms:max(1,min(2400,self::month_delta($first,$today)+14,$last_index+1));
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
            'window_offset'=>$window_offset,'schedule_complete'=>$start===0&&$end===$last_index,'schedule_total'=>$last_index+1
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
                'status'=>$status,'paid_amount'=>$paid,'extra_principal'=>$extra,'principal_amount'=>$payment['principal_amount']??null,'interest_amount'=>$payment['interest_amount']??null,
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
    private static function totals($loan,$payments,$state){
        $terms=(int)($loan['installments']??0);$paid=0.0;$principal_paid=0.0;$with_principal=0;$recorded=0;
        foreach($payments as $p){
            if(empty($p['paid_on']))continue;
            $recorded++;$paid+=(float)$p['paid_amount']+(float)($p['extra_principal']??0);
            if(isset($p['principal_amount'])){$principal_paid+=(float)$p['principal_amount']+(float)($p['extra_principal']??0);$with_principal++;}
        }
        $planned=(float)($loan['repayment_total']??0);$manual=$planned>0;
        if(!$manual&&$terms>0){
            $planned=0.0;$known=true;
            for($i=1;$i<=$terms;$i++){
                $amount=($loan['tracking_mode']??'')==='simple'?self::amount_for_installment($loan,$i):self::regular_amount($loan);
                if($amount<=0)$known=false;
                $planned+=$amount;
            }
            if(!$known)$planned=null;
        }elseif(!$manual)$planned=null;
        $principal=(float)($loan['opening_principal']??0);
        return array('total_installments'=>$terms,'remaining_installments'=>$terms>0?max(0,$terms-(int)$state['paid_count']):null,
            'recorded_total'=>round($paid,2),'planned_total'=>$planned===null?null:round($planned,2),'planned_total_manual'=>$manual,
            'remaining_amount'=>$planned===null?null:round(max(0,$planned-$paid),2),'overpaid_amount'=>$planned===null?0:round(max(0,$paid-$planned),2),
            'principal_total'=>$principal>0?$principal:null,'principal_paid'=>round($principal_paid,2),'principal_incomplete'=>$recorded>$with_principal,
            'remaining_principal'=>$principal>0&&$recorded===$with_principal?round(max(0,$principal-$principal_paid),2):null);
    }
    public static function generate($hid=0) {
        global $wpdb;
        $table=WFF_Storage::table('loans');$tx=WFF_Storage::table('transactions');$cats=WFF_Storage::table('categories');$pay=WFF_Storage::table('loan_payments');$members=WFF_Storage::table('members');
        $where=$hid?$wpdb->prepare(' AND household_id=%d',$hid):'';
        $rows=$wpdb->get_results("SELECT * FROM $table WHERE status='active' AND ledger_next<2400 AND (installments=0 OR ledger_next<installments) AND DATE_ADD(first_due_on,INTERVAL ledger_next MONTH)<='".esc_sql(wp_date('Y-m-d'))."' $where ORDER BY updated_at,id LIMIT 100",ARRAY_A);
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
                    if($index>=2400||$due>wp_date('Y-m-d')||((int)$loan['installments']>0&&$num>(int)$loan['installments']))break;
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
        $full_id=(int)$request->get_param('full_id');
        if($full_id){$rows=array_values(array_filter($rows,function($row)use($full_id){return (int)$row['id']===$full_id;}));if(!$rows)return self::error('找不到此家庭的貸款',404);}
        $offset=max(-120,min(120,(int)$request->get_param('offset')));
        $all=array();
        foreach($rows as $loan){
            $pay=$wpdb->get_results($wpdb->prepare(
                "SELECT installment_no,paid_on,paid_amount,extra_principal,principal_amount,interest_amount,note
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
            $loan['_full_schedule']=$full_id===(int)$loan['id'];
            $state=self::projection($loan,$pay,$offset);
            $totals=self::totals($loan,$pay,$state);
            unset($loan['_full_schedule']);
            $all[]=array_merge($loan,$state,$totals);
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
        if($mode==='simple'){
            $raw_terms=$request->get_param('installments');
            if($raw_terms!==null){
                if($raw_terms!==''&&(!ctype_digit((string)$raw_terms)||(int)$raw_terms>2400))return self::error('總期數請填 1–2400 的整數，或留白');
                $months=$raw_terms===''?0:(int)$raw_terms;
            }
            $raw_principal=$request->get_param('opening_principal');
            if($raw_principal!==null)$principal=$raw_principal===''?0:self::amount($raw_principal,true);
            if($principal===false)return self::error('貸款本金總額請填零或正數');
        }
        $repayment_total=$old?(float)($old['repayment_total']??0):0;
        if($request->get_param('repayment_total')!==null){
            $raw_total=$request->get_param('repayment_total');$repayment_total=$raw_total===''?0:self::amount($raw_total,true);
            if($repayment_total===false)return self::error('預計總繳款金額請填零或正數');
        }
        if($months>0&&$old){
            $last_recorded=(int)$wpdb->get_var($wpdb->prepare("SELECT MAX(installment_no) FROM $paytable WHERE household_id=%d AND loan_id=%d",$hid,$id));
            if($last_recorded>$months)return self::error('總期數不能少於已登記繳款的最高期數');
        }

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
        // A changed fixed amount takes effect at the selected installment.
        // Never rewrite an installment already marked paid.
        $effective_no=0;
        $history=array();
        if($old && $mode==='simple' && abs((float)$old['monthly_payment']-$monthly)>0.005){
            $history=self::amount_changes($hid,$id);
            $change_month=(string)$request->get_param('amount_change_from');
            if($change_month!=='' && !preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/',$change_month)) {
                return self::error('金額生效月份格式不正確');
            }
            $paid_max=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(installment_no),0) FROM $paytable WHERE household_id=%d AND loan_id=%d AND paid_on IS NOT NULL",
                $hid,$id
            ));
            // Also respect entries explicitly marked paid in the general
            // ledger, including older data lacking a loan_payments row.
            $ledger_table=WFF_Storage::table('transactions');
            $ledger_paid_max=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(loan_installment),0) FROM $ledger_table WHERE household_id=%d AND loan_id=%d AND payment_status='paid'",
                $hid,$id
            ));
            $paid_max=max($paid_max,$ledger_paid_max);
            if($change_month==='') {
                $effective_no=max(1,$paid_max+1,self::month_delta($first,wp_date('Y-m-d'))+1);
            } else {
                $effective_no=self::month_delta($first,$change_month.'-01')+1;
            }
            $latest=0;
            foreach($history as $change)$latest=max($latest,(int)$change['installment_no']);
            if($effective_no<= $paid_max) {
                return self::error('生效月份包含已繳期數，請選擇最後一個已繳月份之後');
            }
            if($effective_no<max(1,$latest)||$effective_no>2400){
                return self::error('金額生效月份須不早於上一次調整，且須在貸款追蹤範圍內');
            }
        }
        $now=current_time('mysql',true);
        $data=array(
            'scope'=>$scope,'name'=>mb_substr($name,0,120),
            'lender'=>mb_substr($lender,0,120),
            'tracking_mode'=>$mode,
            'opening_principal'=>$principal,
            'annual_rate'=>round($rate,4),
            'installments'=>$months,'repayment_total'=>$repayment_total,
            'monthly_payment'=>$monthly,
            'first_due_on'=>$first,
            'due_day'=>(int)substr($first,8,2),
            'status'=>$status,
            'notes'=>mb_substr(sanitize_text_field($request->get_param('notes')),0,255),
            'updated_at'=>$now
        );
        $table=WFF_Storage::table('loans');
        $wpdb->query('START TRANSACTION');
        if($id){
            $live=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND household_id=%d FOR UPDATE",$id,$hid),ARRAY_A);
            if(!$live){$wpdb->query('ROLLBACK');return self::error('此貸款已移除，請重新整理',404);}
            if($months>0&&(int)$wpdb->get_var($wpdb->prepare("SELECT MAX(installment_no) FROM $paytable WHERE household_id=%d AND loan_id=%d",$hid,$id))>$months){$wpdb->query('ROLLBACK');return self::error('總期數不能少於已登記繳款的最高期數');}
            if($months>0)$data['ledger_next']=min((int)$old['ledger_next'],$months);
            $ok=$wpdb->update($table,$data,array('id'=>$id,'household_id'=>$hid));
        }else{
            $data['household_id']=$hid;
            $data['created_at']=$now;
            $ok=$wpdb->insert($table,$data);
            $id=(int)$wpdb->insert_id;
        }
        if($ok===false){$wpdb->query('ROLLBACK');return self::error('儲存每月繳款追蹤失敗',500);}
        if($effective_no){
            $changes_table=WFF_Storage::table('loan_amount_changes');
            if(!$history){
                // First change: keep the previous monthly amount before the
                // first affected installment (or only new amount from #1).
                if($effective_no>1){
                    $ok=$wpdb->insert($changes_table,array(
                        'household_id'=>$hid,'loan_id'=>$id,'installment_no'=>1,
                        'monthly_payment'=>$old['monthly_payment'],'created_at'=>$now
                    ));
                    if($ok===false){$wpdb->query('ROLLBACK');return self::error('保留原貸款金額失敗',500);}
                }
            }
            $existing_change=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $changes_table WHERE household_id=%d AND loan_id=%d AND installment_no=%d",
                $hid,$id,$effective_no
            ));
            if($existing_change){
                $ok=$wpdb->update($changes_table,array('monthly_payment'=>$monthly),array(
                    'id'=>$existing_change,'household_id'=>$hid,'loan_id'=>$id
                ));
            }else{
                $ok=$wpdb->insert($changes_table,array(
                    'household_id'=>$hid,'loan_id'=>$id,'installment_no'=>$effective_no,
                    'monthly_payment'=>$monthly,'created_at'=>$now
                ));
            }
            if($ok===false){$wpdb->query('ROLLBACK');return self::error('儲存金額調整紀錄失敗',500);}
            // Only alter generated UNPAID reminders from the effective month,
            // never touch actual historical paid amounts or paid markers.
            $tx=WFF_Storage::table('transactions');
            $synced=$wpdb->query($wpdb->prepare(
                "UPDATE $tx SET amount=%s,updated_at=%s WHERE household_id=%d AND loan_id=%d AND kind='expense' AND payment_status='unpaid' AND loan_installment >=%d",
                $monthly,$now,$hid,$id,$effective_no
            ));
            if($synced===false){$wpdb->query('ROLLBACK');return self::error('更新尚未繳款期數失敗',500);}
        }
        if($months>0){
            $tx=WFF_Storage::table('transactions');
            if($wpdb->query($wpdb->prepare("DELETE FROM $tx WHERE household_id=%d AND loan_id=%d AND loan_installment>%d AND payment_status='unpaid'",$hid,$id,$months))===false){$wpdb->query('ROLLBACK');return self::error('期數範圍更新失敗',500);}
        }
        $wpdb->query('COMMIT');
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
        $max_no=(int)$loan['installments']>0?(int)$loan['installments']:2400;
        if(!ctype_digit((string)$num)||(int)$num<1||(int)$num>$max_no){
            return self::error('繳款月份不正確');
        }
        $paid_on=self::date($request->get_param('paid_on'));
        $raw_amount=$request->get_param('paid_amount');
        $amount=$simple && ($raw_amount===null||$raw_amount==='') ? 0
            : self::amount($raw_amount, $simple);
        $extra_raw=$request->get_param('extra_principal');
        $extra=$simple?0:($extra_raw===null||$extra_raw===''?0:self::amount($extra_raw,true));
        $principal_raw=$request->get_param('principal_amount');$interest_raw=$request->get_param('interest_amount');
        $principal=null;$interest=null;
        if(($principal_raw!==null&&$principal_raw!=='')||($interest_raw!==null&&$interest_raw!=='')){
            $principal=self::amount($principal_raw===null||$principal_raw===''?'0':$principal_raw,true);
            $interest=self::amount($interest_raw===null||$interest_raw===''?'0':$interest_raw,true);
            if($principal===false||$interest===false)return self::error('本金與利息請填寫零或正數，最多兩位小數');
            $amount=round($principal+$interest,2);
            if($amount>10000000000)return self::error('本金與利息合計超過上限');
        }
        if(!$paid_on||$amount===false||$amount<=0||$extra===false)return self::error('請輸入正確的繳款日期與金額');
        $note=mb_substr(sanitize_text_field($request->get_param('note')),0,255);
        self::generate($hid);
        $wpdb->query('START TRANSACTION');
        $loans_table=WFF_Storage::table('loans');
        $live=$wpdb->get_row($wpdb->prepare("SELECT * FROM $loans_table WHERE id=%d AND household_id=%d FOR UPDATE",$id,$hid),ARRAY_A);
        if(!$live){$wpdb->query('ROLLBACK');return self::error('此貸款已移除，請重新整理',404);}
        if((int)$live['installments']>0&&(int)$num>(int)$live['installments']){$wpdb->query('ROLLBACK');return self::error('繳款期數超過貸款總期數');}

        $table=WFF_Storage::table('loan_payments');
        $existing=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE household_id=%d AND loan_id=%d AND installment_no=%d",
            $hid,$id,$num
        ));
        $now=current_time('mysql',true);
        $data=array(
            'due_on'=>self::due($loan,(int)$num-1),'paid_on'=>$paid_on,
            'paid_amount'=>$amount,'extra_principal'=>$extra,'principal_amount'=>$principal,'interest_amount'=>$interest,'note'=>$note,
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
        $existing_tx=$wpdb->get_var($wpdb->prepare("SELECT id FROM $tx WHERE household_id=%d AND loan_id=%d AND loan_installment=%d",$hid,$id,(int)$num));
        $entry=array('payment_status'=>'paid','amount'=>$amount+$extra,'note'=>mb_substr($loan['name'].($note?' · '.$note:''),0,255),'occurred_on'=>$paid_on,'updated_at'=>$now);
        if($existing_tx)$synced=$wpdb->update($tx,$entry,array('id'=>$existing_tx,'household_id'=>$hid));
        else{
            $cats=WFF_Storage::table('categories');
            $cat=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $cats WHERE household_id=%d AND scope=%s AND kind='expense' AND name='貸款繳款'",$hid,$loan['scope']));
            if(!$cat){
                if($wpdb->insert($cats,array('household_id'=>$hid,'scope'=>$loan['scope'],'kind'=>'expense','name'=>'貸款繳款','color'=>'#2457c5','created_at'=>$now))===false){$wpdb->query('ROLLBACK');return self::error('貸款分類建立失敗',500);}
                $cat=(int)$wpdb->insert_id;
            }
            $entry+=array('household_id'=>$hid,'scope'=>$loan['scope'],'kind'=>'expense','category_id'=>$cat,'loan_id'=>$id,'loan_installment'=>(int)$num,'payment_method'=>'other','created_by'=>get_current_user_id(),'created_at'=>$now);
            $synced=$wpdb->insert($tx,$entry);
        }
        if($synced===false){$wpdb->query('ROLLBACK');return self::error('收支同步失敗，請重試',500);}
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
        if($wpdb->update($tx,array('payment_status'=>'unpaid','occurred_on'=>self::due($loan,(int)$request['number']-1),'amount'=>($loan['tracking_mode']==='simple'?self::amount_for_installment($loan,(int)$request['number']):self::regular_amount($loan)),'updated_at'=>current_time('mysql',true)),array('household_id'=>$hid,'loan_id'=>(int)$request['id'],'loan_installment'=>(int)$request['number']))===false){$wpdb->query('ROLLBACK');return self::error('收支同步失敗，請重試',500);}
        $wpdb->query('COMMIT');
        WFF_Storage::activity($hid,'undo_loan_payment',(int)$request['id'],'第'.(int)$request['number'].'期');
        return array('deleted'=>(bool)$ok);
    }
    public static function delete_loan($request){
        global $wpdb;$hid=(int)$request['hid'];$id=(int)$request['id'];
        $auth=self::authorize($hid,true);if(is_wp_error($auth))return $auth;
        $members=WFF_Storage::table('members');
        if($wpdb->get_var($wpdb->prepare("SELECT role FROM $members WHERE household_id=%d AND user_id=%d",$hid,get_current_user_id()))!=='owner')return self::error('只有家庭管理員可刪除貸款',403);
        $loan=self::loan($hid,$id);if(!$loan)return self::error('找不到貸款',404);
        if($request->get_param('confirm_delete')!==true||$request->get_param('confirmation_name')!==$loan['name'])return self::error('請完成兩次刪除確認');
        $lock='wff_loan_'.md5($wpdb->prefix.$id);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1)return self::error('貸款正在更新，請稍後重試',409);
        try{
            $wpdb->query('START TRANSACTION');$table=WFF_Storage::table('loans');
            $live=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND household_id=%d FOR UPDATE",$id,$hid),ARRAY_A);
            if(!$live){$wpdb->query('ROLLBACK');return self::error('此貸款已移除',404);}
            $tx=WFF_Storage::table('transactions');
            $ok=$wpdb->update($tx,array('loan_id'=>null,'loan_installment'=>null),array('household_id'=>$hid,'loan_id'=>$id));
            foreach(array('loan_payments','loan_amount_changes') as $name){if($ok!==false)$ok=$wpdb->delete(WFF_Storage::table($name),array('household_id'=>$hid,'loan_id'=>$id));}
            if($ok!==false)$ok=$wpdb->delete($table,array('household_id'=>$hid,'id'=>$id));
            if($ok===false){$wpdb->query('ROLLBACK');return self::error('刪除失敗，資料已保留，請重試',500);}
            $wpdb->query('COMMIT');
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        WFF_Storage::activity($hid,'delete_loan',$id,$loan['name'].'；已產生的收支保留');return array('deleted'=>true);
    }

}
