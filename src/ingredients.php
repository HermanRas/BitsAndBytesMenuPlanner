<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

require_parent();
$pdo = get_db();

$categories = ['produce', 'dairy', 'meat', 'bakery', 'pantry', 'spices', 'frozen'];
$flashError = null;
$openAddModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_ingredient') {
    $name = trim($_POST['name'] ?? '');
    $category = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'pantry';
    $unit = trim($_POST['unit'] ?? '');
    $price = $_POST['price'] ?? '';

    if ($name === '' || $unit === '' || !is_numeric($price) || (float) $price < 0) {
        $flashError = 'Please enter a name, a unit, and a price of 0 or more.';
        $openAddModal = true;
    } else {
        $exists = $pdo->prepare('SELECT 1 FROM ingredients WHERE lower(name) = lower(:name)');
        $exists->execute([':name' => $name]);
        if ($exists->fetchColumn()) {
            $flashError = 'An ingredient with that name already exists.';
            $openAddModal = true;
        } else {
            $pdo->prepare(
                'INSERT INTO ingredients (name, category, unit, estimated_price) VALUES (:name, :category, :unit, :price)'
            )->execute([
                ':name' => $name,
                ':category' => $category,
                ':unit' => $unit,
                ':price' => (float) $price,
            ]);
        }
    }
}

$ingredients = $pdo->query('SELECT name, category, unit, estimated_price FROM ingredients ORDER BY category, name')->fetchAll(PDO::FETCH_ASSOC);
$byCategory = [];
foreach ($ingredients as $ing) {
    $byCategory[$ing['category']][] = $ing;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ingredients — BitsAndBytesMenuPlanner</title>
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
      <h2 class="page-title">Ingredients</h2>
      <p class="hint" style="margin-bottom:1rem;">
        Every meal's ingredients come from this priced catalog, so the
        shopping list and budget warnings stay accurate.
      </p>

      <?php if ($flashError !== null): ?>
        <p class="flash-error"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></p>
      <?php endif; ?>

      <?php if (empty($ingredients)): ?>
        <p class="hint">No ingredients yet — add the first one below.</p>
      <?php endif; ?>

      <?php foreach ($byCategory as $category => $items): ?>
        <div class="category-heading"><?= htmlspecialchars(ucfirst($category)) ?></div>
        <?php foreach ($items as $ing): ?>
          <label class="check-item" style="cursor:default;">
            <span class="item-name"><?= htmlspecialchars($ing['name']) ?></span>
            <span class="item-qty">
              <?= htmlspecialchars($ing['unit']) ?> · R<?= number_format((float) $ing['estimated_price'], 2) ?>
            </span>
          </label>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <button type="button" class="btn btn-secondary" style="margin-top:1rem;" onclick="document.getElementById('add-ingredient-modal').showModal()">
        + Add Ingredient
      </button>

      <dialog id="add-ingredient-modal">
        <h3>Add Ingredient</h3>
        <form method="post">
          <input type="hidden" name="action" value="add_ingredient">
          <div class="field">
            <label for="ingredient-name">Name</label>
            <input type="text" id="ingredient-name" name="name" required>
          </div>
          <div class="field">
            <label for="ingredient-category">Category</label>
            <select id="ingredient-category" name="category" style="width:100%; padding:0.8rem 0.9rem; border-radius:12px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:1rem;">
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= ucfirst($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="ingredient-unit">Unit</label>
            <input type="text" id="ingredient-unit" name="unit" placeholder="e.g. kg, litre, packet" required>
          </div>
          <div class="field">
            <label for="ingredient-price">Estimated price (R)</label>
            <input type="number" id="ingredient-price" name="price" min="0" step="0.01" required>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-ingredient-modal').close()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </dialog>
    </main>
  </div>
  <script src="js/mockup.js"></script>
  <?php if ($openAddModal): ?>
  <script>
    document.addEventListener("DOMContentLoaded", () => document.getElementById("add-ingredient-modal").showModal());
  </script>
  <?php endif; ?>
</body>
</html>
