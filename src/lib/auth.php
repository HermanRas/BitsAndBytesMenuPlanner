<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function attempt_login(string $email, string $pin): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE lower(email) = lower(:email)');
    $stmt->execute([':email' => trim($email)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user === false || !password_verify($pin, $user['pin_hash'])) {
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

function require_parent(): array
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'parent') {
        header('Location: today.php');
        exit;
    }

    return $user;
}
