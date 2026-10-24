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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'toggle_household_check') {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $deleted = $pdo->prepare('DELETE FROM household_checks WHERE cycle_id = :cid AND item_id = :iid');
    $deleted->execute([':cid' => $cycle['id'], ':iid' => $itemId]);
    if ($deleted->rowCount() === 0) {
        $pdo->prepare('INSERT INTO household_checks (cycle_id, item_id) VALUES (:cid, :iid)')
            ->execute([':cid' => $cycle['id'], ':iid' => $itemId]);
    }
    header('Location: ' . $backHere);
    exit;
}

// "Price changed" marks live on the item, not the cycle, so they survive
// until someone updates the price at home (saving the item clears them).
$priceFlagTables = [
    'ingredient' => ['table' => 'price_flags', 'column' => 'ingredient_id'],
    'household' => ['table' => 'household_price_flags', 'column' => 'item_id'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'toggle_price_flag'
    && isset($priceFlagTables[$_POST['kind'] ?? ''])) {
    ['table' => $table, 'column' => $column] = $priceFlagTables[$_POST['kind']];
    $id = (int) ($_POST['id'] ?? 0);
    $deleted = $pdo->prepare("DELETE FROM $table WHERE $column = :id");
    $deleted->execute([':id' => $id]);
    if ($deleted->rowCount() === 0) {
        $pdo->prepare("INSERT INTO $table ($column) VALUES (:id)")->execute([':id' => $id]);
    }
    header('Location: ' . $backHere);
    exit;
}

$prevId = adjacent_cycle_id($pdo, $cycle['start_date'], -1);
$nextId = adjacent_cycle_id($pdo, $cycle['start_date'], 1);
$range = cycle_label($cycle['start_date'], $cycle['end_date']);

$items = cycle_shopping_items($pdo, (int) $cycle['id']);

$checkedStmt = $pdo->prepare('SELECT ingredient_id FROM shopping_checks WHERE cycle_id = :cid');
$checkedStmt->execute([':cid' => $cycle['id']]);
$checkedIds = array_flip($checkedStmt->fetchAll(PDO::FETCH_COLUMN));

$householdItems = household_shopping_items($pdo);

$householdCheckedStmt = $pdo->prepare('SELECT item_id FROM household_checks WHERE cycle_id = :cid');
$householdCheckedStmt->execute([':cid' => $cycle['id']]);
$householdCheckedIds = array_flip($householdCheckedStmt->fetchAll(PDO::FETCH_COLUMN));

/** Items grouped by category in the given order (unknown categories last), plus their summed cost. */
function group_by_category(array $items, array $categoryOrder): array
{
    $byCategory = [];
    $total = 0.0;
    foreach ($items as $item) {
        $total += $item['line_total'];
        $byCategory[$item['category']][] = $item;
    }
    uksort($byCategory, function ($a, $b) use ($categoryOrder) {
        $ai = array_search($a, $categoryOrder, true);
        $bi = array_search($b, $categoryOrder, true);
        return ($ai === false ? 99 : $ai) <=> ($bi === false ? 99 : $bi);
    });
    return [$byCategory, $total];
}

[$byCategory, $mealsCost] = group_by_category($items, ['produce', 'dairy', 'meat', 'bakery', 'pantry', 'spices', 'frozen']);
[$householdByCategory, $householdCost] = group_by_category($householdItems, HOUSEHOLD_CATEGORIES);
$totalCost = $mealsCost + $householdCost;

$flaggedIds = [];
foreach ($priceFlagTables as $kind => ['table' => $table, 'column' => $column]) {
    $flaggedIds[$kind] = array_flip($pdo->query("SELECT $column FROM $table")->fetchAll(PDO::FETCH_COLUMN));
}

// The two halves of the list share markup; only the toggle action and the
// checked/flagged-state tables differ.
$sections = [
    ['heading' => null, 'byCategory' => $byCategory, 'checked' => $checkedIds, 'action' => 'toggle_check', 'field' => 'ingredient_id', 'kind' => 'ingredient'],
    ['heading' => 'Household', 'byCategory' => $householdByCategory, 'checked' => $householdCheckedIds, 'action' => 'toggle_household_check', 'field' => 'item_id', 'kind' => 'household'],
];

const PRICE_TAG_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>';

function format_qty(float $qty): string
{
    return $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim(number_format($qty, 2), '0'), '.');
}

/** "3 300g jar", plus how much the menu actually uses when that was rounded up. */
function buy_label(array $item): string
{
    $label = format_qty($item['buy_qty']) . ' ' . $item['unit'];
    if (round($item['buy_qty'], 4) !== round($item['total_qty'], 4)) {
        $label .= ' (uses ' . format_qty($item['total_qty']) . ')';
    }
    return $label;
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
  <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
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
          <?php if (!empty($householdItems)): ?>
            <p class="budget-note">Meals R<?= number_format($mealsCost, 2) ?> · Household R<?= number_format($householdCost, 2) ?></p>
          <?php endif; ?>
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

      <?php if (!$guest && (!empty($items) || !empty($householdItems))): ?>
        <?php $flagCount = count($flaggedIds['ingredient']) + count($flaggedIds['household']); ?>
        <p class="hint" style="margin-top:0.75rem;">
          Shelf price different? Tap the tag on the right to mark it, then update it at home.
          <?php if ($flagCount > 0): ?>
            <strong><?= $flagCount ?> marked</strong> — see <a href="ingredients.php?return=<?= urlencode($backHere) ?>">Ingredients</a> / <a href="household.php?return=<?= urlencode($backHere) ?>">Household</a>.
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <?php foreach ($sections as $section): ?>
        <?php if ($section['heading'] !== null && !empty($section['byCategory'])): ?>
          <h3 class="section-heading"><?= htmlspecialchars($section['heading']) ?></h3>
        <?php endif; ?>
        <div class="category-columns">
        <?php foreach ($section['byCategory'] as $category => $categoryItems): ?>
          <div class="category-group">
          <div class="category-heading"><?= htmlspecialchars(ucfirst($category)) ?></div>
          <?php foreach ($categoryItems as $item): ?>
            <?php
              $checked = isset($section['checked'][$item['id']]);
              $flagged = isset($flaggedIds[$section['kind']][$item['id']]);
              $flagLabel = $flagged ? 'Price change marked — tap to unmark' : 'Mark price as changed';
            ?>
            <?php if ($guest): ?>
              <div class="check-item <?= $checked ? 'checked' : '' ?>">
                <input type="checkbox" <?= $checked ? 'checked' : '' ?> disabled>
                <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                <span class="item-qty"><?= htmlspecialchars(buy_label($item)) ?> · R<?= number_format($item['line_total'], 2) ?></span>
                <button type="button" class="price-flag <?= $flagged ? 'on' : '' ?>" disabled aria-label="<?= $flagged ? 'Price change marked' : 'Price unchanged' ?>"><?= PRICE_TAG_ICON ?></button>
              </div>
            <?php else: ?>
              <div class="check-item <?= $checked ? 'checked' : '' ?>">
                <form method="post" class="check-main" onclick="this.submit()">
                  <input type="hidden" name="action" value="<?= $section['action'] ?>">
                  <input type="hidden" name="<?= $section['field'] ?>" value="<?= (int) $item['id'] ?>">
                  <input type="checkbox" <?= $checked ? 'checked' : '' ?> tabindex="-1" aria-label="Bought <?= htmlspecialchars($item['name'], ENT_QUOTES) ?>">
                  <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                  <span class="item-qty"><?= htmlspecialchars(buy_label($item)) ?> · R<?= number_format($item['line_total'], 2) ?></span>
                </form>
                <form method="post">
                  <input type="hidden" name="action" value="toggle_price_flag">
                  <input type="hidden" name="kind" value="<?= $section['kind'] ?>">
                  <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                  <button type="submit" class="price-flag <?= $flagged ? 'on' : '' ?>" aria-pressed="<?= $flagged ? 'true' : 'false' ?>" aria-label="<?= $flagLabel ?>" title="<?= $flagLabel ?>"><?= PRICE_TAG_ICON ?></button>
                </form>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        </div>
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
  <script src="<?= asset_url('js/mockup.js') ?>"></script>
  <script>
    (function () {
      const lines = [<?php foreach (array_merge($byCategory, $householdByCategory) as $category => $categoryItems): ?>
        <?= json_encode(ucfirst((string) $category) . ':') ?>,
        <?php foreach ($categoryItems as $item): ?>
          <?= json_encode('- ' . $item['name'] . ' (' . format_qty($item['buy_qty']) . ' ' . $item['unit'] . ')') ?>,
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
