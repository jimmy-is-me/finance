<?php
declare(strict_types=1);
define('ABSPATH', '/');
require_once dirname(__DIR__) . '/includes/class-wff-card-cycles.php';

function check_cycle($statementDay, $dueDay, $month, $starts, $closes, $due) {
    $c = WFF_Card_Cycles::cycle($statementDay, $dueDay, $month);
    if ($c['starts_on'] !== $starts || $c['statement_on'] !== $closes || $c['due_on'] !== $due) {
        throw new RuntimeException('Unexpected card cycle for ' . $month . ': ' . json_encode($c));
    }
}
// Standard cross-month period and next-month due date.
check_cycle(25, 15, '2026-10', '2026-09-26', '2026-10-25', '2026-11-15');
// Both statement and due can be in the same calendar month.
check_cycle(5, 25, '2026-10', '2026-09-06', '2026-10-05', '2026-10-25');
// Same day means next month's due date.
check_cycle(10, 10, '2026-10', '2026-09-11', '2026-10-10', '2026-11-10');
// End-of-month clamping and leap year.
check_cycle(31, 30, '2026-02', '2026-02-01', '2026-02-28', '2026-03-30');
check_cycle(31, 31, '2024-02', '2024-02-01', '2024-02-29', '2024-03-31');
check_cycle(31, 1, '2026-12', '2026-12-01', '2026-12-31', '2027-01-01');
// Latest closed statement, not the upcoming statement.
$p = WFF_Card_Cycles::periods(25,15,'2026-10-10',2);
if ($p[0]['statement_on'] !== '2026-09-25' || $p[1]['statement_on'] !== '2026-08-25') {
    throw new RuntimeException('Latest statement period mismatch');
}
$p = WFF_Card_Cycles::periods(25,15,'2026-10-25',1);
if ($p[0]['statement_on'] !== '2026-10-25') {
    throw new RuntimeException('Closing day should be included');
}
echo "Card cycles: OK\n";
