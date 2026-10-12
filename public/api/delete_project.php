<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador puede eliminar proyectos'], 403);
}

$d = json_input();
$id_proyecto = $d['id_proyecto'] ?? 0;
$accion = $d['accion'] ?? 'eliminar'; // 'eliminar' | 'restaurar'

if (!$id_proyecto) json_response(['error' => 'ID requerido'], 400);

if ($accion === 'eliminar') {
    db()->prepare("
        UPDATE gestion_proyectos
        SET eliminado = TRUE, eliminado_en = NOW(), eliminado_por = ?
        WHERE id_proyecto = ?
    ")->execute([$user['id_usuario'], $id_proyecto]);
} else {
    db()->prepare("
        UPDATE gestion_proyectos
        SET eliminado = FALSE, eliminado_en = NULL, eliminado_por = NULL
        WHERE id_proyecto = ?
    ")->execute([$id_proyecto]);
}

json_response(['ok' => true]);