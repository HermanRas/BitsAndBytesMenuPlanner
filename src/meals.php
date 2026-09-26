<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

$user = require_parent();
$pdo = get_db();

$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_meal') {
    $id = (int) ($_POST['id'] ?? 0);
    $usedCount = $pdo->prepare('SELECT COUNT(*) FROM menu_entries WHERE meal_id = :id');
    $usedCount->execute([':id' => $id]);
    if ((int) $usedCount->fetchColumn() > 0) {
        $flashError = "Can't delete a meal that's still on the menu — remove it from any scheduled days first.";
    } else {
        $pdo->prepare('DELETE FROM meals WHERE id = :id')->execute([':id' => $id]);
    }
}

$meals = $pdo->query('SELECT id, title, image FROM meals ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meals — BitsAndBytesMenuPlanner</title>
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
      <h2 class="page-title">Meals</h2>

      <?php if ($flashError !== null): ?>
        <p class="flash-error"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></p>
      <?php endif; ?>

      <?php foreach ($meals as $m): ?>
        <div class="fav-list-item">
          <img src="<?= htmlspecialchars($m['image'] ?: 'img/Icon_256x256.png') ?>" alt="">
          <a class="name" href="meal-form.php?id=<?= (int) $m['id'] ?>" style="color:var(--text); text-decoration:none;">
            <?= htmlspecialchars($m['title']) ?>
          </a>
          <form method="post" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($m['title']), ENT_QUOTES) ?>? This can't be undone.');">
            <input type="hidden" name="action" value="delete_meal">
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button type="submit" class="remove-btn" aria-label="Delete <?= htmlspecialchars($m['title']) ?>">✕</button>
          </form>
        </div>
      <?php endforeach; ?>

      <a href="meal-form.php" class="btn btn-primary" style="margin-top:1rem;">+ Add Meal</a>
    </main>
  </div>
</body>
</html>
