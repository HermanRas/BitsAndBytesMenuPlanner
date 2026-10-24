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
$pdo = get_db();
$flashError = null;
$flashSuccess = null;
$openAddModal = false;

// Settings are grouped into an accordion; the group a form belongs to stays
// open after its POST (or redirect) so the result is visible, not collapsed.
$settingsGroups = ['appearance', 'security', 'favorites', 'budget', 'family', 'kitchen', 'feedback'];
$openGroup = in_array($_GET['open'] ?? '', $settingsGroups, true) ? $_GET['open'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'set_theme') {
    $theme = ($_POST['theme'] ?? '') === 'dark' ? 'dark' : 'light';
    $pdo->prepare('UPDATE users SET theme = :t WHERE id = :id')->execute([':t' => $theme, ':id' => $user['id']]);
    header('Location: settings.php?open=appearance');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'set_hue') {
    $hue = max(0, min(360, (int) ($_POST['hue'] ?? 90)));
    $pdo->prepare('UPDATE users SET accent_hue = :h WHERE id = :id')->execute([':h' => $hue, ':id' => $user['id']]);
    header('Location: settings.php?open=appearance');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'update_pin') {
    $current = trim($_POST['current_pin'] ?? '');
    $new = trim($_POST['new_pin'] ?? '');
    $confirm = trim($_POST['confirm_pin'] ?? '');
    $openGroup = 'security';

    if (!password_verify($current, $user['pin_hash'])) {
        $flashError = 'Current PIN is incorrect.';
    } elseif (!preg_match('/^\d{4,6}$/', $new)) {
        $flashError = 'New PIN must be 4–6 digits.';
    } elseif ($new !== $confirm) {
        $flashError = "New PIN and confirmation don't match.";
    } else {
        $pdo->prepare('UPDATE users SET pin_hash = :h WHERE id = :id')
            ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);
        $flashSuccess = 'PIN updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isParent && ($_POST['action'] ?? '') === 'update_budget') {
    $budget = $_POST['budget'] ?? '';
    $openGroup = 'budget';

    if (!is_numeric($budget) || (float) $budget < 0) {
        $flashError = 'Enter a valid budget amount.';
    } else {
        $pdo->prepare('UPDATE budget_settings SET monthly_budget = :b WHERE id = 1')->execute([':b' => (float) $budget]);
        $flashSuccess = 'Budget updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isParent) {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['add_member', 'remove_member'], true)) {
        $openGroup = 'family';
    }

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null && ($_POST['action'] ?? '') === 'remove_favorite') {
    get_db()->prepare('DELETE FROM favorites WHERE user_id = :uid AND meal_id = :mid')
        ->execute([':uid' => $user['id'], ':mid' => (int) ($_POST['meal_id'] ?? 0)]);
    header('Location: settings.php?open=favorites');
    exit;
}

$members = $isParent
    ? get_db()->query('SELECT id, name, email, role FROM users ORDER BY role, name')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$currentBudget = $isParent ? (float) $pdo->query('SELECT monthly_budget FROM budget_settings WHERE id = 1')->fetchColumn() : null;

$favorites = $user !== null
    ? get_db()->prepare('SELECT m.id, m.title, m.image FROM favorites f JOIN meals m ON m.id = f.meal_id WHERE f.user_id = :uid ORDER BY m.title')
    : null;
if ($favorites !== null) {
    $favorites->execute([':uid' => $user['id']]);
    $favorites = $favorites->fetchAll(PDO::FETCH_ASSOC);
} else {
    $favorites = [];
}

$pendingSuggestions = $isParent
    ? (int) $pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'pending'")->fetchColumn()
    : 0;

/** Opening tag for one accordion group; groups share a name so only one is open at a time. */
function settings_group_open(string $id, ?string $openGroup): string
{
    return '<details class="settings-group" id="group-' . $id . '" name="settings"' . ($openGroup === $id ? ' open' : '') . '>';
}

/** The always-visible row of an accordion group: icon, title, a one-line summary of its current state. */
function settings_group_summary(string $iconPath, string $title, string $summary): string
{
    return '<summary>'
        . '<span class="group-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $iconPath . '</svg></span>'
        . '<span class="group-text"><span class="group-title">' . htmlspecialchars($title) . '</span>'
        . '<span class="group-summary">' . htmlspecialchars($summary) . '</span></span>'
        . '<svg class="group-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>'
        . '</summary>';
}
?>
<!doctype html>
<html lang="en" <?= theme_html_attrs($user) ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="manifest" href="manifest.json">
  <link rel="apple-touch-icon" href="img/Icon_192x192.png">
  <meta name="theme-color" content="#1A1A1A">
  <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
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
      <?php if ($flashSuccess !== null): ?>
        <p class="hint" style="color:var(--accent-strong); font-weight:700; margin-bottom:1rem;"><?= htmlspecialchars($flashSuccess, ENT_QUOTES) ?></p>
      <?php endif; ?>

      <?php if ($user !== null): ?>
      <div class="settings-groups">
        <?= settings_group_open('appearance', $openGroup) ?>
          <?= settings_group_summary('<circle cx="13.5" cy="6.5" r="1.5"/><circle cx="17.5" cy="10.5" r="1.5"/><circle cx="8.5" cy="7.5" r="1.5"/><circle cx="6.5" cy="12.5" r="1.5"/><path d="M12 2a10 10 0 0 0 0 20c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.4A5.6 5.6 0 0 0 22 9.8C22 5.5 17.5 2 12 2z"/>', 'Appearance', ucfirst($user['theme']) . ' theme · your own accent color') ?>
          <div class="group-body">
            <div class="view-toggle" style="margin-bottom:1rem;">
              <form method="post" style="display:contents;">
                <input type="hidden" name="action" value="set_theme">
                <input type="hidden" name="theme" value="light">
                <button type="submit" class="<?= $user['theme'] === 'light' ? 'active' : '' ?>">Light</button>
              </form>
              <form method="post" style="display:contents;">
                <input type="hidden" name="action" value="set_theme">
                <input type="hidden" name="theme" value="dark">
                <button type="submit" class="<?= $user['theme'] === 'dark' ? 'active' : '' ?>">Dark</button>
              </form>
            </div>

            <div class="accent-preview" id="accent-preview"></div>
            <label class="hint" for="hue-slider" style="display:block; font-weight:700; color:var(--text); font-size:0.85rem; margin-bottom:0.2rem;">
              Accent color
            </label>
            <input type="range" class="hue-slider" id="hue-slider" min="0" max="360" value="<?= (int) $user['accent_hue'] ?>">
            <form method="post" id="hue-form">
              <input type="hidden" name="action" value="set_hue">
              <input type="hidden" name="hue" id="hue-hidden-input">
            </form>
            <p class="hint">Drag to pick your own color — each family member can set their own.</p>
          </div>
        </details>

        <?= settings_group_open('security', $openGroup) ?>
          <?= settings_group_summary('<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>', 'PIN code', 'Change the PIN you log in with') ?>
          <div class="group-body">
            <form method="post">
              <input type="hidden" name="action" value="update_pin">
              <div class="field">
                <label for="current-pin">Current PIN</label>
                <input type="password" id="current-pin" name="current_pin" inputmode="numeric" maxlength="6" required>
              </div>
              <div class="field">
                <label for="new-pin">New PIN</label>
                <input type="password" id="new-pin" name="new_pin" inputmode="numeric" pattern="\d{4,6}" maxlength="6" required>
              </div>
              <div class="field">
                <label for="confirm-pin">Confirm new PIN</label>
                <input type="password" id="confirm-pin" name="confirm_pin" inputmode="numeric" maxlength="6" required>
              </div>
              <button type="submit" class="btn btn-secondary">Update PIN</button>
            </form>
          </div>
        </details>

        <?= settings_group_open('favorites', $openGroup) ?>
          <?= settings_group_summary('<path d="M12 20.5s-7-4.35-9.5-8.8C.8 8.4 2.4 5 5.8 5c1.9 0 3.3 1 4.2 2.4C11 6 12.4 5 14.3 5c3.4 0 5 3.4 3.3 6.7C19 16.15 12 20.5 12 20.5z"/>', 'Favorite meals', empty($favorites) ? 'None yet' : count($favorites) . (count($favorites) === 1 ? ' meal' : ' meals')) ?>
          <div class="group-body">
            <?php if (empty($favorites)): ?>
              <p class="hint" style="margin:0;">No favorites yet — tap the heart on any meal to save it here.</p>
            <?php endif; ?>
            <?php foreach ($favorites as $fav): ?>
              <div class="fav-list-item">
                <a href="meal-detail.php?meal=<?= (int) $fav['id'] ?>" style="display:contents;">
                  <img src="<?= htmlspecialchars($fav['image'] ?: 'img/Icon_256x256.png') ?>" alt="">
                  <span class="name" style="color:var(--text);"><?= htmlspecialchars($fav['title']) ?></span>
                </a>
                <form method="post">
                  <input type="hidden" name="action" value="remove_favorite">
                  <input type="hidden" name="meal_id" value="<?= (int) $fav['id'] ?>">
                  <button type="submit" class="fav-btn active" aria-label="Remove favorite">
                    <svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5s-7-4.35-9.5-8.8C.8 8.4 2.4 5 5.8 5c1.9 0 3.3 1 4.2 2.4C11 6 12.4 5 14.3 5c3.4 0 5 3.4 3.3 6.7C19 16.15 12 20.5 12 20.5z"/></svg>
                  </button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </details>

        <?php if ($isParent): ?>
        <?= settings_group_open('budget', $openGroup) ?>
          <?= settings_group_summary('<rect x="2" y="6" width="20" height="13" rx="2"/><path d="M16 12.5h2M2 10h20"/>', 'Grocery budget', 'R' . number_format($currentBudget, 2) . ' per cycle') ?>
          <div class="group-body">
            <form method="post">
              <input type="hidden" name="action" value="update_budget">
              <div class="field">
                <label for="budget">Budget per cycle</label>
                <input type="number" id="budget" name="budget" min="0" step="0.01" value="<?= htmlspecialchars((string) $currentBudget) ?>" required>
                <p class="hint">You'll get a warning when a menu's estimated shopping cost goes over this.</p>
              </div>
              <button type="submit" class="btn btn-secondary">Save budget</button>
            </form>
          </div>
        </details>

        <?= settings_group_open('family', $openGroup) ?>
          <?= settings_group_summary('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>', 'Family members', count($members) . (count($members) === 1 ? ' member' : ' members')) ?>
          <div class="group-body">
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
          </div>
        </details>

        <?= settings_group_open('kitchen', $openGroup) ?>
          <?= settings_group_summary('<path d="M4 3v8a2 2 0 0 0 2 2h1v8M8 3v6M6 3v6"/><path d="M17 21V3c-2.2 1.3-3.5 3.7-3.5 6.5V14H17"/>', 'Meals & menu', 'Meals, ingredients, household items, cycles') ?>
          <div class="group-body link-list">
            <a href="meals.php">Manage Meals</a>
            <a href="ingredients.php">Manage Ingredients</a>
            <a href="household.php">Manage Household Items</a>
            <a href="copy-cycle.php">Copy a Previous Cycle</a>
          </div>
        </details>
        <?php endif; ?>

        <?= settings_group_open('feedback', $openGroup) ?>
          <?= settings_group_summary('<path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z"/>', 'Meal suggestions', $pendingSuggestions > 0 ? $pendingSuggestions . ' waiting for review' : 'Suggest a new meal for the menu') ?>
          <div class="group-body link-list">
            <a href="feedback.php">Suggest a new meal</a>
            <?php if ($isParent): ?>
            <a href="feedback-review.php">Review Suggestions<?php if ($pendingSuggestions > 0): ?> <span class="count-pill"><?= $pendingSuggestions ?></span><?php endif; ?></a>
            <?php endif; ?>
          </div>
        </details>
      </div>
      <?php endif; ?>

      <div class="settings-footer">
        <button type="button" id="install-app-btn" class="btn btn-secondary" style="display:none;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v13m0 0-4-4m4 4 4-4"/><path d="M4 17v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
          Install App
        </button>
        <a href="logout.php" class="btn btn-secondary" style="border-color:var(--danger); color:var(--danger); text-align:center;">
          Log Out
        </a>
      </div>

      <?php if ($isParent): ?>
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
      <a href="shopping-list.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.4a2 2 0 0 0 2-1.6L21 7H6"/></svg>
        <span>Shopping</span>
      </a>
      <a href="settings.php" class="active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.9 7.9 0 0 0 0-2l2-1.6-2-3.4-2.4 1a8 8 0 0 0-1.7-1L14.9 3h-4l-.4 2.5a8 8 0 0 0-1.7 1l-2.4-1-2 3.4L6.4 11a7.9 7.9 0 0 0 0 2l-2 1.6 2 3.4 2.4-1a8 8 0 0 0 1.7 1l.4 2.5h4l.4-2.5a8 8 0 0 0 1.7-1l2.4 1 2-3.4z"/></svg>
        <span>Settings</span>
      </a>
    </nav>
  </div>
  <script src="<?= asset_url('js/mockup.js') ?>"></script>
  <script>
    const installBtn = document.getElementById("install-app-btn");
    let deferredInstallPrompt = null;
    window.addEventListener("beforeinstallprompt", (e) => {
      e.preventDefault();
      deferredInstallPrompt = e;
      installBtn.style.display = "";
    });
    installBtn.addEventListener("click", async () => {
      if (!deferredInstallPrompt) return;
      deferredInstallPrompt.prompt();
      await deferredInstallPrompt.userChoice;
      deferredInstallPrompt = null;
      installBtn.style.display = "none";
    });
    window.addEventListener("appinstalled", () => {
      installBtn.style.display = "none";
    });
  </script>
  <?php if ($user !== null): ?>
  <script>
    const hueSlider = document.getElementById("hue-slider");
    hueSlider.addEventListener("input", () => {
      document.documentElement.style.setProperty("--accent-h", hueSlider.value);
    });
    hueSlider.addEventListener("change", () => {
      document.getElementById("hue-hidden-input").value = hueSlider.value;
      document.getElementById("hue-form").submit();
    });
  </script>
  <?php endif; ?>
  <?php if ($openAddModal): ?>
  <script>
    document.addEventListener("DOMContentLoaded", () => document.getElementById("add-member-modal").showModal());
  </script>
  <?php endif; ?>
</body>
</html>
