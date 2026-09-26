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
