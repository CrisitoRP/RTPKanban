<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);

// Solo administrador global puede bloquear/desbloquear proyectos
if ($user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador puede bloquear o desbloquear proyectos'], 403);
}

$d = json_input();
$id_proyecto = (int) ($d['id_proyecto'] ?? 0);
$accion      = $d['accion'] ?? '';
$motivo      = trim($d['motivo'] ?? '');
$nueva_fecha = $d['nueva_fecha'] ?? null;

if (!$id_proyecto || !in_array($accion, ['bloquear', 'desbloquear'])) {
    json_response(['error' => 'Datos inválidos'], 400);
}

// Verificar que el proyecto existe
$stmt = db()->prepare("SELECT id_proyecto, nombre, fecha_fin, bloqueado FROM gestion_proyectos WHERE id_proyecto = ?");
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch();

if (!$proyecto) {
    json_response(['error' => 'Proyecto no encontrado'], 404);
}

// ============================================================
// ACCIÓN: BLOQUEAR
// ============================================================
if ($accion === 'bloquear') {
    if (!empty($proyecto['bloqueado'])) {
        json_response(['error' => 'El proyecto ya está bloqueado'], 400);
    }

    if ($motivo === '') {
        json_response(['error' => 'El motivo del bloqueo es obligatorio'], 400);
    }

    try {
        db()->prepare("
            UPDATE gestion_proyectos
            SET bloqueado = TRUE,
                motivo_bloqueo = ?,
                bloqueado_en = NOW(),
                bloqueado_por = ?,
                actualizado_en = NOW()
            WHERE id_proyecto = ?
        ")->execute([$motivo, $user['id_usuario'], $id_proyecto]);

        registrar_historial(
            $id_proyecto,
            'bloqueo',
            'proyecto_bloqueado',
            'Se bloqueó el proyecto. Motivo: ' . $motivo,
            ['motivo' => $motivo]
        );

        json_response([
            'ok' => true,
            'mensaje' => 'Proyecto bloqueado correctamente'
        ]);

    } catch (Exception $e) {
        json_response(['error' => 'Error bloqueando proyecto: ' . $e->getMessage()], 500);
    }
}

// ============================================================
// ACCIÓN: DESBLOQUEAR
// ============================================================
if ($accion === 'desbloquear') {
    if (empty($proyecto['bloqueado'])) {
        json_response(['error' => 'El proyecto no está bloqueado'], 400);
    }

    $fecha_reajustada = false;
    $nueva_fecha_valida = null;

    // Validar nueva fecha si viene
    if (!empty($nueva_fecha) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nueva_fecha)) {
        $nueva_fecha_valida = $nueva_fecha;
    }

    try {
        if ($nueva_fecha_valida !== null) {
            // Desbloquear + reajustar fecha
            db()->prepare("
                UPDATE gestion_proyectos
                SET bloqueado = FALSE,
                    motivo_bloqueo = NULL,
                    bloqueado_en = NULL,
                    bloqueado_por = NULL,
                    fecha_fin = ?,
                    actualizado_en = NOW()
                WHERE id_proyecto = ?
            ")->execute([$nueva_fecha_valida, $id_proyecto]);

            $fecha_reajustada = true;

            registrar_historial(
                $id_proyecto,
                'bloqueo',
                'proyecto_desbloqueado',
                'Se desbloqueó el proyecto y se reajustó la fecha de finalización a ' . $nueva_fecha_valida,
                ['nueva_fecha' => $nueva_fecha_valida, 'reajustada' => true]
            );
        } else {
            // Desbloquear sin cambiar fecha
            db()->prepare("
                UPDATE gestion_proyectos
                SET bloqueado = FALSE,
                    motivo_bloqueo = NULL,
                    bloqueado_en = NULL,
                    bloqueado_por = NULL,
                    actualizado_en = NOW()
                WHERE id_proyecto = ?
            ")->execute([$id_proyecto]);

            registrar_historial(
                $id_proyecto,
                'bloqueo',
                'proyecto_desbloqueado',
                'Se desbloqueó el proyecto, manteniendo la fecha de finalización original',
                ['reajustada' => false]
            );
        }

        json_response([
            'ok' => true,
            'mensaje' => 'Proyecto desbloqueado correctamente',
            'fecha_reajustada' => $fecha_reajustada
        ]);

    } catch (Exception $e) {
        json_response(['error' => 'Error desbloqueando proyecto: ' . $e->getMessage()], 500);
    }
}

// Si llegamos aquí, acción desconocida
json_response(['error' => 'Acción inválida'], 400);