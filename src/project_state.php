<?php
/**
 * Lógica central de cambio de etapa de proyecto.
 * Sincroniza columna_manual + estado + bloqueado + historial en un solo lugar.
 */
require_once __DIR__ . '/auth.php';

function set_project_stage(int $id_proyecto, string $columna_manual, ?int $id_usuario = null, array $opciones = []): array
{
    $permitidos = ['sin_iniciar', 'en_curso', 'en_revision', 'finalizado', 'programado', 'bloqueado'];
    if (!in_array($columna_manual, $permitidos, true)) {
        return ['error' => 'Etapa inválida'];
    }

    $mapa_estado = [
        'sin_iniciar' => 'activo',
        'en_curso'    => 'activo',
        'en_revision' => 'en_riesgo',
        'finalizado'  => 'finalizado',
        'programado'  => 'activo',
        'bloqueado'   => 'detenido',
    ];

    $labels = [
        'sin_iniciar' => 'Sin iniciar',
        'en_curso'    => 'En curso',
        'en_revision' => 'En revisión',
        'finalizado'  => 'Finalizado',
        'programado'  => 'Programado',
        'bloqueado'   => 'Bloqueado',
    ];

    $stmt = db()->prepare("
        SELECT estado, porcentaje_avance
        FROM gestion_proyectos
        WHERE id_proyecto = ? AND eliminado = FALSE
    ");
    $stmt->execute([$id_proyecto]);
    $prev = $stmt->fetch();
    if (!$prev) return ['error' => 'Proyecto no encontrado'];

    $estado_anterior = $prev['estado'];
    $avance_anterior = $prev['porcentaje_avance'] ?? 0;
    $nuevo_estado    = $mapa_estado[$columna_manual];
    $label           = $labels[$columna_manual];

    $esBloqueado  = ($columna_manual === 'bloqueado');
    $esProgramado = ($columna_manual === 'programado');

    db()->prepare("
        UPDATE gestion_proyectos
        SET columna_manual = ?,
            estado = ?,
            bloqueado = ?,
            actualizado_en = NOW()
        WHERE id_proyecto = ?
    ")->execute([$columna_manual, $nuevo_estado, $esBloqueado ? 'true' : 'false', $id_proyecto]);

    if ($esProgramado && !empty($opciones['fecha_apertura'])) {
        db()->prepare("
            UPDATE gestion_proyectos
            SET fecha_apertura = ?, abierto = FALSE, actualizado_en = NOW()
            WHERE id_proyecto = ?
        ")->execute([$opciones['fecha_apertura'], $id_proyecto]);
    } elseif (!$esProgramado && !$esBloqueado) {
        db()->prepare("
            UPDATE gestion_proyectos
            SET fecha_apertura = NULL, abierto = TRUE, actualizado_en = NOW()
            WHERE id_proyecto = ? AND fecha_apertura IS NOT NULL
        ")->execute([$id_proyecto]);
    }

    $metadata = json_encode([
        'campo' => 'columna_manual',
        'valor_nuevo' => $columna_manual,
        'estado_anterior' => $estado_anterior,
        'estado_nuevo'    => $nuevo_estado,
    ], JSON_UNESCAPED_UNICODE);

    try {
        db()->prepare("
            INSERT INTO gestion_historial_proyecto
                (id_proyecto, id_usuario, tipo, accion, descripcion, metadata, creado_en)
            VALUES (?, ?, 'edicion', ?, ?, ?::jsonb, NOW())
        ")->execute([
            $id_proyecto, $id_usuario,
            'Estado del proyecto actualizado',
            'Cambió el estado del proyecto a: ' . $label,
            $metadata,
        ]);
    } catch (Throwable $e) {
        error_log('set_project_stage historial_proyecto: ' . $e->getMessage());
    }

    if ($estado_anterior !== $nuevo_estado) {
        try {
            db()->prepare("
                INSERT INTO gestion_historial_estado_proyecto
                    (id_proyecto, id_usuario, estado_anterior, estado_nuevo,
                     avance_anterior, avance_nuevo, comentario, creado_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $id_proyecto, $id_usuario,
                $estado_anterior, $nuevo_estado,
                $avance_anterior, $avance_anterior,
                'Cambio manual de etapa a: ' . $label,
            ]);
        } catch (Throwable $e) {
            error_log('set_project_stage historial_estado: ' . $e->getMessage());
        }
    }

    return ['ok' => true, 'label' => $label, 'estado' => $nuevo_estado];
}

/**
 * Cambia el estado de una actividad y registra historial.
 * Solo admin puede mover a 'bloqueado'. Responsable no puede tocar bloqueadas.
 */
function set_activity_status(int $id_actividad, string $nuevo_estado, array $user, array $opciones = []): array
{
    // ✅ FIX: se agregó 'programado' a la lista de estados permitidos
    $permitidos = ['por_hacer', 'en_curso', 'en_revision', 'programado', 'completada', 'bloqueado'];
    if (!in_array($nuevo_estado, $permitidos, true)) {
        return ['error' => 'Estado inválido'];
    }

    $stmt = db()->prepare("
        SELECT a.id_actividad, a.estado AS estado_anterior, a.id_proyecto, a.motivo_bloqueo,
               p.bloqueado AS proyecto_bloqueado, p.archivado AS proyecto_archivado,
               p.fecha_apertura AS proyecto_fecha_apertura
        FROM gestion_actividades a
        JOIN gestion_proyectos p ON p.id_proyecto = a.id_proyecto
        WHERE a.id_actividad = ? AND a.eliminada = FALSE AND p.eliminado = FALSE
    ");
    $stmt->execute([$id_actividad]);
    $act = $stmt->fetch();

    if (!$act) return ['error' => 'Actividad no encontrada'];
    if (!can_access_project($user, $act['id_proyecto'])) return ['error' => 'Sin acceso al proyecto'];
    if (!empty($act['proyecto_archivado'])) return ['error' => 'El proyecto está archivado'];
    if (!empty($act['proyecto_bloqueado']) && $nuevo_estado !== 'bloqueado') return ['error' => 'El proyecto está bloqueado'];
    if (!empty($act['proyecto_fecha_apertura']) && strtotime($act['proyecto_fecha_apertura']) > time()) {
        return ['error' => 'El proyecto aún no se ha abierto'];
    }

    $esAdmin = ($user['rol'] === 'administrador');
    $esResponsable = ($user['rol'] === 'responsable');

    if (!$esAdmin && !$esResponsable) return ['error' => 'Sin permisos'];
    if ($nuevo_estado === 'bloqueado' && !$esAdmin) return ['error' => 'Solo el administrador puede bloquear actividades'];
    if ($act['estado_anterior'] === 'bloqueado' && !$esAdmin) return ['error' => 'Solo el administrador puede mover una actividad bloqueada'];

    if ($nuevo_estado === 'bloqueado') {
        $motivo = $opciones['motivo'] ?? 'Sin motivo especificado';
        db()->prepare("
            UPDATE gestion_actividades
            SET estado_antes_bloqueo = estado,
                estado = 'bloqueado',
                bloqueado_en = NOW(),
                bloqueado_por = ?,
                motivo_bloqueo = ?,
                actualizado_en = NOW()
            WHERE id_actividad = ?
        ")->execute([$user['id_usuario'], $motivo, $id_actividad]);
    } elseif ($act['estado_anterior'] === 'bloqueado') {
        db()->prepare("
            UPDATE gestion_actividades
            SET estado = ?,
                estado_antes_bloqueo = NULL,
                desbloqueado_en = NOW(),
                desbloqueado_por = ?,
                motivo_bloqueo = NULL,
                bloqueado_en = NULL,
                bloqueado_por = NULL,
                actualizado_en = NOW()
            WHERE id_actividad = ?
        ")->execute([$nuevo_estado, $user['id_usuario'], $id_actividad]);
    } else {
        db()->prepare("
            UPDATE gestion_actividades
            SET estado = ?, actualizado_en = NOW()
            WHERE id_actividad = ?
        ")->execute([$nuevo_estado, $id_actividad]);
    }

    try {
        db()->prepare("
            INSERT INTO gestion_historial_actividad
                (id_actividad, id_usuario, accion, valor_anterior, valor_nuevo, creado_en)
            VALUES (?, ?, 'cambio_estado', ?, ?, NOW())
        ")->execute([$id_actividad, $user['id_usuario'], $act['estado_anterior'], $nuevo_estado]);
    } catch (Throwable $e) {
        error_log('set_activity_status historial: ' . $e->getMessage());
    }

    return ['ok' => true, 'estado' => $nuevo_estado];
}