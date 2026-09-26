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
$isParent = $user !== null && $user['role'] === 'parent';

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
$backHere = 'week.php?cycle=' . $cycle['id'] . '&week=' . $weekIndex;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null) {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_cooked') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $pdo->prepare('UPDATE menu_entries SET cooked = 1 - cooked WHERE id = :id')->execute([':id' => $entryId]);
    } elseif ($action === 'assign_meal' && $isParent) {
        $date = $_POST['date'] ?? '';
        $slot = $_POST['slot'] ?? '';
        $mealId = (int) ($_POST['meal_id'] ?? 0);
        if (in_array($date, $thisWeek, true) && in_array($slot, ['breakfast', 'lunch', 'dinner'], true) && $mealId > 0) {
            $exists = $pdo->prepare('SELECT 1 FROM menu_entries WHERE date = :d AND slot = :s');
            $exists->execute([':d' => $date, ':s' => $slot]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO menu_entries (cycle_id, date, slot, meal_id, cooked) VALUES (:cid, :d, :s, :mid, 0)')
                    ->execute([':cid' => $cycle['id'], ':d' => $date, ':s' => $slot, ':mid' => $mealId]);
            }
        }
    } elseif ($action === 'remove_entry' && $isParent) {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $pdo->prepare('DELETE FROM menu_entries WHERE id = :id')->execute([':id' => $entryId]);
    } elseif ($action === 'move_entry' && $isParent) {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $targetDate = $_POST['target_date'] ?? '';
        $targetSlot = $_POST['target_slot'] ?? '';

        $srcStmt = $pdo->prepare('SELECT * FROM menu_entries WHERE id = :id');
        $srcStmt->execute([':id' => $entryId]);
        $src = $srcStmt->fetch(PDO::FETCH_ASSOC);

        if ($src !== false && !$src['cooked'] && in_array($targetDate, $thisWeek, true) && in_array($targetSlot, ['breakfast', 'lunch', 'dinner'], true)) {
            $targetStmt = $pdo->prepare('SELECT * FROM menu_entries WHERE date = :d AND slot = :s');
            $targetStmt->execute([':d' => $targetDate, ':s' => $targetSlot]);
            $targetEntry = $targetStmt->fetch(PDO::FETCH_ASSOC);

            if ($targetEntry === false) {
                $pdo->prepare('UPDATE menu_entries SET date = :d, slot = :s WHERE id = :id')
                    ->execute([':d' => $targetDate, ':s' => $targetSlot, ':id' => $entryId]);
            } elseif (!$targetEntry['cooked'] && (int) $targetEntry['id'] !== $entryId) {
                $pdo->prepare('UPDATE menu_entries SET date = :d, slot = :s WHERE id = :id')
                    ->execute([':d' => $targetDate, ':s' => $targetSlot, ':id' => $entryId]);
                $pdo->prepare('UPDATE menu_entries SET date = :d, slot = :s WHERE id = :id')
                    ->execute([':d' => $src['date'], ':s' => $src['slot'], ':id' => $targetEntry['id']]);
            }
        }
    }

    header('Location: ' . $backHere);
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

$allMeals = $isParent ? $pdo->query('SELECT id, title FROM meals ORDER BY title')->fetchAll(PDO::FETCH_ASSOC) : [];

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

$dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$weekRange = cycle_label($thisWeek[0], end($thisWeek));
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Week — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="stylesheet" href="css/app.css">
  <style>
    .slot-line a { color: inherit; text-decoration: none; flex: 1; min-width: 0; }
    .slot-line .cooked-mini { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 0.2rem; }
    .slot-line .cooked-mini svg { width: 20px; height: 20px; }
    .slot-line .cooked-mini.done { color: var(--accent-strong); }
    .slot-line form { display: contents; }
    .slot-line-empty {
      display: flex; align-items: center; gap: 0.5rem; padding: 0.35rem 0;
      color: var(--text-muted); font-size: 0.85rem; background: none; border: none;
      width: 100%; text-align: left; cursor: pointer; font-family: inherit;
    }
    .slot-line-empty svg { width: 16px; height: 16px; }
    .slot-line[draggable="true"] .drag-handle { cursor: grab; color: var(--text-muted); }
    .slot-line[draggable="true"]:active { opacity: 0.5; }
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

      <?php if ($isParent): ?>
        <p class="hint" style="margin-bottom:0.75rem;">Drag <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg> to move meals that aren't cooked yet.</p>
      <?php endif; ?>

      <?php foreach ($thisWeek as $i => $date): ?>
        <?php $d = new DateTimeImmutable($date); ?>
        <div class="day-row <?= $date === $today ? 'is-today' : '' ?>">
          <div class="day-heading">
            <span><?= $dayNames[$i] ?><?= $date === $today ? ' · Today' : '' ?></span>
            <span class="date-num"><?= $d->format('j M') ?></span>
          </div>
          <?php foreach (['breakfast', 'lunch', 'dinner'] as $slot): ?>
            <?php if (isset($byDate[$date][$slot])): ?>
              <?php $entry = $byDate[$date][$slot]; $cooked = (bool) $entry['cooked']; $draggable = $isParent && !$cooked; ?>
              <div class="slot-line" data-date="<?= $date ?>" data-slot="<?= $slot ?>" data-entry-id="<?= (int) $entry['entry_id'] ?>" <?= $draggable ? 'draggable="true"' : '' ?>>
                <?php if ($isParent): ?>
                  <span class="drag-handle" style="<?= $draggable ? '' : 'visibility:hidden;' ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                  </span>
                <?php endif; ?>
                <span class="slot-tag"><?= ucfirst($slot) ?></span>
                <a href="meal-detail.php?entry=<?= (int) $entry['entry_id'] ?>"><?= htmlspecialchars($entry['title']) ?></a>
                <?php if (!$guest): ?>
                  <form method="post">
                    <input type="hidden" name="action" value="toggle_cooked">
                    <input type="hidden" name="entry_id" value="<?= (int) $entry['entry_id'] ?>">
                    <button type="submit" class="cooked-mini <?= $cooked ? 'done' : '' ?>" aria-label="<?= $cooked ? 'Cooked' : 'Mark cooked' ?>">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </button>
                  </form>
                <?php endif; ?>
                <?php if ($isParent): ?>
                  <form method="post" onsubmit="return confirm('Remove this meal from <?= $dayNames[$i] ?>?');">
                    <input type="hidden" name="action" value="remove_entry">
                    <input type="hidden" name="entry_id" value="<?= (int) $entry['entry_id'] ?>">
                    <button type="submit" class="remove-btn" aria-label="Remove meal">✕</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php elseif ($isParent): ?>
              <button type="button" class="slot-line-empty" data-date="<?= $date ?>" data-slot="<?= $slot ?>" onclick="openAssignModal(this)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Add <?= $slot ?>
              </button>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if (empty($byDate[$date]) && !$isParent): ?>
            <p class="hint" style="margin:0.3rem 0;">Nothing planned yet.</p>
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

  <?php if ($isParent): ?>
  <dialog id="assign-modal">
    <h3>Add a meal</h3>
    <p class="hint" id="assign-label" style="margin-top:-0.5rem;"></p>
    <form method="post">
      <input type="hidden" name="action" value="assign_meal">
      <input type="hidden" name="date" id="assign-date">
      <input type="hidden" name="slot" id="assign-slot">
      <div class="field">
        <label for="assign-meal">Meal</label>
        <select id="assign-meal" name="meal_id" required style="width:100%; padding:0.8rem 0.9rem; border-radius:12px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:1rem;">
          <option value="">Choose a meal…</option>
          <?php foreach ($allMeals as $m): ?>
            <option value="<?= (int) $m['id'] ?>"><?= htmlspecialchars($m['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('assign-modal').close()">Cancel</button>
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
    </form>
  </dialog>

  <form method="post" id="move-form" style="display:none;">
    <input type="hidden" name="action" value="move_entry">
    <input type="hidden" name="entry_id" id="move-entry-id">
    <input type="hidden" name="target_date" id="move-target-date">
    <input type="hidden" name="target_slot" id="move-target-slot">
  </form>

  <script>
    function openAssignModal(btn) {
      document.getElementById("assign-date").value = btn.dataset.date;
      document.getElementById("assign-slot").value = btn.dataset.slot;
      document.getElementById("assign-label").textContent = btn.dataset.slot[0].toUpperCase() + btn.dataset.slot.slice(1) + " on " + btn.dataset.date;
      document.getElementById("assign-meal").value = "";
      document.getElementById("assign-modal").showModal();
    }

    let dragged = null;
    document.querySelectorAll(".slot-line[draggable='true']").forEach((row) => {
      row.addEventListener("dragstart", () => { dragged = row; row.style.opacity = "0.4"; });
      row.addEventListener("dragend", () => { row.style.opacity = "1"; });
    });
    document.querySelectorAll(".slot-line, .slot-line-empty").forEach((target) => {
      target.addEventListener("dragover", (e) => { if (dragged) e.preventDefault(); });
      target.addEventListener("drop", (e) => {
        e.preventDefault();
        if (!dragged) return;
        document.getElementById("move-entry-id").value = dragged.dataset.entryId;
        document.getElementById("move-target-date").value = target.dataset.date;
        document.getElementById("move-target-slot").value = target.dataset.slot;
        document.getElementById("move-form").submit();
      });
    });
  </script>
  <?php endif; ?>

  <script src="js/mockup.js"></script>
</body>
</html>
