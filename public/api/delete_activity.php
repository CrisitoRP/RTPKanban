<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador puede eliminar actividades'], 403);
}

$d = json_input();
$id_actividad = $d['id_actividad'] ?? 0;
$accion = $d['accion'] ?? 'eliminar';

if (!$id_actividad) json_response(['error' => 'ID requerido'], 400);

if ($accion === 'eliminar') {
    db()->prepare("
        UPDATE gestion_actividades
        SET eliminada = TRUE, eliminada_en = NOW(), eliminada_por = ?
        WHERE id_actividad = ?
    ")->execute([$user['id_usuario'], $id_actividad]);
} else {
    db()->prepare("
        UPDATE gestion_actividades
        SET eliminada = FALSE, eliminada_en = NULL, eliminada_por = NULL
        WHERE id_actividad = ?
    ")->execute([$id_actividad]);
}

json_response(['ok' => true]);