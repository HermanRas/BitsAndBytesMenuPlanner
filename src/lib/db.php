<?php
declare(strict_types=1);

function db_path(): string
{
    return __DIR__ . '/../data/family.sqlite';
}

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $isNew = !file_exists(db_path());

    $dataDir = dirname(db_path());
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($isNew) {
        apply_schema($pdo);
        bootstrap_demo_data($pdo);
    }

    return $pdo;
}

function apply_schema(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($sql);
}

/**
 * Runs once, the very first time the database is created (fresh deploy or
 * dev init-db.php), so the app is never left with an empty users table and
 * no way to log in. `seed.php` wipes and replaces all of this with fake
 * family data for local development.
 */
function bootstrap_demo_data(PDO $pdo): void
{
    require_once __DIR__ . '/cycle.php';

    $pdo->prepare(
        'INSERT INTO users (name, email, pin_hash, role, theme, accent_hue) VALUES (:name, :email, :pin_hash, :role, :theme, :hue)'
    )->execute([
        ':name' => 'Demo Parent',
        ':email' => 'demo@example.com',
        ':pin_hash' => password_hash('1234', PASSWORD_DEFAULT),
        ':role' => 'parent',
        ':theme' => 'light',
        ':hue' => 90,
    ]);

    $pdo->exec('INSERT INTO budget_settings (id, monthly_budget) VALUES (1, 2000.00)');

    // Whichever generated cycle actually covers today, checking the
    // surrounding months since the cycle boundary shifts with the calendar.
    $now = new DateTimeImmutable('now');
    $today = $now->format('Y-m-d');
    $cycle = null;
    foreach ([-2, -1, 0] as $offset) {
        $candidate = $now->modify("$offset month");
        $generated = generate_cycle((int) $candidate->format('Y'), (int) $candidate->format('n'));
        if ($generated['start'] <= $today && $today <= $generated['end']) {
            $cycle = $generated;
            break;
        }
    }
    $cycle ??= generate_cycle((int) $now->format('Y'), (int) $now->format('n'));

    $pdo->prepare('INSERT INTO menu_cycles (start_date, end_date, label) VALUES (:s, :e, :l)')
        ->execute([':s' => $cycle['start'], ':e' => $cycle['end'], ':l' => cycle_label($cycle['start'], $cycle['end'])]);
}
