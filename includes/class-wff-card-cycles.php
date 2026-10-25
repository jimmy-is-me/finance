<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Pure calendar helpers for fixed monthly credit card cycles.
 *
 * The statement period may cross a month boundary, and the payment due
 * date is the first occurrence of the configured day AFTER statement close.
 * A configured 29/30/31 is clamped to the last day in short months.
 */
final class WFF_Card_Cycles {
    private static function first($month) {
        if (!preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', (string)$month)) {
            throw new InvalidArgumentException('Invalid cycle month');
        }
        return new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
    }

    public static function month_offset($month, $offset) {
        $first = self::first($month);
        return $first->modify(($offset >= 0 ? '+' : '') . (int)$offset . ' months')->format('Y-m');
    }

    public static function day_in_month($month, $day) {
        $first = self::first($month);
        $day = (int)$day;
        if ($day < 1 || $day > 31) {
            throw new InvalidArgumentException('Invalid monthly day');
        }
        return $first->setDate(
            (int)$first->format('Y'), (int)$first->format('m'),
            min($day, (int)$first->format('t'))
        )->format('Y-m-d');
    }

    public static function cycle($statement_day, $due_day, $month) {
        $statement = self::day_in_month($month, $statement_day);
        $previous = self::day_in_month(self::month_offset($month, -1), $statement_day);
        $due_month = $month;
        // Same-day or earlier due dates refer to the following month.
        if (self::day_in_month($due_month, $due_day) <= $statement) {
            $due_month = self::month_offset($month, 1);
        }
        $due = self::day_in_month($due_month, $due_day);
        return array(
            'statement_on' => $statement,
            'starts_on' => (new DateTimeImmutable($previous, new DateTimeZone('UTC')))
                ->modify('+1 day')->format('Y-m-d'),
            'due_on' => $due,
        );
    }

    public static function periods($statement_day, $due_day, $today, $count = 12) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$today, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $today) {
            throw new InvalidArgumentException('Invalid current date');
        }
        $month = $date->format('Y-m');
        if (self::day_in_month($month, $statement_day) > $today) {
            $month = self::month_offset($month, -1);
        }
        $out = array();
        for ($i = 0; $i < max(1, min(24, (int)$count)); $i++) {
            $out[] = self::cycle($statement_day, $due_day, self::month_offset($month, -$i));
        }
        return $out;
    }
}
