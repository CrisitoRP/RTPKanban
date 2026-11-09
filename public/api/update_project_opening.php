<?php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';
require_login();

header('Content-Type: application/json');

$user = current_user();
if ($user['rol'] !== 'administrador') {
    echo json_encode(['error' => 'Solo el administrador puede modificar la fecha.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id_proyecto = (int)($data['id_proyecto'] ?? 0);
$fecha_apertura = trim($data['fecha_apertura'] ?? '');

if (!$id_proyecto || !$fecha_apertura) {
    echo json_encode(['error' => 'Datos incompletos.']);
    exit;
}

// Validar formato de fecha
$ts = strtotime($fecha_apertura);
if (!$ts) {
    echo json_encode(['error' => 'Fecha inválida.']);
    exit;
}

$fecha_final = date('Y-m-d H:i:s', $ts);

// Si la fecha es pasada o igual a ahora, el proyecto queda abierto
$abierto = ($ts <= time());

try {
    $stmt = db()->prepare("
        UPDATE gestion_proyectos
        SET fecha_apertura = ?,
            abierto = ?
        WHERE id_proyecto = ?
    ");
    // 👇 Opción A: pasar true/false directamente (seguro con boolean de Postgres)
    $stmt->execute([$fecha_final, $abierto ? true : false, $id_proyecto]);

    echo json_encode([
        'ok'      => true,
        'fecha'   => $fecha_final,
        'abierto' => $abierto,
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => 'No se pudo actualizar: ' . $e->getMessage()]);
}