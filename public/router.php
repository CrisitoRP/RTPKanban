<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/' || $path === '') {
    require __DIR__ . '/index.php';
    return true;
}
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false; // Sirve el archivo tal cual
}
require __DIR__ . '/index.php';
return true;