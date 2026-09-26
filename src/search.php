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
$q = trim($_GET['q'] ?? '');

if ($q === '') {
    $meals = $pdo->query('SELECT id, title, image FROM meals ORDER BY title')->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT m.id, m.title, m.image
         FROM meals m
         LEFT JOIN meal_ingredients mi ON mi.meal_id = m.id
         LEFT JOIN ingredients i ON i.id = mi.ingredient_id
         WHERE m.title LIKE :q OR i.name LIKE :q
         ORDER BY m.title'
    );
    $stmt->execute([':q' => '%' . $q . '%']);
    $meals = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$favoriteMealIds = [];
if ($user !== null) {
    $favStmt = $pdo->prepare('SELECT meal_id FROM favorites WHERE user_id = :uid');
    $favStmt->execute([':uid' => $user['id']]);
    $favoriteMealIds = array_flip($favStmt->fetchAll(PDO::FETCH_COLUMN));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'toggle_favorite') {
    $mealId = (int) ($_POST['meal_id'] ?? 0);
    $exists = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = :uid AND meal_id = :mid');
    $exists->execute([':uid' => $user['id'], ':mid' => $mealId]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('DELETE FROM favorites WHERE user_id = :uid AND meal_id = :mid')->execute([':uid' => $user['id'], ':mid' => $mealId]);
    } else {
        $pdo->prepare('INSERT INTO favorites (user_id, meal_id) VALUES (:uid, :mid)')->execute([':uid' => $user['id'], ':mid' => $mealId]);
    }
    header('Location: search.php' . ($q !== '' ? '?q=' . urlencode($q) : ''));
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Search Meals — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <a href="today.php" class="back-link">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6"/></svg>
        Back
      </a>
    </header>

    <main class="app-content">
      <h2 class="page-title">Search Meals</h2>

      <form method="get" class="search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Search by name or ingredient…" autofocus>
      </form>

      <?php if (empty($meals)): ?>
        <p class="hint">No meals match "<?= htmlspecialchars($q) ?>".</p>
      <?php endif; ?>

      <?php foreach ($meals as $m): ?>
        <?php $favorited = isset($favoriteMealIds[$m['id']]); ?>
        <div class="card" style="margin-bottom:0.9rem;">
          <div class="meal-card">
            <a href="meal-detail.php?meal=<?= (int) $m['id'] ?>" class="meal-card-link">
              <img src="<?= htmlspecialchars($m['image'] ?: 'img/Icon_256x256.png') ?>" alt="">
              <div class="meal-info">
                <div class="meal-title"><?= htmlspecialchars($m['title']) ?></div>
              </div>
            </a>
            <?php if (!$guest): ?>
              <form method="post" class="fav-form">
                <input type="hidden" name="action" value="toggle_favorite">
                <input type="hidden" name="meal_id" value="<?= (int) $m['id'] ?>">
                <button type="submit" class="fav-btn <?= $favorited ? 'active' : '' ?>" aria-label="Favorite">
                  <svg viewBox="0 0 24 24" fill="<?= $favorited ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-7-4.35-9.5-8.8C.8 8.4 2.4 5 5.8 5c1.9 0 3.3 1 4.2 2.4C11 6 12.4 5 14.3 5c3.4 0 5 3.4 3.3 6.7C19 16.15 12 20.5 12 20.5z"/></svg>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
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
      <a href="shopping-list.html">
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
</body>
</html>
