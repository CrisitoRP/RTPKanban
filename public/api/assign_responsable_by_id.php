<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/project_state.php';

if (!function_exists('current_user')) { http_response_code(500); echo json_encode(['error' => 'auth.php no define current_user()']); exit; }
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'No hay sesión activa.']); exit; }

$data = json_decode(file_get_contents('php://input'), true);
$id_actividad = (int)($data['id_actividad'] ?? 0);
$id_proyecto  = (int)($data['id_proyecto'] ?? 0);
$id_usuario   = (int)($data['id_usuario'] ?? 0);

if (!$id_actividad || !$id_proyecto || !$id_usuario) {
    http_response_code(400); echo json_encode(['error' => 'Faltan parámetros']); exit;
}
if (!can_access_project($user, $id_proyecto)) {
    http_response_code(403); echo json_encode(['error' => 'Sin acceso al proyecto']); exit;
}
if ($user['rol'] !== 'administrador' && $user['rol'] !== 'responsable') {
    http_response_code(403); echo json_encode(['error' => 'Sin permiso para asignar responsables']); exit;
}

$stmt = db()->prepare("SELECT 1 FROM gestion_miembros_proyecto WHERE id_proyecto = ? AND id_usuario = ? LIMIT 1");
$stmt->execute([$id_proyecto, $id_usuario]);
if (!$stmt->fetch()) {
    echo json_encode(['error' => 'El usuario no pertenece al proyecto']); exit;
}

$stmt = db()->prepare("
    INSERT INTO gestion_actividades_responsables (id_actividad, id_usuario)
    VALUES (?, ?)
    ON CONFLICT (id_actividad, id_usuario) DO NOTHING
");
$stmt->execute([$id_actividad, $id_usuario]);

echo json_encode(['ok' => true]);