<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Aggregate-only analytics; all SQL is household-scoped and excludes transfers.
 * No raw transaction data is leaked across household memberships.
 */
final class WFF_Insights {
    private static function summarize($rows, $keys) {
        $out = array();
        foreach ($rows ?: array() as $row) {
            $key = array();
            foreach ($keys as $name) { $key[$name] = $row[$name]; }
            $key['total'] = (float) $row['total'];
            $out[] = $key;
        }
        return $out;
    }

    public static function dashboard($hid, $month, $credit_cards = array()) {
        global $wpdb;
        $t = WFF_Storage::table('transactions');
        $c = WFF_Storage::table('categories');
        $a = WFF_Storage::table('accounts');

        $date = DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (!$date) { $date = new DateTimeImmutable('first day of this month'); }
        $start = $date->modify('-5 months')->format('Y-m-01');
        $end = $date->modify('+1 month')->format('Y-m-01');

        $months = array();
        for ($offset=5; $offset>=0; $offset--) {
            $key = $date->modify('-' . $offset . ' months')->format('Y-m');
            $months[$key] = array(
                'month' => $key, 'family_income' => 0, 'family_expense' => 0,
                'business_income' => 0, 'business_expense' => 0
            );
        }
        $trend = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(occurred_on,'%%Y-%%m') AS ym, scope,kind,
             SUM(amount) AS total FROM $t
             WHERE payment_status='paid' AND household_id=%d AND occurred_on >= %s AND occurred_on < %s
             AND kind IN ('income','expense')
             GROUP BY ym,scope,kind ORDER BY ym", $hid, $start, $end
        ), ARRAY_A);
        foreach ($trend ?: array() as $row) {
            $key = $row['scope'] . '_' . $row['kind'];
            if (isset($months[$row['ym']][$key])) {
                $months[$row['ym']][$key] = (float) $row['total'];
            }
        }

        // Last 30 days ending on selected month's final day, not today's clock.
        $last = $date->modify('last day of this month');
        $today = new DateTimeImmutable('today', wp_timezone());
        if ($last > $today) { $last = $today; }
        $first = $last->modify('-29 days');
        $daily = array();
        for ($i=0; $i<30; $i++) {
            $key = $first->modify("+$i days")->format('Y-m-d');
            $daily[$key] = array('date'=>$key,'family'=>0,'business'=>0);
        }
        $daily_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT occurred_on,scope,SUM(amount) AS total FROM $t
             WHERE payment_status='paid' AND household_id=%d AND occurred_on BETWEEN %s AND %s
             AND kind='expense' GROUP BY occurred_on,scope ORDER BY occurred_on",
            $hid, $first->format('Y-m-d'), $last->format('Y-m-d')
        ),ARRAY_A);
        foreach ($daily_rows ?: array() as $row) {
            if (isset($daily[$row['occurred_on']][$row['scope']])) {
                $daily[$row['occurred_on']][$row['scope']] = (float)$row['total'];
            }
        }

        $categories = self::summarize($wpdb->get_results($wpdb->prepare(
            "SELECT t.scope,c.name AS label,c.color AS color,SUM(t.amount) AS total FROM $t t
             LEFT JOIN $c c ON c.id=t.category_id AND c.household_id=t.household_id
             WHERE t.payment_status='paid' AND t.household_id=%d AND t.kind='expense' AND t.occurred_on LIKE %s
             GROUP BY t.scope,t.category_id,c.name,c.color ORDER BY total DESC",
            $hid, $month.'%'
        ),ARRAY_A),array('scope','label','color'));

        // Radar dimensions are stable groups rather than arbitrary changing categories.
        $radar_labels = array('飲食','交通','生活用品','健康醫療','休閒娛樂','其他');
        $radar = array_fill_keys($radar_labels,0.0);
        foreach ($categories as $item) {
            if ($item['scope'] !== 'family') { continue; }
            $name = (string)$item['label'];
            if (preg_match('/餐|食|飲|午|早|晚|菜|咖啡|好市多|消夜|茶|宵/', $name)) { $group='飲食'; }
            elseif (preg_match('/交通|車|停車|加油|高鐵|公車|捷運|油資/', $name)) { $group='交通'; }
            elseif (preg_match('/生活|家居|日用|管理費|租|水|電|瓦斯/', $name)) { $group='生活用品'; }
            elseif (preg_match('/醫|藥|健|保健/', $name)) { $group='健康醫療'; }
            elseif (preg_match('/娛樂|旅|遊|電影|遊戲|購物/', $name)) { $group='休閒娛樂'; }
            else { $group='其他'; }
            $radar[$group] += $item['total'];
        }
        $radar_out = array();
        foreach ($radar as $label=>$value) { $radar_out[] = array('label'=>$label,'total'=>$value); }

        // Payment tool usage: each expense is attributed to ONE channel
        // (wallet when present, otherwise funding account / legacy method).
        // The funding account can be analyzed separately without double counting.
        $types = array('cash'=>0,'credit'=>0,'mobile'=>0,'bank'=>0,'other'=>0);
        $usage = $wpdb->get_results($wpdb->prepare(
            "SELECT t.payment_method,t.account_id,t.payment_channel_id,
             a.name AS funding_name,a.type AS funding_type,
             w.name AS wallet_name,w.type AS wallet_type,
             SUM(t.amount) AS total,COUNT(*) AS count
             FROM $t t
             LEFT JOIN $a a ON a.id=t.account_id AND a.household_id=t.household_id
             LEFT JOIN $a w ON w.id=t.payment_channel_id AND w.household_id=t.household_id
             WHERE t.payment_status='paid' AND t.household_id=%d AND t.kind='expense'
             AND t.occurred_on LIKE %s
             GROUP BY t.payment_method,t.account_id,t.payment_channel_id,
               a.name,a.type,w.name,w.type", $hid, $month.'%'
        ),ARRAY_A);
        $rank = array();
        $wallet_rank = array();
        $cards_rank = array();
        foreach ($usage ?: array() as $item) {
            $channel = 'other';
            if ($item['wallet_type'] === 'mobile') { $channel = 'mobile'; }
            elseif ($item['funding_type'] === 'credit') { $channel = 'credit'; }
            elseif ($item['funding_type'] === 'bank') { $channel = 'bank'; }
            elseif ($item['funding_type'] === 'cash') { $channel = 'cash'; }
            elseif ($item['payment_method'] === 'card') { $channel = 'credit'; }
            elseif (in_array($item['payment_method'],array('mobile','bank','cash'),true)) {
                $channel = $item['payment_method'];
            }
            $amount = (float)$item['total'];
            $types[$channel] += $amount;
            $label = $item['wallet_type']==='mobile' ? $item['wallet_name'] :
                ($item['funding_name'] ?: '未指定付款工具');
            $rank_key = $channel.'|'.$label;
            if (!isset($rank[$rank_key])) {
                $rank[$rank_key] = array('name'=>$label,'type'=>$channel,'total'=>0);
            }
            $rank[$rank_key]['total'] += $amount;
            if ($channel==='mobile') {
                $wallet_rank[$label] = ($wallet_rank[$label] ?? 0) + $amount;
            }
            // Linked credit card: attribute amount to the underlying card too,
            // only as a secondary usage breakdown, not another expense.
            if ($item['funding_type']==='credit') {
                $name = $item['funding_name'];
                $cards_rank[$name] = ($cards_rank[$name] ?? 0) + $amount;
            }
        }
        uasort($rank,function($x,$y){return $y['total']<=>$x['total'];});
        arsort($wallet_rank,SORT_NUMERIC); arsort($cards_rank,SORT_NUMERIC);
        $out_rank=array_slice(array_values($rank),0,10);
        $convert_rank=function($src){
            $out=array();
            foreach(array_slice($src,0,10,true) as $name=>$amount){$out[]=array('name'=>$name,'total'=>$amount);}
            return $out;
        };
        $method_out = array();
        foreach ($types as $type=>$amount) { $method_out[] = array('type'=>$type,'total'=>$amount); }

        // Balances are estimates from all recorded transactions; opening
        // balances, bank interest and card issuer statement cycles are not known.
        $cards = array();
        foreach ($credit_cards as $card) {
            $cards[] = array(
                'id'=>(int)$card['id'], 'name'=>$card['name'],
                'outstanding'=>(float)$card['outstanding'],
            );
        }
        $biggest = $wpdb->get_row($wpdb->prepare(
            "SELECT occurred_on,amount,note FROM $t WHERE payment_status='paid' AND household_id=%d
             AND kind='expense' AND occurred_on LIKE %s
             ORDER BY amount DESC,id DESC LIMIT 1", $hid, $month.'%'
        ), ARRAY_A);
        return array(
            'biggest_expense'=>$biggest ? array(
                'date'=>$biggest['occurred_on'],
                'amount'=>(float)$biggest['amount'],
                'note'=>$biggest['note']
            ) : null,
            'monthly'=>array_values($months),
            'last30'=>array_values($daily),
            'radar'=>$radar_out,
            'categories'=>$categories,
            'payment_methods'=>$method_out,
            'payment_rank'=>$out_rank,
            'mobile_rank'=>$convert_rank($wallet_rank),
            'credit_rank'=>$convert_rank($cards_rank),
            'credit_estimates'=>$cards,
        );
    }
}
