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
echo "Loan recurring due dates / month-end / leap years / unpaid / principal / interest: OK\n";
