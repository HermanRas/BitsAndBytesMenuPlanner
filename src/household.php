<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/cycle.php';

$user = require_parent();
$pdo = get_db();

$categories = HOUSEHOLD_CATEGORIES;
$flashError = null;
$openAddModal = false;
$openEditModal = null; // set to the submitted values when an edit needs to reopen with an error
$submitted = null;

$returnUrl = $_GET['return'] ?? '';
if (!preg_match('/^[a-z][a-z0-9-]*\.php(\?[A-Za-z0-9_=&%-]*)?$/', $returnUrl)) {
    $returnUrl = 'settings.php';
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';
if ($action === 'add_item' || $action === 'edit_item') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $category = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'other';
    $unit = trim($_POST['unit'] ?? '');
    $price = $_POST['price'] ?? '';
    $qty = $_POST['monthly_qty'] ?? '';
    $submitted = ['id' => $id, 'name' => $name, 'category' => $category, 'unit' => $unit, 'price' => $price, 'monthly_qty' => $qty];

    $dup = $pdo->prepare('SELECT 1 FROM household_items WHERE lower(name) = lower(:name) AND id != :id');
    $dup->execute([':name' => $name, ':id' => $id]);

    if ($name === '' || $unit === '' || !is_numeric($price) || (float) $price < 0 || !is_numeric($qty) || (float) $qty < 0) {
        $flashError = 'Please enter a name, a unit, a price of 0 or more, and a monthly quantity of 0 or more.';
    } elseif ($dup->fetchColumn()) {
        $flashError = 'Another item already uses that name.';
    } else {
        $params = [
            ':name' => $name,
            ':category' => $category,
            ':unit' => $unit,
            ':price' => (float) $price,
            ':qty' => (float) $qty,
        ];
        if ($action === 'add_item') {
            $pdo->prepare(
                'INSERT INTO household_items (name, category, unit, estimated_price, monthly_qty) VALUES (:name, :category, :unit, :price, :qty)'
            )->execute($params);
        } else {
            $pdo->prepare(
                'UPDATE household_items SET name = :name, category = :category, unit = :unit, estimated_price = :price, monthly_qty = :qty WHERE id = :id'
            )->execute($params + [':id' => $id]);
            // Saving is how a "price changed" mark from the shopping list gets resolved.
            $pdo->prepare('DELETE FROM household_price_flags WHERE item_id = :id')->execute([':id' => $id]);
        }
    }

    if ($flashError !== null) {
        if ($action === 'add_item') {
            $openAddModal = true;
        } else {
            $openEditModal = $submitted;
        }
    }
}

$items = $pdo->query('SELECT id, name, category, unit, estimated_price, monthly_qty FROM household_items ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$byCategory = [];
$monthlyTotal = 0.0;
foreach ($items as $item) {
    $byCategory[$item['category']][] = $item;
    $monthlyTotal += purchase_qty((float) $item['monthly_qty'], $item['unit']) * (float) $item['estimated_price'];
}
$flaggedIds = array_flip($pdo->query('SELECT item_id FROM household_price_flags')->fetchAll(PDO::FETCH_COLUMN));
uksort($byCategory, function ($a, $b) use ($categories) {
    $ai = array_search($a, $categories, true);
    $bi = array_search($b, $categories, true);
    return ($ai === false ? 99 : $ai) <=> ($bi === false ? 99 : $bi);
});

function format_qty(float $qty): string
{
    return $qty == (int) $qty ? (string) (int) $qty : rtrim(rtrim(number_format($qty, 2), '0'), '.');
}

$selectStyle = 'width:100%; padding:0.8rem 0.9rem; border-radius:12px; border:1px solid var(--border); background:var(--surface); color:var(--text); font-size:1rem;';
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Household Items — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="manifest" href="manifest.json">
  <link rel="apple-touch-icon" href="img/Icon_192x192.png">
  <meta name="theme-color" content="#1A1A1A">
  <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES) ?>" class="back-link">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        Back
      </a>
    </header>

    <main class="app-content">
      <h2 class="page-title">Household Items</h2>
      <p class="hint" style="margin-bottom:1rem;">
        Things bought every month that aren't meal ingredients. Each item's
        monthly quantity goes on every cycle's shopping list and counts toward
        the budget — set it to 0 to skip an item for now.
        Monthly total: <strong>R<?= number_format($monthlyTotal, 2) ?></strong>
      </p>

      <?php if ($flashError !== null): ?>
        <p class="flash-error"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></p>
      <?php endif; ?>

      <?php if (!empty($flaggedIds)): ?>
        <p class="hint" style="margin-bottom:1rem;">
          <span class="price-flag-badge" style="margin-left:0;">Price changed</span>
          <?= count($flaggedIds) ?> marked on the shopping list — tap one, enter the new price and Save to clear it.
        </p>
      <?php endif; ?>

      <?php if (empty($items)): ?>
        <p class="hint">No household items yet — add the first one below.</p>
      <?php endif; ?>

      <?php foreach ($byCategory as $category => $categoryItems): ?>
        <div class="category-heading"><?= htmlspecialchars(ucfirst($category)) ?></div>
        <?php foreach ($categoryItems as $item): ?>
          <button
            type="button"
            class="check-item"
            data-id="<?= (int) $item['id'] ?>"
            data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>"
            data-category="<?= htmlspecialchars($item['category'], ENT_QUOTES) ?>"
            data-unit="<?= htmlspecialchars($item['unit'], ENT_QUOTES) ?>"
            data-price="<?= htmlspecialchars((string) $item['estimated_price'], ENT_QUOTES) ?>"
            data-qty="<?= htmlspecialchars((string) $item['monthly_qty'], ENT_QUOTES) ?>"
            onclick="openEditItem(this)"
            <?= (float) $item['monthly_qty'] > 0 ? '' : 'style="opacity:0.55;"' ?>
          >
            <span class="item-name"><?= htmlspecialchars($item['name']) ?><?php if (isset($flaggedIds[$item['id']])): ?><span class="price-flag-badge">Price changed</span><?php endif; ?></span>
            <span class="item-qty">
              <?= format_qty((float) $item['monthly_qty']) ?>/mo · <?= htmlspecialchars($item['unit']) ?> · R<?= number_format((float) $item['estimated_price'], 2) ?>
            </span>
          </button>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <button type="button" class="btn btn-secondary" style="margin-top:1rem;" onclick="document.getElementById('add-item-modal').showModal()">
        + Add Household Item
      </button>

      <?php foreach (['add' => 'Add Household Item', 'edit' => 'Edit Household Item'] as $mode => $heading): ?>
      <dialog id="<?= $mode ?>-item-modal">
        <h3><?= $heading ?></h3>
        <form method="post">
          <input type="hidden" name="action" value="<?= $mode ?>_item">
          <input type="hidden" name="id" id="<?= $mode ?>-id" value="0">
          <div class="field">
            <label for="<?= $mode ?>-name">Name</label>
            <input type="text" id="<?= $mode ?>-name" name="name" required>
          </div>
          <div class="field">
            <label for="<?= $mode ?>-category">Category</label>
            <select id="<?= $mode ?>-category" name="category" style="<?= $selectStyle ?>">
              <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>"><?= htmlspecialchars(ucfirst($cat)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="<?= $mode ?>-unit">Unit</label>
            <input type="text" id="<?= $mode ?>-unit" name="unit" placeholder="e.g. 750ml, 24 rolls, 2kg" required>
          </div>
          <div class="field">
            <label for="<?= $mode ?>-price">Estimated price (R)</label>
            <input type="number" id="<?= $mode ?>-price" name="price" min="0" step="0.01" required>
          </div>
          <div class="field">
            <label for="<?= $mode ?>-qty">Per month</label>
            <input type="number" id="<?= $mode ?>-qty" name="monthly_qty" min="0" step="0.01" value="1" required>
            <p class="hint">How many units to buy each cycle. 0 keeps it here but off the shopping list.</p>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('<?= $mode ?>-item-modal').close()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </dialog>
      <?php endforeach; ?>
    </main>
  </div>
  <script src="<?= asset_url('js/mockup.js') ?>"></script>
  <script>
    function fillItemForm(mode, values) {
      document.getElementById(mode + "-id").value = values.id;
      document.getElementById(mode + "-name").value = values.name;
      document.getElementById(mode + "-category").value = values.category;
      document.getElementById(mode + "-unit").value = values.unit;
      document.getElementById(mode + "-price").value = values.price;
      document.getElementById(mode + "-qty").value = values.monthly_qty;
      document.getElementById(mode + "-item-modal").showModal();
    }
    function openEditItem(el) {
      const d = el.dataset;
      fillItemForm("edit", { id: d.id, name: d.name, category: d.category, unit: d.unit, price: d.price, monthly_qty: d.qty });
    }
  </script>
  <?php if ($openAddModal || $openEditModal !== null): ?>
  <script>
    document.addEventListener("DOMContentLoaded", () => fillItemForm(
      <?= json_encode($openAddModal ? 'add' : 'edit') ?>,
      <?= json_encode($openAddModal ? $submitted : $openEditModal) ?>
    ));
  </script>
  <?php endif; ?>
</body>
</html>
