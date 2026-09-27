<?php
declare(strict_types=1);

/**
 * The Saturday/Sunday of the first shopping weekend on or after $payday.
 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
 */
function shopping_weekend(DateTimeImmutable $payday): array
{
    $mondayZero = ((int) $payday->format('N')) - 1; // Mon=0 ... Sun=6
    $daysToSaturday = (5 - $mondayZero + 7) % 7;
    $saturday = $payday->modify("+{$daysToSaturday} days");
    $sunday = $saturday->modify('+1 day');

    return [$saturday, $sunday];
}

/**
 * The menu cycle for a given payday month: starts the Monday after that
 * month's shopping weekend, ends the Sunday covering next month's 25th.
 * @return array{start: string, end: string}
 */
function generate_cycle(int $year, int $month): array
{
    $payday = new DateTimeImmutable(sprintf('%04d-%02d-25', $year, $month));
    [, $thisSunday] = shopping_weekend($payday);
    $start = $thisSunday->modify('+1 day');

    $nextMonth = $month + 1;
    $nextYear = $year;
    if ($nextMonth > 12) {
        $nextMonth = 1;
        $nextYear++;
    }
    $nextPayday = new DateTimeImmutable(sprintf('%04d-%02d-25', $nextYear, $nextMonth));
    [, $nextSunday] = shopping_weekend($nextPayday);

    return [
        'start' => $start->format('Y-m-d'),
        'end' => $nextSunday->format('Y-m-d'),
    ];
}

function cycle_label(string $start, string $end): string
{
    $s = new DateTimeImmutable($start);
    $e = new DateTimeImmutable($end);

    return $s->format('M j') . ' – ' . $e->format($s->format('M') === $e->format('M') ? 'j' : 'M j');
}

function get_cycle(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM menu_cycles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $cycle = $stmt->fetch(PDO::FETCH_ASSOC);

    return $cycle === false ? null : $cycle;
}

/**
 * The cycle covering $date, or failing that the soonest upcoming one, or
 * failing that the most recent past one. Null only if no cycles exist yet.
 */
function find_cycle_for_date(PDO $pdo, string $date): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM menu_cycles WHERE start_date <= :d AND end_date >= :d LIMIT 1');
    $stmt->execute([':d' => $date]);
    $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cycle !== false) {
        return $cycle;
    }

    $all = $pdo->query('SELECT * FROM menu_cycles ORDER BY start_date')->fetchAll(PDO::FETCH_ASSOC);
    if (empty($all)) {
        return null;
    }
    foreach ($all as $c) {
        if ($c['start_date'] >= $date) {
            return $c;
        }
    }

    return end($all);
}

/** @return int|null the id of the cycle immediately before/after $startDate */
function adjacent_cycle_id(PDO $pdo, string $startDate, int $direction): ?int
{
    $op = $direction < 0 ? '<' : '>';
    $order = $direction < 0 ? 'DESC' : 'ASC';
    $stmt = $pdo->prepare("SELECT id FROM menu_cycles WHERE start_date $op :d ORDER BY start_date $order LIMIT 1");
    $stmt->execute([':d' => $startDate]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

/**
 * The payday date (year/month) whose shopping weekend produced $endDate,
 * i.e. the "next month" input that generate_cycle() used to compute it.
 * @return array{year: int, month: int}
 */
function payday_month_for_end(string $endDate): array
{
    $d = new DateTimeImmutable($endDate);
    for ($i = 1; $i <= 7; $i++) {
        $cand = $d->modify("-{$i} day");
        if ((int) $cand->format('d') === 25) {
            return ['year' => (int) $cand->format('Y'), 'month' => (int) $cand->format('n')];
        }
    }

    throw new RuntimeException("No 25th found near $endDate — cycle end dates should always be within 7 days of a payday.");
}

/** @return list<list<string>> each element is a Mon-Sun list of Y-m-d dates */
function cycle_weeks(array $cycle): array
{
    $weeks = [];
    $cursor = new DateTimeImmutable($cycle['start_date']);
    $end = new DateTimeImmutable($cycle['end_date']);

    while ($cursor <= $end) {
        $week = [];
        for ($i = 0; $i < 7 && $cursor <= $end; $i++) {
            $week[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
        $weeks[] = $week;
    }

    return $weeks;
}

/**
 * How many of an ingredient's units you actually have to buy for $qty.
 * Packaged items (tins, jars, "500g" packs) round up to whole units — you
 * can't buy a quarter of a mayonnaise. Loose items priced "per kg" are weighed
 * at the till, so they keep their fractional quantity.
 */
function purchase_qty(float $qty, string $unit): float
{
    if (stripos(ltrim($unit), 'per ') === 0) {
        return $qty;
    }

    // Round first so float noise like 2.0000000001 doesn't become 3.
    return ceil(round($qty, 4));
}

/**
 * The cycle's shopping list: one row per ingredient with the total quantity
 * the menu uses, the quantity to buy, and what that purchase costs.
 * @return list<array{id: int, name: string, category: string, unit: string, estimated_price: float, total_qty: float, buy_qty: float, line_total: float}>
 */
function cycle_shopping_items(PDO $pdo, int $cycleId): array
{
    $stmt = $pdo->prepare(
        'SELECT i.id, i.name, i.category, i.unit, i.estimated_price, SUM(mi.qty) AS total_qty
         FROM menu_entries me
         JOIN meal_ingredients mi ON mi.meal_id = me.meal_id
         JOIN ingredients i ON i.id = mi.ingredient_id
         WHERE me.cycle_id = :cid
         GROUP BY i.id
         ORDER BY i.name'
    );
    $stmt->execute([':cid' => $cycleId]);

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['total_qty'] = (float) $row['total_qty'];
        $row['estimated_price'] = (float) $row['estimated_price'];
        $row['buy_qty'] = purchase_qty($row['total_qty'], $row['unit']);
        $row['line_total'] = $row['buy_qty'] * $row['estimated_price'];
        $items[] = $row;
    }

    return $items;
}

/** Estimated spend for a cycle, with packaged items rounded up to whole units. */
function cycle_cost(PDO $pdo, int $cycleId): float
{
    return array_sum(array_column(cycle_shopping_items($pdo, $cycleId), 'line_total'));
}
