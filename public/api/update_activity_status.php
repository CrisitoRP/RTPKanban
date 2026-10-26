<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$id_actividad = $data['id_actividad'] ?? null;
$nuevo_estado = $data['estado'] ?? null;

$estadosPermitidos = ['por_hacer', 'en_curso', 'en_revision', 'completada'];

if (!$id_actividad || !in_array($nuevo_estado, $estadosPermitidos, true)) {
    echo json_encode(['error' => 'Parámetros inválidos. Estado no permitido.']);
    exit;
}

// Datos de la actividad + proyecto
$stmt = db()->prepare("
    SELECT a.id_actividad, a.id_proyecto, a.titulo, a.estado,
           p.bloqueado, p.fecha_apertura
    FROM gestion_actividades a
    JOIN gestion_proyectos p ON p.id_proyecto = a.id_proyecto
    WHERE a.id_actividad = ? AND a.eliminada = FALSE
");
$stmt->execute([$id_actividad]);
$act = $stmt->fetch();

if (!$act) {
    echo json_encode(['error' => 'Actividad no encontrada']);
    exit;
}

if (!can_access_project($user, $act['id_proyecto'])) {
    echo json_encode(['error' => 'Sin acceso al proyecto']);
    exit;
}

if ($user['rol'] !== 'administrador' && $user['rol'] !== 'responsable') {
    echo json_encode(['error' => 'Sin permisos para cambiar el estado']);
    exit;
}

if (!empty($act['bloqueado'])) {
    echo json_encode(['error' => 'El proyecto está bloqueado']);
    exit;
}

if (!empty($act['fecha_apertura']) && strtotime($act['fecha_apertura']) > time()) {
    echo json_encode(['error' => 'El proyecto aún no se ha abierto']);
    exit;
}

// Si la actividad está bloqueada, no se puede cambiar de estado (hay que usar desbloquear)
if ($act['estado'] === 'bloqueado') {
    echo json_encode(['error' => 'La actividad está bloqueada. Desbloquéala primero.']);
    exit;
}

$estadoAnterior = $act['estado'];

if ($estadoAnterior === $nuevo_estado) {
    echo json_encode(['ok' => true, 'sin_cambios' => true]);
    exit;
}

// Actualizar estado
$stmt = db()->prepare("
    UPDATE gestion_actividades
    SET estado = ?, actualizado_en = NOW()
    WHERE id_actividad = ?
");
$stmt->execute([$nuevo_estado, $id_actividad]);

// ============================================================
// REGISTRAR EN HISTORIAL DEL PROYECTO
// ============================================================
$estadoLabels = [
    'por_hacer'   => 'Por hacer',
    'en_curso'    => 'En curso',
    'en_revision' => 'En revisión',
    'completada'  => 'Completada',
    'bloqueado'   => 'Bloqueado',
];

$labelAnterior = $estadoLabels[$estadoAnterior] ?? $estadoAnterior;
$labelNuevo    = $estadoLabels[$nuevo_estado]   ?? $nuevo_estado;

$accion = 'Estado actualizado';
$descripcion = 'Cambió el estado de la actividad "' . $act['titulo'] . '" de ' . $labelAnterior . ' a ' . $labelNuevo;

$metadata = json_encode([
    'id_actividad'   => (int)$id_actividad,
    'actividad'      => $act['titulo'],
    'campo'          => 'estado',
    'valor_anterior' => $estadoAnterior,
    'valor_nuevo'    => $nuevo_estado
], JSON_UNESCAPED_UNICODE);

$stmtH = db()->prepare("
    INSERT INTO gestion_historial_proyecto
        (id_proyecto, id_usuario, tipo, accion, descripcion, metadata, creado_en)
    VALUES (?, ?, 'edicion', ?, ?, ?::jsonb, NOW())
");
$stmtH->execute([
    $act['id_proyecto'],
    $user['id_usuario'],
    $accion,
    $descripcion,
    $metadata
]);

// Actualizar última actividad del proyecto
db()->prepare("UPDATE gestion_proyectos SET ultima_actividad_en = NOW() WHERE id_proyecto = ?")
    ->execute([$act['id_proyecto']]);

echo json_encode(['ok' => true]);