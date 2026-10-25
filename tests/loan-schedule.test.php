<?php
/**
 * Standalone regression test of deterministic loan schedule logic.
 * Requires only the PHP runtime, not WordPress database.
 */
define('ABSPATH', __DIR__ . '/');
date_default_timezone_set('Asia/Taipei');
function wp_timezone() { return new DateTimeZone('Asia/Taipei'); }
function wp_date($format) { return date($format); }
require_once __DIR__ . '/../includes/class-wff-loans.php';

function invoke_private($method, ...$params) {
    $m = new ReflectionMethod('WFF_Loans', $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null,$params);
}
function expect($actual,$expected,$message) {
    if ($actual !== $expected) {
        fwrite(STDERR, $message . ' expected ' . var_export($expected,true)
            . ' got ' . var_export($actual,true) . PHP_EOL);
        exit(1);
    }
}
$oct = array(
    'first_due_on'=>'2026-10-05','due_day'=>5,
    'opening_principal'=>3000,'annual_rate'=>0,'installments'=>3,
    'monthly_payment'=>0,'status'=>'active'
);
expect(invoke_private('due',$oct,0),'2026-10-05','First due');
expect(invoke_private('due',$oct,1),'2026-11-05','Next month');
expect(invoke_private('due',$oct,2),'2026-12-05','Third month');
$end = $oct;
$end['first_due_on']='2027-01-31';
$end['due_day']=31;
expect(invoke_private('due',$end,1),'2027-02-28','Short February');
expect(invoke_private('due',$end,2),'2027-03-31','Restored original billing day');
$end['first_due_on']='2028-01-31';
expect(invoke_private('due',$end,1),'2028-02-29','Leap February');
expect(invoke_private('regular_amount',$oct),1000.0,'Equal principal no-interest');
$forecast=invoke_private('projection',$oct,array());
expect($forecast['next_due']['number'],1,'First unpaid installment');
expect($forecast['schedule'][1]['due_on'],'2026-11-05','Forecast next due');
expect($forecast['remaining_principal_estimate'],3000.0,'Not paid means no decrease');
$with_payment=invoke_private('projection',$oct,array(
    array('installment_no'=>1,'paid_amount'=>1000,'extra_principal'=>200,
          'paid_on'=>'2026-10-05','note'=>'正常繳款與多還本金')
));
expect($with_payment['schedule'][0]['status'],'paid','Recorded payment');
expect($with_payment['next_due']['number'],2,'Next due follows a recorded payment');
expect($with_payment['remaining_principal_estimate'],1800.0,'Principal reduced with extra payment');
$interest_loan=$oct;$interest_loan['annual_rate']=12;
$interest_loan['monthly_payment']=0;
$amount=invoke_private('regular_amount',$interest_loan);
if($amount<=1000 || $amount>=1050) {
    fwrite(STDERR,'Interest amortization amount outside expected range'.PHP_EOL);
    exit(1);
}

// Simple recurring tracker does NOT require an outstanding principal or APR.
$tracker=array(
    'tracking_mode'=>'simple','first_due_on'=>'2026-10-05','due_day'=>5,
    'monthly_payment'=>0,'opening_principal'=>0,'annual_rate'=>0,
    'installments'=>0,'status'=>'active'
);
$seen=invoke_private('projection',$tracker,array(),0);
expect($seen['simple'],true,'Simple tracker selected');
expect($seen['regular_payment'],0.0,'Optional monthly amount may be empty');
if(count($seen['schedule'])>18) {
    fwrite(STDERR,"Simple tracker must use a bounded viewing window\n");exit(1);
}
$logged=invoke_private('projection',$tracker,array(
    array('installment_no'=>1,'paid_amount'=>0,'extra_principal'=>0,
          'paid_on'=>'2026-10-05','note'=>'本期已繳')
),0);
expect($logged['paid_count'],1,'Zero amount still counts as explicitly paid');
expect($logged['recorded_total'],0.0,'Unknown amount does not create spending');
$scrolled=invoke_private('projection',$tracker,array(),12);
expect($scrolled['simple'],true,'Can page monthly schedule forward');
if(count($scrolled['schedule'])>18) {
    fwrite(STDERR,"Paged tracker unexpectedly generated too many entries\n");exit(1);
}
$manual=$tracker;$manual['first_due_on']='2027-01-31';$manual['due_day']=31;
expect(invoke_private('due',$manual,1),'2027-02-28','Tracker clamps month end');
expect(invoke_private('due',$manual,2),'2027-03-31','Tracker restores 31st');

// Amount increases must not retroactively change already-paid installments.
$tracker['monthly_payment']=15000;
$tracker['amount_changes']=array(
    array('installment_no'=>1,'monthly_payment'=>12129),
    array('installment_no'=>3,'monthly_payment'=>15000)
);
expect(invoke_private('amount_for_installment',$tracker,1),12129.0,'Historical paid amount plan remains old');
expect(invoke_private('amount_for_installment',$tracker,2),12129.0,'Prior unpaid installment stays old');
expect(invoke_private('amount_for_installment',$tracker,3),15000.0,'Increase applies at chosen installment');
expect(invoke_private('amount_for_installment',$tracker,4),15000.0,'New amount continues');
$paid_projection=invoke_private('projection',$tracker,array(
    array('installment_no'=>1,'paid_amount'=>12129,'extra_principal'=>0,'paid_on'=>'2026-10-05','note'=>'已繳')
),0);
foreach($paid_projection['schedule'] as $p) {
    if($p['number']===1) {
        expect($p['scheduled_amount'],12129.0,'Paid installment displays its original planned amount');
        expect($p['paid_amount'],12129.0,'Paid installment keeps its actual payment');
    }
}
echo "Loan recurring due dates / month-end / leap years / unpaid / principal / interest / simple tracking: OK\n";
