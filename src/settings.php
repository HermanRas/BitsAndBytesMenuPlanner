<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

$user = current_user();
$guest = is_guest();
if ($user === null && !$guest) {
    header('Location: login.php');
    exit;
}

$isParent = $user !== null && $user['role'] === 'parent';
$flashError = null;
$openAddModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isParent) {
    $pdo = get_db();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_member') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $pin = trim($_POST['pin'] ?? '');
        $role = ($_POST['role'] ?? '') === 'parent' ? 'parent' : 'child';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{4,6}$/', $pin)) {
            $flashError = 'Please enter a name, a valid email, and a 4–6 digit PIN.';
            $openAddModal = true;
        } else {
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE lower(email) = lower(:email)');
            $exists->execute([':email' => $email]);
            if ($exists->fetchColumn()) {
                $flashError = 'That email is already in use by another family member.';
                $openAddModal = true;
            } else {
                $pdo->prepare(
                    'INSERT INTO users (name, email, pin_hash, role, theme, accent_hue) VALUES (:name, :email, :pin_hash, :role, :theme, :hue)'
                )->execute([
                    ':name' => $name,
                    ':email' => $email,
                    ':pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
                    ':role' => $role,
                    ':theme' => 'light',
                    ':hue' => 90,
                ]);
            }
        }
    } elseif ($action === 'remove_member') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id === (int) $user['id']) {
            $flashError = "You can't remove yourself.";
        } else {
            $target = $pdo->prepare('SELECT role FROM users WHERE id = :id');
            $target->execute([':id' => $id]);
            $targetRole = $target->fetchColumn();

            if ($targetRole === 'parent') {
                $parentCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
                if ($parentCount <= 1) {
                    $flashError = "Can't remove the last remaining parent.";
                }
            }

            if ($flashError === null && $targetRole !== false) {
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
            }
        }
    }
}

$members = $isParent
    ? get_db()->query('SELECT id, name, email, role FROM users ORDER BY role, name')->fetchAll(PDO::FETCH_ASSOC)
    : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="stylesheet" href="css/app.css">
</head>
<body>
  <div class="app-shell">
    <header class="app-header">
      <img class="logo" src="img/Icon_128x128.png" alt="">
      <div>
        <h1>Settings</h1>
        <p class="subtitle"><?= $user !== null ? htmlspecialchars($user['name']) . ' · ' . ucfirst($user['role']) : 'Guest' ?></p>
      </div>
    </header>

    <main class="app-content">
      <?php if ($flashError !== null): ?>
        <p class="flash-error"><?= htmlspecialchars($flashError, ENT_QUOTES) ?></p>
      <?php endif; ?>

      <h3 class="section-heading" style="margin-top:0;">Appearance</h3>
      <div class="view-toggle" style="margin-bottom:1rem;">
        <button class="active" data-theme-choice="light">Light</button>
        <button data-theme-choice="dark">Dark</button>
      </div>

      <div class="accent-preview" id="accent-preview"></div>
      <label class="hint" for="hue-slider" style="display:block; font-weight:700; color:var(--text); font-size:0.85rem; margin-bottom:0.2rem;">
        Accent color
      </label>
      <input type="range" class="hue-slider" id="hue-slider" min="0" max="360" value="90">
      <p class="hint">Drag to pick your own color — each family member can set their own.</p>

      <h3 class="section-heading">PIN code</h3>
      <div class="field">
        <label for="current-pin">Current PIN</label>
        <input type="password" id="current-pin" inputmode="numeric" maxlength="6">
      </div>
      <div class="field">
        <label for="new-pin">New PIN</label>
        <input type="password" id="new-pin" inputmode="numeric" maxlength="6">
      </div>
      <div class="field">
        <label for="confirm-pin">Confirm new PIN</label>
        <input type="password" id="confirm-pin" inputmode="numeric" maxlength="6">
      </div>
      <button class="btn btn-secondary">Update PIN</button>

      <h3 class="section-heading">Monthly grocery budget</h3>
      <div class="field">
        <label for="budget">Budget per cycle</label>
        <input type="text" id="budget" value="R2,800">
        <p class="hint">You'll get a warning when a menu's estimated shopping cost goes over this.</p>
      </div>
      <button class="btn btn-secondary">Save budget</button>

      <h3 class="section-heading">Favorite meals</h3>
      <div class="fav-list-item">
        <img src="img/Icon_256x256.png" alt="">
        <span class="name">Beef Burgers, Pineapple &amp; Chips</span>
        <button class="fav-btn active" aria-label="Remove favorite">
          <svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-7-4.35-9.5-8.8C.8 8.4 2.4 5 5.8 5c1.9 0 3.3 1 4.2 2.4C11 6 12.4 5 14.3 5c3.4 0 5 3.4 3.3 6.7C19 16.15 12 20.5 12 20.5z"/></svg>
        </button>
      </div>
      <div class="fav-list-item">
        <img src="img/Icon_256x256.png" alt="">
        <span class="name">Homemade Pizzas</span>
        <button class="fav-btn active" aria-label="Remove favorite">
          <svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-7-4.35-9.5-8.8C.8 8.4 2.4 5 5.8 5c1.9 0 3.3 1 4.2 2.4C11 6 12.4 5 14.3 5c3.4 0 5 3.4 3.3 6.7C19 16.15 12 20.5 12 20.5z"/></svg>
        </button>
      </div>

      <?php if ($isParent): ?>
      <h3 class="section-heading">Family Members</h3>
      <?php foreach ($members as $m): ?>
        <div class="fav-list-item">
          <div class="avatar-initial"><?= htmlspecialchars(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?></div>
          <span class="name">
            <?= htmlspecialchars($m['name']) ?>
            <span class="hint"><?= htmlspecialchars($m['email']) ?> · <?= ucfirst($m['role']) ?></span>
          </span>
          <?php if ((int) $m['id'] !== (int) $user['id']): ?>
          <form method="post" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($m['name']), ENT_QUOTES) ?> from the family plan?');">
            <input type="hidden" name="action" value="remove_member">
            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <button type="submit" class="remove-btn" aria-label="Remove <?= htmlspecialchars($m['name']) ?>">✕</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <button type="button" class="btn btn-secondary" style="margin-top:0.75rem;" onclick="document.getElementById('add-member-modal').showModal()">
        + Add Family Member
      </button>

      <dialog id="add-member-modal">
        <h3>Add Family Member</h3>
        <form method="post">
          <input type="hidden" name="action" value="add_member">
          <div class="field">
            <label for="member-name">Name</label>
            <input type="text" id="member-name" name="name" required>
          </div>
          <div class="field">
            <label for="member-email">Email</label>
            <input type="email" id="member-email" name="email" required>
          </div>
          <div class="field">
            <label for="member-pin">PIN</label>
            <input type="password" id="member-pin" name="pin" inputmode="numeric" pattern="\d{4,6}" maxlength="6" required>
            <p class="hint">4–6 digits. They can change it later in Settings.</p>
          </div>
          <div class="field">
            <label>Role</label>
            <div class="role-radio-row">
              <label><input type="radio" name="role" value="child" checked> Child</label>
              <label><input type="radio" name="role" value="parent"> Parent</label>
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-member-modal').close()">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </dialog>
      <?php endif; ?>

      <?php if ($isParent): ?>
      <h3 class="section-heading">Meals &amp; Ingredients</h3>
      <a href="meals.php" class="btn btn-secondary">Manage Meals</a>
      <a href="ingredients.php" class="btn btn-secondary" style="margin-top:0.6rem;">Manage Ingredients</a>
      <?php endif; ?>

      <h3 class="section-heading">Feedback</h3>
      <a href="feedback.html" class="btn btn-secondary member-only">Suggest a new meal</a>
      <a href="feedback-review.html" class="btn btn-secondary parent-only" style="margin-top:0.6rem;">Review Suggestions</a>

      <a href="logout.php" class="btn btn-secondary" style="margin-top:2rem; border-color:var(--danger); color:var(--danger); text-align:center;">
        Log Out
      </a>
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
      <a href="settings.php" class="active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.9 7.9 0 0 0 0-2l2-1.6-2-3.4-2.4 1a8 8 0 0 0-1.7-1L14.9 3h-4l-.4 2.5a8 8 0 0 0-1.7 1l-2.4-1-2 3.4L6.4 11a7.9 7.9 0 0 0 0 2l-2 1.6 2 3.4 2.4-1a8 8 0 0 0 1.7 1l.4 2.5h4l.4-2.5a8 8 0 0 0 1.7-1l2.4 1 2-3.4z"/></svg>
        <span>Settings</span>
      </a>
    </nav>
  </div>
  <script src="js/mockup.js"></script>
  <?php if ($openAddModal): ?>
  <script>
    document.addEventListener("DOMContentLoaded", () => document.getElementById("add-member-modal").showModal());
  </script>
  <?php endif; ?>
</body>
</html>
