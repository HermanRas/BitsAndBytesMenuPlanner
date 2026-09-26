<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';

function bridge_redirect(string $role): never
{
    ?>
<!doctype html>
<html><head><meta charset="utf-8"></head><body>
<script>
  localStorage.setItem("bnb-mock-role", <?= json_encode($role) ?>);
  location.href = "today.php";
</script>
</body></html>
    <?php
    exit;
}

if (current_user() !== null || is_guest()) {
    bridge_redirect(current_role());
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'guest') {
        log_in_guest();
        bridge_redirect('guest');
    }

    $user = attempt_login($_POST['email'] ?? '', $_POST['pin'] ?? '');
    if ($user !== null) {
        log_in_user($user);
        bridge_redirect($user['role']);
    }

    $error = "That email and PIN don't match any family member.";
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log in — BitsAndBytesMenuPlanner</title>
  <link rel="icon" href="img/Icon_32x32.png">
  <link rel="stylesheet" href="css/app.css">
</head>
<body class="login-screen">
  <div class="app-shell">
    <main class="app-content">
      <div class="brand-block">
        <img src="img/Icon_128x128.png" alt="">
        <h1>BitsAndBytesMenuPlanner</h1>
        <p class="subtitle">This month's menu, ready when you are.</p>
      </div>

      <?php if ($error !== null): ?>
        <p class="hint" style="color:var(--danger); text-align:center; margin-bottom:1rem;">
          <?= htmlspecialchars($error, ENT_QUOTES) ?>
        </p>
      <?php endif; ?>

      <form method="post">
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" placeholder="you@family.com" required>
        </div>
        <div class="field">
          <label for="pin">PIN</label>
          <input type="password" id="pin" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="••••" required>
          <p class="hint">4–6 digit PIN, set in Settings.</p>
        </div>
        <button type="submit" name="action" value="login" class="btn btn-primary">Log In</button>

        <div class="divider">or</div>

        <button type="submit" name="action" value="guest" formnovalidate class="btn btn-secondary">Continue as Guest</button>
      </form>
      <p class="hint" style="text-align:center; margin-top:0.75rem;">
        Guests can view the menu and recipes, but can't favorite, mark meals
        cooked, or make changes.
      </p>
    </main>
  </div>
</body>
</html>
