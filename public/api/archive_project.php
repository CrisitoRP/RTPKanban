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

// 🔍 Obtener nombre del proyecto
$stmt = db()->prepare("SELECT nombre FROM gestion_proyectos WHERE id_proyecto = ?");
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch();

if (!$proyecto) json_response(['error' => 'Proyecto no encontrado'], 404);

if ($accion === 'archivar') {
    // 🔒 No permitir archivar si está bloqueado
    bloquear_si_proyecto_bloqueado($id_proyecto);

    db()->prepare("
        UPDATE gestion_proyectos
        SET archivado = TRUE, archivado_en = NOW(), archivado_por = ?
        WHERE id_proyecto = ?
    ")->execute([$user['id_usuario'], $id_proyecto]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'edicion',
        'proyecto_archivado',
        'Se archivó el proyecto "' . $proyecto['nombre'] . '"',
        ['id_proyecto' => $id_proyecto]
    );

} else {
    db()->prepare("
        UPDATE gestion_proyectos
        SET archivado = FALSE, archivado_en = NULL, archivado_por = NULL
        WHERE id_proyecto = ?
    ")->execute([$id_proyecto]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'edicion',
        'proyecto_restaurado',
        'Se restauró el proyecto "' . $proyecto['nombre'] . '"',
        ['id_proyecto' => $id_proyecto]
    );
}

json_response(['ok' => true]);