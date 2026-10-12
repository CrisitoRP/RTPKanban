<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || !puede_gestionar($user, 'proyecto')) {
    json_response(['error' => 'Sin permisos'], 403);
}

$d = json_input();
$id_proyecto = $d['id_proyecto'] ?? 0;
$accion = $d['accion'] ?? 'archivar'; // 'archivar' | 'restaurar'

if (!$id_proyecto) json_response(['error' => 'ID requerido'], 400);

if ($accion === 'archivar') {
    db()->prepare("
        UPDATE gestion_proyectos
        SET archivado = TRUE, archivado_en = NOW(), archivado_por = ?
        WHERE id_proyecto = ?
    ")->execute([$user['id_usuario'], $id_proyecto]);
} else {
    db()->prepare("
        UPDATE gestion_proyectos
        SET archivado = FALSE, archivado_en = NULL, archivado_por = NULL
        WHERE id_proyecto = ?
    ")->execute([$id_proyecto]);
}

json_response(['ok' => true]);