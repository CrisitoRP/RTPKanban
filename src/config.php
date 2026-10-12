<?php
/**
 * Lector del archivo .env
 * Uso: env('DB_HOST')
 */
function env($key, $default = null) {
    static $vars = null;

    if ($vars === null) {
        $vars = [];
        $envFile = __DIR__ . '/../.env';

        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '=') !== false && $line[0] !== '#') {
                    [$k, $v] = explode('=', $line, 2);
                    $vars[trim($k)] = trim($v, '"\'');
                }
            }
        }
    }

    return $vars[$key] ?? $default;
}