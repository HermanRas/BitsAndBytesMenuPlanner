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

$weeks = cycle_weeks($cycle);

if (isset($_GET['week'])) {
    $weekIndex = max(0, min(count($weeks) - 1, (int) $_GET['week']));
} else {
    $weekIndex = 0;
    foreach ($weeks as $i => $week) {
        if (in_array($today, $week, true)) {
            $weekIndex = $i;
            break;
        }
    }
}
$thisWeek = $weeks[$weekIndex];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'toggle_cooked') {
    $entryId = (int) ($_POST['entry_id'] ?? 0);
    $pdo->prepare('UPDATE menu_entries SET cooked = 1 - cooked WHERE id = :id')->execute([':id' => $entryId]);
    header('Location: week.php?cycle=' . $cycle['id'] . '&week=' . $weekIndex);
    exit;
}

$prevCycleId = adjacent_cycle_id($pdo, $cycle['start_date'], -1);
$nextCycleId = adjacent_cycle_id($pdo, $cycle['start_date'], 1);

if ($weekIndex > 0) {
    $prevLink = 'week.php?cycle=' . $cycle['id'] . '&week=' . ($weekIndex - 1);
} elseif ($prevCycleId !== null) {
    $prevCycle = get_cycle($pdo, $prevCycleId);
    $prevWeeks = cycle_weeks($prevCycle);
    $prevLink = 'week.php?cycle=' . $prevCycleId . '&week=' . (count($prevWeeks) - 1);
} else {
    $prevLink = null;
}

if ($weekIndex < count($weeks) - 1) {
    $nextLink = 'week.php?cycle=' . $cycle['id'] . '&week=' . ($weekIndex + 1);
} elseif ($nextCycleId !== null) {
    $nextLink = 'week.php?cycle=' . $nextCycleId . '&week=0';
} else {
    $nextLink = null;
}

$placeholders = implode(',', array_fill(0, count($thisWeek), '?'));
$entriesStmt = $pdo->prepare(
    "SELECT me.id AS entry_id, me.date, me.slot, me.cooked, m.title
     FROM menu_entries me
     JOIN meals m ON m.id = me.meal_id
     WHERE me.date IN ($placeholders)"
);
$entriesStmt->execute($thisWeek);
$byDate = [];
foreach ($entriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $byDate[$row['date']][$row['slot']] = $row;
}

$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$weekRange = cycle_label($thisWeek[0], end($thisWeek));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Week — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="stylesheet" href="css/app.css">
  <style>
    .slot-line a { color: inherit; text-decoration: none; flex: 1; min-width: 0; }
    .slot-line .cooked-mini { margin-left: auto; background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0.2rem; }
    .slot-line .cooked-mini svg { width: 20px; height: 20px; }
    .slot-line .cooked-mini.done { color: var(--accent-strong); }
  </style>
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <img class="logo" src="img/Icon_128x128.png" alt="">
      <div>
        <h1>Menu</h1>
        <p class="subtitle">Week <?= $weekIndex + 1 ?> of <?= count($weeks) ?></p>
      </div>
    </header>

    <main class="app-content">
      <div class="view-toggle">
        <a href="calendar.php?cycle=<?= (int) $cycle['id'] ?>">Full cycle</a>
        <a href="week.php?cycle=<?= (int) $cycle['id'] ?>&week=<?= $weekIndex ?>" class="active">Week</a>
      </div>

      <div class="cycle-header">
        <?php if ($prevLink !== null): ?>
          <a class="icon-btn" href="<?= htmlspecialchars($prevLink) ?>" aria-label="Previous week">
        <?php else: ?>
          <span class="icon-btn" style="opacity:0.3;" aria-hidden="true">
        <?php endif; ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        <?= $prevLink !== null ? '</a>' : '</span>' ?>
        <span class="range"><?= htmlspecialchars($weekRange) ?></span>
        <?php if ($nextLink !== null): ?>
          <a class="icon-btn" href="<?= htmlspecialchars($nextLink) ?>" aria-label="Next week">
        <?php else: ?>
          <span class="icon-btn" style="opacity:0.3;" aria-hidden="true">
        <?php endif; ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
        <?= $nextLink !== null ? '</a>' : '</span>' ?>
      </div>

      <?php foreach ($thisWeek as $i => $date): ?>
        <?php $d = new DateTimeImmutable($date); ?>
        <div class="day-row <?= $date === $today ? 'is-today' : '' ?>">
          <div class="day-heading">
            <span><?= $dayNames[$i] ?><?= $date === $today ? ' · Today' : '' ?></span>
            <span class="date-num"><?= $d->format('j M') ?></span>
          </div>
          <?php if (empty($byDate[$date])): ?>
            <p class="hint" style="margin:0.3rem 0;">Nothing planned yet.</p>
          <?php else: ?>
            <?php foreach (['breakfast', 'lunch', 'dinner'] as $slot): ?>
              <?php if (isset($byDate[$date][$slot])): ?>
                <?php $entry = $byDate[$date][$slot]; $cooked = (bool) $entry['cooked']; ?>
                <div class="slot-line">
                  <span class="slot-tag"><?= ucfirst($slot) ?></span>
                  <a href="meal-detail.php?entry=<?= (int) $entry['entry_id'] ?>"><?= htmlspecialchars($entry['title']) ?></a>
                  <?php if (!$guest): ?>
                    <form method="post" style="margin-left:auto;">
                      <input type="hidden" name="action" value="toggle_cooked">
                      <input type="hidden" name="entry_id" value="<?= (int) $entry['entry_id'] ?>">
                      <button type="submit" class="cooked-mini <?= $cooked ? 'done' : '' ?>" aria-label="<?= $cooked ? 'Cooked' : 'Mark cooked' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
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
      <a href="shopping-list.html">
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
