<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_login();

header('Content-Type: application/json');
$user = current_user();
if ($user['rol'] !== 'administrador') {
    echo json_encode(['error' => 'Solo el administrador puede aperturar proyectos.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id_proyecto = (int)($data['id_proyecto'] ?? 0);
if (!$id_proyecto) { echo json_encode(['error' => 'ID inválido.']); exit; }

$ahora = date('Y-m-d H:i:s');
$stmt = db()->prepare("
    UPDATE gestion_proyectos
    SET fecha_apertura = ?, abierto = TRUE
    WHERE id_proyecto = ?
");
$stmt->execute([$ahora, $id_proyecto]);

echo json_encode(['ok' => true]);