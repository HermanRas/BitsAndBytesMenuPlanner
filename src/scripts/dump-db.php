<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../lib/db.php';

$pdo = get_db();

$tables = ['users', 'ingredients', 'meals', 'meal_ingredients', 'menu_cycles', 'menu_entries', 'budget_settings', 'favorites', 'feedback'];

foreach ($tables as $table) {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    echo "=== $table ($count rows) ===\n";

    $rows = $pdo->query("SELECT * FROM $table LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
    if ($count > 5) {
        echo "... (" . ($count - 5) . " more)\n";
    }
    echo "\n";
}
