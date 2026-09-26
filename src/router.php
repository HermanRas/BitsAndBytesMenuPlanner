<?php
declare(strict_types=1);

// Front controller for PHP's built-in server (php -S ... router.php).
// The document root is the whole src/ tree, which otherwise serves every
// file as static content — including lib/ (PHP source), scripts/ (CLI-only
// maintenance scripts), data/ (the live SQLite database!), and schema.sql.
// Block those before the built-in server gets a chance to serve them as-is.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/(lib|scripts|data)(/|$)#', $path) || preg_match('#\.(sql|sqlite)$#i', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}

return false; // let the built-in server handle everything else as usual
