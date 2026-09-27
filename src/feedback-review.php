<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

$user = require_parent();
$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'approve_feedback') {
        $feedbackStmt = $pdo->prepare('SELECT meal_name FROM feedback WHERE id = :id AND status = :status');
        $feedbackStmt->execute([':id' => $id, ':status' => 'pending']);
        $mealName = $feedbackStmt->fetchColumn();

        if ($mealName !== false) {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO meals (title, description, prep_steps) VALUES (:t, '', '[]')")
                ->execute([':t' => $mealName]);
            $newMealId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE feedback SET status = 'added' WHERE id = :id")->execute([':id' => $id]);
            $pdo->commit();

            header('Location: meal-form.php?id=' . $newMealId);
            exit;
        }
    } elseif ($action === 'dismiss_feedback') {
        $pdo->prepare("UPDATE feedback SET status = 'dismissed' WHERE id = :id AND status = 'pending'")
            ->execute([':id' => $id]);
    }

    header('Location: feedback-review.php');
    exit;
}

$suggestions = $pdo->query(
    'SELECT f.id, f.meal_name, f.note, f.status, f.created_at, u.name AS submitter
     FROM feedback f
     JOIN users u ON u.id = f.user_id
     ORDER BY (f.status = "pending") DESC, f.created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);

function time_ago(string $datetime): string
{
    $diff = (new DateTimeImmutable('now'))->getTimestamp() - (new DateTimeImmutable($datetime))->getTimestamp();
    $diff = max(0, $diff);

    if ($diff < 86400) {
        return 'today';
    }
    $days = intdiv($diff, 86400);
    if ($days < 14) {
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }
    $weeks = intdiv($days, 7);
    if ($weeks < 8) {
        return $weeks . ' week' . ($weeks === 1 ? '' : 's') . ' ago';
    }

    return intdiv($days, 30) . ' months ago';
}
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Review Suggestions — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="manifest" href="manifest.json">
  <link rel="apple-touch-icon" href="img/Icon_192x192.png">
  <meta name="theme-color" content="#1A1A1A">
  <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
  <style>
    .suggestion-card { display:flex; flex-direction:column; gap:0.5rem; }
    .suggestion-card .top-row { display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem; }
    .suggestion-card .meal-name { font-weight:700; font-size:1.05rem; }
    .suggestion-card .submitted-by { font-size:0.78rem; color:var(--text-muted); }
    .suggestion-card .note { font-size:0.88rem; color:var(--text-muted); }
    .suggestion-actions { display:flex; gap:0.6rem; margin-top:0.3rem; }
    .suggestion-actions .btn { width:auto; flex:1; }
    .suggestion-actions form { display: contents; }
  </style>
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
      <h2 class="page-title">Review Suggestions</h2>
      <p class="hint" style="margin-bottom:1rem;">Meals the family has suggested. Approve to add them to the meal catalog.</p>

      <?php if (empty($suggestions)): ?>
        <p class="hint">No suggestions yet.</p>
      <?php endif; ?>

      <?php foreach ($suggestions as $s): ?>
        <div class="card suggestion-card">
          <div class="top-row">
            <div>
              <div class="meal-name"><?= htmlspecialchars($s['meal_name']) ?></div>
              <div class="submitted-by">Suggested by <?= htmlspecialchars($s['submitter']) ?> · <?= time_ago($s['created_at']) ?></div>
            </div>
            <?php if ($s['status'] === 'pending'): ?>
              <span class="badge badge-not-cooked">Pending</span>
            <?php elseif ($s['status'] === 'added'): ?>
              <span class="badge badge-cooked">Added to catalog</span>
            <?php else: ?>
              <span class="badge badge-not-cooked">Dismissed</span>
            <?php endif; ?>
          </div>
          <?php if ($s['note'] !== ''): ?>
            <p class="note">"<?= htmlspecialchars($s['note']) ?>"</p>
          <?php endif; ?>
          <?php if ($s['status'] === 'pending'): ?>
            <div class="suggestion-actions">
              <form method="post">
                <input type="hidden" name="action" value="approve_feedback">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="btn btn-primary btn-small">Approve</button>
              </form>
              <form method="post">
                <input type="hidden" name="action" value="dismiss_feedback">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-small">Dismiss</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </main>
  </div>
  <script src="<?= asset_url('js/mockup.js') ?>"></script>
</body>
</html>
