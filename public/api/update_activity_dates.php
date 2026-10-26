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

$id_actividad = $data['id_actividad'] ?? null;
$campo        = $data['campo'] ?? null;
$fecha        = $data['fecha'] ?? null;

if (!$id_actividad || !in_array($campo, ['fecha_inicio', 'fecha_limite'], true)) {
    echo json_encode(['error' => 'Parámetros inválidos']);
    exit;
}

// Datos de la actividad + proyecto
$stmt = db()->prepare("
    SELECT a.id_actividad, a.id_proyecto, a.titulo,
           a.fecha_inicio, a.fecha_limite,
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
    echo json_encode(['error' => 'Sin permisos para editar fechas']);
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

// Guardar el valor anterior antes de actualizar
$valorAnterior = $campo === 'fecha_inicio' ? $act['fecha_inicio'] : $act['fecha_limite'];

// Validar coherencia con la otra fecha
if ($campo === 'fecha_inicio' && $fecha) {
    if ($act['fecha_limite'] && $fecha > $act['fecha_limite']) {
        echo json_encode(['error' => 'La fecha de inicio no puede ser posterior a la fecha límite']);
        exit;
    }
}
if ($campo === 'fecha_limite' && $fecha) {
    if ($act['fecha_inicio'] && $fecha < $act['fecha_inicio']) {
        echo json_encode(['error' => 'La fecha límite no puede ser anterior a la fecha de inicio']);
        exit;
    }
}

// Actualizar
$stmt = db()->prepare("UPDATE gestion_actividades SET $campo = ?, actualizado_en = NOW() WHERE id_actividad = ?");
$stmt->execute([$fecha ?: null, $id_actividad]);

// ============================================================
// REGISTRAR EN HISTORIAL DEL PROYECTO
// ============================================================
$etiquetaCampo = $campo === 'fecha_inicio' ? 'fecha de inicio' : 'fecha límite';
$fechaAnteriorTxt = $valorAnterior ? date('d/m/Y', strtotime($valorAnterior)) : 'sin definir';
$fechaNuevaTxt    = $fecha ? date('d/m/Y', strtotime($fecha)) : 'sin definir';

$accion = 'Fecha ' . ($campo === 'fecha_inicio' ? 'inicio' : 'límite') . ' actualizada';
$descripcion = 'Cambió la ' . $etiquetaCampo . ' de la actividad "' . $act['titulo'] . '" de ' . $fechaAnteriorTxt . ' a ' . $fechaNuevaTxt;

$metadata = json_encode([
    'id_actividad'    => (int)$id_actividad,
    'actividad'       => $act['titulo'],
    'campo'           => $campo,
    'valor_anterior'  => $valorAnterior,
    'valor_nuevo'     => $fecha ?: null
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

// Actualizar última actividad del proyecto (para que no aparezca "detenido")
db()->prepare("UPDATE gestion_proyectos SET ultima_actividad_en = NOW() WHERE id_proyecto = ?")
    ->execute([$act['id_proyecto']]);

echo json_encode(['ok' => true]);