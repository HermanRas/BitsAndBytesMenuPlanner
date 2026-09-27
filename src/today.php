<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

$user = current_user();
$guest = is_guest();
if ($user === null && !$guest) {
    header('Location: login.php');
    exit;
}

$pdo = get_db();
$isParent = $user !== null && $user['role'] === 'parent';
$now = new DateTimeImmutable('today');
$today = $now->format('Y-m-d');

// ?date=Y-m-d lets the family step to tomorrow (or any day) from here.
$viewDay = $now;
if (isset($_GET['date'])) {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $_GET['date']);
    if ($parsed !== false && $parsed->format('Y-m-d') === $_GET['date']) {
        $viewDay = $parsed;
    }
}
$viewDate = $viewDay->format('Y-m-d');
$selfUrl = $viewDate === $today ? 'today.php' : 'today.php?date=' . $viewDate;

$cycleStmt = $pdo->prepare('SELECT id FROM menu_cycles WHERE start_date <= :d AND end_date >= :d LIMIT 1');
$cycleStmt->execute([':d' => $viewDate]);
$cycleId = $cycleStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null) {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_cooked') {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $pdo->prepare('UPDATE menu_entries SET cooked = 1 - cooked WHERE id = :id')->execute([':id' => $entryId]);
    } elseif ($action === 'assign_meal' && $isParent && $cycleId !== false) {
        $slot = $_POST['slot'] ?? '';
        $mealId = (int) ($_POST['meal_id'] ?? 0);
        if (in_array($slot, ['breakfast', 'lunch', 'dinner'], true) && $mealId > 0) {
            $exists = $pdo->prepare('SELECT 1 FROM menu_entries WHERE date = :d AND slot = :s');
            $exists->execute([':d' => $viewDate, ':s' => $slot]);
            if (!$exists->fetchColumn()) {
                $pdo->prepare('INSERT INTO menu_entries (cycle_id, date, slot, meal_id, cooked) VALUES (:cid, :d, :s, :mid, 0)')
                    ->execute([':cid' => $cycleId, ':d' => $viewDate, ':s' => $slot, ':mid' => $mealId]);
            }
        }
    } elseif ($action === 'remove_entry' && $isParent) {
        $entryId = (int) ($_POST['entry_id'] ?? 0);
        $pdo->prepare('DELETE FROM menu_entries WHERE id = :id')->execute([':id' => $entryId]);
    } elseif ($action === 'toggle_favorite') {
        $mealId = (int) ($_POST['meal_id'] ?? 0);
        $exists = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = :uid AND meal_id = :mid');
        $exists->execute([':uid' => $user['id'], ':mid' => $mealId]);
        if ($exists->fetchColumn()) {
            $pdo->prepare('DELETE FROM favorites WHERE user_id = :uid AND meal_id = :mid')
                ->execute([':uid' => $user['id'], ':mid' => $mealId]);
        } else {
            $pdo->prepare('INSERT INTO favorites (user_id, meal_id) VALUES (:uid, :mid)')
                ->execute([':uid' => $user['id'], ':mid' => $mealId]);
        }
    }

    header('Location: ' . $selfUrl);
    exit;
}

$dayOffset = (int) $now->diff($viewDay)->format('%r%a');
$dayTitle = match ($dayOffset) {
    0 => 'Today',
    1 => 'Tomorrow',
    -1 => 'Yesterday',
    default => $viewDay->format('l'),
};
$dayWord = in_array($dayOffset, [-1, 0, 1], true) ? strtolower($dayTitle) : 'on ' . $viewDay->format('D j M');
$todayLabel = $viewDay->format('l, j F');
$prevDate = $viewDay->modify('-1 day')->format('Y-m-d');
$nextDate = $viewDay->modify('+1 day')->format('Y-m-d');

$bySlot = ['breakfast' => null, 'lunch' => null, 'dinner' => null];
if ($cycleId !== false) {
    $entriesStmt = $pdo->prepare(
        "SELECT me.id AS entry_id, me.slot, me.cooked, m.id AS meal_id, m.title, m.image
         FROM menu_entries me
         JOIN meals m ON m.id = me.meal_id
         WHERE me.cycle_id = :cid AND me.date = :d
         ORDER BY CASE me.slot WHEN 'breakfast' THEN 0 WHEN 'lunch' THEN 1 ELSE 2 END, me.sort_order"
    );
    $entriesStmt->execute([':cid' => $cycleId, ':d' => $viewDate]);
    foreach ($entriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bySlot[$row['slot']] = $row;
    }
}

$favoriteMealIds = [];
if ($user !== null) {
    $favStmt = $pdo->prepare('SELECT meal_id FROM favorites WHERE user_id = :uid');
    $favStmt->execute([':uid' => $user['id']]);
    $favoriteMealIds = array_flip($favStmt->fetchAll(PDO::FETCH_COLUMN));
}

$allMeals = $isParent ? $pdo->query('SELECT id, title FROM meals ORDER BY title')->fetchAll(PDO::FETCH_ASSOC) : [];

function render_meal_card(array $entry, array $favoriteMealIds, bool $guest, bool $isParent, string $dayWord): void
{
    $favorited = isset($favoriteMealIds[$entry['meal_id']]);
    $cooked = (bool) $entry['cooked'];
    ?>
    <div class="meal-card meal-card-stacked">
      <a href="meal-detail.php?entry=<?= (int) $entry['entry_id'] ?>" class="meal-thumb" tabindex="-1" aria-hidden="true">
        <img src="<?= htmlspecialchars($entry['image'] ?: 'img/Icon_256x256.png') ?>" alt="">
      </a>
      <div class="meal-body">
        <a href="meal-detail.php?entry=<?= (int) $entry['entry_id'] ?>" class="meal-title"><?= htmlspecialchars($entry['title']) ?></a>
        <div class="meal-actions">
          <?php if ($guest): ?>
            <span class="badge <?= $cooked ? 'badge-cooked' : 'badge-not-cooked' ?>"><?= $cooked ? 'Cooked' : 'Not cooked' ?></span>
          <?php else: ?>
            <form method="post" class="cooked-toggle-form">
              <input type="hidden" name="action" value="toggle_cooked">
              <input type="hidden" name="entry_id" value="<?= (int) $entry['entry_id'] ?>">
              <button type="submit" class="badge <?= $cooked ? 'badge-cooked' : 'badge-not-cooked' ?>" style="border:none; cursor:pointer;">
                <?php if ($cooked): ?>
                  <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                <?php endif; ?>
                <?= $cooked ? 'Cooked' : 'Not cooked' ?>
              </button>
            </form>
            <form method="post" class="fav-form">
              <input type="hidden" name="action" value="toggle_favorite">
              <input type="hidden" name="meal_id" value="<?= (int) $entry['meal_id'] ?>">
              <button type="submit" class="fav-btn <?= $favorited ? 'active' : '' ?>" aria-label="Favorite">
                <svg viewBox="0 0 24 24" fill="<?= $favorited ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-7-4.35-9.5-8.8C.8 8.4 2.4 5 5.8 5c1.9 0 3.3 1 4.2 2.4C11 6 12.4 5 14.3 5c3.4 0 5 3.4 3.3 6.7C19 16.15 12 20.5 12 20.5z"/></svg>
              </button>
            </form>
          <?php endif; ?>
          <?php if ($isParent): ?>
            <form method="post" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Remove this meal from ' . $dayWord . '?'), ENT_QUOTES) ?>);">
              <input type="hidden" name="action" value="remove_entry">
              <input type="hidden" name="entry_id" value="<?= (int) $entry['entry_id'] ?>">
              <button type="submit" class="remove-btn" aria-label="Remove meal">✕</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($dayTitle) ?> — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="manifest" href="manifest.json">
  <link rel="apple-touch-icon" href="img/Icon_192x192.png">
  <meta name="theme-color" content="#1A1A1A">
  <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
</head>
<body>
  <div class="app-shell">
    <?php if ($guest): ?>
      <div class="role-banner">Guest view — sign in to favorite or mark meals cooked</div>
    <?php endif; ?>
    <header class="app-header">
      <img class="logo" src="img/Icon_128x128.png" alt="">
      <div>
        <h1><?= htmlspecialchars($dayTitle) ?></h1>
        <p class="subtitle"><?= htmlspecialchars($todayLabel) ?></p>
      </div>
      <div class="header-spacer"></div>
      <a class="icon-btn" href="search.php" aria-label="Search meals">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
      </a>
    </header>

    <main class="app-content">
      <div class="cycle-header">
        <a class="icon-btn" href="today.php?date=<?= $prevDate ?>" aria-label="Previous day">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        </a>
        <?php if ($viewDate === $today): ?>
          <span class="range"><?= htmlspecialchars($viewDay->format('D j M')) ?></span>
        <?php else: ?>
          <a class="range" href="today.php">Back to today</a>
        <?php endif; ?>
        <a class="icon-btn" href="today.php?date=<?= $nextDate ?>" aria-label="Next day">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
        </a>
      </div>

      <?php foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner'] as $slot => $label): ?>
        <div class="card" style="margin-bottom:1.1rem;">
          <div class="slot-label"><?= $label ?></div>
          <?php if ($bySlot[$slot] !== null): ?>
            <div style="margin-top:0.5rem;">
              <?php render_meal_card($bySlot[$slot], $favoriteMealIds, $guest, $isParent, $dayWord); ?>
            </div>
          <?php elseif ($isParent && $cycleId !== false): ?>
            <button type="button" class="slot-line-empty" data-slot="<?= $slot ?>" onclick="openAssignModal(this)" style="margin-top:0.5rem;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
              Add <?= $slot ?>
            </button>
          <?php elseif ($isParent): ?>
            <p class="hint" style="margin:0.5rem 0 0;">No menu cycle covers this day yet.</p>
          <?php else: ?>
            <p class="hint" style="margin:0.5rem 0 0;">Nothing planned yet.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </main>

    <nav class="bottom-nav">
      <a href="today.php" class="active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/></svg>
        <span>Today</span>
      </a>
      <a href="calendar.php">
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
  <script>
    function openAssignModal(btn) {
      document.getElementById("assign-slot").value = btn.dataset.slot;
      document.getElementById("assign-label").textContent = btn.dataset.slot[0].toUpperCase() + btn.dataset.slot.slice(1) + " " + <?= json_encode($dayWord) ?>;
      document.getElementById("assign-meal").value = "";
      document.getElementById("assign-modal").showModal();
    }
  </script>
  <?php endif; ?>

  <script src="<?= asset_url('js/mockup.js') ?>"></script>
</body>
</html>
