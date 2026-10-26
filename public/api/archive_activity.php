<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] === 'visualizador') {
    json_response(['error' => 'Sin permisos'], 403);
}

$d = json_input();
$id_actividad = $d['id_actividad'] ?? 0;
$accion = $d['accion'] ?? 'archivar';

if (!$id_actividad) json_response(['error' => 'ID requerido'], 400);

// 🔍 Obtener info de la actividad (estado, proyecto y título)
$a = db()->prepare("
    SELECT id_proyecto, titulo, estado
    FROM gestion_actividades
    WHERE id_actividad = ?
");
$a->execute([$id_actividad]);
$act = $a->fetch();

if (!$act) json_response(['error' => 'Actividad no encontrada'], 404);
if ($accion === 'archivar' && $act['estado'] !== 'completada') {
    json_response(['error' => 'Solo se pueden archivar actividades completadas'], 400);
}

$id_proyecto = $act['id_proyecto'];
$titulo_actividad = $act['titulo'];

// 🔒 Verificar que el proyecto NO esté bloqueado
bloquear_si_proyecto_bloqueado($id_proyecto);

if ($accion === 'archivar') {
    db()->prepare("
        UPDATE gestion_actividades
        SET archivada = TRUE, archivada_en = NOW(), archivada_por = ?
        WHERE id_actividad = ?
    ")->execute([$user['id_usuario'], $id_actividad]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'edicion',
        'actividad_archivada',
        'Se archivó la actividad "' . $titulo_actividad . '"',
        ['id_actividad' => $id_actividad]
    );

} else {
    db()->prepare("
        UPDATE gestion_actividades
        SET archivada = FALSE, archivada_en = NULL, archivada_por = NULL
        WHERE id_actividad = ?
    ")->execute([$id_actividad]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'edicion',
        'actividad_desarchivada',
        'Se desarchivó la actividad "' . $titulo_actividad . '"',
        ['id_actividad' => $id_actividad]
    );
}

json_response(['ok' => true]);