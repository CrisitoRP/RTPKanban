<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador puede desbloquear'], 403);
}

$d = json_input();
$id_actividad = $d['id_actividad'] ?? 0;
$nueva_fecha = $d['nueva_fecha'] ?? null;

if (!$id_actividad) json_response(['error' => 'ID requerido'], 400);

// Obtener estado actual
$stmt = db()->prepare("
    SELECT estado, estado_antes_bloqueo, fecha_limite, id_proyecto, titulo
    FROM gestion_actividades
    WHERE id_actividad = ?
");
$stmt->execute([$id_actividad]);
$act = $stmt->fetch();

if (!$act) json_response(['error' => 'Actividad no encontrada'], 404);
if ($act['estado'] !== 'bloqueado') json_response(['error' => 'No está bloqueada'], 400);

// Estado al que vuelve (por defecto "por_hacer" si no hay guardado)
$estado_vuelve = $act['estado_antes_bloqueo'] ?: 'por_hacer';

// Validar estado
if (!in_array($estado_vuelve, ['por_hacer', 'en_curso', 'en_revision', 'completada'])) {
    $estado_vuelve = 'por_hacer';
}

try {
    // Desbloquear
    if ($nueva_fecha) {
        db()->prepare("
            UPDATE gestion_actividades
            SET estado = ?,
                estado_antes_bloqueo = NULL,
                desbloqueado_en = NOW(),
                desbloqueado_por = ?,
                fecha_limite = ?,
                actualizado_en = NOW()
            WHERE id_actividad = ?
        ")->execute([$estado_vuelve, $user['id_usuario'], $nueva_fecha, $id_actividad]);
    } else {
        db()->prepare("
            UPDATE gestion_actividades
            SET estado = ?,
                estado_antes_bloqueo = NULL,
                desbloqueado_en = NOW(),
                desbloqueado_por = ?,
                actualizado_en = NOW()
            WHERE id_actividad = ?
        ")->execute([$estado_vuelve, $user['id_usuario'], $id_actividad]);
    }

    // Registrar en historial de bloqueos de actividad (tu tabla)
    db()->prepare("
        INSERT INTO gestion_historial_bloqueos_actividad
            (id_actividad, accion, fecha_limite_anterior, fecha_limite_nueva, realizado_por)
        VALUES (?, 'desbloqueo', ?, ?, ?)
    ")->execute([$id_actividad, $act['fecha_limite'], $nueva_fecha, $user['id_usuario']]);

    // 📝 Registrar en el historial DEL PROYECTO
    $descripcion = $nueva_fecha
        ? 'Se desbloqueó la actividad "' . $act['titulo'] . '" y se reajustó la fecha a ' . $nueva_fecha
        : 'Se desbloqueó la actividad "' . $act['titulo'] . '", manteniendo la fecha original';

    registrar_historial(
        $act['id_proyecto'],
        'bloqueo',
        'actividad_desbloqueada',
        $descripcion,
        [
            'id_actividad' => $id_actividad,
            'estado_vuelve' => $estado_vuelve,
            'nueva_fecha' => $nueva_fecha
        ]
    );

    // Notificar
    $stmtResp = db()->prepare("
        SELECT id_usuario FROM gestion_actividades_responsables
        WHERE id_actividad = ?
    ");
    $stmtResp->execute([$id_actividad]);
    $responsables = $stmtResp->fetchAll();

    foreach ($responsables as $r) {
        db()->prepare("
            INSERT INTO gestion_notificaciones
                (id_usuario, id_proyecto, id_actividad, tipo, titulo, mensaje)
            VALUES (?, ?, ?, 'actividad_desbloqueada', ?, ?)
        ")->execute([
            $r['id_usuario'],
            $act['id_proyecto'],
            $id_actividad,
            'Actividad desbloqueada',
            'La actividad "' . $act['titulo'] . '" fue desbloqueada. Vuelve a: ' . $estado_vuelve
        ]);
    }

    json_response(['ok' => true, 'estado_vuelve' => $estado_vuelve]);

} catch (Exception $e) {
    json_response(['error' => 'Error: ' . $e->getMessage()], 500);
}