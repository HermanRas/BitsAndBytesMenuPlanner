<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../lib/db.php';

$isNew = !file_exists(db_path());
$pdo = get_db();

if ($isNew) {
    echo "Created " . db_path() . " and applied schema.\n";
} else {
    apply_schema($pdo);
    echo db_path() . " already existed; re-applied schema (CREATE TABLE IF NOT EXISTS is a no-op on existing tables).\n";
}
