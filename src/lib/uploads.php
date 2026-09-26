<?php
declare(strict_types=1);

/** @return array{path: ?string, error: ?string} */
function save_meal_image(array $file): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => 'Image upload failed.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['path' => null, 'error' => 'Image must be under 5MB.'];
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($file['tmp_name']);
    if ($mime === false || !isset($allowed[$mime])) {
        return ['path' => null, 'error' => 'Image must be JPEG, PNG, or WebP.'];
    }

    $dir = __DIR__ . '/../img/meals';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $filename = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        return ['path' => null, 'error' => 'Could not save the uploaded image.'];
    }

    return ['path' => 'img/meals/' . $filename, 'error' => null];
}
