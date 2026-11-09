<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/project_state.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { echo json_encode(['error' => 'JSON inválido']); exit; }

$id_proyecto    = $data['id_proyecto'] ?? null;
$columna_manual = $data['columna_manual'] ?? null;
$fecha_apertura = $data['fecha_apertura'] ?? null;

if (!$id_proyecto || !$columna_manual) { echo json_encode(['error' => 'Parámetros inválidos']); exit; }

$stmt = db()->prepare("SELECT bloqueado, fecha_apertura FROM gestion_proyectos WHERE id_proyecto = ? AND eliminado = FALSE");
$stmt->execute([$id_proyecto]);
$proyecto = $stmt->fetch();
if (!$proyecto) { echo json_encode(['error' => 'Proyecto no encontrado']); exit; }

if (!can_access_project($user, $id_proyecto)) { echo json_encode(['error' => 'Sin acceso al proyecto']); exit; }
if ($user['rol'] !== 'administrador' && $user['rol'] !== 'responsable') { echo json_encode(['error' => 'Sin permisos']); exit; }
if (!empty($proyecto['bloqueado']) && $columna_manual !== 'bloqueado') { echo json_encode(['error' => 'El proyecto está bloqueado. Desbloquéalo primero.']); exit; }
if (!empty($proyecto['fecha_apertura']) && strtotime($proyecto['fecha_apertura']) > time() && $columna_manual !== 'programado') {
    echo json_encode(['error' => 'El proyecto aún no se ha abierto']); exit;
}
if (in_array($columna_manual, ['programado', 'bloqueado'], true) && $user['rol'] !== 'administrador') {
    echo json_encode(['error' => 'Solo el administrador puede mover a esta etapa']); exit;
}
if ($columna_manual === 'programado' && empty($fecha_apertura)) {
    echo json_encode(['error' => 'Se requiere una fecha de apertura']); exit;
}

$resultado = set_project_stage((int)$id_proyecto, $columna_manual, (int)$user['id_usuario'], ['fecha_apertura' => $fecha_apertura]);

if (isset($resultado['error'])) { echo json_encode(['error' => $resultado['error']]); }
else { echo json_encode(['ok' => true, 'mensaje' => $resultado['label']]); }