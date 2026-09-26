<?php
declare(strict_types=1);

function db_path(): string
{
    return __DIR__ . '/../data/family.sqlite';
}

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $isNew = !file_exists(db_path());

    $pdo = new PDO('sqlite:' . db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    if ($isNew) {
        apply_schema($pdo);
    }

    return $pdo;
}

function apply_schema(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($sql);
}
