<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user || $user['rol'] !== 'administrador') {
    json_response(['error' => 'Solo el administrador puede bloquear'], 403);
}

$d = json_input();
$id_actividad = $d['id_actividad'] ?? 0;
$motivo = trim($d['motivo'] ?? 'Sin motivo especificado');

if (!$id_actividad) json_response(['error' => 'ID requerido'], 400);

// Obtener estado actual
$stmt = db()->prepare("SELECT estado, id_proyecto, titulo FROM gestion_actividades WHERE id_actividad = ?");
$stmt->execute([$id_actividad]);
$act = $stmt->fetch();

if (!$act) json_response(['error' => 'Actividad no encontrada'], 404);
if ($act['estado'] === 'bloqueado') json_response(['error' => 'Ya está bloqueada'], 400);

try {
    // Guardar estado anterior y bloquear
    db()->prepare("
        UPDATE gestion_actividades
        SET estado = 'bloqueado',
            estado_antes_bloqueo = ?,
            bloqueado_en = NOW(),
            bloqueado_por = ?,
            motivo_bloqueo = ?,
            actualizado_en = NOW()
        WHERE id_actividad = ?
    ")->execute([$act['estado'], $user['id_usuario'], $motivo, $id_actividad]);

    // Registrar en historial de bloqueos de actividad (tu tabla)
    db()->prepare("
        INSERT INTO gestion_historial_bloqueos_actividad
            (id_actividad, accion, motivo, realizado_por)
        VALUES (?, 'bloqueo', ?, ?)
    ")->execute([$id_actividad, $motivo, $user['id_usuario']]);

    // 📝 Registrar en el historial DEL PROYECTO
    registrar_historial(
        $act['id_proyecto'],
        'bloqueo',
        'actividad_bloqueada',
        'Se bloqueó la actividad "' . $act['titulo'] . '". Motivo: ' . $motivo,
        [
            'id_actividad' => $id_actividad,
            'motivo'       => $motivo
        ]
    );

    // Notificar a los responsables
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
            VALUES (?, ?, ?, 'actividad_bloqueada', ?, ?)
        ")->execute([
            $r['id_usuario'],
            $act['id_proyecto'],
            $id_actividad,
            'Actividad bloqueada',
            'La actividad "' . $act['titulo'] . '" fue bloqueada. Motivo: ' . $motivo
        ]);
    }

    json_response(['ok' => true]);

} catch (Exception $e) {
    json_response(['error' => 'Error: ' . $e->getMessage()], 500);
}