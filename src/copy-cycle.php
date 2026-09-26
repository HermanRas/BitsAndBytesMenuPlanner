<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cycle.php';

$user = require_parent();
$pdo = get_db();

$cycles = $pdo->query('SELECT * FROM menu_cycles ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$latest = $cycles[0] ?? null;

$countStmt = $pdo->query('SELECT cycle_id, COUNT(*) AS n FROM menu_entries GROUP BY cycle_id');
$entryCounts = array_column($countStmt->fetchAll(PDO::FETCH_ASSOC), 'n', 'cycle_id');

$flashError = null;
$dest = null;
$destLabel = null;

if ($latest !== null) {
    $paydayMonth = payday_month_for_end($latest['end_date']);
    $dest = generate_cycle($paydayMonth['year'], $paydayMonth['month']);
    $destLabel = cycle_label($dest['start'], $dest['end']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'copy_cycle' && $dest !== null) {
    $sourceId = (int) ($_POST['source_cycle_id'] ?? 0);
    $source = get_cycle($pdo, $sourceId);

    if ($source === null) {
        $flashError = 'Choose a cycle to copy from.';
    } else {
        $existing = $pdo->prepare('SELECT id FROM menu_cycles WHERE start_date = :s AND end_date = :e');
        $existing->execute([':s' => $dest['start'], ':e' => $dest['end']]);

        if ($existing->fetchColumn() !== false) {
            $flashError = 'That cycle already exists — open it from the Menu tab instead.';
        } else {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT INTO menu_cycles (start_date, end_date, label) VALUES (:s, :e, :l)')
                ->execute([':s' => $dest['start'], ':e' => $dest['end'], ':l' => $destLabel]);
            $newCycleId = (int) $pdo->lastInsertId();
            $newCycle = get_cycle($pdo, $newCycleId);

            $sourceWeeks = cycle_weeks($source);
            $destWeeks = cycle_weeks($newCycle);

            $entriesStmt = $pdo->prepare('SELECT date, slot, meal_id, sort_order FROM menu_entries WHERE cycle_id = :cid');
            $entriesStmt->execute([':cid' => $source['id']]);
            $byDate = [];
            foreach ($entriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byDate[$row['date']][] = $row;
            }

            $insertEntry = $pdo->prepare(
                'INSERT INTO menu_entries (cycle_id, date, slot, meal_id, cooked, sort_order) VALUES (:cid, :date, :slot, :mid, 0, :so)'
            );

            foreach ($destWeeks as $weekIndex => $destWeek) {
                $srcWeek = $sourceWeeks[$weekIndex % count($sourceWeeks)];
                foreach ($destWeek as $dayIndex => $destDate) {
                    foreach ($byDate[$srcWeek[$dayIndex]] ?? [] as $entry) {
                        $insertEntry->execute([
                            ':cid' => $newCycleId,
                            ':date' => $destDate,
                            ':slot' => $entry['slot'],
                            ':mid' => $entry['meal_id'],
                            ':so' => $entry['sort_order'],
                        ]);
                    }
                }
            }

            $pdo->commit();
            header('Location: calendar.php?cycle=' . $newCycleId);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Copy Cycle — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <a href="settings.php" class="back-link">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        Back
      </a>
    </header>

    <main class="app-content">
      <h2 class="page-title">Copy Cycle</h2>

      <?php if ($flashError !== null): ?>
        <p class="flash-error"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></p>
      <?php endif; ?>

      <?php if ($latest === null): ?>
        <p class="hint">No cycles exist yet — nothing to copy from.</p>
      <?php else: ?>
        <p class="hint" style="margin-bottom:1rem;">
          Pick a cycle to reuse its menu. Week 1 copies onto week 1, week 2
          onto week 2, and so on — if <?= htmlspecialchars($destLabel) ?> runs
          longer than the cycle you pick, the extra week(s) repeat from week 1.
        </p>

        <div class="budget-card">
          <div class="row"><span>New cycle</span><span><?= htmlspecialchars($destLabel) ?></span></div>
          <p class="budget-note">Starts fresh with everything marked "not cooked".</p>
        </div>

        <form method="post">
          <input type="hidden" name="action" value="copy_cycle">
          <h3 class="section-heading" style="margin-top:0;">Copy from</h3>
          <?php foreach ($cycles as $c): ?>
            <label class="check-item">
              <input type="radio" name="source_cycle_id" value="<?= (int) $c['id'] ?>" required>
              <span class="item-name"><?= htmlspecialchars($c['label']) ?></span>
              <span class="item-qty"><?= (int) ($entryCounts[$c['id']] ?? 0) ?> meals planned</span>
            </label>
          <?php endforeach; ?>

          <button type="submit" class="btn btn-primary" style="margin-top:1rem;">Copy to <?= htmlspecialchars($destLabel) ?></button>
        </form>
      <?php endif; ?>
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
