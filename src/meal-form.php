<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/uploads.php';

$user = require_parent();
$pdo = get_db();

$mealId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$isEdit = $mealId !== null;

if ($isEdit) {
    $mealStmt = $pdo->prepare('SELECT * FROM meals WHERE id = :id');
    $mealStmt->execute([':id' => $mealId]);
    $meal = $mealStmt->fetch(PDO::FETCH_ASSOC);
    if ($meal === false) {
        header('Location: meals.php');
        exit;
    }
} else {
    $meal = ['title' => '', 'description' => '', 'prep_steps' => '[]', 'image' => null];
}

$allIngredients = $pdo->query('SELECT id, name, unit FROM ingredients ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$flashError = null;
// Values used to re-render the form: DB values normally, or the submitted
// (possibly invalid) values if we're redisplaying after a validation error.
$formTitle = $meal['title'];
$formDescription = $meal['description'];
$formSteps = implode("\n", json_decode($meal['prep_steps'], true) ?? []);
$formRows = [];
if ($isEdit) {
    $rowsStmt = $pdo->prepare(
        'SELECT ingredient_id, qty FROM meal_ingredients WHERE meal_id = :id ORDER BY rowid'
    );
    $rowsStmt->execute([':id' => $mealId]);
    $formRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formTitle = trim($_POST['title'] ?? '');
    $formDescription = trim($_POST['description'] ?? '');
    $formSteps = $_POST['steps_text'] ?? '';
    $formRows = [];
    foreach ($_POST['ingredients'] ?? [] as $row) {
        $ingId = (int) ($row['ingredient_id'] ?? 0);
        $qty = $row['qty'] ?? '';
        if ($ingId > 0 && is_numeric($qty) && (float) $qty > 0) {
            $formRows[] = ['ingredient_id' => $ingId, 'qty' => (float) $qty];
        }
    }

    $steps = array_values(array_filter(array_map('trim', explode("\n", $formSteps)), fn ($s) => $s !== ''));

    if ($formTitle === '') {
        $flashError = 'Please give the meal a title.';
    } elseif (empty($formRows)) {
        $flashError = 'Add at least one ingredient with a quantity.';
    } elseif (empty($steps)) {
        $flashError = 'Add at least one preparation step.';
    } else {
        $imageResult = ['path' => null, 'error' => null];
        if (!empty($_FILES['image']['name'])) {
            $imageResult = save_meal_image($_FILES['image']);
        }

        if ($imageResult['error'] !== null) {
            $flashError = $imageResult['error'];
        } else {
            $imagePath = $imageResult['path'] ?? $meal['image'];

            $pdo->beginTransaction();
            if ($isEdit) {
                $pdo->prepare('UPDATE meals SET title=:t, description=:d, prep_steps=:s, image=:i WHERE id=:id')
                    ->execute([':t' => $formTitle, ':d' => $formDescription, ':s' => json_encode($steps, JSON_UNESCAPED_UNICODE), ':i' => $imagePath, ':id' => $mealId]);
                $pdo->prepare('DELETE FROM meal_ingredients WHERE meal_id = :id')->execute([':id' => $mealId]);
            } else {
                $pdo->prepare('INSERT INTO meals (title, description, prep_steps, image) VALUES (:t, :d, :s, :i)')
                    ->execute([':t' => $formTitle, ':d' => $formDescription, ':s' => json_encode($steps, JSON_UNESCAPED_UNICODE), ':i' => $imagePath]);
                $mealId = (int) $pdo->lastInsertId();
            }

            $insertRow = $pdo->prepare('INSERT INTO meal_ingredients (meal_id, ingredient_id, qty) VALUES (:mid, :iid, :qty)');
            foreach ($formRows as $row) {
                $insertRow->execute([':mid' => $mealId, ':iid' => $row['ingredient_id'], ':qty' => $row['qty']]);
            }
            $pdo->commit();

            header('Location: meals.php');
            exit;
        }
    }
}

function ingredient_options(array $all, int $selectedId): string
{
    $html = '<option value="">Choose…</option>';
    foreach ($all as $ing) {
        $sel = $ing['id'] === $selectedId ? 'selected' : '';
        $html .= '<option value="' . $ing['id'] . '" ' . $sel . '>' . htmlspecialchars($ing['name']) . ' (' . htmlspecialchars($ing['unit']) . ')</option>';
    }

    return $html;
}
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $isEdit ? 'Edit Meal' : 'Add Meal' ?> — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="manifest" href="manifest.json">
  <link rel="apple-touch-icon" href="img/Icon_192x192.png">
  <meta name="theme-color" content="#1A1A1A">
  <link rel="stylesheet" href="css/app.css">
  <style>
    .ingredient-row { display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem; }
    .ingredient-row select { flex: 1; padding: 0.7rem; border-radius: 10px; border: 1px solid var(--border); background: var(--surface); color: var(--text); }
    .ingredient-row input[type="number"] { width: 80px; padding: 0.7rem; border-radius: 10px; border: 1px solid var(--border); background: var(--surface); color: var(--text); }
    .ingredient-row button { background: none; border: none; color: var(--danger); font-weight: 700; font-size: 1.1rem; cursor: pointer; padding: 0.3rem 0.5rem; }
    .current-image { width: 100%; max-width: 200px; border-radius: 12px; margin-bottom: 0.75rem; display: block; }
  </style>
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <a href="meals.php" class="back-link">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        Back
      </a>
    </header>

    <main class="app-content">
      <h2 class="page-title"><?= $isEdit ? 'Edit Meal' : 'Add Meal' ?></h2>

      <?php if ($flashError !== null): ?>
        <p class="flash-error"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></p>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <div class="field">
          <label for="title">Title</label>
          <input type="text" id="title" name="title" value="<?= htmlspecialchars($formTitle, ENT_QUOTES) ?>" required>
        </div>
        <div class="field">
          <label for="description">Description</label>
          <input type="text" id="description" name="description" value="<?= htmlspecialchars($formDescription, ENT_QUOTES) ?>">
        </div>

        <div class="field">
          <label>Photo</label>
          <?php if (!empty($meal['image'])): ?>
            <img class="current-image" src="<?= htmlspecialchars($meal['image']) ?>" alt="">
          <?php endif; ?>
          <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
          <p class="hint"><?= $isEdit ? 'Leave blank to keep the current photo.' : 'Optional — JPEG, PNG, or WebP, under 5MB.' ?></p>
        </div>

        <div class="field">
          <label>Ingredients</label>
          <div id="ingredient-rows">
            <?php foreach ($formRows as $i => $row): ?>
              <div class="ingredient-row">
                <select name="ingredients[<?= $i ?>][ingredient_id]">
                  <?= ingredient_options($allIngredients, $row['ingredient_id']) ?>
                </select>
                <input type="number" name="ingredients[<?= $i ?>][qty]" value="<?= htmlspecialchars((string) $row['qty']) ?>" min="0" step="0.01" placeholder="Qty">
                <button type="button" onclick="this.parentElement.remove()">✕</button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-secondary btn-small" onclick="addIngredientRow()">+ Add ingredient</button>
          <p class="hint">Only ingredients already in the <a href="ingredients.php">catalog</a> can be used — add a new one there first if it's missing.</p>
        </div>

        <div class="field">
          <label for="steps_text">Preparation steps</label>
          <textarea id="steps_text" name="steps_text" rows="6" style="width:100%; padding:0.8rem 0.9rem; border-radius:12px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:1rem; font-family:inherit;" placeholder="One step per line"><?= htmlspecialchars($formSteps) ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Meal' ?></button>
      </form>
    </main>
  </div>

  <template id="ingredient-row-template">
    <div class="ingredient-row">
      <select name="ingredients[__INDEX__][ingredient_id]">
        <?= ingredient_options($allIngredients, 0) ?>
      </select>
      <input type="number" name="ingredients[__INDEX__][qty]" min="0" step="0.01" placeholder="Qty">
      <button type="button" onclick="this.parentElement.remove()">✕</button>
    </div>
  </template>
  <script>
    let ingredientRowIndex = <?= count($formRows) ?>;
    function addIngredientRow() {
      const tpl = document.getElementById("ingredient-row-template").innerHTML.replaceAll("__INDEX__", ingredientRowIndex++);
      document.getElementById("ingredient-rows").insertAdjacentHTML("beforeend", tpl);
    }
  </script>
</body>
</html>
