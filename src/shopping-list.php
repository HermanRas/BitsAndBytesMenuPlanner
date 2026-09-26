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

$backHere = 'shopping-list.php?cycle=' . $cycle['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'toggle_check') {
    $ingredientId = (int) ($_POST['ingredient_id'] ?? 0);
    $exists = $pdo->prepare('SELECT 1 FROM shopping_checks WHERE cycle_id = :cid AND ingredient_id = :iid');
    $exists->execute([':cid' => $cycle['id'], ':iid' => $ingredientId]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('DELETE FROM shopping_checks WHERE cycle_id = :cid AND ingredient_id = :iid')
            ->execute([':cid' => $cycle['id'], ':iid' => $ingredientId]);
    } else {
        $pdo->prepare('INSERT INTO shopping_checks (cycle_id, ingredient_id) VALUES (:cid, :iid)')
            ->execute([':cid' => $cycle['id'], ':iid' => $ingredientId]);
    }
    header('Location: ' . $backHere);
    exit;
}

$prevId = adjacent_cycle_id($pdo, $cycle['start_date'], -1);
$nextId = adjacent_cycle_id($pdo, $cycle['start_date'], 1);
$range = cycle_label($cycle['start_date'], $cycle['end_date']);

$itemsStmt = $pdo->prepare(
    'SELECT i.id, i.name, i.category, i.unit, i.estimated_price, SUM(mi.qty) AS total_qty
     FROM menu_entries me
     JOIN meal_ingredients mi ON mi.meal_id = me.meal_id
     JOIN ingredients i ON i.id = mi.ingredient_id
     WHERE me.cycle_id = :cid
     GROUP BY i.id
     ORDER BY i.name'
);
$itemsStmt->execute([':cid' => $cycle['id']]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$checkedStmt = $pdo->prepare('SELECT ingredient_id FROM shopping_checks WHERE cycle_id = :cid');
$checkedStmt->execute([':cid' => $cycle['id']]);
$checkedIds = array_flip($checkedStmt->fetchAll(PDO::FETCH_COLUMN));

$categoryOrder = ['produce', 'dairy', 'meat', 'bakery', 'pantry', 'spices', 'frozen'];
$byCategory = [];
$totalCost = 0.0;
foreach ($items as $item) {
    $item['line_total'] = $item['total_qty'] * $item['estimated_price'];
    $totalCost += $item['line_total'];
    $byCategory[$item['category']][] = $item;
}
uksort($byCategory, function ($a, $b) use ($categoryOrder) {
    $ai = array_search($a, $categoryOrder, true);
    $bi = array_search($b, $categoryOrder, true);
    return ($ai === false ? 99 : $ai) <=> ($bi === false ? 99 : $bi);
});

function format_qty(float $qty): string
{
    return $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim(number_format($qty, 2), '0'), '.');
}

$budget = $pdo->query('SELECT monthly_budget FROM budget_settings WHERE id = 1')->fetchColumn();
$budget = $budget !== false ? (float) $budget : null;
$overBudget = $budget !== null && $totalCost > $budget;
$budgetPct = $budget !== null && $budget > 0 ? min(100, ($totalCost / $budget) * 100) : 0;
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Shopping List — BitsAndBytesMenuPlanner</title>
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
        <h1>Shopping List</h1>
        <p class="subtitle"><?= htmlspecialchars($range) ?></p>
      </div>
      <div class="header-spacer"></div>
      <button class="icon-btn" id="share-btn" aria-label="Share list">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.5 15.4 6.5M8.6 13.5l6.8 4"/></svg>
      </button>
    </header>

    <main class="app-content">
      <div class="cycle-header">
        <?php if ($prevId !== null): ?>
          <a class="icon-btn" href="shopping-list.php?cycle=<?= $prevId ?>" aria-label="Previous cycle">
        <?php else: ?>
          <span class="icon-btn" style="opacity:0.3;" aria-hidden="true">
        <?php endif; ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        <?= $prevId !== null ? '</a>' : '</span>' ?>
        <span class="range"><?= htmlspecialchars($cycle['label']) ?> cycle</span>
        <?php if ($nextId !== null): ?>
          <a class="icon-btn" href="shopping-list.php?cycle=<?= $nextId ?>" aria-label="Next cycle">
        <?php else: ?>
          <span class="icon-btn" style="opacity:0.3;" aria-hidden="true">
        <?php endif; ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
        <?= $nextId !== null ? '</a>' : '</span>' ?>
      </div>

      <?php if ($budget !== null): ?>
        <div class="budget-card">
          <div class="row"><span>Estimated cost</span><span>R<?= number_format($totalCost, 2) ?></span></div>
          <div class="progress-track">
            <div class="progress-fill <?= $overBudget ? 'over-budget' : '' ?>" style="width:<?= $budgetPct ?>%;"></div>
          </div>
          <?php if ($overBudget): ?>
            <p class="budget-note">⚠ R<?= number_format($totalCost - $budget, 2) ?> over your R<?= number_format($budget, 2) ?> monthly budget — consider swapping a meal before you shop.</p>
          <?php else: ?>
            <p class="budget-note">Budget: R<?= number_format($budget, 2) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (empty($items)): ?>
        <p class="hint">No meals planned in this cycle yet — nothing to shop for.</p>
      <?php endif; ?>

      <?php foreach ($byCategory as $category => $categoryItems): ?>
        <div class="category-heading"><?= htmlspecialchars(ucfirst($category)) ?></div>
        <?php foreach ($categoryItems as $item): ?>
          <?php $checked = isset($checkedIds[$item['id']]); ?>
          <?php if ($guest): ?>
            <label class="check-item <?= $checked ? 'checked' : '' ?>">
              <input type="checkbox" <?= $checked ? 'checked' : '' ?> disabled>
              <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
              <span class="item-qty"><?= format_qty((float) $item['total_qty']) ?> <?= htmlspecialchars($item['unit']) ?> · R<?= number_format($item['line_total'], 2) ?></span>
            </label>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="action" value="toggle_check">
              <input type="hidden" name="ingredient_id" value="<?= (int) $item['id'] ?>">
              <label class="check-item <?= $checked ? 'checked' : '' ?>" onclick="this.closest('form').submit()">
                <input type="checkbox" <?= $checked ? 'checked' : '' ?> tabindex="-1">
                <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                <span class="item-qty"><?= format_qty((float) $item['total_qty']) ?> <?= htmlspecialchars($item['unit']) ?> · R<?= number_format($item['line_total'], 2) ?></span>
              </label>
            </form>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <button type="button" class="btn btn-secondary" id="share-btn-2" style="margin-top:1rem;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.5 15.4 6.5M8.6 13.5l6.8 4"/></svg>
        Share with family
      </button>
      <p class="hint" id="share-copied" style="display:none; text-align:center; margin-top:0.5rem;">Copied to clipboard!</p>
    </main>

    <nav class="bottom-nav">
      <a href="today.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/></svg>
        <span>Today</span>
      </a>
      <a href="calendar.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
        <span>Menu</span>
      </a>
      <a href="shopping-list.php" class="active">
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
  <script>
    (function () {
      const lines = [<?php foreach ($byCategory as $category => $categoryItems): ?>
        <?= json_encode(ucfirst((string) $category) . ':') ?>,
        <?php foreach ($categoryItems as $item): ?>
          <?= json_encode('- ' . $item['name'] . ' (' . format_qty((float) $item['total_qty']) . ' ' . $item['unit'] . ')') ?>,
        <?php endforeach; ?>
      <?php endforeach; ?>];
      const text = "Shopping List — <?= addslashes($range) ?>\n\n" + lines.join("\n") + "\n\nEstimated total: R<?= number_format($totalCost, 2) ?>";

      async function share() {
        if (navigator.share) {
          try { await navigator.share({ title: "Shopping List", text }); return; } catch (e) { /* cancelled */ }
        } else if (navigator.clipboard) {
          await navigator.clipboard.writeText(text);
          const note = document.getElementById("share-copied");
          note.style.display = "block";
          setTimeout(() => { note.style.display = "none"; }, 2000);
        }
      }
      document.getElementById("share-btn").addEventListener("click", share);
      document.getElementById("share-btn-2").addEventListener("click", share);
    })();
  </script>
</body>
</html>
