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

$id_proyecto = $data['id_proyecto'] ?? null;
$columna_manual = $data['columna_manual'] ?? null;

$estadosPermitidos = ['sin_iniciar', 'en_curso', 'en_revision', 'finalizado'];

if (!$id_proyecto || !in_array($columna_manual, $estadosPermitidos, true)) {
    echo json_encode(['error' => 'Parámetros inválidos']);
    exit;
}

// Verificar acceso y permisos
$stmt = db()->prepare("SELECT nombre, bloqueado, fecha_apertura FROM gestion_proyectos WHERE id_proyecto = ? AND eliminado = FALSE");
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch();

if (!$proyecto) {
    echo json_encode(['error' => 'Proyecto no encontrado']);
    exit;
}

if (!can_access_project($user, $id_proyecto)) {
    echo json_encode(['error' => 'Sin acceso al proyecto']);
    exit;
}

if ($user['rol'] !== 'administrador' && $user['rol'] !== 'responsable') {
    echo json_encode(['error' => 'Sin permisos para cambiar el estado del proyecto']);
    exit;
}

if (!empty($proyecto['bloqueado'])) {
    echo json_encode(['error' => 'El proyecto está bloqueado']);
    exit;
}

if (!empty($proyecto['fecha_apertura']) && strtotime($proyecto['fecha_apertura']) > time()) {
    echo json_encode(['error' => 'El proyecto aún no se ha abierto']);
    exit;
}

// Actualizar estado
$stmt = db()->prepare("UPDATE gestion_proyectos SET columna_manual = ?, actualizado_en = NOW() WHERE id_proyecto = ?");
$stmt->execute([$columna_manual, $id_proyecto]);

// Registrar en historial
$estadoLabels = [
    'sin_iniciar' => 'Sin iniciar',
    'en_curso'    => 'En curso',
    'en_revision' => 'En revisión',
    'finalizado'  => 'Finalizado',
];

$labelNuevo = $estadoLabels[$columna_manual] ?? $columna_manual;

$accion = 'Estado del proyecto actualizado';
$descripcion = 'Cambió el estado del proyecto a: ' . $labelNuevo;

$metadata = json_encode([
    'campo' => 'columna_manual',
    'valor_nuevo' => $columna_manual
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

echo json_encode(['ok' => true, 'mensaje' => $labelNuevo]);