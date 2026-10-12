<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador'], 403);
}

$d = json_input();
$accion = $d['accion'] ?? 'check'; // 'check' | 'eliminar' | 'reasignar_eliminar'
$id_usuario = $d['id_usuario'] ?? 0;

if (!$id_usuario) json_response(['error' => 'ID requerido'], 400);

// ============================================================
// CHECK: Ver qué implica eliminar a este usuario
// ============================================================
if ($accion === 'check') {
    // Proyectos donde es responsable único
    $stmt = db()->prepare("
        SELECT id_proyecto, nombre
        FROM gestion_proyectos
        WHERE id_responsable = ? AND eliminado = FALSE AND archivado = FALSE
    ");
    $stmt->execute([$id_usuario]);
    $proyectos_como_responsable = $stmt->fetchAll();

    // Actividades asignadas
    $stmt = db()->prepare("
        SELECT a.id_actividad, a.titulo, a.estado, p.nombre AS proyecto
        FROM gestion_actividades a
        JOIN gestion_proyectos p ON p.id_proyecto = a.id_proyecto
        WHERE a.asignado_a = ?
          AND a.estado NOT IN ('completada')
          AND a.eliminada = FALSE
          AND a.archivada = FALSE
    ");
    $stmt->execute([$id_usuario]);
    $actividades_asignadas = $stmt->fetchAll();

    json_response([
        'proyectos_como_responsable' => $proyectos_como_responsable,
        'actividades_asignadas'      => $actividades_asignadas,
        'requiere_reasignacion'      => count($actividades_asignadas) > 0,
        'requiere_nuevo_responsable' => count($proyectos_como_responsable) > 0,
    ]);
}

// ============================================================
// REASIGNAR Y ELIMINAR
// ============================================================
if ($accion === 'reasignar_eliminar') {
    $reasignaciones = $d['reasignaciones'] ?? []; // [{id_actividad, id_usuario_nuevo}, ...]
    $nuevos_responsables = $d['nuevos_responsables'] ?? []; // [{id_proyecto, id_usuario_nuevo}, ...]

    // Procesar reasignaciones de actividades
    foreach ($reasignaciones as $r) {
        db()->prepare("
            UPDATE gestion_actividades SET asignado_a = ?
            WHERE id_actividad = ?
        ")->execute([$r['id_usuario_nuevo'], $r['id_actividad']]);
    }

    // Procesar nuevos responsables de proyectos
    foreach ($nuevos_responsables as $nr) {
        db()->prepare("
            UPDATE gestion_proyectos SET id_responsable = ?
            WHERE id_proyecto = ?
        ")->execute([$nr['id_usuario_nuevo'], $nr['id_proyecto']]);

        db()->prepare("
            INSERT INTO gestion_miembros_proyecto (id_proyecto, id_usuario, rol_miembro)
            VALUES (?, ?, 'responsable')
            ON CONFLICT (id_proyecto, id_usuario) DO UPDATE SET rol_miembro = 'responsable'
        ")->execute([$nr['id_proyecto'], $nr['id_usuario_nuevo']]);
    }

    // 🆕 QUITAR AL USUARIO DE TODOS LOS PROYECTOS
    db()->prepare("DELETE FROM gestion_miembros_proyecto WHERE id_usuario = ?")
        ->execute([$id_usuario]);

    // Marcar como eliminado
    db()->prepare("
        UPDATE gestion_usuarios
        SET eliminado = TRUE, eliminado_en = NOW(), activo = FALSE
        WHERE id_usuario = ?
    ")->execute([$id_usuario]);

    json_response(['ok' => true]);
}

// ============================================================
// ELIMINAR SIN PENDIENTES
// ============================================================
if ($accion === 'eliminar') {
    // 🆕 QUITAR AL USUARIO DE TODOS LOS PROYECTOS
    db()->prepare("DELETE FROM gestion_miembros_proyecto WHERE id_usuario = ?")
        ->execute([$id_usuario]);

    // Marcar como eliminado
    db()->prepare("
        UPDATE gestion_usuarios
        SET eliminado = TRUE, eliminado_en = NOW(), activo = FALSE
        WHERE id_usuario = ?
    ")->execute([$id_usuario]);

    json_response(['ok' => true]);
}

json_response(['error' => 'Acción no válida'], 400);