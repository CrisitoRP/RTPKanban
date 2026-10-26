<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['error' => 'JSON inválido recibido']);
    exit;
}

$id_proyecto = $data['id_proyecto'] ?? null;
$campo       = $data['campo'] ?? null;
$fecha       = $data['fecha'] ?? null;

if (!$id_proyecto || !in_array($campo, ['fecha_inicio', 'fecha_fin'], true)) {
    echo json_encode(['error' => 'Parámetros inválidos']);
    exit;
}

$stmt = db()->prepare("
    SELECT id_proyecto, nombre, fecha_inicio, fecha_fin, bloqueado, fecha_apertura
    FROM gestion_proyectos
    WHERE id_proyecto = ? AND eliminado = FALSE
");
$stmt->execute([$id_proyecto]);
$proy = $stmt->fetch();

if (!$proy) {
    echo json_encode(['error' => 'Proyecto no encontrado']);
    exit;
}

if (!can_access_project($user, $id_proyecto)) {
    echo json_encode(['error' => 'Sin acceso al proyecto']);
    exit;
}

if ($user['rol'] !== 'administrador') {
    echo json_encode(['error' => 'Solo el administrador puede editar fechas del proyecto']);
    exit;
}

if (!empty($proy['bloqueado'])) {
    echo json_encode(['error' => 'El proyecto está bloqueado']);
    exit;
}

// Guardar valor anterior
$valorAnterior = $campo === 'fecha_inicio' ? $proy['fecha_inicio'] : $proy['fecha_fin'];

// Validar coherencia
if ($campo === 'fecha_inicio' && $fecha) {
    if ($proy['fecha_fin'] && $fecha > $proy['fecha_fin']) {
        echo json_encode(['error' => 'La fecha de inicio no puede ser posterior a la fecha de fin']);
        exit;
    }
}
if ($campo === 'fecha_fin' && $fecha) {
    if ($proy['fecha_inicio'] && $fecha < $proy['fecha_inicio']) {
        echo json_encode(['error' => 'La fecha de fin no puede ser anterior a la fecha de inicio']);
        exit;
    }
}

// Actualizar
$stmt = db()->prepare("UPDATE gestion_proyectos SET $campo = ?, actualizado_en = NOW() WHERE id_proyecto = ?");
$stmt->execute([$fecha ?: null, $id_proyecto]);

// ============================================================
// REGISTRAR EN HISTORIAL DEL PROYECTO
// ============================================================
$etiquetaCampo = $campo === 'fecha_inicio' ? 'fecha de inicio' : 'fecha de fin';
$fechaAnteriorTxt = $valorAnterior ? date('d/m/Y', strtotime($valorAnterior)) : 'sin definir';
$fechaNuevaTxt    = $fecha ? date('d/m/Y', strtotime($fecha)) : 'sin definir';

$accion = 'Fecha ' . ($campo === 'fecha_inicio' ? 'inicio' : 'fin') . ' actualizada';
$descripcion = 'Cambió la ' . $etiquetaCampo . ' del proyecto de ' . $fechaAnteriorTxt . ' a ' . $fechaNuevaTxt;

$metadata = json_encode([
    'campo'          => $campo,
    'valor_anterior' => $valorAnterior,
    'valor_nuevo'    => $fecha ?: null
], JSON_UNESCAPED_UNICODE);

$stmtH = db()->prepare("
    INSERT INTO gestion_historial_proyecto
        (id_proyecto, id_usuario, tipo, accion, descripcion, metadata, creado_en)
    VALUES (?, ?, 'edicion', ?, ?, ?::jsonb, NOW())
");
$stmtH->execute([
    $id_proyecto,
    $user['id_usuario'],
    $accion,
    $descripcion,
    $metadata
]);

// Actualizar última actividad del proyecto
db()->prepare("UPDATE gestion_proyectos SET ultima_actividad_en = NOW() WHERE id_proyecto = ?")
    ->execute([$id_proyecto]);

echo json_encode(['ok' => true]);