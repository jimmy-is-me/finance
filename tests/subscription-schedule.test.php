<?php
// Calendar behavior is independent of WordPress storage.
define('ABSPATH', __DIR__);
function wp_timezone(){return new DateTimeZone('Asia/Taipei');}
require __DIR__.'/../includes/class-wff-subscriptions.php';
function expect_date($config,$index,$expected){
    $actual=WFF_Subscriptions::due($config,$index);
    if($actual!==$expected)throw new Exception("$actual != $expected");
}
$c=array('start_on'=>'2026-01-31','unit'=>'month','interval'=>1);
expect_date($c,1,'2026-02-28');expect_date($c,2,'2026-03-31');
$c['start_on']='2028-02-29';$c['unit']='year';
expect_date($c,1,'2029-02-28');expect_date($c,4,'2032-02-29');
$c=array('start_on'=>'2026-12-28','unit'=>'week','interval'=>2);
expect_date($c,1,'2027-01-11');
$c=array('start_on'=>'2026-01-31','unit'=>'month','interval'=>3);
expect_date($c,1,'2026-04-30');expect_date($c,2,'2026-07-31');
echo "Subscription calendar tests passed\n";
