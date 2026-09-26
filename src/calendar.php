<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cycle.php';

$user = current_user();
$guest = is_guest();
if ($user === null && !$guest) {
    header('Location: login.php');
    exit;
}

$pdo = get_db();
$today = (new DateTimeImmutable('now'))->format('Y-m-d');

$cycle = isset($_GET['cycle'])
    ? get_cycle($pdo, (int) $_GET['cycle'])
    : find_cycle_for_date($pdo, $today);

if ($cycle === null) {
    header('Location: today.php');
    exit;
}

$prevId = adjacent_cycle_id($pdo, $cycle['start_date'], -1);
$nextId = adjacent_cycle_id($pdo, $cycle['start_date'], 1);

$entriesStmt = $pdo->prepare(
    "SELECT me.id AS entry_id, me.date, me.slot, m.title
     FROM menu_entries me
     JOIN meals m ON m.id = me.meal_id
     WHERE me.cycle_id = :cid"
);
$entriesStmt->execute([':cid' => $cycle['id']]);
$byDate = [];
foreach ($entriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $byDate[$row['date']][$row['slot']] = $row;
}

$weeks = cycle_weeks($cycle);
$range = cycle_label($cycle['start_date'], $cycle['end_date']);

$budget = $pdo->query('SELECT monthly_budget FROM budget_settings WHERE id = 1')->fetchColumn();
$costStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(mi.qty * i.estimated_price), 0)
     FROM menu_entries me
     JOIN meal_ingredients mi ON mi.meal_id = me.meal_id
     JOIN ingredients i ON i.id = mi.ingredient_id
     WHERE me.cycle_id = :cid'
);
$costStmt->execute([':cid' => $cycle['id']]);
$cycleCost = (float) $costStmt->fetchColumn();
$budget = $budget !== false ? (float) $budget : null;
$overBudget = $budget !== null && $cycleCost > $budget;
$budgetPct = $budget !== null && $budget > 0 ? min(100, ($cycleCost / $budget) * 100) : 0;
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Menu — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="manifest" href="manifest.json">
  <link rel="apple-touch-icon" href="img/Icon_192x192.png">
  <meta name="theme-color" content="#1A1A1A">
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <img class="logo" src="img/Icon_128x128.png" alt="">
      <div>
        <h1>Menu</h1>
        <p class="subtitle"><?= htmlspecialchars($cycle['label']) ?> cycle</p>
      </div>
    </header>

    <main class="app-content">
      <?php if ($budget !== null): ?>
        <div class="budget-card">
          <div class="row"><span>Cycle estimated cost</span><span>R<?= number_format($cycleCost, 2) ?></span></div>
          <div class="progress-track">
            <div class="progress-fill <?= $overBudget ? 'over-budget' : '' ?>" style="width:<?= $budgetPct ?>%;"></div>
          </div>
          <?php if ($overBudget): ?>
            <p class="budget-note">⚠ R<?= number_format($cycleCost - $budget, 2) ?> over your R<?= number_format($budget, 2) ?> monthly budget.</p>
          <?php else: ?>
            <p class="budget-note">Budget: R<?= number_format($budget, 2) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="view-toggle">
        <a href="calendar.php?cycle=<?= (int) $cycle['id'] ?>" class="active">Full cycle</a>
        <a href="week.php?cycle=<?= (int) $cycle['id'] ?>">Week</a>
      </div>

      <div class="cycle-header">
        <?php if ($prevId !== null): ?>
          <a class="icon-btn" href="calendar.php?cycle=<?= $prevId ?>" aria-label="Previous cycle">
        <?php else: ?>
          <span class="icon-btn" style="opacity:0.3;" aria-hidden="true">
        <?php endif; ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        <?= $prevId !== null ? '</a>' : '</span>' ?>
        <span class="range"><?= htmlspecialchars($range) ?></span>
        <?php if ($nextId !== null): ?>
          <a class="icon-btn" href="calendar.php?cycle=<?= $nextId ?>" aria-label="Next cycle">
        <?php else: ?>
          <span class="icon-btn" style="opacity:0.3;" aria-hidden="true">
        <?php endif; ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
        <?= $nextId !== null ? '</a>' : '</span>' ?>
      </div>

      <div class="cal-legend">
        <span><i class="dot-breakfast"></i>Breakfast</span>
        <span><i class="dot-lunch"></i>Lunch</span>
        <span><i class="dot-dinner"></i>Dinner</span>
      </div>

      <div class="cal-weekday-row">
        <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
      </div>

      <div class="cal-grid">
        <?php foreach ($weeks as $i => $week): ?>
          <div class="cal-week-label">Week <?= $i + 1 ?></div>
          <?php foreach ($week as $date): ?>
            <?php $d = new DateTimeImmutable($date); ?>
            <div class="cal-cell <?= $date === $today ? 'today' : '' ?>">
              <div class="cal-date"><?= $d->format('j') ?></div>
              <?php foreach (['breakfast', 'lunch', 'dinner'] as $slot): ?>
                <?php if (isset($byDate[$date][$slot])): ?>
                  <a class="cal-meal <?= $slot ?>" href="meal-detail.php?entry=<?= (int) $byDate[$date][$slot]['entry_id'] ?>">
                    <?= htmlspecialchars($byDate[$date][$slot]['title']) ?>
                  </a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </main>

    <nav class="bottom-nav">
      <a href="today.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/></svg>
        <span>Today</span>
      </a>
      <a href="calendar.php" class="active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
        <span>Menu</span>
      </a>
      <a href="shopping-list.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.4a2 2 0 0 0 2-1.6L21 7H6"/></svg>
        <span>Shopping</span>
      </a>
      <a href="settings.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.9 7.9 0 0 0 0-2l2-1.6-2-3.4-2.4 1a8 8 0 0 0-1.7-1L14.9 3h-4l-.4 2.5a8 8 0 0 0-1.7 1l-2.4-1-2 3.4L6.4 11a7.9 7.9 0 0 0 0 2l-2 1.6 2 3.4 2.4-1a8 8 0 0 0 1.7 1l.4 2.5h4l.4-2.5a8 8 0 0 0 1.7-1l2.4 1 2-3.4z"/></svg>
        <span>Settings</span>
      </a>
    </nav>
  </div>
  <script src="js/mockup.js"></script>
</body>
</html>
