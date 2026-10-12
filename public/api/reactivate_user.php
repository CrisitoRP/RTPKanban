<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador'], 403);
}

$d = json_input();
$id = $d['id_usuario'] ?? 0;
if (!$id) json_response(['error' => 'ID requerido'], 400);

db()->prepare("
    UPDATE gestion_usuarios
    SET eliminado = FALSE, eliminado_en = NULL, activo = TRUE
    WHERE id_usuario = ?
")->execute([$id]);

json_response(['ok' => true]);