<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

$user = current_user();
if ($user === null) {
    header('Location: today.php');
    exit;
}

$pdo = get_db();
$flashError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_feedback') {
    $mealName = trim($_POST['meal_name'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($mealName === '') {
        $flashError = 'Please enter a meal name.';
    } else {
        $pdo->prepare('INSERT INTO feedback (user_id, meal_name, note) VALUES (:uid, :name, :note)')
            ->execute([':uid' => $user['id'], ':name' => $mealName, ':note' => $note]);
        header('Location: feedback.php');
        exit;
    }
}

$suggestions = $pdo->query('SELECT meal_name, status FROM feedback ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

$statusBadge = [
    'pending' => ['Pending review', 'badge-not-cooked'],
    'added' => ['Added to catalog', 'badge-cooked'],
    'dismissed' => ['Dismissed', 'badge-not-cooked'],
];
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Suggest a Meal — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="manifest" href="manifest.json">
  <link rel="apple-touch-icon" href="img/Icon_192x192.png">
  <meta name="theme-color" content="#1A1A1A">
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
      <h2 class="page-title">Suggest a Meal</h2>
      <p class="hint" style="margin-bottom:1rem;">Got an idea for the menu? Let the family know.</p>

      <?php if ($flashError !== null): ?>
        <p class="flash-error"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></p>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="action" value="submit_feedback">
        <div class="field">
          <label for="meal-name">Meal name</label>
          <input type="text" id="meal-name" name="meal_name" placeholder="e.g. Bunny Chow" required>
        </div>
        <div class="field">
          <label for="meal-why">Why should we add it?</label>
          <input type="text" id="meal-why" name="note" placeholder="Optional notes for whoever's planning">
        </div>
        <button type="submit" class="btn btn-primary">Submit Suggestion</button>
      </form>

      <h3 class="section-heading">Recent suggestions</h3>
      <?php if (empty($suggestions)): ?>
        <p class="hint">No suggestions yet — be the first!</p>
      <?php endif; ?>
      <?php foreach ($suggestions as $s): ?>
        <?php [$label, $class] = $statusBadge[$s['status']]; ?>
        <div class="card" style="display:flex; justify-content:space-between; align-items:center;">
          <span><?= htmlspecialchars($s['meal_name']) ?></span>
          <span class="badge <?= $class ?>"><?= $label ?></span>
        </div>
      <?php endforeach; ?>
    </main>
  </div>
  <script src="js/mockup.js"></script>
</body>
</html>
