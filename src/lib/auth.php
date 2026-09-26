<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

// A pre-computed bcrypt hash of an unused value, so a login attempt against
// a non-existent email still runs password_verify() (rather than skipping
// it) and takes the same time as a real one — otherwise response time would
// leak which emails belong to real family members.
const DUMMY_PIN_HASH = '$2y$10$Nfu3OXtqp01Jbn5vSd3qc.NvtY5nxVMkNNyHIQPO/92kGjlRTkjy6';

function attempt_login(string $email, string $pin): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE lower(email) = lower(:email)');
    $stmt->execute([':email' => trim($email)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $valid = password_verify($pin, $user !== false ? $user['pin_hash'] : DUMMY_PIN_HASH);

    if ($user === false || !$valid) {
        usleep(300_000); // slow down PIN brute-forcing
        return null;
    }

    return $user;
}

function log_in_user(array $user): void
{
    ensure_session();
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int) $user['id']];
}

function log_in_guest(): void
{
    ensure_session();
    session_regenerate_id(true);
    $_SESSION = ['guest' => true];
}

function log_out(): void
{
    ensure_session();
    $_SESSION = [];
    session_destroy();
}

function current_user(): ?array
{
    ensure_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $cache = ($user === false ? null : $user);
}

function is_guest(): bool
{
    ensure_session();
    return !empty($_SESSION['guest']);
}

function current_role(): string
{
    if (is_guest()) {
        return 'guest';
    }
    $user = current_user();

    return $user['role'] ?? 'guest';
}

function require_login(): array
{
    $user = current_user();
    if ($user === null && !is_guest()) {
        header('Location: login.php');
        exit;
    }

    return $user ?? ['role' => 'guest'];
}

/** HTML attributes for the <html> tag applying a user's saved theme/accent hue app-wide. */
function theme_html_attrs(?array $user): string
{
    $theme = ($user['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
    $hue = max(0, min(360, (int) ($user['accent_hue'] ?? 90)));

    return 'data-theme="' . $theme . '" style="--accent-h: ' . $hue . ';"';
}

function require_parent(): array
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'parent') {
        header('Location: today.php');
        exit;
    }

    return $user;
}
