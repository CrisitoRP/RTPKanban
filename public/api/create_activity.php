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
$fecha = $d['fecha'] ?? null;                     // fecha límite
$prioridad = $d['prioridad'] ?? 'media';
$asignado = $d['asignado_a'] ?? null;

// ✅ Leer los campos que antes se ignoraban
$fecha_inicio       = !empty($d['fecha_inicio']) ? $d['fecha_inicio'] : null;
$porcentaje_aporte  = isset($d['porcentaje_aporte']) ? (float)$d['porcentaje_aporte'] : 0;
$estado             = $d['estado'] ?? 'por_hacer';

if (!$id_proyecto || !$titulo) {
    json_response(['error' => 'Faltan datos obligatorios'], 400);
}

if (!in_array($prioridad, ['baja','media','alta','critica'])) {
    $prioridad = 'media';
}

// Validar estado permitido
if (!in_array($estado, ['por_hacer','en_curso','en_revision','programado','completada','bloqueado'])) {
    $estado = 'por_hacer';
}

// Validar porcentaje
if ($porcentaje_aporte < 0)   $porcentaje_aporte = 0;
if ($porcentaje_aporte > 100) $porcentaje_aporte = 100;

// 🔒 Verificar que el proyecto NO esté bloqueado
bloquear_si_proyecto_bloqueado($id_proyecto);

try {
    $stmt = db()->prepare("
        INSERT INTO gestion_actividades
            (id_proyecto, titulo, descripcion, estado, prioridad, fecha_inicio, fecha_limite, asignado_a, porcentaje_aporte, creado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id_actividad
    ");
    $stmt->execute([
        $id_proyecto,
        $titulo,
        $descripcion ?: null,
        $estado,
        $prioridad,
        $fecha_inicio ?: null,       // ✅ ahora SÍ se guarda
        $fecha ?: null,
        $asignado ?: null,
        $porcentaje_aporte,          // ✅ ahora SÍ se guarda
        $user['id_usuario']
    ]);

    $id_actividad = $stmt->fetch()['id_actividad'];

    // 📝 Registrar en el historial del proyecto
    registrar_historial(
        $id_proyecto,
        'creacion',
        'actividad_creada',
        'Se creó la actividad "' . $titulo . '"',
        [
            'id_actividad'      => $id_actividad,
            'prioridad'         => $prioridad,
            'fecha_inicio'      => $fecha_inicio,
            'fecha_limite'      => $fecha,
            'estado'            => $estado,
            'porcentaje_aporte' => $porcentaje_aporte
        ]
    );

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