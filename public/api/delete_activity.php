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

// 🔍 Obtener info de la actividad (para saber el proyecto y el título)
$stmt = db()->prepare("
    SELECT id_proyecto, titulo
    FROM gestion_actividades
    WHERE id_actividad = ?
");
$stmt->execute([$id_actividad]);
$actividad = $stmt->fetch();

if (!$actividad) {
    json_response(['error' => 'Actividad no encontrada'], 404);
}

$id_proyecto = $actividad['id_proyecto'];
$titulo_actividad = $actividad['titulo'];

if ($accion === 'eliminar') {
    // 🔒 Verificar que el proyecto NO esté bloqueado
    bloquear_si_proyecto_bloqueado($id_proyecto);

    db()->prepare("
        UPDATE gestion_actividades
        SET eliminada = TRUE, eliminada_en = NOW(), eliminada_por = ?
        WHERE id_actividad = ?
    ")->execute([$user['id_usuario'], $id_actividad]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'eliminacion',
        'actividad_eliminada',
        'Se eliminó la actividad "' . $titulo_actividad . '"',
        ['id_actividad' => $id_actividad]
    );

} else {
    // Restaurar
    bloquear_si_proyecto_bloqueado($id_proyecto);

    db()->prepare("
        UPDATE gestion_actividades
        SET eliminada = FALSE, eliminada_en = NULL, eliminada_por = NULL
        WHERE id_actividad = ?
    ")->execute([$id_actividad]);

    // 📝 Registrar en el historial
    registrar_historial(
        $id_proyecto,
        'creacion',
        'actividad_restaurada',
        'Se restauró la actividad "' . $titulo_actividad . '"',
        ['id_actividad' => $id_actividad]
    );
}

json_response(['ok' => true]);