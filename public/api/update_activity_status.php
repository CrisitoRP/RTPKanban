<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_once __DIR__ . '/../../src/project_state.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) { echo json_encode(['error' => 'JSON inválido']); exit; }

$id_actividad = $data['id_actividad'] ?? null;
$nuevo_estado = $data['estado'] ?? null;
$motivo       = $data['motivo'] ?? null;

if (!$id_actividad || !$nuevo_estado) { echo json_encode(['error' => 'Parámetros inválidos']); exit; }

$resultado = set_activity_status((int)$id_actividad, $nuevo_estado, $user, ['motivo' => $motivo]);

if (isset($resultado['error'])) {
    echo json_encode(['error' => $resultado['error']]);
} else {
    echo json_encode(['ok' => true, 'estado' => $resultado['estado']]);
}