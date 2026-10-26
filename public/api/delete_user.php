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

// No puede eliminarse a sí mismo
if ($id_usuario == $user['id_usuario']) {
    json_response(['error' => 'No puedes eliminarte a ti mismo'], 400);
}

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
// ELIMINAR DE VERDAD (HARD DELETE)
// ============================================================
if ($accion === 'eliminar' || $accion === 'reasignar_eliminar') {

    // Si hay reasignaciones, procesarlas primero
    if ($accion === 'reasignar_eliminar') {
        $reasignaciones = $d['reasignaciones'] ?? [];
        $nuevos_responsables = $d['nuevos_responsables'] ?? [];

        foreach ($reasignaciones as $r) {
            db()->prepare("
                UPDATE gestion_actividades SET asignado_a = ?
                WHERE id_actividad = ?
            ")->execute([$r['id_usuario_nuevo'], $r['id_actividad']]);
        }

        foreach ($nuevos_responsables as $nr) {
            db()->prepare("
                UPDATE gestion_proyectos SET id_responsable = ?
                WHERE id_proyecto = ?
            ")->execute([$nr['id_usuario_nuevo'], $nr['id_proyecto']]);

            db()->prepare("
                INSERT INTO gestion_miembros_proyecto (id_proyecto, id_usuario, rol_miembro)
                VALUES (?, ?, 'responsable')
                ON CONFLICT (id_proyecto, id_usuario)
                DO UPDATE SET rol_miembro = 'responsable'
            ")->execute([$nr['id_proyecto'], $nr['id_usuario_nuevo']]);
        }
    }

    // ============================================================
    // LIMPIEZA EN CASCADA + BORRADO REAL
    // ============================================================
    db()->beginTransaction();

    try {
        // 1. Reasignar actividades al admin actual
        db()->prepare("UPDATE gestion_actividades SET asignado_a = ? WHERE asignado_a = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 2. Reasignar proyectos donde era responsable
        db()->prepare("UPDATE gestion_proyectos SET id_responsable = ? WHERE id_responsable = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 3. Reasignar creador de proyectos
        db()->prepare("UPDATE gestion_proyectos SET creado_por = ? WHERE creado_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 4. Reasignar creador de actividades
        db()->prepare("UPDATE gestion_actividades SET creado_por = ? WHERE creado_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 5. Reasignar bloqueos/desbloqueos/aprobaciones/archivados
        db()->prepare("UPDATE gestion_actividades SET bloqueado_por = ? WHERE bloqueado_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);
        db()->prepare("UPDATE gestion_actividades SET desbloqueado_por = ? WHERE desbloqueado_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);
        db()->prepare("UPDATE gestion_actividades SET enviada_revision_por = ? WHERE enviada_revision_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);
        db()->prepare("UPDATE gestion_actividades SET aprobada_por = ? WHERE aprobada_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);
        db()->prepare("UPDATE gestion_actividades SET archivada_por = ? WHERE archivada_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);
        db()->prepare("UPDATE gestion_actividades SET eliminada_por = ? WHERE eliminada_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 6. Reasignar archivado/eliminado de proyectos
        db()->prepare("UPDATE gestion_proyectos SET archivado_por = ? WHERE archivado_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);
        db()->prepare("UPDATE gestion_proyectos SET eliminado_por = ? WHERE eliminado_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 7. Reasignar adjuntos subidos
        db()->prepare("UPDATE gestion_adjuntos_actividad SET subido_por = ? WHERE subido_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 8. Reasignar historial
        db()->prepare("UPDATE gestion_historial_actividad SET id_usuario = ? WHERE id_usuario = ?")
            ->execute([$user['id_usuario'], $id_usuario]);
        db()->prepare("UPDATE gestion_historial_bloqueos_actividad SET realizado_por = ? WHERE realizado_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);
        db()->prepare("UPDATE gestion_historial_estado_proyecto SET id_usuario = ? WHERE id_usuario = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 9. Reasignar invitaciones creadas
        db()->prepare("UPDATE gestion_invitaciones SET creado_por = ? WHERE creado_por = ?")
            ->execute([$user['id_usuario'], $id_usuario]);

        // 10. BORRAR todo lo que depende exclusivamente del usuario
        db()->prepare("DELETE FROM gestion_miembros_proyecto WHERE id_usuario = ?")
            ->execute([$id_usuario]);

        db()->prepare("DELETE FROM gestion_comentarios_actividad WHERE id_usuario = ?")
            ->execute([$id_usuario]);

        db()->prepare("DELETE FROM gestion_notificaciones WHERE id_usuario = ?")
            ->execute([$id_usuario]);

        db()->prepare("DELETE FROM gestion_eventos_calendario WHERE id_usuario = ?")
            ->execute([$id_usuario]);

        // 11. BORRAR AL USUARIO DE VERDAD
        db()->prepare("DELETE FROM gestion_usuarios WHERE id_usuario = ?")
            ->execute([$id_usuario]);

        db()->commit();
        json_response(['ok' => true]);

    } catch (Exception $e) {
        db()->rollBack();
        json_response(['error' => 'Error eliminando usuario: ' . $e->getMessage()], 500);
    }
}

json_response(['error' => 'Acción no válida'], 400);