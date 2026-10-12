<?php
require_once __DIR__ . '/config.php';

/**
 * Conexión PDO a PostgreSQL (singleton)
 */
function db() {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            env('DB_HOST', 'localhost'),
            env('DB_PORT', '5432'),
            env('DB_NAME', 'rtp_kanban')
        );

        try {
            $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET client_encoding TO 'UTF8'");
        } catch (PDOException $e) {
            die('Error de conexión a la BD: ' . $e->getMessage());
        }
    }

    return $pdo;
}