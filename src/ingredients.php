<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

$user = require_parent();
$pdo = get_db();

$categories = ['produce', 'dairy', 'meat', 'bakery', 'pantry', 'spices', 'frozen'];
$flashError = null;
$openAddModal = false;
$openEditModal = null; // set to the submitted values when an edit needs to reopen with an error

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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_ingredient') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $category = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'pantry';
    $unit = trim($_POST['unit'] ?? '');
    $price = $_POST['price'] ?? '';

    if ($name === '' || $unit === '' || !is_numeric($price) || (float) $price < 0) {
        $flashError = 'Please enter a name, a unit, and a price of 0 or more.';
        $openEditModal = ['id' => $id, 'name' => $name, 'category' => $category, 'unit' => $unit, 'price' => $price];
    } else {
        $dup = $pdo->prepare('SELECT 1 FROM ingredients WHERE lower(name) = lower(:name) AND id != :id');
        $dup->execute([':name' => $name, ':id' => $id]);
        if ($dup->fetchColumn()) {
            $flashError = 'Another ingredient already uses that name.';
            $openEditModal = ['id' => $id, 'name' => $name, 'category' => $category, 'unit' => $unit, 'price' => $price];
        } else {
            $pdo->prepare(
                'UPDATE ingredients SET name = :name, category = :category, unit = :unit, estimated_price = :price WHERE id = :id'
            )->execute([
                ':name' => $name,
                ':category' => $category,
                ':unit' => $unit,
                ':price' => (float) $price,
                ':id' => $id,
            ]);
        }
    }
}

$ingredients = $pdo->query('SELECT id, name, category, unit, estimated_price FROM ingredients ORDER BY category, name')->fetchAll(PDO::FETCH_ASSOC);
$byCategory = [];
foreach ($ingredients as $ing) {
    $byCategory[$ing['category']][] = $ing;
}
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
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
          <button
            type="button"
            class="check-item"
            data-id="<?= (int) $ing['id'] ?>"
            data-name="<?= htmlspecialchars($ing['name'], ENT_QUOTES) ?>"
            data-category="<?= htmlspecialchars($ing['category'], ENT_QUOTES) ?>"
            data-unit="<?= htmlspecialchars($ing['unit'], ENT_QUOTES) ?>"
            data-price="<?= htmlspecialchars((string) $ing['estimated_price'], ENT_QUOTES) ?>"
            onclick="openEditIngredient(this)"
          >
            <span class="item-name"><?= htmlspecialchars($ing['name']) ?></span>
            <span class="item-qty">
              <?= htmlspecialchars($ing['unit']) ?> · R<?= number_format((float) $ing['estimated_price'], 2) ?>
            </span>
          </button>
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

      <dialog id="edit-ingredient-modal">
        <h3>Edit Ingredient</h3>
        <form method="post">
          <input type="hidden" name="action" value="edit_ingredient">
          <input type="hidden" name="id" id="edit-id">
          <div class="field">
            <label for="edit-name">Name</label>
            <input type="text" id="edit-name" name="name" required>
          </div>
          <div class="field">
            <label for="edit-category">Category</label>
            <select id="edit-category" name="category" style="width:100%; padding:0.8rem 0.9rem; border-radius:12px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:1rem;">
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>"><?= ucfirst($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="edit-unit">Unit</label>
            <input type="text" id="edit-unit" name="unit" required>
          </div>
          <div class="field">
            <label for="edit-price">Estimated price (R)</label>
            <input type="number" id="edit-price" name="price" min="0" step="0.01" required>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('edit-ingredient-modal').close()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </dialog>
    </main>
  </div>
  <script src="js/mockup.js"></script>
  <script>
    function openEditIngredient(el) {
      document.getElementById("edit-id").value = el.dataset.id;
      document.getElementById("edit-name").value = el.dataset.name;
      document.getElementById("edit-category").value = el.dataset.category;
      document.getElementById("edit-unit").value = el.dataset.unit;
      document.getElementById("edit-price").value = el.dataset.price;
      document.getElementById("edit-ingredient-modal").showModal();
    }
  </script>
  <?php if ($openAddModal): ?>
  <script>
    document.addEventListener("DOMContentLoaded", () => document.getElementById("add-ingredient-modal").showModal());
  </script>
  <?php endif; ?>
  <?php if ($openEditModal !== null): ?>
  <script>
    document.addEventListener("DOMContentLoaded", () => {
      document.getElementById("edit-id").value = <?= json_encode($openEditModal['id']) ?>;
      document.getElementById("edit-name").value = <?= json_encode($openEditModal['name']) ?>;
      document.getElementById("edit-category").value = <?= json_encode($openEditModal['category']) ?>;
      document.getElementById("edit-unit").value = <?= json_encode($openEditModal['unit']) ?>;
      document.getElementById("edit-price").value = <?= json_encode($openEditModal['price']) ?>;
      document.getElementById("edit-ingredient-modal").showModal();
    });
  </script>
  <?php endif; ?>
</body>
</html>
