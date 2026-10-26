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

// 🔍 Obtener nombre del proyecto
$stmt = db()->prepare("SELECT nombre FROM gestion_proyectos WHERE id_proyecto = ?");
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch();

if (!$proyecto) json_response(['error' => 'Proyecto no encontrado'], 404);

if ($accion === 'eliminar') {
    // 🔒 No permitir eliminar si está bloqueado
    bloquear_si_proyecto_bloqueado($id_proyecto);

    db()->prepare("
        UPDATE gestion_proyectos
        SET eliminado = TRUE, eliminado_en = NOW(), eliminado_por = ?
        WHERE id_proyecto = ?
    ")->execute([$user['id_usuario'], $id_proyecto]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'eliminacion',
        'proyecto_eliminado',
        'Se eliminó el proyecto "' . $proyecto['nombre'] . '"',
        ['id_proyecto' => $id_proyecto]
    );

} else {
    db()->prepare("
        UPDATE gestion_proyectos
        SET eliminado = FALSE, eliminado_en = NULL, eliminado_por = NULL
        WHERE id_proyecto = ?
    ")->execute([$id_proyecto]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'creacion',
        'proyecto_restaurado',
        'Se restauró el proyecto "' . $proyecto['nombre'] . '"',
        ['id_proyecto' => $id_proyecto]
    );
}

json_response(['ok' => true]);