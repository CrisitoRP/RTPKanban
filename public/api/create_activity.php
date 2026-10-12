<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

$user = current_user();
if (!$user) json_response(['error' => 'No autenticado'], 401);
if ($user['rol'] === 'visualizador') json_response(['error' => 'Sin permisos'], 403);

$d = json_input();
$id_proyecto = $d['id_proyecto'] ?? 0;
$titulo = trim($d['titulo'] ?? '');
$descripcion = trim($d['descripcion'] ?? '');
$fecha = $d['fecha'] ?? null;
$prioridad = $d['prioridad'] ?? 'media';
$asignado = $d['asignado_a'] ?? null;

if (!$id_proyecto || !$titulo) {
    json_response(['error' => 'Faltan datos obligatorios'], 400);
}

if (!in_array($prioridad, ['baja','media','alta','critica'])) {
    $prioridad = 'media';
}

try {
    $stmt = db()->prepare("
        INSERT INTO gestion_actividades
            (id_proyecto, titulo, descripcion, estado, prioridad, fecha_limite, asignado_a, creado_por)
        VALUES (?, ?, ?, 'por_hacer', ?, ?, ?, ?)
        RETURNING id_actividad
    ");
    $stmt->execute([
        $id_proyecto,
        $titulo,
        $descripcion ?: null,
        $prioridad,
        $fecha ?: null,
        $asignado ?: null,
        $user['id_usuario']
    ]);

    $id_actividad = $stmt->fetch()['id_actividad'];

    // Notificar al asignado si es otro usuario
    if ($asignado && $asignado != $user['id_usuario']) {
        db()->prepare("
            INSERT INTO gestion_notificaciones (id_usuario, id_proyecto, id_actividad, tipo, titulo, mensaje)
            VALUES (?, ?, ?, 'asignacion', ?, ?)
        ")->execute([
            $asignado,
            $id_proyecto,
            $id_actividad,
            'Nueva actividad asignada',
            'Se te asignó la actividad "' . $titulo . '"'
        ]);
    }

    json_response(['ok' => true, 'id_actividad' => $id_actividad]);

} catch (Exception $e) {
    json_response(['error' => 'Error: ' . $e->getMessage()], 500);
}