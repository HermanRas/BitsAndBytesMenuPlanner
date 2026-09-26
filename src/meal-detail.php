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
$entryId = isset($_GET['entry']) ? (int) $_GET['entry'] : null;
$mealIdParam = isset($_GET['meal']) ? (int) $_GET['meal'] : null;

if ($entryId !== null) {
    $stmt = $pdo->prepare(
        'SELECT me.id AS entry_id, me.slot, me.cooked, me.date, m.*
         FROM menu_entries me
         JOIN meals m ON m.id = me.meal_id
         WHERE me.id = :id'
    );
    $stmt->execute([':id' => $entryId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    $viewUrl = 'meal-detail.php?entry=' . $entryId;
} elseif ($mealIdParam !== null) {
    $stmt = $pdo->prepare('SELECT * FROM meals WHERE id = :id');
    $stmt->execute([':id' => $mealIdParam]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    $viewUrl = 'meal-detail.php?meal=' . $mealIdParam;
} else {
    $entry = false;
}

if ($entry === false) {
    header('Location: today.php');
    exit;
}
$hasEntry = $entryId !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null) {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_cooked' && $hasEntry) {
        $pdo->prepare('UPDATE menu_entries SET cooked = 1 - cooked WHERE id = :id')->execute([':id' => $entryId]);
    } elseif ($action === 'toggle_favorite') {
        $exists = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = :uid AND meal_id = :mid');
        $exists->execute([':uid' => $user['id'], ':mid' => $entry['id']]);
        if ($exists->fetchColumn()) {
            $pdo->prepare('DELETE FROM favorites WHERE user_id = :uid AND meal_id = :mid')
                ->execute([':uid' => $user['id'], ':mid' => $entry['id']]);
        } else {
            $pdo->prepare('INSERT INTO favorites (user_id, meal_id) VALUES (:uid, :mid)')
                ->execute([':uid' => $user['id'], ':mid' => $entry['id']]);
        }
    }

    header('Location: ' . $viewUrl);
    exit;
}

$favorited = false;
if ($user !== null) {
    $favStmt = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = :uid AND meal_id = :mid');
    $favStmt->execute([':uid' => $user['id'], ':mid' => $entry['id']]);
    $favorited = (bool) $favStmt->fetchColumn();
}

$ingredientsStmt = $pdo->prepare(
    'SELECT i.name, i.unit, i.estimated_price, mi.qty
     FROM meal_ingredients mi
     JOIN ingredients i ON i.id = mi.ingredient_id
     WHERE mi.meal_id = :mid
     ORDER BY i.name'
);
$ingredientsStmt->execute([':mid' => $entry['id']]);
$ingredients = $ingredientsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCost = 0.0;
foreach ($ingredients as &$ing) {
    $ing['line_total'] = $ing['qty'] * $ing['estimated_price'];
    $totalCost += $ing['line_total'];
}
unset($ing);

function format_qty(float $qty): string
{
    return $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim(number_format($qty, 2), '0'), '.');
}

$steps = json_decode($entry['prep_steps'], true) ?? [];
$cooked = $hasEntry ? (bool) $entry['cooked'] : false;
$slotLabel = $hasEntry ? ucfirst($entry['slot']) : null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($entry['title']) ?> — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <?php if ($hasEntry): ?>
        <a href="today.php" class="back-link">
      <?php else: ?>
        <a href="search.php" onclick="if (history.length > 1) { history.back(); return false; }" class="back-link">
      <?php endif; ?>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        Back
      </a>
      <div class="header-spacer"></div>
      <a href="ingredients.php" class="icon-btn parent-only" aria-label="Manage Ingredients" title="Manage Ingredients">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
      </a>
      <a class="icon-btn parent-only" href="meal-form.php?id=<?= (int) $entry['id'] ?>" aria-label="Edit meal">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      </a>
    </header>

    <main class="app-content">
      <img class="hero-image" src="<?= htmlspecialchars($entry['image'] ?: 'img/Icon_1024x1024.png') ?>" alt="">

      <div style="display:flex; align-items:flex-start; gap:0.75rem;">
        <h2 class="page-title" style="flex:1; margin-bottom:0.25rem;"><?= htmlspecialchars($entry['title']) ?></h2>
        <?php if (!$guest): ?>
          <form method="post" style="margin-top:0.35rem;">
            <input type="hidden" name="action" value="toggle_favorite">
            <button type="submit" class="fav-btn <?= $favorited ? 'active' : '' ?>" aria-label="Favorite">
              <svg viewBox="0 0 24 24" fill="<?= $favorited ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-7-4.35-9.5-8.8C.8 8.4 2.4 5 5.8 5c1.9 0 3.3 1 4.2 2.4C11 6 12.4 5 14.3 5c3.4 0 5 3.4 3.3 6.7C19 16.15 12 20.5 12 20.5z"/></svg>
            </button>
          </form>
        <?php endif; ?>
      </div>

      <?php if ($hasEntry): ?>
      <div class="pill-row">
        <span class="badge badge-not-cooked"><?= htmlspecialchars($slotLabel) ?></span>
        <?php if ($guest): ?>
          <span class="badge <?= $cooked ? 'badge-cooked' : 'badge-not-cooked' ?>"><?= $cooked ? 'Cooked' : 'Not cooked' ?></span>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="toggle_cooked">
            <button type="submit" class="badge <?= $cooked ? 'badge-cooked' : 'badge-not-cooked' ?>" style="border:none; cursor:pointer;">
              <?php if ($cooked): ?>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
              <?php endif; ?>
              <?= $cooked ? 'Cooked' : 'Not cooked' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <p style="color:var(--text-muted); line-height:1.5;">
        <?= htmlspecialchars($entry['description']) ?>
      </p>

      <h3 class="section-heading" style="margin-top:1.5rem;">Ingredients</h3>
      <ul class="ingredient-list">
        <?php foreach ($ingredients as $ing): ?>
          <li>
            <span><?= htmlspecialchars($ing['name']) ?></span>
            <span class="price"><?= format_qty((float) $ing['qty']) ?> <?= htmlspecialchars($ing['unit']) ?> · R<?= number_format($ing['line_total'], 2) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="budget-note" style="text-align:right;">Estimated cost: R<?= number_format($totalCost, 2) ?></p>

      <h3 class="section-heading">Preparation</h3>
      <ol class="steps-list">
        <?php foreach ($steps as $step): ?>
          <li><?= htmlspecialchars($step) ?></li>
        <?php endforeach; ?>
      </ol>
    </main>
  </div>
  <script src="js/mockup.js"></script>
</body>
</html>
